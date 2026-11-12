<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Lib\Geo\Ip;

use Illuminate\Contracts\Debug\ExceptionHandler;
use MaxMind\Db\Reader;
use Throwable;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Schedule_Model;

/**
 * Rsx_Geo_Ip - where an IP address is, as city / state / country names.
 *
 *     Rsx_Geo_Ip::lookup('8.8.8.8');
 *     // ['city' => 'Mountain View', 'state' => 'California', 'country' => 'United States']
 *     Rsx_Geo_Ip::label('8.8.8.8');
 *     // 'Mountain View, California, United States' (null when nothing is known)
 *
 * The answer always carries exactly those three keys; any of them may be null. The data is
 * DB-IP's IP to City Lite database (CC BY 4.0 - every screen that shows a location credits
 * "IP geolocation by DB-IP", linking to https://db-ip.com), read with the pure-PHP
 * maxmind-db/reader from the file at Rsx_Project_Paths::geo_ip_database_file(), which
 * Geo_Ip_Service::update downloads, verifies and swaps into place.
 *
 * THE ORDER OF A LOOKUP:
 *
 *   1. An invalid address, or one outside the global range (private, loopback, link-local,
 *      reserved, documentation ...), answers all-null at once: no cache, no file.
 *   2. A cached answer (RsxCache persistent namespace, one day) is the answer.
 *   3. The installed database answers, and that answer is cached for a day - an address the
 *      database does not know (all-null) included.
 *   4. No usable database (not acquired yet, removed by rsx:clean, unreadable): all-null,
 *      NOT cached, and the update task may be dispatched (maybe_trigger_update()).
 *   5. Anything that throws while reading the database or triggering the update is REPORTED
 *      (the exception handler logs it) and answers all-null, NOT cached. A lookup is never
 *      fatal: a location is decoration beside an address, never a reason a page fails.
 *
 * So an answer may be null the first times it is asked, and may change when the database
 * updates: call lookup() whenever a location is shown, and never store the answer. A
 * location is approximate and spoofable by whoever controls the address's routing - it is
 * never a security control.
 *
 * Every value is a plain string from a downloaded file - untrusted text, capped at
 * VALUE_MAX_LENGTH characters, escaped on output like any other.
 */
class Rsx_Geo_Ip
{
    /** How long a real answer is cached (owner ruling: one day). */
    public const CACHE_SECONDS = RsxCache::DAY;

    /** Characters kept of each name. */
    public const VALUE_MAX_LENGTH = 100;

    /** The update task (Geo_Ip_Service::update) is not dispatched again within this window of its last run. */
    public const TRIGGER_RECENT_RUN_SECONDS = 6 * 3600;

    /** ... nor when its pending or scheduled run is due within this window. */
    public const TRIGGER_DUE_WITHIN_SECONDS = 5 * 60;

    /** The update task. */
    public const UPDATE_SERVICE = 'Geo_Ip_Service';
    public const UPDATE_METHOD = 'update';

    /** The persistent cache key prefix; the canonical address follows. */
    private const CACHE_PREFIX = 'geo_ip:';

    /** The open reader, and the identity (inode, mtime, size) of the file it opened. */
    private static ?Reader $reader = null;
    private static ?string $reader_identity = null;

    /** The trigger is considered at most once per process. */
    private static bool $trigger_considered = false;

    /** Test seam: a database file in place of the installed one. */
    private static ?string $database_file_for_tests = null;

    /**
     * Where $ip is: city, state (the first subdivision) and country, in English.
     *
     * @return array{city: ?string, state: ?string, country: ?string}
     */
    public static function lookup(string $ip): array
    {
        $ip = static::canonical_public_ip($ip);
        if ($ip === null) {
            return static::empty_answer();
        }

        $cached = RsxCache::get_persistent(self::CACHE_PREFIX . $ip);
        if (is_array($cached)) {
            return static::__shape($cached);
        }

        // The owner-ruled containment: a failure to open or read the database, or to trigger
        // its update, is reported and answers all-null uncached. Nothing else is caught here.
        try {
            $reader = static::__reader();
        } catch (Throwable $e) {
            // A file that will not open is no usable database: report it, and let the update
            // task replace it.
            static::__report($e, $ip);
            static::__trigger_contained($ip);

            return static::empty_answer();
        }

        if ($reader === null) {
            static::__trigger_contained($ip);

            return static::empty_answer();
        }

        try {
            $answer = static::answer_from_record($reader->get($ip));
        } catch (Throwable $e) {
            static::__report($e, $ip);

            return static::empty_answer();
        }

        RsxCache::set_persistent(self::CACHE_PREFIX . $ip, $answer, self::CACHE_SECONDS);

        return $answer;
    }

