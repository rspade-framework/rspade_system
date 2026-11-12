<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Lib\Geo\Ip;

use Exception;
use MaxMind\Db\Reader;
use Throwable;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Lib\Geo\Ip\Rsx_Geo_Ip;

/**
 * Geo_Ip_Service - keeps the IP geolocation database (DB-IP City Lite) current.
 *
 * update() runs in the maintenance window, once per build (#[On_Build] - so rsx:clean, which
 * removes the file with the rest of tmp/, heals itself), and when Rsx_Geo_Ip::lookup() finds
 * no usable database (Rsx_Geo_Ip::maybe_trigger_update()). It is #[Exclusive('maintenance')]:
 * never two runs at once, never beside another maintenance task.
 *
 *   1. THE CHEAP LOCAL CHECK. The installed file is this month's release (the UTC month of
 *      its build epoch): "current", no network.
 *   2. THE DOWNLOAD PAGE (DOWNLOAD_PAGE_URL) names the published MMDB release and the SHA1 of
 *      its UNPACKED file. This month's release is taken, else the previous month's (a new
 *      month's release appears some time after the month begins); neither listed is a
 *      failure. The installed file already being that release ends the run.
 *   3. THE DOWNLOAD of dbip-city-lite-YYYY-MM.mmdb.gz into the database's own directory,
 *      heartbeating as data arrives. No timeout of ours: a stalled transfer stops the
 *      heartbeats and the task silence limit (rsx.tasks.silent_timeout) ends the run.
 *      (libcurl's own connect-phase default, 300 s, bounds establishing the connection only;
 *      nothing here sets or extends it.) The download and the unpacked file are both capped
 *      at MAX_BYTES - a size bound, never a time bound.
 *   4. VERIFICATION (verify_candidate()): the SHA1 of the unpacked file is the published one;
 *      the reader opens it; its database_type is DATABASE_TYPE; its build epoch falls in the
 *      release's month (UTC); SANITY_IP answers SANITY_COUNTRY_ISO.
 *   5. One rename() swaps it into place. Any failure discards the staged files, leaves the
 *      installed file as it was, and fails the run with the reason.
 */
class Geo_Ip_Service extends Rsx_Service_Abstract
{
    /** The page naming the current release and its checksums. */
    public const DOWNLOAD_PAGE_URL = 'https://db-ip.com/db/download/ip-to-city-lite';

    /** The release file; %s is the release month, YYYY-MM. */
    public const DOWNLOAD_URL = 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz';

    /** The metadata database_type of a DB-IP City Lite MMDB. */
    public const DATABASE_TYPE = 'DBIP-City-Lite';

    /** A well-known public address, and the country it must answer, checked before a swap. */
    public const SANITY_IP = '8.8.8.8';
    public const SANITY_COUNTRY_ISO = 'US';

    /** The size cap on the download and on the unpacked file (the release is ~130 MB unpacked). */
    public const MAX_BYTES = 1024 * 1024 * 1024;

    /** The size cap on the download page. */
    public const MAX_PAGE_BYTES = 4 * 1024 * 1024;

    /** Unpacking reads the gzip stream this many bytes at a time. */
    private const CHUNK_BYTES = 1024 * 1024;

    /** Downloaded bytes between two stop-request checks. */
    private const STOP_CHECK_BYTES = 8 * 1024 * 1024;

    /** Test seam: the HTML the download page answers, in place of fetching it. */
    private static ?string $download_page_for_tests = null;

