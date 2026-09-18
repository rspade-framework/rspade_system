<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Rsx;

/**
 * Production_Health_Checks - the rows that only mean something on a SEALED box.
 *
 * Every check here declares modes: ['debug', 'production'], because every one of them
 * asks a question that has no answer in development: is the seal intact, is the box
 * serving over https, has a development convenience been left switched on. Asked in
 * development they would each report a "wrong" answer that is in fact the correct
 * development posture - which is the whole reason the mode axis exists.
 *
 * WHY THESE ROWS EXIST AT ALL when several of the conditions also throw at boot: a
 * throw tells an operator that the box is refusing, in the middle of whatever command
 * they ran. These rows are the place where the same conditions are ENUMERATED with
 * their remediation, on the one command whose job is to answer "is this box configured
 * correctly" before it is asked to serve. Where a condition is boot-fatal the row says
 * so, and says which spelling of the value it read - the guards read the raw
 * environment, these rows read the CONFIGURED value, and the two disagreeing is itself
 * the finding (a config cache baked by a build that predates the current .env).
 *
 * Every row builder is public and takes what it reports as parameters, because none of
 * these states is one a development box is in - a check whose failure branches cannot
 * be exercised is a check nobody has ever seen fail.
 *
 * @see rsx:man prod
 */
class Production_Health_Checks
{
    // =========================================================================
    // The seal
    // =========================================================================

    /**
     * Is the build on disk still the build that was sealed?
     *
     * The same comparison rsx:prod:verify performs - recomputed sha256 per sealed
     * asset, the build_key, and the mode the seal was built for. This is the cluster
     * drift check, reported on the command an operator already runs.
     *
     * @return array
     */
    #[Health_Check('Production Seal', modes: ['debug', 'production'])]
    public static function production_seal(): array
    {
        return static::_seal_row(Rsx_Prod_Seal::exists(), Rsx_Prod_Seal::exists() ? Rsx_Prod_Seal::verify() : []);
    }

    /**
     * @param bool $exists Whether a seal file is present
     * @param array $drift The findings rsx:prod:verify would report
     * @return array{status: string, detail: string, remediation: ?string}
     */
    public static function _seal_row(bool $exists, array $drift): array
    {
        // An unsealed prod box refuses every request and every command at the manifest
        // gate, so in practice this row is never reached with $exists false. It is
        // stated rather than assumed: a health command that would silently pass on a
        // box with no seal is worse than one that names it.
        if (!$exists) {
            return [
                'status' => 'FAIL',
                'detail' => 'no seal present - this production build has never been built',
                'remediation' => 'php artisan rsx:build --force',
            ];
        }

        if (empty($drift)) {
            return ['status' => 'OK', 'detail' => 'every sealed asset matches the seal', 'remediation' => null];
        }

        return [
            'status' => 'FAIL',
            'detail' => 'the build on disk has drifted from its seal: ' . implode(' ', $drift),
            'remediation' => 'php artisan rsx:build --force (php artisan rsx:prod:verify lists every drifted file)',
        ];
    }

    // =========================================================================
    // The environment a sealed box must have
    // =========================================================================

    /**
     * The scheme the application believes it is served over.
     *
     * Rsx_App_Url::enforce_scheme_from_env() already THROWS at boot on a non-https
     * APP_URL outside development, reading the raw environment. This row reads the
     * CONFIGURED value instead, which on a sealed box comes from the cached config the
     * build produced - so it catches the one shape the boot guard cannot see: a .env
     * repaired after the build, leaving the running application still addressing itself
     * over http in every mail link, OAuth redirect and absolute URL it generates.
     *
     * @return array
     */
    #[Health_Check('APP_URL Scheme', modes: ['debug', 'production'])]
    public static function app_url_scheme(): array
    {
        return static::_app_url_row((string) config('app.url'));
    }

