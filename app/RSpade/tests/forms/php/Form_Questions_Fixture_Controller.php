<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Forms\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Forms\Form_Questions;

/**
 * An endpoint that asks one question and then reports what it was answered.
 *
 * Form_Questions_Test runs it through the in-process Ajax entry point to pin what a
 * PHP caller sees: AjaxQuestionException on the first round, the endpoint's own result
 * once _answers carries the answer. Public gate: the subject is the question protocol,
 * not authorization.
 */
class Form_Questions_Fixture_Controller extends Rsx_Controller_Abstract
{
    const QUESTION = [
        'kind' => 'confirm',
        'title' => 'Proceed?',
        'body' => "The world is in a state.\n\nProceed anyway?",
        'confirm_label' => 'Proceed',
        'cancel_label' => 'Do not',
    ];

    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function ask(Request $request, array $params = [])
    {
        $answer = Form_Questions::answer($params, 'proceed');

        if ($answer === null) {
            return response_form_question('proceed', self::QUESTION);
        }

        return ['answered' => $answer];
    }
}
