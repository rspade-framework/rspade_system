<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Carbon\Carbon;
use App\RSpade\Core\Models\User_Model;

/**
 * Shared argument handling for the rsx:api:key:* commands.
 *
 * Separate from the commands themselves so "how a user is named" and "how an expiry is
 * spelled" are answered ONCE - three commands accepting the same argument three subtly
 * different ways is how a CLI becomes untrustworthy.
 */
class Api_Key_Cli_Support
{
    /**
     * Resolve a user from an id or an email address.
     *
     * The site scope is bypassed: an operator on the CLI has no site context, and refusing to
     * find a user because the process has no session would be an accident of the environment
     * rather than an answer.
     */
    public static function resolve_user(string $needle): ?User_Model
    {
        return User_Model::without_site_scope(function () use ($needle) {
            if (ctype_digit($needle)) {
                return User_Model::find((int) $needle);
            }

            return User_Model::where('email', $needle)->first();
        });
    }

    /**
     * Parse an expiry given as an ISO datetime ('2027-01-01T00:00:00Z') or as a relative
     * span ('30 days', '6 months', '1 year').
     *
     * Both spellings are accepted because both are natural at a prompt, and guessing wrong
     * about which one an operator meant would set a wrong expiry silently. An unparseable
     * value throws rather than defaulting - the whole point of passing --expires is that the
     * caller has a specific date in mind.
     *
     * @throws \InvalidArgumentException when the value cannot be understood
     */
    public static function parse_expiry(string $value): Carbon
    {
        $value = trim($value);

        try {
            $parsed = Carbon::parse($value);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                "Could not understand --expires='{$value}'. Use an ISO datetime "
                . "(2027-01-01) or a relative span (\"30 days\", \"6 months\")."
            );
        }

        return $parsed;
    }
}
