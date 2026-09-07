<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Email_ManifestSupport;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Mail_Manifest_Support_Test - the build-time contract every email class owes.
 *
 * Email_ManifestSupport::process() is a PURE FUNCTION over the manifest's files array,
 * which is what makes this class possible: a bad declaration can be proved to break the
 * build without a bad declaration ever existing in the tree, and without a real build.
 * Every test here hands process() a synthetic files array and asserts on what comes back
 * or on the RuntimeException that does.
 *
 * The three FATALs exist because each one is otherwise invisible until it is expensive:
 *
 *   No CATEGORY - there is no safe default. TRANSACTIONAL mails people who opted out;
 *   NOTIFICATION silently drops mail somebody needed. Only the author can decide.
 *
 *   No sample() - an email nobody can preview is an email nobody reviews before it
 *   reaches a customer.
 *
 *   No blade with a matching @rsx_id - the class name IS the template id, so the
 *   mismatch would surface at render time, in a background task, hours after the code
 *   shipped.
 *
 * The category is read from the SOURCE FILE rather than by reflection, because the
 * manifest builds before the autoloader can resolve application classes - so these
 * tests write real fixture files and point the synthetic entries at them.
 */
class Mail_Manifest_Support_Test extends Rsx_Test_Abstract
{
    // The support module is a pure function over an array; the fixtures are files.
    protected static $use_database_transactions = false;

    /** Directories this class created, removed after each build. */
    private static array $fixture_roots = [];

    /**
     * Write a fixture email class source and return its path RELATIVE to base_path(),
     * which is the key shape the manifest's files array uses.
     */
    private static function __write_class(string $class, string $body): string
    {
        $root = 'storage/rsx-tmp/email_manifest_fixture_' . uniqid();
        $relative = $root . '/' . strtolower($class) . '.php';
        $absolute = base_path($relative);

        ensure_directory(dirname($absolute));
        file_put_contents_safe($absolute, "<?php\n\nclass {$class} extends Rsx_Email_Abstract\n{\n{$body}\n}\n");

        self::$fixture_roots[] = base_path($root);

        return $relative;
    }

    private static function __cleanup(): void
    {
        foreach (self::$fixture_roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            foreach (scandir($root) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($root . '/' . $entry);
                }
            }

            @rmdir($root);
        }

