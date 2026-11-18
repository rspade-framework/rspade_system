<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\ProdMode\Php;

use App\RSpade\Core\Manifest\Manifest_Store;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Determinism units for the manifest build key (Manifest_Store::_compute_hash).
 *
 * The build key must be identical for two byte-identical checkouts at different absolute
 * paths. Every path the manifest stores is project-relative (Manifest_Location_Independence_Test
 * holds the cache files to that), and a file contributes its PATH and its sha1 and NOTHING
 * ELSE, so mtime and size cannot reach the key by construction. The per-file lines are
 * sorted, so readdir order cannot reach it either.
 *
 * These build a small fake manifest body and drive the hash directly. Pure logic, no DB.
 */
class Manifest_Hash_Normalization_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /**
     * A minimal but representative manifest body: two file entries carrying
     * mtime/size/sha1 plus class metadata (one with a trait-supplied method and
     * its trait's `file` key), and a class map.
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
                            'file' => 'vendor/some/trait/SoftDeletes.php',
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
            Manifest_Store::_compute_hash($a, Rsx::MODE_PRODUCTION, ''),
            Manifest_Store::_compute_hash($b, Rsx::MODE_PRODUCTION, ''),
            'mtime mutation must not change the build key'
        );
    }

    public static function test_hash_unchanged_when_size_mutates()
    {
        $a = self::_fixture();
        $b = self::_fixture();
        $b['files']['rsx/models/alpha_model.php']['size'] = 424242;

        static::__assert_equals(
            Manifest_Store::_compute_hash($a, Rsx::MODE_PRODUCTION, ''),
            Manifest_Store::_compute_hash($b, Rsx::MODE_PRODUCTION, ''),
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
            Manifest_Store::_compute_hash($a, Rsx::MODE_PRODUCTION, ''),
            Manifest_Store::_compute_hash($b, Rsx::MODE_PRODUCTION, ''),
            'a per-file sha1 change must change the build key'
        );
    }

    // -------------------------------------------------------------------------
    // A file record reaches the key only through its path and sha1
    // -------------------------------------------------------------------------

    public static function test_a_method_record_inside_a_file_record_does_not_reach_the_key()
    {
        $a = self::_fixture();
        $b = self::_fixture();

        // The method map is a function of the file's content, which its sha1 already carries.
        $b['files']['rsx/models/alpha_model.php']['public_static_methods']['fetch']['line'] = 99;

        static::__assert_equals(
            Manifest_Store::_compute_hash($a, Rsx::MODE_PRODUCTION, ''),
            Manifest_Store::_compute_hash($b, Rsx::MODE_PRODUCTION, ''),
            'a method record inside a file record must not change the build key'
        );
    }

    public static function test_hash_does_not_mutate_input()
    {
        $body = self::_fixture();
        Manifest_Store::_compute_hash($body, Rsx::MODE_PRODUCTION, '');

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
            Manifest_Store::_compute_hash($a, Rsx::MODE_PRODUCTION, ''),
            Manifest_Store::_compute_hash($b, Rsx::MODE_PRODUCTION, ''),
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
            Manifest_Store::_compute_hash($a, Rsx::MODE_PRODUCTION, ''),
            Manifest_Store::_compute_hash($b, Rsx::MODE_PRODUCTION, ''),
            'file insertion order must not affect the build key'
        );
    }
}
