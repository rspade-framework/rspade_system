<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Mail;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Rsx_Mail_Transport - the one place a mail transport is constructed.
 *
 * config('rsx.mail.transport') is turned into a Symfony DSN here and nowhere else, so
 * "what does this install actually send through" has one answer, readable by the queue
 * drain, by rsx:mail:test and by the health check alike.
 *
 * IT ALSO OWNS THE DELIVERY MODE - the four-valued answer to "what does this install do
 * with an email". See delivery_mode(); every reader in the framework asks here, so an
 * unknown value throws in ONE place instead of being silently treated as "not live".
 *
 * NO TIMEOUT IS SET ANYWHERE IN THIS CLASS. Symfony's own socket defaults belong to
 * the external party we are talking to; shortening them would convert a slow-but-
 * working mail host into a failed send at the worst possible moment.
 */
class Rsx_Mail_Transport
{
    /**
     * The development catcher on this box: SMTP to 127.0.0.1:1025, no encryption, no
     * auth, and a greeting that has to say so. THE SHIPPED DEFAULT - a fresh install
     * sends mail, keeps it, and never reaches a stranger.
     */
    const MODE_AIOSMTPD = 'aiosmtpd';

    /** Real delivery through rsx.mail.transport.*, with the dev-site recipient gate. */
    const MODE_LIVE = 'live';

    /** Built and recorded, deliberately never handed to a transport. */
    const MODE_SUPPRESSED = 'suppressed';

    /** The queue is frozen: the drain does nothing at all and rows stay PENDING. */
    const MODE_DISABLED = 'disabled';

    /** Every value rsx.mail.delivery may hold. Anything else throws. */
    const DELIVERY_MODES = [
        self::MODE_AIOSMTPD,
        self::MODE_LIVE,
        self::MODE_SUPPRESSED,
        self::MODE_DISABLED,
    ];

    /**
     * The fixed transport of MODE_AIOSMTPD.
     *
     * Fixed, not defaulted: in this mode rsx.mail.transport.* is IGNORED entirely, so a
     * box whose MAIL_HOST still names last year's relay cannot mail anybody by accident.
     * Point it somewhere real by choosing MODE_LIVE, which is the switch that says so.
     */
    const AIOSMTPD_HOST = '127.0.0.1';
    const AIOSMTPD_PORT = 1025;

    /** The substring the catcher's SMTP greeting must contain. */
    const AIOSMTPD_IDENT = 'aiosmtpd';

    /**
     * TEST-ONLY SEAM: when set, probe_banner() returns this instead of opening a socket.
     *
     * The banner check is a real TCP conversation with a real catcher, which is exactly
     * what makes it worth having and exactly what makes "what happens when the greeting
     * is wrong" untestable without a seam. Same rules as $override_for_tests: a test
     * sets it, a test clears it in a finally, and nothing else ever assigns it.
     */
    public static ?string $banner_for_tests = null;

    /**
     * TEST-ONLY SEAM: when set, make() returns this instead of building from config.
     *
     * The runner constructs its own transport (that is the point - nobody hands it
     * one), so a test that needs to observe how the loop reacts to a particular SMTP
     * outcome has no other way in. Same shape and same rules as the
     * rsx.files.storage_root override: a test sets it, a test clears it in a finally,
     * and NOTHING in the framework or an application ever assigns it at runtime.
     */
    public static ?TransportInterface $override_for_tests = null;

    /**
     * What this install does with an email. One of the four MODE_* constants.
     *
     * An unrecognised value THROWS rather than falling back: "not one of the four" is
     * always a typo or a stale deployment, and every possible guess is wrong in a way
     * somebody only discovers from the mail that did or did not go out.
     */
    public static function delivery_mode(): string
    {
        $mode = strtolower(trim((string) config('rsx.mail.delivery', self::MODE_AIOSMTPD)));

        if (!in_array($mode, self::DELIVERY_MODES, true)) {
            throw new \RuntimeException(
                "rsx.mail.delivery is '{$mode}' - the only modes are '"
                . implode("', '", self::DELIVERY_MODES) . "'."
            );
        }

        return $mode;
    }

