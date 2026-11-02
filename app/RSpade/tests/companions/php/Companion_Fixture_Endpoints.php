<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Companions\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Task\Task;
use App\RSpade\Tests\Companions\Php\Companion_Fixture_Model;

/**
 * Test fixture: what a companion endpoint can observe and do.
 */
class Companion_Fixture_Endpoints
{
    #[Companion_Endpoint('companion_fixture')]
    public static function ping(Request $request, array $params = [])
    {
        return [
            'params' => $params,
            'site_id' => Session::get_site_id(),
            'session_id' => Session::get_session_id(),
            'has_browser' => !Session::_is_virtual(),
        ];
    }

    #[Companion_Endpoint('companion_fixture')]
    public static function nothing(Request $request, array $params = [])
    {
        return null;
    }

    #[Companion_Endpoint('companion_fixture')]
    public static function write_record(Request $request, array $params = [])
    {
        $record = new Companion_Fixture_Model();
        $record->name = (string) ($params['name'] ?? 'written');
        $record->save();

        return ['id' => (int) $record->id];
    }

    #[Companion_Endpoint('companion_fixture')]
    public static function run_as_dispatcher(Request $request, array $params = [])
    {
        return ['run' => Task::internal(\App\RSpade\Tests\Tasks\Php\Task_Run_As_Fixture_Service::class, 'as_dispatcher')];
    }

    #[Companion_Endpoint('companion_fixture')]
    public static function coded(Request $request, array $params = [])
    {
        abort(404, 'Fixture missing');
    }

    #[Companion_Endpoint('companion_fixture')]
    public static function fails(Request $request, array $params = [])
    {
        throw new \RuntimeException('Fixture endpoint failure');
    }
}
