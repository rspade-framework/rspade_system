<?php

namespace App\RSpade\Core\Files;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\File_Attachment_Controller;
use App\RSpade\Core\Files\File_Blob_Locks;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Search\Search_Index_Model;

/**
 * File_Storage_Model_Abstract - Physical file storage with deduplication
 *
 * Represents unique physical files on disk. Multiple File_Attachment_Model records
 * can reference the same File_Storage_Model if they have identical content.
 *
 * Hash collision handling: When a hash collision is detected (same hash, different content),
 * the hash is incremented as a base-16 number until an available slot is found.
 *
 * THE BASE OF A SPLIT MODEL. Every member of the framework's File_Storage_Model lives here;
 * `File_Storage_Model.php` beside it is a shell an application replaces by declaring
 * `class File_Storage_Model extends File_Storage_Model_Abstract` under rsx/models/ - so an
 * application's customization is only the members it actually changes, and a frozen
 * clone can never miss the members the framework adds next.
 *
 * See: php artisan rsx:man class_override
 */

/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _file_storage
 *
 * @property string $created_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property string $hash
 * @property int $id
 * @property int $is_indexed
 * @property int $process_attempt_count
 * @property string $process_due_at
 * @property string $process_last_attempt_at
 * @property int $process_soft_failure_count
 * @property string $render_error
 * @property int $render_status_id
 * @property string $rendered_at
 * @property int $size
 * @property string $updated_at
 * @property int $updated_by_id
 * @property int $updated_by_type
 *
 * @property-read string $render_status_id__label
 * @property-read string $render_status_id__constant
 *
 * @method static array render_status_id__enum() Get all enum definitions with full metadata
 * @method static array render_status_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array render_status_id__enum_labels() Get simple id => label map
 * @method static array render_status_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