    /**
     * Why this connection must not be trusted, or null when it may be.
     *
     * ONLY MODE_AIOSMTPD ASKS. That mode's whole promise is that mail lands in a Maildir
     * on this box and reaches nobody, and the one thing that could break the promise is
     * something else listening on 127.0.0.1:1025 - an ssh tunnel to a real relay, a
     * developer's own MTA, a port-forward left over from yesterday. The greeting is the
     * only thing the catcher can say before we hand it a message, so we read it.
     *
     * A connection that cannot be opened AT ALL returns null, deliberately: that is an
     * outage, not an identity problem, and the drain's transport-failure path (release,
     * reconnect once, then die loudly) is the correct handling for it. Reporting it here
     * would convert a stopped catcher into per-message server errors and burn every
     * message's retry budget on it.
     */
    public static function aiosmtpd_banner_error(): ?string
    {
        if (static::delivery_mode() !== self::MODE_AIOSMTPD) {
            return null;
        }

        $banner = static::probe_banner();

        if ($banner === '') {
            return null;
        }

        if (stripos($banner, self::AIOSMTPD_IDENT) !== false) {
            return null;
        }

        return 'expected server ' . self::AIOSMTPD_IDENT . ' on '
            . self::AIOSMTPD_HOST . ':' . self::AIOSMTPD_PORT
            . ' but it greeted with: ' . $banner;
    }

