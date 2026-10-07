<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

use Illuminate\Support\Facades\DB;
use App\RSpade\Commands\Database\Db_Rebuild_Provision_Cache_Snapshot_Command;
use App\RSpade\Core\Database\Rsx_Data_Wipe;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * Migrate_Dump_Rollback - `migrate --dump-rollback`: a gzipped mysqldump of the database is
 * taken before the migrations run, and a failed run is undone by restoring it.
 *
 * It needs nothing but the mysql client and the application's own credentials, so it works
 * where the datadir snapshot cannot - a production box, an external database host. Maint_Migrate
 * owns the run (maintenance window, the migrations themselves); this class owns the dump, the
 * restore, the run record and the decision what a LATER run does with whatever an earlier one
 * left behind.
 *
 * TWO RECORDS, AND THE DATABASE IS THE ONE THAT DECIDES.
 *
 *   - `_migration_runs` (a framework table in the migrated database): one row per protected
 *     run - token, status (migrating, completed, rolled_back, abandoned), the dump's name and
 *     checksum.
 *   - The marker (Rsx_Project_Paths::migrate_dump_marker_file()): the same token, the phase
 *     (migrating, restoring), the dump's path, checksum and table count, the database's name
 *     and default character set.
 *
 * A file can outlive the run it describes; a row cannot disagree with the database it is in.
 * So NOTHING ever restores on the marker's word alone: a leftover dump is restored only when
 * the live database itself says the run that took it is still `migrating` (or the marker says
 * a restore was already under way, which only follows that same check). A successful run
 * flips its row to `completed` as its FIRST act of committing, before the marker or the dump is
 * touched - so after a success, whatever survives an interrupted cleanup is inert, and the next
 * run only deletes it. There is no path on which a successful migration leaves behind anything
 * that a later run would restore over.
 *
 * WHAT A LATER RUN DOES (decide(), resolve_leftovers()):
 *
 *   no marker, a row still `migrating`     the dump is gone        REFUSE (operator)
 *   no marker, files in the directory      never committed to      delete them
 *   marker for another database            config moved            REFUSE (operator)
 *   row completed / rolled_back / abandoned the run is over         delete the leftovers
 *   phase migrating, row migrating         crashed mid-migration   RESTORE, then migrate
 *   phase migrating, no row                not this database       REFUSE (operator)
 *   phase restoring                        crashed mid-restore     RESTORE again
 *   either RESTORE with an unusable dump   nothing safe to use     REFUSE (operator)
 *
 * Every refusal is final until `migrate --abandon-dump-rollback` (abandon()) accepts the
 * database as it stands. Each one is a state in which a machine cannot tell a correct dump
 * from a stale one, which is exactly when restoring would risk real data.
 *
 * THE ROW IS WRITTEN AFTER THE DUMP. The dump therefore never contains its own run's row: a
 * restore removes the `migrating` row along with everything else the run did, and the
 * rollback records the outcome (`rolled_back`) afterwards.
 *
 * NO TIMEOUTS. A dump, a restore and the wait for the migrate lock take as long as the data
 * and the other run take; the lock is GET_LOCK with an infinite wait.
 *
 * AN INSTANCE PER RUN: it holds the connection it protects, the directory, the lock's
 * connection and the marker of the run it began.
 */
#[Instantiatable]
class Migrate_Dump_Rollback
{
    /** The run record. Framework bookkeeping beside `_migrations`, excluded from normalization. */
    public const RUNS_TABLE = '_migration_runs';

    public const STATUS_MIGRATING = 'migrating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_ABANDONED = 'abandoned';

    public const PHASE_MIGRATING = 'migrating';
    public const PHASE_RESTORING = 'restoring';

    /** The client programs a dump and a restore run: mysqldump, mysql, and gzip both ways. */
    public const CLIENT_BINARIES = ['mysqldump', 'mysql', 'gzip'];

    /** decide() answers. */
    public const ACTION_NONE = 'none';
    public const ACTION_CLEANUP = 'cleanup';
    public const ACTION_RESTORE = 'restore';
    public const REFUSE_DUMP_LOST = 'refuse_dump_lost';
    public const REFUSE_DUMP_UNUSABLE = 'refuse_dump_unusable';
    public const REFUSE_FOREIGN_DATABASE = 'refuse_foreign_database';
    public const REFUSE_OTHER_DATABASE = 'refuse_other_database';

