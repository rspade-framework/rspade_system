<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Sys\App\Sys\SystemInfo;

use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Health\Opcache_Advisory;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Rsx;

/**
 * The System Info screen's probes: what the machine, PHP and the RSpade container
 * look like from the PHP process answering the request.
 *
 * A READ-ONLY DIAGNOSTIC, so every probe fails SOFT: a /proc file that is absent or
 * unreadable (a non-Linux box), an OPcache that is not loaded or whose API is
 * restricted, a pool file that is not where the container puts it - each answers null
 * for the value it could not read, and the screen draws a dash. Each read is guarded
 * at the read (is_readable, the OPcache API restriction); nothing is wrapped in a blanket catch,
 * so a defect in this code still fails loud.
 *
 * WHAT IS DESCRIBED IS THE WEB TIER. The endpoint runs inside php-fpm, so the PHP
 * version, extensions, ini values and OPcache are the web SAPI's - the CLI's may
 * differ (OPcache in particular is normally off for the CLI).
 *
 * The parsers (parse_meminfo, parse_os_release, parse_mountinfo, parse_pool_config,
 * parse_supervisor_status) and select_disks() are pure functions of their input, so
 * the tests drive them with fixed text.
 */
class _Sys_System_Info
{
    /**
     * The php-fpm pools the RSpade container runs (resource/docker/php/www.conf and
     * ajax.conf, sized by resource/docker/sizing.sh): page requests and Ajax.
     */
    public const FPM_POOLS = [
        'www' => 'Web pool',
        'ajax' => 'Ajax pool',
    ];

    /** The pool settings shown, in display order. */
    public const FPM_KEYS = [
        'pm',
        'pm.max_children',
        'pm.start_servers',
        'pm.min_spare_servers',
        'pm.max_spare_servers',
        'pm.max_requests',
        'pm.process_idle_timeout',
        'user',
        'listen',
    ];

    /**
     * The supervisor control socket the RSpade container's supervisord.conf
     * (resource/docker/supervisor/supervisord.conf, [unix_http_server]) listens on.
     * Named explicitly rather than trusting the [supervisorctl] serverurl, which a
     * host's own config may point elsewhere.
     */
    public const SUPERVISOR_SOCKET = 'unix:///var/run/supervisor.sock';

