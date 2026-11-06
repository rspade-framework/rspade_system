<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Response;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use App\RSpade\Core\Response\Rsx_Response_Abstract;

/**
 * Rollback_Response - answer with an ordinary error AND undo the request.
 *
 * A #[Writer] that RETURNS an error commits what it wrote before answering (a coded result is
 * a return, and a return commits). Only a throw rolls a writer back - and an ordinary throw
 * answers a fault. This is the throw that answers like a return:
 *
 *     // Ajax endpoint / page route
 *     throw new Rollback_Response(response_form_error('Row 14 is invalid', ['rows' => '...']));
 *     throw new Rollback_Response(response_error(Ajax::ERROR_VALIDATION, 'The batch failed'));
 *
 *     // External API / companion endpoint
 *     throw new Rollback_Response(Rsx_Api::validation_error(['rows' => '...'], 'Row 14 is invalid'));
 *
 * The site lock boundary sees a throw: the writer's transaction rolls back (its _transactions
 * row with it), the lock is released, rsx.post_dispatch does not fire (it fires on success
 * only). Then the dispatcher renders the carried value EXACTLY as it would have rendered it
 * returned: the same envelope or body, the same _duration_ms / _completed_at stamping. Nothing
 * is recorded in the request history, so a _request_ref sent with it stays reusable - as for
 * any request that threw.
 *
 * THE VALUE IS THE CHANNEL'S OWN. Each dispatcher applies its ordinary rule to it: an Ajax call
 * answers a coded result (an Rsx_Response_Abstract - an HTTP response is refused there as it
 * is when returned), the external API answers a JSON object response (Rsx_Api::error() and
 * friends - a coded Rsx_Response_Abstract is refused there as it is when returned), a page
 * route answers either.
 *
 * Anywhere else it is legal and harmless: in a reader there is nothing to roll back; in a
 * #[Manual_Lock] handler it rolls back the request transaction a raw site write lock began,
 * while Site_Lock::write() sections that already ended stay committed. An Ajax::internal()
 * call nested inside another endpoint answers it to its caller as the coded exception
 * internal() raises for that result (AjaxFormErrorException ...): the OUTERMOST boundary owns
 * the transaction, and rolls back only if that exception reaches it. Thrown outside every
 * dispatcher (a task, a command), nothing renders it: it is an ordinary failure.
 *
 * See: php artisan rsx:man site_locks (HOLDING A LOCK WELL)
 */
#[Instantiatable]
class Rollback_Response extends RuntimeException
{
    /**
     * @param Rsx_Response_Abstract|Response $response The answer, exactly as the handler would have returned it
     */
    public function __construct(public readonly Rsx_Response_Abstract|Response $response)
    {
        $what = $response instanceof Rsx_Response_Abstract
            ? $response->get_type() . ': ' . $response->get_reason()
            : 'HTTP ' . $response->getStatusCode();

        parent::__construct("Rollback_Response ({$what}) - an error answer that rolls the request back. Only an "
            . 'Ajax, external API, companion or page-route dispatcher renders it.');
    }
}
