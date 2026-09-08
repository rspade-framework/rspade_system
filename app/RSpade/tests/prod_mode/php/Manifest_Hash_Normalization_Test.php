<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Manifest\_Manifest_Cache_Helper;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Determinism units for the manifest build key (_Manifest_Cache_Helper::_compute_hash).
 *
 * The build key must be identical for two byte-identical checkouts at different absolute
 * paths. A file contributes its PATH and its sha1 and NOTHING ELSE, so mtime, size and any
 * absolute path embedded in a reflected method record cannot reach the key by construction -
 * where the predecessor had to strip them out of a deep copy of the whole manifest body.
 * The per-file lines are sorted, so readdir order cannot reach it either.
 *
 * These build a small fake manifest body and drive the hash directly. Pure logic, no DB.
 */
class Manifest_Hash_Normalization_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * A minimal but representative manifest body: two file entries carrying
     * mtime/size/sha1 plus class metadata, and a class map. The 'file' entries
     * under public_static_methods deliberately embed an absolute path (as PHP
     * reflection does for inherited trait methods) to exercise absolute-path
     * stripping.
     */
    private static function _fixture(): array
    {
        return [
            'files' => [
                'rsx/models/alpha_model.php' => [
                    'file' => 'rsx/models/alpha_model.php',
                    'hash' => 'aaaa1111',
                    'mtime' => 1000,
                    'size' => 200,
                    'extension' => 'php',
                    'class' => 'Alpha_Model',
                    'public_static_methods' => [
                        'fetch' => [
                            'file' => base_path() . '/vendor/some/trait/SoftDeletes.php',
                            'line' => 46,
                        ],
                    ],
                ],
                'rsx/models/beta_model.php' => [
                    'file' => 'rsx/models/beta_model.php',
                    'hash' => 'bbbb2222',
                    'mtime' => 2000,
                    'size' => 300,
                    'extension' => 'php',
                    'class' => 'Beta_Model',
                ],
            ],
            'php_classes' => [
                'Alpha_Model' => ['file' => 'rsx/models/alpha_model.php'],
                'Beta_Model' => ['file' => 'rsx/models/beta_model.php'],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // mtime/size are excluded from the hash
    // -------------------------------------------------------------------------

    public static function test_hash_unchanged_when_mtime_mutates()
    {
        $a = self::_fixture();
        $b = self::_fixture();
        $b['files']['rsx/models/alpha_model.php']['mtime'] = 999999;
        $b['files']['rsx/models/beta_model.php']['mtime'] = 888888;

        static::__assert_equals(
            _Manifest_Cache_Helper::_compute_hash($a),
            _Manifest_Cache_Helper::_compute_hash($b),
            'mtime mutation must not change the build key'
        );
    }

    public static function test_hash_unchanged_when_size_mutates()
    {
        $a = self::_fixture();
        $b = self::_fixture();
        $b['files']['rsx/models/alpha_model.php']['size'] = 424242;

        static::__assert_equals(
            _Manifest_Cache_Helper::_compute_hash($a),
            _Manifest_Cache_Helper::_compute_hash($b),
            'size mutation must not change the build key'
        );
    }

    // -------------------------------------------------------------------------
    // sha1 (semantic content) DOES change the hash
    // -------------------------------------------------------------------------

    public static function test_hash_changes_when_file_sha_changes()
    {
        $a = self::_fixture();
        $b = self::_fixture();
        $b['files']['rsx/models/alpha_model.php']['hash'] = 'cccc3333';

        static::__assert_not_equals(
            _Manifest_Cache_Helper::_compute_hash($a),
            _Manifest_Cache_Helper::_compute_hash($b),
            'a per-file sha1 change must change the build key'
        );
    }

    // -------------------------------------------------------------------------
    // Absolute paths inside a file record cannot reach the key
    // -------------------------------------------------------------------------

    public static function test_absolute_paths_in_a_file_record_do_not_reach_the_key()
    {
        $a = self::_fixture();
        $b = self::_fixture();

        // Reflection reports an ABSOLUTE path for an inherited trait method, and that path
        // differs between checkouts. A file contributes only its own sha1 now, so the value
        // is structurally unable to move the key.
        $b['files']['rsx/models/alpha_model.php']['public_static_methods']['fetch']['file']
            = '/somewhere/else/entirely/vendor/some/trait/SoftDeletes.php';

        static::__assert_equals(
            _Manifest_Cache_Helper::_compute_hash($a),
            _Manifest_Cache_Helper::_compute_hash($b),
            'an absolute path inside a file record must not change the build key'
        );
    }

    public static function test_absolute_paths_in_a_derived_section_are_relativized()
    {
        $a = self::_fixture();
        $b = self::_fixture();

        // A DERIVED section does reach the key - reduced to project-relative form first, so
        // the same checkout at a different absolute path still keys the same.
        $a['models'] = ['Alpha_Model' => ['file' => base_path() . '/rsx/models/alpha_model.php']];
        $b['models'] = ['Alpha_Model' => ['file' => 'rsx/models/alpha_model.php']];

        static::__assert_equals(
            _Manifest_Cache_Helper::_compute_hash($a),
            _Manifest_Cache_Helper::_compute_hash($b),
            'an absolute base_path() prefix in a derived section is reduced before hashing'
        );
    }

    public static function test_hash_does_not_mutate_input()
    {
        $body = self::_fixture();
        _Manifest_Cache_Helper::_compute_hash($body);

        // The live manifest body must keep mtime/size (dev change-detection needs them).
        static::__assert_equals(1000, $body['files']['rsx/models/alpha_model.php']['mtime'], 'input must not be mutated');
        static::__assert_equals(200, $body['files']['rsx/models/alpha_model.php']['size'], 'input must not be mutated');
    }

    public static function test_a_derived_section_change_moves_the_key()
    {
        $a = self::_fixture();
        $b = self::_fixture();
        $b['php_classes']['Gamma_Model'] = ['file' => 'rsx/models/gamma_model.php'];

        static::__assert_not_equals(
            _Manifest_Cache_Helper::_compute_hash($a),
            _Manifest_Cache_Helper::_compute_hash($b),
            'a derived-section change must change the build key'
        );
    }

    // -------------------------------------------------------------------------
    // Key order is stable (defensive ksort inside normalization)
    // -------------------------------------------------------------------------

    public static function test_hash_stable_regardless_of_file_key_order()
    {
        $a = self::_fixture();

        // Same logical content, files inserted in the opposite order.
        $b = self::_fixture();
        $b['files'] = array_reverse($b['files'], true);

        static::__assert_equals(
            _Manifest_Cache_Helper::_compute_hash($a),
            _Manifest_Cache_Helper::_compute_hash($b),
            'file insertion order must not affect the build key'
        );
    }
}