    /**
     * @return array{status: string, detail: string, remediation: ?string}
     */
    public static function _app_url_row(string $app_url): array
    {
        $scheme = strtolower((string) parse_url($app_url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            return ['status' => 'OK', 'detail' => $app_url, 'remediation' => null];
        }

        return [
            'status' => 'FAIL',
            'detail' => 'the application addresses itself as ' . ($app_url === '' ? '(empty)' : $app_url)
                . ' - a sealed build emits Secure session cookies a plain-http page discards',
            'remediation' => 'set APP_URL=https://... in .env, then php artisan rsx:build --force'
                . ' (the running value comes from the cached config the build produced)',
        ];
    }

    /**
     * The hostname a sealed box addresses itself by.
     *
     * `.dev.` in a hostname is not cosmetic here: Rsx::is_dev_site() reads it, and two
     * outbound channels gate on that answer. With live delivery every email recipient
     * on such a host is checked against the dev-site whitelists and otherwise rewritten
     * to the catchall; an SMS is gated whatever the delivery mode is, and one with no
     * whitelist match is recorded Suppressed and never sent. So a production site that
     * carries a `.dev.` hostname silently stops writing to its own users - the queue
     * drains, the rows read Sent or Suppressed, and nobody is told.
     *
     * The host is read from the CONFIGURED application URL, exactly as app_url_scheme()
     * reads the scheme from it, so on a sealed box this is the value the running
     * application actually addresses itself by.
     *
     * @return array
     */
    #[Health_Check('Hostname', modes: ['debug', 'production'])]
    public static function hostname(): array
    {
        return static::_hostname_row((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    /**
     * @param string $host The host of the configured application URL
     * @return array{status: string, detail: string, remediation: ?string}
     */
    public static function _hostname_row(string $host): array
    {
        if (!str_contains($host, '.dev.')) {
            return [
                'status' => 'OK',
                'detail' => ($host === '' ? '(empty)' : $host) . ' is not a development hostname',
                'remediation' => null,
            ];
        }

        return [
            'status' => 'FAIL',
            'detail' => 'a production site cannot carry a .dev. hostname: ' . $host
                . ' makes Rsx::is_dev_site() true, so every outbound email is checked against the'
                . ' dev-site whitelists and otherwise redirected to the catchall even when delivery'
                . ' is live, and an SMS with no whitelist match is recorded Suppressed and never sent',
            'remediation' => 'set APP_URL to the production hostname in .env, then php artisan rsx:build --force'
                . ' (the running value comes from the cached config the build produced)',
        ];
    }

    /**
     * Credential auto-fill on a sealed build.
     *
     * RSPADE_LOGIN_AUTOFILL pre-fills RSPADE_DEFAULT_EMAIL / RSPADE_DEFAULT_PASSWORD
     * into the login form, which puts a working credential on an unauthenticated page.
     * It is a development convenience and there is no reading of it that is acceptable
     * on a sealed box.
     *
     * @return array
     */
    #[Health_Check('Login Auto-fill', modes: ['debug', 'production'])]
    public static function login_autofill(): array
    {
        return static::_login_autofill_row((bool) config('rsx.development.login_autofill'));
    }

    /**
     * @return array{status: string, detail: string, remediation: ?string}
     */
    public static function _login_autofill_row(bool $enabled): array
    {
        if (!$enabled) {
            return ['status' => 'OK', 'detail' => 'off', 'remediation' => null];
        }

        return [
            'status' => 'FAIL',
            'detail' => 'the login form pre-fills RSPADE_DEFAULT_EMAIL / RSPADE_DEFAULT_PASSWORD'
                . ' - a working credential on an unauthenticated page',
            'remediation' => 'set RSPADE_LOGIN_AUTOFILL= (empty) in .env, then php artisan rsx:build --force',
        ];
    }

    /**
     * Which console_debug posture this sealed variant has.
     *
     * Strict production strips every console_debug() call site from the compiled bundle
     * and returns early in the PHP gate; debug keeps both, which is the entire point of
     * the debug variant. Neither is wrong, so this is INFO - it names the variant, so an
     * operator who finds debug output in a browser can see in one line why.
     *
     * @return array
     */
    #[Health_Check('Console Debug', modes: ['debug', 'production'])]
    public static function console_debug_posture(): array
    {
        return static::_console_debug_row(Rsx::get_mode(), (bool) config('rsx.console_debug.enabled', true));
    }

    /**
     * @return array{status: string, detail: string, remediation: ?string}
     */
    public static function _console_debug_row(string $mode, bool $enabled): array
    {
        if ($mode === Rsx::MODE_PRODUCTION) {
            return [
                'status' => 'INFO',
                'detail' => 'strict production: every console_debug() call site is stripped from the bundle'
                    . ' and the PHP gate returns early, whatever CONSOLE_DEBUG_ENABLED says'
                    . ' (currently ' . ($enabled ? 'true' : 'false') . ')',
                'remediation' => null,
            ];
        }

        return [
            'status' => 'INFO',
            'detail' => 'debug variant: console_debug() is intact in PHP and in the bundle, and is currently '
                . ($enabled ? 'enabled' : 'disabled by CONSOLE_DEBUG_ENABLED'),
            'remediation' => 'php artisan rsx:mode:set prod for the strict variant, which strips it',
        ];
    }

    /**
     * The development mail catcher on a sealed box.
     *
     * aiosmtpd delivery captures every message into storage/mail-catcher and nothing
     * leaves the host. That is the correct development default and a silent outage in
     * production: the queue drains, every row reads Sent, and no recipient is ever
     * written to.
     *
     * WARN rather than FAIL: a staging box deliberately catching its own mail is a
     * legitimate configuration, and rsx:health's exit code gates deploys.
     *
     * @return array
     */
    #[Health_Check('Mail Delivery Target', modes: ['debug', 'production'])]
    public static function mail_delivery_target(): array
    {
        return static::_mail_delivery_row(Rsx_Mail_Transport::delivery_mode());
    }

    /**
     * @return array{status: string, detail: string, remediation: ?string}
     */
    public static function _mail_delivery_row(string $delivery): array
    {
        if ($delivery !== 'aiosmtpd') {
            return ['status' => 'OK', 'detail' => 'rsx.mail.delivery is ' . $delivery, 'remediation' => null];
        }

        return [
            'status' => 'WARN',
            'detail' => 'the development mail catcher is the delivery target on a sealed build'
                . ' - every message is captured locally and no recipient is written to',
            'remediation' => 'set MAIL_DELIVERY=live in .env (rsx:man email), or leave it if this box'
                . ' is deliberately catching its own mail',
        ];
    }
}