    #[Task('Download the IP geolocation database (DB-IP City Lite) when a newer release is published')]
    #[Exclusive('maintenance')]
    #[Schedule('maintenance')]
    #[On_Build]
    public static function update(Task_Instance $task, array $params = [])
    {
        $target = Rsx_Geo_Ip::database_file();
        $this_month = gmdate('Y-m');
        $previous_month = gmdate('Y-m', strtotime(gmdate('Y-m-01') . ' -1 month'));

        // The task is exclusive, so a staged file found now is an orphan of a run that died.
        foreach (glob(dirname($target) . '/.{download,staged}-*', GLOB_BRACE) ?: [] as $orphan) {
            unlink($orphan);
            $task->stdout('Removed ' . basename($orphan) . ', left behind by an earlier run that did not finish.');
        }

        $task->status('Checking the installed IP geolocation database');
        $installed = static::installed_release($task);
        if ($installed === $this_month) {
            $task->stdout("The installed IP geolocation database is the {$installed} release, this month's; nothing to do.");
            $task->summary("Current ({$installed} release).");

            return null;
        }

        $task->status('Reading the DB-IP download page');
        $published = static::parse_published_releases(static::__download_page($task));
        $release = null;
        foreach ([$this_month, $previous_month] as $month) {
            if (isset($published[$month])) {
                $release = $month;
                break;
            }
        }
        if ($release === null) {
            throw new Exception(
                "The DB-IP download page (" . self::DOWNLOAD_PAGE_URL . ") lists no MMDB release for {$this_month} or {$previous_month}"
                . ($published === [] ? ' (it lists no MMDB release at all - the page format may have changed).' : ' (it lists ' . implode(', ', array_keys($published)) . ').')
            );
        }

        if ($installed === $release) {
            $task->stdout("The installed IP geolocation database is the {$installed} release, the latest DB-IP has published; nothing to do.");
            $task->summary("Current ({$installed} release; {$this_month} is not published yet).");

            return null;
        }

        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new Exception("Cannot create the IP geolocation directory {$directory}.");
        }

        $staged_gz = $directory . '/.download-' . bin2hex(random_bytes(6)) . '.mmdb.gz';
        try {
            $url = sprintf(self::DOWNLOAD_URL, $release);
            $task->status("Downloading the {$release} release");
            $bytes = static::__download_to_file($url, $staged_gz, $task);
            if ($bytes !== null) {
                $task->stdout("Downloaded {$url} (" . bytes_to_human($bytes) . ').');
            }

            if ($bytes === null || $task->is_stop_requested()) {
                $task->stdout('Stop requested - the download was discarded and the installed database left as it was.');
                $task->summary('Stopped before installing.');

                return null;
            }

            $task->status("Unpacking and verifying the {$release} release");
            static::install_from_gz($staged_gz, $release, $published[$release], $task);
        } finally {
            if (is_file($staged_gz)) {
                unlink($staged_gz);
            }
        }

        $task->stdout("Installed the {$release} release of DB-IP City Lite" . ($installed !== null ? " (replacing the {$installed} release)." : '.'));
        $task->summary("Installed the {$release} release.");