    /**
     * lookup() as one line for display - "Mountain View, California, United States" - with
     * the unknown parts left out (and a state equal to its city once), or null when nothing
     * is known. A plain string: escape it on output.
     */
    public static function label(string $ip): ?string
    {
        $answer = static::lookup($ip);

        $parts = [];
        foreach ([$answer['city'], $answer['state'], $answer['country']] as $part) {
            if ($part !== null && !in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** The answer with nothing known. */
    public static function empty_answer(): array
    {
        return ['city' => null, 'state' => null, 'country' => null];
    }

    /**
     * $ip in canonical form when it is a valid GLOBAL address, else null. An IPv4-mapped IPv6
     * address (::ffff:a.b.c.d) is read as the IPv4 address it carries.
     */
    public static function canonical_public_ip(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }

        $canonical = inet_ntop($packed);
        if ($canonical === false) {
            return null;
        }

        if (filter_var($canonical, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return null;
        }

        return $canonical;
    }

    /**
     * The three names from one DB-IP City record (null = the address is not in the database).
     * Anything that is not a non-empty string is null; each value is capped.
     */
    public static function answer_from_record(mixed $record): array
    {
        if (!is_array($record)) {
            return static::empty_answer();
        }

        return [
            'city' => static::__name($record['city']['names']['en'] ?? null),
            'state' => static::__name($record['subdivisions'][0]['names']['en'] ?? null),
            'country' => static::__name($record['country']['names']['en'] ?? null),
        ];
    }

    /** The database file lookups read. */
    public static function database_file(): string
    {
        return self::$database_file_for_tests ?? Rsx_Project_Paths::geo_ip_database_file();
    }

    /**
     * The installed database's metadata, or null when there is no file. Throws when a file
     * is there but is not a readable MMDB.
     *
     * @return array{database_type: string, build_epoch: int, release: string}|null
     *         release = the UTC year-month of the build ('2026-11')
     */
    public static function installed_metadata(): ?array
    {
        $file = static::database_file();
        if (!is_file($file)) {
            return null;
        }

        $reader = new Reader($file);
        try {
            $metadata = $reader->metadata();

            return [
                'database_type' => (string) $metadata->databaseType,
                'build_epoch' => (int) $metadata->buildEpoch,
                'release' => gmdate('Y-m', (int) $metadata->buildEpoch),
            ];
        } finally {
            $reader->close();
        }
    }

    /**
     * Dispatch the update task when no usable database is installed - unless a run of it was
     * created in the last TRIGGER_RECENT_RUN_SECONDS (whatever its outcome), or its pending or
     * scheduled run is due within TRIGGER_DUE_WITHIN_SECONDS. Considered at most once per
     * process.
     *
     * @return int|null The dispatched run's id, or null when nothing was dispatched
     */
    public static function maybe_trigger_update(): ?int
    {
        if (self::$trigger_considered) {
            return null;
        }
        self::$trigger_considered = true;

        $class = Task::resolve_task_class(self::UPDATE_SERVICE, self::UPDATE_METHOD);
        $now = now();
        $recent = $now->copy()->subSeconds(self::TRIGGER_RECENT_RUN_SECONDS)->format('Y-m-d H:i:s.v');
        $due_by = $now->copy()->addSeconds(self::TRIGGER_DUE_WITHIN_SECONDS)->format('Y-m-d H:i:s.v');

        $owed = Task_Run_Model::query()
            ->where('class', $class)
            ->where('method', self::UPDATE_METHOD)
            ->where(function ($query) use ($recent, $due_by) {
                $query->where('created_at', '>=', $recent)
                    ->orWhere(function ($pending) use ($due_by) {
                        $pending->where('status_id', Task_Run_Model::STATUS_PENDING)
                            ->where('scheduled_for', '<=', $due_by);
                    });
            })
            ->exists();
        if ($owed) {
            return null;
        }

        $scheduled_soon = Task_Schedule_Model::query()
            ->where('class', $class)
            ->where('method', self::UPDATE_METHOD)
            ->where('next_run_at', '<=', $due_by)
            ->exists();
        if ($scheduled_soon) {
            return null;
        }

        return Task::dispatch(self::UPDATE_SERVICE, self::UPDATE_METHOD);
    }

    /**
     * Point lookups at another database file (null restores the installed one), and forget
     * the open reader and the per-process trigger memory. Tests only.
     */
    public static function _set_database_file_for_tests(?string $file): void
    {
        self::$database_file_for_tests = $file;
        static::_reset_for_tests();
    }

    /** Forget the open reader and the per-process trigger memory. Tests only. */
    public static function _reset_for_tests(): void
    {
        self::$reader?->close();
        self::$reader = null;
        self::$reader_identity = null;
        self::$trigger_considered = false;
    }

    /** The cache key an address's answer is stored under (tests read and seed it). */
    public static function _cache_key_for_tests(string $canonical_ip): string
    {
        return self::CACHE_PREFIX . $canonical_ip;
    }

    /**
     * The reader over the database file, reopened when the file was replaced since it was
     * opened (a long-lived worker outlives an update); null when there is no file.
     */
    private static function __reader(): ?Reader
    {
        $file = static::database_file();

        clearstatcache(true, $file);
        $stat = @stat($file);
        if ($stat === false) {
            static::__reset_reader();

            return null;
        }

        $identity = "{$stat['ino']}:{$stat['mtime']}:{$stat['size']}";
        if (self::$reader === null || self::$reader_identity !== $identity) {
            static::__reset_reader();
            self::$reader = new Reader($file);
            self::$reader_identity = $identity;
        }

        return self::$reader;
    }

    private static function __reset_reader(): void
    {
        self::$reader?->close();
        self::$reader = null;
        self::$reader_identity = null;
    }

    /** One name: a trimmed, capped, non-empty string, or null. */
    private static function __name(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return mb_substr($value, 0, self::VALUE_MAX_LENGTH);
    }

    /** A cached answer, shaped to the contract whatever the entry holds. */
    private static function __shape(array $cached): array
    {
        return [
            'city' => static::__name($cached['city'] ?? null),
            'state' => static::__name($cached['state'] ?? null),
            'country' => static::__name($cached['country'] ?? null),
        ];
    }

    /** maybe_trigger_update(), its failure reported rather than thrown (the lookup's containment). */
    private static function __trigger_contained(string $ip): void
    {
        try {
            static::maybe_trigger_update();
        } catch (Throwable $e) {
            static::__report($e, $ip);
        }
    }

    /** Report a contained lookup failure through the exception handler (it is logged). */
    private static function __report(Throwable $e, string $ip): void
    {
        app(ExceptionHandler::class)->report(new \RuntimeException(
            "Rsx_Geo_Ip::lookup({$ip}) answered no location: "
            . get_class($e) . ': ' . $e->getMessage(),
            0,
            $e
        ));
    }
}
