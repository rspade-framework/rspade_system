<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Core\PHP\Php_Fixer;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
/**
 * BACKLOG B-108: an overridden class's import is REWRITTEN, never dropped.
 *
 * THE FAILURE. Cloning a framework class into rsx/ makes the override pass archive the
 * framework file, so the framework FQCN stops existing. Every framework file that imported
 * it therefore has to be pointed at the rsx/ FQCN - and Php_Fixer DELETED the import
 * instead. For a same-namespace reference that is survivable (the bare name still resolves
 * through the autoloader at runtime); for a TYPE HINT IN AN INHERITED SIGNATURE it is not,
 * because PHP's compile-time signature-compatibility check does not autoload:
 *
 *   Could not check compatibility between ...::fetch(App\RSpade\Tests\...\File_Attachment_Model $x)
 *   and ...Rsx_Attachment_Handler_Abstract::fetch(App\RSpade\Core\Files\File_Attachment_Model $x)
 *
 * That is a boot fatal, and it is unrecoverable from inside the tree: no build can run to
 * undo the edit. Observed 2026-09-07 (~50 imports dropped from one clone).
 *
 * TWO HALVES, AND THEY MUST AGREE. The DELETE pass now rewrites a manifest-known name in
 * every mode, and the RE-ADD pass allows an `Rsx\` import into a framework file when the
 * name is an override. If only one half changed, the two passes would fight - one writing
 * the import, the other stripping it, on every build.
 *
 * Resolution is DETERMINISTIC: while both copies are still in the file map (the fixer runs
 * before the archive), rsx/ wins by construction, not by hash order.
 *
 * Everything here is driven through the public fix() entry point over a SYNTHETIC file map,
 * so it exercises the real decision path without a manifest build. Only the file being
 * fixed has to exist on disk.
 */
class Php_Fixer_Class_Override_Import_Test extends Rsx_Test_Abstract
{
    // Token work and one temp file; no database.
    protected static $use_database_transactions = false;

    /** The overridden class's simple name - unique so no real class can shadow it. */
    private const OVERRIDDEN_CLASS = 'Fixture_B108_Attachment_Model';

    /** The framework file that imports and type-hints it, relative to base_path(). */
    private static string $consumer_path = '';

    private static function __consumer_namespace(): string
    {
        return 'App\\RSpade\\Temp\\FixerB108' . getmypid();
    }

    /**
     * A FRESH PATH PER TEST. The build's Source_Cache is keyed by path for the life of the
     * process, so re-using one path would serve the previous test's content to the next.
     */
    private static int $consumer_seq = 0;

    private static function __make_consumer(string $import_fqcn): void
    {
        static::$consumer_seq++;
        static::$consumer_path = 'app/RSpade/temp/fixer_b108' . getmypid()
            . '/fixture_b108_consumer' . static::$consumer_seq . '.php';

        $absolute = base_path(static::$consumer_path);
        ensure_directory(dirname($absolute));

        $namespace = static::__consumer_namespace();
        $class = self::OVERRIDDEN_CLASS;
        $seq = static::$consumer_seq;

        file_put_contents($absolute, <<<PHP_SOURCE
<?php

namespace {$namespace};

use {$import_fqcn};

class Fixture_B108_Consumer{$seq}
{
    public static function fetch({$class} \$record): ?{$class}
    {
        return \$record;
    }
}

PHP_SOURCE);
    }

    private static function __remove_consumer(): void
    {
        if (static::$consumer_path === '') {
            return;
        }

        $absolute = base_path(static::$consumer_path);

        if (is_file($absolute)) {
            unlink($absolute);
        }

        @rmdir(dirname($absolute));
    }

