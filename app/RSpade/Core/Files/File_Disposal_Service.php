<?php

namespace App\RSpade\Core\Files;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Blob_Locks;
use App\RSpade\Core\Files\File_Blob_References;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Service\Rsx_Service_Abstract;
use App\RSpade\Core\Task\Task_Instance;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * File_Disposal_Service - the SOLE authority that releases blob bytes.
 *
 * File_Attachment_Model uses SoftDeletes: delete() means "enter the retention window"
 * (recoverable for rsx.files.deleted_retention_days; 0 = forever). Nothing is destroyed
 * inline. This
 * service owns the whole back half of the lifecycle:
 *   - the daily disposal task (destroy pass + blob-release pass), see Phase 2 methods,
 *   - the 6-hourly claim-window sweep of unattached uploads,
 *   - the monthly deep orphan sweep,
 *   - and the shared release_blob_if_orphaned() helper that force_destroy() also calls.
 *
 * RETENTION-AWARE REFCOUNT (the crux): a deduplicated storage blob is PINNED by any
 * attachment that is LIVE or SOFT-DELETED-BUT-NOT-DESTROYED. Only once every attachment
 * referencing a blob is DESTROYED (destroyed_at set) may the bytes be released. Every
 * refcount check therefore uses withTrashed() + whereNull('destroyed_at') - the naive
 * "count remaining attachments" of the old inline hook would under-count and free a blob a
 * recoverable attachment still needs.
 *
 * _file_attachments is NOT the only thing that pins a blob. A queued email's part does too, and
 * so does a task's attachment: they live in the same content-addressed store, and releasing
 * their bytes would turn a pending send into a message that arrives with an empty attachment -
 * silently, hours later, in a background task. Every table that can hold a reference DECLARES
 * it with #[Blob_Reference] on its model, and every reference check below asks each declared
 * table (File_Blob_References). A table referencing _file_storage.id with no declaration
 * FAILs the "Blob References" health row.
 *
 * RACE-PROOF RELEASE. A reference can be recorded at any moment, deduplicated onto the very
 * blob being released. Two mechanisms close that window:
 *   - the PER-BLOB LOCK (File_Blob_Locks, file_blob:<hash>). Every reference creator holds the
 *     read lock from before its storage lookup until its reference row commits; every destroyer
 *     here holds the write lock and re-checks every declared reference table inside it.
 *   - DELETE THE ROW BEFORE THE FILE. The storage row is deleted in a transaction first, where
 *     the ON DELETE RESTRICT foreign keys of the reference tables refuse it
 *     if any reference exists; the file and its derived caches are unlinked only after that
 *     commits. So a reference the lock somehow did not exclude makes the release FAIL SAFE
 *     (nothing unlinked) instead of leaving a row that points at a missing file.
 * Maintenance mode grants cluster locks as no-ops (rsx:man locks); the ordering is then the
 * whole safety net, on a fleet with nothing else running.
 *
 * No global disposal lock: two destroyers of the same blob serialize on its write lock, and
 * destroyers of different blobs share no state. The passes themselves are #[Exclusive].
 */
