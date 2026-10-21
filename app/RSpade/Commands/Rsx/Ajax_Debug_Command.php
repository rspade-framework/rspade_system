<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxAuthRequiredException;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Ajax\Exceptions\AjaxFormErrorException;
use App\RSpade\Core\Ajax\Exceptions\AjaxQuestionException;
use App\RSpade\Core\Ajax\Exceptions\AjaxFatalErrorException;
use App\RSpade\Core\Console\Rsx_Cli_Actor;
use App\RSpade\Core\Console\Rsx_Cli_Actor_Exception;
use App\RSpade\Core\Debug\Debugger;

/**
 * RSX Ajax Command
 * =================
 *
 * PURPOSE:
 * Execute Ajax endpoint methods from CLI with JSON output.
 * Designed for testing, automation, and scripting.
 *
 * OUTPUT MODES:
 * 1. Default: Raw JSON response (just the return value)
 * 2. --debug: Full HTTP-like response with {success, _ajax_return_value, console_debug}
 * 3. --verbose: Add request context before JSON output
 *
 * USAGE EXAMPLES:
 * php artisan rsx:ajax Controller action                               # Basic call
 * php artisan rsx:ajax Controller action --args='{"id":1}'             # With params
 * php artisan rsx:ajax Controller action --site=1                      # With site context
 * php artisan rsx:ajax Controller action --user=1 --site=1             # Full context
 * php artisan rsx:ajax Controller action --user=admin@test.com        # User by email
 * php artisan rsx:ajax Controller action --debug                       # HTTP-like response
 * php artisan rsx:ajax Controller action --verbose --site=1            # Show context
 *
 * OUTPUT FORMAT:
 * Default: {"records":[...], "total":10}
 * --debug: {"success":true, "_ajax_return_value":{...}, "console_debug":[...]}
 * --verbose: Adds "Set site_id to 1" before JSON
 *
 * ERROR HANDLING:
 * All errors return JSON (never throws to stderr)
 * {"success":false, "error":"Error message", "error_type":"exception_type"}
 *
 * USE CASES:
 * - Test Ajax endpoints behind auth/site scoping
 * - Invoke RPC calls from automation scripts
 * - Debug API responses in production environments
 */
class Ajax_Debug_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:ajax
        {controller : The RSX controller name}
        {action : The action/method name}
        {--args= : JSON-encoded arguments to pass to the action}
        {--user= : Run as this login identity (login_users id or email); the site user is resolved from it}
        {--site= : Run under this site (required when the login identity has users rows on several sites)}
        {--debug : Wrap output in HTTP-like response format (success, _ajax_return_value, console_debug)}
        {--show-context : Show request context before JSON output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Ajax endpoints from CLI with JSON output';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        // Get command arguments
        $controller = $this->argument('controller');
        $action = $this->argument('action');

        // Parse arguments if provided
        $args = [];
        if ($this->option('args')) {
            $args_json = $this->option('args');
            $args = json_decode($args_json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->output_json_error('Invalid JSON in --args parameter: ' . json_last_error_msg(), 'invalid_json');
                return 1;
            }
        }

        // Get options
        $user_input = $this->option('user');
        $site_input = $this->option('site');
        $debug_mode = $this->option('debug');
        $show_context = $this->option('show-context');

        // Resolve who the call acts as (Rsx_Cli_Actor - the one resolution rsx:task:run
        // shares, so the two command-line entry points read --user / --site identically).
        try {
            $actor = Rsx_Cli_Actor::resolve($user_input, $site_input);
        } catch (Rsx_Cli_Actor_Exception $e) {
            $this->output_json_error($e->getMessage(), $e->error_type);
            return 1;
        }

        // Rotate logs before test
        Debugger::logrotate();

        Rsx_Cli_Actor::establish($actor);

        if ($show_context) {
            $user_id = $actor['login_user_id'];
            $site_id = $actor['site_id'];
            $site_user = $actor['user_id'];

            if ($user_id !== null) {
                $this->error("Set login_user_id to {$user_id}");
                $this->error("Set site_id to {$site_id}");
                if ($site_user !== null) {
                    $this->error("Set user_id to {$site_user} (the users row of login user {$user_id} on site {$site_id})");
                } else {
                    // Not an error: a cross-site login with no presence on this tenant is a
                    // real state. It is the state that used to be silent, so it is named.
                    $this->error("Set user_id to NULL: login user {$user_id} has no users row on site {$site_id}, so Session::get_user_id() answers null and a user-scoped endpoint sees no user");
                }
            } elseif ($site_id !== null) {
                $this->error("Set site_id to {$site_id}");
            }
        }

        try {
            // Call the Ajax method
            $response = Ajax::internal($controller, $action, $args);

            // Output response based on mode
            if ($debug_mode) {
                // Use shared format_ajax_response method for consistency with HTTP
                $wrapped_response = Ajax::format_ajax_response($response);
                $this->output_json($wrapped_response);
            } else {
                // Just output the raw response
                $this->output_json($response);
            }

        } catch (AjaxAuthRequiredException $e) {
            $this->output_json_error($e->getMessage(), 'auth_required');
            return 1;

        } catch (AjaxUnauthorizedException $e) {
            $this->output_json_error($e->getMessage(), 'unauthorized');
            return 1;

        } catch (AjaxFormErrorException $e) {
            $error_response = [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => 'form_error',
            ];

            $details = $e->get_details();
            if (!empty($details)) {
                $error_response['details'] = $details;
            }

            $this->output_json($error_response);
            return 1;

        } catch (AjaxQuestionException $e) {
            // Not a failure: the endpoint is asking. Call again with
            // --args='{"_answers":{"<key>":<answer>}}' to answer it.
            $this->output_json([
                'success' => false,
                'error_type' => 'question',
                'key' => $e->get_key(),
                'question' => $e->get_question(),
            ]);
            return 1;

        } catch (AjaxFatalErrorException $e) {
            $this->output_json_error($e->getMessage(), 'fatal_error', [
                'trace' => $e->getTraceAsString()
            ]);
            return 1;

        } catch (\InvalidArgumentException $e) {
            $this->output_json_error($e->getMessage(), 'invalid_argument');
            return 1;

        } catch (\Exception $e) {
            $this->output_json_error($e->getMessage(), get_class($e), [
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        // Rotate logs after test
        Debugger::logrotate();

        return 0;
    }

    /**
     * Output JSON to stdout (pretty-printed)
     */
    protected function output_json($data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->line($json);
    }

    /**
     * Output error as JSON
     */
    protected function output_json_error(string $message, string $error_type, array $extra = []): void
    {
        $error = [
            'success' => false,
            'error' => $message,
            'error_type' => $error_type,
        ];

        foreach ($extra as $key => $value) {
            $error[$key] = $value;
        }

        $this->output_json($error);
    }
}