abstract class File_Storage_Model_Abstract extends Rsx_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const RENDER_STATUS_NOT_REQUIRED = 1;
    const RENDER_STATUS_PENDING = 2;
    const RENDER_STATUS_RENDERED = 3;
    const RENDER_STATUS_FAILED = 4;
    /**
     * Enum field definitions.
     *
     * render_status_id is the DOCUMENT RENDER lifecycle, driven by Document_Render_Service. It
     * lives on the blob rather than the attachment because both products of a render - the PDF
     * rendition (tmp/renditions/{hash}.pdf) and the thumbnail cache key - are already
     * content-addressed on the blob hash, so N attachments sharing one blob share one render.
     *
     *   NOT_REQUIRED  nothing to render (an image, a zip, a plain PDF). The DEFAULT: store_blob()
     *                 sees only bytes and cannot know the mime, so the ATTACHMENT flips the blob
     *                 to PENDING when its pipeline mime is convertible.
     *   PENDING       a rendition is owed and no attempt has reported yet. Shown as "preparing".
     *   RENDERED      rendition on disk, rendered_at set.
     *   FAILED        the last attempt failed; render_error carries the reason. Whether the
     *                 worker will try again is not in this value - it is the work queue's
     *                 answer (see THE WORK QUEUE below): a FAILED blob that still has a
     *                 process_due_at is waiting for its next attempt, one without is failed for
     *                 good and re-renders only on an operator's rsx:documents:rerender. Either
     *                 way every screen shows the failed-document preview, never "preparing".
     *
     * @var array
     */
    public static $enums = [
        'render_status_id' => [
            1 => ['constant' => 'RENDER_STATUS_NOT_REQUIRED', 'label' => 'Not Required', 'badge' => 'bg-secondary', 'order' => 1],
            2 => ['constant' => 'RENDER_STATUS_PENDING', 'label' => 'Pending', 'badge' => 'bg-warning', 'order' => 2],
            3 => ['constant' => 'RENDER_STATUS_RENDERED', 'label' => 'Rendered', 'badge' => 'bg-success', 'order' => 3],
            4 => ['constant' => 'RENDER_STATUS_FAILED', 'label' => 'Failed', 'badge' => 'bg-danger', 'order' => 4],
        ],
    ];
    public static $rel = [];

    /**
     * UNBOUNDED: this table's row count grows with customer activity, not with the
     * codebase, so no reader may assume the set is small.
     * One row per DISTINCT blob ever uploaded.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule, which flags a bare ->get() /
     * ->pluck() on this model in framework code and points at ->result_set(). It is a
     * DECLARATION, not a runtime gate - a small, well-narrowed query here is still fine.
     * See: the Do The Whole Job section of CLAUDE.md.
     *
     * @var bool
     */
    public static $unbounded = true;

    /**
     * The table associated with the model
     *
     * @var string
     */
    protected $table = '_file_storage';

    /**
     * The attributes that should be cast to native types
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Cascade cleanup: extracted-text index rows key on this blob (indexable =
     * File_Storage_Model), so when the last-referencing attachment deletes the
     * storage row the index row must go with it - otherwise it lingers as
     * unreachable cruft (search_text() only ever joins live file_storage_ids).
     */
    protected static function boot()
    {
        parent::boot();

        static::deleted(function ($storage) {
            Search_Index_Model::forModel('File_Storage_Model', $storage->id)->delete();
        });
    }

    /**
     * Get all logical file attachments that reference this physical file
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function file_attachments()
    {
        return $this->hasMany(\App\RSpade\Core\Files\File_Attachment_Model::class, 'file_storage_id');
    }

    /**
     * Get the storage path for this file
     * Based on the hash for efficient file system distribution
     *
     * @return string
     */
    public function get_storage_path()
    {
        // RELATIVE display path (e.g. "uploads/ab/c1/abc123..."), derived on every call
        // and persisted nowhere. The absolute on-disk path is get_full_path(), which
        // routes through Rsx_File_Paths so a test run relocates the blob store (B-38).
        return 'uploads/' . static::__hash_subpath($this->hash);
    }

    /**
     * The hash-relative sub-path within the blob root: {dir1}/{dir2}/{hash}.
     * Splitting the hash into two levels keeps directories from growing unbounded.
     *
     * @param string $hash
     * @return string
     */
    /**
     * Where a blob with this hash lives, relative to the blob root ("ab/cd/<hash>"). For
     * reporting - rsx:files:unreferenced_blobs prints it; reading bytes goes through the model.
     */
    public static function relative_blob_path(string $hash): string
    {
        return static::__hash_subpath($hash);
    }

    protected static function __hash_subpath($hash)
    {
        $dir1 = substr($hash, 0, 2);
        $dir2 = substr($hash, 2, 2);

        return "{$dir1}/{$dir2}/{$hash}";
    }

    /**
     * Get the full file system path
     *
     * @return string
     */
    public function get_full_path()
    {
        // Resolve through the single choke point so a test run's storage-root
        // override relocates blob reads/writes off the shared store (B-38).
        return Rsx_File_Paths::blob_root() . '/' . static::__hash_subpath($this->hash);
    }

    /**
     * Human-readable byte size (e.g. "1.5 MB").
     *
     * @return string
     */
    public function get_human_size()
    {
        return bytes_to_human((int) $this->size);
    }

    /**
     * Check if the physical file exists on disk
     *
     * @return bool
     */
    public function file_exists()
    {
        return file_exists($this->get_full_path());
    }

    /**
     * Increment a hexadecimal hash value as a base-16 number
     *
     * Treats the entire hex string as a single base-16 number and increments it.
     * Example: "ff" becomes "100", "abc" becomes "abd"
     *
     * @param string $hash Hexadecimal hash string
     * @return string Incremented hash (may be longer than input)
     */
    public static function increment_base16_value($hash)
    {
        // Convert hex string to decimal
        $decimal = gmp_init($hash, 16);

        // Increment by 1
        $decimal = gmp_add($decimal, 1);

        // Convert back to hex
        $incremented = gmp_strval($decimal, 16);

        return $incremented;
    }

    /**
     * Store a temp file's bytes into the blob store and record the reference that pins them.
     *
     * THE ONE WAY BYTES ENTER THE STORE. Content-addressed (sha256), deduplicated, immutable.
     * $reference receives the storage row and writes the row that points at it (an attachment,
     * an email part); store_blob() returns the storage row once $reference has returned.
     *
     * WHY THE CALLBACK: a deduplicated row can be the one File_Disposal_Service is about to
     * release. The blob's read lock (File_Blob_Locks, file_blob:<hash>) is taken BEFORE the row
     * is looked up or created and held until the reference written inside $reference is
     * COMMITTED - at once with no transaction open, at the outermost commit or rollback inside
     * one. A destroyer re-checks the reference tables under the matching write lock, so it either
     * sees the reference or finishes before the lookup runs. A reference recorded after
     * store_blob() returned would have no such guarantee; record it inside $reference.
     *
     * A $reference that records nothing is legal (the blob is then an orphan for the disposal
     * sweeps), and one that throws leaves whatever bytes were written for the same sweeps.
     *
     * @param string   $temp_file_path Absolute path to a readable temp file.
     * @param callable $reference      fn (File_Storage_Model $storage): mixed - writes the reference.
     * @return static
     */
    public static function store_blob(string $temp_file_path, callable $reference): File_Storage_Model_Abstract
    {
        return File_Blob_Locks::reference_scope(function () use ($temp_file_path, $reference) {
            $storage = static::__find_or_create($temp_file_path);
            $reference($storage);

            return $storage;
        });
    }

    /**
     * store_blob() for bytes held in memory: the bytes go to a scratch file in the temp directory,
     * are stored, and the scratch file is removed whatever happens. $reference is
     * the same contract as store_blob()'s - record the reference row inside it.
     *
     * @param string   $bytes
     * @param callable $reference fn (File_Storage_Model $storage): mixed - writes the reference.
     * @return static
     */
    public static function store_bytes(string $bytes, callable $reference): File_Storage_Model_Abstract
    {
        $temp_path = sys_get_temp_dir() . '/rspade_blob_' . random_hash() . '.bin';

        if (file_put_contents_safe($temp_path, $bytes) === false) {
            throw new \RuntimeException('Failed to write blob bytes to a temporary file.');
        }

        try {
            return static::store_blob($temp_path, $reference);
        } finally {
            if (file_exists($temp_path)) {
                @unlink($temp_path);
            }
        }
    }

    /**
     * An open read stream over the blob's bytes. The caller closes it.
     *
     * The blob store's byte-access surface is this, read_bytes() and the two response
     * builders: a consumer never needs the blob's location, so the store can move.
     *
     * @return resource
     */
    public function read_stream()
    {
        $path = $this->get_full_path();
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("File storage #{$this->id} has no bytes on disk.");
        }

        return $stream;
    }

    /**
     * The blob's whole contents.
     */
    public function read_bytes(): string
    {
        $bytes = @file_get_contents($this->get_full_path());
        if ($bytes === false) {
            throw new \RuntimeException("File storage #{$this->id} has no bytes on disk.");
        }

        return $bytes;
    }

    /**
     * A download (attachment disposition) response for the blob, hardened the way every file
     * response is (File_Attachment_Controller::harden_file_response()). Authorizing the
     * caller is the caller's job.
     *
     * @param string $file_name The name the browser saves it under.
     * @param string|null $mime_type Content-Type; sniffed from the bytes when null.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download_response(string $file_name, ?string $mime_type = null)
    {
        return File_Attachment_Controller::harden_file_response(
            \Illuminate\Support\Facades\Response::download($this->__existing_path(), $file_name, $this->__type_header($mime_type))
        );
    }

    /**
     * An inline-disposition response for the blob, hardened like download_response().
     *
     * @param string $file_name
     * @param string|null $mime_type
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function inline_response(string $file_name, ?string $mime_type = null)
    {
        $headers = $this->__type_header($mime_type);
        $headers['Content-Disposition'] = \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition('inline', $file_name, 'file');

        return File_Attachment_Controller::harden_file_response(
            \Illuminate\Support\Facades\Response::file($this->__existing_path(), $headers)
        );
    }

    private function __existing_path(): string
    {
        $path = $this->get_full_path();
        if (!file_exists($path)) {
            throw new \RuntimeException("File storage #{$this->id} has no bytes on disk.");
        }

        return $path;
    }

    private function __type_header(?string $mime_type): array
    {
        return $mime_type !== null && $mime_type !== '' ? ['Content-Type' => $mime_type] : [];
    }

    /**
     * Put a temp file's bytes at an absolute blob path, creating the directory it lives in.
     *
     * The one place bytes enter the store, shared by the create path and the reuse-the-row
     * repair above so both write them identically.
     *
     * @param string $temp_file_path
     * @param string $full_path
     * @return void
     */
    private static function __write_blob_bytes(string $temp_file_path, string $full_path): void
    {
        $directory = dirname($full_path);
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        copy($temp_file_path, $full_path);
    }

    /**
     * Find or create a file storage record with collision handling. Runs only inside
     * store_blob()'s reference scope: every hash it examines is read-locked before its row is
     * looked up (File_Blob_Locks::hold_read() throws outside a scope).
     *
     * Algorithm:
     * 1. Hash the uploaded file (SHA-256)
     * 2. Check if file exists on disk at hash path
     * 3. If exists: byte-by-byte compare with uploaded file
     *    - Match: return existing record
     *    - No match: increment hash, repeat from step 2
     * 4. If the record exists but its file does not: rewrite the bytes at that record's
     *    own path and return it - the row and its attachments stay valid
     * 5. If no record exists: save file to disk, create record
     *
     * @param string $temp_file_path Path to uploaded temporary file
     * @return static
     */
    private static function __find_or_create(string $temp_file_path)
    {
        if (!file_exists($temp_file_path)) {
            shouldnt_happen("Temporary file does not exist: {$temp_file_path}");
        }

        $original_hash = hash_file('sha256', $temp_file_path);
        $current_hash = $original_hash;
        $file_size = filesize($temp_file_path);

        // Loop until we find a matching file or an available slot
        while (true) {
            // Lock each hash before its row is consulted. A collision moves on to the next hash
            // and locks that one too, so the lock always guards the hash the row will carry.
            File_Blob_Locks::hold_read($current_hash);

            // Check if a record exists for this hash
            $existing_record = static::where('hash', $current_hash)->first();

            if ($existing_record) {
                // Record exists - check if physical file exists
                if ($existing_record->file_exists()) {
                    // Byte-by-byte compare
                    $existing_path = $existing_record->get_full_path();

                    if (static::__files_match($temp_file_path, $existing_path)) {
                        // Perfect match - reuse existing storage
                        return $existing_record;
                    } else {
                        // Hash collision - increment and try again
                        $current_hash = static::increment_base16_value($current_hash);
                        continue;
                    }
                } else {
                    // The row is here and its bytes are not. Something outside the framework
                    // removed the blob from disk - the row's id, and every attachment pointing
                    // at it, are still perfectly valid.
                    //
                    // So this REUSES the row: write the bytes back at its own path and return
                    // it. Falling through to the create path instead would insert a second row
                    // carrying the same hash and die on uk_file_hashes_hash, turning a
                    // recoverable disk problem into a failed upload.
                    //
                    // render_status_id is deliberately left alone. The restored bytes hash to
                    // the same value, so a RENDERED blob's rendition and thumbnail caches still
                    // describe them exactly; re-queueing would redo work whose output is already
                    // correct. For the same reason Document_Render_Service::kick() is not called
                    // here - no new blob entered the store.
                    error_log(
                        '[RSX] File storage id ' . $existing_record->id . ' (hash ' . $current_hash
                        . ') had no file on disk; rewriting its bytes from the incoming copy.'
                    );

                    static::__write_blob_bytes($temp_file_path, $existing_record->get_full_path());

                    if ((int) $existing_record->size !== (int) $file_size) {
                        $existing_record->size = $file_size;
                    }
                    $existing_record->save();

                    return $existing_record;
                }
            } else {
                // No record for this hash - available slot
                break;
            }
        }

        // Create new storage record and save file. Resolve through the choke point
        // so a test run writes into its isolated storage root (B-38).
        $full_path = Rsx_File_Paths::blob_root() . '/' . static::__hash_subpath($current_hash);

        static::__write_blob_bytes($temp_file_path, $full_path);

        // Create record
        $file_storage = new static();
        $file_storage->hash = $current_hash;
        $file_storage->size = $file_size;
        $file_storage->save();

        // Prompt kick for the document render worker (PDF rendition + text extraction). This is
        // the single choke point for a NEW unique blob entering the store (covers upload,
        // create_from_*, external materialization); a dedup hit returns earlier and never reaches
        // here. The row was BORN in the work queue: is_indexed defaults to 0 (its text is owed)
        // and process_due_at defaults to the insert time, in the same INSERT - so even without
        // this kick the 10-minute sweeper takes it; the dispatch just drains promptly.
        // #[Exclusive] coalesces concurrent kicks. The blob is NOT_REQUIRED at this point - the
        // attachment adds the rendition half of the work by calling request_render() once it
        // knows the mime.
        Document_Render_Service::kick();

        return $file_storage;
    }

    /**
     * Queue this blob for document rendering: NOT_REQUIRED -> PENDING, then kick the worker.
     *
     * IDEMPOTENT BY DESIGN, and that is the whole dedup story. Only NOT_REQUIRED moves; a blob
     * already PENDING (queued), RENDERED (done) or FAILED is left exactly as it is. So the
     * second, tenth and hundredth attachment created over the same deduplicated bytes call this
     * freely and cost nothing - and an upload never hands a FAILED blob fresh attempts.
     *
     * Called by File_Attachment_Model when its pipeline mime matches rsx.documents.renderers -
     * the attachment is the only layer that knows the filename and extension.
     *
     * @return void
     */
    public function request_render(): void
    {
        if ((int) $this->render_status_id !== static::RENDER_STATUS_NOT_REQUIRED) {
            return;
        }

        $this->requeue_render();
    }

    /**
     * Put this blob back in the render queue UNCONDITIONALLY: -> PENDING, artifacts of any earlier
     * attempt cleared, due now with a full set of attempts, worker kicked.
     *
     * The difference from request_render() is the absence of the state guard, so every caller of
     * it is an explicit decision that the previous outcome is no longer valid:
     *   - a serving path that finds the rendition of a RENDERED blob GONE from disk (the LRU
     *     cache evicted it). Self-healing: the request serves a placeholder and the worker
     *     rebuilds the rendition, rather than showing anyone an error page over a cache eviction.
     *     A serving path never calls this for a FAILED blob - a web request must not be able to
     *     hand a document that cannot be rendered another set of attempts.
     *   - the operator command rsx:documents:rerender.
     *
     * $kick is the ONE knob: pass false to record the queued state WITHOUT spawning a worker. The
     * only callers that want that are the ones which will run the document themselves and need to
     * observe each step (the /dev/attachment_thumbnail page, which resets a document to PENDING so
     * a human - or Playwright - can watch the placeholder swap when the render is triggered by
     * hand). The row is still queued either way, so the 10-minute sweeper is the backstop.
     *
     * @param bool $kick Whether to ask for a worker pass now.
     * @return void
     */
    public function requeue_render(bool $kick = true): void
    {
        static::where('id', $this->id)->update(array_merge(
            static::render_pending_values(),
            static::work_owed_values()
        ));
        $this->__reload_row();

        if ($kick) {
            Document_Render_Service::kick();
        }
    }

    /**
     * The column values of "a rendition is owed and nothing has been attempted": PENDING with
     * every artifact of an earlier attempt cleared. For a set UPDATE; always written together
     * with work_owed_values(), because a PENDING blob the queue does not know about is never
     * rendered.
     *
     * @return array
     */
    public static function render_pending_values(): array
    {
        return [
            'render_status_id' => static::RENDER_STATUS_PENDING,
            'rendered_at' => null,
            'render_error' => null,
        ];
    }

    // =========================================================================================
    // THE WORK QUEUE
    //
    // Document_Render_Service takes blobs in the order of ONE column:
    //
    //   process_due_at IS NULL   the worker has nothing to do for this blob - nothing is owed,
    //                            or its attempts are spent.
    //   process_due_at = a time  the blob owes work (a rendition: render_status_id PENDING or
    //                            FAILED; its text: is_indexed = 0) and may be attempted at or
    //                            after that time.
    //
    // process_attempt_count is how many attempts have been charged since the blob last entered
    // the queue. An attempt is charged BEFORE it starts and pushes the due time forward by the
    // retry interval, so an attempt that dies without a word has still been counted, and the
    // blob it was working on is no longer first in line. process_last_attempt_at is when the
    // last one was charged.
    //
    // A SOFT failure charges nothing. It is the environment, not the document - a tool the pass
    // needs is not installed, the sandbox image is absent, the source cannot be read - found by
    // a soft_failure() check BEFORE any attempt. process_soft_failure_count counts the soft
    // failures in a row, and is the exponent of their backoff: the next check is
    // retry_after_minutes * 2^count away (30 minutes, 1 hour, 2 hours ...), capped at
    // soft_retry_max_minutes, with no end. A charged attempt restarts it.
    //
    // Every write of these four columns is one of the methods below. The times are the
    // DATABASE's clock (NOW(3)) on both sides of every comparison.
    // =========================================================================================

    /**
     * The column values of "this blob owes work": due now, no attempts charged.
     *
     * THE one statement of it. Anything that makes a blob owe work - a render queued, its text
     * marked un-extracted - writes these beside it, in a set UPDATE or through mark_work_owed().
     * The blob starts over: no attempts charged, none on record, no soft-failure backoff.
     *
     * @return array
     */
    public static function work_owed_values(): array
    {
        return [
            'process_due_at' => DB::raw('NOW(3)'),
            'process_attempt_count' => 0,
            'process_soft_failure_count' => 0,
            'process_last_attempt_at' => null,
        ];
    }

    /**
     * This blob owes work: due now, with a full set of attempts. The caller has already said
     * WHAT is owed (render_status_id, is_indexed).
     *
     * @return void
     */
    public function mark_work_owed(): void
    {
        static::where('id', $this->id)->update(static::work_owed_values());
        $this->__reload_row();
    }

    /**
     * The blobs the worker may take now, as a query: one range read from the front of
     * idx_process_due however large the table is. Order it with ->orderBy('process_due_at')
     * ->orderBy('id') to take them earliest-due first.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function work_due_query()
    {
        return static::where('process_due_at', '<=', DB::raw('NOW(3)'));
    }

    /**
     * Charge one attempt to this blob and push its due time one retry interval ahead - the
     * step that comes BEFORE the attempt runs. The attempt's time is recorded, and a soft-failure
     * backoff in progress ends: a pass that runs is not a soft failure.
     *
     * Guarded: it succeeds only if the row is still due and still carries the attempt count
     * this instance read, so two processes can never both charge - and run - the same attempt.
     *
     * @return bool False when another process took the blob first; nothing was changed.
     */
    public function charge_work_attempt(): bool
    {
        $minutes = static::work_retry_after_minutes();

        $charged = static::where('id', $this->id)
            ->where('process_due_at', '<=', DB::raw('NOW(3)'))
            ->where('process_attempt_count', (int) $this->process_attempt_count)
            ->update([
                'process_attempt_count' => DB::raw('process_attempt_count + 1'),
                'process_due_at' => DB::raw("NOW(3) + INTERVAL {$minutes} MINUTE"),
                'process_last_attempt_at' => DB::raw('NOW(3)'),
                'process_soft_failure_count' => 0,
            ]);

        if ($charged !== 1) {
            return false;
        }

        $this->__reload_row();

        return true;
    }

    /**
     * Record a SOFT failure: the environment cannot process this blob yet (a soft_failure()
     * check answered before any attempt), so nothing is charged - process_attempt_count and
     * process_last_attempt_at are untouched - and the blob is offered again on the soft
     * backoff: retry_after_minutes * 2^(soft failures before this one), capped at
     * soft_retry_max_minutes. The first soft failure waits one retry interval, the next twice
     * that, and so on, for as long as the environment stays broken.
     *
     * The caller has already recorded WHAT failed (render_error, the index row).
     *
     * @return void
     */
    public function record_soft_failure(): void
    {
        $retry = static::work_retry_after_minutes();
        $cap = static::work_soft_retry_max_minutes();

        // The exponent is clamped so POW() stays a small finite number however long the
        // failures run: 2^32 retry intervals is past any cap a config could sensibly hold, and
        // LEAST() brings it down to the cap. MySQL applies a single-table UPDATE's assignments
        // left to right, so the due time is computed from the count BEFORE the increment.
        static::where('id', $this->id)->update([
            'process_due_at' => DB::raw(
                "NOW(3) + INTERVAL CAST(LEAST({$retry} * POW(2, LEAST(process_soft_failure_count, 32)), {$cap}) AS UNSIGNED) MINUTE"
            ),
            'process_soft_failure_count' => DB::raw('process_soft_failure_count + 1'),
        ]);
        $this->__reload_row();
    }

    /**
     * Make this blob due now without charging anything - for a caller that has reason to
     * believe the next attempt should not wait out its interval (a page viewing the document,
     * once the cause of its failure has gone). The attempt the worker then makes is charged
     * like any other.
     *
     * Guarded: it touches only a blob still in the queue whose due time is still ahead. A blob
     * outside the queue (nothing owed, or its attempts spent) is never put back by this - that
     * is requeue_render() / mark_work_owed(), an operator's decision.
     *
     * @return bool True when the due time was moved; false when the blob is not in the queue or
     *              is already due.
     */
    public function pull_work_forward(): bool
    {
        $pulled = static::where('id', $this->id)
            ->whereNotNull('process_due_at')
            ->where('process_due_at', '>', DB::raw('NOW(3)'))
            ->update(['process_due_at' => DB::raw('NOW(3)')]);

        $this->__reload_row();

        return $pulled === 1;
    }

    /**
     * Take this blob out of the queue. $attempts_spent says how it left: false = everything
     * owed was produced (the counter returns to 0), true = its attempts are spent and what is
     * still missing is failed for good (the counter stays at the limit, which is how a
     * permanently failed blob reads). Either way no soft-failure backoff is left running;
     * process_last_attempt_at is kept as the record of when it last ran.
     *
     * @param bool $attempts_spent
     * @return void
     */
    public function close_work(bool $attempts_spent = false): void
    {
        static::where('id', $this->id)->update([
            'process_due_at' => null,
            'process_attempt_count' => $attempts_spent ? static::work_max_attempts() : 0,
            'process_soft_failure_count' => 0,
        ]);
        $this->__reload_row();
    }

    /**
     * Owed work that cannot be attempted right now (a rendition while LibreOffice is switched
     * off, text while extraction is switched off) is offered again one retry interval from now,
     * with no attempt charged: the blob waits for the switch without holding the front of the
     * queue.
     *
     * A deferral starts the blob's accounting over - no attempts, none on record, and no
     * soft-failure backoff: the work was switched off, which is a decision, not a failure, and
     * when it is switched back on the blob is owed a full set of attempts.
     *
     * @return void
     */
    public function defer_work(): void
    {
        $minutes = static::work_retry_after_minutes();

        static::where('id', $this->id)->update([
            'process_due_at' => DB::raw("NOW(3) + INTERVAL {$minutes} MINUTE"),
            'process_attempt_count' => 0,
            'process_soft_failure_count' => 0,
            'process_last_attempt_at' => null,
        ]);
        $this->__reload_row();
    }

    /**
     * Read this row back after a set UPDATE wrote it, so the instance carries what the database
     * now holds (the due time is the database's clock, not a value this process computed).
     *
     * @return void
     */
    private function __reload_row(): void
    {
        $this->setRawAttributes(static::where('id', $this->id)->firstOrFail()->getRawOriginal(), true);
    }

    /**
     * Attempts a blob gets each time it enters the queue (rsx.documents.max_attempts).
     *
     * @return int
     */
    public static function work_max_attempts(): int
    {
        return max(1, (int) config('rsx.documents.max_attempts'));
    }

    /**
     * Minutes between a failed attempt and the next one (rsx.documents.retry_after_minutes).
     *
     * @return int
     */
    public static function work_retry_after_minutes(): int
    {
        return max(1, (int) config('rsx.documents.retry_after_minutes'));
    }

    /**
     * The longest wait between two checks of a soft-failed blob
     * (rsx.documents.soft_retry_max_minutes): a whole number of minutes, never below the retry
     * interval the backoff starts from.
     *
     * @return int
     */
    public static function work_soft_retry_max_minutes(): int
    {
        $minutes = config('rsx.documents.soft_retry_max_minutes');

        if (!is_int($minutes) && !(is_string($minutes) && ctype_digit($minutes))) {
            throw new \Exception('rsx.documents.soft_retry_max_minutes must be a whole number of minutes, got ' . var_export($minutes, true));
        }

        $minutes = (int) $minutes;
        $retry = static::work_retry_after_minutes();

        if ($minutes < $retry) {
            throw new \Exception("rsx.documents.soft_retry_max_minutes ({$minutes}) must not be below rsx.documents.retry_after_minutes ({$retry}): it caps a backoff that starts at the retry interval");
        }

        return $minutes;
    }

    /**
     * True when this blob's last pass failed SOFTLY and it is waiting to be checked again: it
     * is in the queue with a soft failure on record.
     *
     * @return bool
     */
    public function is_soft_failed(): bool
    {
        return $this->process_due_at !== null && (int) $this->process_soft_failure_count > 0;
    }

    /**
     * True when the worker will not attempt this blob again by itself: its render FAILED and it
     * is no longer in the queue.
     *
     * @return bool
     */
    public function is_render_failed_for_good(): bool
    {
        return (int) $this->render_status_id === static::RENDER_STATUS_FAILED && $this->process_due_at === null;
    }

    /**
     * Get the extracted-text search index row for this blob, or null when no extraction attempt
     * has recorded an outcome yet.
     *
     * @return Search_Index_Model|null
     */
    public function get_search_index(): ?Search_Index_Model
    {
        return Search_Index_Model::forModel('File_Storage_Model', $this->id)->first();
    }

    /**
     * Compare two files byte-by-byte
     *
     * @param string $file1_path
     * @param string $file2_path
     * @return bool True if files are identical
     */
    protected static function __files_match($file1_path, $file2_path)
    {
        // Quick size check first
        if (filesize($file1_path) !== filesize($file2_path)) {
            return false;
        }

        // Byte-by-byte comparison
        $fp1 = fopen($file1_path, 'rb');
        $fp2 = fopen($file2_path, 'rb');

        $match = true;
        while (!feof($fp1) && !feof($fp2)) {
            if (fread($fp1, 8192) !== fread($fp2, 8192)) {
                $match = false;
                break;
            }
        }

        fclose($fp1);
        fclose($fp2);

        return $match;
    }


    /**
     * How much disk the blob store really holds, and what holds it.
     *
     * THE STORE IS CONTENT-ADDRESSED: identical bytes are one blob however many
     * attachments point at them. So the sum of every attachment's file size is NOT the
     * disk in use - it counts each blob once per REFERENCE, and on a store with heavy
     * reuse it overstates by orders of magnitude. This is the one place that figure is
     * computed, with the naive sum reported beside it so the difference is visible.
     *
     * Every figure is an aggregate computed in the database; no rows are loaded.
     *
     *   stored        everything in the store, whatever references it (attachments,
     *                 email and task attachments, temp files, blobs awaiting disposal):
     *                 {blobs, bytes}. THE DISK FIGURE.
     *   attachments   the live attachments (not deleted):
     *                 {count, blobs, bytes, naive_bytes} - bytes is deduplicated,
     *                 naive_bytes is the per-reference sum.
     *   retained      attachments deleted and still inside the retention window:
     *                 {count, exclusive_bytes} - the bytes that would be released if they
     *                 were destroyed today (blobs no live attachment also points at).
     *   by_category   the live attachments split by fileable_category ('' for none):
     *                 category => {count, blobs, bytes, naive_bytes, exclusive_bytes}.
     *                 `bytes` is deduplicated WITHIN the category, so the categories do not
     *                 add up to the total when they share blobs. `exclusive_bytes` counts
     *                 only blobs no other category references - what that category costs
     *                 on its own, which is the figure to quote for "how much disk do my
     *                 version snapshots take": a blob a snapshot shares with a live
     *                 document would be on disk either way.
     *
     * INSTALL-WIDE, across every site: a blob is shared between tenants by design, so
     * disk in use is not a per-tenant number. For an operator's capacity report, not a
     * tenant-facing screen. Read-only.
     *
     * @return array
     */
    public static function usage_report(): array
    {
        $int = fn ($value) => (int) ($value ?? 0);

        $stored = DB::selectOne('SELECT COUNT(*) AS blobs, SUM(size) AS bytes FROM _file_storage');

        $live = DB::selectOne(
            'SELECT COUNT(*) AS attachments, COUNT(DISTINCT a.file_storage_id) AS blobs, SUM(s.size) AS naive_bytes
             FROM _file_attachments a
             JOIN _file_storage s ON s.id = a.file_storage_id
             WHERE a.deleted_at IS NULL'
        );

        $live_bytes = DB::selectOne(
            'SELECT SUM(s.size) AS bytes
             FROM _file_storage s
             WHERE EXISTS (SELECT 1 FROM _file_attachments a WHERE a.file_storage_id = s.id AND a.deleted_at IS NULL)'
        );

        $retained = DB::selectOne(
            'SELECT COUNT(*) AS attachments FROM _file_attachments WHERE deleted_at IS NOT NULL AND destroyed_at IS NULL'
        );

        $retained_bytes = DB::selectOne(
            'SELECT SUM(s.size) AS bytes
             FROM _file_storage s
             WHERE EXISTS (SELECT 1 FROM _file_attachments a WHERE a.file_storage_id = s.id AND a.deleted_at IS NOT NULL AND a.destroyed_at IS NULL)
               AND NOT EXISTS (SELECT 1 FROM _file_attachments a WHERE a.file_storage_id = s.id AND a.deleted_at IS NULL)'
        );

        $by_category = [];

        // One row per category: bounded by how many categories the application uses.
        foreach (DB::select(
            "SELECT COALESCE(a.fileable_category, '') AS category, COUNT(*) AS attachments, SUM(s.size) AS naive_bytes
             FROM _file_attachments a
             JOIN _file_storage s ON s.id = a.file_storage_id
             WHERE a.deleted_at IS NULL
             GROUP BY COALESCE(a.fileable_category, '')"
        ) as $row) {
            $by_category[$row->category] = [
                'count' => $int($row->attachments),
                'blobs' => 0,
                'bytes' => 0,
                'naive_bytes' => $int($row->naive_bytes),
                'exclusive_bytes' => 0,
            ];
        }

        foreach (DB::select(
            "SELECT pairs.category, COUNT(*) AS blobs, SUM(s.size) AS bytes,
                    SUM(CASE WHEN spread.categories = 1 THEN s.size ELSE 0 END) AS exclusive_bytes
             FROM (
                 SELECT DISTINCT COALESCE(fileable_category, '') AS category, file_storage_id
                 FROM _file_attachments
                 WHERE deleted_at IS NULL
             ) pairs
             JOIN _file_storage s ON s.id = pairs.file_storage_id
             JOIN (
                 SELECT file_storage_id, COUNT(DISTINCT COALESCE(fileable_category, '')) AS categories
                 FROM _file_attachments
                 WHERE deleted_at IS NULL
                 GROUP BY file_storage_id
             ) spread ON spread.file_storage_id = pairs.file_storage_id
             GROUP BY pairs.category"
        ) as $row) {
            $by_category[$row->category]['blobs'] = $int($row->blobs);
            $by_category[$row->category]['bytes'] = $int($row->bytes);
            $by_category[$row->category]['exclusive_bytes'] = $int($row->exclusive_bytes);
        }

        ksort($by_category);

        return [
            'stored' => ['blobs' => $int($stored->blobs), 'bytes' => $int($stored->bytes)],
            'attachments' => [
                'count' => $int($live->attachments),
                'blobs' => $int($live->blobs),
                'bytes' => $int($live_bytes->bytes),
                'naive_bytes' => $int($live->naive_bytes),
            ],
            'retained' => [
                'count' => $int($retained->attachments),
                'exclusive_bytes' => $int($retained_bytes->bytes),
            ],
            'by_category' => $by_category,
        ];
    }

    /**
     * Calculate hash for file content
     *
     * @param string $content
     * @return string
     */
    public static function calculate_hash($content)
    {
        return hash('sha256', $content);
    }

    /**
     * Calculate hash for a file path
     *
     * @param string $file_path
     * @return string|false
     */
    public static function calculate_file_hash($file_path)
    {
        if (!file_exists($file_path)) {
            return false;
        }

        return hash_file('sha256', $file_path);
    }
}
