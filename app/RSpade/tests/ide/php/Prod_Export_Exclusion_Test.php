<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Ide\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Framework test for the Prod_Export_Command whitelist exclusion predicate
 * (app/RSpade/Commands/Rsx/Prod_Export_Command.php).
 *
 * The security-relevant guarantee of the rewritten (whitelist) exporter is that
 * sensitive/runtime state never lands in a deployment package. The system/ copy
 * excludes ALL of storage/ (only sealed-build artifacts are whitelisted back), so
 * the IDE-bridge grant token, blob store, db backups and logs cannot ship. This
 * exercises the pure _is_excluded() predicate directly (reflection); the full
 * copy/HTTP behavior is covered by manual verification of rsx:prod:export.
 */
class Prod_Export_Exclusion_Test extends Rsx_Test_Abstract
{
    // Pure predicate logic - no database, no filesystem, no config.
    protected static $use_database_transactions = false;

    /** The exact exclude list the system/ tree is copied with (see handle()). */
    private const SYSTEM_EXCLUDES = [
        'storage', '.env', '.git', 'app/RSpade/tests',
        'app/RSpade/resource/DebugProxy', 'docs.dev', 'docs.goals',
        'ace_reference', 'bin/publish', '__pycache__', '*.expect',
    ];

    private static function __is_excluded(string $sub_path, array $excludes): bool
    {
        $cmd = new \App\RSpade\Commands\Rsx\Prod_Export_Command();
        $ref = new \ReflectionMethod($cmd, '_is_excluded');
        $ref->setAccessible(true);

        return (bool) $ref->invoke($cmd, $sub_path, $excludes);
    }

    public static function test_ide_bridge_grant_token_is_excluded()
    {
        // The whole storage/ subtree is excluded from the system/ copy, so the grant
        // token directory (and its token file) cannot ship into an export.
        static::__assert_true(
            self::__is_excluded('storage/rsx-ide-bridge/ide-grant-abc.token', self::SYSTEM_EXCLUDES),
            'IDE bridge grant token must be excluded from the export'
        );
        static::__assert_true(
            self::__is_excluded('storage/rsx-ide-bridge', self::SYSTEM_EXCLUDES),
            'the IDE bridge directory itself must be excluded'
        );
    }

    public static function test_runtime_state_and_secrets_are_excluded()
    {
        $must_exclude = [
            'storage/uploads/ab/cd/blobhash',          // content-addressed blob store
            'storage/db_backups/dump.sql',             // test-DB mysqldump cache
            'storage/logs/laravel.log',                // logs
            'storage/rsx-framework/mutations.json',       // framework-update working store
            '.env',                                    // real secrets
            '.git/config',                             // repo
            'app/RSpade/tests/ide/php/x.php',          // framework tests
            'app/RSpade/resource/DebugProxy/x.ts',     // removed debug proxy tree
            'bin/publish',                             // packaging tool
            'app/RSpade/Foo.expect',                   // *.expect suffix pattern
        ];
        foreach ($must_exclude as $path) {
            static::__assert_true(
                self::__is_excluded($path, self::SYSTEM_EXCLUDES),
                "must be excluded from export: {$path}"
            );
        }
    }

    public static function test_source_code_is_not_excluded()
    {
        $must_ship = [
            'app/RSpade/Core/Rsx.php',
            'app/RSpade/Ide/Services/handler.php',
            'config/rsx.php',
            'public/index.php',
            'vendor/autoload.php',
        ];
        foreach ($must_ship as $path) {
            static::__assert_true(
                !self::__is_excluded($path, self::SYSTEM_EXCLUDES),
                "must ship in export (not excluded): {$path}"
            );
        }
    }

    public static function test_exclude_matches_segment_not_substring()
    {
        // 'storage' must not accidentally exclude a path that merely CONTAINS the word.
        static::__assert_true(
            !self::__is_excluded('app/RSpade/Core/Storage/Rsx_Storage.js', self::SYSTEM_EXCLUDES),
            'a "Storage" path segment unrelated to storage/ must still ship'
        );
    }
}
