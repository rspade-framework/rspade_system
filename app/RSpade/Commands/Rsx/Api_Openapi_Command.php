<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
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
 */
class Api_Openapi_Command extends Command
{
    protected $signature = 'rsx:api:openapi
                            {--user= : Scope the document to what this user (id or email) may actually call}
                            {--site= : Site id, to disambiguate an email held in more than one site}
                            {--compact : Emit without pretty-printing}';

    protected $description = 'Write the OpenAPI 3.1 document to stdout (optionally scoped to one user)';

    public function handle()
    {
        $flags = JSON_UNESCAPED_SLASHES;

        if (!$this->option('compact')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $accessible_targets = null;

        // With no --user the document describes the whole published surface, which is what a
        // public openapi.json is. With one, it describes what that identity's #[Auth] gates
        // actually admit - the same question /apidocs answers for an adopted key, asked
        // through the same fenced identity swap rather than a second approximation of it.
        if ($this->option('user') !== null && trim((string) $this->option('user')) !== '') {
            try {
                $user = Api_Key_Cli_Support::resolve_user($this->option('user'), $this->option('site'));
            } catch (Api_Cli_Error $e) {
                return Api_Key_Cli_Support::json_error($this, $e->error_code, $e->getMessage());
            }

            $accessible_targets = Api_Tester_Key::accessible_targets_for_user($user);
        } elseif ($this->option('site') !== null && trim((string) $this->option('site')) !== '') {
            return Api_Key_Cli_Support::json_error(
                $this,
                'site_without_user',
                '--site only disambiguates --user; on its own it scopes nothing. Pass --user, or drop --site for the full document.'
            );
        }

        $this->getOutput()->writeln(json_encode(
            Rsx_Api_Docs::openapi_document($accessible_targets),
            $flags
        ));

        return 0;
    }
}
