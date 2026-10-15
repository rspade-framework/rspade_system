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
 * Only PHP classes are overridable. An application TEMPLATE (<Define:Name>) or JS CLASS with
 * the name of a framework one is REFUSED by the override pass, naming both files - it is
 * neither shadowed nor ignored, because either would leave two definitions of one name in a
 * bundle.
 *
 * A STYLESHEET is never an override: an application stylesheet wrapped in the class of a
 * framework component adds to the framework's stylesheet and never shadows it, and two
 * application stylesheets wrapped in one class are not a conflict.
 *
 * What is pinned:
 *   - the match is by DECLARED NAME, never by file name or directory;
 *   - a template namesake and a JS class namesake each throw, naming both files, and the
 *     refusal holds in a build that has already marked its manifest bad;
 *   - an application entry the disk no longer has is stale, collides with nothing and is
 *     dropped;
 *   - no stylesheet is ever shadowed, and no stylesheet pair is ever refused.
 *
 * Manifest_Overrides::derive() reads and writes Manifest::$data directly, so every test swaps
 * in a synthetic file list and restores the real one in a finally. Only the APPLICATION files
 * are written to disk (the derivation asks the disk before it believes the file list), under
 * rsx/resource/, which the manifest never indexes. Pure filesystem + static state.
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
    private const APPLICATION_CLASS = self::APPLICATION_DIR . '/a_script.js';
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
    // Templates and JS classes: refused
    // =====================================================================

    /**
     * An application template with the name of a framework template is refused, naming both
     * files and saying templates are not overridable.
     */
    public static function test_an_application_template_named_like_a_framework_one_is_refused()
    {
        self::__write(self::APPLICATION_TEMPLATE);

        try {
            $error = static::__assert_throws(
                \RuntimeException::class,
                static fn () => self::__derive(array_merge(
                    self::__entry(self::FRAMEWORK_TEMPLATE),
                    self::__entry(self::FRAMEWORK_CLASS),
                    self::__entry(self::FRAMEWORK_STYLESHEET),
                    self::__entry(self::APPLICATION_TEMPLATE)
                )),
                "Fatal: jqhtml component '" . self::NAME . "' is not overridable."
            );

            static::__assert_contains(self::APPLICATION_TEMPLATE, $error->getMessage(), 'the refusal names the application template');
            static::__assert_contains(self::FRAMEWORK_TEMPLATE, $error->getMessage(), 'and the framework template');
            static::__assert_contains('jqhtml components are', $error->getMessage(), 'and says components are not overridable');
            static::__assert_contains('rsx:man class_override', $error->getMessage(), 'and where the contract is');
        } finally {
            self::__clean();
        }
    }

    /**
     * An application JS class with the name of a framework JS class is refused the same way.
     */
    public static function test_an_application_js_class_named_like_a_framework_one_is_refused()
    {
        self::__write(self::APPLICATION_CLASS);

        try {
            $error = static::__assert_throws(
                \RuntimeException::class,
                static fn () => self::__derive(array_merge(
                    self::__entry(self::FRAMEWORK_CLASS),
                    self::__entry(self::APPLICATION_CLASS)
                )),
                "Fatal: JavaScript class '" . self::NAME . "' is not overridable."
            );

            static::__assert_contains(self::APPLICATION_CLASS, $error->getMessage(), 'the refusal names the application class');
            static::__assert_contains(self::FRAMEWORK_CLASS, $error->getMessage(), 'and the framework class');
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

    public static function test_a_template_entry_that_is_not_on_disk_collides_with_nothing()
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

    /**
     * A build that has already marked its manifest bad still refuses a template namesake that
     * is on disk: the refusal shadows nothing, so the disk check is all it needs.
     */
    public static function test_a_poisoned_build_still_refuses_a_template_namesake()
    {
        self::__write(self::APPLICATION_TEMPLATE);

        try {
            static::__assert_throws(
                \RuntimeException::class,
                static fn () => self::__derive(array_merge(
                    self::__entry(self::FRAMEWORK_TEMPLATE),
                    self::__entry(self::APPLICATION_TEMPLATE)
                ), true),
                'is not overridable'
            );
        } finally {
            self::__clean();
        }
    }

    // =====================================================================
    // Two application stylesheets of one wrapper class
    // =====================================================================

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
