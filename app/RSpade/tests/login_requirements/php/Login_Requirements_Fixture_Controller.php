<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\LoginRequirements\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;

/**
 * Real staff surfaces for the login-requirements tests: the fixture requirements' screens,
 * an endpoint one of them lists, and a page and an endpoint none of them list. Every one is
 * gated is_logged_in, so each answers only when the identity is visible to it.
 */
class Login_Requirements_Fixture_Controller extends Rsx_Controller_Abstract
{
    public const DISPATCHED = 'login_requirements_fixture_dispatched';

    #[Route('/_test/login-requirements/screen')]
    #[Auth('is_logged_in')]
    public static function screen(Request $request, array $params = [])
    {
        return ['marker' => self::DISPATCHED, 'surface' => 'screen'];
    }

    #[Route('/_test/login-requirements/second-screen')]
    #[Auth('is_logged_in')]
    public static function second_screen(Request $request, array $params = [])
    {
        return ['marker' => self::DISPATCHED, 'surface' => 'second_screen'];
    }

    #[Route('/_test/login-requirements/unlisted-page')]
    #[Auth('is_logged_in')]
    public static function unlisted_page(Request $request, array $params = [])
    {
        return ['marker' => self::DISPATCHED, 'surface' => 'unlisted_page'];
    }

    #[Ajax_Endpoint]
    #[Auth('is_logged_in')]
    public static function listed(Request $request, array $params = [])
    {
        return ['marker' => self::DISPATCHED, 'surface' => 'listed'];
    }

    #[Ajax_Endpoint]
    #[Auth('is_logged_in')]
    public static function unlisted(Request $request, array $params = [])
    {
        return ['marker' => self::DISPATCHED, 'surface' => 'unlisted'];
    }
}