        self::$fixture_roots = [];
    }

    /**
     * A synthetic manifest holding ONE email class and, optionally, its blade.
     *
     * The shape mirrors what the manifest scanner bakes: a class entry carries
     * class/fqcn/extends/abstract plus public_static_methods, and a view entry carries
     * type 'view' and the @rsx_id it declared.
     */
    private static function __manifest(
        string $class,
        string $file,
        bool $with_view = true,
        bool $abstract = false,
        string $extends = 'Rsx_Email_Abstract',
        array $static_methods = ['sample' => []]
    ): array {
        $files = [
            $file => [
                'class' => $class,
                'fqcn' => 'Rsx\\Emails\\' . $class,
                'extends' => $extends,
                'abstract' => $abstract,
                'public_static_methods' => $static_methods,
            ],
        ];

        if ($with_view) {
            $files['rsx/emails/fixture.blade.php'] = [
                'type' => 'view',
                'id' => $class,
            ];
        }

        return ['data' => ['files' => $files]];
    }

    /**
     * Run the support module and hand back the baked email table.
     */
    private static function __process(array $manifest): array
    {
        Email_ManifestSupport::process($manifest);

        return $manifest['data']['emails'];
    }

    // =========================================================================
    // THE HAPPY PATH
    // =========================================================================

    public static function test_a_well_formed_email_class_is_baked_into_the_table()
    {
        $file = static::__write_class(
            'Fixture_Welcome_Email',
            "    const CATEGORY = self::TRANSACTIONAL;\n"
        );

        try {
            $table = static::__process(static::__manifest('Fixture_Welcome_Email', $file));

            static::__assert_array_has_key('Fixture_Welcome_Email', $table, 'the class is in the baked table');
            static::__assert_equals(
                1,
                $table['Fixture_Welcome_Email']['category'],
                'with the category it declared'
            );
            static::__assert_equals(
                'Fixture_Welcome_Email',
                $table['Fixture_Welcome_Email']['view_id'],
                'and the view id, which is the class basename'
            );
            static::__assert_equals($file, $table['Fixture_Welcome_Email']['file'], 'and where it lives');
        } finally {
            static::__cleanup();
        }
    }

    public static function test_every_category_spelling_is_recognized()
    {
        $expected = ['TRANSACTIONAL' => 1, 'NOTIFICATION' => 2, 'MARKETING' => 3];

        try {
            foreach ($expected as $name => $value) {
                $class = 'Fixture_' . $name . '_Email';
                $file = static::__write_class($class, "    const CATEGORY = self::{$name};\n");

                $table = static::__process(static::__manifest($class, $file));

                static::__assert_equals($value, $table[$class]['category'], "self::{$name} is category {$value}");
            }
        } finally {
            static::__cleanup();
        }
    }

    /**
     * An application may put an abstract base of its own between Rsx_Email_Abstract and
     * its concrete emails. Only a class somebody can actually instantiate owes the
     * contract - an abstract with no CATEGORY is not a defect.
     */
    public static function test_an_abstract_intermediate_class_owes_nothing()
    {
        $file = static::__write_class('Fixture_Base_Email', "    // no category, no sample\n");

        try {
            $table = static::__process(
                static::__manifest('Fixture_Base_Email', $file, false, true, 'Rsx_Email_Abstract', [])
            );

            static::__assert_count(0, $table, 'an abstract base is not an email');
        } finally {
            static::__cleanup();
        }
    }

    public static function test_a_class_that_is_not_an_email_is_ignored()
    {
        $file = static::__write_class('Fixture_Not_An_Email', "    // nothing at all\n");

        try {
            $table = static::__process(
                static::__manifest('Fixture_Not_An_Email', $file, false, false, 'Rsx_Model_Abstract', [])
            );

            static::__assert_count(0, $table, 'the module only looks at Rsx_Email_Abstract descendants');
        } finally {
            static::__cleanup();
        }
    }

    // =========================================================================
    // THE FATALS
    // =========================================================================

    public static function test_a_missing_category_is_a_fatal_that_shows_the_three_choices()
    {
        $file = static::__write_class('Fixture_No_Category_Email', "    // the author never decided\n");

        try {
            $exception = static::__assert_throws(
                \RuntimeException::class,
                fn () => static::__process(static::__manifest('Fixture_No_Category_Email', $file)),
                'has no CATEGORY'
            );

            static::__assert_contains(
                'Fixture_No_Category_Email',
                $exception->getMessage(),
                'the message names the class'
            );
            static::__assert_contains($file, $exception->getMessage(), 'and the file');
            static::__assert_contains(
                'self::TRANSACTIONAL',
                $exception->getMessage(),
                'and spells out the choice the author has to make'
            );
        } finally {
            static::__cleanup();
        }
    }

    public static function test_an_unrecognized_category_is_a_fatal()
    {
        $file = static::__write_class('Fixture_Bad_Category_Email', "    const CATEGORY = 'urgent';\n");

        try {
            static::__assert_throws(
                \RuntimeException::class,
                fn () => static::__process(static::__manifest('Fixture_Bad_Category_Email', $file)),
                'unrecognized CATEGORY'
            );
        } finally {
            static::__cleanup();
        }
    }

    public static function test_a_missing_sample_is_a_fatal_that_shows_the_signature()
    {
        $file = static::__write_class('Fixture_No_Sample_Email', "    const CATEGORY = self::NOTIFICATION;\n");

        try {
            $exception = static::__assert_throws(
                \RuntimeException::class,
                fn () => static::__process(
                    static::__manifest('Fixture_No_Sample_Email', $file, true, false, 'Rsx_Email_Abstract', [])
                ),
                'has no sample()'
            );

            static::__assert_contains(
                'public static function sample()',
                $exception->getMessage(),
                'the message shows the method to add'
            );
        } finally {
            static::__cleanup();
        }
    }

    public static function test_a_missing_blade_is_a_fatal_that_names_the_required_rsx_id()
    {
        $file = static::__write_class('Fixture_No_View_Email', "    const CATEGORY = self::MARKETING;\n");

        try {
            $exception = static::__assert_throws(
                \RuntimeException::class,
                fn () => static::__process(static::__manifest('Fixture_No_View_Email', $file, false)),
                'has no template'
            );

            static::__assert_contains(
                "@rsx_id('Fixture_No_View_Email')",
                $exception->getMessage(),
                'the message names the exact id the blade must declare'
            );
        } finally {
            static::__cleanup();
        }
    }

    /**
     * One basename names exactly ONE email: it is the blade id and the value stored in
     * email_queue.email_class, so two classes sharing it would make a queued row
     * ambiguous about which message it is.
     */
    public static function test_two_classes_with_the_same_basename_are_a_fatal()
    {
        $first = static::__write_class('Fixture_Duplicate_Email', "    const CATEGORY = self::TRANSACTIONAL;\n");
        $second = static::__write_class('Fixture_Duplicate_Email', "    const CATEGORY = self::TRANSACTIONAL;\n");

        try {
            $manifest = static::__manifest('Fixture_Duplicate_Email', $first);
            $manifest['data']['files'][$second] = $manifest['data']['files'][$first];

            static::__assert_throws(
                \RuntimeException::class,
                fn () => static::__process($manifest),
                'Duplicate email class'
            );
        } finally {
            static::__cleanup();
        }
    }

    // =========================================================================
    // THE REAL BUILD
    // =========================================================================

    /**
     * This install's own manifest carries the table, and the framework's smoke-test
     * email is in it. A pure-function test can only prove the module is right about an
     * array it was handed; this proves the module actually ran.
     */
    public static function test_the_built_manifest_carries_the_email_table()
    {
        $manifest = Manifest::get_full_manifest();
        $emails = $manifest['data']['emails'] ?? [];

        static::__assert_array_has_key(
            'Rsx_Mail_Test_Email',
            $emails,
            "the framework's own email is in the baked table"
        );
        static::__assert_equals(
            1,
            $emails['Rsx_Mail_Test_Email']['category'],
            'declared TRANSACTIONAL - an operator asked for it, and a blocklist row must not silence it'
        );
    }
}