    /**
     * The whole report.
     *
     * @return array {server, cpu_memory, disks, file_storage, php, opcache, extensions,
     *                container: {is_rspade_container, fpm_pools, services}}
     */
    public static function report(): array
    {
        $is_container = Rsx::is_rspade_container();

        return [
            'server' => static::server(),
            'cpu_memory' => static::cpu_memory(),
            'disks' => static::disks(),
            'file_storage' => static::file_storage(),
            'php' => static::php(),
            'opcache' => static::opcache(),
            'extensions' => static::extensions(),
            'container' => [
                'is_rspade_container' => $is_container,
                'fpm_pools' => $is_container ? static::fpm_pools() : null,
                'services' => $is_container ? static::services() : null,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Server / OS
    // -----------------------------------------------------------------------

    /**
     * @return array {os_name, hostname, kernel, uptime_seconds, uptime_human,
     *                load_average: [1m, 5m, 15m]|null, install_path}
     */
    public static function server(): array
    {
        $os_release = static::__read('/etc/os-release');
        $uptime_text = static::__read('/proc/uptime');

        $uptime_seconds = null;
        if ($uptime_text !== null && preg_match('/^\s*(\d+)(?:\.\d+)?\s/', $uptime_text, $m)) {
            $uptime_seconds = (int) $m[1];
        }

        $load = sys_getloadavg();

        $hostname = gethostname();

        return [
            'os_name' => $os_release !== null ? static::parse_os_release($os_release) : null,
            'hostname' => $hostname === false ? null : $hostname,
            'kernel' => php_uname('s') . ' ' . php_uname('r'),
            'uptime_seconds' => $uptime_seconds,
            'uptime_human' => $uptime_seconds === null ? null : duration_to_human($uptime_seconds),
            'load_average' => is_array($load) ? array_map(fn ($value) => round((float) $value, 2), $load) : null,
            'install_path' => static::project_root(),
        ];
    }

    /**
     * The project root - where system/ and rsx/ live, one directory above the Laravel
     * base path.
     */
    public static function project_root(): string
    {
        return dirname(base_path());
    }

    /**
     * The operating system's name from an os-release file: PRETTY_NAME, else NAME plus
     * VERSION, else null.
     */
    public static function parse_os_release(string $text): ?string
    {
        $values = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', trim($line), $m)) {
                $values[$m[1]] = trim($m[2], " \t\"'");
            }
        }

        if (($values['PRETTY_NAME'] ?? '') !== '') {
            return $values['PRETTY_NAME'];
        }

        if (($values['NAME'] ?? '') !== '') {
            return trim($values['NAME'] . ' ' . ($values['VERSION'] ?? ''));
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // CPU and memory
    // -----------------------------------------------------------------------

    /**
     * Memory in BYTES from /proc/meminfo, the way free(1) reads it: used is what is
     * neither free nor buffers/cache. In a container /proc/meminfo describes the HOST.
     *
     * @return array {cores, cpu_model, mem_total, mem_used, mem_cached, mem_free,
     *                mem_available, swap_total, swap_used, swap_free}
     */
    public static function cpu_memory(): array
    {
        $cpuinfo = static::__read('/proc/cpuinfo');
        $meminfo = static::__read('/proc/meminfo');

        $cores = null;
        $cpu_model = null;
        if ($cpuinfo !== null) {
            $count = preg_match_all('/^processor\s*:/m', $cpuinfo);
            $cores = $count > 0 ? $count : null;
            if (preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $m)) {
                $cpu_model = trim($m[1]);
            }
        }

        return array_merge(
            ['cores' => $cores, 'cpu_model' => $cpu_model],
            static::memory_figures($meminfo !== null ? static::parse_meminfo($meminfo) : [])
        );
    }

    /**
     * /proc/meminfo as key => bytes (its kB figures multiplied out).
     *
     * @return array<string,int>
     */
    public static function parse_meminfo(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^(\w+(?:\(\w+\))?):\s+(\d+)(?:\s+kB)?\s*$/', $line, $m)) {
                $out[$m[1]] = (int) $m[2] * (str_contains($line, 'kB') ? 1024 : 1);
            }
        }

        return $out;
    }

    /**
     * The memory figures from parsed meminfo; each null when its inputs are missing.
     *
     * @param array<string,int> $mem
     */
    public static function memory_figures(array $mem): array
    {
        $total = $mem['MemTotal'] ?? null;
        $free = $mem['MemFree'] ?? null;

        $cached = null;
        if (isset($mem['Buffers'], $mem['Cached'])) {
            $cached = $mem['Buffers'] + $mem['Cached'] + ($mem['SReclaimable'] ?? 0);
        }

        $used = ($total !== null && $free !== null && $cached !== null) ? max(0, $total - $free - $cached) : null;

        $swap_total = $mem['SwapTotal'] ?? null;
        $swap_free = $mem['SwapFree'] ?? null;

        return [
            'mem_total' => $total,
            'mem_used' => $used,
            'mem_cached' => $cached,
            'mem_free' => $free,
            'mem_available' => $mem['MemAvailable'] ?? null,
            'swap_total' => $swap_total,
            'swap_used' => ($swap_total !== null && $swap_free !== null) ? $swap_total - $swap_free : null,
            'swap_free' => $swap_free,
        ];
    }

    // -----------------------------------------------------------------------
    // Disk
    // -----------------------------------------------------------------------

    /**
     * Free space on the project root, the filesystem root and the storage root, each
     * with the device it lives on - a path on the SAME device as the project root is
     * omitted (select_disks()).
     *
     * @return array [{key, label, path, device, fs_type, total, free, used}]
     */
    public static function disks(): array
    {
        $mountinfo = static::__read('/proc/self/mountinfo');
        $mounts = $mountinfo !== null ? static::parse_mountinfo($mountinfo) : [];

        $entries = [];
        foreach ([
            'project' => ['Project root', static::project_root()],
            'root' => ['Filesystem root', '/'],
            'storage' => ['Storage', Rsx_Project_Paths::storage_root()],
        ] as $key => [$label, $path]) {
            $entries[] = static::disk_entry($key, $label, $path, $mounts);
        }

        return static::select_disks($entries);
    }

