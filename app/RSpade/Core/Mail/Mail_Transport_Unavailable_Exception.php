<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Mail;

/**
 * The mail host could not be reached, twice, with a fresh connection in between.
 *
 * This is how the queue drain DIES, and dying is the correct behaviour: the messages
 * are untouched and still PENDING, so the next kick or the next minute's sweep tries
 * again, forever, with no message having burned any part of its retry budget on an
 * outage that had nothing to do with it. The task row records the failure so an
 * operator sees the outage in rsx:task:list rather than in a silent queue.
 *
 * NOT to be confused with an SMTP server ERROR REPLY for one message - that is the
 * message's problem, and Email_Queue_Model::mark_server_error() gives it its own clock.
 */
#[Instantiatable]
class Mail_Transport_Unavailable_Exception extends \RuntimeException
{
    public function __construct(
        public readonly string $transport_description,
        public readonly string $last_error
    ) {
        parent::__construct(
            "Mail transport unavailable ({$transport_description}) after a reconnect: {$last_error}"
        );
    }
}
