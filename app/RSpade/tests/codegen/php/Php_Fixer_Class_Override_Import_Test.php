<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use App\RSpade\Core\PHP\Php_Fixer;
use App\RSpade\Core\PHP\Rsx_Namespace;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
/**
 * An application's class override never makes the fixer edit a framework file.
 *
 * An override (rsx:man class_override) leaves the framework file in place, and the framework
 * name is an alias of the application's class at run time (Class_Override_Loader). A
 * framework file's import of the overridden class therefore already names the right class -
 * it is kept exactly as written, in every mode. Outside framework-developer mode the fixer
 * writes nothing under app/RSpade/ at all.
 *
 * THE RSX/ SIDE IS UNCHANGED. An application file importing the framework FQCN of a class the
 * application overrides is pointed at the rsx/ FQCN - deterministically, whatever order the
 * file map is iterated in.
 *
 * BACKLOG B-108 is why an import is never simply DELETED: with it gone, a type hint in an
 * inherited signature resolves the bare name into the file's own namespace, where no such
 * class exists, and the class fails to link at boot (observed 2026-09-07, ~50 imports dropped
 * from one clone).
 *
 * Everything here is driven through the public fix() entry point over a SYNTHETIC file map -
 * the map as it stands when the fixer runs, both declarations of the overridden name present
 * - so it exercises the real decision path without a manifest build. Only the file being
 * fixed exists on disk.
 */
class Php_Fixer_Class_Override_Import_Test extends Rsx_Test_Abstract
{
    // Token work and one temp file; no database.
    protected static $use_database_transactions = false;

    /** The overridden class's simple name - unique so no real class can shadow it. */
    private const OVERRIDDEN_CLASS = 'Fixture_B108_Attachment_Model';

    private const FRAMEWORK_FQCN = 'App\\RSpade\\Core\\Files\\' . self::OVERRIDDEN_CLASS;
    private const APPLICATION_FQCN = 'Rsx\\Models\\' . self::OVERRIDDEN_CLASS;

    /** The consumer file, relative to base_path(). */
    private static string $consumer_path = '';

    /**
     * A FRESH PATH PER TEST. The build's Source_Cache is keyed by path for the life of the
     * process, so re-using one path would serve the previous test's content to the next.
     */
    private static int $consumer_seq = 0;

    /**
     * Write a consumer that imports and type-hints the overridden class, in the framework
     * tree or the application tree, under the namespace its path gives it (so nothing but
     * the import is ever in question). Returns its content.
     */
    private static function __make_consumer(string $tree_root, string $import_fqcn): string
    {
        static::$consumer_seq++;
        static::$consumer_path = $tree_root . '/fixer_b108' . getmypid()
            . '/fixture_b108_consumer' . static::$consumer_seq . '.php';

        $absolute = base_path(static::$consumer_path);
        ensure_directory(dirname($absolute));

        $namespace = Rsx_Namespace::from_path(static::$consumer_path);
        $class = self::OVERRIDDEN_CLASS;
        $seq = static::$consumer_seq;

        $content = <<<PHP_SOURCE
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

PHP_SOURCE;

        file_put_contents($absolute, $content);

        return $content;
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
     * framework file it shadows are BOTH in the working set.
     */
    private static function __manifest_data(): array
    {
        return [
            'data' => [
                'files' => [
                    'app/RSpade/Core/Files/fixture_b108_attachment_model.php' => [
                        'extension' => 'php',
                        'class' => self::OVERRIDDEN_CLASS,
                        'fqcn' => self::FRAMEWORK_FQCN,
                    ],
                    'rsx/models/fixture_b108_attachment_model.php' => [
                        'extension' => 'php',
                        'class' => self::OVERRIDDEN_CLASS,
                        'fqcn' => self::APPLICATION_FQCN,
                    ],
                    static::$consumer_path => [
                        'extension' => 'php',
                        'class' => 'Fixture_B108_Consumer' . static::$consumer_seq,
                        'fqcn' => Rsx_Namespace::from_path(static::$consumer_path) . '\\Fixture_B108_Consumer' . static::$consumer_seq,
                    ],
                ],
            ],
        ];
    }

    /** Run the fixer over the consumer, $passes times; return whether any pass wrote. */
    private static function __fix(array $data, int $passes = 1): bool
    {
        $wrote = false;

        Php_Fixer::begin_run($data);

        try {
            for ($i = 0; $i < $passes; $i++) {
                $wrote = Php_Fixer::fix(static::$consumer_path, $data) || $wrote;
            }
        } finally {
            Php_Fixer::end_run();
        }

        return $wrote;
    }

    /**
     * A framework file importing the framework FQCN of a class the application overrides is
     * left exactly as it is - twice over, so a second build cannot disagree with the first.
     */
    public static function test_a_framework_import_of_an_overridden_class_is_kept()
    {
        $content = static::__make_consumer('app/RSpade/temp', self::FRAMEWORK_FQCN);

        try {
            static::__assert_false(static::__fix(static::__manifest_data(), 2), 'the fixer writes nothing');
            static::__assert_equals(
                $content,
                file_get_contents(base_path(static::$consumer_path)),
                'the framework file is byte-for-byte what it was, its import of the framework class included'
            );
        } finally {
            static::__remove_consumer();
        }
    }

    /**
     * Outside framework-developer mode, app/RSpade/ is framework property: the fixer writes
     * nothing there, even to an import it would otherwise correct.
     */
    public static function test_outside_framework_development_a_framework_file_is_never_written()
    {
        $content = static::__make_consumer('app/RSpade/temp', self::APPLICATION_FQCN);
        $saved = config('rsx.code_quality.is_framework_developer');

        config(['rsx.code_quality.is_framework_developer' => false]);

        try {
            static::__assert_false(static::__fix(static::__manifest_data()), 'the fixer declines the file');
            static::__assert_equals(
                $content,
                file_get_contents(base_path(static::$consumer_path)),
                'and the framework file is untouched'
            );
        } finally {
            config(['rsx.code_quality.is_framework_developer' => $saved]);
            static::__remove_consumer();
        }
    }

    /**
     * An application file importing the framework FQCN of a class the application overrides
     * is pointed at the rsx/ FQCN, whatever order the file map is iterated in.
     */
    public static function test_an_application_import_of_an_overridden_class_is_pointed_at_the_override()
    {
        foreach ([false, true] as $reversed) {
            static::__make_consumer('rsx/resource', self::FRAMEWORK_FQCN);

            try {
                $data = static::__manifest_data();

                if ($reversed) {
                    $data['data']['files'] = array_reverse($data['data']['files'], true);
                }

                static::__fix($data);

                $result = file_get_contents(base_path(static::$consumer_path));
                $order = $reversed ? 'rsx/ entry first' : 'framework entry first';

                static::__assert_true(
                    str_contains($result, 'use ' . self::APPLICATION_FQCN . ';'),
                    "({$order}) the import names the rsx/ FQCN of the overriding class\n" . $result
                );
                static::__assert_false(
                    str_contains($result, 'use ' . self::FRAMEWORK_FQCN . ';'),
                    "({$order}) the framework FQCN is gone from the application file"
                );
                static::__assert_true(
                    str_contains($result, self::OVERRIDDEN_CLASS . ' $record'),
                    "({$order}) the type hint the import exists for is untouched"
                );
            } finally {
                static::__remove_consumer();
            }
        }
    }
}