    /**
     * One path's disk facts. `dev` is the path's st_dev - the identity select_disks()
     * compares - and `device` / `fs_type` the mount the path sits under (the longest
     * mount point that prefixes its real path), as /proc/self/mountinfo names it.
     */
    public static function disk_entry(string $key, string $label, string $path, array $mounts): array
    {
        $real = is_dir($path) ? rsxrealpath($path) : false;
        $stat = $real !== false ? stat($real) : false;
        $total = $real !== false ? disk_total_space($real) : false;
        $free = $real !== false ? disk_free_space($real) : false;
        $mount = $real !== false ? static::mount_for($real, $mounts) : null;

        return [
            'key' => $key,
            'label' => $label,
            'path' => $real !== false ? $real : $path,
            'dev' => $stat !== false ? (int) $stat['dev'] : null,
            'device' => $mount['source'] ?? null,
            'fs_type' => $mount['fs_type'] ?? null,
            'mount_point' => $mount['mount_point'] ?? null,
            'total' => $total !== false ? (int) $total : null,
            'free' => $free !== false ? (int) $free : null,
            'used' => ($total !== false && $free !== false) ? (int) ($total - $free) : null,
        ];
    }

    /**
     * Which entries to show. The project root always; any other entry whose device
     * (st_dev) is the project root's is dropped, because the project root already
     * speaks for that device. An entry whose device could not be read is kept - it
     * cannot be shown to be the same. `dev` is removed from what is returned.
     *
     * @param array $entries [{key, dev, ...}], the 'project' entry among them
     * @return array the kept entries, in their given order
     */
    public static function select_disks(array $entries): array
    {
        $project_dev = null;
        foreach ($entries as $entry) {
            if ($entry['key'] === 'project') {
                $project_dev = $entry['dev'];
            }
        }

        $out = [];
        foreach ($entries as $entry) {
            $same_device = $entry['key'] !== 'project'
                && $project_dev !== null
                && $entry['dev'] !== null
                && $entry['dev'] === $project_dev;

            if (!$same_device) {
                unset($entry['dev']);
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * /proc/self/mountinfo as [{mount_point, fs_type, source}].
     *
     * Line shape: `id parent major:minor root mount_point options [optional...] - fs_type source super_options`;
     * the mount point is octal-escaped (a space is \040).
     */
    public static function parse_mountinfo(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $halves = explode(' - ', $line, 2);
            if (count($halves) !== 2) {
                continue;
            }

            $left = explode(' ', $halves[0]);
            $right = explode(' ', $halves[1]);
            if (count($left) < 5 || count($right) < 2) {
                continue;
            }

            $out[] = [
                'mount_point' => stripcslashes($left[4]),
                'fs_type' => $right[0],
                'source' => stripcslashes($right[1]),
            ];
        }

        return $out;
    }

    /**
     * The mount a real path sits under: the longest mount point prefixing it (the
     * later line wins a tie, as a later mount over the same point shadows the earlier).
     */
    public static function mount_for(string $real_path, array $mounts): ?array
    {
        $best = null;
        foreach ($mounts as $mount) {
            $point = rtrim($mount['mount_point'], '/');
            $is_prefix = $point === '' || $real_path === $point || str_starts_with($real_path, $point . '/');
            if ($is_prefix && ($best === null || strlen($point) >= strlen(rtrim($best['mount_point'], '/')))) {
                $best = $mount;
            }
        }

        return $best;
    }

    /**
     * The blob store's size: the SUM of every stored blob's size, from the DATABASE
     * (_file_storage.size) - File_Storage_Model::usage_report()'s `stored` figure,
     * the one place it is computed. It is what the store holds, not a measurement of
     * the disk.
     *
     * @return array {bytes, blobs}
     */
    public static function file_storage(): array
    {
        $stored = File_Storage_Model::usage_report()['stored'];

        return ['bytes' => (int) $stored['bytes'], 'blobs' => (int) $stored['blobs']];
    }

    // -----------------------------------------------------------------------
    // PHP
    // -----------------------------------------------------------------------

    /**
     * @return array {version, sapi, zend_version, ini_file, memory_limit,
     *                max_execution_time, upload_max_filesize, post_max_size,
     *                date_timezone, display_errors}
     */
    public static function php(): array
    {
        $ini_file = php_ini_loaded_file();
        $ini = fn (string $key) => ($value = ini_get($key)) === false ? null : $value;

        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'zend_version' => zend_version(),
            'ini_file' => $ini_file === false ? null : $ini_file,
            'memory_limit' => $ini('memory_limit'),
            'max_execution_time' => $ini('max_execution_time'),
            'upload_max_filesize' => $ini('upload_max_filesize'),
            'post_max_size' => $ini('post_max_size'),
            'date_timezone' => date_default_timezone_get(),
            'display_errors' => $ini('display_errors'),
        ];
    }

    /**
     * Loaded extensions, each list sorted case-insensitively.
     *
     * @return array {extensions: string[], zend_extensions: string[]}
     */
    public static function extensions(): array
    {
        $extensions = get_loaded_extensions();
        $zend = get_loaded_extensions(true);
        natcasesort($extensions);
        natcasesort($zend);

        return ['extensions' => array_values($extensions), 'zend_extensions' => array_values($zend)];
    }

    /**
     * OPcache's configuration and live statistics, or null when the extension is not
     * loaded. `enabled` is the ini setting this process applies; `status` is null when
     * the cache is off for this process or its API is restricted (opcache.restrict_api)
     * from this script.
     *
     * @return array|null {enabled, restricted, config: {...}, status: null|{...}}
     */
    public static function opcache(): ?array
    {
        $ini = fn (string $key) => ($value = ini_get($key)) === false ? null : $value;

        $config = [
            'validate_timestamps' => Opcache_Advisory::_ini_enabled($ini('opcache.validate_timestamps')),
            'revalidate_freq' => static::__int_or_null($ini('opcache.revalidate_freq')),
            'memory_consumption' => static::__int_or_null($ini('opcache.memory_consumption')) !== null
                ? (int) $ini('opcache.memory_consumption') * 1048576
                : null,
            'interned_strings_buffer' => static::__int_or_null($ini('opcache.interned_strings_buffer')) !== null
                ? (int) $ini('opcache.interned_strings_buffer') * 1048576
                : null,
            'max_accelerated_files' => static::__int_or_null($ini('opcache.max_accelerated_files')),
            'jit' => $ini('opcache.jit'),
            'jit_buffer_size' => $ini('opcache.jit_buffer_size'),
            'enable_cli' => Opcache_Advisory::_ini_enabled($ini('opcache.enable_cli')),
        ];

        $restricted = static::opcache_api_restricted((string) $ini('opcache.restrict_api'), (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

        $status = null;
        if (!$restricted) {
            $raw = opcache_get_status(false);
            if (is_array($raw)) {
                $status = static::opcache_status_figures($raw);
            }
        }

        return [
            'enabled' => Opcache_Advisory::is_enabled(),
            'restricted' => $restricted,
            'config' => $config,
            'status' => $status,
        ];
    }

    /**
     * Whether opcache.restrict_api refuses the API to this script: set, and the
     * running script's path does not start with it (PHP's own rule).
     */
    public static function opcache_api_restricted(string $restrict_api, string $script_filename): bool
    {
        return $restrict_api !== '' && !str_starts_with($script_filename, $restrict_api);
    }

    /**
     * The figures shown from an opcache_get_status() answer.
     */
    public static function opcache_status_figures(array $raw): array
    {
        $memory = $raw['memory_usage'] ?? [];
        $stats = $raw['opcache_statistics'] ?? [];
        $strings = $raw['interned_strings_usage'] ?? [];
        $jit = $raw['jit'] ?? [];

        $hits = isset($stats['hits']) ? (int) $stats['hits'] : null;
        $misses = isset($stats['misses']) ? (int) $stats['misses'] : null;

        return [
            'opcache_enabled' => (bool) ($raw['opcache_enabled'] ?? false),
            'cache_full' => (bool) ($raw['cache_full'] ?? false),
            'used_memory' => isset($memory['used_memory']) ? (int) $memory['used_memory'] : null,
            'free_memory' => isset($memory['free_memory']) ? (int) $memory['free_memory'] : null,
            'wasted_memory' => isset($memory['wasted_memory']) ? (int) $memory['wasted_memory'] : null,
            'interned_used' => isset($strings['used_memory']) ? (int) $strings['used_memory'] : null,
            'cached_scripts' => isset($stats['num_cached_scripts']) ? (int) $stats['num_cached_scripts'] : null,
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate' => isset($stats['opcache_hit_rate']) ? round((float) $stats['opcache_hit_rate'], 2) : null,
            'start_time' => isset($stats['start_time']) ? date('c', (int) $stats['start_time']) : null,
            'restarts' => (int) ($stats['oom_restarts'] ?? 0) + (int) ($stats['hash_restarts'] ?? 0) + (int) ($stats['manual_restarts'] ?? 0),
            'jit_enabled' => isset($jit['enabled']) ? (bool) $jit['enabled'] : null,
        ];
    }

    // -----------------------------------------------------------------------
    // The RSpade container
    // -----------------------------------------------------------------------

    /**
     * The settings of each php-fpm pool the container runs, read from the pool files
     * of THIS PHP version (/etc/php/<major.minor>/fpm/pool.d/<pool>.conf, where the
     * Dockerfile installs them). A pool whose file is missing or unreadable answers
     * `settings` null; a setting the file does not declare answers null.
     *
     * @return array [{pool, label, path, settings: null|{key => value|null}}]
     */
    public static function fpm_pools(): array
    {
        $dir = '/etc/php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '/fpm/pool.d';

        $out = [];
        foreach (self::FPM_POOLS as $pool => $label) {
            $path = $dir . '/' . $pool . '.conf';
            $text = static::__read($path);

            $settings = null;
            if ($text !== null) {
                $parsed = static::parse_pool_config($text);
                $settings = [];
                foreach (self::FPM_KEYS as $key) {
                    $settings[$key] = $parsed[$key] ?? null;
                }
            }

            $out[] = ['pool' => $pool, 'label' => $label, 'path' => $path, 'settings' => $settings];
        }

        return $out;
    }

    /**
     * A php-fpm pool file as key => value: `key = value` lines, comments (`;`) and
     * section headers skipped; a later line wins over an earlier one, as in php-fpm.
     *
     * @return array<string,string>
     */
    public static function parse_pool_config(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#' || $line[0] === '[') {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_.\[\]]+)\s*=\s*(.*)$/', $line, $m)) {
                $out[$m[1]] = trim($m[2], " \t\"'");
            }
        }

        return $out;
    }

    /**
     * The services supervisor runs, as `supervisorctl status` reports them, or null
     * when it cannot be asked (no supervisorctl, the socket refused or unreadable to
     * this process's user, nothing parseable in the answer).
     *
     * supervisorctl exits non-zero when any program is not RUNNING, so the exit code
     * is not the test of an answer - the parsed lines are.
     *
     * @return array|null [{name, state, description}]
     */
    public static function services(): ?array
    {
        $output = [];
        $return_var = 0;
        exec_safe('supervisorctl -s ' . escapeshellarg(self::SUPERVISOR_SOCKET) . ' status', $output, $return_var);

        $services = static::parse_supervisor_status(implode("\n", $output));

        return $services === [] ? null : $services;
    }

    /**
     * `supervisorctl status` output as [{name, state, description}]; lines that are not
     * a program's status (an error message) are skipped.
     */
    public static function parse_supervisor_status(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^(\S+)\s+(RUNNING|STARTING|BACKOFF|STOPPING|STOPPED|EXITED|FATAL|UNKNOWN)\b\s*(.*)$/', trim($line), $m)) {
                $out[] = ['name' => $m[1], 'state' => $m[2], 'description' => trim($m[3])];
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------------

    /**
     * A file's contents, or null when it is absent, unreadable or empty.
     */
    private static function __read(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $text = file_get_contents($path);

        return ($text === false || $text === '') ? null : $text;
    }

    private static function __int_or_null($value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
