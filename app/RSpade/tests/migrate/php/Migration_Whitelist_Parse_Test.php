<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use App\RSpade\Commands\Migrate\Maint_Migrate;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Migrate\Php\Whitelist_Probe_Migrate;

/**
 * An unparseable .migration_whitelist is an ERROR naming the file, never an empty one.
 *
 * The file is machine-written, so the way it stops being JSON is conflict markers left in
 * it by a merge - both sides appended an entry and git could not settle the closing brace.
 * Read as an empty map it makes the stray-file tripwire fire on EVERY migration in the
 * tree, under a message that names make:migration and never mentions the file, which is a
 * ten-minute diagnosis for a one-line cause (a downstream field report, 2026-09-21).
 *
 * Two subjects: the command's parse seam, driven through the sandbox probe, and the
 * bin/lib resolver the git proxy runs to produce the union in the first place.
 *
 * No database access - skip the per-test transaction.
 */
class Migration_Whitelist_Parse_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** Relative to base_path() - the resolver is framework-internal, inside system/. */
    private const RESOLVER = 'bin/lib/merge_migration_whitelist.php';

    /** @var Whitelist_Probe_Migrate[] Probes created by the current test. */
    private static $probes = [];

    private static function __probe(): Whitelist_Probe_Migrate
    {
        $probe = new Whitelist_Probe_Migrate();
        self::$probes[] = $probe;

        return $probe;
    }

    private static function __restore(): void
    {
        foreach (self::$probes as $probe) {
            $probe->cleanup();
        }

        self::$probes = [];

        Rsx::clear_mode_cache();
    }

    /** A whitelist exactly as git leaves it when both sides appended an entry. */
    private static function __conflicted_whitelist(): string
    {
        return <<<'JSON'
{
    "description": "This file tracks migrations created via php artisan make:migration",
    "purpose": "Prevents manually created migrations from running to avoid timestamp conflicts",
    "migrations": {
        "2026_01_01_000000_create_ancestor_table.php": {
            "created_at": "2026-01-01T00:00:00+00:00",
            "created_by": "fixture",
            "command": "php artisan make:migration:safe create_ancestor_table"
        },
<<<<<<< HEAD
        "2026_09_20_101010_create_ours_table.php": {
            "created_at": "2026-09-20T10:10:10+00:00",
=======
        "2026_09_20_202020_create_theirs_table.php": {
            "created_at": "2026-09-20T20:20:20+00:00",
>>>>>>> origin/master
            "created_by": "fixture",
            "command": "php artisan make:migration:safe create_a_table"
        }
    }
}
JSON;
    }

    // -------------------------------------------------------------------------
    // The command's parse seam
    // -------------------------------------------------------------------------

    public static function test_a_whitelist_with_conflict_markers_is_reported_as_invalid_json()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);

            $command = self::__probe();
            $command->raw_whitelist = self::__conflicted_whitelist();

            $directory = Whitelist_Probe_Migrate::stage_migrations(['2026_09_15_000001_listed.php']);

            try {
                $passed = $command->check_whitelist([$directory]);
                $console = $command->console_text();

                static::__assert_false($passed, 'the run is refused');
                static::__assert_contains(
                    'not valid JSON',
                    $console,
                    'the message says the file is not JSON'
                );
                static::__assert_contains(
                    '.migration_whitelist',
                    $console,
                    'the message names the path'
                );
                static::__assert_contains(
                    'merge conflict markers',
                    $console,
                    'and names the usual cause'
                );
                static::__assert_false(
                    str_contains($console, 'Unauthorized migrations detected'),
                    'it never degrades into declaring every migration in the tree unauthorized'
                );
            } finally {
                exec_safe('rm -rf ' . escapeshellarg($directory));
            }
        } finally {
            self::__restore();
        }
    }

    public static function test_a_valid_whitelist_parses()
    {
        try {
            Rsx::_testing_set_mode(Rsx::MODE_DEVELOPMENT);

            $command = self::__probe();
            $command->whitelisted = ['2026_09_15_000001_listed.php'];

            $directory = Whitelist_Probe_Migrate::stage_migrations(['2026_09_15_000001_listed.php']);

            try {
                static::__assert_true(
                    $command->check_whitelist([$directory]),
                    'a whitelist that parses and lists the tree passes'
                );
                static::__assert_equals('', trim($command->console_text()), 'silently');
            } finally {
                exec_safe('rm -rf ' . escapeshellarg($directory));
            }
        } finally {
            self::__restore();
        }
    }

    public static function test_the_parse_seam_answers_null_for_a_file_that_is_not_a_whitelist()
    {
        $directory = sys_get_temp_dir() . '/rsx-whitelist-parse-' . random_hash(12);
        ensure_directory($directory);

        try {
            $path = $directory . '/.migration_whitelist';

            file_put_contents_safe($path, self::__conflicted_whitelist());
            static::__assert_null(
                Maint_Migrate::read_whitelist_entries($path),
                'conflict markers'
            );

            file_put_contents_safe($path, json_encode(['description' => 'no map here'], JSON_PRETTY_PRINT));
            static::__assert_null(
                Maint_Migrate::read_whitelist_entries($path),
                'valid JSON with no migrations map is still not a whitelist'
            );

            file_put_contents_safe($path, json_encode(['migrations' => []], JSON_PRETTY_PRINT));
            static::__assert_equals(
                [],
                Maint_Migrate::read_whitelist_entries($path),
                'a whitelist that lists nothing is EMPTY, not invalid'
            );
        } finally {
            exec_safe('rm -rf ' . escapeshellarg($directory));
        }
    }

    // -------------------------------------------------------------------------
    // The resolver the git proxy runs
    // -------------------------------------------------------------------------

    /**
     * Run the resolver over three staged files.
     *
     * @return array{0:int,1:string,2:string} exit code, stdout, stderr
     */
    private static function __run_resolver(?string $base, string $ours, string $theirs): array
    {
        $directory = sys_get_temp_dir() . '/rsx-whitelist-merge-' . random_hash(12);
        ensure_directory($directory);

        try {
            file_put_contents_safe($directory . '/base', $base ?? '');
            file_put_contents_safe($directory . '/ours', $ours);
            file_put_contents_safe($directory . '/theirs', $theirs);

            $command = 'php ' . escapeshellarg(base_path(self::RESOLVER))
                . ' ' . escapeshellarg($directory . '/base')
                . ' ' . escapeshellarg($directory . '/ours')
                . ' ' . escapeshellarg($directory . '/theirs')
                . ' 2>' . escapeshellarg($directory . '/stderr');

            $stdout = [];
            $status = 0;
            exec_safe($command, $stdout, $status);

            return [$status, implode("\n", $stdout), (string) file_get_contents($directory . '/stderr')];
        } finally {
            exec_safe('rm -rf ' . escapeshellarg($directory));
        }
    }

    private static function __whitelist(array $basenames): string
    {
        $migrations = [];

        foreach ($basenames as $name) {
            $migrations[$name] = [
                'created_at' => '2026-09-21T00:00:00+00:00',
                'created_by' => 'fixture',
                'command' => 'php artisan make:migration:safe ' . $name,
            ];
        }

        return json_encode([
            'description' => 'This file tracks migrations created via php artisan make:migration',
            'purpose' => 'Prevents manually created migrations from running to avoid timestamp conflicts',
            'migrations' => $migrations,
        ], JSON_PRETTY_PRINT);
    }

    public static function test_the_resolver_produces_the_sorted_key_union()
    {
        $ancestor = '2026_01_01_000000_create_ancestor_table.php';
        $ours = '2026_09_20_101010_create_ours_table.php';
        $theirs = '2026_09_20_202020_create_theirs_table.php';

        [$status, $stdout, $stderr] = self::__run_resolver(
            self::__whitelist([$ancestor]),
            self::__whitelist([$ancestor, $ours]),
            self::__whitelist([$ancestor, $theirs])
        );

        static::__assert_equals(0, $status, 'the merge succeeds');

        $decoded = json_decode($stdout, true);

        static::__assert_not_empty($decoded, 'the output is valid JSON');
        static::__assert_equals(
            [$ancestor, $ours, $theirs],
            array_keys($decoded['migrations']),
            'every key from both sides, in filename order'
        );
        static::__assert_contains(
            'ours=2 theirs=2 merged=3',
            $stderr,
            'the accounting line the proxy quotes in its [NOTE]'
        );

        // The shape make:migration:safe writes, byte for byte: pretty-printed, no trailing
        // newline. The proxy writes stdout over the working file as it stands.
        static::__assert_equals(
            json_encode($decoded, JSON_PRETTY_PRINT),
            $stdout,
            'the output is in the writer command\'s own shape'
        );
    }

    public static function test_the_resolver_treats_an_empty_base_as_no_merge_base()
    {
        $ours = '2026_09_20_101010_create_ours_table.php';
        $theirs = '2026_09_20_202020_create_theirs_table.php';

        // The whitelist was created on both sides independently: there is no stage 1.
        [$status, $stdout] = self::__run_resolver(
            '',
            self::__whitelist([$ours]),
            self::__whitelist([$theirs])
        );

        static::__assert_equals(0, $status, 'an absent merge base is not a failure');

        $decoded = json_decode($stdout, true);

        static::__assert_equals(
            [$ours, $theirs],
            array_keys($decoded['migrations']),
            'both sides survive'
        );
    }

    public static function test_the_resolver_refuses_a_malformed_stage()
    {
        $good = self::__whitelist(['2026_01_01_000000_create_ancestor_table.php']);

        [$status, $stdout, $stderr] = self::__run_resolver($good, '{ not json', $good);

        static::__assert_equals(2, $status, 'a stage that does not parse exits 2');
        static::__assert_equals('', trim($stdout), 'and writes no merged file');
        static::__assert_contains('ours', $stderr, 'the failing stage is named');

        // Valid JSON that is not a whitelist is refused on the same terms: the resolver
        // only ever writes a file it fully understood.
        [$status, , $stderr] = self::__run_resolver($good, $good, json_encode(['nope' => 1]));

        static::__assert_equals(2, $status, 'a stage with no migrations map exits 2');
        static::__assert_contains('theirs', $stderr, 'the failing stage is named');
    }
}
