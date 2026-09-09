<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Manifest\Php;

use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Dispatch\Route_ManifestSupport;
use App\RSpade\Core\Manifest\Full_ManifestSupport_Abstract;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\ManifestSupport_Abstract;
use App\RSpade\Core\Portal\Portal_Route_ManifestSupport;
use App\RSpade\Core\Portal\Portal_Spa_ManifestSupport;
use App\RSpade\Core\SPA\Spa_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * A derived section REPAIRS ITSELF on an ordinary build.
 *
 * THE INCIDENT. The standard route table was found EMPTY against a fully populated file
 * index. Every page answered 404 and all eight bundles failed to compile
 * ("_Sys_Dashboard_Action has no routes in the manifest"), and no ordinary rebuild fixed
 * it: the section was CARRIED FORWARD from the previous build and only rows belonging to
 * CHANGED files were ever re-derived, so an unchanged tree re-derived nothing. Touching one
 * controller restored exactly that controller's routes and no others. The only cure was
 * `rsx:manifest:build --force`, which works solely by making every file dirty at once.
 *
 * THE RULE THIS PINS (owner ruling 2026-09-09): the changed-set contract is for EXPENSIVE
 * work - reading source, including a class to reflect on it, writing a generated stub. A
 * module that only reads values already indexed in the manifest and regroups them derives
 * its section IN FULL, every build. It is a loop over a few thousand in-memory records on
 * the only occasion it runs, which is a code change, and it never runs in a served request.
 * That makes the empty-and-stuck state unreachable rather than recoverable.
 */
class Manifest_Derived_Section_Recovery_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * The exact incident, reproduced against the live manifest: empty the section, rebuild,
     * and it must come back WITHOUT anything having changed on disk.
     */
    public static function test_an_emptied_route_table_is_rebuilt_with_nothing_dirty()
    {
        $data = Manifest::get_full_manifest();

        $before = 0;
        foreach ($data['data']['routes'] ?? [] as $row) {
            if (($row['type'] ?? null) === 'standard') {
                $before++;
            }
        }

        static::__assert_true($before > 0, 'the live manifest carries standard route rows to begin with');

        // The state the box was found in: the file index intact, the derived section gone.
        $data['data']['routes'] = [];

        Route_ManifestSupport::rebuild($data);

        $after = 0;
        foreach ($data['data']['routes'] as $row) {
            if (($row['type'] ?? null) === 'standard') {
                $after++;
            }
        }

        static::__assert_equals(
            $before,
            $after,
            'an emptied route table is fully re-derived from the file map - no file had to change'
        );
    }

    /**
     * The same property for the other sections that answer a request: a portal route table,
     * the SPA rows, and the auth surfaces Rsx::Route() resolves a target through (an empty
     * surfaces map is what turned the outage into a 500 once routes were back).
     */
    public static function test_the_other_request_serving_sections_rebuild_too()
    {
        $data = Manifest::get_full_manifest();

        $portal_before = count($data['data']['portal_routes'] ?? []);
        $surfaces_before = count($data['data']['auth']['surfaces'] ?? []);

        static::__assert_true($portal_before > 0, 'the live manifest carries portal routes');
        static::__assert_true($surfaces_before > 0, 'the live manifest carries auth surfaces');

        $data['data']['portal_routes'] = [];
        $data['data']['auth'] = ['checks' => [], 'surfaces' => []];

        // portal_routes carries TWO row types owned by two modules, so both run - the same
        // pair, in the same order, that config('rsx.manifest_support') lists.
        Portal_Route_ManifestSupport::rebuild($data);
        Portal_Spa_ManifestSupport::rebuild($data);
        Auth_ManifestSupport::rebuild($data);

        static::__assert_equals(
            $portal_before,
            count($data['data']['portal_routes']),
            'an emptied portal route table is fully re-derived'
        );

        static::__assert_equals(
            $surfaces_before,
            count($data['data']['auth']['surfaces']),
            'an emptied auth surface map is fully re-derived'
        );
    }

    /**
     * A module that only regroups manifest data must NOT be a diffing module.
     *
     * Structural, and deliberately a named list rather than a heuristic: these are the
     * sections a request is served from, so a future change that moves one back onto the
     * changed-set contract is a change that can strand it empty again, and it should have
     * to delete this line to do it.
     */
    public static function test_the_request_serving_modules_derive_in_full()
    {
        $must_be_full = [
            Route_ManifestSupport::class,
            Portal_Route_ManifestSupport::class,
            Spa_ManifestSupport::class,
            Auth_ManifestSupport::class,
        ];

        foreach ($must_be_full as $module) {
            static::__assert_true(
                is_subclass_of($module, Full_ManifestSupport_Abstract::class),
                $module . ' derives its section in full - it only regroups data already in the manifest, '
                . 'and a carried-forward section that is lost cannot be restored by an unchanged tree'
            );
        }
    }

    /**
     * Every registered module is one kind or the other, and a full one can never be handed
     * a changed set (process() is final on the base and discards it).
     */
    public static function test_every_registered_module_declares_a_kind()
    {
        $modules = config('rsx.manifest_support', []);

        static::__assert_true(count($modules) > 0, 'modules are registered');

        foreach ($modules as $module) {
            static::__assert_true(
                is_subclass_of($module, ManifestSupport_Abstract::class),
                $module . ' extends a manifest support base'
            );
        }
    }
}
