<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Manifest\_Manifest_Cache_Helper;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Determinism units for the manifest build-key normalization
 * (_Manifest_Cache_Helper::_normalize_for_hash / _compute_hash).
 *
 * The build key must be identical for two byte-identical checkouts at different
 * absolute paths. That means the hashed projection of the manifest body must be
 * blind to local disk state (per-file mtime/size) and to absolute-path prefixes
 * embedded in reflected metadata, while still reacting to real semantic changes
 * (a file's sha1 moving).
 *
 * These build a small fake manifest body and drive the normalization directly.
 * Pure logic, no DB.
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
                'Alpha_Model' => 'rsx/models/alpha_model.php',
                'Beta_Model' => 'rsx/models/beta_model.php',
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
    // Absolute-path prefixes are stripped -> checkout independence
    // -------------------------------------------------------------------------

    public static function test_normalization_strips_absolute_paths()
    {
        $normalized = _Manifest_Cache_Helper::_normalize_for_hash(self::_fixture());
        $method_file = $normalized['files']['rsx/models/alpha_model.php']['public_static_methods']['fetch']['file'];

        static::__assert_equals(
            'vendor/some/trait/SoftDeletes.php',
            $method_file,
            'absolute base_path() prefix must be stripped from embedded metadata paths'
        );
    }

    public static function test_normalization_removes_mtime_and_size()
    {
        $normalized = _Manifest_Cache_Helper::_normalize_for_hash(self::_fixture());
        $entry = $normalized['files']['rsx/models/alpha_model.php'];

        static::__assert_false(isset($entry['mtime']), 'mtime must be absent from the normalized projection');
        static::__assert_false(isset($entry['size']), 'size must be absent from the normalized projection');
        static::__assert_true(isset($entry['hash']), 'sha1 must be retained in the normalized projection');
        static::__assert_equals('Alpha_Model', $entry['class'], 'semantic metadata must be retained');
    }

    public static function test_normalization_does_not_mutate_input()
    {
        $body = self::_fixture();
        _Manifest_Cache_Helper::_normalize_for_hash($body);

        // The live manifest body must keep mtime/size (dev change-detection needs them).
        static::__assert_equals(1000, $body['files']['rsx/models/alpha_model.php']['mtime'], 'input must not be mutated');
        static::__assert_equals(200, $body['files']['rsx/models/alpha_model.php']['size'], 'input must not be mutated');
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
