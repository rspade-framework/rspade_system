<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TextTypes\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * An Ajax endpoint that reports what its 'body' param arrived as, so a test can see
 * whether an Ajax entry point rehydrated a {__TEXT, raw} envelope before the endpoint ran.
 *
 * @PHP-AUTH-01-EXCEPTION - gated declaratively via #[Auth].
 */
class Text_Ajax_Fixture_Controller extends Rsx_Controller_Abstract
{
    #[Ajax_Endpoint]
    #[Auth('public')]
    public static function describe_body(Request $request, array $params = [])
    {
        $body = $params['body'] ?? null;

        return ['type' => is_object($body) ? class_basename($body) : gettype($body)];
    }
}
