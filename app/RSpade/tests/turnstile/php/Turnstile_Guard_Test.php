<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Turnstile\Php;

use Illuminate\Http\Request;
use RuntimeException;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Turnstile\Rsx_Turnstile;

/**
 * Turnstile_Guard_Test - the completeness guard, and the rsx.post_dispatch event it rides.
 *
 * A form carrying the widget posts __turnstile to an endpoint that MUST call validate().
 * If the endpoint never does, the request was accepted with no verification whatsoever and
 * nothing anywhere would say so - the feature would look wired and be inert. The guard
 * closes that hole by throwing at the post-dispatch seam on the first such submit.
 *
 * Two things are pinned here:
 *   1. the guard's own decision matrix, exercised by firing rsx.post_dispatch directly
 *      (payload contract included - a payload with no Request is a framework bug, not a
 *      user error, so it fails loud);
 *   2. the latch bookkeeping around Ajax::internal(), which is what makes a BATCH honest:
 *      each sub-call gets its own latch (so one call's validation cannot be laundered
 *      across its siblings), and the CALLING scope's latch is restored afterwards (so a
 *      handler that validated and then made an internal call has not lost its own proof).
 *
 * Spa_Session_Controller::get_state is the probe endpoint: #[Auth('public')],
 * #[Auth_Realm('any')], reads nothing but the caller's own (absent, under CLI) session, and
 * does not call validate() - exactly the "endpoint that forgot" the guard exists to catch.
 */
class Turnstile_Guard_Test extends Rsx_Test_Abstract
{
    /** The guard only reads state; the probe endpoint writes nothing. */
    protected static $use_database_transactions = false;

    /** The event the guard subscribes to. */
    private const EVENT = 'rsx.post_dispatch';

    /** A harmless public #[Ajax_Endpoint] that never calls validate(). */
    private const PROBE_CONTROLLER = 'Spa_Session_Controller';

    /** The probe endpoint's action. */
    private const PROBE_ACTION = 'get_state';

    /** The distinguishing text of the guard's exception. */
    private const INCOMPLETE = 'Turnstile implementation incomplete';

    public static function teardown()
    {
        Rsx_Turnstile::_reset_request_state();
    }

    /**
     * Fire the post-dispatch event exactly as a dispatch seam does.
     *
     * @param Request $request
     * @param array|null $params
     * @return void
     */
    private static function __fire(Request $request, ?array $params = null): void
    {
        Rsx::trigger_action(self::EVENT, [
            'request' => $request,
            'params' => $params,
            'result' => null,
        ]);
    }

    // --- The event exists and the guard is subscribed to it ---

    public static function test_the_post_dispatch_event_has_a_handler()
    {
        // The guard is discovered from its #[OnEvent] attribute at manifest build. If this
        // fails, every other test in this class is passing for the wrong reason.
        static::__assert_true(
            Event_Registry::has_handlers(self::EVENT),
            'rsx.post_dispatch must have at least one registered handler (the Turnstile guard)'
        );
    }

    // --- The guard's decision matrix ---

    public static function test_a_submitted_token_with_no_validation_throws()
    {
        Rsx_Turnstile::_reset_request_state();

        $request = Request::create('/login', 'POST', [Rsx_Turnstile::FIELD => 'a-token']);

        $exception = static::__assert_throws(RuntimeException::class, function () use ($request) {
            static::__fire($request);
        });

        static::__assert_contains(self::INCOMPLETE, $exception->getMessage());
        static::__assert_contains('Rsx_Turnstile::validate()', $exception->getMessage());
        static::__assert_contains('/login', $exception->getMessage(), 'the message names where to add the call');
    }

    public static function test_a_submitted_token_in_params_with_no_validation_throws()
    {
        Rsx_Turnstile::_reset_request_state();

        // The Ajax seam shape: the field rides $params, and the Request the endpoint was
        // handed may not carry it at all.
        $request = Request::create('/_ajax/Foo_Controller/bar', 'POST');

        static::__assert_throws(RuntimeException::class, function () use ($request) {
            static::__fire($request, [Rsx_Turnstile::FIELD => 'a-token']);
        }, self::INCOMPLETE);
    }

