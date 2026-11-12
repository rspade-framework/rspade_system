<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Lib\Geo\Ip;

use Illuminate\Contracts\Debug\ExceptionHandler;
use MaxMind\Db\Reader;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;
use App\RSpade\Core\Cache\RsxCache;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Task\Task_Run_Model;
use App\RSpade\Core\Task\Task_Schedule_Model;
use App\RSpade\Lib\Geo\Places\Country_Model;

/**
 * Rsx_Geo_Ip - where an IP address is, and whose network it belongs to.
 *
 *     Rsx_Geo_Ip::lookup('8.8.8.8');
 *     // ['city' => 'Mountain View', 'state' => 'California', 'country' => 'USA', 'org' => 'Google']
 *     Rsx_Geo_Ip::label('8.8.8.8');
 *     // 'Google' (the org when the network is listed; else 'Mountain View, California, USA';
 *     //  null when nothing is known)
 *
 * The answer always carries exactly those four keys; any of them may be null:
 *
 *   city, state  English names (state = the first subdivision)
 *   country      the ISO 3166-1 ALPHA-3 code ('USA'), mapped from DB-IP's alpha-2 code
 *                through the countries table (Country_Model); a code with no row answers
 *                null and is reported once per process
 *   org          the short name of a NOTABLE network (cloud, hosting, CDN, security proxy,
 *                AI) the address belongs to - config('rsx.geo_ip.networks'), keyed by
 *                autonomous system ('AS16509') or CIDR range ('160.79.104.0/21'); a range
 *                containing the address wins over its ASN (the most specific range among
 *                several); default-deny: any other network answers null
 *
 * org supplements the place, never replaces it: city / state / country are answered either
 * way, and label() is the display that chooses.
 *
 * The data is two DB-IP Lite databases (CC BY 4.0 - every screen that shows a location
 * credits "IP geolocation by DB-IP", <Geo_Ip_Credit />): IP to City Lite for the place and
 * IP to ASN Lite for the network, read with the pure-PHP maxmind-db/reader from the files at
 * Rsx_Project_Paths::geo_ip_city_database_file() / geo_ip_asn_database_file(), which
 * Geo_Ip_Service::update downloads, verifies and swaps into place.
 *
 * THE ORDER OF A LOOKUP:
 *
 *   1. An invalid address, or one outside the global range (private, loopback, link-local,
 *      reserved, documentation ...), answers all-null at once: no cache, no file.
 *   2. A cached answer (RsxCache persistent namespace, one day) is the answer.
 *   3. Each database answers its own part: the city database city / state / country, the
 *      ASN database the autonomous system that org falls back to (network_name()). When
 *      both answered, the answer is cached for a day - an address the databases do not
 *      know (all-null) included.
 *   4. A database that is not usable (not acquired yet, removed by rsx:clean, unreadable,
 *      or EXPIRED - more than DATA_VALIDITY_DAYS past its own release) leaves its part null, the answer is NOT cached, and the update task may be dispatched
 *      (maybe_trigger_update()). The two databases are independent: one missing never
 *      blanks the other's part.
 *   5. Anything that throws while reading a database, mapping its answer or triggering the
 *      update is REPORTED (the exception handler logs it), leaves that part null, and the
 *      answer is NOT cached. A lookup is never fatal: a location is decoration beside an
 *      address, never a reason a page fails.
 *
 * THE DATA LIFETIME: the update keeps trying (schedule, build, trigger), and while it fails
 * the installed file keeps answering - until DATA_VALIDITY_DAYS after its release, when it
 * is absent and the address stands alone. A cached answer may outlive the database that
 * gave it by at most CACHE_SECONDS.
 *
 * So an answer may be null the first times it is asked, and may change when a database
 * updates: call lookup() whenever a location is shown, and never store the answer. A
 * location is approximate and spoofable by whoever controls the address's routing - it is
 * never a security control.
 *
 * Every value is a plain string from a downloaded file or configuration - untrusted text,
 * capped at VALUE_MAX_LENGTH characters, escaped on output like any other.
 */
class Rsx_Geo_Ip
{
    /** How long a real answer is cached (owner ruling: one day). */
    public const CACHE_SECONDS = RsxCache::DAY;

    /** Characters kept of each name. */
    public const VALUE_MAX_LENGTH = 100;

