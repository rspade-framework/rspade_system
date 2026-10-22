<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\SystemInfo;

use Illuminate\Http\Request;
use App\RSpade\Sys\App\Sys\SystemInfo\_Sys_System_Info;
use App\RSpade\Sys\Lib\_Sys_Endpoint_Controller_Abstract;

/**
 * The System Info screen's endpoints: the report (_Sys_System_Info::report()) and the
 * full phpinfo() page it links to.
 *
 * Both describe the PHP process answering the request - the web tier - which is the
 * one an operator asking "what is serving this site" means.
 */
#[Auth('is_sysadmin')]
class _Sys_System_Info_Controller extends _Sys_Endpoint_Controller_Abstract
{
    /**
     * The whole report; see _Sys_System_Info::report() for the shape.
     */
    #[Ajax_Endpoint]
    public static function report(Request $request, array $params = [])
    {
        return _Sys_System_Info::report();
    }

    /**
     * PHP's own phpinfo() page, as it prints it - the screen's "View full phpinfo" link,
     * opened in a new tab. A plain route, not a SPA screen: the page is PHP's HTML.
     * It names every ini value and environment variable the process sees, which is why
     * it carries the panel's gate like every other surface here.
     */
    #[Route('/_sys/system-info/phpinfo', methods: ['GET'])]
    public static function phpinfo(Request $request, array $params = [])
    {
        ob_start();
        phpinfo();
        $html = ob_get_clean();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
