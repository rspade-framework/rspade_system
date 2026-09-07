<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\Externals\Php;

use RuntimeException;
use App\RSpade\Core\Externals\Externals_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Externals_ManifestSupport is the ONLY gate on what a `*.externals.php` file may say.
 * A malformed declaration must break the manifest build rather than surface later as a
 * missing script tag or a silently-widened CSP, so every rule is pinned here: the spec
 * key whitelist, the URL shape, the realm vocabulary, the readiness forms, and the
 * flat-namespace collision.
 *
 * Fixtures are written under storage/rsx-tmp (never a scanned directory), handed to
 * process() as synthetic manifest file entries, and deleted in a finally.
 *
 * Pure logic, no DB.
 */
class Externals_Declaration_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** Fixture counter - a distinct path per fixture keeps the reader's mtime cache honest. */
    private static int $_fixture_seq = 0;

    private const FIXTURE_DIR = 'rsx-tmp/externals_test';

    /**
     * Write fixture declaration files, run the support module over them, and return the
     * consolidated table. Files are removed regardless of outcome.
     *
     * @param array $files List of declaration arrays, one per fixture file (processed in order).
     */
    private static function _build(array $files): array
    {
        $directory = storage_path(self::FIXTURE_DIR);
        ensure_directory($directory);

        $relative_paths = [];

        try {
            foreach ($files as $declarations) {
                $name = sprintf('fixture_%03d', ++self::$_fixture_seq);
                file_put_contents(
                    $directory . '/' . $name . '.externals.php',
                    "<?php\n\nreturn " . var_export($declarations, true) . ";\n"
                );
                $relative_paths[] = '../storage/' . self::FIXTURE_DIR . '/' . $name . '.externals.php';
            }

            $manifest_data = ['data' => ['files' => []]];
            foreach ($relative_paths as $relative_path) {
                $manifest_data['data']['files'][$relative_path] = ['extension' => 'externals.php'];
            }

            Externals_ManifestSupport::process($manifest_data);

            return $manifest_data['data']['external_resources'];
        } finally {
            foreach ($relative_paths as $relative_path) {
                $absolute_path = base_path($relative_path);
                if (file_exists($absolute_path)) {
                    unlink($absolute_path);
                }
            }
        }
    }

    /**
     * A minimal valid spec, so each failure case differs in exactly one key.
     */
    private static function _valid_spec(array $overrides = []): array
    {
        return array_merge(['js' => ['https://cdn.example.com/thing.js']], $overrides);
    }

    // -------------------------------------------------------------------------
    // Defaults
    // -------------------------------------------------------------------------

    public static function test_defaults_are_applied()
    {
        $entries = self::_build([['thing' => self::_valid_spec()]]);

        static::__assert_count(1, $entries, 'one entry consolidated');

        $entry = $entries['thing'];

        static::__assert_equals('thing', $entry['identifier']);
        static::__assert_equals(['https://cdn.example.com/thing.js'], $entry['js']);
        static::__assert_equals([], $entry['css'], 'css defaults to empty');
        static::__assert_equals([], $entry['integrity'], 'integrity defaults to empty');
        static::__assert_true($entry['mirror'], 'mirror defaults to true');
        static::__assert_equals('both', $entry['realm'], 'realm defaults to both');
        static::__assert_equals('onload', $entry['readiness'], 'readiness defaults to onload');
        static::__assert_equals([], $entry['csp'], 'csp defaults to empty');
        static::__assert_contains('.externals.php', $entry['file'], 'the source file is annotated');
    }

    public static function test_declared_values_survive_normalization()
    {
        $entries = self::_build([[
            'thing' => [
                'js' => ['https://cdn.example.com/thing.js'],
                'css' => ['https://cdn.example.com/thing.css'],
                'integrity' => ['https://cdn.example.com/thing.js' => 'sha384-abc'],
                'mirror' => false,
                'realm' => 'portal',
                'readiness' => ['callback_param' => 'onload'],
                'csp' => ['frame-src' => ['https://frames.example.com']],
            ],
        ]]);

        $entry = $entries['thing'];

        static::__assert_false($entry['mirror']);
        static::__assert_equals('portal', $entry['realm']);
        static::__assert_equals(['callback_param' => 'onload'], $entry['readiness']);
        static::__assert_equals(['https://cdn.example.com/thing.js' => 'sha384-abc'], $entry['integrity']);
        static::__assert_equals(['frame-src' => ['https://frames.example.com']], $entry['csp']);
    }

    // -------------------------------------------------------------------------
    // Shape validation
    // -------------------------------------------------------------------------

    public static function test_unknown_spec_key_is_an_error()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => self::_build([['thing' => self::_valid_spec(['defer' => true])]]),
            "Unknown key 'defer'"
        );
    }

    public static function test_a_declaration_with_neither_js_nor_css_is_an_error()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => self::_build([['thing' => ['mirror' => false]]]),
            'At least one of `js` or `css`'
        );
    }

    public static function test_a_non_https_url_is_an_error()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => self::_build([['thing' => ['js' => ['http://cdn.example.com/thing.js']]]]),
            'absolute https:// URLs'
        );
    }

    public static function test_a_bad_realm_is_an_error()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => self::_build([['thing' => self::_valid_spec(['realm' => 'admin'])]]),
            "Invalid `realm` 'admin'"
        );
    }

    public static function test_a_bad_readiness_is_an_error()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => self::_build([['thing' => self::_valid_spec(['readiness' => 'polling'])]]),
            'Invalid `readiness`'
        );
    }

    public static function test_an_integrity_hash_for_an_undeclared_url_is_an_error()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => self::_build([[
                'thing' => self::_valid_spec(['integrity' => ['https://other.example.com/x.js' => 'sha384-abc']]),
            ]]),
            'Unknown `integrity` URL'
        );
    }

    public static function test_a_bad_identifier_is_an_error()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => self::_build([['My-Thing' => self::_valid_spec()]]),
            'lowercase_with_underscores'
        );
    }

    public static function test_a_non_bool_mirror_is_an_error()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => self::_build([['thing' => self::_valid_spec(['mirror' => 'yes'])]]),
            'Invalid `mirror`'
        );
    }

    public static function test_an_empty_csp_source_list_is_an_error()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => self::_build([['thing' => self::_valid_spec(['csp' => ['frame-src' => []]])]]),
            'Invalid `csp` sources'
        );
    }

    // -------------------------------------------------------------------------
    // Flat namespace
    // -------------------------------------------------------------------------

    public static function test_an_identifier_declared_twice_names_both_files()
    {
        $exception = static::__assert_throws(
            RuntimeException::class,
            fn () => self::_build([
                ['thing' => self::_valid_spec()],
                ['thing' => self::_valid_spec()],
            ]),
            "Duplicate external resource identifier 'thing'"
        );

        $message = $exception->getMessage();

        static::__assert_contains('Already declared in:', $message);
        static::__assert_contains('Conflicting declaration:', $message);
        static::__assert_equals(
            2,
            substr_count($message, '.externals.php'),
            'both source files are named'
        );
    }

    public static function test_two_files_declaring_different_identifiers_consolidate()
    {
        $entries = self::_build([
            ['second' => self::_valid_spec()],
            ['first' => self::_valid_spec()],
        ]);

        static::__assert_equals(
            ['first', 'second'],
            array_keys($entries),
            'the consolidated table is ksorted regardless of file order'
        );
    }
}
