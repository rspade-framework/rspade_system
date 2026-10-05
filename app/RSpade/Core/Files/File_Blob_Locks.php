<?php

namespace App\RSpade\Core\Files;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use App\RSpade\Core\Locks\RsxLocks;

/**
 * File_Blob_Locks - the per-blob readers-writer lock between the code that RECORDS a reference
 * to a blob and the code that RELEASES the blob's bytes.
 *
 * One web-cluster lock per content hash, named file_blob:<hash>:
 *
 *   READERS are reference creators. A creator holds the read lock from BEFORE the _file_storage
 *   row is looked up (or created) until the reference row that pins it is COMMITTED. Readers of
 *   one hash never wait for each other.
 *
 *   WRITERS are destroyers: File_Disposal_Service::release_blob_if_orphaned() and the monthly
 *   disk-orphan sweep. A writer re-checks every reference table inside the lock, so no reference
 *   can appear between its check and its unlink.
 *
 * WHY THE LOCK IS NEEDED. A storage row is deduplicated by content hash, so a brand-new upload
 * can land on a row a destroyer is about to release. Without the lock a creator could reuse the
 * row after the destroyer's check, or - worse - recreate the row and rewrite the file at the
 * same hash path a moment before the destroyer unlinks it: a committed reference to a file that
 * is gone, discovered only when someone opens it.
 *
 * HOLD UNTIL COMMIT. A reference written inside a database transaction is invisible to every
 * other connection until it commits, so a lock released at the INSERT would let a destroyer in
 * that cannot see the reference. Every hold therefore ends when the work that took it ends AND
 * the default connection's outermost transaction has ended (commit or rollback); with no
 * transaction open, that is immediately. The release runs from Laravel's TransactionCommitted /
 * TransactionRolledBack events at level 0, which fire AFTER DB::afterCommit() callbacks - so a
 * destroyer's post-commit unlink (registered with DB::afterCommit) always runs while its write
 * lock is still held.
 *
 * ONE PROCESS, BOTH ROLES. A process that recorded a reference inside a still-open transaction
 * holds the READ lock when it goes on to release a blob of the same hash. rsx-lockd refuses a
 * second acquire of one name on one connection, so the held READ is upgraded to WRITE
 * (RsxLocks::upgrade_lock()) and every outstanding hold now ends on the write token. A held
 * WRITE also satisfies a later READ in the same process.
 *
 * THE SEAMS that take these locks - application code never calls this class:
 *   - File_Storage_Model::store_blob($temp_path, $reference) - the only way bytes enter the
 *     store; opens a reference scope around the lookup/create AND the caller's reference write.
 *   - File_Attachment_Model::save() / Email_Attachment_Model::save() - any write that points a
 *     reference row at a storage id holds the read lock for that write, so a reference to an
 *     EXISTING row (relink, an email attaching a file attachment's blob) is covered too.
 *   - File_Disposal_Service - the writers.
 *
 * MAINTENANCE MODE grants cluster locks as no-ops (rsx:man locks). Nothing is excluded then;
 * the destroyer's ordering (delete the storage row under the foreign keys first, unlink only
 * after that commits) is the safety net, and the fleet is quiesced besides.
 */
class File_Blob_Locks
{
    /** Lock-name prefix; the content hash completes the name. */
    const NAME_PREFIX = 'file_blob:';

    /** @var array<string, array{token: string, type: string, holds: int}> name => this process's hold */
    private static array $held = [];

    /** @var array<int, array<int, string>> stack of open reference scopes, each a list of names */
    private static array $scopes = [];

    /** @var array<string, array<int, string>> connection name => names whose hold ends with its transaction */
    private static array $deferred = [];

    private static bool $listeners_installed = false;

    /**
     * The lock name guarding one blob.
     *
     * @param string $hash _file_storage.hash (the blob's filename)
     * @return string
     */
    public static function name(string $hash): string
    {
        return self::NAME_PREFIX . $hash;
    }

    /**
     * Run $work as one reference scope: every hold_read() inside it is held until $work returns
     * and the current transaction (if any) has ended.
     *
     * @param callable $work
     * @return mixed $work's return value
     */
    public static function reference_scope(callable $work): mixed
    {
        self::$scopes[] = [];

        try {
            return $work();
        } finally {
            self::__end_holds(array_pop(self::$scopes));
        }
    }

    /**
     * Take the READ lock on a blob hash for the innermost open reference scope.
     *
     * Throws outside a scope: a read lock with no scope would have no defined end, and a leaked
     * read lock parks every later destroyer of that hash - and, behind it in the FIFO, every
     * later upload of the same bytes.
     *
     * @param string $hash
     * @return void
     */
    public static function hold_read(string $hash): void
    {
        if (empty(self::$scopes)) {
            shouldnt_happen(
                'File_Blob_Locks::hold_read() outside a reference scope. Bytes enter the store only through '
                . 'File_Storage_Model::store_blob($temp_path, $reference).'
            );
        }

        $name = self::name($hash);
        self::__acquire($name, RsxLocks::READ_LOCK);
        self::$scopes[array_key_last(self::$scopes)][] = $name;
    }

