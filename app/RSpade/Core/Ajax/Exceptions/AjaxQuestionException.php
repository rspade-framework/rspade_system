<?php

namespace App\RSpade\Core\Ajax\Exceptions;

// @FILE-SUBCLASS-01-EXCEPTION

/**
 * Exception thrown when an endpoint asks the user a question instead of writing.
 *
 * This is not a failure. response_form_question() returns a pending question; the
 * browser's form engine answers it through the application's registered question
 * handler, and an IN-PROCESS caller - Ajax::internal(), and therefore a PHP test and
 * rsx:ajax - receives it as this exception.
 *
 * Answering it means calling the endpoint again with the answer attached:
 * $params['_answers'][$e->get_key()] = <answer>. The endpoint re-runs from scratch,
 * so nothing is held between rounds.
 *
 * See: php artisan rsx:man form_conventions (QUESTIONS)
 */
#[Instantiatable]
class AjaxQuestionException extends \Exception
{
    protected string $key;
    protected array $question;

    public function __construct($message = 'A question is pending', string $key = '', array $question = [], $code = 200, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->key = $key;
        $this->question = $question;
    }

    /**
     * The question's key - what an answer is filed under in $params['_answers'].
     */
    public function get_key(): string
    {
        return $this->key;
    }

    /**
     * The question object the endpoint returned, verbatim. Opaque to the framework.
     */
    public function get_question(): array
    {
        return $this->question;
    }
}