class File_Disposal_Service extends Rsx_Service_Abstract
{
    /**
     * Release a storage blob IFF nothing still pins it under the retention-aware refcount rule
     * (any declared reference - a live-or-retained attachment, a queued email's part, a task's
     * attachment). Purges the blob-keyed caches
     * too: the search index rides along on File_Storage_Model::delete()'s own cascade; the
     * rendition + thumbnails are NOT cascade-cleaned, so they are unlinked explicitly here.
     *
     * Runs under the blob's WRITE lock (File_Blob_Locks) and re-checks every declared
     * reference table inside it. Order inside the lock: re-check -> transaction (release the destroyed
     * tombstones' claim, delete the storage row) -> commit -> unlink the file and purge the
     * caches. Inside a caller's open transaction the unlink waits for the OUTERMOST commit
     * (DB::afterCommit; a rollback restores the row and keeps the file), and the write lock is
     * held until then.
     *
     * Safe to call for an absent storage id (no-op).
     *
     * NOTHING IS RELEASED while rsx.files.deleted_retention_days is 0 (blob_release_enabled()):
     * this is the one release authority, so the daily and monthly passes and force_destroy()
     * all answer false then and the bytes stay. `rsx:files:unreferenced_blobs` lists them.
     *
     * @param int $storage_id
     * @return bool true if the storage row was deleted (its bytes are unlinked at commit)
     */
    public static function release_blob_if_orphaned(int $storage_id): bool
    {
        if (!self::blob_release_enabled()) {
            return false;
        }

        $hash = DB::table('_file_storage')->where('id', $storage_id)->value('hash');
        if ($hash === null) {
            return false;
        }
        $hash = (string) $hash;

        return File_Blob_Locks::exclusive($hash, function () use ($storage_id, $hash) {
            if (self::__blob_is_referenced($storage_id)) {
                return false;
            }

            $storage = File_Storage_Model::find($storage_id);
            if ($storage === null || (string) $storage->hash !== $hash) {
                return false;
            }

            return self::__release_unreferenced_blob($storage);
        });
    }

    /**
     * Is the blob still pinned? Every declared reference counts (File_Blob_References), from
     * EVERY site (the blob is deduplicated across the whole install, so a site-scoped count
     * would release bytes another tenant still holds). An attachment counts while live or
     * retained - its declaration excludes only destroyed tombstones.
     */
    private static function __blob_is_referenced(int $storage_id): bool
    {
        return File_Blob_References::is_referenced($storage_id);
    }

    /**
     * Delete an unreferenced blob's storage row, then - once that commits - its file and
     * derived caches. The caller holds the blob's write lock and has already re-checked the
     * references; this step is the database's half of the guarantee.
     *
     * Returns false, with NOTHING unlinked, when a foreign key refuses the delete: a reference
     * row exists after all, and the bytes are exactly what it needs.
     */
    private static function __release_unreferenced_blob(File_Storage_Model $storage): bool
    {
        $storage_id = (int) $storage->id;
        $hash = (string) $storage->hash;
        $blob_path = $storage->get_full_path();

        try {
            DB::transaction(function () use ($storage, $storage_id) {
                // Release the FK claim of the DESTROYED tombstones so the storage row can be
                // deleted - the _file_attachments.file_storage_id FK is ON DELETE RESTRICT. The
                // tombstones persist forever as audit records, metadata intact. Destroyed rows
                // ONLY: a live attachment recorded after the re-check keeps its claim, and its
                // claim is what makes the delete below refuse.
                //
                // @REALTIME-BULK-01-EXCEPTION - every row this touches is a DESTROYED tombstone: the
                // retention window has elapsed, the attachment is unreachable from every screen, and
                // nothing can be subscribed to it. A change frame here would tell a subscriber that
                // does not exist to refetch a record it may no longer read.
                DB::table('_file_attachments')
                    ->where('file_storage_id', $storage_id)
                    ->whereNotNull('destroyed_at')
                    ->update(['file_storage_id' => null]);

                // Deletes the storage row and (via its own deleted() hook) the _search_indexes row,
                // in this same transaction.
                $storage->delete();
            });
        } catch (QueryException $e) {
            // ONLY the foreign-key refusal is expected here (MySQL 1451, SQLSTATE 23000): a
            // declared reference row (File_Blob_References) still points at this
            // storage row. The transaction rolled back, nothing was unlinked, and the blob stays
            // for whoever references it - the fail-safe outcome. Every other database error is
            // a real fault and propagates.
            if (!self::__is_foreign_key_refusal($e)) {
                throw $e;
            }

            return false;
        }

        // Runs now with no transaction open, else after the OUTERMOST commit (discarded on a
        // rollback, which restores the row). The write lock is still held either way.
        DB::afterCommit(function () use ($blob_path, $hash) {
            // This process may have stored the same bytes again later in the same transaction;
            // a live row at this hash owns the file now.
            if (DB::table('_file_storage')->where('hash', $hash)->exists()) {
                return;
            }

            if (is_file($blob_path)) {
                @unlink($blob_path);
            }

            // Blob-keyed derived caches that $storage->delete() does NOT cascade.
            self::__purge_blob_derived_caches($hash);
        });

        return true;
    }