        return null;
    }

    /**
     * The installed database's release month, or null when none is installed. A file that is
     * there but does not open is reported on the run and treated as none, so it is replaced.
     */
    public static function installed_release(?Task_Instance $task = null): ?string
    {
        try {
            $metadata = Rsx_Geo_Ip::installed_metadata();
        } catch (Throwable $e) {
            $task?->stdout('The installed IP geolocation database does not open (' . $e->getMessage() . '); it will be replaced.');

            return null;
        }

        return $metadata['release'] ?? null;
    }

    /**
     * Every MMDB release the download page lists, with the SHA1 it publishes for the unpacked
     * file: ['2026-11' => '<40 hex>']. Read from each card holding a dbip-city-lite-YYYY-MM.mmdb.gz
     * link: the card must say Format MMDB and carry a SHA1SUM. A release without one is not listed.
     *
     * @return array<string, string>
     */
    public static function parse_published_releases(string $html): array
    {
        $releases = [];
        if (!preg_match_all('~https://download\.db-ip\.com/free/dbip-city-lite-(\d{4}-\d{2})\.mmdb\.gz~', $html, $links, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($links[1] as [$month, $offset]) {
            $card_start = strrpos(substr($html, 0, $offset), '<dl');
            if ($card_start === false) {
                continue;
            }
            $card = substr($html, $card_start, $offset - $card_start);
            if (!preg_match('~<dt>\s*Format\s*</dt>\s*<dd[^>]*>\s*MMDB\s*</dd>~i', $card)) {
                continue;
            }
            if (!preg_match('~<dt>\s*SHA1SUM\s*</dt>\s*<dd[^>]*>\s*([0-9a-fA-F]{40})\s*</dd>~', $card, $sha1)) {
                continue;
            }
            $releases[$month] = strtolower($sha1[1]);
        }

        return $releases;
    }

    /**
     * Unpack a downloaded release beside the installed database, verify it, and rename it into
     * place. Throws, leaving the installed file untouched and no staged file behind, on any
     * failure.
     */
    public static function install_from_gz(string $gz_path, string $release, string $expected_sha1, ?Task_Instance $task = null): void
    {
        $target = Rsx_Geo_Ip::database_file();
        $staged = dirname($target) . '/.staged-' . bin2hex(random_bytes(6)) . '.mmdb';

        try {
            $sha1 = static::unpack_gz($gz_path, $staged, self::MAX_BYTES, $task);
            static::verify_candidate($staged, $release, $expected_sha1, $sha1);

            if (!rename($staged, $target)) {
                throw new Exception("Cannot move the verified IP geolocation database into place at {$target}.");
            }
        } finally {
            if (is_file($staged)) {
                unlink($staged);
            }
        }
    }

    /**
     * Gunzip $gz_path into the new file $out_path, refusing past $max_bytes unpacked, and
     * answer the SHA1 of what was written.
     */
    public static function unpack_gz(string $gz_path, string $out_path, int $max_bytes, ?Task_Instance $task = null): string
    {
        $in = gzopen($gz_path, 'rb');
        if ($in === false) {
            throw new Exception("Cannot open the downloaded file {$gz_path} as gzip.");
        }

        try {
            $out = fopen($out_path, 'xb');
            if ($out === false) {
                throw new Exception("Cannot create {$out_path}.");
            }

            try {
                $hash = hash_init('sha1');
                $written = 0;
                while (!gzeof($in)) {
                    $chunk = gzread($in, self::CHUNK_BYTES);
                    if ($chunk === false) {
                        throw new Exception("Reading the gzip stream {$gz_path} failed: the download is corrupt.");
                    }
                    if ($chunk === '') {
                        break;
                    }

                    $written += strlen($chunk);
                    if ($written > $max_bytes) {
                        throw new Exception('The downloaded IP geolocation database unpacks to more than ' . bytes_to_human($max_bytes) . '; refused.');
                    }

                    hash_update($hash, $chunk);
                    if (fwrite($out, $chunk) !== strlen($chunk)) {
                        throw new Exception("Writing {$out_path} failed (is the disk full?).");
                    }

                    $task?->heartbeat();
                }
            } finally {
                fclose($out);
            }
        } finally {
            gzclose($in);
        }

        if ($written === 0) {
            throw new Exception("The downloaded file {$gz_path} unpacked to nothing.");
        }

        return hash_final($hash);
    }

    /**
     * Refuse a candidate database file unless it is the expected release: its SHA1 (computed
     * here when not given), its type, its build month and a sanity lookup. Throws naming the
     * failed check.
     */
    public static function verify_candidate(string $path, string $release, string $expected_sha1, ?string $actual_sha1 = null): void
    {
        $actual_sha1 ??= sha1_file($path);
        if (!hash_equals(strtolower($expected_sha1), (string) $actual_sha1)) {
            throw new Exception("The downloaded IP geolocation database's SHA1 is {$actual_sha1}, but DB-IP publishes {$expected_sha1} for the {$release} release; refused.");
        }

        $reader = new Reader($path);
        try {
            $metadata = $reader->metadata();
            if ($metadata->databaseType !== self::DATABASE_TYPE) {
                throw new Exception("The downloaded IP geolocation database is of type '{$metadata->databaseType}', not '" . self::DATABASE_TYPE . "'; refused.");
            }

            $built = gmdate('Y-m', (int) $metadata->buildEpoch);
            if ($built !== $release) {
                throw new Exception("The downloaded IP geolocation database was built " . gmdate('Y-m-d', (int) $metadata->buildEpoch) . ", outside its {$release} release month; refused.");
            }

            $record = $reader->get(self::SANITY_IP);
            $country = is_array($record) ? ($record['country']['iso_code'] ?? null) : null;
            if ($country !== self::SANITY_COUNTRY_ISO) {
                throw new Exception('The downloaded IP geolocation database places ' . self::SANITY_IP . ' in ' . var_export($country, true) . ', not ' . self::SANITY_COUNTRY_ISO . '; refused.');
            }
        } finally {
            $reader->close();
        }
    }

    /** Answer the download page from $html instead of the network (null restores it). Tests only. */
    public static function _set_download_page_for_tests(?string $html): void
    {
        self::$download_page_for_tests = $html;
    }

    /** The download page's HTML. */
    private static function __download_page(Task_Instance $task): string
    {
        if (self::$download_page_for_tests !== null) {
            return self::$download_page_for_tests;
        }

        $html = '';
        static::__fetch(self::DOWNLOAD_PAGE_URL, function (string $data) use (&$html, $task) {
            $html .= $data;
            if (strlen($html) > self::MAX_PAGE_BYTES) {
                throw new Exception('The DB-IP download page is larger than ' . bytes_to_human(self::MAX_PAGE_BYTES) . '; refused.');
            }
            $task->heartbeat();

            return true;
        });

        return $html;
    }

    /** Download $url into the new file $path; answers the byte count, or null when a stop was requested. */
    private static function __download_to_file(string $url, string $path, Task_Instance $task): ?int
    {
        $out = fopen($path, 'xb');
        if ($out === false) {
            throw new Exception("Cannot create {$path}.");
        }

        $bytes = 0;
        $next_stop_check = self::STOP_CHECK_BYTES;
        try {
            $completed = static::__fetch($url, function (string $data) use ($out, $path, &$bytes, &$next_stop_check, $task) {
                $bytes += strlen($data);
                if ($bytes > self::MAX_BYTES) {
                    throw new Exception('The IP geolocation download is larger than ' . bytes_to_human(self::MAX_BYTES) . '; refused.');
                }
                if (fwrite($out, $data) !== strlen($data)) {
                    throw new Exception("Writing {$path} failed (is the disk full?).");
                }
                $task->heartbeat();

                if ($bytes >= $next_stop_check) {
                    $next_stop_check += self::STOP_CHECK_BYTES;
                    if ($task->is_stop_requested()) {
                        return false;
                    }
                }

                return true;
            });
        } finally {
            fclose($out);
        }

        return $completed ? $bytes : null;
    }

    /**
     * GET an https URL, handing the body to $on_data as it arrives; $on_data answers false to
     * end the transfer early, and __fetch() then answers false. Redirects are followed (https
     * only); a non-2xx answer throws. NO TIMEOUT IS SET: CURLOPT_TIMEOUT stays 0 (none), and the
     * transfer runs as long as data keeps arriving.
     *
     * @param callable(string): bool $on_data
     */
    private static function __fetch(string $url, callable $on_data): bool
    {
        $curl = curl_init($url);
        $failure = null;
        $ended = false;

        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'RSpade IP geolocation update',
            CURLOPT_WRITEFUNCTION => function ($handle, string $data) use ($on_data, &$failure, &$ended) {
                // An exception cannot cross libcurl: it is carried out and rethrown below, and
                // answering a short count aborts the transfer.
                try {
                    if ($on_data($data) === false) {
                        $ended = true;

                        return 0;
                    }
                } catch (Throwable $e) {
                    $failure = $e;

                    return 0;
                }

                return strlen($data);
            },
        ]);

        $ok = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($failure !== null) {
            throw $failure;
        }
        if ($ended) {
            return false;
        }
        if ($ok === false) {
            throw new Exception("Fetching {$url} failed: {$error}");
        }
        if ($status < 200 || $status >= 300) {
            throw new Exception("Fetching {$url} answered HTTP {$status}.");
        }

        return true;
    }
}