    private string $connection_name;

    private string $dir;

    /** @var callable(string $level, string $line): void */
    private $say;

    private ?string $lock_connection_name = null;

    /** The marker of the run this instance began, between begin() and commit()/rollback(). */
    private ?array $marker = null;

    /**
     * @param string|null $connection_name the connection to protect (null: the default)
     * @param string|null $dir             where the dump and marker live (null: the state tree)
     * @param callable|null $say           fn(string $level, string $line): info, warn, error
     */
    public function __construct(?string $connection_name = null, ?string $dir = null, ?callable $say = null)
    {
        $this->connection_name = $connection_name ?? (string) config('database.default');
        $this->dir = rtrim($dir ?? Rsx_Project_Paths::migrate_dump_dir(), '/');
        $this->say = $say ?? function (string $level, string $line): void {
        };
    }

    // =========================================================================
    // THE DECISION
    // =========================================================================

    /**
     * What a run does with what an earlier run left, from the evidence alone - pure, so every
     * row of the table in the class docblock is testable without a database.
     *
     * @param array|null $marker          the marker, or null when there is none
     * @param bool $dump_ok               the marker's dump exists and matches its checksum
     * @param string|null $row_status     the status of the marker token's row, null if none
     * @param string[] $migrating_tokens  tokens of every row still `migrating`
     * @param string $database            the database the connection points at
     */
    public static function decide(?array $marker, bool $dump_ok, ?string $row_status, array $migrating_tokens, string $database): string
    {
        if ($marker === null) {
            return count($migrating_tokens) > 0 ? self::REFUSE_DUMP_LOST : self::ACTION_NONE;
        }

        if ((string) ($marker['database'] ?? '') !== $database) {
            return self::REFUSE_OTHER_DATABASE;
        }

        // A run that reached its end in THIS database never restores, whatever the marker
        // says: completed is the commit point, rolled_back and abandoned are already settled.
        if (in_array($row_status, [self::STATUS_COMPLETED, self::STATUS_ROLLED_BACK, self::STATUS_ABANDONED], true)) {
            return self::ACTION_CLEANUP;
        }

        $phase = (string) ($marker['phase'] ?? '');

        if ($phase === self::PHASE_RESTORING) {
            // Only ever entered after the token check below, and the restore wipes the row
            // with everything else - so the row cannot be asked again. Restoring twice from
            // the same dump is the same result.
            return $dump_ok ? self::ACTION_RESTORE : self::REFUSE_DUMP_UNUSABLE;
        }

        if ($phase === self::PHASE_MIGRATING) {
            if ($row_status === self::STATUS_MIGRATING) {
                return $dump_ok ? self::ACTION_RESTORE : self::REFUSE_DUMP_UNUSABLE;
            }

            return self::REFUSE_FOREIGN_DATABASE;
        }

        throw new \RuntimeException('The dump-rollback marker names an unknown phase "' . $phase . '": ' . Rsx_Project_Paths::migrate_dump_marker_file());
    }

    // =========================================================================
    // THE CLIENT PROGRAMS
    // =========================================================================

    /**
     * Every program in $binaries that is missing or does not run (`<program> --version` must
     * exit 0), each with the reason - empty when all of them run.
     *
     * @param string[] $binaries
     * @return array<string, string> program => why it cannot be used
     */
    public static function unusable_client_binaries(array $binaries = self::CLIENT_BINARIES): array
    {
        $unusable = [];

        foreach ($binaries as $binary) {
            if (!command_exists($binary)) {
                $unusable[$binary] = 'not installed (not on PATH)';
                continue;
            }

            $output = [];
            $exit_code = 0;
            \exec_safe(escapeshellarg($binary) . ' --version 2>&1', $output, $exit_code);
            if ($exit_code !== 0) {
                $unusable[$binary] = 'does not run (`' . $binary . ' --version` exited ' . $exit_code . ': ' . trim(implode(' ', $output)) . ')';
            }
        }

        return $unusable;
    }

    /**
     * Fail loud, before anything is touched, unless every client program runs. Called before
     * a dump and before a restore: a restore that dropped the database and then could not load
     * the dump would leave nothing.
     */
    public static function require_client_binaries(): void
    {
        $unusable = static::unusable_client_binaries();
        if (count($unusable) === 0) {
            return;
        }

        $lines = [];
        foreach ($unusable as $binary => $reason) {
            $lines[] = '  ' . $binary . ': ' . $reason;
        }

        throw new \RuntimeException("migrate --dump-rollback needs the MySQL client programs and gzip, and these cannot be used:\n"
            . implode("\n", $lines)
            . "\nInstall the MySQL client (mysql, mysqldump) and gzip on this host. Nothing has been touched.");
    }