    /**
     * Whether a query failed because a foreign key RESTRICTs deleting a referenced row
     * (MySQL ER_ROW_IS_REFERENCED_2, 1451).
     */
    private static function __is_foreign_key_refusal(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1451;
    }

    /**
     * Remove the rendition + thumbnail caches derived from a blob hash. Both key on the
     * deduplicated blob hash and are otherwise only reclaimed by their LRU quota sweeps, so
     * a destroyed blob would leave them orphaned. Paths resolve through Rsx_File_Paths.
     *
     * @param string $hash storage blob content hash
     * @return void
     */
    private static function __purge_blob_derived_caches(string $hash): void
    {
        // PDF rendition: renditions_root()/{hash}.pdf
        $rendition = Rsx_File_Paths::renditions_root() . '/' . $hash . '.pdf';
        if (is_file($rendition)) {
            @unlink($rendition);
        }

        // Thumbnails: thumbnails_root()/{preset,dynamic}/{hash}_*.webp
        foreach (['preset', 'dynamic'] as $sub) {
            $matches = glob(Rsx_File_Paths::thumbnails_root() . '/' . $sub . '/' . $hash . '_*.webp');
            foreach ($matches ?: [] as $thumb) {
                @unlink($thumb);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Scheduled disposal tasks
    // -------------------------------------------------------------------------

    /**
     * DAILY disposal (02:00). Two batched, keyset-paginated passes (bounded memory):
     *   (a) DESTROY: attachments whose retention window has elapsed (deleted_at < now -
     *       deleted_retention_days, destroyed_at still NULL) go through the destroy.hold gate
     *       + the file.attachment.destroyed action, then get stamped destroyed_at (a permanent
     *       audit tombstone). SKIPPED when deleted_retention_days is 0 (keep forever).
     *   (b) BLOB-RELEASE: for blobs referenced by attachments destroyed within
     *       disposal_lookback_days, recompute the retention-aware refcount and release the
     *       bytes at zero. Runs under every retention setting: it only ever frees bytes whose
     *       every referrer is DESTROYED, which under keep-forever means force_destroy()ed.
     *
     * Every other "is it retained?" answer (the refcount, the monthly sweep, undelete(),
     * get_deleted_files()) is keyed on destroyed_at, never on a date, so this destroy pass
     * is the ONLY place the retention period is read.
     */
    #[Task('Dispose of attachments past their retention window and release orphaned blobs')]
    #[Exclusive]
    #[Schedule('0 2 * * *')]
    public static function run_daily_disposal(Task_Instance $task, array $params = [])
    {
        // Retention is install policy and the blob store is shared, so every pass below walks
        // every site's attachments; a worker's declared site says nothing about what is due.
        // A stop ends the walk between attachments / blobs and comes back as 'stopped'.
        $result = File_Attachment_Model::without_site_scope(function () use ($task, $params) {
            $chunk = 1000;
            $retention_days = self::__deleted_retention_days();
            $lookback_days = (int) config('rsx.files.disposal_lookback_days', 60);

            // (a) DESTROY PASS. 0 = keep forever: nothing is ever past retention.
            $destroyed = 0;
            $held = 0;
            if ($retention_days > 0) {
                $task->status('Destroying attachments past their retention window');
                $destroy_cutoff = Rsx_Time::subtract(Rsx_Time::now_iso(), $retention_days * 86400);
                // Keyset-walked: a HELD attachment keeps destroyed_at NULL and so stays in the
                // predicate, but the cursor has already passed its id - no re-visit, no spin.
                $past_retention = File_Attachment_Model::withTrashed()
                    ->whereNotNull('deleted_at')
                    ->whereNull('destroyed_at')
                    ->where('deleted_at', '<', $destroy_cutoff)
                    ->result_set($chunk);

                foreach ($past_retention as $attachment) {
                    if ($task->is_stop_requested()) {
                        return ['destroyed' => $destroyed, 'held' => $held, 'blobs_released' => 0, 'stopped' => true];
                    }

                    if (self::__destroy_attachment($attachment, $task)) {
                        $destroyed++;
                    } else {
                        $held++;
                    }
                    $task->heartbeat();
                }
            }

            // (b) BLOB-RELEASE PASS: distinct blobs of recently-destroyed attachments. Off with
            // keep-forever (blob_release_enabled()).
            $released = 0;
            if (!self::blob_release_enabled()) {
                return ['destroyed' => $destroyed, 'held' => $held, 'blobs_released' => 0, 'stopped' => false];
            }
            $task->status('Releasing blobs of destroyed attachments');
            $release_cutoff = Rsx_Time::subtract(Rsx_Time::now_iso(), $lookback_days * 86400);
            $last_sid = 0;
            while (true) {
                $storage_ids = File_Attachment_Model::withTrashed()
                    ->whereNotNull('destroyed_at')
                    ->where('destroyed_at', '>=', $release_cutoff)
                    ->whereNotNull('file_storage_id')
                    ->where('file_storage_id', '>', $last_sid)
                    ->orderBy('file_storage_id')
                    ->distinct()
                    ->limit($chunk)
                    ->pluck('file_storage_id');
                if ($storage_ids->isEmpty()) {
                    break;
                }
                foreach ($storage_ids as $sid) {
                    if ($task->is_stop_requested()) {
                        return ['destroyed' => $destroyed, 'held' => $held, 'blobs_released' => $released, 'stopped' => true];
                    }
                    $task->heartbeat();

                    $last_sid = (int) $sid;
                    if (self::release_blob_if_orphaned((int) $sid)) {
                        $released++;
                    }
                }
            }

            if ($destroyed || $held || $released) {
                $task->stdout("Destroyed {$destroyed} attachment(s), {$held} held; released {$released} blob(s).");
            }

            return ['destroyed' => $destroyed, 'held' => $held, 'blobs_released' => $released, 'stopped' => false];
        });

        $task->state($result);
        $done = "{$result['destroyed']} attachment(s) destroyed, {$result['held']} held, {$result['blobs_released']} blob(s) released";
        $task->summary($result['stopped'] ? "Stopped with {$done}." : ucfirst($done) . '.');

        return null;
    }

    /**
     * Whether anything may remove a blob from the store. FALSE while
     * rsx.files.deleted_retention_days is 0 (keep forever): then nothing releases a blob or
     * unlinks a file in the store for ANY reason - an unreferenced row, a destroyed attachment,
     * a force_destroy(), a file on disk with no row. Keep-forever is also how an install says
     * its blob store is shared (several environments on one uploads mount): a database cannot
     * see another environment's references, so no release it decides on is safe.
     * `rsx:files:unreferenced_blobs` lists what stays behind.
     */
    public static function blob_release_enabled(): bool
    {
        return self::__deleted_retention_days() > 0;
    }

    /**
     * config('rsx.files.deleted_retention_days'), validated: a whole number of days, 0
     * meaning keep forever. A negative or non-integer value THROWS - read as a number it
     * would put the destroy cutoff in the future (or at "now") and destroy every deleted
     * attachment on the next run.
     */
    private static function __deleted_retention_days(): int
    {
        $value = config('rsx.files.deleted_retention_days', 30);
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0) {
            throw new \RuntimeException(
                "config('rsx.files.deleted_retention_days') must be a whole number of days >= 0 "
                . '(0 keeps deleted attachments forever); got ' . var_export($value, true) . '. See rsx:man file_disposal.'
            );
        }
        return $value;
    }

    /**
     * MONTHLY deep orphan sweep (first Sunday, 02:00). Classic cron cannot express "first
     * Sunday", so this fires every Sunday (0 2 * * 0) and guards on day-of-month <= 7. Full
     * reconciliation with no time window, three keyset/streaming passes:
     *   (a) DB: every _file_storage row with a retention-aware refcount of 0 -> release.
     *   (b) DISK: stream-walk blob_root(); unlink files older than the age guard that have no
     *       _file_storage row (the guard protects a just-written blob whose row commits after
     *       the file lands).
     *   (c) UPLOADS: soft-delete stale plain-local unassigned uploads (they enter retention;
     *       WP-A external attachments and their bytes are NEVER touched).
     *
     * A ['force' => true] param bypasses the first-Sunday guard (tests / manual invocation).
     */
    #[Task('Monthly deep file sweep: reconcile blob refcounts + disk, sweep stale unassigned uploads')]
    #[Exclusive]
    #[Schedule('0 2 * * 0')]
    public static function run_monthly_deep_sweep(Task_Instance $task, array $params = [])
    {
        // Every site's attachments, for the reason run_daily_disposal() gives.
        // A stop ends the current pass between items and comes back as 'stopped'.
        $result = File_Attachment_Model::without_site_scope(function () use ($task, $params) {
            if (empty($params['force']) && (int) date('j') > 7) {
                return ['skipped' => 'not the first Sunday of the month'];
            }

            $chunk = 1000;
            $min_age_days = (int) config('rsx.files.disk_orphan_min_age_days', 14);

            // (a) DB SIDE: storage rows with zero retention-aware references. Raw DB::table so the
            // SoftDeletes global scope does not hide the retained rows that must still count.
            $task->status('Releasing unreferenced blobs');
            $released = 0;
            $disk_deleted = 0;
            $uploads_swept = 0;
            $stopped = function () use (&$released, &$disk_deleted, &$uploads_swept) {
                return ['blobs_released' => $released, 'disk_files_removed' => $disk_deleted, 'uploads_swept' => $uploads_swept, 'stopped' => true];
            };
            $last_sid = 0;
            // Keep-forever releases nothing and removes no file: (a) and (b) are skipped.
            while (self::blob_release_enabled()) {
                $orphan_ids = File_Blob_References::where_unreferenced(
                    DB::table('_file_storage as s')->where('s.id', '>', $last_sid),
                    's'
                )
                    ->orderBy('s.id')
                    ->limit($chunk)
                    ->pluck('s.id');
                if ($orphan_ids->isEmpty()) {
                    break;
                }
                foreach ($orphan_ids as $sid) {
                    if ($task->is_stop_requested()) {
                        return $stopped();
                    }
                    $task->heartbeat();

                    $last_sid = (int) $sid;
                    if (self::release_blob_if_orphaned((int) $sid)) {
                        $released++;
                    }
                }
            }

            // (b) DISK SIDE.
            if (self::blob_release_enabled()) {
                $task->status('Removing orphaned disk files');
                [$disk_deleted, $disk_stopped] = self::__sweep_disk_orphans($task, $min_age_days, $chunk);
                if ($disk_stopped) {
                    return $stopped();
                }
            } else {
                $task->stdout('Blob release is off (rsx.files.deleted_retention_days = 0): no blob released, no disk file removed.');
            }

            // (c) UNASSIGNED UPLOADS: soft-delete stale plain-local unattached uploads.
            $task->status('Sweeping stale unassigned uploads');
            $upload_cutoff = Rsx_Time::subtract(Rsx_Time::now_iso(), $min_age_days * 86400);
            $stale_uploads = File_Attachment_Model::whereNull('fileable_id')
                ->whereNull('handler_class')          // plain local uploads only (never WP-A external)
                ->whereNotNull('file_storage_id')
                ->where('created_at', '<', $upload_cutoff)
                ->result_set($chunk);

            foreach ($stale_uploads as $attachment) {
                if ($task->is_stop_requested()) {
                    return $stopped();
                }
                $task->heartbeat();

                $attachment->delete();   // soft-delete -> enters the retention window
                $uploads_swept++;
            }

            if ($released || $disk_deleted || $uploads_swept) {
                $task->stdout("Released {$released} orphaned blob(s); removed {$disk_deleted} orphaned disk file(s); swept {$uploads_swept} stale unassigned upload(s).");
            }

            return ['blobs_released' => $released, 'disk_files_removed' => $disk_deleted, 'uploads_swept' => $uploads_swept, 'stopped' => false];
        });

        $task->state($result);
        if (isset($result['skipped'])) {
            $task->summary('Skipped: not the first Sunday of the month.');

            return null;
        }
        $done = "{$result['blobs_released']} orphaned blob(s) released, {$result['disk_files_removed']} orphaned disk file(s) removed, {$result['uploads_swept']} stale unassigned upload(s) swept";
        $task->summary($result['stopped'] ? "Stopped with {$done}." : ucfirst($done) . '.');

        return null;
    }

    /**
     * CLAIM-WINDOW SWEEP (every 6 hours). An upload arrives UNATTACHED and is reachable only
     * by its unguessable key until app code claims it with attach_to()/add_to(). That claim
     * window is what bounds the key's usefulness - it is the successor to the bound the old
     * session_id stamp implied, now that claimability is a property of the attachment rather
     * than of a browser session.
     *
     * Anything still unattached past config('rsx.attachments.unattached_claim_window_hours')
     * is SOFT-deleted (delete(), the retention-aware path - NOT force_destroy): the row enters
     * the normal recoverable window and its blob stays pinned, so a mis-timed sweep of a file
     * someone still wanted is recoverable for the full retention period.
     *
     * Never touched: handler-backed (external) attachments, anything already attached,
     * anything already soft-deleted. 0/null window disables the sweep.
     *
     * The monthly deep sweep has its own, much older (disk_orphan_min_age_days) unassigned
     * pass; this one is the tight, frequent bound and the monthly pass remains the backstop
     * that also reconciles blobs and disk.
     */
    #[Task('Sweep unattached uploads past their claim window')]
    #[Exclusive]
    #[Schedule('every 6 hours')]
    public static function sweep_unclaimed_uploads(Task_Instance $task, array $params = [])
    {
        // Every site's attachments, for the reason run_daily_disposal() gives.
        $result = File_Attachment_Model::without_site_scope(function () use ($task, $params) {
            $window_hours = (int) config('rsx.attachments.unattached_claim_window_hours', 24);
            if ($window_hours <= 0) {
                return ['skipped' => 'claim-window sweep disabled', 'swept' => 0];
            }

            $cutoff = Rsx_Time::subtract(Rsx_Time::now_iso(), $window_hours * 3600);

            // Keyset-walked (result_set): an unbounded table, and a backlog of abandoned uploads
            // is exactly the shape that grows without warning. Each delete() removes the row from
            // the predicate, and the cursor only moves forward, so there is no re-visit or spin.
            $unclaimed_query = fn () => File_Attachment_Model::whereNull('fileable_type')
                ->whereNull('fileable_id')
                ->whereNull('handler_class')          // plain local uploads only (never WP-A external)
                ->where('created_at', '<', $cutoff);

            $total = $unclaimed_query()->count();
            $swept = 0;

            foreach ($unclaimed_query()->result_set(1000) as $attachment) {
                if ($task->is_stop_requested()) {
                    return ['swept' => $swept, 'window_hours' => $window_hours, 'stopped' => true];
                }
                $task->heartbeat();

                $attachment->delete();   // soft-delete -> enters the retention window
                $swept++;
                $task->progress_count($swept, max($total, $swept));
            }

            if ($swept) {
                $task->stdout("Swept {$swept} unattached upload(s) past the {$window_hours}h claim window.");
            }

            return ['swept' => $swept, 'window_hours' => $window_hours, 'stopped' => false];
        });

        $task->state($result);
        if (isset($result['skipped'])) {
            $task->summary('The claim-window sweep is disabled; nothing was swept.');
        } elseif ($result['stopped']) {
            $task->summary("Stopped after sweeping {$result['swept']} unattached upload(s).");
        } else {
            $task->summary("Swept {$result['swept']} unattached upload(s) past the {$result['window_hours']}h claim window.");
        }

        return null;
    }

    /**
     * Destroy ONE attachment: consult the hold gate, fire the destroyed action, then stamp
     * destroyed_at. Returns true if destroyed, false if HELD (the gate held it, or a
     * destroyed listener threw - either way the row is left un-stamped for the next run,
     * never half-destroyed). The gate uses the framework convention (a listener returns true
     * to PERMIT, a non-true value HOLDS). The action fires OUTSIDE any blob-lock section.
     */
    private static function __destroy_attachment(File_Attachment_Model $attachment, Task_Instance $task): bool
    {
        if (Rsx::trigger_gate('file.attachment.destroy.hold', $attachment) !== true) {
            return false;
        }

        try {
            Rsx::trigger_action('file.attachment.destroyed', $attachment);
        } catch (Throwable $e) {
            // A throwing listener ABORTS this attachment's destruction (fail-loud, not
            // half-done): leave it un-stamped and retry next run.
            $task->stderr('file.attachment.destroyed listener threw for attachment ' . $attachment->id . '; deferring: ' . $e->getMessage());
            return false;
        }

        $attachment->destroyed_at = Rsx_Time::now_iso();
        $attachment->save();
        return true;
    }

    /**
     * Streaming disk-orphan sweep: walk blob_root() with a recursive iterator (never
     * materializing the file list), batch-probe each chunk of hashes against _file_storage,
     * and unlink files older than the age guard whose hash has no row. A stop requested on
     * $task ends the walk between batches.
     *
     * @return array{0: int, 1: bool} [files deleted, stopped]
     */
    private static function __sweep_disk_orphans(Task_Instance $task, int $min_age_days, int $chunk): array
    {
        $blob_root = Rsx_File_Paths::blob_root();
        if (!is_dir($blob_root)) {
            return [0, false];
        }

        $age_cutoff = time() - $min_age_days * 86400;
        $deleted = 0;
        $pending = [];

        $iterator = new \RecursiveIteratorIterator(self::blob_tree_iterator($blob_root));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            // Age guard: a just-written blob's DB row can commit AFTER the file lands.
            if ($file->getMTime() > $age_cutoff) {
                continue;
            }
            // The blob filename IS the content hash (the two-level shard dirs sit above it).
            $pending[$file->getFilename()] = $file->getPathname();
            if (count($pending) >= $chunk) {
                $deleted += self::__sweep_disk_batch($pending);
                $pending = [];
                $task->heartbeat();
                if ($task->is_stop_requested()) {
                    return [$deleted, true];
                }
            }
        }
        if (!empty($pending)) {
            $deleted += self::__sweep_disk_batch($pending);
        }

        return [$deleted, false];
    }

    /**
     * The blob store's own tree: every shard directory under $blob_root, never a `_` directory
     * at its root - those hold other stores (uploads/_temp is Rsx_Temp_Files') whose files are
     * not blobs and never belong to a blob sweep.
     */
    public static function blob_tree_iterator(string $blob_root): \RecursiveIterator
    {
        return new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($blob_root, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $entry) use ($blob_root): bool {
                return !($entry->isDir() && $entry->getPath() === $blob_root && str_starts_with($entry->getFilename(), '_'));
            }
        );
    }

    /**
     * Probe a batch of {hash => path} against _file_storage and unlink the files whose hash
     * has no row. Each unlink runs under that blob's WRITE lock and re-checks the row inside it,
     * so a creator mid-upload (holding the read lock from its lookup until its reference
     * commits) is waited for, and a blob it stored meanwhile is never destroyed.
     *
     * @param array<string, string> $pending hash => absolute path
     */
    private static function __sweep_disk_batch(array $pending): int
    {
        $known = array_flip(
            DB::table('_file_storage')->whereIn('hash', array_keys($pending))->pluck('hash')->all()
        );

        $deleted = 0;
        foreach ($pending as $hash => $path) {
            if (isset($known[$hash])) {
                continue;
            }

            $removed = File_Blob_Locks::exclusive((string) $hash, function () use ($hash, $path) {
                if (DB::table('_file_storage')->where('hash', $hash)->exists()) {
                    return false;
                }
                if (!is_file($path)) {
                    return false;
                }

                @unlink($path);
                return true;
            });

            if ($removed) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
