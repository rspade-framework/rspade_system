<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\JsTransform\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Decorator class-binding contract for the JS transform pipeline.
 *
 * Upstream Babel's decorator transform drops the module-scope name binding of a decorated
 * class DECLARATION that has static members. RSpade concatenates transform output into a
 * shared, non-module bundle scope, so decorated classes MUST keep their bare-name binding
 * or downstream references throw a ReferenceError (a white screen with no build signal).
 *
 * The binding is restored at the producer by the vendored fork
 * app/RSpade/Core/JsParsers/resource/babel-plugin-decorators (see its README.md). These
 * tests drive the REAL transform path (the exported internals of babel-service.js,
 * via harness.js) and prove, at runtime, that:
 *   - every decorated class keeps a reachable bare-name binding at modern/es6/es5
 *   - a decorated-static class executes correctly (static field, method, prototype chain,
 *     decorator execution)
 *   - a class decorator that returns a replacement binds the replacement
 *   - the fail-closed contract assertion trips when the binding is dropped (fork-less)
 *
 * No database. Everything shells to node.
 */
class Js_Decorator_Transform_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** Fixtures under test and the targets each is exercised at. */
    private const TARGETS = ['modern', 'es6', 'es5'];
    private const FIXTURES = [
        'Fixture_Static_Action',
        'Fixture_Member_Decs',
        'Fixture_Replacement',
        'Fixture_Method_Only',
        'Fixture_Plain',
        'Fixture_Title_Action',
        '_Fixture_Sys_Sidebar',
        '_Fixture_Sys_Action',
    ];

    private static function __harness_path(): string
    {
        return base_path('app/RSpade/tests/js_transform/resource/harness.js');
    }

    /**
     * Run the node harness and decode its single JSON output line.
     */
    private static function __run_harness(array $args): array
    {
        $process = new Process(array_merge(['node', static::__harness_path()], $args));
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            static::__fail(
                'Harness failed (' . implode(' ', $args) . "):\n" .
                $process->getOutput() . "\n" . $process->getErrorOutput()
            );
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (!is_array($decoded)) {
            static::__fail('Harness produced non-JSON output: ' . $process->getOutput());
        }

        return $decoded;
    }

    /**
     * Every decorated/undecorated fixture keeps a reachable bare-name binding at each
     * target. This is the core contract the fork restores.
     */
    public static function test_bare_name_binding_present_all_targets()
    {
        foreach (self::FIXTURES as $fixture) {
            foreach (self::TARGETS as $target) {
                $facts = static::__run_harness(['runtime', $fixture, $target]);
                static::__assert_true(
                    $facts['bare_name_defined'] ?? false,
                    "Bare-name binding missing for {$fixture} [{$target}]"
                );
            }
        }
    }

    /**
     * The transform EMITS the fork's `var <Name> =` binding for a decorated class
     * declaration with static members, at every target - asserted on the raw output text
     * rather than on runtime reachability, because the text is what gets concatenated.
     *
     * This used to drive the transformer's own CLI mode. There is no CLI any more: the
     * transform lives in the node service's `babel` module, which has one entry point. The
     * harness's `emit` mode requires that module out-of-process and prints what it produced,
     * so the assertion is unchanged - only the door it comes through moved.
     */
    public static function test_transform_emits_var_binding_all_targets()
    {
        foreach (self::TARGETS as $target) {
            $process = new Process([
                'node',
                static::__harness_path(),
                'emit',
                'Fixture_Static_Action',
                $target,
            ]);
            $process->setWorkingDirectory(base_path());
            $process->setTimeout(60);
            $process->run();

            static::__assert_true(
                $process->isSuccessful(),
                "transform failed [{$target}]: " . $process->getErrorOutput()
            );
            static::__assert_contains(
                'var Fixture_Static_Action =',
                $process->getOutput(),
                "emitted source missing the var binding [{$target}]"
            );
        }
    }

    /**
     * Case (a): a decorated class declaration with a static field executes correctly - the
     * static field, a method reading it, the prototype chain, and every class decorator all
     * work through the bound bare name, at every target.
     */
    public static function test_static_action_runtime_all_targets()
    {
        foreach (self::TARGETS as $target) {
            $facts = static::__run_harness(['runtime', 'Fixture_Static_Action', $target]);
            static::__assert_true($facts['bare_name_defined'], "no binding [{$target}]");
            static::__assert_equals(['overview', 'history'], $facts['static_field'], "static field [{$target}]");
            static::__assert_equals(['overview', 'history'], $facts['method_reads_static'], "method reads static [{$target}]");
            static::__assert_true($facts['instanceof_base'], "instanceof base [{$target}]");
            static::__assert_equals('/fixture/static', $facts['decorator_ran']['route'] ?? null, "route decorator ran [{$target}]");
            static::__assert_equals('Fixture_Spa_Layout', $facts['decorator_ran']['layout'] ?? null, "layout decorator ran [{$target}]");
            static::__assert_equals('Fixture_Spa_Controller::index', $facts['decorator_ran']['spa'] ?? null, "spa decorator ran [{$target}]");
        }
    }

    /**
     * Case (b): class decorator + member decorator + static field. Binding present, method
     * present, both decorators ran, at every target.
     */
    public static function test_member_decorators_runtime_all_targets()
    {
        foreach (self::TARGETS as $target) {
            $facts = static::__run_harness(['runtime', 'Fixture_Member_Decs', $target]);
            static::__assert_true($facts['bare_name_defined'], "no binding [{$target}]");
            static::__assert_equals(['a', 'b'], $facts['static_field'], "static field [{$target}]");
            static::__assert_true($facts['has_method'], "member method present [{$target}]");
            static::__assert_equals('/fixture/member', $facts['decorator_ran']['route'] ?? null, "route ran [{$target}]");
            static::__assert_equals(250, $facts['decorator_ran']['debounce'] ?? null, "member decorator ran [{$target}]");
        }
    }

    /**
     * Case (c): a class decorator returning a replacement class binds the REPLACEMENT to the
     * bare name (marker proves it), while the original stays reachable up the chain.
     */
    public static function test_replacement_decorator_wins_all_targets()
    {
        foreach (self::TARGETS as $target) {
            $facts = static::__run_harness(['runtime', 'Fixture_Replacement', $target]);
            static::__assert_true($facts['bare_name_defined'], "no binding [{$target}]");
            static::__assert_true($facts['bound_is_replacement'], "replacement did not win [{$target}]");
            static::__assert_true($facts['orig_via_chain'], "original not reachable up chain [{$target}]");
        }
    }

    /**
     * Case (d) control: a decorated class with a static METHOD but no static FIELDS. Upstream
     * keeps the class declaration; the contract assertion passes and the bare name binds.
     */
    public static function test_static_method_only_control_all_targets()
    {
        foreach (self::TARGETS as $target) {
            $facts = static::__run_harness(['runtime', 'Fixture_Method_Only', $target]);
            static::__assert_true($facts['bare_name_defined'], "no binding [{$target}]");
            static::__assert_true($facts['make_returns_instance'], "static method broken [{$target}]");
        }
    }

    /**
     * Case (e): a non-decorated class is left as an ordinary declaration and executes
     * normally at every target.
     */
    public static function test_plain_class_untouched_all_targets()
    {
        foreach (self::TARGETS as $target) {
            $facts = static::__run_harness(['runtime', 'Fixture_Plain', $target]);
            static::__assert_true($facts['bare_name_defined'], "no binding [{$target}]");
            static::__assert_equals(42, $facts['static_field'], "static field [{$target}]");
            static::__assert_equals(42, $facts['method_reads_static'], "method reads static [{$target}]");
        }
    }

    /**
     * Case (f): the @title decorator survives the transform as class METADATA. The SPA title
     * system reads `<Action>._spa_title` back off the class - Spa_Action::page_title() returns
     * it, and get_static_title() hands it to the layout as the zero-latency title - so a
     * dropped or unapplied class decorator would degrade every page to "(title not set)"
     * with no build signal.
     */
    public static function test_title_decorator_metadata_survives_all_targets()
    {
        foreach (self::TARGETS as $target) {
            $facts = static::__run_harness(['runtime', 'Fixture_Title_Action', $target]);
            static::__assert_true($facts['bare_name_defined'], "no binding [{$target}]");
            static::__assert_equals(
                'Fixture Title',
                $facts['spa_title'] ?? null,
                "@title metadata missing from the bound class [{$target}]"
            );
        }
    }

    /**
     * The fail-closed contract assertion trips when the binding is dropped: running the real
     * prefix plugin against a fork-LESS config (stock upstream decorator plugin) must throw,
     * naming the class, instead of silently shipping a broken bundle.
     */
    public static function test_fail_closed_assertion_trips_when_binding_dropped()
    {
        $result = static::__run_harness(['assert_negative']);
        static::__assert_true($result['threw'] ?? false, 'Assertion did not trip when binding was dropped');
        static::__assert_contains('Fixture_Static_Action', $result['message'] ?? '', 'Error did not name the class');
        static::__assert_contains('module-scope binding', $result['message'] ?? '', 'Error did not describe the contract');
    }

    /**
     * Emit the raw transformed source for a fixture. No timeout: the transform takes as
     * long as the machine needs and a cap would only fail a loaded box.
     */
    private static function __emit_source(string $fixture, string $target): string
    {
        $process = new Process(['node', static::__harness_path(), 'emit', $fixture, $target]);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(null);
        $process->run();

        static::__assert_true(
            $process->isSuccessful(),
            "emit failed for {$fixture} [{$target}]: " . $process->getErrorOutput()
        );

        return $process->getOutput();
    }

    /**
     * A framework-application name (a SINGLE leading underscore - the reserved prefix whose
     * shape lives in App\RSpade\Core\Naming\Rsx_Identifier) survives the transform with its
     * name intact. The prefix plugin decides generated-ness by PROVENANCE, so an authored
     * top-level class is never renamed to `_<fileHash><name>`.
     */
    public static function test_framework_prefixed_class_survives_transform()
    {
        foreach (self::TARGETS as $target) {
            $facts = static::__run_harness(['runtime', '_Fixture_Sys_Sidebar', $target]);
            static::__assert_true($facts['bare_name_defined'], "no binding [{$target}]");
            static::__assert_equals(240, $facts['static_field'], "static field [{$target}]");
            static::__assert_equals(240, $facts['method_reads_static'], "method reads static [{$target}]");
            static::__assert_true($facts['instanceof_base'], "instanceof base [{$target}]");

            $source = static::__emit_source('_Fixture_Sys_Sidebar', $target);
            static::__assert_contains(
                '_Fixture_Sys_Sidebar',
                $source,
                "the authored name was rewritten out of the emitted source [{$target}]"
            );
        }
    }

    /**
     * The DECORATED framework-application class: the vendored fork's `var <Name> = <uid>;`
     * binding must survive, which it only can because the prefix plugin recognises the name
     * as authored. This is the head-on collision the provenance rule resolves.
     */
    public static function test_framework_prefixed_decorated_class_keeps_bare_binding()
    {
        foreach (self::TARGETS as $target) {
            $source = static::__emit_source('_Fixture_Sys_Action', $target);
            static::__assert_contains(
                'var _Fixture_Sys_Action =',
                $source,
                "emitted source missing the fork's bare binding [{$target}]"
            );

            $facts = static::__run_harness(['runtime', '_Fixture_Sys_Action', $target]);
            static::__assert_true($facts['bare_name_defined'], "no binding [{$target}]");
            static::__assert_equals(['overview'], $facts['static_field'], "static field [{$target}]");
            static::__assert_equals('Root Probe', $facts['spa_title'] ?? null, "@title metadata [{$target}]");
        }
    }

    /**
     * The other half of the contract: a name Babel GENERATED is still hash-prefixed, so two
     * concatenated files cannot collide on `_applyDecs`. Provenance widened what is left
     * alone; it must not have widened it to everything.
     */
    public static function test_babel_generated_names_are_still_hash_prefixed()
    {
        $source = static::__emit_source('Fixture_Static_Action', 'es5');

        static::__assert_true(
            preg_match('/_[0-9a-f]{8}_applyDecs/', $source) === 1,
            'Babel helper _applyDecs was not hash-prefixed - generated names would collide in the bundle'
        );
        static::__assert_true(
            !str_contains($source, 'function _applyDecs('),
            'An unprefixed Babel helper _applyDecs reached the emitted source'
        );
    }

    /**
     * An authored top-level `_helper()` / `_CONST` in a NON-class file is left exactly as
     * written. Before provenance, every `_`-named top-level binding was claimed as Babel's.
     */
    public static function test_authored_underscore_bindings_are_left_alone()
    {
        foreach (self::TARGETS as $target) {
            $source = static::__emit_source('Fixture_Authored_Underscores', $target);

            static::__assert_contains('function _helper(', $source, "authored _helper renamed [{$target}]");
            static::__assert_contains('_CONST', $source, "authored _CONST renamed [{$target}]");
            static::__assert_true(
                preg_match('/_[0-9a-f]{8}_helper/', $source) !== 1,
                "authored _helper was hash-prefixed [{$target}]"
            );
            static::__assert_true(
                preg_match('/_[0-9a-f]{8}_CONST/', $source) !== 1,
                "authored _CONST was hash-prefixed [{$target}]"
            );
        }
    }
}
