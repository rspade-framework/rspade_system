<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Forms;

/**
 * Form_Questions - the endpoint's side of a server-driven question.
 *
 * An endpoint that asks a question with response_form_question() reads the answer back
 * here on the next round. The answers ride in the submission body under '_answers', a
 * map of question key to answer, and reach $params untouched - the Ajax dispatcher strips
 * only its own routing keys.
 *
 * THE NULL RULE: null means NOT ASKED YET. Every other value, false included, is an
 * answer the user gave and the endpoint acts on. A question whose answer is "No" is
 * answered; a submit the user abandoned never reaches the endpoint at all, because the
 * browser's cancel (Rsx_Form.CANCELLED) stops the submission rather than answering.
 *
 *     $answer = Form_Questions::answer($params, 'duplicate_email');
 *     if ($answer === null) { return response_form_question('duplicate_email', [...]); }
 *     if ($answer === false) { ... }
 *
 * See: php artisan rsx:man form_conventions (QUESTIONS)
 */
class Form_Questions
{
    /**
     * The answer to one question, or null when it has not been asked and answered yet.
     *
     * @param array $params The endpoint's $params
     * @param string $key The question key
     * @return mixed null when unanswered; otherwise the answer, false included
     */
    public static function answer(array $params, string $key)
    {
        $answers = $params['_answers'] ?? null;

        if (!is_array($answers)) {
            return null;
        }

        return $answers[$key] ?? null;
    }

    /**
     * Has this question been answered?
     *
     * The predicate form of answer(), for a question whose answer is not itself the
     * branch (a prompt's string, a select's value).
     */
    public static function answered(array $params, string $key): bool
    {
        return static::answer($params, $key) !== null;
    }
}
