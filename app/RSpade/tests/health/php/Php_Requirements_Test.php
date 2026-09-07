<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use RuntimeException;
use App\RSpade\Core\Health\Environment_Health_Checks;
use App\RSpade\Core\Health\Health_Check_Runner;
use App\RSpade\Core\Health\Rsx_Php_Requirements;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Php_Requirements: the ONE declared PHP-extension list, and its two consumers.
 *
 * The declaration is the point of the class, so the first test is the one that keeps
 * it honest - every hard `ext-*` require of an installed composer package must appear
 * in it. That set is DERIVED from vendor/composer/installed.json at test time rather
 * than pinned, so adding a package that requires an extension nobody declared fails
 * here instead of on somebody's box.
 *
 * Everything else drives the seams: missing_from()/enforce_list() take an arbitrary
 * list, and the container marker is a redirected path. No test touches
 * /.rspade_container, and no test asks what THIS box happens to have loaded.
 *
 * Pure logic, no DB.
 */
class Php_Requirements_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** A name no PHP extension will ever have. */
    private const FABRICATED = 'rsx_no_such_extension';

    // -------------------------------------------------------------------------
    // The declaration matches the evidence
    // -------------------------------------------------------------------------

    /**
     * Every ext-* a composer package hard-requires is declared.
     *
     * Subset, not equality: the list is also fed by direct call sites (gmp, zlib,
     * xmlreader, imagick, redis, posix), by the declared standard library (ldap, imap,
     * sodium) and by zstd, which is declared ahead of the revision-history feature that
     * will use it. Composer knows about none of those - it is a floor, not a ceiling.
     */
    public static function test_every_composer_ext_require_is_declared()
    {
        $required = self::_composer_ext_requires();

        static::__assert_true(
            count($required) > 0,
            'installed.json yielded at least one ext-* require (a zero here means the derivation broke, not that composer needs nothing)'
        );

        $undeclared = array_values(array_diff($required, Rsx_Php_Requirements::REQUIRED_EXTENSIONS));

        static::__assert_equals(
            [],
            $undeclared,
            'every ext-* a composer package requires appears in Rsx_Php_Requirements::REQUIRED_EXTENSIONS (undeclared: ' . implode(', ', $undeclared) . ')'
        );
    }

    /** The list is a clean, lower-case, duplicate-free set of names. */
    public static function test_the_declared_list_is_a_clean_set()
    {
        $list = Rsx_Php_Requirements::REQUIRED_EXTENSIONS;

        static::__assert_equals(
            count($list),
            count(array_unique($list)),
            'no extension is declared twice'
        );

        foreach ($list as $extension) {
            static::__assert_true(
                is_string($extension) && $extension !== '' && $extension === strtolower($extension),
                "'{$extension}' is a non-empty lower-case extension name"
            );
        }
    }

    /**
     * zstd is declared. The revision-history feature is built on it and the apt package
     * is in the framework image, so an environment without it must say so at boot.
     */
    public static function test_zstd_is_declared()
    {
        static::__assert_true(
            in_array('zstd', Rsx_Php_Requirements::REQUIRED_EXTENSIONS, true),
            'zstd is part of the declared runtime'
        );
    }

    // -------------------------------------------------------------------------
    // The CLI tier
    // -------------------------------------------------------------------------

    /**
     * The CLI tier is declared, holds pcntl, and overlaps the runtime tier nowhere.
     *
     * An extension in both lists would be enforced twice and would mean the tier split
     * had stopped saying anything.
     */
    public static function test_the_cli_tier_is_declared()
    {
        $cli = Rsx_Php_Requirements::REQUIRED_CLI_EXTENSIONS;

        static::__assert_true(
            in_array('pcntl', $cli, true),
            'pcntl is declared in the CLI tier - php-cli ships it, php-fpm does not'
        );

        static::__assert_equals(
            count($cli),
            count(array_unique($cli)),
            'no CLI extension is declared twice'
        );

        static::__assert_equals(
            [],
            array_values(array_intersect($cli, Rsx_Php_Requirements::REQUIRED_EXTENSIONS)),
            'the two tiers do not overlap'
        );

        static::__assert_true(
            !in_array('pcntl', Rsx_Php_Requirements::REQUIRED_EXTENSIONS, true),
            'pcntl is NOT in the runtime tier - requiring it would refuse every web request'
        );
    }

    /** ldap, imap and sodium are declared - the standard library the app is promised. */
    public static function test_the_standard_library_extensions_are_declared()
    {
        foreach (['ldap', 'imap', 'sodium'] as $extension) {
            static::__assert_true(
                in_array($extension, Rsx_Php_Requirements::REQUIRED_EXTENSIONS, true),
                "{$extension} is part of the declared runtime"
            );
        }
    }

    /**
     * The CLI tier goes through the same missing_from() as the runtime tier.
     *
     * Driven under a fabricated name so the assertion is about the computation, not
     * about what this box happens to have loaded.
     */
    public static function test_missing_from_reports_a_missing_cli_extension()
    {
        $list = array_merge(Rsx_Php_Requirements::REQUIRED_CLI_EXTENSIONS, [self::FABRICATED]);

        static::__assert_equals(
            [self::FABRICATED],
            Rsx_Php_Requirements::missing_from($list),
            'only the fabricated CLI extension is reported missing'
        );
    }

    /** The composition: the CLI tier applies under 'cli' and under no other SAPI. */
    public static function test_the_sapi_composition_adds_the_cli_tier_only_in_cli()
    {
        $for_cli = Rsx_Php_Requirements::extensions_for_sapi('cli');
        $for_fpm = Rsx_Php_Requirements::extensions_for_sapi('fpm-fcgi');

        static::__assert_true(
            in_array('pcntl', $for_cli, true),
            'the CLI SAPI must satisfy the CLI tier'
        );
        static::__assert_true(
            !in_array('pcntl', $for_fpm, true),
            'php-fpm is never asked for pcntl'
        );
        static::__assert_equals(
            Rsx_Php_Requirements::REQUIRED_EXTENSIONS,
            $for_fpm,
            'a non-CLI SAPI is asked for the runtime tier and nothing else'
        );
        static::__assert_equals(
            array_merge(Rsx_Php_Requirements::REQUIRED_EXTENSIONS, Rsx_Php_Requirements::REQUIRED_CLI_EXTENSIONS),
            $for_cli,
            'the CLI SAPI is asked for both tiers'
        );
    }

    /**
     * The boot check enforces what the composition says, per SAPI.
     *
     * Both calls run under the real (CLI) process: the SAPI is a parameter, so neither
     * mutates anything global to make its point.
     */
    public static function test_the_boot_check_applies_the_cli_tier_by_sapi()
    {
        Rsx_Php_Requirements::enforce_for_sapi('cli');
        Rsx_Php_Requirements::enforce_for_sapi('fpm-fcgi');

        static::__assert_true(
            true,
            'this box satisfies both tiers, so neither enforcement throws'
        );

        // And the CLI branch really is stricter: enforcing the composed list with a
        // fabricated CLI member throws, while the runtime tier alone does not.
        $caught = null;

        try {
            Rsx_Php_Requirements::enforce_list(
                array_merge(Rsx_Php_Requirements::REQUIRED_EXTENSIONS, [self::FABRICATED])
            );
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        static::__assert_true($caught !== null, 'a missing CLI-tier extension throws like any other');
    }

    // -------------------------------------------------------------------------
    // missing_extensions() / missing_from()
    // -------------------------------------------------------------------------

    /** A fabricated name is reported missing, by name, and a real one is not. */
    public static function test_missing_from_reports_the_fabricated_name()
    {
        $missing = Rsx_Php_Requirements::missing_from(['json', self::FABRICATED, 'pcre']);

        static::__assert_equals(
            [self::FABRICATED],
            $missing,
            'only the fabricated extension is reported missing'
        );
    }

    /** An empty list has nothing missing - the guard must not invent a failure. */
    public static function test_missing_from_an_empty_list_is_empty()
    {
        static::__assert_equals([], Rsx_Php_Requirements::missing_from([]), 'nothing declared, nothing missing');
    }

    // -------------------------------------------------------------------------
    // The boot check
    // -------------------------------------------------------------------------

    /** enforce_list() throws, and the message names the missing extension. */
    public static function test_the_boot_check_throws_naming_the_extension()
    {
        $caught = null;

        try {
            Rsx_Php_Requirements::enforce_list(['json', self::FABRICATED]);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        static::__assert_true($caught !== null, 'a missing extension throws - it never degrades');
        static::__assert_true(
            str_contains($caught->getMessage(), self::FABRICATED),
            'the throw names the missing extension: ' . ($caught ? $caught->getMessage() : '')
        );
        static::__assert_true(
            str_contains($caught->getMessage(), 'rsx:health'),
            'the throw points at the diagnostic that is exempt from it'
        );
    }

    /** A satisfied list passes silently. */
    public static function test_the_boot_check_passes_a_satisfied_list()
    {
        Rsx_Php_Requirements::enforce_list(['json', 'pcre']);

        static::__assert_true(true, 'a satisfied list throws nothing');
    }

    /** rsx:health and rsx:heal are exempt; nothing else is. */
    public static function test_only_health_and_heal_are_exempt()
    {
        static::__assert_equals(
            ['rsx:health', 'rsx:heal'],
            Rsx_Php_Requirements::EXEMPT_COMMANDS,
            'the exemption list is exactly the two diagnostics'
        );
    }

    // -------------------------------------------------------------------------
    // Container remediation (marker redirected - the real one is never touched)
    // -------------------------------------------------------------------------

    /** Inside the container, the remediation is "regenerate the image". */
    public static function test_the_container_remediation_names_the_image_and_the_package()
    {
        [$tmp, $marker] = self::_make_marker(true);

        try {
            $text = Rsx_Php_Requirements::remediation_for('zstd');

            static::__assert_true(
                str_contains($text, 'container image may need to be regenerated'),
                'the container branch recommends regenerating the image: ' . $text
            );
            static::__assert_true(
                str_contains($text, 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-zstd'),
                'it names the versioned apt package: ' . $text
            );
            static::__assert_true(
                str_contains($text, 'system/app/RSpade/resource/docker/Dockerfile'),
                'it names the Dockerfile to edit: ' . $text
            );
        } finally {
            self::_cleanup($tmp, $marker);
        }
    }

    /** Outside the container, it is the plain install line. */
    public static function test_the_non_container_remediation_is_the_plain_install_line()
    {
        [$tmp, $marker] = self::_make_marker(false);

        try {
            $text = Rsx_Php_Requirements::remediation_for('zstd');

            static::__assert_equals(
                'install/enable the php-zstd extension',
                $text,
                'outside a container the remediation stays the ordinary one'
            );
        } finally {
            self::_cleanup($tmp, $marker);
        }
    }

    /** The boot throw carries the container recommendation too. */
    public static function test_the_boot_throw_carries_the_container_recommendation()
    {
        [$tmp, $marker] = self::_make_marker(true);

        try {
            $message = Rsx_Php_Requirements::missing_message([self::FABRICATED]);

            static::__assert_true(
                str_contains($message, 'container image may need to be regenerated'),
                'the boot message recommends regenerating the image: ' . $message
            );
        } finally {
            self::_cleanup($tmp, $marker);
        }
    }

    // -------------------------------------------------------------------------
    // The rsx:health row reads the shared list
    // -------------------------------------------------------------------------

    /**
     * The "PHP" health row is built from the declared list, and its missing-extension
     * remediation carries the container guidance when the marker is present.
     */
    public static function test_the_health_row_reads_the_shared_list()
    {
        [$tmp, $marker] = self::_make_marker(true);

        try {
            $rows = Health_Check_Runner::run_one(Environment_Health_Checks::class, 'php_environment', 'PHP');

            $summary = null;
            foreach ($rows as $row) {
                if ($row['label'] === 'PHP Extensions') {
                    $summary = $row;
                }
            }

            static::__assert_true($summary !== null, 'the row set carries a "PHP Extensions" summary');

            // The CLI tier gets its own row - a box can be right for the web tier and
            // wrong for the CLI tier, so one row could not say both things.
            $cli_summary = null;
            foreach ($rows as $row) {
                if ($row['label'] === 'PHP CLI Extensions') {
                    $cli_summary = $row;
                }
            }

            static::__assert_true($cli_summary !== null, 'the row set carries a "PHP CLI Extensions" summary');

            $cli_listed = array_map('trim', explode(',', str_replace('loaded: ', '', $cli_summary['detail'])));
            $cli_foreign = array_values(array_diff($cli_listed, Rsx_Php_Requirements::REQUIRED_CLI_EXTENSIONS));

            static::__assert_equals(
                [],
                $cli_foreign,
                'the CLI row reports only extensions the CLI tier declares (foreign: ' . implode(', ', $cli_foreign) . ')'
            );

            // Every extension the summary lists as loaded came from the shared declaration.
            $listed = array_map('trim', explode(',', str_replace('loaded: ', '', $summary['detail'])));
            $foreign = array_values(array_diff($listed, Rsx_Php_Requirements::REQUIRED_EXTENSIONS));

            static::__assert_equals(
                [],
                $foreign,
                'the health row reports only extensions the shared list declares (foreign: ' . implode(', ', $foreign) . ')'
            );

            // And the per-missing remediation is the container one while the marker is up.
            static::__assert_true(
                str_contains(Rsx_Php_Requirements::remediation_for(self::FABRICATED), 'regenerated'),
                'a FAIL row for a missing extension would carry the container remediation'
            );
        } finally {
            self::_cleanup($tmp, $marker);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Every ext-* named in a `require` block of an installed composer package.
     *
     * `require` only - a `suggest` is by definition not a requirement, and treating one
     * as though it were is how a list grows extensions nothing needs.
     *
     * @return array<int, string>
     */
    private static function _composer_ext_requires(): array
    {
        $path = base_path('vendor/composer/installed.json');

        static::__assert_true(is_file($path), 'vendor/composer/installed.json exists');

        $data = json_decode((string) file_get_contents($path), true);
        $packages = $data['packages'] ?? $data;

        $found = [];

        foreach ($packages as $package) {
            foreach (array_keys($package['require'] ?? []) as $requirement) {
                if (str_starts_with($requirement, 'ext-')) {
                    $found[] = strtolower(substr($requirement, 4));
                }
            }
        }

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * Point the container marker at a throwaway path, present or absent.
     *
     * @return array{0:string,1:string} [tmp_dir, marker_path]
     */
    private static function _make_marker(bool $present): array
    {
        $tmp = sys_get_temp_dir() . '/rsx_php_requirements_' . bin2hex(random_bytes(8));
        mkdir($tmp, 0777, true);

        $marker = $tmp . '/.rspade_container';

        if ($present) {
            file_put_contents($marker, "\n");
        }

        Rsx_Php_Requirements::_testing_set_container_marker($marker);

        return [$tmp, $marker];
    }

    private static function _cleanup(string $tmp, string $marker): void
    {
        Rsx_Php_Requirements::_testing_reset();

        if (is_file($marker)) {
            unlink($marker);
        }
        if (is_dir($tmp)) {
            rmdir($tmp);
        }
    }
}
