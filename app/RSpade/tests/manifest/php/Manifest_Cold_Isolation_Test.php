<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE SPLIT INDEX EARNS ITS KEEP ONLY IF THE COLD HALF IS NEVER LOADED.
 *
 * The index is two files: `manifest_index.php` (routes, class maps, auth surfaces, the
 * attribute index, and the `files` entries whose METHOD MAP is read at request time) and
 * `manifest_files.php` (every other record, method maps intact). If a served request merges
 * the cold half, the split has cost a stat and bought nothing.
 *
 * `--_manifest-report-cold` (the `--_` convention: no InputOption, stripped from argv
 * pre-boot, absent from every help output) makes any artisan process print, at shutdown,
 *
 *     MANIFEST_COLD_LOADS=<n>
 *     MANIFEST_REBUILT=<0|1>
 *
 * These tests read those out of a CHILD process, because the test process is already
 * holding a manifest somebody else loaded.
 *
 * WHY THE SECOND LINE EXISTS. A BUILD owns the whole tree: `_refresh_manifest()` merges the
 * cold half deliberately, before it carries anything forward. So a cold-load count says
 * something about the REQUEST PATH only when the child did not build - and whether a child
 * builds is not this test's to decide. A served site must not index the test trees, so the
 * moment a web request lands the index is rewritten to the plain scan set, and the next test
 * child puts the 635 fixtures back: that IS a rebuild, and it loads the cold half correctly.
 * On this box a health check hits `/` every ten seconds, so that window is never reliably
 * quiet. The predecessor of this test asserted zero cold loads unconditionally and was
 * therefore green or red by luck (runs observed: 4/4, 4/4, 1/4, 2/4, 3/4, 1/4).
 *
 * WHAT IS ASSERTED, THEREFORE: a boot that did NOT rebuild loaded nothing from the cold
 * half. When the probe reports a rebuild, the assertion is the rebuild path's instead - the
 * child still has to have produced a working answer - and the run does not count as evidence
 * about the request path. No sleeps and no settle loops: the honest claim is a CONDITIONAL
 * one, and a conditional claim is stated, not waited for.
 *
 * A failure here is a FINDING with a name attached: run the same command with the trace and
 * the stack says which accessor pulled a record the hot index does not carry.
 */
class Manifest_Cold_Isolation_Test extends Rsx_Test_Abstract
{
    // Children of this test hit the database (an Ajax endpoint runs for real), and the
    // parent must not hold a transaction open around them.
    protected static $use_database_transactions = false;

    /**
     * Run an artisan command in a child with the probe on.
     *
     * @return array{cold:int,rebuilt:bool,output:array<int,string>}
     */
    private static function __probe(string $command, array $arguments = []): array
    {
        $output = [];

        Rsx_Artisan::run($command, array_merge($arguments, ['--_manifest-report-cold']), $output);

        $cold = null;
        $rebuilt = null;

        foreach ($output as $line) {
            if (preg_match('/^MANIFEST_COLD_LOADS=(\d+)$/', trim($line), $matches)) {
                $cold = (int) $matches[1];
            }

            if (preg_match('/^MANIFEST_REBUILT=([01])$/', trim($line), $matches)) {
                $rebuilt = $matches[1] === '1';
            }
        }

        if ($cold === null || $rebuilt === null) {
            static::__fail("the child did not report its manifest probe lines:\n" . implode("\n", $output));
        }

        return ['cold' => $cold, 'rebuilt' => $rebuilt, 'output' => $output];
    }

    /**
     * The assertion every case below makes, over one child's probe.
     *
     * DID NOT REBUILD -> zero cold loads. That is the claim the split index makes.
     * DID REBUILD -> at least one, because a build merges the cold half on purpose; a
     * rebuild that reported ZERO would mean the build carried entries forward from a half
     * index, which is the OTHER way this can be wrong.
     */
    private static function __assert_request_path_is_hot(array $probe, string $what): void
    {
        if (!$probe['rebuilt']) {
            static::__assert_equals(0, $probe['cold'], $what . ' must be answered entirely by the hot index');

            return;
        }

        static::__assert_true(
            $probe['cold'] >= 1,
            $what . ' rebuilt the index (the tree moved underneath this run), and a build must'
            . ' merge the cold half before it carries any record forward'
        );
    }

    /**
     * BOOTING loads the hot index and nothing else.
     *
     * rsx:manifest:get_build_key prints one string the hot index carries: a full boot -
     * providers, the autoloader, the classless files - and no file record at all.
     */
    public static function test_booting_does_not_load_the_cold_half()
    {
        static::__assert_request_path_is_hot(
            static::__probe('rsx:manifest:get_build_key'),
            'boot'
        );
    }

    /**
     * AN AJAX CALL - the one request shape that used to read a controller's whole method map
     * twice, once to find the controller and once to prove the method was an endpoint.
     */
    public static function test_an_ajax_call_does_not_load_the_cold_half()
    {
        static::__assert_request_path_is_hot(
            static::__probe('rsx:ajax', [
                'controller' => 'Rsx_Timezone_Controller',
                'action' => 'set_timezone',
                '--args={"timezone":"UTC"}',
                '--user=1',
            ]),
            'an Ajax dispatch'
        );
    }

    /**
     * A MODEL FETCH - Orm_Controller reads the model's fetch attribute and its relationship
     * methods, which is exactly why models and their ancestors stay in the hot half.
     */
    public static function test_a_model_fetch_does_not_load_the_cold_half()
    {
        static::__assert_request_path_is_hot(
            static::__probe('rsx:ajax', [
                'controller' => 'Orm_Controller',
                'action' => 'fetch',
                '--args={"model":"User_Model","ids":[1]}',
                '--user=1',
            ]),
            'a model fetch'
        );
    }

    /**
     * ASKING FOR THE WHOLE TREE loads it - once.
     */
    public static function test_get_all_loads_the_cold_half_once()
    {
        $before = Manifest::cold_load_count();

        $all = Manifest::get_all();
        Manifest::get_all();
        Manifest::get_all();

        static::__assert_true(
            Manifest::cold_load_count() - $before <= 1,
            'the cold half is merged at most once per process'
        );

        static::__assert_equals(
            count(Manifest::get_full_manifest()['data']['file_index']),
            count($all),
            'after the merge, get_all() returns every indexed file'
        );
    }
}