    /**
     * THE DATA VALIDITY WINDOW (owner ruling 2026-11-12) - a lifetime of the DATA, never a
     * timeout on any operation: a downloaded database answers only until this many days after
     * its own release (the build epoch in its MMDB metadata). Past that, lookup() treats it as
     * ABSENT - exactly the missing-file path: its part of the answer null, nothing cached, the
     * update triggered. DB-IP publishes monthly, so a healthy box replaces each file long
     * before; while every download fails the installed file keeps answering until this
     * window ends, and then the address stands alone. A later successful update revives it.
     * 183 days is "six months" made exact; it is defined here and nowhere else.
     */
    public const DATA_VALIDITY_DAYS = 183;

    /** The update task (Geo_Ip_Service::update) is not dispatched again within this window of its last run. */
    public const TRIGGER_RECENT_RUN_SECONDS = 6 * 3600;

    /** ... nor when its pending or scheduled run is due within this window. */
    public const TRIGGER_DUE_WITHIN_SECONDS = 5 * 60;

    /** The update task. */
    public const UPDATE_SERVICE = 'Geo_Ip_Service';
    public const UPDATE_METHOD = 'update';

    /** The two databases: DB-IP City Lite (the place) and DB-IP ASN Lite (the network). */
    public const DATABASE_CITY = 'city';
    public const DATABASE_ASN = 'asn';
    public const DATABASES = [self::DATABASE_CITY, self::DATABASE_ASN];

    /** The configuration key of the notable-network list. */
    public const NETWORKS_CONFIG = 'rsx.geo_ip.networks';

    /** The persistent cache key prefix; the canonical address follows. */
    private const CACHE_PREFIX = 'geo_ip.lookup:';

    /** The open readers, and the identity (inode, mtime, size) of the file each opened, by database. */
    private static array $readers = [];
    private static array $reader_identities = [];

    /** DB-IP alpha-2 country code => alpha-3 (null = no countries row), memoised per process. */
    private static array $alpha3_by_alpha2 = [];

    /** The trigger is considered at most once per process. */
    private static bool $trigger_considered = false;

    /** Test seam: database files in place of the installed ones, by database. */
    private static array $database_files_for_tests = [];

    /** Test seam: the Unix time the validity window is measured against, in place of the clock. */
    private static ?int $now_for_tests = null;

    /**
     * Where $ip is: city, state (the first subdivision), country (alpha-3) and the notable
     * network it belongs to.
     *
     * @return array{city: ?string, state: ?string, country: ?string, org: ?string}
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

        $answer = static::empty_answer();
        $asn = null;
        $complete = true;
        $missing = false;

        // The owner-ruled containment: a failure to open or read a database, or to map its
        // answer, is reported and leaves that database's part null, uncached. Nothing else is
        // caught here.
        foreach (self::DATABASES as $database) {
            try {
                $reader = static::__reader($database);
            } catch (Throwable $e) {
                // A file that will not open is no usable database: report it, and let the
                // update task replace it.
                static::__report($e, $ip);
                $complete = false;
                $missing = true;
                continue;
            }

            // A database past its validity window is absent: the same path as no file.
            if ($reader === null || static::is_expired((int) $reader->metadata()->buildEpoch)) {
                $complete = false;
                $missing = true;
                continue;
            }

            try {
                $record = $reader->get($ip);
                if ($database === self::DATABASE_CITY) {
                    $answer = array_merge($answer, static::answer_from_city_record($record));
                } else {
                    $asn = static::asn_from_record($record);
                }
            } catch (Throwable $e) {
                static::__report($e, $ip);
                $complete = false;
            }
        }

        // A listed range needs no database; an ASN entry needs the ASN database's answer.
        try {
            $answer['org'] = static::network_name($ip, $asn);
        } catch (Throwable $e) {
            static::__report($e, $ip);
            $complete = false;
        }

        if ($missing) {
            static::__trigger_contained($ip);
        }

        if ($complete) {
            RsxCache::set_persistent(self::CACHE_PREFIX . $ip, $answer, self::CACHE_SECONDS);
        }

        return $answer;
    }

    /**
     * lookup() as one line for display: the org when the address belongs to a listed network
     * ('AWS'); otherwise the place - 'Mountain View, California, USA' - with the unknown parts
     * left out (and a state equal to its city once); null when nothing is known. A plain
     * string: escape it on output.
     */
    public static function label(string $ip): ?string
    {
        $answer = static::lookup($ip);

        if ($answer['org'] !== null) {
            return $answer['org'];
        }

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
        return ['city' => null, 'state' => null, 'country' => null, 'org' => null];
    }