    public static function test_a_validated_request_passes_the_guard()
    {
        Rsx_Turnstile::_reset_request_state();
        Rsx_Turnstile::_set_request_checked(true);

        try {
            $request = Request::create('/login', 'POST', [Rsx_Turnstile::FIELD => 'a-token']);
            static::__fire($request);

            // Idempotent: the event fires at nested seams (the Ajax handler AND the
            // Dispatcher transport route around it), so a second firing must be as quiet
            // as the first.
            static::__fire($request);
        } finally {
            Rsx_Turnstile::_reset_request_state();
        }
    }

    public static function test_a_get_request_passes_the_guard()
    {
        Rsx_Turnstile::_reset_request_state();

        // Only a POST can submit the field, so a GET is never the guard's business - not
        // even one whose query string happens to carry the name.
        static::__fire(Request::create('/login', 'GET', [Rsx_Turnstile::FIELD => 'a-token']));
    }

    public static function test_a_post_without_the_field_passes_the_guard()
    {
        Rsx_Turnstile::_reset_request_state();

        // The overwhelming majority of POSTs: no widget, no field, nothing to guard.
        static::__fire(Request::create('/settings/save', 'POST', ['name' => 'x']));
    }

    // --- The payload contract ---

    public static function test_a_payload_without_a_request_fails_loud()
    {
        Rsx_Turnstile::_reset_request_state();

        // Every seam passes a Request. A payload without one means a seam was written
        // wrong, which is a framework bug and must not degrade to "guard silently off".
        static::__assert_throws(RuntimeException::class, function () {
            Rsx::trigger_action(self::EVENT, ['params' => null, 'result' => null]);
        }, 'shouldnt_happen');
    }

    // --- Ajax::internal() latch bookkeeping ---

    public static function test_an_internal_call_carrying_the_field_hits_the_guard()
    {
        Rsx_Turnstile::_reset_request_state();

        // The probe endpoint does not validate, so the sub-call's own post-dispatch firing
        // must catch it. This is the batch-laundering case: a sibling call's validation
        // cannot cover this one, because internal() reset the latch on the way in.
        Rsx_Turnstile::_set_request_checked(true);

        try {
            static::__assert_throws(RuntimeException::class, function () {
                Ajax::internal(self::PROBE_CONTROLLER, self::PROBE_ACTION, [
                    Rsx_Turnstile::FIELD => 'a-token',
                ]);
            }, self::INCOMPLETE);
        } finally {
            Rsx_Turnstile::_reset_request_state();
        }
    }

    public static function test_an_internal_call_restores_the_callers_latch()
    {
        Rsx_Turnstile::_reset_request_state();

        // A plain POST handler that validated its own request and THEN made an internal
        // call must still be able to prove it validated: internal() gives the sub-call a
        // fresh latch and puts the caller's back afterwards.
        Rsx_Turnstile::_set_request_checked(true);

        try {
            Ajax::internal(self::PROBE_CONTROLLER, self::PROBE_ACTION, []);

            static::__assert_true(
                Rsx_Turnstile::_was_checked(),
                'the outer request latch must survive a nested internal call'
            );
        } finally {
            Rsx_Turnstile::_reset_request_state();
        }
    }

    public static function test_an_internal_call_does_not_grant_a_latch_to_its_caller()
    {
        Rsx_Turnstile::_reset_request_state();

        try {
            // The reverse direction of the restore: a sub-call that DID validate must not
            // leave the outer scope looking validated. The probe never validates, so the
            // proof here is that the caller's cleared latch is still cleared afterwards.
            Ajax::internal(self::PROBE_CONTROLLER, self::PROBE_ACTION, []);

            static::__assert_false(
                Rsx_Turnstile::_was_checked(),
                'an internal call must not leave the caller latched'
            );
        } finally {
            Rsx_Turnstile::_reset_request_state();
        }
    }
}
