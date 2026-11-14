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
 * Private class members stay private to their own file in a bundle.
 *
 * The transform lowers every private member to a generated top-level holder: `static #X`
 * becomes `var _X = {_: ...}` and an instance `#x` becomes `var _x = new WeakMap()`. Bundles
 * concatenate transformed files into one shared script scope, so each holder must carry its
 * file's hash prefix like every other generated name; an unprefixed holder is shared by every
 * file that declares a member of that name, and the last file's value silently wins.
 *
 * Drives the REAL `babel.transform` handler through resource/private_fields.js: two fixture
 * classes declaring the same `static #SCALE` and `#count`, transformed separately, then
 * concatenated and evaluated as one script.
 *
 * No database. Everything shells to node.
 */
class Js_Private_Field_Transform_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * Run the harness and decode its JSON line. No timeout: two transforms take as long as
     * the machine needs, and a cap would only fail a loaded box.
     */
    private static function __facts(): array
    {
        $process = new Process(['node', base_path('app/RSpade/tests/js_transform/resource/private_fields.js')]);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            static::__fail("private_fields.js failed:\n" . $process->getOutput() . "\n" . $process->getErrorOutput());
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (!is_array($decoded)) {
            static::__fail('private_fields.js produced non-JSON output: ' . $process->getOutput());
        }

        return $decoded;
    }

    /**
     * Two classes with the same `static #SCALE` each read their own value once concatenated.
     */
    public static function test_static_private_fields_keep_separate_values_when_concatenated()
    {
        $facts = static::__facts();

        static::__assert_equals(100, $facts['alpha_scale'] ?? null, 'Fixture_Private_Alpha read another class\'s static #SCALE');
        static::__assert_equals(7, $facts['beta_scale'] ?? null, 'Fixture_Private_Beta static #SCALE');
    }

    /**
     * Two classes with the same instance `#count` each read their own value, and the brand
     * check of one class refuses the other's instance - one shared WeakMap would accept it.
     */
    public static function test_instance_private_fields_keep_separate_storage_when_concatenated()
    {
        $facts = static::__facts();

        static::__assert_equals(1, $facts['alpha_count'] ?? null, 'Fixture_Private_Alpha #count');
        static::__assert_equals(2, $facts['beta_count'] ?? null, 'Fixture_Private_Beta #count');
        static::__assert_true($facts['alpha_brands_alpha'] ?? false, '`#count in` refused an instance of its own class');
        static::__assert_false(
            $facts['alpha_brands_beta'] ?? true,
            '`#count in` accepted an instance of ANOTHER class - the two classes share one private-field WeakMap'
        );
    }

    /**
     * Every generated holder in the emitted text carries its file's hash prefix.
     */
    public static function test_private_member_holders_are_hash_prefixed()
    {
        $facts = static::__facts();

        foreach (['alpha_holders', 'beta_holders'] as $key) {
            static::__assert_count(2, $facts[$key] ?? [], "expected two private-member holders in {$key}");
            foreach ($facts[$key] as $holder) {
                static::__assert_true(
                    preg_match('/^_[0-9a-f]{8}_(SCALE|count)$/', $holder) === 1,
                    "private-member holder '{$holder}' is not hash-prefixed ({$key})"
                );
            }
        }
    }
}