    /**
     * The notable-network list: config('rsx.geo_ip.networks') with entries set to null (an
     * application removing a shipped one) left out - key => short display name, a key being
     * 'AS<number>' or an IPv4 / IPv6 CIDR range. Throws naming the entry when a key is
     * neither, or a name is not a non-empty string of at most VALUE_MAX_LENGTH characters.
     *
     * @return array<string, string>
     */
    public static function networks(): array
    {
        $configured = config(self::NETWORKS_CONFIG) ?? [];
        if (!is_array($configured)) {
            throw new \InvalidArgumentException(self::NETWORKS_CONFIG . " must be an array of 'AS<number>' or CIDR range => 'Display name'.");
        }

        $networks = [];
        foreach ($configured as $key => $name) {
            if (!is_string($key) || (!preg_match('/^AS[1-9]\d{0,9}$/', $key) && static::__cidr_prefix($key) === null)) {
                throw new \InvalidArgumentException(self::NETWORKS_CONFIG . ': the key ' . var_export($key, true) . " is neither 'AS<number>' (e.g. 'AS16509') nor a CIDR range (e.g. '160.79.104.0/21').");
            }
            if ($name === null) {
                continue;
            }
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > self::VALUE_MAX_LENGTH) {
                throw new \InvalidArgumentException(self::NETWORKS_CONFIG . ".{$key} must be a non-empty name of at most " . self::VALUE_MAX_LENGTH . ' characters, or null to unlist it.');
            }
            $networks[$key] = trim($name);
        }

