<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ClassOverride\Php;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\Manifest_Indexer;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * An application overrides a framework TEMPLATE or STYLESHEET the way it overrides a
 * class: by declaring the same name. The framework's file is archived as .upstream, and
 * comes back when the override goes away.
 *
 * A .jqhtml declares a component (<Define:Name>), a .scss the one class it is wrapped in;
 * neither is a `class`, which is the only thing the per-class pass looks at. Before this
 * pass an application template with a framework component's name stopped the build
 * ("Duplicate jqhtml component name"), and a stylesheet was compiled in beside the
 * framework's with the cascade deciding between them.
 *
 * What is pinned:
 *   - the match is by DECLARED NAME, never by file name or directory;
 *   - each file is its own override - a template override leaves the framework's class
 *     and stylesheet of that name alone, and the reverse;
 *   - the two guards the class pass has (a poisoned index, an override that is not on
 *     disk) hold here too;
 *   - an archived JS class, template or stylesheet is restored when no application file
 *     OF THE SAME KIND declares its name, and kept while one does;
 *   - an archived file is indexed under an extension and a key nothing reads as a live
 *     declaration.
 *
 * The passes read and write Manifest::$data directly, so every test swaps in a synthetic
 * file list and restores the real one in a finally. Pure filesystem + static state.
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
    private const APPLICATION_CLASS = self::APPLICATION_DIR . '/a_class.js';
    private const APPLICATION_STYLESHEET = self::APPLICATION_DIR . '/some_styles.scss';

    private const CONTENT = [
        'jqhtml' => "<Define:Override_Unit_Probe_Temp>\n    <div></div>\n</Define:Override_Unit_Probe_Temp>\n",
        'js' => "class Override_Unit_Probe_Temp extends Component {\n}\n",
        'scss' => ".Override_Unit_Probe_Temp {\n    display: block;\n}\n",
    ];

    private static function __write(string ...$files): void
    {
        foreach ($files as $file) {
            ensure_directory(dirname(base_path($file)));
            file_put_contents(base_path($file), self::CONTENT[pathinfo(preg_replace('/\.upstream$/', '', $file), PATHINFO_EXTENSION)]);
        }
    }

    private static function __clean(): void
    {
        foreach ([self::FRAMEWORK_DIR, self::APPLICATION_DIR] as $directory) {
            if (!is_dir(base_path($directory))) {
                continue;
            }

            foreach (scandir(base_path($directory)) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    unlink(base_path($directory . '/' . $entry));
                }
            }

            rmdir(base_path($directory));
        }
    }

    /** A manifest entry as the passes read it. */
    private static function __entry(string $file): array
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $key = ['jqhtml' => 'id', 'js' => 'class', 'scss' => 'scss_wrapper_class'][$extension];

        return [$file => ['file' => $file, 'extension' => $extension, $key => self::NAME]];
    }

    /** An archived file's manifest entry. */
    private static function __archived_entry(string $file): array
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        return [$file . '.upstream' => [
            'file' => $file . '.upstream',
            'extension' => $extension . '.upstream',
            'upstream_name' => self::NAME,
        ]];
    }

    /**
     * Run one pass against a synthetic file list, with the real manifest state saved and
     * restored around it. Returns whatever the pass wrote to the error log.
     */
    private static function __run(callable $pass, array $files, bool $manifest_is_bad = false): string
    {
        $saved_data = Manifest::$data;
        $saved_restart = Manifest::$_needs_manifest_restart;
        $saved_is_bad = Manifest::$_manifest_is_bad;
        $saved_error_log = ini_get('error_log');

        $log_path = Rsx_Project_Paths::tmp_path('override_unit_log_' . uniqid() . '.txt');

        Manifest::$data = ['data' => ['files' => $files]];
        Manifest::$_needs_manifest_restart = false;
        Manifest::$_manifest_is_bad = $manifest_is_bad;
        ini_set('error_log', $log_path);

        try {
            $pass();
        } finally {
            ini_set('error_log', $saved_error_log === false ? '' : $saved_error_log);
            Manifest::$data = $saved_data;
            Manifest::$_needs_manifest_restart = $saved_restart;
            Manifest::$_manifest_is_bad = $saved_is_bad;
        }

        $log = file_exists($log_path) ? file_get_contents($log_path) : '';
        @unlink($log_path);

        return $log;
    }

    private static function __override_pass(array $files, bool $manifest_is_bad = false): string
    {
        return self::__run(fn () => Manifest_Indexer::_override_framework_templates_and_stylesheets(), $files, $manifest_is_bad);
    }

    private static function __restore_pass(array $files): string
    {
        return self::__run(fn () => Manifest::_restore_orphaned_upstream_files(), $files);
    }

    private static function __is_active(string $file): bool
    {
        return file_exists(base_path($file));
    }

    private static function __is_archived(string $file): bool
    {
        return file_exists(base_path($file) . '.upstream');
    }

    // =====================================================================
    // Archiving
    // =====================================================================

    public static function test_an_application_template_archives_the_framework_template_only()
    {
        self::__write(self::FRAMEWORK_TEMPLATE, self::FRAMEWORK_CLASS, self::FRAMEWORK_STYLESHEET, self::APPLICATION_TEMPLATE);

        try {
            $log = self::__override_pass(array_merge(
                self::__entry(self::FRAMEWORK_TEMPLATE),
                self::__entry(self::FRAMEWORK_CLASS),
                self::__entry(self::FRAMEWORK_STYLESHEET),
                self::__entry(self::APPLICATION_TEMPLATE)
            ));

            static::__assert_true(self::__is_archived(self::FRAMEWORK_TEMPLATE), 'the framework template is archived');
            static::__assert_false(self::__is_active(self::FRAMEWORK_TEMPLATE), 'and no longer active');
            static::__assert_true(self::__is_active(self::FRAMEWORK_CLASS), 'the framework class of that name is untouched');
            static::__assert_true(self::__is_active(self::FRAMEWORK_STYLESHEET), 'and so is its stylesheet');
            static::__assert_contains(self::FRAMEWORK_TEMPLATE, $log, 'the notice names the archived file');
            static::__assert_contains(self::APPLICATION_TEMPLATE, $log, 'and the override that caused it');
        } finally {
            self::__clean();
        }
    }

    /**
     * A stylesheet has no name of its own - it is identified by the class it is wrapped
     * in. The application's file here shares neither a name nor a directory with the
     * framework's.
     */
    public static function test_an_application_stylesheet_archives_the_framework_stylesheet_by_wrapper_class()
    {
        self::__write(self::FRAMEWORK_TEMPLATE, self::FRAMEWORK_STYLESHEET, self::APPLICATION_STYLESHEET);

        try {
            self::__override_pass(array_merge(
                self::__entry(self::FRAMEWORK_TEMPLATE),
                self::__entry(self::FRAMEWORK_STYLESHEET),
                self::__entry(self::APPLICATION_STYLESHEET)
            ));

            static::__assert_true(self::__is_archived(self::FRAMEWORK_STYLESHEET), 'the framework stylesheet is archived');
            static::__assert_true(self::__is_active(self::FRAMEWORK_TEMPLATE), 'the framework template of that name is untouched');
        } finally {
            self::__clean();
        }
    }

    public static function test_nothing_is_archived_when_the_application_declares_no_such_name()
    {
        self::__write(self::FRAMEWORK_TEMPLATE, self::FRAMEWORK_STYLESHEET);

        try {
            self::__override_pass(array_merge(
                self::__entry(self::FRAMEWORK_TEMPLATE),
                self::__entry(self::FRAMEWORK_STYLESHEET)
            ));

            static::__assert_true(self::__is_active(self::FRAMEWORK_TEMPLATE));
            static::__assert_true(self::__is_active(self::FRAMEWORK_STYLESHEET));
        } finally {
            self::__clean();
        }
    }

    public static function test_an_override_that_is_not_on_disk_archives_nothing()
    {
        self::__write(self::FRAMEWORK_TEMPLATE);

        try {
            $log = self::__override_pass(array_merge(
                self::__entry(self::FRAMEWORK_TEMPLATE),
                self::__entry(self::APPLICATION_TEMPLATE)
            ));

            static::__assert_true(self::__is_active(self::FRAMEWORK_TEMPLATE), 'the framework template is still there');
            static::__assert_false(self::__is_archived(self::FRAMEWORK_TEMPLATE));
            static::__assert_contains('stale index entry', $log);
        } finally {
            self::__clean();
        }
    }

    public static function test_a_poisoned_build_archives_nothing()
    {
        self::__write(self::FRAMEWORK_TEMPLATE, self::APPLICATION_TEMPLATE);

        try {
            $log = self::__override_pass(array_merge(
                self::__entry(self::FRAMEWORK_TEMPLATE),
                self::__entry(self::APPLICATION_TEMPLATE)
            ), true);

            static::__assert_true(self::__is_active(self::FRAMEWORK_TEMPLATE));
            static::__assert_false(self::__is_archived(self::FRAMEWORK_TEMPLATE));
            static::__assert_contains('NOT archiving', $log);
        } finally {
            self::__clean();
        }
    }

    // =====================================================================
    // Restoring
    // =====================================================================

    public static function test_archived_files_come_back_when_nothing_overrides_them()
    {
        self::__write(self::FRAMEWORK_TEMPLATE . '.upstream', self::FRAMEWORK_CLASS . '.upstream', self::FRAMEWORK_STYLESHEET . '.upstream');

        try {
            self::__restore_pass(array_merge(
                self::__archived_entry(self::FRAMEWORK_TEMPLATE),
                self::__archived_entry(self::FRAMEWORK_CLASS),
                self::__archived_entry(self::FRAMEWORK_STYLESHEET)
            ));

            foreach ([self::FRAMEWORK_TEMPLATE, self::FRAMEWORK_CLASS, self::FRAMEWORK_STYLESHEET] as $file) {
                static::__assert_true(self::__is_active($file), $file . ' is restored');
                static::__assert_false(self::__is_archived($file), $file . ' is no longer archived');
            }
        } finally {
            self::__clean();
        }
    }

    /**
     * Each kind answers for itself: an application TEMPLATE keeps the framework's template
     * archived, and releases nothing else of that name.
     */
    public static function test_an_archive_is_kept_only_by_an_override_of_its_own_kind()
    {
        self::__write(
            self::FRAMEWORK_TEMPLATE . '.upstream',
            self::FRAMEWORK_CLASS . '.upstream',
            self::FRAMEWORK_STYLESHEET . '.upstream',
            self::APPLICATION_TEMPLATE
        );

        try {
            self::__restore_pass(array_merge(
                self::__archived_entry(self::FRAMEWORK_TEMPLATE),
                self::__archived_entry(self::FRAMEWORK_CLASS),
                self::__archived_entry(self::FRAMEWORK_STYLESHEET),
                self::__entry(self::APPLICATION_TEMPLATE)
            ));

            static::__assert_true(self::__is_archived(self::FRAMEWORK_TEMPLATE), 'the template stays archived: it is still overridden');
            static::__assert_true(self::__is_active(self::FRAMEWORK_CLASS), 'the class is restored: no application class of that name');
            static::__assert_true(self::__is_active(self::FRAMEWORK_STYLESHEET), 'the stylesheet is restored: no application stylesheet of that name');
        } finally {
            self::__clean();
        }
    }

    // =====================================================================
    // What an archived file looks like in the index
    // =====================================================================

    /**
     * An archived file is read for the one name it declares, and recorded under a key and
     * an extension that nothing treats as a live declaration - or it would be counted as
     * the duplicate it was archived to stop being.
     */
    public static function test_an_archived_file_is_indexed_by_name_and_declares_nothing()
    {
        self::__write(self::FRAMEWORK_TEMPLATE . '.upstream', self::FRAMEWORK_CLASS . '.upstream', self::FRAMEWORK_STYLESHEET . '.upstream');

        try {
            foreach ([
                self::FRAMEWORK_TEMPLATE => 'jqhtml.upstream',
                self::FRAMEWORK_CLASS => 'js.upstream',
                self::FRAMEWORK_STYLESHEET => 'scss.upstream',
            ] as $file => $extension) {
                $entry = Manifest::_process_file($file . '.upstream');

                static::__assert_equals($extension, $entry['extension'], $file);
                static::__assert_equals(self::NAME, $entry['upstream_name'] ?? null, $file . ' names what it declared');
                static::__assert_false(isset($entry['class']) || isset($entry['id']) || isset($entry['scss_wrapper_class']), $file . ' declares nothing live');
            }
        } finally {
            self::__clean();
        }
    }
}
