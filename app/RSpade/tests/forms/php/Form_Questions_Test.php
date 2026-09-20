<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Forms\Php;

use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Ajax\Exceptions\AjaxQuestionException;
use App\RSpade\Core\Forms\Form_Questions;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Forms\Php\Form_Questions_Fixture_Controller;

/**
 * The server half of a server-driven question: the response envelope, the answer reader,
 * and what an in-process caller sees.
 *
 * Three properties carry the feature and are pinned here. The QUESTION TRAVELS VERBATIM -
 * the framework reads no field of it, so whatever the endpoint wrote is what the
 * application's handler receives. NULL MEANS UNASKED AND FALSE IS AN ANSWER - the whole
 * cancel-versus-No distinction rests on an endpoint being able to act on "No". And an
 * IN-PROCESS CALLER GETS AN EXCEPTION carrying both halves, which is how a PHP test,
 * rsx:ajax and one leg of a batch answer a question rather than mistaking it for a failure.
 */
class Form_Questions_Test extends Rsx_Test_Abstract
{
    // -------------------------------------------------------------------------
    // The response envelope
    // -------------------------------------------------------------------------

    public static function test_response_carries_the_question_error_code()
    {
        $response = response_form_question('proceed', ['kind' => 'confirm']);

        static::__assert_instance_of(Error_Response::class, $response);
        static::__assert_equals(Ajax::ERROR_QUESTION, $response->get_error_code());
    }

    public static function test_response_metadata_is_exactly_key_and_question()
    {
        $question = Form_Questions_Fixture_Controller::QUESTION;

        $metadata = response_form_question('proceed', $question)->get_metadata();

        static::__assert_equals(['key', 'question'], array_keys($metadata), 'nothing else rides along');
        static::__assert_equals('proceed', $metadata['key']);
        static::__assert_equals($question, $metadata['question'], 'the question travels verbatim');
    }

    public static function test_response_reason_is_the_default_question_message()
    {
        // No _message key, so the envelope falls back to the code's default rather than
        // reading a field of the question.
        static::__assert_equals(
            'A question is pending',
            response_form_question('proceed', ['kind' => 'confirm'])->get_reason()
        );
    }

    // -------------------------------------------------------------------------
    // Form_Questions::answer() / answered()
    // -------------------------------------------------------------------------

    public static function test_answer_is_null_when_nothing_was_answered()
    {
        static::__assert_null(Form_Questions::answer([], 'proceed'));
        static::__assert_null(Form_Questions::answer(['_answers' => []], 'proceed'));
        static::__assert_null(Form_Questions::answer(['_answers' => ['other' => true]], 'proceed'));
    }

    public static function test_false_is_an_answer_not_an_absence()
    {
        $params = ['_answers' => ['proceed' => false]];

        static::__assert_false(Form_Questions::answer($params, 'proceed'));
        static::__assert_true(Form_Questions::answered($params, 'proceed'));
    }

    public static function test_answer_returns_the_value_it_was_given()
    {
        static::__assert_true(Form_Questions::answer(['_answers' => ['proceed' => true]], 'proceed'));
        static::__assert_equals('later', Form_Questions::answer(['_answers' => ['proceed' => 'later']], 'proceed'));
        static::__assert_equals(0, Form_Questions::answer(['_answers' => ['proceed' => 0]], 'proceed'));
    }

    public static function test_answered_is_false_until_an_answer_arrives()
    {
        static::__assert_false(Form_Questions::answered([], 'proceed'));
        static::__assert_false(Form_Questions::answered(['_answers' => 'not an array'], 'proceed'));
        static::__assert_true(Form_Questions::answered(['_answers' => ['proceed' => 'x']], 'proceed'));
    }

    // -------------------------------------------------------------------------
    // The in-process caller
    // -------------------------------------------------------------------------

    public static function test_an_asking_endpoint_throws_the_question_exception()
    {
        $thrown = static::__assert_throws(AjaxQuestionException::class, function () {
            Ajax::internal('Form_Questions_Fixture_Controller', 'ask');
        });

        static::__assert_equals('proceed', $thrown->get_key());
        static::__assert_equals(
            Form_Questions_Fixture_Controller::QUESTION,
            $thrown->get_question(),
            'the question survives the round trip through the response envelope'
        );
    }

    public static function test_an_answered_endpoint_runs_to_completion()
    {
        $result = Ajax::internal('Form_Questions_Fixture_Controller', 'ask', [
            '_answers' => ['proceed' => true],
        ]);

        static::__assert_true($result['answered']);
    }

    public static function test_an_answer_of_false_reaches_the_endpoint()
    {
        $result = Ajax::internal('Form_Questions_Fixture_Controller', 'ask', [
            '_answers' => ['proceed' => false],
        ]);

        static::__assert_false($result['answered'], 'the endpoint acts on No; it never sees a cancel');
    }
}
