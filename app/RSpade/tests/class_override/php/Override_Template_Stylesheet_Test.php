<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ClassOverride\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\Manifest_Overrides;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * An application overrides a framework TEMPLATE the way it overrides a class: by declaring
 * the same component name (<Define:Name>). The framework's file is SHADOWED - left in place,
 * out of the live index - and is live again the moment the override goes away (the map is
 * derived in full on every build, so there is nothing to undo).
 *
 * A STYLESHEET is never an override: an application stylesheet wrapped in the class of a
 * framework component adds to the framework's stylesheet and never shadows it, and two
 * application stylesheets wrapped in one class are not a conflict.
 *
 * What is pinned:
 *   - the match is by DECLARED NAME, never by file name or directory;
 *   - each file is its own override - a template override leaves the framework's class and
 *     stylesheet of that name live;
 *   - no stylesheet is ever shadowed, and no stylesheet pair is ever refused;
 *   - the two guards the class kind has (a poisoned index, an override that is not on disk)
 *     hold here too.
 *
 * Manifest_Overrides::derive() reads and writes Manifest::$data directly, so every test swaps
 * in a synthetic file list and restores the real one in a finally. Only the APPLICATION files
 * are written to disk (the derivation checks that an override exists), under rsx/resource/,
 * which the manifest never indexes. Pure filesystem + static state.
 */
