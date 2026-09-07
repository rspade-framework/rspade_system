<?php

namespace App\RSpade\Tests\TestRunner\Php;

use ReflectionMethod;
use App\RSpade\Commands\Rsx\Rsx_Test_Command;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The full-suite RESULT CACHE: a docker-mode run records its per-class records and exit
 * code under the manifest build key, and a second run under the same key replays that
 * record instead of running again. The key is the hash of every scanned source file, so
 * "same key" means "same code", and the recorded verdict is the verdict that code has.
 *
 * What is asserted here is the record's contract: a written record reads back whole; a
 * record written under another key is NOT a hit (a renamed or copied file cannot leak a
 * verdict onto different code); a missing or unreadable file is "not yet run".
 *
 * @PHP-REFLECT-01-EXCEPTION The subjects are protected static helpers on an artisan
 * command whose only other caller is the docker run itself, which cannot be driven
 * without docker. Reflection is how the record contract is asserted in-process.
 */
class Results_Cache_Test extends Rsx_Test_Abstract
{
    /**
     * Pure file IO - no database.
     *
     * @var bool
     */
    protected static $use_database_transactions = false;

    protected static function __invoke_static(string $method, array $args)
    {
        $reflection = new ReflectionMethod(Rsx_Test_Command::class, $method);

        return $reflection->invoke(null, ...$args);
    }

    protected static function __scratch_path(): string
    {
        $dir = storage_path('rsx-tmp/test-results');
        ensure_directory($dir);

        return $dir . '/probe_' . bin2hex(random_bytes(6)) . '.json';
    }

    public static function test_a_written_record_reads_back_whole_under_its_key()
    {
        $path = static::__scratch_path();
        $by_class = [
            'App\\Fixture\\Alpha_Test' => ['class' => 'App\\Fixture\\Alpha_Test', 'short' => 'Alpha_Test', 'results' => [], 'duration' => 1.5],
            'App\\Fixture\\Beta_Test' => ['class' => 'App\\Fixture\\Beta_Test', 'short' => 'Beta_Test', 'results' => [], 'duration' => 0.2, 'error' => 'boom'],
        ];

        try {
            static::__invoke_static('write_cached_results', [$path, 'key_abc', '/tmp/run_1', $by_class, 1]);
            $read = static::__invoke_static('read_cached_results', [$path, 'key_abc']);

            static::__assert_not_null($read, 'a record written under key_abc reads back under key_abc');
            static::__assert_equals('key_abc', $read['build_key']);
            static::__assert_equals(1, $read['exit_code']);
            static::__assert_equals('/tmp/run_1', $read['run_dir']);
            static::__assert_equals($by_class, $read['by_class']);
            static::__assert_true(is_string($read['recorded_at']) && $read['recorded_at'] !== '', 'recorded_at is stamped');
        } finally {
            @unlink($path);
        }
    }

    public static function test_a_record_under_another_key_is_not_a_hit()
    {
        $path = static::__scratch_path();

        try {
            static::__invoke_static('write_cached_results', [$path, 'key_abc', '/tmp/run_1', [], 0]);

            static::__assert_null(static::__invoke_static('read_cached_results', [$path, 'key_xyz']), 'a different build key never reads another key\'s verdict');
        } finally {
            @unlink($path);
        }
    }

    public static function test_missing_and_unreadable_records_mean_not_yet_run()
    {
        $missing = static::__scratch_path();
        static::__assert_null(static::__invoke_static('read_cached_results', [$missing, 'key_abc']), 'no file: no verdict');

        $garbage = static::__scratch_path();
        try {
            file_put_contents($garbage, '{not json');
            static::__assert_null(static::__invoke_static('read_cached_results', [$garbage, 'key_abc']), 'unreadable file: no verdict');

            file_put_contents($garbage, json_encode(['build_key' => 'key_abc']));
            static::__assert_null(static::__invoke_static('read_cached_results', [$garbage, 'key_abc']), 'a record without by_class/exit_code is incomplete: no verdict');
        } finally {
            @unlink($garbage);
        }
    }

    public static function test_the_cache_path_is_keyed_by_build_key()
    {
        $path = static::__invoke_static('results_cache_path', ['deadbeef']);

        static::__assert_true(str_ends_with($path, '/rsx-tmp/test-results/framework_deadbeef.json'), 'path names the build key: ' . $path);
    }

    public static function test_results_file_parsing_skips_malformed_lines()
    {
        $path = static::__scratch_path();
        try {
            file_put_contents($path, "{\"class\":\"A\",\"short\":\"A\",\"results\":[]}\nnot json\n{\"no_class\":true}\n{\"class\":\"B\",\"short\":\"B\",\"results\":[]}\n");
            $by_class = static::__invoke_static('read_results_file', [$path]);

            static::__assert_equals(['A', 'B'], array_keys($by_class));
        } finally {
            @unlink($path);
        }
    }
    public static function test_the_environment_fingerprint_follows_name_size_and_mtime()
    {
        $dir = storage_path('rsx-tmp/test-results/fp_probe_' . bin2hex(random_bytes(4)));
        ensure_directory($dir . '/sub');
        try {
            file_put_contents($dir . '/a.txt', 'aaa');
            file_put_contents($dir . '/sub/b.txt', 'bb');
            $first = static::__invoke_static('fingerprint_directories', [[$dir]]);

            static::__assert_equals($first, static::__invoke_static('fingerprint_directories', [[$dir]]), 'deterministic for an unchanged tree');

            file_put_contents($dir . '/sub/b.txt', 'bbbb');
            $after_size = static::__invoke_static('fingerprint_directories', [[$dir]]);
            static::__assert_true($first !== $after_size, 'a size change moves the fingerprint');

            touch($dir . '/a.txt', time() + 5);
            $after_mtime = static::__invoke_static('fingerprint_directories', [[$dir]]);
            static::__assert_true($after_size !== $after_mtime, 'an mtime change moves the fingerprint');

            file_put_contents($dir . '/c.txt', '');
            $after_name = static::__invoke_static('fingerprint_directories', [[$dir]]);
            static::__assert_true($after_mtime !== $after_name, 'a new file moves the fingerprint');

            static::__assert_equals(
                static::__invoke_static('fingerprint_directories', [['/definitely/not/a/dir']]),
                sha1(''),
                'a missing directory contributes nothing'
            );
        } finally {
            foreach (['/sub/b.txt', '/a.txt', '/c.txt'] as $f) { @unlink($dir . $f); }
            @rmdir($dir . '/sub'); @rmdir($dir);
        }
    }
}
