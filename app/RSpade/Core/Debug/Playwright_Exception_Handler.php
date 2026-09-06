<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Debug;

use Illuminate\Http\Request;
use Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use App\RSpade\Core\Debug\Debugger;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Exceptions\Rsx_Exception_Handler_Abstract;
use App\RSpade\Core\Rsx;

/**
 * Playwright_ExceptionHandler - Handle exceptions during Playwright test execution
 *
 * PRIORITY: 30
 *
 * This handler processes exceptions when the request is from a Playwright test
 * (identified by X-Playwright-Test header). It provides plain text error output
 * suitable for automated testing, including:
 * - Error message, file, and line
 * - Stack trace (last 10 calls)
 * - Console debug messages (if enabled)
 * - Special 404 handling (tries RSX dispatch first)
 *
 * SECURITY: this handler answers ONLY the local harness - development mode AND a
 * loopback caller AND the X-Playwright-Test marker. The marker is unsigned, so it is
 * never a gate on its own: without the loopback requirement anyone on the network
 * could ask a public development box for a plain-text stack trace by sending one
 * header.
 */
class Playwright_Exception_Handler extends Rsx_Exception_Handler_Abstract
{
    /**
     * Get priority - Playwright handlers run after AJAX but before general web
     *
     * @return int
     */
    public static function get_priority(): int
    {
        return 30;
    }

    /**
     * Handle exception if request is from Playwright test
     *
     * @param Throwable $e
     * @param Request $request
     * @return mixed Plain text response if Playwright request, null otherwise
     */
    public function handle(Throwable $e, Request $request)
    {
        // THIS HANDLER DISCLOSES A PLAIN-TEXT STACK TRACE, so it answers only the local
        // harness. Three conditions, all required:
        //   - development mode (Rsx::is_development(), not "not production" - RSX_MODE is
        //     the one mode switch, and app()->environment() reports 'local' for a sealed
        //     debug box too);
        //   - a LOOPBACK caller with no forwarded headers (is_loopback_ip()), which is
        //     what the rsx:debug browser always is;
        //   - the X-Playwright-Test header, which is an unsigned marker and therefore
        //     never a gate on its own - anybody can send it.
        if (!Rsx::is_development() || !is_loopback_ip() || !$request->header('X-Playwright-Test')) {
            return null;
        }

        Log::debug('Exception handler triggered for Playwright test, exception: ' . get_class($e));
        console_debug('DISPATCH', 'Exception handler triggered for Playwright test, exception:', get_class($e));

        // Special handling for 404s - check RSX routes first
        if ($e instanceof NotFoundHttpException) {
            // Get the requested path
            $path = '/' . ltrim($request->path(), '/');

            Log::debug("Exception handler: attempting RSX dispatch for path: $path");
            console_debug('DISPATCH', 'Exception handler: attempting RSX dispatch for', $path);

            // Try RSX dispatch
            $response = Dispatcher::dispatch($path, $request->method(), [], $request);

            Log::debug('RSX dispatch returned: ' . ($response ? 'response' : 'null'));

            // If RSX found a route, return the response
            if ($response !== null) {
                return $response;
            }

            // No RSX route found - return 404 as plain text
            return response('404 Not Found', 404)
                ->header('Content-Type', 'text/plain');
        }

        // Build error output
        $error_output = 'Error: ' . $e->getMessage() . "\n";
        $error_output .= 'File: ' . $e->getFile() . "\n";
        $error_output .= 'Line: ' . $e->getLine() . "\n";
        $error_output .= "\n";
        $error_output .= "Stack Trace (last 10 calls):\n";

        // Get stack trace
        $trace = $e->getTrace();
        $count = 0;
        foreach ($trace as $frame) {
            if ($count >= 10) {
                break;
            }

            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? 'unknown';
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';

            if ($class) {
                $function = $class . $type . $function;
            }

            $error_output .= sprintf(
                "  #%d %s:%d %s()\n",
                $count,
                $file,
                $line,
                $function
            );
            $count++;
        }

        // Output console debug messages if enabled
        $show_console = env('SHOW_CONSOLE_DEBUG_HTTP', true) ||
                       (isset($_SERVER['HTTP_X_PLAYWRIGHT_CONSOLE_DEBUG']) && $_SERVER['HTTP_X_PLAYWRIGHT_CONSOLE_DEBUG'] === '1');

        if (Rsx::is_development() && $show_console) {
            $console_messages = Debugger::_get_console_messages();
            if (!empty($console_messages)) {
                $error_output .= "\nConsole Debug Messages:\n";
                foreach ($console_messages as $message) {
                    // Messages are now arrays with 'message' key
                    if (is_array($message) && isset($message['message'])) {
                        $error_output .= '  ' . $message['message'] . "\n";
                    } elseif (is_string($message)) {
                        $error_output .= '  ' . $message . "\n";
                    }
                }
            }
        }

        // Return plain text response
        return response($error_output, 500)
            ->header('Content-Type', 'text/plain');
    }
}
