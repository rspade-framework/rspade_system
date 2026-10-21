<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Console;

use App\RSpade\Core\Console\Rsx_Cli_Actor_Exception;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;

/**
 * Rsx_Cli_Actor - who a command line acts as: the `--user=<id|email>` and `--site=<id>` flags.
 *
 * ONE resolution for every command that takes the pair (rsx:ajax, rsx:task:run), so the two
 * entry points into application code cannot disagree about what the flags mean:
 *
 *   --user   a login identity: a numeric login_users id, or an email address
 *            (Login_User_Model::find_by_email()). It must exist.
 *   --site   a site id. It must exist. With --user it is REQUIRED when the identity has
 *            users rows on more than one site, or on none; with exactly one, that site is
 *            used.
 *
 * establish() then declares the context on the process's virtual session:
 *
 *   - with --user: Session::impersonate($site_id, $login_user_id, $user_id) - the site, the
 *     login identity AND the site user (the identity's users row there, or null when it has
 *     none on that site). An identity has three parts and a user-scoped endpoint or task
 *     reads the third; setting the login identity alone (Session::set_login_user_id()) leaves
 *     get_user_id() answering null in a command, so everything user-scoped runs as nobody
 *     with a correct actor stamp and no warning (a downstream field report, 2026-09-04).
 *   - with --site alone: Session::set_site_id() - a site, and nobody acting.
 *
 * No sign-in is created: no browser row, no user session, no audit session. A command is not
 * a request, and the context lives in the virtual session's statics alone.
 */
class Rsx_Cli_Actor
{
    /**
     * Resolve the two flags' raw values. Nothing is declared yet.
     *
     * @param string|null $user_input --user, or null when absent
     * @param string|null $site_input --site, or null when absent
     * @return array{login_user_id: int|null, site_id: int|null, user_id: int|null}
     * @throws Rsx_Cli_Actor_Exception A flag names nothing, or --site is needed and absent
     */
    public static function resolve(?string $user_input, ?string $site_input): array
    {
        $login_user_id = $user_input === null ? null : static::__resolve_login_user($user_input);
        $site_id = $site_input === null ? null : static::__resolve_site($site_input);
        $user_id = null;

        if ($login_user_id !== null) {
            $site_id ??= static::__resolve_site_for_login_user($login_user_id);
            $user_id = static::__resolve_site_user($login_user_id, $site_id);
        }

        return ['login_user_id' => $login_user_id, 'site_id' => $site_id, 'user_id' => $user_id];
    }

    /**
     * Declare a resolved context on this process's virtual session (see the class docblock).
     * Nothing is declared when neither flag was given.
     *
     * @param array{login_user_id: int|null, site_id: int|null, user_id: int|null} $actor resolve()'s answer
     * @return void
     */
    public static function establish(array $actor): void
    {
        if ($actor['login_user_id'] !== null) {
            Session::impersonate($actor['site_id'], $actor['login_user_id'], $actor['user_id']);
        } elseif ($actor['site_id'] !== null) {
            Session::set_site_id($actor['site_id']);
        }
    }

    private static function __resolve_login_user(string $user_input): int
    {
        if (str_contains($user_input, '@')) {
            $login_user = Login_User_Model::find_by_email($user_input);
            if (!$login_user) {
                throw new Rsx_Cli_Actor_Exception("User not found: {$user_input}", 'user_not_found');
            }

            return (int) $login_user->id;
        }

        if (!ctype_digit($user_input)) {
            throw new Rsx_Cli_Actor_Exception("Invalid user identifier: {$user_input} (must be numeric ID or email address)", 'invalid_user');
        }

        $login_user_id = (int) $user_input;
        if (!Login_User_Model::find($login_user_id)) {
            throw new Rsx_Cli_Actor_Exception("User ID not found: {$login_user_id}", 'user_not_found');
        }

        return $login_user_id;
    }

    private static function __resolve_site(string $site_input): int
    {
        if (!ctype_digit($site_input)) {
            throw new Rsx_Cli_Actor_Exception("Invalid site identifier: {$site_input} (must be numeric ID)", 'invalid_site');
        }

        $site_id = (int) $site_input;
        if (!Site_Model::find($site_id)) {
            throw new Rsx_Cli_Actor_Exception("Site ID not found: {$site_id}", 'site_not_found');
        }

        return $site_id;
    }

    /**
     * The site to act on when --site was not given: the one site the login identity has a
     * users row on. A web request always runs under a site (it is resolved from the host),
     * so acting under no site at all is not a state worth reproducing; when the answer is
     * not unique the caller is told to choose.
     *
     * @DB-UNBOUNDED-01-EXCEPTION - narrowed to ONE login identity's memberships, at most one
     * users row per site; the whole list is wanted, since the refusal names every site.
     */
    private static function __resolve_site_for_login_user(int $login_user_id): int
    {
        // Bounded: one identity's memberships (see the docblock).
        $site_ids = User_Model::without_site_scope(function () use ($login_user_id) {
            return User_Model::where('login_user_id', $login_user_id)
                ->orderBy('site_id')
                ->get()
                ->pluck('site_id')
                ->unique()
                ->values()
                ->all();
        });

        if (count($site_ids) === 1) {
            return (int) $site_ids[0];
        }

        if (count($site_ids) === 0) {
            throw new Rsx_Cli_Actor_Exception("Login user {$login_user_id} has no users row on any site; pass --site=<id> to choose the site to run under", 'site_required');
        }

        throw new Rsx_Cli_Actor_Exception("Login user {$login_user_id} has users rows on sites " . implode(', ', $site_ids) . '; pass --site=<id> to choose one', 'site_required');
    }

    /**
     * The users.id of a login identity on one site, or null when it has no row there. Read
     * outside the site scope: the scope keys on Session::get_site_id(), which is what this
     * lookup is about to establish.
     *
     * A DISABLED membership or site is refused, exactly as a sign-in and a
     * #[Run_As_Dispatcher] worker refuse it (User_Model::is_active(): users.is_enabled and
     * sites.is_enabled): a command line never acts as somebody the application has shut out.
     */
    private static function __resolve_site_user(int $login_user_id, int $site_id): ?int
    {
        $site = Site_Model::find($site_id);
        if ($site !== null && !$site->is_active()) {
            throw new Rsx_Cli_Actor_Exception("Site {$site_id} is disabled; nobody can act on it", 'site_disabled');
        }

        return User_Model::without_site_scope(function () use ($login_user_id, $site_id) {
            $user = User_Model::where('login_user_id', $login_user_id)
                ->where('site_id', $site_id)
                ->first();

            if ($user !== null && !$user->is_active()) {
                throw new Rsx_Cli_Actor_Exception("Login user {$login_user_id}'s membership on site {$site_id} is disabled; a disabled membership cannot act", 'membership_disabled');
            }

            return $user ? (int) $user->id : null;
        });
    }
}
