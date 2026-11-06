<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Locks\Cli;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * rsx:site_lock:endpoints - the site lock mode of every server-dispatched surface.
 *
 * Asserted against the locks concern's own fixture surfaces
 * (Site_Lock_Dispatch_Fixture_Controller: readers by default, #[Writer], #[Manual_Lock], an
 * undeclared and a #[Writer] page route), which are indexed while the suite runs. Run
 * in-process through Artisan::call().
 */
class Site_Lock_Endpoints_Command_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const FIXTURE = 'Site_Lock_Dispatch_Fixture_Controller::';

    public static function test_every_surface_carries_its_resolved_mode()
    {
        $rows = static::__json([]);

        static::__assert_equals(['reader', false, ['ajax']], static::__row($rows, 'reader_probe'), 'an undeclared Ajax endpoint is a reader by default');
        static::__assert_equals(['writer', true, ['ajax']], static::__row($rows, 'writer_probe'), 'a #[Writer] endpoint');
        static::__assert_equals(['manual', true, ['ajax']], static::__row($rows, 'manual_raw_lock'), 'a #[Manual_Lock] endpoint');
        static::__assert_equals(['none', false, ['route']], static::__row($rows, 'route_probe'), 'an undeclared page route takes no lock');
        static::__assert_equals(['writer', true, ['route']], static::__row($rows, 'route_writer_probe'), 'a #[Writer] page route');

        static::__assert_true(
            in_array('Orm_Controller::fetch', array_column($rows, 'surface'), true),
            'the model fetch endpoint is listed'
        );
    }

    public static function test_mode_and_kind_filter_the_list()
    {
        $writers = static::__json(['--mode' => ['writer']]);

        static::__assert_equals(['writer'], array_values(array_unique(array_column($writers, 'mode'))), 'only writers');
        static::__assert_not_null(static::__row($writers, 'writer_probe'));
        static::__assert_null(static::__row($writers, 'reader_probe'));

        $routes = static::__json(['--kind' => ['route'], '--mode' => ['none', 'writer']]);

        static::__assert_not_null(static::__row($routes, 'route_probe'));
        static::__assert_not_null(static::__row($routes, 'route_writer_probe'));
        static::__assert_null(static::__row($routes, 'writer_probe'), 'an Ajax writer is not a route');
    }

    public static function test_the_table_marks_a_declared_mode_and_an_unknown_mode_is_refused()
    {
        static::__assert_equals(0, Artisan::call('rsx:site_lock:endpoints', ['--mode' => ['writer']]));
        $output = Artisan::output();

        static::__assert_true(
            preg_match('/^writer\*\s+ajax\s+' . preg_quote(self::FIXTURE . 'writer_probe', '/') . '\s/m', $output) === 1,
            "a declared mode is starred: {$output}"
        );

        static::__assert_equals(1, Artisan::call('rsx:site_lock:endpoints', ['--mode' => ['sometimes']]));
        static::__assert_contains("[ERROR] Unknown --mode 'sometimes'", Artisan::output());
    }

    private static function __json(array $options): array
    {
        static::__assert_equals(0, Artisan::call('rsx:site_lock:endpoints', $options + ['--json' => true]));

        $rows = json_decode(Artisan::output(), true);
        static::__assert_true(is_array($rows), 'the command printed a JSON array');

        return $rows;
    }

    /** [mode, declared, kinds] of a fixture member, or null when it is not listed. */
    private static function __row(array $rows, string $method): ?array
    {
        foreach ($rows as $row) {
            if ($row['surface'] === self::FIXTURE . $method) {
                return [$row['mode'], $row['declared'], $row['kinds']];
            }
        }

        return null;
    }
}
