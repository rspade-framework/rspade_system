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
 * Class and function NAMES survive minification.
 *
 * The runtime resolves jqhtml components and SPA actions by `cls.name`, so the MINIFIED
 * artifact a strict production build serves must still answer to every authored name. A
 * test on the Babel output alone cannot see a minifier rename: the decorator transform emits
 * `_<hash>_Name = Name`, and a minifier allowed to drop the class's own name binding leaves
 * an anonymous class expression whose `.name` JS infers from that hashed alias (or, at es5,
 * a mangled single letter). Every decorated SPA action then dispatched to a jqhtml
 * "Component with defaults" and no SPA page rendered - in strict production only, because
 * development and debug builds do not minify.
 *
 * These tests run each fixture through the REAL transform AND the REAL minify service
 * (app/RSpade/Core/Bundle/resource/minify-service.js, required in-process by harness.js
 * `minified` mode), evaluate the minified output in a vm sandbox, and read `.name`. The
 * last test pins the loud half: Manifest._define refuses a class whose `.name` is not its
 * manifest name, so a rename is a boot failure naming the class rather than a blank page.
 *
 * No database. Everything shells to node.
 */
class Js_Minify_Names_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const TARGETS = ['modern', 'es6', 'es5'];

    /** Decorated (statics branch, title metadata, surviving-declaration branch) and plain. */
    private const CLASS_FIXTURES = [
        'Fixture_Static_Action',
        'Fixture_Title_Action',
        'Fixture_Method_Only',
        'Fixture_Plain',
    ];

    /**
     * Run the harness in `minified` mode and decode its JSON line. No timeout: a transform
     * plus a Terser pass takes as long as the machine needs, and a cap would only fail a
     * loaded box.
     */
    private static function __minified_facts(string $fixture, string $target): array
    {
        $process = new Process([
            'node',
            base_path('app/RSpade/tests/js_transform/resource/harness.js'),
            'minified',
            $fixture,
            $target,
        ]);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            static::__fail(
                "Harness failed (minified {$fixture} {$target}):\n" .
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
     * Every class fixture - decorated or not - answers to its authored name AFTER
     * minification, at every transform target. The decorated ones are the regression: the
     * minified class must not answer to the transform's `_<hash>_<Name>` alias.
     */
    public static function test_class_names_survive_minification_all_targets()
    {
        foreach (self::CLASS_FIXTURES as $fixture) {
            foreach (self::TARGETS as $target) {
                $facts = static::__minified_facts($fixture, $target);

                // Control: the transform itself never moved the name.
                static::__assert_equals(
                    $fixture,
                    $facts['transformed']['class_name'] ?? null,
                    "transformed {$fixture} [{$target}] does not answer to its authored name"
                );
                static::__assert_equals(
                    $fixture,
                    $facts['minified']['class_name'] ?? null,
                    "minified {$fixture} [{$target}] answers to '" . ($facts['minified']['class_name'] ?? 'null') .
                    "' - the minifier renamed a class the runtime resolves by name (keep_classnames)"
                );
            }
        }
    }

    /**
     * A top-level function declaration and a named function nested inside an IIFE (mangle
     * scope) both keep their names through minification.
     */
    public static function test_function_names_survive_minification_all_targets()
    {
        foreach (self::TARGETS as $target) {
            $facts = static::__minified_facts('Fixture_Named_Functions', $target);

            static::__assert_equals(
                'fixture_top_level_function',
                $facts['minified']['top'] ?? null,
                "minified top-level function renamed [{$target}]"
            );
            static::__assert_equals(
                'fixture_inner_helper',
                $facts['minified']['inner'] ?? null,
                "minified nested function renamed [{$target}] (keep_fnames)"
            );
        }
    }

    /**
     * The loud half: the browser's class registry (the REAL Manifest._define, evaluated
     * from Core/Js/Manifest.js) refuses a class whose runtime `.name` is not its manifest
     * name, so a renamed class is a boot failure naming the class instead of a page that
     * quietly renders a default Component. A correctly named class registers as before.
     */
    public static function test_manifest_refuses_a_class_answering_to_another_name()
    {
        $process = new Process([
            'node',
            base_path('app/RSpade/tests/js_transform/resource/harness.js'),
            'manifest_identity',
        ]);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(null);
        $process->run();

        static::__assert_true($process->isSuccessful(), 'harness failed: ' . $process->getErrorOutput());
        $facts = json_decode(trim($process->getOutput()), true);

        static::__assert_false($facts['named']['threw'] ?? true, 'a correctly named class was refused');
        static::__assert_true($facts['named']['registered'] ?? false, 'a correctly named class was not registered');

        static::__assert_true($facts['renamed']['threw'] ?? false, 'a renamed class was registered silently');
        static::__assert_contains("Boot failure in class 'Fixture_Renamed_Action'", $facts['renamed']['message'] ?? '', 'the failure does not name the class');
        static::__assert_contains("answers to the name '_9de25a2c_Fixture_Renamed_Action'", $facts['renamed']['message'] ?? '', 'the failure does not name the runtime name');
    }
}