    /**
     * The SMTP greeting line 127.0.0.1:1025 answers with, or '' if nothing answered.
     *
     * Symfony's EsmtpTransport never exposes the 220 line, so this opens its own socket,
     * reads exactly the greeting, says QUIT and closes. NO TIMEOUT: a catcher that
     * accepts a connection and then never speaks is a fault to SEE, and a number here
     * would turn one into a randomly-timed send failure instead.
     */
    public static function probe_banner(): string
    {
        if (static::$banner_for_tests !== null) {
            return static::$banner_for_tests;
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen(self::AIOSMTPD_HOST, self::AIOSMTPD_PORT, $errno, $errstr);

        if ($socket === false) {
            return '';
        }

        stream_set_blocking($socket, true);

        $banner = (string) fgets($socket);

        @fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return trim($banner);
    }

    /**
     * Build the transport this install is configured to send through.
     */
    public static function make(): TransportInterface
    {
        if (static::$override_for_tests !== null) {
            return static::$override_for_tests;
        }

        return Transport::fromDsn(static::dsn());
    }

    /**
     * The DSN describing the configured transport.
     *
     * Kept separate from make() because it is also the thing to print when somebody
     * asks why mail went where it went - with the password left out.
     */
    public static function dsn(): string
    {
        $config = static::_transport_config();

        if ($config['driver'] === 'sendmail') {
            return 'sendmail://default?command=' . rawurlencode($config['sendmail_path']);
        }

        return static::_smtp_dsn($config, $config['password']);
    }

    /**
     * A one-line description of the transport, for narration and health rows.
     *
     * Never contains the password.
     */
    public static function describe(): string
    {
        $config = static::_transport_config();

        if ($config['driver'] === 'sendmail') {
            return 'sendmail ' . $config['sendmail_path'];
        }

        $description = 'smtp ' . $config['host'] . ':' . $config['port'];

        if ($config['encryption'] !== '') {
            $description .= ' (' . $config['encryption'] . ')';
        }

        if ($config['username'] !== '') {
            $description .= ' as ' . $config['username'];
        }

        return $description;
    }

    /**
     * The SMTP DSN.
     *
     * Encryption maps onto the two knobs Symfony actually has:
     *   ''    -> smtp:// with auto_tls=false. Explicitly OFF, not merely unmentioned:
     *            left to itself Symfony opportunistically STARTTLSes whenever the
     *            server advertises it, and a plain loopback catcher that does not
     *            advertise it would work while a real host silently changed behaviour.
     *   'tls' -> smtp:// with require_tls=true (STARTTLS on the plain port, mandatory).
     *   'ssl' -> smtps:// (TLS from the first byte, the historical port 465 shape).
     */
    private static function _smtp_dsn(array $config, string $password): string
    {
        $scheme = $config['encryption'] === 'ssl' ? 'smtps' : 'smtp';

        $credentials = '';
        if ($config['username'] !== '') {
            $credentials = rawurlencode($config['username']) . ':' . rawurlencode($password) . '@';
        }

        $query = [];
        if ($config['encryption'] === '') {
            $query['auto_tls'] = 'false';
        } elseif ($config['encryption'] === 'tls') {
            $query['require_tls'] = 'true';
        }

        $dsn = $scheme . '://' . $credentials . $config['host'] . ':' . $config['port'];

        if ($query !== []) {
            $dsn .= '?' . http_build_query($query);
        }

        return $dsn;
    }

    /**
     * rsx:health probe: can this install send mail, and will anybody accept it?
     *
     * Three rows, because they fail for three unrelated reasons and an operator fixes
     * them in three different places:
     *
     *   Mail delivery      - which of the four modes this install is in. INFO, never
     *                        FAIL, for suppressed and disabled: those are settings
     *                        somebody chose, not faults.
     *   Mail transport     - can the configured host be reached AT ALL. A read-only TCP
     *                        connect, opened and immediately closed, exactly as the
     *                        realtime relay and lock daemon probes do it - plus, in
     *                        aiosmtpd mode, whether the thing answering is the catcher.
     *   Mail sender domain - will a receiving server believe mail claiming to come from
     *                        our From address. DNS only, and only where it can matter:
     *                        anything but `live` is a development setup, not a
     *                        deliverability problem.
     *
     * NO TIMEOUT beyond the 2-second fsockopen argument every probe in this framework
     * uses. The DNS lookups have none: a dead resolver is a fault to SEE.
     *
     * @return array
     */
    #[Health_Check('Mail')]
    public static function mail_health(): array
    {
        $mode = static::delivery_mode();
        $from_address = (string) config('rsx.mail.from_address');

        $rows = [];

        if ($mode === self::MODE_SUPPRESSED) {
            $rows[] = [
                'label' => 'Mail delivery',
                'status' => 'INFO',
                'detail' => 'suppressed - nothing leaves this host'
                    . ' (queued messages are rendered and recorded, never handed to a transport)',
            ];
        } elseif ($mode === self::MODE_DISABLED) {
            $rows[] = [
                'label' => 'Mail delivery',
                'status' => 'INFO',
                'detail' => 'disabled - the queue is frozen'
                    . ' (messages are queued and stay PENDING; the drain does nothing)',
            ];
        } elseif ($mode === self::MODE_AIOSMTPD) {
            $rows[] = [
                'label' => 'Mail delivery',
                'status' => 'OK',
                'detail' => 'aiosmtpd - captured by the development catcher on '
                    . self::AIOSMTPD_HOST . ':' . self::AIOSMTPD_PORT
                    . ', from ' . $from_address . ' (nothing leaves this host)',
            ];
        } else {
            $rows[] = [
                'label' => 'Mail delivery',
                'status' => 'OK',
                'detail' => 'live via ' . static::describe() . ' from ' . $from_address,
            ];
        }

        $rows[] = static::_health_transport_row($mode);
        $rows[] = static::_health_sender_domain_row($mode, $from_address);

        return $rows;
    }

    /**
     * Can the configured transport be reached right now.
     */
    private static function _health_transport_row(string $mode): array
    {
        if ($mode === self::MODE_SUPPRESSED || $mode === self::MODE_DISABLED) {
            return [
                'label' => 'Mail transport',
                'status' => 'INFO',
                'detail' => 'not probed - delivery is ' . $mode . ', so the transport is never opened',
            ];
        }

        $config = static::_transport_config();

        if ($config['driver'] === 'sendmail') {
            $command = trim($config['sendmail_path']);
            $binary = explode(' ', $command)[0];

            if (!is_executable($binary)) {
                return [
                    'label' => 'Mail transport',
                    'status' => 'FAIL',
                    'detail' => "sendmail binary '{$binary}' is not executable - nothing can be sent",
                    'remediation' => 'install an MTA providing ' . $binary
                        . ', or set rsx.mail.transport.driver to smtp',
                ];
            }

            return [
                'label' => 'Mail transport',
                'status' => 'OK',
                'detail' => 'sendmail ' . $command,
            ];
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($config['host'], $config['port'], $errno, $errstr, 2);

        if ($socket === false) {
            $remediation = $mode === self::MODE_AIOSMTPD
                ? 'start the development mail catcher - check supervisor [program:mail-catcher]'
                : 'check MAIL_HOST/MAIL_PORT and that the relay accepts connections from this host';

            return [
                'label' => 'Mail transport',
                'status' => 'FAIL',
                'detail' => 'cannot connect to ' . $config['host'] . ':' . $config['port']
                    . ' (' . trim($errstr) . ') - every send will fail',
                'remediation' => $remediation,
            ];
        }

        stream_set_blocking($socket, true);
        fclose($socket);

        // Reachable is not the same as CORRECT. In aiosmtpd mode something else on
        // 127.0.0.1:1025 would happily relay mail to real people, so the greeting is
        // read before this row is allowed to say OK.
        $banner_error = static::aiosmtpd_banner_error();

        if ($banner_error !== null) {
            return [
                'label' => 'Mail transport',
                'status' => 'FAIL',
                'detail' => 'server did not advertise ' . self::AIOSMTPD_IDENT
                    . ' - ' . $banner_error . '; every send is refused',
                'remediation' => 'the listener on ' . self::AIOSMTPD_HOST . ':' . self::AIOSMTPD_PORT
                    . ' is not the development catcher - restart supervisor [program:mail-catcher],'
                    . ' or set MAIL_DELIVERY=live if this box is meant to send real mail',
            ];
        }

        return [
            'label' => 'Mail transport',
            'status' => 'OK',
            'detail' => 'accepting connections on ' . $config['host'] . ':' . $config['port']
                . ($mode === self::MODE_AIOSMTPD ? ' (greeting confirms the catcher)' : ''),
        ];
    }

    /**
     * Does the From domain publish the records a receiving server looks for.
     *
     * WARN, never FAIL: mail still sends without SPF or DMARC, it just lands in spam
     * folders - and that is a DNS change somebody makes at a registrar, not a fault of
     * this box. DKIM is only asked about when a selector is configured, because without
     * one there is no record to look up.
     */
    private static function _health_sender_domain_row(string $mode, string $from_address): array
    {
        $at = strrpos($from_address, '@');
        $domain = $at === false ? '' : strtolower(substr($from_address, $at + 1));

        if ($domain === '') {
            return [
                'label' => 'Mail sender domain',
                'status' => 'FAIL',
                'detail' => "rsx.mail.from_address is '{$from_address}' - that is not an email address",
                'remediation' => 'set MAIL_FROM_ADDRESS to a real address at a domain you control',
            ];
        }

        $example_domains = ['example.com', 'example.org', 'example.net'];

        if (in_array($domain, $example_domains, true) || $mode !== self::MODE_LIVE) {
            return [
                'label' => 'Mail sender domain',
                'status' => 'INFO',
                'detail' => 'development sender - deliverability not checked (' . $from_address . ')',
            ];
        }

        $missing = [];

        if (!static::_has_txt_record($domain, 'v=spf1')) {
            $missing[] = 'SPF (a "v=spf1 ..." TXT record on ' . $domain . ')';
        }

        if (!static::_has_txt_record('_dmarc.' . $domain, 'v=DMARC1')) {
            $missing[] = 'DMARC (a "v=DMARC1; ..." TXT record on _dmarc.' . $domain . ')';
        }

        $selector = (string) config('rsx.mail.dkim_selector', '');

        if ($selector !== '' && !static::_has_txt_record($selector . '._domainkey.' . $domain, 'v=DKIM1')) {
            $missing[] = 'DKIM (a TXT record on ' . $selector . '._domainkey.' . $domain . ')';
        }

        if ($missing !== []) {
            return [
                'label' => 'Mail sender domain',
                'status' => 'WARN',
                'detail' => $domain . ' publishes no ' . implode(', no ', $missing)
                    . ' - receiving servers are likely to spam-folder or reject this mail',
                'remediation' => 'publish the missing DNS record(s) for ' . $domain
                    . ' at the domain registrar',
            ];
        }

        return [
            'label' => 'Mail sender domain',
            'status' => 'OK',
            'detail' => $domain . ' publishes the sender records receiving servers look for',
        ];
    }

    /**
     * Whether $name has a TXT record beginning with $prefix.
     *
     * A long TXT value arrives split into chunks, so the chunks are joined before the
     * prefix test (dns_get_record exposes both 'txt' and the 'entries' array).
     */
    private static function _has_txt_record(string $name, string $prefix): bool
    {
        $records = @dns_get_record($name, DNS_TXT);

        if (!is_array($records)) {
            return false;
        }

        foreach ($records as $record) {
            $value = isset($record['entries']) && is_array($record['entries'])
                ? implode('', $record['entries'])
                : (string) ($record['txt'] ?? '');

            if (stripos($value, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The transport block, normalized to the types the DSN builder expects.
     */
    private static function _transport_config(): array
    {
        // MODE_AIOSMTPD IS NOT A DEFAULT, IT IS AN OVERRIDE. rsx.mail.transport.* is not
        // consulted at all: the mode names one specific listener on this box, and a
        // half-applied MAIL_HOST from an old deployment must not be able to redirect a
        // development install's mail at a real relay. Choosing MODE_LIVE is the one way
        // to make that block matter.
        if (static::delivery_mode() === self::MODE_AIOSMTPD) {
            return [
                'driver' => 'smtp',
                'host' => self::AIOSMTPD_HOST,
                'port' => self::AIOSMTPD_PORT,
                'encryption' => '',
                'username' => '',
                'password' => '',
                'sendmail_path' => '/usr/sbin/sendmail -bs -i',
            ];
        }

        $config = config('rsx.mail.transport', []);

        $driver = (string) ($config['driver'] ?? 'smtp');

        if ($driver !== 'smtp' && $driver !== 'sendmail') {
            throw new \RuntimeException(
                "rsx.mail.transport.driver is '{$driver}' - the only drivers are 'smtp' and 'sendmail'."
            );
        }

        $encryption = strtolower(trim((string) ($config['encryption'] ?? '')));

        if ($encryption !== '' && $encryption !== 'tls' && $encryption !== 'ssl') {
            throw new \RuntimeException(
                "rsx.mail.transport.encryption is '{$encryption}' - the only values are '', 'tls' and 'ssl'."
            );
        }

        return [
            'driver' => $driver,
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (int) ($config['port'] ?? 25),
            'encryption' => $encryption,
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
            'sendmail_path' => (string) ($config['sendmail_path'] ?? '/usr/sbin/sendmail -bs -i'),
        ];
    }
}
