<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Login;

use RuntimeException;

/**
 * Login_Notice - one line a sign-in page shows inline, chosen by a fixed code in the URL.
 *
 * "You have been signed out." "This invitation has expired." A flow that ends on the sign-in
 * page often owes the visitor a sentence, and a session flash is the wrong carrier: it renders
 * as a floating toast and is gone after one hop. So the sentence travels as a CODE, beside the
 * threaded redirect, and the sign-in page renders it in the form:
 *
 *     return redirect(Rsx_Portal::Route('Portal_Login_Controller::index',
 *         Login_Notice::params('invitation_expired') + Login_Redirect::params()));
 *
 *     @php $notice = Login_Notice::current(); @endphp
 *
 * THE APPLICATION DECLARES THE CODES, in config('rsx.login_notices'):
 *
 *     'login_notices' => [
 *         'invitation_expired' => ['level' => 'error', 'text' => 'This invitation has expired.'],
 *     ]
 *
 * level is 'notice' or 'error'; text may carry {name} for config('rsx.name'). NO FREE TEXT
 * CROSSES THE URL: current() answers only a declared code, so a crafted link can put nothing on
 * the page but a sentence the application wrote, and an unknown code reads as no notice at all
 * (the silent degradation Login_Redirect applies to the same kind of input). params() is the
 * developer's side and fails loud on a code nobody declared.
 *
 * See: php artisan rsx:man login_redirect, LOGIN NOTICES.
 */
class Login_Notice
{
    /** The query parameter. PRIVATE - nothing outside this class types the string. */
    private const PARAM = 'notice';

    public const LEVEL_NOTICE = 'notice';
    public const LEVEL_ERROR = 'error';

    /**
     * The parameter that carries $code, for merging into a route to a sign-in page.
     *
     * @throws RuntimeException When the application declares no such code
     */
    public static function params(string $code): array
    {
        if (static::__declared($code) === null) {
            throw new RuntimeException("Login_Notice: no notice '{$code}' is declared in rsx.login_notices.");
        }

        return [self::PARAM => $code];
    }

    /**
     * The notice this request carries: {code, level, text} - or null when it carries none, or
     * a code the application does not declare.
     */
    public static function current(): ?array
    {
        $code = request()->query(self::PARAM);
        if (!is_string($code) || $code === '') {
            return null;
        }

        $notice = static::__declared($code);
        if ($notice === null) {
            return null;
        }

        return [
            'code' => $code,
            'level' => $notice['level'],
            'text' => str_replace('{name}', (string) config('rsx.name', ''), $notice['text']),
        ];
    }

    /** A declared notice, or null; a malformed declaration fails loud. */
    private static function __declared(string $code): ?array
    {
        $notice = config('rsx.login_notices')[$code] ?? null;
        if ($notice === null) {
            return null;
        }

        if (!in_array($notice['level'] ?? null, [self::LEVEL_NOTICE, self::LEVEL_ERROR], true) || !is_string($notice['text'] ?? null)) {
            throw new RuntimeException("rsx.login_notices.{$code} must be ['level' => 'notice'|'error', 'text' => '...'].");
        }

        return $notice;
    }
}