    // =========================================================================
    // LEFTOVERS
    // =========================================================================

    /** Is there anything an earlier run left that resolve_leftovers() must settle? */
    public function has_leftovers(): bool
    {
        return is_file($this->marker_path()) || count($this->migrating_tokens()) > 0;
    }

    /**
     * Settle whatever an earlier run left: clean up, restore, or refuse (throws, naming what
     * an operator must decide). Returns the action taken. $before_restore is called only when
     * the answer is a RESTORE, before anything is touched - the caller raises the maintenance
     * window there, and nowhere else: a cleanup or a refusal needs no window.
     */
    public function resolve_leftovers(?callable $before_restore = null): string
    {
        $marker = $this->read_marker();
        $database = $this->database_name();
        $row_status = $marker !== null ? $this->row_status((string) $marker['token']) : null;
        $dump_ok = $marker !== null && $this->dump_matches($marker);

        $action = self::decide($marker, $dump_ok, $row_status, $this->migrating_tokens(), $database);

        switch ($action) {
            case self::ACTION_NONE:
                $this->delete_orphan_files();

                return $action;

            case self::ACTION_CLEANUP:
                $this->say('info', 'A previous dump-rollback run (' . $marker['token'] . ') finished (' . $row_status . ') but did not clean up; removing its dump.');
                $this->delete_dump($marker);
                $this->delete_marker();

                return $action;

            case self::ACTION_RESTORE:
                if ($before_restore !== null) {
                    $before_restore();
                }
                $this->say('warn', 'A previous migrate --dump-rollback run (' . $marker['token'] . ', started ' . ($marker['started_at'] ?? '?') . ') did not finish.');
                $this->say('warn', 'Restoring the database from its dump before migrating again...');
                $this->restore_and_settle($marker, 'restored by a later run: the run that took this dump did not finish');
                $this->say('info', '[OK] Database restored to its state before that run.');

                return $action;
        }

        throw new \RuntimeException($this->refusal_message($action, $marker, $row_status));
    }

    /**
     * Accept the database as it stands and discard every leftover: the marker and dumps are
     * deleted and every `migrating` row becomes `abandoned`. The operator's answer to a
     * refusal - never automatic.
     *
     * @return string[] what was discarded, one line each
     */
    public function abandon(): array
    {
        $discarded = [];

        $marker = $this->read_marker_lenient();
        if ($marker !== null) {
            $discarded[] = 'marker for run ' . ($marker['token'] ?? '?') . ' (phase ' . ($marker['phase'] ?? '?') . ')';
        }

        foreach ($this->dir_files() as $file) {
            if ($file !== $this->marker_path()) {
                $discarded[] = 'dump ' . $file;
                unlink($file);
            }
        }
        if (is_file($this->marker_path())) {
            unlink($this->marker_path());
        }

        foreach ($this->migrating_tokens() as $token) {
            $this->db()->table(self::RUNS_TABLE)->where('token', $token)->update([
                'status' => self::STATUS_ABANDONED,
                'finished_at' => self::__now(),
                'note' => 'abandoned by migrate --abandon-dump-rollback',
            ]);
            $discarded[] = 'run record ' . $token . ' (marked abandoned)';
        }

        return $discarded;
    }

    // =========================================================================
    // A PROTECTED RUN
    // =========================================================================

