<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ModelFetch\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Ajax_Batch_Controller;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The batch transport preserves each sub-call's own error code.
 *
 * The transport batcher (/_ajax/_batch, active outside development mode) runs N sub-calls
 * through Ajax::internal() and re-encodes whatever each one throws. AjaxNotFoundException
 * is a SUBCLASS of AjaxFormErrorException, so without its own catch arm a not-found
 * sub-call reached the client stamped 'validation' - and client code that branches on
 * Ajax.ERROR_NOT_FOUND (the ORM fetch path does, to tell a missing record from a broken
 * request) behaved differently batched than unbatched.
 *
 * Both codes are asserted in ONE batch: the arm has to catch the subclass without
 * swallowing its parent.
 */
class Ajax_Batch_Error_Code_Test extends Rsx_Test_Abstract
{
    /**
     * A not-found sub-call keeps 'not_found'; a validation sub-call beside it keeps
     * 'validation'.
     *
     * Both sub-calls target the ORM fetch endpoint because it is the batched surface the
     * code matters to, and its gate is 'public' - the refusals under test are about the
     * REQUEST (unknown model, absent model name), so no identity is needed to reach them.
     */
    public static function test_batched_sub_call_keeps_its_error_code()
    {
        $data = static::__run_batch([
            [
                'call_id' => 0,
                'controller' => 'Orm_Controller',
                'action' => 'fetch',
                'params' => ['model' => 'No_Such_Model_Anywhere', 'ids' => [1]],
            ],
            [
                'call_id' => 1,
                'controller' => 'Orm_Controller',
                'action' => 'fetch',
                'params' => ['ids' => [1]],
            ],
        ]);

        static::__assert_true(isset($data['C_0']), 'the batch response is keyed C_<call_id>');
        static::__assert_false($data['C_0']['_success'], 'the not-found sub-call failed');
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $data['C_0']['error_code']);

        static::__assert_true(isset($data['C_1']), 'the batch response carries every sub-call');
        static::__assert_false($data['C_1']['_success'], 'the malformed sub-call failed');
        static::__assert_equals(Ajax::ERROR_VALIDATION, $data['C_1']['error_code']);
    }

    /**
     * A sub-call that throws a raw \Throwable is CONTAINED to that sub-call, and its
     * message is not leaked verbatim.
     *
     * An array where a string param is typed makes Orm_Controller::fetch throw a TypeError
     * inside the batch loop. A TypeError is a \Throwable but NOT an \Exception, so before
     * the generic arm was widened it escaped the loop and aborted the whole batch with a
     * fatal (backtrace and all) - an anonymous, unauthenticated oracle, since /_ajax/_batch
     * is #[Auth('public')]. The arm now catches \Throwable, so the fault stays a per-call
     * failure, and in strict production the reason is a single generic string (mirroring the
     * direct-path Ajax_Exception_Handler, which shares the Rsx::is_production() gate). This
     * process runs in development mode, so the real message is kept for troubleshooting; the
     * production redaction of that same branch is exercised by the live-probe row in the
     * catalog and by the direct handler's own coverage.
     */
    public static function test_a_raw_throwable_is_contained_and_not_fatal()
    {
        $data = static::__run_batch([
            [
                'call_id' => 0,
                'controller' => 'Orm_Controller',
                'action' => 'fetch',
                'params' => ['model' => ['not', 'a', 'string'], 'ids' => [1]],
            ],
            [
                'call_id' => 1,
                'controller' => 'Orm_Controller',
                'action' => 'fetch',
                'params' => ['model' => 'No_Such_Model_Anywhere', 'ids' => [1]],
            ],
        ]);

        // The TypeError sub-call did not abort the batch: its sibling still answered.
        static::__assert_true(isset($data['C_0']), 'the throwing sub-call produced its own response');
        static::__assert_false($data['C_0']['_success'], 'the throwing sub-call failed');
        static::__assert_equals('exception', $data['C_0']['error_type']);
        static::__assert_true(isset($data['C_1']), 'the sibling sub-call still ran after the throw');
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $data['C_1']['error_code']);
    }

    /**
     * Run one batch request in-process and return the decoded response map.
     *
     * The controller flips the process-wide Ajax response mode on; a test process is not
     * a request, so it is put back the way it was found.
     *
     * @return array
     */
    private static function __run_batch(array $batch_calls): array
    {
        $previous_response_mode = Ajax::is_ajax_response_mode();

        try {
            $request = Request::create('/_ajax/_batch', 'POST', ['batch_calls' => $batch_calls]);

            $response = Ajax_Batch_Controller::batch($request, []);

            return $response->getData(true);
        } finally {
            Ajax::set_ajax_response_mode($previous_response_mode);
        }
    }
}
