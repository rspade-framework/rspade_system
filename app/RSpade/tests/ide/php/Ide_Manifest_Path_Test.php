<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Ide\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE IDE BRIDGE OPENS A MANIFEST KEY THROUGH ide_absolute_path(), NEVER BY HAND.
 *
 * The manifest keys every file from `base_path()`, which is `<project>/system`. The bridge
 * (`Ide/Services/handler.php`) runs from the PROJECT ROOT, before Laravel boots, so
 * `app/RSpade/Core/Rsx.php` is at `system/app/RSpade/Core/Rsx.php` on disk while
 * `rsx/models/client_model.php` is already right. Eleven call sites joined the key onto
 * IDE_BASE_PATH directly, so `file_exists()` said NO for every framework file and each
 * caller fell through to its "line 1" answer: go-to-definition on any framework class landed
 * at the top of the file rather than on the member, silently, for as long as the bridge has
 * existed.
 *
 * `ide_absolute_path()` (which composes `normalize_ide_path()`) is the one correct join, and
 * a path the IDE SENT is project-relative already and must not go through it.
 *
 * This is a STRUCTURAL test rather than an HTTP one, for the same reason the other bridge
 * tests are: handler.php is a pre-boot request handler, and including it runs a request. It
 * asserts the arithmetic that made the bug real, and then that no call site spells the join
 * by hand any more.
 */
class Ide_Manifest_Path_Test extends Rsx_Test_Abstract
{
    // Path arithmetic and a source grep - no database.
    protected static $use_database_transactions = false;

    private static function __handler_source(): string
    {
        $path = base_path('app/RSpade/Ide/Services/handler.php');
        static::__assert_true(is_file($path), 'the bridge handler is where this test expects it');

        return file_get_contents($path);
    }

    /**
     * The arithmetic itself: a framework manifest key resolves under `system/`, and the raw
     * project-root join names nothing. This is the defect, stated as an assertion.
     */
    public static function test_a_framework_manifest_key_only_resolves_under_system()
    {
        $project_root = dirname(base_path());
        $key = 'app/RSpade/Core/Manifest/Manifest.php';

        static::__assert_true(
            is_file($project_root . '/system/' . $key),
            'the framework key resolves when system/ is prepended'
        );

        static::__assert_false(
            is_file($project_root . '/' . $key),
            'and names nothing when it is joined onto the project root - the bug'
        );

        // An application key is already project-relative and must NOT be prefixed.
        $application_key = 'rsx/main.php';

        static::__assert_true(
            is_file($project_root . '/' . $application_key),
            'an rsx/ key resolves from the project root as it stands'
        );
    }

    /**
     * Every framework class the manifest knows resolves through the same rule, so the fix is
     * not specific to one file.
     */
    public static function test_every_framework_class_key_resolves_under_system()
    {
        $project_root = dirname(base_path());
        $classes = Manifest::$data['data']['php_classes'] ?? [];

        static::__assert_greater_than(0, count($classes), 'the manifest knows some PHP classes');

        $checked = 0;
        $missing = [];

        foreach ($classes as $name => $record) {
            $file = $record['file'] ?? null;

            if ($file === null || !str_starts_with($file, 'app/RSpade/')) {
                continue;
            }

            $checked++;

            if (!is_file($project_root . '/system/' . $file)) {
                $missing[] = $name . ' -> ' . $file;
            }
        }

        static::__assert_greater_than(0, $checked, 'some framework classes were examined');
        static::__assert_count(0, $missing, 'every framework key resolves: ' . implode(', ', array_slice($missing, 0, 5)));
    }

    /**
     * No call site joins a MANIFEST KEY onto IDE_BASE_PATH by hand.
     *
     * The shape is the variable name: `$file_path` and a manifest record's `js_file` are
     * manifest keys throughout that file, while `$file` is what the editor sent.
     */
    public static function test_no_call_site_joins_a_manifest_key_by_hand()
    {
        $source = self::__handler_source();

        foreach (["IDE_BASE_PATH . '/' . \$file_path", "IDE_BASE_PATH . '/' . \$component"] as $shape) {
            static::__assert_false(
                str_contains($source, $shape),
                "a manifest key is joined by hand: {$shape} - use ide_absolute_path()"
            );
        }

        static::__assert_true(
            str_contains($source, 'function ide_absolute_path('),
            'the one correct join still exists'
        );
    }

    /**
     * The joins that REMAIN are the project-relative ones, and they are named.
     *
     * A new `IDE_BASE_PATH . '/' . <something>` join has to be added to this list
     * deliberately, which is the point: the next person joining a manifest key has to
     * explain why it is not a manifest key.
     */
    public static function test_remaining_joins_are_the_known_project_relative_ones()
    {
        $source = self::__handler_source();

        $allowed = [
            "IDE_BASE_PATH . '/storage'",              // volatile storage, project level
            "IDE_BASE_PATH . '/' . ltrim(\$file, '/')", // a path the editor sent
            "IDE_BASE_PATH . '/artisan'",              // the project-root artisan shim
            "IDE_BASE_PATH . '/' . normalize_ide_path", // ide_absolute_path() itself
        ];

        preg_match_all("/IDE_BASE_PATH \. '\/[^\n]*/", $source, $matches);

        foreach ($matches[0] as $occurrence) {
            $recognized = false;

            foreach ($allowed as $form) {
                if (str_starts_with($occurrence, $form)) {
                    $recognized = true;

                    break;
                }
            }

            static::__assert_true(
                $recognized,
                'unrecognized IDE_BASE_PATH join - is it a manifest key? ' . trim($occurrence)
            );
        }
    }
}