class Override_Template_Stylesheet_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const NAME = 'Override_Unit_Probe_Temp';

    private const FRAMEWORK_DIR = 'app/RSpade/temp/override_unit_probe';
    private const APPLICATION_DIR = 'rsx/resource/override_unit_probe';

    private const FRAMEWORK_TEMPLATE = self::FRAMEWORK_DIR . '/Override_Unit_Probe_Temp.jqhtml';
    private const FRAMEWORK_CLASS = self::FRAMEWORK_DIR . '/Override_Unit_Probe_Temp.js';
    private const FRAMEWORK_STYLESHEET = self::FRAMEWORK_DIR . '/Override_Unit_Probe_Temp.scss';

    // Deliberately named nothing like the framework's files.
    private const APPLICATION_TEMPLATE = self::APPLICATION_DIR . '/a_template.jqhtml';
    private const APPLICATION_STYLESHEET = self::APPLICATION_DIR . '/some_styles.scss';

    private static function __write(string ...$files): void
    {
        foreach ($files as $file) {
            ensure_directory(dirname(base_path($file)));
            file_put_contents(base_path($file), "/* " . self::NAME . " */\n");
        }
    }

    private static function __clean(): void
    {
        if (is_dir(base_path(self::APPLICATION_DIR))) {
            rmdir_recursive(base_path(self::APPLICATION_DIR));
        }
    }

    /** A manifest entry as the derivation reads it. */
    private static function __entry(string $file): array
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $key = ['jqhtml' => 'id', 'js' => 'class', 'scss' => 'scss_wrapper_class'][$extension];

        return [$file => ['extension' => $extension, $key => self::NAME, 'hash' => sha1($file)]];
    }

    /**
     * Derive over a synthetic file list, with the real manifest state saved and restored
     * around it.
     *
     * @return array{log: string, files: array, overrides: array}
     */
    private static function __derive(array $files, bool $manifest_is_bad = false): array
    {
        $saved_data = Manifest::$data;
        $saved_is_bad = Manifest::$_manifest_is_bad;
        $saved_error_log = ini_get('error_log');

        $log_path = Rsx_Project_Paths::tmp_path('override_unit_log_' . uniqid() . '.txt');

        Manifest::$data = ['data' => ['files' => $files]];
        Manifest::$_manifest_is_bad = $manifest_is_bad;
        ini_set('error_log', $log_path);

        try {
            Manifest_Overrides::derive([], []);
            $outcome = ['files' => Manifest::$data['data']['files'], 'overrides' => Manifest::$data['data']['overrides']];
        } finally {
            ini_set('error_log', $saved_error_log === false ? '' : $saved_error_log);
            Manifest::$data = $saved_data;
            Manifest::$_manifest_is_bad = $saved_is_bad;
        }

        $outcome['log'] = file_exists($log_path) ? file_get_contents($log_path) : '';
        @unlink($log_path);

        return $outcome;
    }

    // =====================================================================
    // Shadowing
    // =====================================================================

    public static function test_an_application_template_shadows_the_framework_template_only()
    {
        self::__write(self::APPLICATION_TEMPLATE);

        try {
            $outcome = self::__derive(array_merge(
                self::__entry(self::FRAMEWORK_TEMPLATE),
                self::__entry(self::FRAMEWORK_CLASS),
                self::__entry(self::FRAMEWORK_STYLESHEET),
                self::__entry(self::APPLICATION_TEMPLATE)
            ));

            static::__assert_equals(
                [self::FRAMEWORK_TEMPLATE => ['kind' => 'jqhtml', 'name' => self::NAME, 'by' => self::APPLICATION_TEMPLATE]],
                $outcome['overrides'],
                'the framework template, and only it, is shadowed by the application template'
            );
            static::__assert_false(isset($outcome['files'][self::FRAMEWORK_TEMPLATE]), 'it is out of the live index');
            static::__assert_true(isset($outcome['files'][self::FRAMEWORK_CLASS]), 'the framework class of that name stays live');
            static::__assert_true(isset($outcome['files'][self::FRAMEWORK_STYLESHEET]), 'and so does its stylesheet');
            static::__assert_contains(self::FRAMEWORK_TEMPLATE, $outcome['log'], 'the notice names the shadowed file');
            static::__assert_contains(self::APPLICATION_TEMPLATE, $outcome['log'], 'and the override that shadows it');
        } finally {
            self::__clean();
        }
    }

    /**
     * An application stylesheet wrapped in the class of a framework component styles that
     * component ON TOP of the framework's own stylesheet: both stay in the live index.
     */
    public static function test_an_application_stylesheet_never_shadows_the_framework_stylesheet()
    {
        self::__write(self::APPLICATION_STYLESHEET);

        try {
            $outcome = self::__derive(array_merge(
                self::__entry(self::FRAMEWORK_TEMPLATE),
                self::__entry(self::FRAMEWORK_STYLESHEET),
                self::__entry(self::APPLICATION_STYLESHEET)
            ));

            static::__assert_equals([], $outcome['overrides'], 'nothing is shadowed');
            static::__assert_true(isset($outcome['files'][self::FRAMEWORK_STYLESHEET]), 'the framework stylesheet stays live');
            static::__assert_true(isset($outcome['files'][self::APPLICATION_STYLESHEET]), 'beside the application stylesheet');
            static::__assert_null(Manifest_Overrides::kind_of(['extension' => 'scss']), 'a stylesheet has no override kind');
            static::__assert_false(isset(Manifest_Overrides::NAME_KEYS['scss']), 'and no declared-name key');
        } finally {
            self::__clean();
        }
    }

    public static function test_nothing_is_shadowed_when_the_application_declares_no_such_name()
    {
        $outcome = self::__derive(array_merge(
            self::__entry(self::FRAMEWORK_TEMPLATE),
            self::__entry(self::FRAMEWORK_STYLESHEET)
        ));

        static::__assert_equals([], $outcome['overrides']);
        static::__assert_true(isset($outcome['files'][self::FRAMEWORK_TEMPLATE]));
        static::__assert_true(isset($outcome['files'][self::FRAMEWORK_STYLESHEET]));
    }

    public static function test_an_override_that_is_not_on_disk_shadows_nothing()
    {
        self::__clean();

        $outcome = self::__derive(array_merge(
            self::__entry(self::FRAMEWORK_TEMPLATE),
            self::__entry(self::APPLICATION_TEMPLATE)
        ));

        static::__assert_equals([], $outcome['overrides'], 'nothing is shadowed');
        static::__assert_true(isset($outcome['files'][self::FRAMEWORK_TEMPLATE]), 'the framework template stays live');
        static::__assert_false(isset($outcome['files'][self::APPLICATION_TEMPLATE]), 'the stale entry is dropped');
        static::__assert_contains('stale index entry', $outcome['log']);
    }

    public static function test_a_poisoned_build_shadows_nothing()
    {
        self::__write(self::APPLICATION_TEMPLATE);

        try {
            $outcome = self::__derive(array_merge(
                self::__entry(self::FRAMEWORK_TEMPLATE),
                self::__entry(self::APPLICATION_TEMPLATE)
            ), true);

            static::__assert_equals([], $outcome['overrides']);
            static::__assert_true(isset($outcome['files'][self::FRAMEWORK_TEMPLATE]));
            static::__assert_contains('NOT shadowing', $outcome['log']);
        } finally {
            self::__clean();
        }
    }

    // =====================================================================
    // Two application declarations of one name
    // =====================================================================

    /**
     * Two application templates of one component name are refused - nothing can choose
     * between them.
     */
    public static function test_two_application_templates_of_one_name_are_refused()
    {
        $error = static::__assert_throws(
            \RuntimeException::class,
            static fn () => self::__derive(array_merge(
                self::__entry(self::APPLICATION_DIR . '/a_template.jqhtml'),
                self::__entry(self::APPLICATION_DIR . '/b_template.jqhtml')
            )),
            "Duplicate jqhtml component '" . self::NAME . "'"
        );

        static::__assert_contains(self::APPLICATION_DIR . '/b_template.jqhtml', $error->getMessage(), 'the refusal names both files');
    }

    /**
     * Two application stylesheets wrapped in one class - each styling the same framework
     * component, in different bundles or in one - are not a conflict: there is nothing to
     * choose between, because neither replaces anything.
     */
    public static function test_two_application_stylesheets_of_one_wrapper_class_are_not_refused()
    {
        $first = self::APPLICATION_DIR . '/a_styles.scss';
        $second = self::APPLICATION_DIR . '/b_styles.scss';

        self::__write($first, $second);

        try {
            $outcome = self::__derive(array_merge(
                self::__entry(self::FRAMEWORK_STYLESHEET),
                self::__entry($first),
                self::__entry($second)
            ));

            static::__assert_equals([], $outcome['overrides'], 'nothing is shadowed and nothing is refused');
            foreach ([self::FRAMEWORK_STYLESHEET, $first, $second] as $file) {
                static::__assert_true(isset($outcome['files'][$file]), "{$file} stays live");
            }
        } finally {
            self::__clean();
        }
    }
}