        return $networks;
    }

    /**
     * The listed name for $ip (canonical form): the most specific listed CIDR range containing
     * it, else its autonomous system's entry ($asn, from the ASN database), else null. Ranges
     * are matched by Symfony's IpUtils::checkIp() - the matcher the trusted-proxy list runs
     * on - for IPv4 and IPv6 alike.
     */
    public static function network_name(string $ip, ?int $asn): ?string
    {
        $networks = static::networks();

        $best_name = null;
        $best_prefix = -1;
        foreach ($networks as $key => $name) {
            $prefix = static::__cidr_prefix($key);
            if ($prefix !== null && $prefix > $best_prefix && IpUtils::checkIp($ip, $key)) {
                $best_name = $name;
                $best_prefix = $prefix;
            }
        }
        if ($best_name !== null) {
            return $best_name;
        }

        return $asn !== null ? ($networks["AS{$asn}"] ?? null) : null;
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
     * city / state / country from one DB-IP City record (null = the address is not in the
     * database). Anything that is not a non-empty string is null; each name is capped; the
     * country is the alpha-3 code of the record's alpha-2 iso_code (country_alpha3()).
     */
    public static function answer_from_city_record(mixed $record): array
    {
        if (!is_array($record)) {
            return ['city' => null, 'state' => null, 'country' => null];
        }

        return [
            'city' => static::__name($record['city']['names']['en'] ?? null),
            'state' => static::__name($record['subdivisions'][0]['names']['en'] ?? null),
            'country' => static::country_alpha3($record['country']['iso_code'] ?? null),
        ];
    }

    /** The autonomous system number of one DB-IP ASN record (null = not in the database). */
    public static function asn_from_record(mixed $record): ?int
    {
        $asn = is_array($record) ? ($record['autonomous_system_number'] ?? null) : null;

        return is_int($asn) && $asn > 0 ? $asn : null;
    }

    /**
     * The ISO 3166-1 alpha-3 code for an alpha-2 code, from the countries table, memoised per
     * process. A well-formed code with no row answers null and is REPORTED (once per process
     * per code): a gap in the reference data, never a failure of the lookup.
     */
    public static function country_alpha3(mixed $alpha2): ?string
    {
        if (!is_string($alpha2) || !preg_match('/^[A-Za-z]{2}$/', $alpha2)) {
            return null;
        }
        $alpha2 = strtoupper($alpha2);

        if (!array_key_exists($alpha2, self::$alpha3_by_alpha2)) {
            $alpha3 = Country_Model::query()->where('alpha2', $alpha2)->value('alpha3');
            self::$alpha3_by_alpha2[$alpha2] = is_string($alpha3) && $alpha3 !== '' ? $alpha3 : null;

            if (self::$alpha3_by_alpha2[$alpha2] === null) {
                app(ExceptionHandler::class)->report(new \RuntimeException(
                    "Rsx_Geo_Ip: DB-IP's country code '{$alpha2}' has no alpha-3 code in the countries table; the lookup answers country null."
                ));
            }
        }

        return self::$alpha3_by_alpha2[$alpha2];
    }

    /** The file lookups read for $database (DATABASE_CITY or DATABASE_ASN). */
    public static function database_file(string $database): string
    {
        return self::$database_files_for_tests[$database] ?? match ($database) {
            self::DATABASE_CITY => Rsx_Project_Paths::geo_ip_city_database_file(),
            self::DATABASE_ASN => Rsx_Project_Paths::geo_ip_asn_database_file(),
        };
    }

    /**
     * The installed $database's metadata, or null when there is no file. Throws when a file
     * is there but is not a readable MMDB.
     *
     * @return array{database_type: string, build_epoch: int, release: string, expires_at: int, expired: bool}|null
     *         release = the UTC year-month of the build ('2026-11'); expires_at = the end of
     *         its validity window (validity_ends()); expired = lookups treat it as absent
     */
    public static function installed_metadata(string $database): ?array
    {
        $file = static::database_file($database);
        if (!is_file($file)) {
            return null;
        }

        $reader = new Reader($file);
        try {
            $metadata = $reader->metadata();

            $build_epoch = (int) $metadata->buildEpoch;

            return [
                'database_type' => (string) $metadata->databaseType,
                'build_epoch' => $build_epoch,
                'release' => gmdate('Y-m', $build_epoch),
                'expires_at' => static::validity_ends($build_epoch),
                'expired' => static::is_expired($build_epoch),
            ];
        } finally {
            $reader->close();
        }
    }

    /** The Unix time a database built at $build_epoch stops answering (DATA_VALIDITY_DAYS later). */
    public static function validity_ends(int $build_epoch): int
    {
        return $build_epoch + self::DATA_VALIDITY_DAYS * 86400;
    }

    /** Whether a database built at $build_epoch is past its validity window. */
    public static function is_expired(int $build_epoch): bool
    {
        return static::now() >= static::validity_ends($build_epoch);
    }

    /** The current Unix time as the validity window and the health row read it (a test fixes it). */
    public static function now(): int
    {
        return self::$now_for_tests ?? time();
    }

    /**
     * Dispatch the update task when a database is not usable - unless a run of it was
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
     * Point lookups at other database files (null restores the installed one), and forget
     * the open readers and the per-process memories. Tests only.
     */
    public static function _set_database_files_for_tests(?string $city_file, ?string $asn_file): void
    {
        self::$database_files_for_tests = array_filter(
            [self::DATABASE_CITY => $city_file, self::DATABASE_ASN => $asn_file],
            fn ($file) => $file !== null
        );
        static::_reset_for_tests();
    }

    /** Measure the validity window against $epoch instead of the clock (null restores it). Tests only. */
    public static function _set_now_for_tests(?int $epoch): void
    {
        self::$now_for_tests = $epoch;
    }

    /** Forget the open readers, the country memo and the per-process trigger memory. Tests only. */
    public static function _reset_for_tests(): void
    {
        foreach (self::DATABASES as $database) {
            static::__reset_reader($database);
        }
        self::$alpha3_by_alpha2 = [];
        self::$trigger_considered = false;
    }

    /** The cache key an address's answer is stored under (tests read and seed it). */
    public static function _cache_key_for_tests(string $canonical_ip): string
    {
        return self::CACHE_PREFIX . $canonical_ip;
    }

    /**
     * The reader over $database's file, reopened when the file was replaced since it was
     * opened (a long-lived worker outlives an update); null when there is no file.
     */
    private static function __reader(string $database): ?Reader
    {
        $file = static::database_file($database);

        clearstatcache(true, $file);
        $stat = @stat($file);
        if ($stat === false) {
            static::__reset_reader($database);

            return null;
        }

        $identity = "{$stat['ino']}:{$stat['mtime']}:{$stat['size']}";
        if (!isset(self::$readers[$database]) || self::$reader_identities[$database] !== $identity) {
            static::__reset_reader($database);
            self::$readers[$database] = new Reader($file);
            self::$reader_identities[$database] = $identity;
        }

        return self::$readers[$database];
    }

    private static function __reset_reader(string $database): void
    {
        if (isset(self::$readers[$database])) {
            self::$readers[$database]->close();
        }
        unset(self::$readers[$database], self::$reader_identities[$database]);
    }

    /** The prefix length of a well-formed CIDR range ('160.79.104.0/21' -> 21), else null. */
    private static function __cidr_prefix(string $key): ?int
    {
        if (!preg_match('~^([0-9A-Fa-f.:]+)/(\d{1,3})$~', $key, $match)) {
            return null;
        }

        $prefix = (int) $match[2];
        if (filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $prefix <= 32 ? $prefix : null;
        }
        if (filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $prefix <= 128 ? $prefix : null;
        }

        return null;
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
            'org' => static::__name($cached['org'] ?? null),
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