    /**
     * The file map as it stands the moment the fixer runs: the rsx/ override and the
     * framework twin are BOTH still indexed (the archive happens later in the same build).
     */
    private static function __manifest_data(): array
    {
        return [
            'data' => [
                'files' => [
                    // Framework twin first, so a first-match implementation would answer
                    // with the framework FQCN and the test would fail.
                    'app/RSpade/Core/Files/fixture_b108_attachment_model.php' => [
                        'extension' => 'php',
                        'class' => self::OVERRIDDEN_CLASS,
                        'fqcn' => 'App\\RSpade\\Core\\Files\\' . self::OVERRIDDEN_CLASS,
                    ],
                    'rsx/models/fixture_b108_attachment_model.php' => [
                        'extension' => 'php',
                        'class' => self::OVERRIDDEN_CLASS,
                        'fqcn' => 'Rsx\\Models\\' . self::OVERRIDDEN_CLASS,
                    ],
                    static::$consumer_path => [
                        'extension' => 'php',
                        'class' => 'Fixture_B108_Consumer' . static::$consumer_seq,
                        'fqcn' => static::__consumer_namespace() . '\\Fixture_B108_Consumer' . static::$consumer_seq,
                    ],
                ],
            ],
        ];
    }

    /**
     * A framework file importing the ARCHIVED framework FQCN is repointed at the rsx/ one.
     */
    public static function test_framework_import_of_an_overridden_class_is_rewritten_to_rsx()
    {
        static::__make_consumer('App\\RSpade\\Core\\Files\\' . self::OVERRIDDEN_CLASS);

        try {
            $data = static::__manifest_data();

            Php_Fixer::begin_run($data);

            try {
                Php_Fixer::fix(static::$consumer_path, $data);
            } finally {
                Php_Fixer::end_run();
            }

            $result = file_get_contents(base_path(static::$consumer_path));

            static::__assert_true(
                str_contains($result, 'use Rsx\\Models\\' . self::OVERRIDDEN_CLASS . ';'),
                "the import names the rsx/ FQCN of the overriding class\n" . $result
            );

            static::__assert_false(
                str_contains($result, 'use App\\RSpade\\Core\\Files\\' . self::OVERRIDDEN_CLASS . ';'),
                'the stale framework FQCN is gone'
            );

            static::__assert_true(
                str_contains($result, self::OVERRIDDEN_CLASS . ' $record'),
                'the type hint the import exists for is untouched'
            );
        } finally {
            static::__remove_consumer();
        }
    }

    /**
     * An import that ALREADY names the rsx/ FQCN survives - the delete pass and the re-add
     * pass agree, so a second build does not undo the first.
     */
    public static function test_an_rsx_import_of_an_overridden_class_is_not_deleted()
    {
        static::__make_consumer('Rsx\\Models\\' . self::OVERRIDDEN_CLASS);

        try {
            $data = static::__manifest_data();

            Php_Fixer::begin_run($data);

            try {
                Php_Fixer::fix(static::$consumer_path, $data);
                // A second pass over the already-correct file must be a no-op, which is what
                // "the two passes agree" means in practice.
                Php_Fixer::fix(static::$consumer_path, $data);
            } finally {
                Php_Fixer::end_run();
            }

            $result = file_get_contents(base_path(static::$consumer_path));

            static::__assert_true(
                str_contains($result, 'use Rsx\\Models\\' . self::OVERRIDDEN_CLASS . ';'),
                "the correct import is still there after two passes\n" . $result
            );
        } finally {
            static::__remove_consumer();
        }
    }

    /**
     * The resolution does not depend on iteration order: with the rsx/ entry listed FIRST
     * the answer is the same one.
     */
    public static function test_resolution_is_deterministic_whatever_the_file_order()
    {
        static::__make_consumer('App\\RSpade\\Core\\Files\\' . self::OVERRIDDEN_CLASS);

        try {
            $data = static::__manifest_data();
            $data['data']['files'] = array_reverse($data['data']['files'], true);

            Php_Fixer::begin_run($data);

            try {
                Php_Fixer::fix(static::$consumer_path, $data);
            } finally {
                Php_Fixer::end_run();
            }

            $result = file_get_contents(base_path(static::$consumer_path));

            static::__assert_true(
                str_contains($result, 'use Rsx\\Models\\' . self::OVERRIDDEN_CLASS . ';'),
                "rsx/ wins with the entries in the other order too\n" . $result
            );
        } finally {
            static::__remove_consumer();
        }
    }
}
