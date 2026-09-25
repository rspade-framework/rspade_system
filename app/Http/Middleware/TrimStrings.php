<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

/**
 * Trims request strings, except where the whitespace IS the value.
 *
 * Two kinds of input are never touched:
 *
 *   - ANY FIELD WHOSE NAME CONTAINS "password", in any case and at any depth
 *     (password, current_password, new_password_confirm, user.Password ...). A
 *     password with a leading or trailing space is a different password; trimming it
 *     locks the user out of the one they chose. Matched by pattern, never by a list of
 *     names, so a form nobody enumerated is covered too.
 *   - A TEXT-TYPE ENVELOPE ({__TEXT, raw, empty}, rsx:man text_types): its raw is the
 *     encoded content exactly as the editor produced it, and the declared type is what
 *     sanitizes it. The whole envelope passes through untouched.
 */
class TrimStrings extends Middleware
{
    /**
     * Clean one value: a text-type envelope is returned as it arrived.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    protected function cleanValue($key, $value)
    {
        if (is_array($value) && array_key_exists('__TEXT', $value)) {
            return $value;
        }

        return parent::cleanValue($key, $value);
    }

    /**
     * Skip any key whose dotted path contains "password", case-insensitively.
     *
     * @param string $key
     * @param array $except
     * @return bool
     */
    protected function shouldSkip($key, $except)
    {
        if (stripos((string) $key, 'password') !== false) {
            return true;
        }

        return parent::shouldSkip($key, $except);
    }
}
