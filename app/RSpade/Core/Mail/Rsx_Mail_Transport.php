<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Mail;

use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use App\RSpade\Core\Models\Email_Queue_Model;

/**
 * Rsx_Mail_Transport - the one place a mail transport is constructed.
 *
 * In 'live' mode the transport is the mailer Laravel's mail config names (config('mail'),
 * MAIL_MAILER and friends), built by Laravel's MailManager - so any Laravel mailer, and any
 * transport a composer package registers with Mail::extend(), carries the queue. In
 * 'aiosmtpd' mode it is the fixed development catcher. Either way it is built here and
 * nowhere else, so "what does this install actually send through" has one answer,
 * readable by the queue drain, by rsx:mail:test and by the health check alike.
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

    /** Real delivery through the mailer config('mail.default') names, with the dev-site recipient gate. */
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
     * Fixed, not defaulted: in this mode Laravel's mail config is IGNORED entirely, so a
     * box whose MAIL_HOST still names last year's relay cannot mail anybody by accident.
     * Point it somewhere real by choosing MODE_LIVE, which is the switch that says so.
     */
    const AIOSMTPD_HOST = '127.0.0.1';
    const AIOSMTPD_PORT = 1025;

    /** The substring the catcher's SMTP greeting must contain. */
    const AIOSMTPD_IDENT = 'aiosmtpd';

    /**
     * Laravel transports that accept a message and deliver it nowhere. 'live' refuses
     * them, because the queue would record every row SENT - see mailer_config().
     */
    const NON_DELIVERING_TRANSPORTS = ['log', 'array'];

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
     * Rsx_Project_Paths::_override(): a test sets it, a test clears it in a finally,
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
     *
     * 'aiosmtpd' is the fixed development catcher, whatever Laravel's mail config says.
     * 'live' is the mailer config('mail.default') names, built by Laravel's own
     * MailManager - so every Laravel mailer, and every transport a package registers
     * with Mail::extend(), carries this queue unchanged. A FRESH transport per call:
     * the drain rebuilds after a connection failure, and a mailer Laravel had cached
     * would hand back the broken one.
     *
     * 'suppressed' and 'disabled' never open a transport and never call this.
     */
    public static function make(): TransportInterface
    {
        if (static::$override_for_tests !== null) {
            return static::$override_for_tests;
        }

        if (static::delivery_mode() === self::MODE_AIOSMTPD) {
            return Transport::fromDsn(static::aiosmtpd_dsn());
        }

        $config = static::mailer_config();

        return app('mail.manager')->createSymfonyTransport($config);
    }

    /**
     * The development catcher's DSN.
     *
     * auto_tls=false is explicit, not merely unmentioned: left to itself Symfony
     * STARTTLSes whenever a server advertises it, and the catcher is plain loopback.
     */
    public static function aiosmtpd_dsn(): string
    {
        return 'smtp://' . self::AIOSMTPD_HOST . ':' . self::AIOSMTPD_PORT . '?auto_tls=false';
    }

    /**
     * The name of the mailer 'live' sends through - config('mail.default').
     *
     * Stored on every row it sends (email_queue.transport), so it must fit that column;
     * a name that does not is refused HERE, before a message goes out, rather than by the
     * database after it has.
     */
    public static function mailer_name(): string
    {
        $name = trim((string) config('mail.default', ''));

        if ($name === '') {
            throw new \RuntimeException(
                "mail.default is empty - set MAIL_MAILER to one of the mailers in config('mail.mailers')."
            );
        }

        $max = Email_Queue_Model::field_length('transport');

        if ($max !== null && mb_strlen($name) > $max) {
            throw new \RuntimeException(
                "The mailer name '{$name}' is longer than {$max} characters, the width of the email "
                . "queue's transport column - rename the mailer in rsx/resource/config/mail.php."
            );
        }

        return $name;
    }

    /**
     * The configuration array of the mailer 'live' sends through.
     *
     * Throws when MAIL_MAILER names no mailer, and when it names one that delivers
     * nothing: 'log' and 'array' would record every row SENT while no message left the
     * box, which is the one outcome worse than an error. Recording without delivering
     * is what MAIL_DELIVERY=suppressed is for.
     */
    public static function mailer_config(): array
    {
        $name = static::mailer_name();
        $config = static::_resolve_mailer($name);

        if ($config === null) {
            $known = implode("', '", array_keys((array) config('mail.mailers', [])));

            throw new \RuntimeException(
                "MAIL_MAILER is '{$name}', but config('mail.mailers') has no such mailer - the mailers are '{$known}'. "
                . "Declare it in rsx/resource/config/mail.php."
            );
        }

        $transport = (string) ($config['transport'] ?? '');

        if (in_array($transport, self::NON_DELIVERING_TRANSPORTS, true)) {
            throw new \RuntimeException(
                "MAIL_DELIVERY is live, but mailer '{$name}' uses the '{$transport}' transport, which delivers nothing - "
                . "every message would be recorded SENT and none would arrive. Use MAIL_DELIVERY=suppressed "
                . "to record without sending, or point MAIL_MAILER at a mailer that delivers."
            );
        }

        return $config;
    }

    /**
     * A mailer's configuration exactly as Laravel's MailManager resolves it, or null when
     * config('mail.mailers') has no such mailer: a 'url' (MAIL_URL) is parsed into the
     * keys it supplies and names the transport, as MailManager::getConfig() does it.
     */
    private static function _resolve_mailer(string $name): ?array
    {
        $config = config('mail.mailers.' . $name);

        if (!is_array($config)) {
            return null;
        }

        if (isset($config['url'])) {
            $config = array_merge($config, (new ConfigurationUrlParser())->parseConfiguration($config));
            $config['transport'] = Arr::pull($config, 'driver');
        }

        return $config;
    }

    /**
     * What goes in email_queue.transport for a row this install hands to a transport.
     */
    public static function transport_label(): string
    {
        return static::delivery_mode() === self::MODE_AIOSMTPD
            ? self::MODE_AIOSMTPD
            : static::mailer_name();
    }

    /**
     * The From address every queued email is sent from - config('mail.from.address').
     */
    public static function from_address(): string
    {
        return trim((string) config('mail.from.address', ''));
    }

    /**
     * The From display name - config('mail.from.name'), or the application name when
     * that is empty.
     */
    public static function from_name(): string
    {
        $name = trim((string) config('mail.from.name', ''));

        return $name !== '' ? $name : (string) config('rsx.name', '');
    }

    /**
     * A one-line description of the transport, for narration and health rows.
     *
     * Never contains a password or an API key: only the host, port, scheme and user of
     * an SMTP mailer, the path of a sendmail one, and the transport name of anything
     * else.
     */
    public static function describe(): string
    {
        if (static::delivery_mode() === self::MODE_AIOSMTPD) {
            return 'smtp ' . self::AIOSMTPD_HOST . ':' . self::AIOSMTPD_PORT . ' (development catcher)';
        }

        $name = static::mailer_name();
        $config = static::_resolve_mailer($name) ?? [];
        $transport = (string) ($config['transport'] ?? '?');

        if ($transport === 'smtp') {
            $smtp = static::_smtp_endpoint($config);
            $description = "mailer '{$name}': " . $smtp['scheme'] . ' ' . $smtp['host'] . ':' . $smtp['port'];

            if (!empty($config['require_tls'])) {
                $description .= ' (STARTTLS required)';
            }

            if ($smtp['username'] !== '') {
                $description .= ' as ' . $smtp['username'];
            }

            return $description;
        }

        if ($transport === 'sendmail') {
            return "mailer '{$name}': sendmail " . (string) ($config['path'] ?? '');
        }

        if ($transport === 'failover' || $transport === 'roundrobin') {
            return "mailer '{$name}': {$transport} over '" . implode("', '", (array) ($config['mailers'] ?? [])) . "'";
        }

        return "mailer '{$name}': {$transport} transport";
    }

    /**
     * Where an SMTP mailer connects. The scheme follows Laravel: 'smtps' when declared or
     * when the port is 465, 'smtp' when not.
     *
     * @return array{scheme: string, host: string, port: int, username: string}
     */
    private static function _smtp_endpoint(array $config): array
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 25);
        $scheme = (string) ($config['scheme'] ?? '');

        if ($scheme === '') {
            $scheme = $port === 465 ? 'smtps' : 'smtp';
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'username' => (string) ($config['username'] ?? '')];
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
     * MODE: every mode. Mail is delivered in every mode, and each of the four delivery
     * modes is a legitimate configuration somewhere - which is precisely why the row is
     * descriptive here. Whether the DEVELOPMENT CATCHER is an appropriate target for a
     * SEALED build is a different question, asked by the prod-only "Mail Delivery
     * Target" row (Production_Health_Checks).
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
        $from_address = static::from_address();

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
            // An unusable mailer is the transport row's FAIL to report, with its reason.
            try {
                $via = static::describe();
            } catch (\RuntimeException $e) {
                $via = 'an unusable mailer (see Mail transport)';
            }

            $rows[] = [
                'label' => 'Mail delivery',
                'status' => 'OK',
                'detail' => 'live via ' . $via . ' from ' . $from_address,
            ];
        }

        $rows[] = static::_health_transport_row($mode);
        $rows[] = static::_health_sender_domain_row($mode, $from_address);

        return $rows;
    }

    /**
     * Can the configured transport be reached right now.
     *
     * What "reached" can mean depends on the transport. An SMTP host gets a read-only
     * TCP connect; a sendmail binary gets an executable check; a composite (failover,
     * roundrobin) is described, its members not probed. Anything else - an HTTP API
     * transport, one a package registered - is CONSTRUCTED and nothing more: that proves
     * the package is installed and its driver registered without sending anything, and
     * whether the service accepts the credentials is what rsx:mail:test is for.
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

        if ($mode === self::MODE_AIOSMTPD) {
            return static::_health_smtp_row(self::AIOSMTPD_HOST, self::AIOSMTPD_PORT, $mode);
        }

        try {
            $config = static::mailer_config();
        } catch (\RuntimeException $e) {
            return [
                'label' => 'Mail transport',
                'status' => 'FAIL',
                'detail' => $e->getMessage(),
                'remediation' => 'set MAIL_MAILER to a delivering mailer in config(\'mail.mailers\') - rsx:man email, MAIL TRANSPORTS',
            ];
        }

        $transport = (string) ($config['transport'] ?? '');

        if ($transport === 'smtp') {
            $smtp = static::_smtp_endpoint($config);

            return static::_health_smtp_row($smtp['host'], $smtp['port'], $mode);
        }

        if ($transport === 'sendmail') {
            $command = trim((string) ($config['path'] ?? ''));
            $binary = explode(' ', $command)[0];

            if (!is_executable($binary)) {
                return [
                    'label' => 'Mail transport',
                    'status' => 'FAIL',
                    'detail' => "sendmail binary '{$binary}' is not executable - nothing can be sent",
                    'remediation' => 'install an MTA providing ' . $binary . ', or point MAIL_MAILER at another mailer',
                ];
            }

            return [
                'label' => 'Mail transport',
                'status' => 'OK',
                'detail' => static::describe(),
            ];
        }

        if ($transport === 'failover' || $transport === 'roundrobin') {
            return [
                'label' => 'Mail transport',
                'status' => 'INFO',
                'detail' => static::describe() . ' - members not probed; rsx:mail:test proves delivery',
            ];
        }

        try {
            app('mail.manager')->createSymfonyTransport($config);
        } catch (\Throwable $e) {
            return [
                'label' => 'Mail transport',
                'status' => 'FAIL',
                'detail' => static::describe() . ' cannot be constructed: ' . $e->getMessage(),
                'remediation' => 'install the transport\'s package (php artisan rsx:composer require ...) and register '
                    . 'its service provider in rsx.integrations.providers - rsx:man email, MAIL TRANSPORTS',
            ];
        }

        return [
            'label' => 'Mail transport',
            'status' => 'OK',
            'detail' => static::describe() . ' - constructed; rsx:mail:test proves the service accepts mail',
        ];
    }

    /**
     * A read-only TCP connect to an SMTP host - plus, in aiosmtpd mode, the greeting.
     */
    private static function _health_smtp_row(string $host, int $port, string $mode): array
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);

        if ($socket === false) {
            $remediation = $mode === self::MODE_AIOSMTPD
                ? 'start the development mail catcher - check supervisor [program:mail-catcher]'
                : 'check MAIL_HOST/MAIL_PORT (or MAIL_URL) and that the relay accepts connections from this host';

            return [
                'label' => 'Mail transport',
                'status' => 'FAIL',
                'detail' => 'cannot connect to ' . $host . ':' . $port
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
            'detail' => 'accepting connections on ' . $host . ':' . $port
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
                'detail' => "mail.from.address is '{$from_address}' - that is not an email address",
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
}
