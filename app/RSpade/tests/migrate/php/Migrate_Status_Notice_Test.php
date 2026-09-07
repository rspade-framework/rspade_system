<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use Illuminate\Support\Facades\Artisan;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * migrate:status_notice is an advisory command: it prints a one-line notice when migrations
 * are pending and NOTHING when the schema is current, and it must never fail (exit non-zero)
 * regardless of migrator state. It is wired into the rsx:framework:pull post-update tail.
 */
class Migrate_Status_Notice_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Always exits 0 (advisory). When it does emit a notice, the notice matches the exact
     * documented wording. This holds whichever way the ambient pending-count falls, so the
     * test is robust to the test DB's migration state.
     */
    public static function test_exit_zero_and_notice_format()
    {
        $code = Artisan::call('migrate:status_notice');
        static::__assert_equals(0, $code, 'migrate:status_notice must always exit 0 (advisory command)');

        $out = trim(Artisan::output());
        if ($out !== '') {
            $matched = (bool) preg_match(
                '/^There are \d+ unapplied migrations pending\. Please run `php artisan migrate` now to run them$/',
                $out
            );
            static::__assert_true($matched, 'notice did not match the documented wording: ' . $out);
        }
    }
}
