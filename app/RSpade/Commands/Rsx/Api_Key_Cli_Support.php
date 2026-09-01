<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Carbon\Carbon;
use Illuminate\Console\Command;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Scope_Validation_Exception;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Shared argument handling and output shaping for the rsx:api:* commands.
 *
 * Separate from the commands themselves so "how a user is named", "how an expiry is
 * spelled" and "what a JSON envelope looks like" are answered ONCE - five commands
 * accepting the same flags five subtly different ways is how a CLI becomes untrustworthy,
 * and a script that has to special-case one command's envelope will not be written at all.
 */
class Api_Key_Cli_Support
{
    /**
     * The JSON envelope, success form:
     *
     *     {"ok": true, "command": "rsx:api:key:create", "data": { ... }}
     *
     * Emitted on STDOUT and nothing else is - no banner, no table, no trailing summary -
     * so `php artisan rsx:api:key:create --user=1 --json | jq -r .data.key` works.
     *
     * @return int the process exit code, always 0
     */
    public static function json_ok(Command $command, array $data): int
    {
        $command->getOutput()->writeln(json_encode([
            'ok' => true,
            'command' => $command->getName(),
            'data' => $data,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 0;
    }

    /**
     * The JSON envelope, failure form:
     *
     *     {"ok": false, "command": "...", "error": {"code": "user_not_found", "message": "..."}}
     *
     * A failure is STILL JSON, and still exits non-zero. A script cannot parse an English
     * error line, and a script that gets valid JSON back on a failure but a zero exit code
     * carries on as though nothing happened - both halves are required.
     *
     * @return int the process exit code, always 1
     */
    public static function json_error(Command $command, string $error_code, string $message): int
    {
        $command->getOutput()->writeln(json_encode([
            'ok' => false,
            'command' => $command->getName(),
            'error' => [
                'code' => $error_code,
                'message' => $message,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return 1;
    }

    /**
     * Report an Api_Cli_Error in whichever form the caller asked for.
     *
     * Note this FORMATS an error, it does not swallow one: the message and its machine code
     * both reach the operator, and the exit code is non-zero either way.
     */
    public static function report_error(Command $command, Api_Cli_Error $error, bool $as_json): int
    {
        if ($as_json) {
            return static::json_error($command, $error->error_code, $error->getMessage());
        }

        $command->error('[ERROR] ' . $error->getMessage());

        return 1;
    }

    /**
     * Resolve the user named by --user, disambiguated by --site.
     *
     * WHY --user IS AN OPTION AND STILL REQUIRED. Every other framework command spells this
     * --user= (rsx:debug, rsx:ajax, rsx:file:list), and one command in the tree taking a
     * positional user is a trap for anyone moving between them. An option is optional by
     * nature, so the requirement is enforced HERE, loudly, naming the flag - never by
     * defaulting to user 1, which would mint a real key against the wrong identity and say
     * nothing about it.
     *
     * WHAT --site DOES. users.id is a global primary key, so a numeric --user is already
     * unambiguous and --site is only cross-checked against the row it found. An EMAIL is
     * not: users.email is indexed but not unique, and one person may hold a users row in
     * several sites. So an email that matches in more than one site is REFUSED with the
     * candidate sites listed, rather than picking a tenant. Omitted --site therefore means
     * "infer it from the user", and the only case it cannot be inferred is the one that
     * fails loud.
     *
     * The site scope is bypassed for the lookup itself: a CLI process has no session, and
     * refusing to find a user because of that would be an accident of the environment
     * rather than an answer.
     *
     * @throws Api_Cli_Error when --user is missing, unresolvable, or ambiguous
     */
    public static function resolve_user($user_option, $site_option): User_Model
    {
        $needle = trim((string) ($user_option ?? ''));

        if ($needle === '') {
            throw new Api_Cli_Error(
                'user_required',
                '--user is required: pass a users.id or an email address (e.g. --user=1 or --user=ops@example.com).'
            );
        }

        $site_id = static::__parse_site($site_option);

        if (ctype_digit($needle)) {
            $user = User_Model::without_site_scope(fn () => User_Model::find((int) $needle));

            if (!$user) {
                throw new Api_Cli_Error('user_not_found', "No user with id {$needle}.");
            }

            if ($site_id !== null && (int) $user->site_id !== $site_id) {
                throw new Api_Cli_Error(
                    'user_site_mismatch',
                    "User {$user->id} belongs to site {$user->site_id}, not the --site={$site_id} you named."
                );
            }

            return $user;
        }

        $matches = User_Model::without_site_scope(function () use ($needle, $site_id) {
            $query = User_Model::where('email', $needle);

            if ($site_id !== null) {
                $query = $query->where('site_id', $site_id);
            }

            return $query->orderBy('site_id')->get();
        });

        if ($matches->count() === 0) {
            $where = $site_id === null ? '' : " in site {$site_id}";

            throw new Api_Cli_Error('user_not_found', "No user matches '{$needle}'{$where} (try a users.id or an email address).");
        }

        if ($matches->count() > 1) {
            $sites = implode(', ', $matches->pluck('site_id')->all());

            throw new Api_Cli_Error(
                'user_ambiguous',
                "'{$needle}' matches a user in more than one site ({$sites}). Name one with --site=, or pass the users.id directly."
            );
        }

        return $matches->first();
    }

    /**
     * --site as an integer, or null when it was not given.
     *
     * @throws Api_Cli_Error when the value is not a positive integer
     */
    private static function __parse_site($site_option): ?int
    {
        $value = trim((string) ($site_option ?? ''));

        if ($value === '') {
            return null;
        }

        if (!ctype_digit($value) || (int) $value < 1) {
            throw new Api_Cli_Error('site_invalid', "--site must be a positive site id, got '{$value}'.");
        }

        return (int) $value;
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
     * @throws Api_Cli_Error when the value cannot be understood or is already past
     */
    public static function parse_expiry(string $value): Carbon
    {
        $value = trim($value);

        try {
            $parsed = Carbon::parse($value);
        } catch (\Throwable $e) {
            throw new Api_Cli_Error(
                'expires_invalid',
                "Could not understand --expires='{$value}'. Use an ISO datetime "
                . "(2027-01-01) or a relative span (\"30 days\", \"6 months\")."
            );
        }

        if ($parsed->isPast()) {
            throw new Api_Cli_Error(
                'expires_in_past',
                "--expires='{$value}' resolves to the past; the key would be unusable the moment it was created."
            );
        }

        return $parsed;
    }

    /**
     * 'live' or 'test', validated.
     *
     * @throws Api_Cli_Error when it is neither
     */
    public static function parse_environment($environment_option): string
    {
        $environment = (string) $environment_option;

        if (!in_array($environment, ['live', 'test'], true)) {
            throw new Api_Cli_Error(
                'environment_invalid',
                "--environment must be 'live' or 'test', got '{$environment}'."
            );
        }

        return $environment;
    }

    /**
     * The scopes named by a repeatable --scope, as ONE canonical text - or null when none was
     * given, which mints a key carrying its holder's full authority.
     *
     * REPEATABLE RATHER THAN NEWLINE-EMBEDDED because a scope set is a list, and a list is
     * what a shell can build:
     *
     *     --scope="/api/v1/contacts/*" --scope="/api/v1/clients/#/view"
     *
     * Passing one string with embedded newlines is still accepted (Api_Scopes splits on
     * them) - a provisioning script that already holds a scope set as text should not have to
     * take it apart to pass it.
     *
     * VALIDATED HERE, BEFORE THE MINT. Api_Key_Model::generate() would refuse a malformed
     * scope too, but doing it here means the refusal joins the same Api_Cli_Error path every
     * other bad flag takes - one exit code, one JSON error shape - and it happens before
     * anything is written, so a rejected scope set never leaves a key behind.
     *
     * @param array<int, string>|string|null $scope_option the raw --scope value(s)
     *
     * @throws Api_Cli_Error naming the offending scope, when one is malformed
     */
    public static function parse_scopes($scope_option): ?string
    {
        $lines = is_array($scope_option) ? $scope_option : ($scope_option === null ? [] : [$scope_option]);

        $text = trim(implode("\n", array_map(static fn ($line) => (string) $line, $lines)));

        if ($text === '') {
            return null;
        }

        try {
            return Api_Scopes::canonicalize($text);
        } catch (Api_Scope_Validation_Exception $e) {
            throw new Api_Cli_Error('scopes_invalid', $e->getMessage());
        }
    }

    /**
     * A key's scoping in one column: 'unrestricted', or how many scopes narrow it.
     *
     * The SCOPE COUNT rather than the scopes themselves, because a list column is not where
     * anyone reads a scope set - the question a listing answers is "is this key narrowed at
     * all", and the answer that matters is the difference between none and some. The scopes
     * are in --json, in full.
     *
     * A MALFORMED scope counts, and says so. It grants nothing while still making the key
     * deny-by-default, so a key that can call nothing must never read as '1 scope' and look
     * ordinary.
     */
    public static function key_scope_summary(Api_Key_Model $key): string
    {
        if ($key->is_unrestricted()) {
            return 'unrestricted';
        }

        $count = Api_Scopes::count_scopes($key->scopes);
        $summary = $count . ' scope' . ($count === 1 ? '' : 's');

        if ($key->has_malformed_scopes()) {
            $summary .= ' (malformed - denies all)';
        }

        return $summary;
    }

    /**
     * Why a key is unusable, not merely that it is - revoked and expired call for different
     * responses from whoever is reading.
     */
    public static function key_state(Api_Key_Model $key): string
    {
        if ($key->is_revoked) {
            return 'revoked';
        }

        if ($key->expires_at && Rsx_Time::is_past($key->expires_at)) {
            return 'expired';
        }

        return 'active';
    }

    /**
     * One key as JSON data. ISO strings throughout, never a formatted-for-humans date: the
     * consumer is a script, and a script comparing "2 hours ago" is a script that is wrong.
     */
    public static function key_data(Api_Key_Model $key): array
    {
        return [
            'id' => (int) $key->id,
            'user_id' => (int) $key->user_id,
            'name' => $key->name,
            'key_prefix' => $key->key_prefix,
            'state' => static::key_state($key),
            'is_revoked' => (bool) $key->is_revoked,
            'expires_at' => $key->expires_at ? Rsx_Time::to_iso($key->expires_at) : null,
            'last_used_at' => $key->last_used_at ? Rsx_Time::to_iso($key->last_used_at) : null,
            'created_at' => Rsx_Time::to_iso($key->created_at),
            // The canonical scope text, or null for a key carrying its holder's full
            // authority. The full text and not a summary: the consumer is a script, and a
            // script that has to re-derive the scopes from '3 scopes' cannot.
            'scopes' => $key->scopes,
        ];
    }

    /**
     * The user identification every envelope carries, so a script never has to re-resolve
     * what it just asked for.
     */
    public static function user_data(User_Model $user): array
    {
        return [
            'id' => (int) $user->id,
            'site_id' => (int) $user->site_id,
            'email' => $user->email,
        ];
    }

    /**
     * Whether this user is permitted to use the API at all.
     *
     * Minting is NOT blocked on it. This command runs as whoever holds shell access on the
     * box, which is a strictly higher privilege than any in-app permission, and a
     * provisioning script that cannot mint a key for a user it is about to enable would be
     * useless. But a key minted for a disabled user fails at Api_Dispatcher on first use,
     * which reads as "the key is broken" - so the commands WARN, in both output forms, and
     * name the field to change.
     */
    public static function api_access_enabled(User_Model $user): bool
    {
        return (bool) $user->is_api_access_enabled;
    }
}