    /**
     * Run $work - a write that points a reference row at an EXISTING storage row - holding that
     * blob's READ lock until the write commits.
     *
     * The row is re-read under the lock with a locking (current) read: a destroyer that held the
     * write lock when we asked has finished by the time we are granted, and a snapshot read
     * inside an older transaction could still see the row it deleted. A row that is gone THROWS -
     * the bytes were released before the reference existed, and silently pointing at them would
     * be exactly the data loss this lock exists to prevent.
     *
     * @param int      $storage_id
     * @param callable $work
     * @return mixed $work's return value
     */
    public static function referencing_storage(int $storage_id, callable $work): mixed
    {
        return self::reference_scope(function () use ($storage_id, $work) {
            $hash = DB::table('_file_storage')->where('id', $storage_id)->value('hash');
            if ($hash === null) {
                throw new RuntimeException("File storage #{$storage_id} does not exist; a reference cannot point at it.");
            }

            self::hold_read((string) $hash);

            $locked_hash = DB::table('_file_storage')->where('id', $storage_id)->sharedLock()->value('hash');
            if ($locked_hash !== $hash) {
                throw new RuntimeException(
                    "File storage #{$storage_id} was released while a reference to it was being recorded. "
                    . 'Store the bytes again with File_Storage_Model::store_blob().'
                );
            }

            return $work();
        });
    }

    /**
     * Run $work holding the WRITE lock on a blob hash. The hold ends when $work returns and the
     * current transaction (if any) has ended - so an unlink the work registered with
     * DB::afterCommit() runs before any creator can be granted the read lock.
     *
     * @param string   $hash
     * @param callable $work
     * @return mixed $work's return value
     */
    public static function exclusive(string $hash, callable $work): mixed
    {
        $name = self::name($hash);
        self::__acquire($name, RsxLocks::WRITE_LOCK);

        try {
            return $work();
        } finally {
            self::__end_holds([$name]);
        }
    }

    /**
     * Acquire one more hold on $name of at least strength $type.
     */
    private static function __acquire(string $name, string $type): void
    {
        $held = self::$held[$name] ?? null;

        if ($held === null) {
            $token = $type === RsxLocks::READ_LOCK
                ? RsxLocks::named_read_lock($name)
                : RsxLocks::named_write_lock($name);
            self::$held[$name] = ['token' => $token, 'type' => $type, 'holds' => 1];
            return;
        }

        if ($held['type'] === RsxLocks::READ_LOCK && $type === RsxLocks::WRITE_LOCK) {
            $held['token'] = RsxLocks::upgrade_lock($held['token']);
            $held['type'] = RsxLocks::WRITE_LOCK;
        }

        // Reentrant: RsxLocks counts a nested acquire of the held type client-side and hands
        // back the same token, so each hold here is matched by exactly one release_lock().
        $held['token'] = $held['type'] === RsxLocks::READ_LOCK
            ? RsxLocks::named_read_lock($name)
            : RsxLocks::named_write_lock($name);
        $held['holds']++;
        self::$held[$name] = $held;
    }

    /**
     * End holds: now when no transaction is open on the default connection, else when its
     * outermost transaction ends.
     *
     * @param array<int, string> $names
     */
    private static function __end_holds(array $names): void
    {
        if (empty($names)) {
            return;
        }

        $connection = DB::connection();

        if ($connection->transactionLevel() > 0) {
            self::__install_listeners();
            foreach ($names as $name) {
                self::$deferred[$connection->getName()][] = $name;
            }
            return;
        }

        foreach ($names as $name) {
            self::__release_one($name);
        }
    }

    private static function __release_one(string $name): void
    {
        $held = self::$held[$name] ?? null;
        if ($held === null) {
            return;
        }

        RsxLocks::release_lock($held['token']);

        $held['holds']--;
        if ($held['holds'] <= 0) {
            unset(self::$held[$name]);
        } else {
            self::$held[$name] = $held;
        }
    }

    /**
     * Release the deferred holds when a connection's OUTERMOST transaction ends, either way. A
     * savepoint ending is not the end: the enclosing transaction can still make the reference
     * visible (or roll it back) later.
     */
    private static function __install_listeners(): void
    {
        if (self::$listeners_installed) {
            return;
        }
        self::$listeners_installed = true;

        $on_end = function ($event) {
            if ($event->connection->transactionLevel() !== 0) {
                return;
            }

            $names = self::$deferred[$event->connectionName] ?? [];
            unset(self::$deferred[$event->connectionName]);

            foreach ($names as $name) {
                self::__release_one($name);
            }
        };

        Event::listen(TransactionCommitted::class, $on_end);
        Event::listen(TransactionRolledBack::class, $on_end);
    }
}
