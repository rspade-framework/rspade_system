<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * @REALPATH-EXCEPTION - This class MANAGES the system/.env symlink: it must
 * deliberately FOLLOW symlinks to compare resolved targets (rsxrealpath, which
 * does not follow symlinks, would defeat the healer's core comparison).
 */

namespace App\RSpade\Core\Prod;

use RuntimeException;

/**
 * Maintains the ".env symlink invariant".
 *
 * The intended layout is: base_path('.env') (system/.env) is a SYMLINK to the
 * project-root .env (dirname(base_path()).'/.env'; httpdocs/.env in deployed
 * layouts). Laravel/Dotenv loads base_path('.env'); when that path is a symlink
 * to the root file there is exactly ONE authoritative .env and edits to either
 * path are the same edit.
 *
 * Deploys and clones frequently materialize the symlink into a real FILE. After
 * that, the two files drift: Laravel reads only system/.env, so edits to the
 * root .env become inert (an entire realtime/OneDrive config block once sat
 * unread this way). A naive re-symlink would silently DISCARD keys that live only
 * in system/.env (RSX_MODE historically lived there).
 *
 * heal() restores the invariant safely:
 *   - the ROOT .env is authoritative; on a merge conflict the ROOT value WINS and
 *     the discarded system/.env value is REPORTED (never silently dropped);
 *   - keys unique to system/.env are APPENDED to the root .env under a dated
 *     marker comment;
 *   - system/.env is then replaced by a RELATIVE symlink (../.env) so it is
 *     portable across layouts and mount moves.
 *
 * This class is the reason Rsx_Prod_Env's single-file base_path('.env') read/write
 * is correct by construction: once healed, that one path IS the root file.
 */
class Rsx_Env_Symlink
{
    /**
     * Suffix for the safety copy written before a real system/.env file is
     * replaced. Contains secrets - kept 0600 and outside any web-servable root.
     */
    public const BACKUP_SUFFIX = '.replaced_by_healer';

    /**
     * Test seam: override the system/.env path (defaults to base_path('.env')).
     */
    protected static ?string $_system_env_override = null;

    /**
     * Test seam: override the root .env path (defaults to dirname(base_path())/.env).
     */
    protected static ?string $_root_env_override = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Restore the .env symlink invariant, mutating the filesystem as required.
     *
     * @return array {
     *   status: already_healthy|healed|created,
     *   merged_keys: string[],
     *   overridden_keys: array<array{key:string,system_value:string,root_value:string}>,
     *   actions: string[],
     *   backup_path: ?string
     * }
     */
    public static function heal(): array
    {
        $plan = self::__plan();
        $state = $plan['state'];
        $system_env = $plan['system_env'];
        $root_env = $plan['root_env'];

        $report = [
            'status' => 'already_healthy',
            'merged_keys' => [],
            'overridden_keys' => [],
            'actions' => [],
            'backup_path' => null,
        ];

        if ($state === 'already_healthy') {
            return $report;
        }

        if ($state === 'both_missing') {
            throw new RuntimeException(
                'env healer: neither ' . $root_env . ' nor ' . $system_env
                . ' exists - the application is unbootable. The healer maintains the'
                . ' .env symlink invariant; it does not create an initial .env.'
            );
        }

        if ($state === 'wrong_symlink') {
            $target = self::__relative_link_target($system_env, $root_env);
            self::__install_symlink($system_env, $target);
            $report['status'] = 'healed';
            $report['actions'][] = 'Repointed system/.env symlink to ' . $target
                . ' (was -> ' . $plan['current_link_target'] . ').';

            return $report;
        }

        if ($state === 'system_missing') {
            $target = self::__relative_link_target($system_env, $root_env);
            self::__install_symlink($system_env, $target);
            $report['status'] = 'healed';
            $report['actions'][] = 'Created system/.env symlink -> ' . $target
                . ' (root .env is authoritative).';

            return $report;
        }

        if ($state === 'root_missing') {
            // Root .env absent: MOVE the system/.env content to the root, then symlink.
            $report['backup_path'] = self::__backup($system_env);
            self::__atomic_write($root_env, file_get_contents($system_env));
            $target = self::__relative_link_target($system_env, $root_env);
            self::__install_symlink($system_env, $target);
            $report['status'] = 'created';
            $report['actions'][] = 'Root .env was missing: moved system/.env content to ' . $root_env . '.';
            $report['actions'][] = 'Replaced system/.env with symlink -> ' . $target . '.';

            return $report;
        }

        // state === 'regular_file': merge unique keys into the root (root wins),
        // then replace system/.env with the symlink.
        $report['merged_keys'] = $plan['merged_keys'];
        $report['overridden_keys'] = $plan['overridden_keys'];
        $report['backup_path'] = self::__backup($system_env);

        if (!empty($plan['merged_lines'])) {
            self::__append_block($root_env, $plan['merged_lines']);
            $report['actions'][] = 'Appended ' . count($plan['merged_lines'])
                . ' key(s) unique to system/.env into ' . $root_env . '.';
        }
        if (!empty($plan['overridden_keys'])) {
            $report['actions'][] = count($plan['overridden_keys'])
                . ' conflicting key(s) kept the root value; system values discarded (see overridden_keys).';
        }

        $target = self::__relative_link_target($system_env, $root_env);
        self::__install_symlink($system_env, $target);
        $report['actions'][] = 'Replaced system/.env with symlink -> ' . $target . '.';
        $report['status'] = 'healed';

        return $report;
    }

    /**
     * The DEVELOPER-BOOTSTRAP heal: get this environment's .env into a state the
     * application can boot from, and keep it in step with .env.dist.
     *
     * This is the ONE implementation of .env recreation and key synchronization.
     * It is what `rsx:env:heal` runs, what both entrypoints run pre-boot, and
     * what the container entrypoint invokes. heal() above remains the symlink
     * invariant on its own (step 3 here calls it).
     *
     * The order matters and is fixed:
     *
     *   1. .env.dist missing            -> THROW (it is the tracked canonical file)
     *   1b. .env.encrypted, no .env     -> THROW (never create a .env over an
     *                                      encrypted one; decrypt it first)
     *   2. .env missing                 -> whole-file copy of .env.dist
     *   3. symlink invariant            -> heal()
     *   4. read RSX_MODE                -> development vs debug/production
     *   5. key sync (DEVELOPMENT ONLY)  -> system/.env.dist -> .env.dist -> .env,
     *                                      missing KEYS only, values never touched.
     *                                      Runs BEFORE validation so a newly
     *                                      introduced required key can arrive here.
     *   6. credential validation        -> DB_* / REDIS_HOST present (all modes)
     *   7. APP_KEY                      -> minted in development, fatal outside it
     *
     * There is deliberately NO production-protection cleverness: this is the tool
     * that makes a new developer environment work, and a production box that has
     * lost its .env has an administrator problem, not a framework problem.
     *
     * @return array {
     *   status: string,
     *   actions: string[],
     *   synced_keys: array{root_dist: string[], root_env: string[]},
     *   app_key_minted: bool,
     *   merged_keys: string[], overridden_keys: array, backup_path: ?string
     * }
     */
    public static function full_heal(): array
    {
        $root_env = self::__root_env_path();
        $root_dist = self::__root_dist_path();
        $system_dist = self::__system_dist_path();

        $report = [
            'status' => 'already_healthy',
            'actions' => [],
            'synced_keys' => ['root_dist' => [], 'root_env' => []],
            'app_key_minted' => false,
            'merged_keys' => [],
            'overridden_keys' => [],
            'backup_path' => null,
        ];

        // 1. The tracked canonical defaults must exist.
        if (!is_file($root_dist)) {
            throw new RuntimeException(
                'env heal: ' . $root_dist . ' is missing.' . "\n\n"
                . '  It is RSpade convention that every RSpade project carries .env.dist at the' . "\n"
                . '  project root: the TRACKED set of default settings for .env, from which a' . "\n"
                . '  missing .env is created and into which every new configuration key is added.' . "\n\n"
                . '  Recover it from the framework copy, then review it:' . "\n"
                . '      cp system/.env.dist .env.dist' . "\n"
            );
        }

        // 1b. An ENCRYPTED .env with no .env beside it. Creating one from
        //     .env.dist here would be the worst possible outcome: the box boots,
        //     looks configured, and quietly runs on default credentials while the
        //     real configuration sits encrypted one filename away. This throws in
        //     EVERY mode - with no .env there is no RSX_MODE to read, so the mode
        //     is unknowable at this point.
        if (is_file(self::__root_env_encrypted_path()) && !is_file($root_env)) {
            throw new RuntimeException(
                'env heal: .env is encrypted (' . self::__root_env_encrypted_path()
                . ' present, ' . $root_env . ' absent).' . "\n\n"
                . '  RSpade never creates a .env over an encrypted one. Decrypt it before' . "\n"
                . '  booting:' . "\n\n"
                . '      php artisan env:decrypt --key=<key>' . "\n\n"
                . '  (or set LARAVEL_ENV_ENCRYPTION_KEY and run it with no --key). In' . "\n"
                . '  development the file must be decrypted on disk.' . "\n"
            );
        }

        // 2. No .env at all: this environment has never been set up. Copy the
        //    whole file verbatim - values included, comments included.
        if (!is_file($root_env)) {
            if (!copy($root_dist, $root_env)) {
                throw new RuntimeException('env heal: failed to create ' . $root_env . ' from ' . $root_dist . '.');
            }
            $report['status'] = 'created';
            $report['actions'][] = 'Created .env from .env.dist.';
        }

        // 3. The symlink invariant, unchanged.
        $link_report = self::heal();
        $report['merged_keys'] = $link_report['merged_keys'];
        $report['overridden_keys'] = $link_report['overridden_keys'];
        $report['backup_path'] = $link_report['backup_path'];
        foreach ($link_report['actions'] as $action) {
            $report['actions'][] = $action;
        }
        if ($link_report['status'] !== 'already_healthy' && $report['status'] === 'already_healthy') {
            $report['status'] = $link_report['status'];
        }

        // 4. Mode. An absent RSX_MODE is development, matching the framework default.
        $is_development = self::__is_development_env($root_env);

        // 5. Key sync - development only.
        if ($is_development) {
            self::__sync_keys($root_env, $root_dist, $system_dist, $report);
        }

        // 6. Credentials - every mode.
        self::__validate_credentials($root_env);

        // 7. APP_KEY.
        self::__ensure_app_key($root_env, $is_development, $report);

        if (!empty($report['actions']) && $report['status'] === 'already_healthy') {
            $report['status'] = 'healed';
        }

        self::__touch_stamp();

        return $report;
    }

    /**
     * The PRE-BOOT entry point: full_heal(), skipped when nothing has changed.
     *
     * Development runs this on EVERY boot, so the common path must cost close to
     * nothing. The stamp under storage/rsx-tmp is that: it records the size and
     * mtime of every file the heal reads, and matching it exactly means the last
     * heal already saw this input. A missing or differing stamp means the heal
     * runs.
     *
     * Outside development the heal runs only when .env is absent - a configured
     * box is not re-examined on every request.
     *
     * @return array The full_heal() report, or ['status' => 'skipped'].
     */
    public static function boot_heal(): array
    {
        $root_env = self::__root_env_path();

        if (is_file($root_env) && self::__stamp_is_fresh()) {
            return ['status' => 'skipped', 'actions' => []];
        }

        if (is_file($root_env) && !self::__is_development_env($root_env)) {
            // Configured, non-development: nothing to do, and say so cheaply next boot.
            self::__touch_stamp();

            return ['status' => 'skipped', 'actions' => []];
        }

        return self::full_heal();
    }

    // -------------------------------------------------------------------------
    // Bootstrap heal internals
    // -------------------------------------------------------------------------

    /**
     * Is RSX_MODE in this file development? An absent key is development.
     */
    protected static function __is_development_env(string $root_env): bool
    {
        $data = self::__parse_env_lines((string) file_get_contents($root_env));
        $mode = strtolower(trim($data['keys']['RSX_MODE'] ?? ''));

        return $mode === '' || $mode === 'development' || $mode === 'dev';
    }

    /**
     * Propagate KEYS - never values - down the chain:
     *   system/.env.dist -> .env.dist  and  -> .env
     *   .env.dist        -> .env
     *
     * A key already present anywhere is left exactly as it is, whatever its
     * value; nothing is deleted, nothing is reordered, and the source line is
     * appended verbatim. system/.env.dist exists downstream only (it is the
     * framework's shipped copy); this monorepo has none and the first pass is
     * simply skipped.
     */
    protected static function __sync_keys(string $root_env, string $root_dist, string $system_dist, array &$report): void
    {
        $root_dist_data = self::__parse_env_lines((string) file_get_contents($root_dist));
        $root_env_data = self::__parse_env_lines((string) file_get_contents($root_env));

        $to_dist = [];
        $to_env = [];

        if (is_file($system_dist)) {
            $system_dist_data = self::__parse_env_lines((string) file_get_contents($system_dist));
            foreach ($system_dist_data['lines'] as $key => $line) {
                if (!array_key_exists($key, $root_dist_data['keys'])) {
                    $to_dist[$key] = $line;
                }
                if (!array_key_exists($key, $root_env_data['keys'])) {
                    $to_env[$key] = $line;
                }
            }
        }

        foreach ($root_dist_data['lines'] as $key => $line) {
            if (!array_key_exists($key, $root_env_data['keys']) && !isset($to_env[$key])) {
                $to_env[$key] = $line;
            }
        }

        $marker = '# added by rsx env heal (' . date('Y-m-d') . ')';

        if (!empty($to_dist)) {
            self::__append_block($root_dist, array_values($to_dist), $marker);
            $report['synced_keys']['root_dist'] = array_keys($to_dist);
            $report['actions'][] = 'Added ' . count($to_dist) . ' key(s) to .env.dist: '
                . implode(', ', array_keys($to_dist)) . '.';
        }

        if (!empty($to_env)) {
            self::__append_block($root_env, array_values($to_env), $marker);
            $report['synced_keys']['root_env'] = array_keys($to_env);
            $report['actions'][] = 'Added ' . count($to_env) . ' key(s) to .env: '
                . implode(', ', array_keys($to_env)) . '.';
        }
    }

    /**
     * The keys without which the application cannot connect to anything. Present
     * is the requirement; a value is required only where an empty one is
     * meaningless (an empty DB_PASSWORD and a passwordless Redis are both legal).
     */
    protected static function __validate_credentials(string $root_env): void
    {
        $data = self::__parse_env_lines((string) file_get_contents($root_env));

        $missing = [];
        foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'REDIS_HOST'] as $key) {
            if (!array_key_exists($key, $data['keys'])) {
                $missing[] = $key;
            }
        }

        $empty = [];
        foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME'] as $key) {
            if (array_key_exists($key, $data['keys']) && trim($data['keys'][$key]) === '') {
                $empty[] = $key;
            }
        }

        if (empty($missing) && empty($empty)) {
            return;
        }

        $message = 'env heal: ' . $root_env . ' cannot connect this application to its services.' . "\n";
        if (!empty($missing)) {
            $message .= "\n  Missing key(s): " . implode(', ', $missing) . "\n";
        }
        if (!empty($empty)) {
            $message .= "\n  Present but empty: " . implode(', ', $empty) . "\n";
        }
        $message .= "\n  Set them in .env. The defaults for the development container are in .env.dist.\n";

        throw new RuntimeException($message);
    }

    /**
     * APP_KEY: minted here in development, fatal outside it.
     *
     * An application key is 32 random bytes, base64-encoded - that is the whole
     * definition, and it is generated directly rather than through
     * `artisan key:generate`. The container entrypoint makes the same key the
     * same way in shell, and for the same reason: key:generate boots the entire
     * framework, which wants Redis, the database and a provisioned schema, while
     * two services refuse to start WITHOUT a key. Generating it directly breaks
     * that circle.
     */
    protected static function __ensure_app_key(string $root_env, bool $is_development, array &$report): void
    {
        $data = self::__parse_env_lines((string) file_get_contents($root_env));
        $app_key = trim($data['keys']['APP_KEY'] ?? '');

        if ($app_key !== '') {
            return;
        }

        if (!$is_development) {
            throw new RuntimeException(
                'env heal: APP_KEY is empty in ' . $root_env . ' and this is not a development'
                . ' environment.' . "\n\n"
                . '  A key is never invented for a debug or production box: minting one here would'
                . "\n" . '  silently invalidate every encrypted value and signed cookie made with the'
                . "\n" . '  key that went missing. Restore the original key, or set a new one'
                . "\n" . '  deliberately, knowing that cost.' . "\n"
            );
        }

        self::__set_env_value($root_env, 'APP_KEY', 'base64:' . base64_encode(random_bytes(32)));
        $report['app_key_minted'] = true;
        $report['actions'][] = 'Minted APP_KEY (it was empty).';
    }

    /**
     * Set ONE key in an environment file: replace every existing definition with
     * a single line, or append the line when the key is absent.
     */
    protected static function __set_env_value(string $path, string $key, string $value): void
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('env heal: failed to read ' . $path);
        }

        $out = [];
        $written = false;
        foreach (explode("\n", $contents) as $line) {
            if (strncmp($line, $key . '=', strlen($key) + 1) === 0) {
                if (!$written) {
                    $out[] = $key . '=' . $value;
                    $written = true;
                }

                continue;
            }
            $out[] = $line;
        }

        if (!$written) {
            $out[] = $key . '=' . $value;
        }

        self::__atomic_write($path, implode("\n", $out));
    }

    /**
     * What the last heal saw: the size and modification time of every file it
     * reads, as one line each.
     *
     * A mtime COMPARISON was the obvious implementation and is wrong: mtimes have
     * one-second resolution, so an edit made in the same second as the heal that
     * preceded it compares as "not newer" and is never picked up. Recording the
     * observation and comparing it EXACTLY has no such window - any difference at
     * all, in either direction, means look again.
     */
    protected static function __stamp_signature(): string
    {
        $lines = [];

        foreach ([self::__root_env_path(), self::__root_dist_path(), self::__system_dist_path()] as $path) {
            if (!is_file($path)) {
                $lines[] = $path . ' absent';

                continue;
            }
            $lines[] = $path . ' ' . filemtime($path) . ' ' . filesize($path);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Has anything the heal reads changed since the last one?
     */
    protected static function __stamp_is_fresh(): bool
    {
        $stamp = self::__stamp_path();
        if (!is_file($stamp)) {
            return false;
        }

        return file_get_contents($stamp) === self::__stamp_signature();
    }

    /**
     * Record that a heal has just seen the current files. Best effort by design:
     * an unwritable storage directory costs a repeated heal, never a failure.
     */
    protected static function __touch_stamp(): void
    {
        $stamp = self::__stamp_path();
        $dir = dirname($stamp);

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        @file_put_contents($stamp, self::__stamp_signature());
    }

    /**
     * Detect the current state WITHOUT mutating anything (drift / doctor surface).
     *
     * @return array {
     *   healthy: bool,
     *   state: string,
     *   status: string,          # what heal() WOULD produce
     *   merged_keys: string[],
     *   overridden_keys: array,
     *   actions: string[],       # planned actions, phrased as "would ..."
     *   system_env: string,
     *   root_env: string
     * }
     */
    public static function get_drift_report(): array
    {
        $plan = self::__plan();
        $state = $plan['state'];

        $report = [
            'healthy' => $state === 'already_healthy',
            'state' => $state,
            'status' => null,
            'merged_keys' => $plan['merged_keys'],
            'overridden_keys' => $plan['overridden_keys'],
            'actions' => [],
            'system_env' => $plan['system_env'],
            'root_env' => $plan['root_env'],
        ];

        if ($state === 'already_healthy') {
            $report['status'] = 'already_healthy';
            $report['actions'][] = 'system/.env is already a symlink to the root .env; no change needed.';
        } elseif ($state === 'wrong_symlink') {
            $report['status'] = 'healed';
            $report['actions'][] = 'Would repoint system/.env symlink (currently -> '
                . $plan['current_link_target'] . ') to the root .env.';
        } elseif ($state === 'system_missing') {
            $report['status'] = 'healed';
            $report['actions'][] = 'Would create system/.env as a symlink to the root .env.';
        } elseif ($state === 'regular_file') {
            $report['status'] = 'healed';
            if (!empty($plan['merged_keys'])) {
                $report['actions'][] = 'Would append ' . count($plan['merged_keys'])
                    . ' key(s) unique to system/.env into the root .env.';
            }
            if (!empty($plan['overridden_keys'])) {
                $report['actions'][] = 'Would keep the root value for '
                    . count($plan['overridden_keys']) . ' conflicting key(s) (system values discarded).';
            }
            $report['actions'][] = 'Would back up system/.env and replace it with a symlink to the root .env.';
        } elseif ($state === 'root_missing') {
            $report['status'] = 'created';
            $report['actions'][] = 'Root .env is missing: would move system/.env content to the root, then symlink.';
        } else {
            // both_missing
            $report['status'] = 'error';
            $report['actions'][] = 'Neither .env exists - the healer cannot proceed (unbootable app).';
        }

        return $report;
    }

    /**
     * rsx:health probe: is the system/.env -> root .env symlink invariant intact? A public
     * static `#[Health_Check('label')]` (bare marker attribute - never a defined class),
     * read-only (get_drift_report never mutates). A drifted state is a WARN (the app still
     * boots, but edits to the root .env may be inert) with a heal-command remediation.
     *
     * @return array
     */
    #[Health_Check('Env Symlink')]
    public static function env_symlink(): array
    {
        $report = self::get_drift_report();

        if ($report['healthy']) {
            return ['status' => 'OK', 'detail' => 'system/.env is a symlink to the root .env'];
        }

        return [
            'status' => 'WARN',
            'detail' => 'symlink invariant drifted (state: ' . $report['state'] . ')',
            'remediation' => 'run php artisan rsx:env:heal',
        ];
    }

    // -------------------------------------------------------------------------
    // Planning (pure detection - no mutation)
    // -------------------------------------------------------------------------

    /**
     * Resolve paths, classify the current state, and (for the merge/move states)
     * precompute exactly what would change. Never mutates the filesystem.
     */
    protected static function __plan(): array
    {
        $system_env = self::__system_env_path();
        $root_env = self::__root_env_path();

        $plan = [
            'system_env' => $system_env,
            'root_env' => $root_env,
            'state' => null,
            'merged_keys' => [],
            'overridden_keys' => [],
            'merged_lines' => [],
            'current_link_target' => null,
        ];

        if (is_link($system_env)) {
            $plan['current_link_target'] = readlink($system_env);
            $resolved_system = realpath($system_env);
            $resolved_root = realpath($root_env);
            $healthy = $resolved_system !== false
                && $resolved_root !== false
                && $resolved_system === $resolved_root;
            $plan['state'] = $healthy ? 'already_healthy' : 'wrong_symlink';

            return $plan;
        }

        if (is_file($system_env)) {
            if (file_exists($root_env)) {
                $plan['state'] = 'regular_file';
                self::__plan_merge($plan, $system_env, $root_env);

                return $plan;
            }

            $plan['state'] = 'root_missing';

            return $plan;
        }

        // system/.env does not exist at all.
        if (file_exists($root_env)) {
            $plan['state'] = 'system_missing';

            return $plan;
        }

        $plan['state'] = 'both_missing';

        return $plan;
    }

    /**
     * Fill in merged_keys / merged_lines / overridden_keys for the regular-file
     * merge: keys unique to system/.env are moved; keys in both keep the root
     * value (root wins) and the discarded system value is recorded.
     */
    protected static function __plan_merge(array &$plan, string $system_env, string $root_env): void
    {
        $system_data = self::__parse_env_lines(file_get_contents($system_env));
        $root_data = self::__parse_env_lines(file_get_contents($root_env));

        foreach ($system_data['keys'] as $key => $system_value) {
            $in_root = array_key_exists($key, $root_data['keys']);
            if (!$in_root) {
                $plan['merged_keys'][] = $key;
                $plan['merged_lines'][] = $system_data['lines'][$key];
                continue;
            }

            $root_value = $root_data['keys'][$key];
            if (trim($system_value) !== trim($root_value)) {
                $plan['overridden_keys'][] = [
                    'key' => $key,
                    'system_value' => trim($system_value),
                    'root_value' => trim($root_value),
                ];
            }
        }
    }

    // -------------------------------------------------------------------------
    // Parsing
    // -------------------------------------------------------------------------

    /**
     * Parse KEY=VALUE lines. Comments and blank lines are ignored. Quoted values
     * are preserved verbatim - we move whole lines, never reformat a value.
     *
     * @return array{keys: array<string,string>, lines: array<string,string>}
     */
    protected static function __parse_env_lines(string $contents): array
    {
        $keys = [];
        $lines = [];

        foreach (explode("\n", $contents) as $raw) {
            $trimmed = ltrim($raw);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            $eq_pos = strpos($raw, '=');
            if ($eq_pos === false) {
                continue;
            }

            $key = trim(substr($raw, 0, $eq_pos));
            $is_valid_key = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key);
            if (!$is_valid_key) {
                continue;
            }

            $keys[$key] = substr($raw, $eq_pos + 1);
            $lines[$key] = rtrim($raw, "\r\n");
        }

        return ['keys' => $keys, 'lines' => $lines];
    }

    // -------------------------------------------------------------------------
    // Mutation primitives
    // -------------------------------------------------------------------------

    /**
     * Append the unique-key lines to the root .env under a dated marker comment.
     * The root file's existing bytes are left untouched (root wins). Written via
     * temp+rename in the file's own directory for an atomic replace.
     */
    protected static function __append_block(string $root_env, array $merged_lines, ?string $marker = null): void
    {
        $root_contents = file_get_contents($root_env);
        if ($root_contents !== '' && !str_ends_with($root_contents, "\n")) {
            $root_contents .= "\n";
        }

        $marker = $marker ?? '# merged from system/.env by rsx env healer (' . date('Y-m-d') . ')';
        $block = "\n" . $marker . "\n" . implode("\n", $merged_lines) . "\n";

        self::__atomic_write($root_env, $root_contents . $block);
    }

    /**
     * Atomic file write: stage into a sibling temp file, then rename over target.
     *
     * Deliberately NOT file_put_contents_safe(): the .env files never live under
     * the sealed build root (so the seal guard is irrelevant), and staging in the
     * target's own directory guarantees a same-filesystem rename.
     */
    protected static function __atomic_write(string $path, string $contents): void
    {
        $tmp = $path . '.healer_tmp_' . getmypid();

        if (file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException('env healer: failed to stage write for ' . $path);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('env healer: failed to move staged write into place at ' . $path);
        }
    }

    /**
     * Install a symlink at $link_path pointing at $relative_target, atomically
     * replacing whatever (regular file / wrong symlink / nothing) is there now.
     */
    protected static function __install_symlink(string $link_path, string $relative_target): void
    {
        $tmp = $link_path . '.healer_link_' . getmypid();

        if (is_link($tmp) || file_exists($tmp)) {
            @unlink($tmp);
        }

        if (!symlink($relative_target, $tmp)) {
            throw new RuntimeException('env healer: failed to create symlink at ' . $link_path);
        }

        if (!rename($tmp, $link_path)) {
            @unlink($tmp);
            throw new RuntimeException('env healer: failed to move symlink into place at ' . $link_path);
        }
    }

    /**
     * Copy a real system/.env file to a 0600 backup before it is replaced.
     *
     * @return string The backup path.
     */
    protected static function __backup(string $system_env): string
    {
        $backup = $system_env . self::BACKUP_SUFFIX;

        if (!copy($system_env, $backup)) {
            throw new RuntimeException('env healer: failed to back up ' . $system_env);
        }

        chmod($backup, 0600);

        return $backup;
    }

    // -------------------------------------------------------------------------
    // Path helpers
    // -------------------------------------------------------------------------

    /**
     * The system/.env path (the one Laravel/Dotenv actually loads).
     */
    protected static function __system_env_path(): string
    {
        return self::$_system_env_override ?? base_path('.env');
    }

    /**
     * The project-root .env path (one level above base_path()).
     */
    protected static function __root_env_path(): string
    {
        return self::$_root_env_override ?? dirname(base_path()) . '/.env';
    }

    /**
     * The project-root .env.encrypted - where `env:encrypt` writes, now that
     * environmentFilePath() is the root .env. Its presence WITHOUT a .env means
     * this environment's configuration is encrypted and must be decrypted, never
     * regenerated from .env.dist.
     */
    protected static function __root_env_encrypted_path(): string
    {
        return self::__root_env_path() . '.encrypted';
    }

    /**
     * Public reader for the encrypted-env path (rsx:health's Env Encryption row
     * needs it, and reading it through this seam means a test that redirects the
     * healer's paths redirects the health check with it).
     */
    public static function get_root_env_encrypted_path(): string
    {
        return self::__root_env_encrypted_path();
    }

    /**
     * The project-root .env.dist: the TRACKED, hand-curated canonical defaults.
     */
    protected static function __root_dist_path(): string
    {
        return self::__root_env_path() . '.dist';
    }

    /**
     * The framework's own shipped copy of .env.dist. It exists DOWNSTREAM only
     * (publish writes it from the project-root file); this monorepo has none, and
     * it never seeds a .env - it only feeds the key sync.
     */
    protected static function __system_dist_path(): string
    {
        return self::__system_env_path() . '.dist';
    }

    /**
     * The boot-heal stamp. Under storage/rsx-tmp, which is volatile by design:
     * losing it costs one heal.
     */
    protected static function __stamp_path(): string
    {
        return dirname(self::__root_env_path()) . '/storage/rsx-tmp/env_heal.stamp';
    }

    /**
     * Compute a RELATIVE symlink target from the link's own directory to the root
     * .env. Layout-agnostic: for the standard layout this yields "../.env".
     */
    protected static function __relative_link_target(string $link_path, string $target_path): string
    {
        $from = explode('/', trim(dirname($link_path), '/'));
        $to = explode('/', trim($target_path, '/'));

        $common = 0;
        $from_count = count($from);
        $to_count = count($to);
        while ($common < $from_count && $common < $to_count && $from[$common] === $to[$common]) {
            $common++;
        }

        $up = array_fill(0, $from_count - $common, '..');
        $down = array_slice($to, $common);
        $parts = array_merge($up, $down);

        return empty($parts) ? '.' : implode('/', $parts);
    }

    // -------------------------------------------------------------------------
    // Path seams
    // -------------------------------------------------------------------------

    /**
     * Declare the layout explicitly from the system directory.
     *
     * The PRE-BOOT caller (bootstrap/rsx_env_heal.php) needs this: base_path()
     * is a Laravel helper and the whole point of running there is that Laravel
     * has not booted - it cannot boot until the .env this class is about to
     * create exists.
     */
    public static function _set_paths_from_system_dir(string $system_dir): void
    {
        self::$_system_env_override = $system_dir . '/.env';
        self::$_root_env_override = dirname($system_dir) . '/.env';
    }

    /**
     * Drop both path overrides, so the class resolves its own paths again.
     */
    public static function _clear_path_overrides(): void
    {
        self::$_system_env_override = null;
        self::$_root_env_override = null;
    }

    // -------------------------------------------------------------------------
    // Test seams
    // -------------------------------------------------------------------------

    /**
     * Point the healer at throwaway paths (tests only). Pass null to restore a
     * single side to its real default.
     */
    public static function _testing_set_paths(?string $system_env, ?string $root_env): void
    {
        self::$_system_env_override = $system_env;
        self::$_root_env_override = $root_env;
    }

    /**
     * Restore both path seams to their real defaults (tests only).
     */
    public static function _testing_reset(): void
    {
        self::_clear_path_overrides();
    }
}
