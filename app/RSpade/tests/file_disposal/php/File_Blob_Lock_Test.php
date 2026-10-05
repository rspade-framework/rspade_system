<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\FileDisposal\Php;

use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Files\File_Blob_Locks;
use App\RSpade\Core\Files\File_Disposal_Service;
use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Files\Rsx_File_Paths;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Models\Email_Attachment_Model;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Race-proof blob release (File_Blob_Locks + File_Disposal_Service). Proves: a reference that
 * exists when the storage row is deleted makes the release fail safe with the bytes kept (the
 * foreign keys are the database's half of the guarantee); a release WAITS for a reference
 * creator holding the blob's read lock in another process; a reference's read lock lasts until
 * its transaction commits; a release inside a transaction unlinks only at the outermost commit
 * and keeps the file on a rollback; one process may record and release the same blob in one
 * transaction; a blob pinned by both an attachment and an email part survives until both are
 * gone; the disk-orphan sweep removes only files no storage row claims.
 *
 * Commits (the unlink happens at COMMIT, and a second process must see the rows), so the class
 * opts out of the per-test transaction and runs on a fresh migrated DB; each test clears the
 * file tables first. The runner relocates the file subsystem to test-storage, so nothing here
 * reaches the developer store.
 */
class File_Blob_Lock_Test extends Rsx_Test_Abstract
{
    protected static $requires_db_reset = true;
    protected static $use_database_transactions = false;

    public static function setup()
    {
        static::__reset();
    }

    public static function teardown()
    {
        static::__reset();
    }

    private static function __reset(): void
    {
        DB::statement('DELETE FROM _email_queue');
        DB::statement('DELETE FROM _file_attachments');
        DB::statement('DELETE FROM _file_storage');
    }

    private static function __site_id(): int
    {
        $id = (int) DB::selectOne('SELECT id FROM sites ORDER BY id LIMIT 1')->id;
        Session::set_site_id($id);
        return $id;
    }

    private static function __make(string $content, string $name = 'doc.txt'): File_Attachment_Model
    {
        return File_Attachment_Model::create_from_string($content, $name, ['site_id' => static::__site_id()]);
    }

    /** A storage row nothing references (store_blob() with a reference callback that records nothing). */
    private static function __orphan_blob(string $content): File_Storage_Model
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rsx_blob_lock_');
        file_put_contents($tmp, $content);
        try {
            return File_Storage_Model::store_blob($tmp, fn () => null);
        } finally {
            @unlink($tmp);
        }
    }

    private static function __email_row(): Email_Queue_Model
    {
        $id = (int) DB::table('_email_queue')->insertGetId([
            'site_id' => static::__site_id(),
            'to_address' => 'blob-lock-' . uniqid() . '@example.com',
            'subject' => 'Blob lock probe',
            'email_class' => 'Mail_Notification_Fixture_Email',
            'category_id' => Email_Queue_Model::CATEGORY_NOTIFICATION,
            'status_id' => Email_Queue_Model::STATUS_SENT,
            'attempt_count' => 1,
            'created_at' => now(),
        ]);

        return Email_Queue_Model::without_site_scope(fn () => Email_Queue_Model::find($id));
    }

    private static function __stats(string $hash): array
    {
        return RsxLocks::get_lock_stats(RsxLocks::CLUSTER_LOCK, File_Blob_Locks::name($hash));
    }

    /** The release step that runs after the reference re-check, called with the lock path bypassed. */
    private static function __release_step(File_Storage_Model $storage): bool
    {
        $method = new ReflectionMethod(File_Disposal_Service::class, '__release_unreferenced_blob');
        return $method->invoke(null, $storage);
    }

    // -------------------------------------------------------------------------

    public static function test_an_attachment_recorded_after_the_recheck_keeps_the_bytes()
    {
        $att = static::__make('late-attachment-' . uniqid());
        $storage = File_Storage_Model::find($att->file_storage_id);
        $path = $storage->get_full_path();

        // The attachment stands in for a reference that appeared after release's re-check.
        static::__assert_false(static::__release_step($storage), 'the storage delete is refused by the foreign key');
        static::__assert_not_null(File_Storage_Model::find($storage->id), 'the storage row survives');
        static::__assert_true(is_file($path), 'the blob file was NOT unlinked');
        static::__assert_equals(
            (int) $storage->id,
            (int) File_Attachment_Model::find($att->id)->file_storage_id,
            'the live attachment keeps its claim - only destroyed tombstones are released'
        );
    }

    public static function test_an_email_part_recorded_after_the_recheck_keeps_the_bytes()
    {
        $row = static::__email_row();
        $tmp = tempnam(sys_get_temp_dir(), 'rsx_blob_lock_');
        file_put_contents($tmp, 'late-email-part-' . uniqid());
        try {
            $storage = File_Storage_Model::store_blob(
                $tmp,
                fn (File_Storage_Model $s) => Email_Attachment_Model::record_part(
                    $row, $s, 'part.bin', 'application/octet-stream', Email_Attachment_Model::DISPOSITION_ATTACHMENT, null, 0
                )
            );
        } finally {
            @unlink($tmp);
        }

        static::__assert_false(static::__release_step($storage), 'the storage delete is refused by the foreign key');
        static::__assert_not_null(File_Storage_Model::find($storage->id), 'the storage row survives');
        static::__assert_true(is_file($storage->get_full_path()), 'the blob file was NOT unlinked');
    }

    /**
     * A release WAITS for a creator holding the blob's read lock: this process holds it (as a
     * reference creator mid-upload would), a helper process asks to release the blob, and
     * nothing is deleted until the read lock is released.
     */
    public static function test_a_release_waits_for_a_reference_in_flight()
    {
        $storage = static::__orphan_blob('in-flight-' . uniqid());
        $hash = (string) $storage->hash;
        $path = $storage->get_full_path();

        $result_file = Rsx_Project_Paths::tmp_path('rsxtest_blob_lock_result_' . getmypid());
        $helper_path = Rsx_Project_Paths::tmp_path('rsxtest_blob_lock_helper_' . getmypid() . '.php');
        @unlink($result_file);
        ensure_directory(dirname($helper_path));
        file_put_contents($helper_path, static::__release_helper_source((int) $storage->id, $result_file));

        $read = RsxLocks::named_read_lock(File_Blob_Locks::name($hash));
        $read_held = true;

        try {
            $out = [];
            $rc = 0;
            exec_safe('php ' . escapeshellarg($helper_path) . ' > /dev/null 2>&1 &', $out, $rc);

            // 120 s is the house bound (tests/CLAUDE.md, the contention principle): the helper
            // boots the framework before it can park, and a loaded box gives it little CPU.
            $parked = false;
            for ($attempt = 0; $attempt < 1200; $attempt++) {
                if (static::__stats($hash)['writers_waiting'] === 1) {
                    $parked = true;
                    break;
                }
                usleep(100000);
            }
            static::__assert_true($parked, 'the helper never parked on the WRITE lock ' . File_Blob_Locks::name($hash));

            static::__assert_not_null(File_Storage_Model::find($storage->id), 'nothing is released while the read lock is held');
            static::__assert_true(is_file($path), 'the blob file is untouched while the read lock is held');
            static::__assert_false(file_exists($result_file), 'the release has not returned while the read lock is held');

            RsxLocks::release_lock($read);
            $read_held = false;

            $finished = false;
            for ($attempt = 0; $attempt < 1200; $attempt++) {
                if (file_exists($result_file)) {
                    $finished = true;
                    break;
                }
                usleep(100000);
            }
            static::__assert_true($finished, 'the helper never reported the release result to ' . $result_file);

            static::__assert_equals('released', trim((string) file_get_contents($result_file)), 'the release ran once the lock was free');
            static::__assert_null(File_Storage_Model::find($storage->id), 'the storage row is gone');
            static::__assert_false(is_file($path), 'the blob file is gone');
        } finally {
            if ($read_held) {
                RsxLocks::release_lock($read);
            }
            @unlink($result_file);
            @unlink($helper_path);
        }
    }

    public static function test_a_reference_holds_the_read_lock_until_its_transaction_commits()
    {
        $plain = static::__make('no-transaction-' . uniqid());
        static::__assert_equals(
            0,
            static::__stats((string) $plain->file_storage->hash)['readers_active'],
            'with no transaction open the read lock ends with the insert'
        );

        DB::beginTransaction();
        try {
            $att = static::__make('in-transaction-' . uniqid());
            $hash = (string) File_Storage_Model::find($att->file_storage_id)->hash;
            static::__assert_equals(1, static::__stats($hash)['readers_active'], 'the read lock outlives the insert inside a transaction');
        } finally {
            DB::commit();
        }

        static::__assert_equals(0, static::__stats($hash)['readers_active'], 'the read lock ends at the commit');
    }

    public static function test_a_release_inside_a_transaction_unlinks_only_at_commit()
    {
        $att = static::__make('commit-' . uniqid());
        $storage = File_Storage_Model::find($att->file_storage_id);
        $hash = (string) $storage->hash;
        $path = $storage->get_full_path();

        DB::beginTransaction();
        try {
            $att->force_destroy();
            static::__assert_null(File_Storage_Model::find($storage->id), 'the storage row is deleted inside the transaction');
            static::__assert_true(is_file($path), 'the file is NOT unlinked before the commit');
            static::__assert_true(static::__stats($hash)['writer_active'], 'the write lock is held until the commit');
        } finally {
            DB::commit();
        }

        static::__assert_false(is_file($path), 'the file is unlinked at the commit');
        static::__assert_false(static::__stats($hash)['writer_active'], 'the write lock ends after the unlink');
    }

    public static function test_a_rolled_back_release_keeps_the_file()
    {
        $att = static::__make('rollback-' . uniqid());
        $storage = File_Storage_Model::find($att->file_storage_id);
        $hash = (string) $storage->hash;
        $path = $storage->get_full_path();

        DB::beginTransaction();
        try {
            $att->force_destroy();
            static::__assert_null(File_Storage_Model::find($storage->id), 'the storage row is deleted inside the transaction');
        } finally {
            DB::rollBack();
        }

        static::__assert_not_null(File_Storage_Model::find($storage->id), 'the rollback restored the storage row');
        static::__assert_true(is_file($path), 'and its file was never unlinked');
        static::__assert_false(static::__stats($hash)['writer_active'], 'the write lock ended at the rollback');
    }

    /**
     * Record and release the same blob in ONE transaction: the process already holds the read
     * lock (its reference is uncommitted), so the release upgrades it rather than asking the
     * daemon for a second lock on the same name.
     */
    public static function test_one_transaction_may_record_and_release_the_same_blob()
    {
        DB::beginTransaction();
        try {
            $att = static::__make('same-transaction-' . uniqid());
            $storage = File_Storage_Model::find($att->file_storage_id);
            $hash = (string) $storage->hash;
            $path = $storage->get_full_path();

            $att->force_destroy();
            static::__assert_null(File_Storage_Model::find($storage->id), 'the storage row is deleted');
        } finally {
            DB::commit();
        }

        static::__assert_false(is_file($path), 'the file is unlinked at the commit');
        $stats = static::__stats($hash);
        static::__assert_false($stats['writer_active'], 'no write lock outlives the transaction');
        static::__assert_equals(0, $stats['readers_active'], 'no read lock outlives the transaction');
    }

    public static function test_a_blob_pinned_by_an_attachment_and_an_email_survives_until_both_are_gone()
    {
        $att = static::__make('two-pins-' . uniqid());
        $storage = File_Storage_Model::find($att->file_storage_id);
        $path = $storage->get_full_path();
        $row = static::__email_row();
        Email_Attachment_Model::record_part(
            $row, $storage, 'two-pins.txt', 'text/plain', Email_Attachment_Model::DISPOSITION_ATTACHMENT, null, 0
        );

        $att->force_destroy();
        static::__assert_not_null(File_Storage_Model::find($storage->id), 'the email part still pins the storage row');
        static::__assert_true(is_file($path), 'and the bytes');

        // The queue row's deletion cascades to its parts; then nothing pins the blob.
        DB::table('_email_queue')->where('id', $row->id)->delete();
        static::__assert_true(File_Disposal_Service::release_blob_if_orphaned((int) $storage->id), 'released once both pins are gone');
        static::__assert_null(File_Storage_Model::find($storage->id), 'the storage row is gone');
        static::__assert_false(is_file($path), 'and the bytes');
    }

    public static function test_the_disk_sweep_removes_only_files_no_row_claims()
    {
        $kept = static::__orphan_blob('swept-kept-' . uniqid());
        $kept_path = $kept->get_full_path();

        $orphan_hash = hash('sha256', 'swept-orphan-' . uniqid());
        $orphan_path = Rsx_File_Paths::blob_root() . '/' . substr($orphan_hash, 0, 2) . '/' . substr($orphan_hash, 2, 2) . '/' . $orphan_hash;
        ensure_directory(dirname($orphan_path));
        file_put_contents($orphan_path, 'nobody claims me');

        $method = new ReflectionMethod(File_Disposal_Service::class, '__sweep_disk_batch');
        $removed = $method->invoke(null, [(string) $kept->hash => $kept_path, $orphan_hash => $orphan_path]);

        static::__assert_equals(1, $removed, 'exactly one file removed');
        static::__assert_false(is_file($orphan_path), 'the file with no storage row is removed');
        static::__assert_true(is_file($kept_path), 'the file a storage row claims is kept');
        static::__assert_false(static::__stats($orphan_hash)['writer_active'], 'the per-blob write lock is released after the unlink');
    }

    /**
     * A standalone PHP process that releases one blob and writes the outcome to $result_file.
     * It points its default connection at the test database and its files root at the run's
     * test-storage root, as this process does - lock names are scoped by database, so it must
     * coordinate under the same one.
     */
    private static function __release_helper_source(int $storage_id, string $result_file): string
    {
        $autoload = var_export(base_path('vendor/autoload.php'), true);
        $bootstrap = var_export(base_path('bootstrap/app.php'), true);
        $files_root = var_export(Rsx_Project_Paths::files_root(), true);
        $result = var_export($result_file, true);

        return <<<PHP
<?php
require {$autoload};
\$app = require {$bootstrap};
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

config(['database.default' => 'test']);
Illuminate\Support\Facades\DB::purge('test');
App\RSpade\Core\Paths\Rsx_Project_Paths::_override(['files' => {$files_root}]);

\$released = App\RSpade\Core\Files\File_Disposal_Service::release_blob_if_orphaned({$storage_id});
file_put_contents({$result}, \$released ? 'released' : 'kept');
PHP;
    }
}
