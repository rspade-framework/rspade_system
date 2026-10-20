<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Models\Portal_User_Model;

/**
 * "Which portal user does --portal-user name?" - answered ONCE, for every framework CLI
 * command that operates on a client portal identity. The portal sibling of
 * Login_User_Cli_Support.
 *
 * A portal user is site-scoped: portal_users.email is unique per SITE, not globally, so an
 * email alone names one account per tenant and is refused without --site. A numeric value is
 * a portal_users.id, which is global, so --site is then only cross-checked. The lookup reads
 * across sites (a command serves no tenant); soft-deleted portal users are excluded by the
 * model's own scope, as a deleted identity is not one an operator administers.
 *
 * THE ERROR CODES ARE PART OF THE CONTRACT ('portal_user_required', 'portal_user_not_found',
 * 'site_required', 'site_invalid', 'site_mismatch'): reported as error.code in --json output, never localized or reworded.
 */
class Portal_User_Cli_Support
{
    /**
     * Resolve the portal user named by --portal-user (and --site).
     *
     * @param mixed $portal_user_option The --portal-user value: an id, or an email with --site
     * @param mixed $site_option The --site value, or null
     * @return Portal_User_Model
     * @throws Api_Cli_Error when the option does not resolve
     */
    public static function resolve_portal_user($portal_user_option, $site_option): Portal_User_Model
    {
        $needle = trim((string) ($portal_user_option ?? ''));
        $site = trim((string) ($site_option ?? ''));

        if ($needle === '') {
            throw new Api_Cli_Error(
                'portal_user_required',
                '--portal-user needs a value: a portal_users.id, or an email address with --site.'
            );
        }

        if ($site !== '' && !ctype_digit($site)) {
            throw new Api_Cli_Error('site_invalid', "--site must be a sites.id (got '{$site}').");
        }

        if (ctype_digit($needle)) {
            $portal_user = Portal_User_Model::without_site_scope(
                fn () => Portal_User_Model::where('id', (int) $needle)->first()
            );

            if (!$portal_user) {
                throw new Api_Cli_Error('portal_user_not_found', "No portal user with id {$needle}.");
            }

            if ($site !== '' && (int) $portal_user->site_id !== (int) $site) {
                throw new Api_Cli_Error(
                    'site_mismatch',
                    "Portal user {$needle} belongs to site {$portal_user->site_id}, not site {$site}."
                );
            }

            return $portal_user;
        }

        if ($site === '') {
            throw new Api_Cli_Error(
                'site_required',
                'A portal user email is unique per site: pass --site=<sites.id> with it, or name the '
                . 'portal user by id (--portal-user=12).'
            );
        }

        $portal_user = Portal_User_Model::without_site_scope(
            fn () => Portal_User_Model::find_by_email((int) $site, $needle)
        );

        if (!$portal_user) {
            throw new Api_Cli_Error('portal_user_not_found', "No portal user on site {$site} matches '{$needle}'.");
        }

        return $portal_user;
    }

    /**
     * The identity every envelope carries.
     */
    public static function portal_user_data(Portal_User_Model $portal_user): array
    {
        return [
            'id' => (int) $portal_user->id,
            'site_id' => (int) $portal_user->site_id,
            'email' => $portal_user->email,
        ];
    }

    /**
     * The identifying line a command opens its human output with.
     */
    public static function print_identity(Command $command, Portal_User_Model $portal_user): void
    {
        $command->line('  Portal user: ' . $portal_user->id . ' (' . $portal_user->email . ', site ' . $portal_user->site_id . ')');
    }
}