    /**
     * Take the dump and record the run. Nothing has been migrated yet, so a failure here
     * costs nothing: the partial dump is deleted and no marker or row is written.
     */
    public function begin(int $pending_count): string
    {
        static::require_client_binaries();
        ensure_directory($this->dir);
        $this->ensure_runs_table();

        $database = $this->database_name();
        $token = random_hash(16);
        $dump = $this->dir . '/dump_' . $token . '.sql.gz';
        $partial = $dump . '.partial';

        $schema = $this->db()->selectOne(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$database]
        );
        $routines = (int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?', [$database])->n;
        $events = (int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?', [$database])->n;
        $table_count = $this->table_count();

        $options = trim(($routines > 0 ? '--routines ' : '') . ($events > 0 ? '--events' : ''));

        $this->say('info', 'Dumping ' . $database . ' (' . $table_count . ' tables and views) to ' . $dump . ' ...');

        try {
            $pipeline = Rsx_Data_Wipe::mysqldump_command($database, $this->client_flags(), $options)
                . Db_Rebuild_Provision_Cache_Snapshot_Command::mysqlpv_pipe_segment()
                . ' | gzip > ' . escapeshellarg($partial);
            Rsx_Data_Wipe::stream($pipeline, $this->mysql_env(), 'dumping ' . $database);

            // The whole file decompresses: a dump is never trusted on its size alone.
            Rsx_Data_Wipe::stream('gzip -t ' . escapeshellarg($partial), [], 'verifying the dump of ' . $database);

            rename($partial, $dump);
        } catch (\Throwable $e) {
            if (is_file($partial)) {
                unlink($partial);
            }

            throw $e;
        }

        $marker = [
            'token' => $token,
            'phase' => self::PHASE_MIGRATING,
            'database' => $database,
            'dump_file' => $dump,
            'dump_sha256' => hash_file('sha256', $dump),
            'table_count' => $table_count,
            'charset' => (string) ($schema->charset ?? 'utf8mb4'),
            'collation' => (string) ($schema->collation ?? 'utf8mb4_unicode_ci'),
            'started_at' => self::__now(),
            'host' => (string) gethostname(),
        ];

        // Marker FIRST, then the row: a run with a row and no marker reads as "dump lost" and
        // is refused, while a marker with no row reads as "not this database" and is refused
        // too - but a crash between the two writes leaves a marker and NO row, and nothing has
        // been migrated yet, so that refusal is the safe answer to a run that did nothing.
        $this->write_marker($marker);

        $this->db()->table(self::RUNS_TABLE)->insert([
            'token' => $token,
            'status' => self::STATUS_MIGRATING,
            'host' => $marker['host'],
            'dump_file' => basename($dump),
            'dump_sha256' => $marker['dump_sha256'],
            'pending_count' => $pending_count,
            'started_at' => $marker['started_at'],
        ]);

        $this->marker = $marker;
        $this->say('info', '[OK] Dump taken (' . bytes_to_human((int) filesize($dump)) . ').');

        return $token;
    }

    /**
     * The migrations succeeded. Committing is THREE steps in this order, and the order is the
     * guarantee: the row says `completed` before the marker or the dump is touched, so an
     * interruption anywhere after the first step leaves only inert files.
     */
    public function commit(): void
    {
        $marker = $this->require_marker();

        $updated = $this->db()->table(self::RUNS_TABLE)
            ->where('token', $marker['token'])
            ->where('status', self::STATUS_MIGRATING)
            ->update(['status' => self::STATUS_COMPLETED, 'finished_at' => self::__now()]);

        if ($updated !== 1) {
            throw new \RuntimeException('The run record ' . $marker['token'] . ' could not be marked completed (' . $updated
                . ' rows matched). The marker and dump are kept: ' . $this->marker_path());
        }

        $this->delete_marker();
        $this->delete_dump($marker);
        $this->marker = null;
    }

    /** The migrations failed: restore the dump and record the run as rolled back. */
    public function rollback(string $reason): void
    {
        $marker = $this->require_marker();

        $this->restore_and_settle($marker, $reason);
        $this->marker = null;
    }

    // =========================================================================
    // THE LOCK
    // =========================================================================

    /**
     * One protected migrate at a time, per database, across every host: MySQL's GET_LOCK on a
     * connection of its own. A server-level lock, so it survives the database being dropped
     * and recreated by a restore; released with the connection, so a crashed run frees it.
     * Waits as long as the other run takes.
     */
    public function acquire_lock(): void
    {
        $this->lock_connection_name = $this->connection_name . '__migrate_lock';
        config(['database.connections.' . $this->lock_connection_name => $this->connection_config()]);

        $name = $this->lock_name();
        $lock = DB::connection($this->lock_connection_name);

        if ((int) $lock->selectOne('SELECT IS_FREE_LOCK(?) AS free', [$name])->free !== 1) {
            $this->say('warn', 'Another migrate is running against ' . $this->database_name() . '; waiting for it to finish...');
        }

        if ((int) $lock->selectOne('SELECT GET_LOCK(?, -1) AS got', [$name])->got !== 1) {
            throw new \RuntimeException('Could not take the migrate lock ' . $name . '.');
        }
    }

    public function release_lock(): void
    {
        if ($this->lock_connection_name === null) {
            return;
        }

        DB::connection($this->lock_connection_name)->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->lock_name()]);
        DB::disconnect($this->lock_connection_name);
        $this->lock_connection_name = null;
    }

    // =========================================================================
    // RESTORE
    // =========================================================================

    /** Restore the marker's dump, record the run rolled back, and remove the leftovers. */
    private function restore_and_settle(array $marker, string $reason): void
    {
        static::require_client_binaries();

        if (($marker['phase'] ?? '') !== self::PHASE_RESTORING) {
            $marker['phase'] = self::PHASE_RESTORING;
            $this->write_marker($marker);
        }

        $this->restore($marker);

        $this->ensure_runs_table();
        $this->db()->table(self::RUNS_TABLE)->updateOrInsert(['token' => $marker['token']], [
            'status' => self::STATUS_ROLLED_BACK,
            'host' => (string) ($marker['host'] ?? gethostname()),
            'dump_file' => basename((string) $marker['dump_file']),
            'dump_sha256' => (string) $marker['dump_sha256'],
            'started_at' => (string) $marker['started_at'],
            'finished_at' => self::__now(),
            'note' => $reason,
        ]);

        $this->delete_marker();
        $this->delete_dump($marker);
    }

    /**
     * Put the database back exactly as the dump has it: drop and recreate the database when
     * the account may, otherwise empty it object by object; then load the dump and check the
     * table count matches what was dumped.
     */
    private function restore(array $marker): void
    {
        $database = (string) $marker['database'];

        DB::purge($this->connection_name);

        if (!$this->drop_and_recreate($marker)) {
            $this->say('info', 'Emptying ' . $database . ' object by object (the account may not drop and recreate it)...');
            $this->drop_every_object($database);
        }

        DB::purge($this->connection_name);

        $this->say('info', 'Loading the dump into ' . $database . ' ...');
        $pipeline = 'set -o pipefail; cat ' . escapeshellarg((string) $marker['dump_file'])
            . ' | gunzip'
            . Db_Rebuild_Provision_Cache_Snapshot_Command::mysqlpv_pipe_segment()
            . ' | mysql ' . $this->client_flags() . ' ' . escapeshellarg($database);
        Rsx_Data_Wipe::stream($pipeline, $this->mysql_env(), 'restoring ' . $database);

        DB::purge($this->connection_name);

        $restored = $this->table_count();
        if ($restored !== (int) $marker['table_count']) {
            throw new \RuntimeException('The restore of ' . $database . ' produced ' . $restored . ' tables and views where the dump held '
                . $marker['table_count'] . '. The marker and dump are kept for another attempt: ' . $this->marker_path());
        }
    }

    /**
     * DROP DATABASE + CREATE DATABASE, when the account may do both. CREATE is probed first
     * with IF NOT EXISTS, which changes nothing: an account that could drop the database but
     * not create it must never be allowed to start, because it would end with no database at
     * all. Returns false when the account may not, so the caller empties it instead.
     */
    protected function drop_and_recreate(array $marker): bool
    {
        $quoted = self::__quote_identifier((string) $marker['database']);

        if ($this->mysql('CREATE DATABASE IF NOT EXISTS ' . $quoted)[0] !== 0) {
            return false;
        }

        if ($this->mysql('DROP DATABASE IF EXISTS ' . $quoted)[0] !== 0) {
            return false;
        }

        [$exit, $output] = $this->mysql('CREATE DATABASE ' . $quoted
            . ' CHARACTER SET ' . self::__quote_identifier((string) $marker['charset'])
            . ' COLLATE ' . self::__quote_identifier((string) $marker['collation']));

        if ($exit !== 0) {
            throw new \RuntimeException('The database ' . $marker['database'] . ' was dropped but could not be created again: ' . $output
                . "\nThe marker and dump are kept; once the account can create it, run migrate again to restore.");
        }

        return true;
    }

    /**
     * Empty the database without dropping it: foreign key checks off, every view, table,
     * routine and event dropped, checks back on - in ONE session, because the FK switch is
     * per session. Everything is dropped, not only what the dump holds: an object the failed
     * run created is one the restore must remove.
     */
    private function drop_every_object(string $database): void
    {
        $statements = ['SET FOREIGN_KEY_CHECKS=0'];

        $objects = $this->db()->select(
            'SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_TYPE DESC',
            [$database]
        );
        foreach ($objects as $object) {
            $statements[] = ($object->type === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . self::__quote_identifier($object->name);
        }

        foreach ($this->db()->select('SELECT ROUTINE_NAME AS name, ROUTINE_TYPE AS type FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?', [$database]) as $routine) {
            $statements[] = 'DROP ' . ($routine->type === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE') . ' IF EXISTS ' . self::__quote_identifier($routine->name);
        }

        foreach ($this->db()->select('SELECT EVENT_NAME AS name FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?', [$database]) as $event) {
            $statements[] = 'DROP EVENT IF EXISTS ' . self::__quote_identifier($event->name);
        }

        $statements[] = 'SET FOREIGN_KEY_CHECKS=1';

        [$exit, $output] = $this->mysql(implode(";\n", $statements) . ';', $database);
        if ($exit !== 0) {
            throw new \RuntimeException('Could not empty ' . $database . ' before restoring: ' . $output
                . "\nThe marker and dump are kept; run migrate again to retry the restore.");
        }

        DB::purge($this->connection_name);
    }

    // =========================================================================
    // RECORDS
    // =========================================================================

    /**
     * The run table, created on first use the way the migrations table is: it must exist
     * before any migration runs, so no migration can create it.
     */
    public function ensure_runs_table(): void
    {
        $this->db()->statement('CREATE TABLE IF NOT EXISTS ' . self::RUNS_TABLE . ' (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            token CHAR(32) CHARACTER SET ascii NOT NULL,
            status VARCHAR(20) CHARACTER SET ascii NOT NULL,
            host VARCHAR(255) NOT NULL,
            dump_file VARCHAR(255) NULL,
            dump_sha256 CHAR(64) CHARACTER SET ascii NULL,
            pending_count INT NOT NULL DEFAULT 0,
            started_at TIMESTAMP(3) NOT NULL,
            finished_at TIMESTAMP(3) NULL,
            note TEXT NULL,
            UNIQUE KEY uk_migration_runs_token (token),
            KEY idx_migration_runs_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** The status of one run's row, or null when there is none (or no table yet). */
    public function row_status(string $token): ?string
    {
        if (!$this->runs_table_exists()) {
            return null;
        }

        $row = $this->db()->table(self::RUNS_TABLE)->where('token', $token)->first(['status']);

        return $row !== null ? (string) $row->status : null;
    }

    /** @return string[] tokens of every run still `migrating` */
    public function migrating_tokens(): array
    {
        if (!$this->runs_table_exists()) {
            return [];
        }

        return $this->db()->table(self::RUNS_TABLE)->where('status', self::STATUS_MIGRATING)->pluck('token')->map(fn ($t) => (string) $t)->all();
    }

    /** The marker, or null. An unreadable marker is an error naming the file, never "no marker". */
    public function read_marker(): ?array
    {
        $path = $this->marker_path();
        if (!is_file($path)) {
            return null;
        }

        $marker = json_decode((string) file_get_contents($path), true);
        if (!is_array($marker) || !isset($marker['token'], $marker['phase'], $marker['database'], $marker['dump_file'], $marker['dump_sha256'])) {
            throw new \RuntimeException('The dump-rollback marker is unreadable: ' . $path
                . "\nInspect it; to accept the database as it stands, run: php artisan migrate --abandon-dump-rollback");
        }

        return $marker;
    }

    public function marker_path(): string
    {
        return $this->dir . '/marker.json';
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    private function read_marker_lenient(): ?array
    {
        $path = $this->marker_path();
        if (!is_file($path)) {
            return null;
        }

        $marker = json_decode((string) file_get_contents($path), true);

        return is_array($marker) ? $marker : [];
    }

    /** Written to a sibling and renamed: a reader sees the old marker or the new one, never half. */
    private function write_marker(array $marker): void
    {
        ensure_directory($this->dir);
        $temporary = $this->marker_path() . '.tmp';
        file_put_contents($temporary, json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        rename($temporary, $this->marker_path());
    }

    private function delete_marker(): void
    {
        if (is_file($this->marker_path())) {
            unlink($this->marker_path());
        }
    }

    private function delete_dump(array $marker): void
    {
        $dump = (string) ($marker['dump_file'] ?? '');
        if ($dump !== '' && is_file($dump)) {
            unlink($dump);
        }
    }

    /** Files in the directory with no marker to own them: never part of a committed run. */
    private function delete_orphan_files(): void
    {
        foreach ($this->dir_files() as $file) {
            $this->say('info', 'Removing an incomplete dump no run recorded: ' . $file);
            unlink($file);
        }
    }

    /** @return string[] */
    private function dir_files(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        return array_values(array_filter(glob($this->dir . '/*') ?: [], 'is_file'));
    }

    private function dump_matches(array $marker): bool
    {
        $dump = (string) $marker['dump_file'];

        return is_file($dump) && hash_equals((string) $marker['dump_sha256'], (string) hash_file('sha256', $dump));
    }

    private function require_marker(): array
    {
        if ($this->marker === null) {
            shouldnt_happen('Migrate_Dump_Rollback: commit() or rollback() without a begin()');
        }

        return $this->marker;
    }

    private function refusal_message(string $action, ?array $marker, ?string $row_status): string
    {
        $marker_path = $this->marker_path();
        $abandon = 'If you are sure the database is correct as it stands, accept it with:'
            . "\n    php artisan migrate --abandon-dump-rollback";

        switch ($action) {
            case self::REFUSE_DUMP_LOST:
                return 'A migrate --dump-rollback run is recorded as still migrating in this database ('
                    . implode(', ', $this->migrating_tokens()) . '), but its marker and dump are gone (' . $this->dir . ').'
                    . "\nThe run did not finish and there is nothing to restore it from, so the database may hold a partial migration."
                    . "\nThe state tree (storage/) must persist between runs for automatic recovery.\n" . $abandon;

            case self::REFUSE_DUMP_UNUSABLE:
                return 'The dump of an unfinished migrate --dump-rollback run (' . $marker['token'] . ') is missing or does not match its checksum: '
                    . $marker['dump_file'] . "\nThe database may hold a partial migration and cannot be restored automatically.\n" . $abandon;

            case self::REFUSE_FOREIGN_DATABASE:
                return 'A dump-rollback marker exists (' . $marker_path . ', run ' . $marker['token'] . '), but this database has no record of that run.'
                    . "\nThe database was replaced or restored outside migrate since the dump was taken; restoring could revert it.\n" . $abandon;

            case self::REFUSE_OTHER_DATABASE:
                return 'The dump-rollback marker (' . $marker_path . ') belongs to the database ' . ($marker['database'] ?? '?')
                    . ', but this connection points at ' . $this->database_name() . '.'
                    . "\nPoint the connection back at that database and run migrate to recover it, or:\n" . $abandon;
        }

        shouldnt_happen('Migrate_Dump_Rollback: no refusal message for ' . $action);
    }

    private function runs_table_exists(): bool
    {
        return (int) $this->db()->selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->database_name(), self::RUNS_TABLE]
        )->n > 0;
    }

    private function table_count(): int
    {
        return (int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$this->database_name()])->n;
    }

    /**
     * Run SQL through the mysql client. Never the framework's own connection: the database
     * it is bound to may be the one being dropped.
     *
     * @return array{0: int, 1: string} exit code, output
     */
    private function mysql(string $sql, ?string $database = null): array
    {
        $command = 'mysql ' . $this->client_flags()
            . ($database !== null ? ' ' . escapeshellarg($database) : '')
            . ' -e ' . escapeshellarg($sql) . ' 2>&1';

        $output = [];
        $exit_code = 0;
        \exec_safe($command, $output, $exit_code, $this->mysql_env());

        return [$exit_code, implode("\n", $output)];
    }

    private function db(): \Illuminate\Database\Connection
    {
        return DB::connection($this->connection_name);
    }

    private function connection_config(): array
    {
        return (array) config('database.connections.' . $this->connection_name);
    }

    private function database_name(): string
    {
        return (string) ($this->connection_config()['database'] ?? '');
    }

    private function client_flags(): string
    {
        return Rsx_Data_Wipe::client_flags($this->connection_config());
    }

    private function mysql_env(): array
    {
        return Rsx_Data_Wipe::mysql_env($this->connection_config());
    }

    private function lock_name(): string
    {
        // GET_LOCK names are at most 64 characters.
        return substr('rsx_migrate:' . $this->database_name(), 0, 64);
    }

    private function say(string $level, string $line): void
    {
        ($this->say)($level, $line);
    }

    private static function __quote_identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private static function __now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
