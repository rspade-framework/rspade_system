<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Tester_Key;
use App\RSpade\Core\Api\Rsx_Api_Docs;

/**
 * rsx:api:openapi - the OpenAPI 3.1 description of the API, on stdout.
 *
 * So a script can generate a client without a browser and without an HTTP round trip to
 * /apidocs/openapi.json: pipe it into openapi-generator, into jq, into a diff against the
 * copy committed last release.
 *
 * THERE IS NO --json FLAG, and that is not an oversight. Every other rsx:api:* command has a
 * human form and a machine form, so --json selects between them; this command has only the
 * machine form. A flag that can only ever be passed is noise, and a flag that LOOKS optional
 * but changes nothing is worse - it implies a human form exists somewhere.
 *
 * Nor is the envelope those commands use applied here. The document IS the payload, and it
 * has a standardized shape a generator already knows how to read; wrapping it in {ok, data}
 * would mean every consumer unwrapping it first. Errors, having no such standard shape, DO
 * use the envelope - a failure is never mistaken for a document, because a document never
 * has an "ok" key at the top.
 *
 * PRETTY-PRINTED BY DEFAULT because the overwhelmingly common use is a human reading it or
 * committing it to a repository, where a one-line file makes every future diff unreadable.
 * --compact is for piping into something that does not care.
 *
 * --user and --key both NARROW the document, and --key narrows it further than --user can:
 * gates alone versus gates intersected with that key's scope rules. Generating a client from
 * the key-narrowed document means every operation in it is one the key can actually call.
 */
class Api_Openapi_Command extends Command
{
    protected $signature = 'rsx:api:openapi
                            {--user= : Narrow the document to what this user (id or email) may actually call}
                            {--site= : Site id, to disambiguate an email held in more than one site}
                            {--key= : Narrow to one API key id - its user\'s gates INTERSECTED with the key\'s own scope rules. Composable with --user, which must then name that key\'s user}
                            {--compact : Emit without pretty-printing}';

    protected $description = 'Write the OpenAPI 3.1 document to stdout (optionally narrowed to one user or one API key)';

    public function handle()
    {
        $flags = JSON_UNESCAPED_SLASHES;

        if (!$this->option('compact')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            $accessible_targets = $this->__accessible_targets();
        } catch (Api_Cli_Error $e) {
            return Api_Key_Cli_Support::json_error($this, $e->error_code, $e->getMessage());
        }

        $this->getOutput()->writeln(json_encode(
            Rsx_Api_Docs::openapi_document($accessible_targets),
            $flags
        ));

        return 0;
    }

    /**
     * The visibility filter this invocation asks for, or null for the whole published
     * surface.
     *
     * THREE NARROWINGS, AND THEY COMPOSE:
     *
     *   (none)          every published endpoint - what a public openapi.json is.
     *   --user          what that identity's #[Auth] gates admit.
     *   --key           that key's user's gates INTERSECTED with the key's own scope rules,
     *                   which is the document the holder of that key can actually use.
     *
     * --key IMPLIES ITS USER, so --user is redundant with it - but redundant is not the same
     * as harmless. Passing both and having them DISAGREE means the caller believes something
     * false about one of them, and silently honouring either half would hand back a document
     * describing an access that nobody has. So it is a refusal, naming both sides.
     *
     * @throws Api_Cli_Error on an unresolvable user, an unusable key, or a mismatch
     */
    private function __accessible_targets(): ?array
    {
        $user_option = trim((string) ($this->option('user') ?? ''));
        $key_option = trim((string) ($this->option('key') ?? ''));
        $site_option = trim((string) ($this->option('site') ?? ''));

        $user = $user_option === ''
            ? null
            : Api_Key_Cli_Support::resolve_user($this->option('user'), $this->option('site'));

        if ($key_option === '') {
            if ($user) {
                return Api_Tester_Key::accessible_targets_for_user($user);
            }

            if ($site_option !== '') {
                throw new Api_Cli_Error(
                    'site_without_user',
                    '--site only disambiguates --user; on its own it scopes nothing. Pass --user, or drop --site for the full document.'
                );
            }

            return null;
        }

        if (!ctype_digit($key_option)) {
            throw new Api_Cli_Error('key_invalid', "--key must be an _api_keys id, got '{$key_option}'.");
        }

        $key = Api_Key_Model::find((int) $key_option);

        if (!$key) {
            throw new Api_Cli_Error('key_not_found', "No API key with id {$key_option}.");
        }

        // A revoked or expired key can call nothing at all, so a document drawn for it would
        // describe an access that does not exist. Said plainly rather than emitting an empty
        // paths object nobody could interpret.
        if (!$key->is_valid()) {
            throw new Api_Cli_Error(
                'key_unusable',
                "API key {$key->id} ({$key->key_prefix}) is revoked or expired; it can reach nothing."
            );
        }

        if ($user && (int) $user->id !== (int) $key->user_id) {
            throw new Api_Cli_Error(
                'key_user_mismatch',
                "--key={$key->id} belongs to user {$key->user_id}, not the --user you named (user {$user->id}). "
                . 'Drop --user - the key already names its own.'
            );
        }

        if (!$key->get_user()) {
            throw new Api_Cli_Error('key_user_missing', "API key {$key->id} names user {$key->user_id}, which no longer exists.");
        }

        return Api_Tester_Key::accessible_targets_for_key($key);
    }
}
