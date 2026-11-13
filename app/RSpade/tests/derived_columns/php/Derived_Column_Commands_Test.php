<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\DerivedColumns\Php;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Realtime\Realtime_Emissions;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\DerivedColumns\Php\Derived_Column_Fixture_Tables;

/**
 * rsx:derived:check reports derived-column drift and changes nothing; rsx:derived:rebuild
 * repairs it with the same pass loop and output as migrate's rederive step, sending realtime
 * frames as any save does. Both run in-process through Artisan::call(), so the command's
 * site lock enforcement window is real: the rebuild of a site-scoped table must take each
 * site's write lock to save at all.
 */
class Derived_Column_Commands_Test extends Rsx_Test_Abstract
{
    private const OLD_STAMP = '2020-01-02 03:04:05.678';

    public static function setup()
    {
        Derived_Column_Fixture_Tables::create();
    }

    public static function teardown()
    {
        config(['rsx.realtime.enabled' => false]);
        Realtime_Emissions::_testing_reset();
        Derived_Column_Fixture_Tables::drop();
    }

    private static function __plain_rows(): void
    {
        DB::insert(
            'INSERT INTO derived_plain_fixtures (first_name, last_name, full_name, updated_at) VALUES (?, ?, ?, ?), (?, ?, ?, ?)',
            ['Ada', 'Lovelace', 'Stale', self::OLD_STAMP, 'Grace', 'Hopper', 'Grace Hopper', self::OLD_STAMP]
        );
    }

    // DERIVED-CMD-CHECK: drift is reported with examples, exit 1, nothing changed.
    public static function test_check_reports_drift_and_changes_nothing()
    {
        static::__plain_rows();
        $id = (int) DB::selectOne("SELECT id FROM derived_plain_fixtures WHERE first_name = 'Ada'")->id;

        $exit = Artisan::call('rsx:derived:check', ['table' => 'derived_plain_fixtures']);
        $output = Artisan::output();

        static::__assert_equals(1, $exit, 'drift is a non-zero exit');
        static::__assert_contains('derived_plain_fixtures: 1 of 2 record(s) drifting', $output);
        static::__assert_contains("#{$id} full_name: 'Stale' -> 'Ada Lovelace'", $output);
        static::__assert_equals('Stale', Derived_Column_Fixture_Tables::raw_row('derived_plain_fixtures', $id)['full_name'], 'nothing was written');
    }

    // DERIVED-CMD-CHECK-CLEAN
    public static function test_check_of_a_current_table_exits_zero()
    {
        DB::insert("INSERT INTO derived_plain_fixtures (first_name, last_name, full_name) VALUES ('Ada', 'Lovelace', 'Ada Lovelace')");

        static::__assert_equals(0, Artisan::call('rsx:derived:check', ['table' => 'derived_plain_fixtures']));
        static::__assert_contains('none drifting', Artisan::output());
    }

    // DERIVED-CMD-REFUSE: a table with no model, a model with no derived columns, and a call naming neither or both of <table> / --all are errors.
    public static function test_a_table_that_cannot_be_checked_is_refused()
    {
        static::__assert_equals(1, Artisan::call('rsx:derived:check', ['table' => 'no_such_rederive_table']));
        static::__assert_contains('No model owns the table', Artisan::output());

        static::__assert_equals(1, Artisan::call('rsx:derived:rebuild', ['table' => 'derived_category_fixtures']));
        static::__assert_contains('declares no derived columns', Artisan::output());

        static::__assert_equals(1, Artisan::call('rsx:derived:check'), 'neither a table nor --all');
        static::__assert_contains('Name one table, or pass --all', Artisan::output());

        static::__assert_equals(1, Artisan::call('rsx:derived:rebuild', ['table' => 'derived_plain_fixtures', '--all' => true]), 'both a table and --all');
        static::__assert_contains('Name one table, or pass --all', Artisan::output());
    }

    // DERIVED-CMD-REBUILD: repairs, prints the drain's output, leaves updated_at alone.
    public static function test_rebuild_repairs_the_drift()
    {
        static::__plain_rows();

        $exit = Artisan::call('rsx:derived:rebuild', ['table' => 'derived_plain_fixtures']);
        $output = Artisan::output();

        static::__assert_equals(0, $exit);
        static::__assert_equals(
            "Rederiving cached values for derived_plain_fixtures....\n"
            . "Rederiving cached values for derived_plain_fixtures....\n"
            . "Done rederiving cached values\n",
            $output
        );
        static::__assert_equals(0, (int) DB::selectOne("SELECT COUNT(*) AS c FROM derived_plain_fixtures WHERE full_name = 'Stale'")->c);
        static::__assert_equals(2, (int) DB::selectOne('SELECT COUNT(*) AS c FROM derived_plain_fixtures WHERE updated_at = ?', [self::OLD_STAMP])->c);
        static::__assert_equals(0, Artisan::call('rsx:derived:check', ['table' => 'derived_plain_fixtures']), 'and check now finds nothing');
    }

    // DERIVED-CMD-REBUILD-SITE: a site-scoped table under the command's enforcement window, with realtime.
    public static function test_rebuild_of_a_site_scoped_table_takes_the_site_lock_and_sends_realtime()
    {
        config(['rsx.realtime.enabled' => true]);
        DB::insert('INSERT INTO derived_site_fixtures (site_id, name, label) VALUES (1, ?, ?), (1, ?, ?)', ['alpha', 'STALE', 'beta', 'BETA']);
        Realtime_Emissions::_testing_reset();

        static::__assert_equals(0, Artisan::call('rsx:derived:rebuild', ['table' => 'derived_site_fixtures']), Artisan::output());

        static::__assert_equals(['ALPHA', 'BETA'], array_map(fn ($row) => $row->label, DB::select('SELECT label FROM derived_site_fixtures ORDER BY id')));

        $pending = Realtime_Emissions::_testing_pending();
        static::__assert_count(1, $pending, 'one frame, for the one record saved');
        static::__assert_equals('Derived_Site_Fixture_Model', $pending[0]['model']);
    }

    // DERIVED-CMD-REBUILD-UNSETTLED: never settling is a non-zero exit with the warning.
    public static function test_rebuild_of_values_that_never_settle_exits_non_zero()
    {
        DB::statement('INSERT INTO derived_unstable_fixtures (tick) VALUES (NULL)');

        static::__assert_equals(1, Artisan::call('rsx:derived:rebuild', ['table' => 'derived_unstable_fixtures']));

        $output = Artisan::output();
        static::__assert_equals(3, substr_count($output, 'Rederiving cached values for derived_unstable_fixtures...'), 'one table: three passes');
        static::__assert_contains('[WARNING] Cached values were still changing after 3 passes', $output);
    }
}
