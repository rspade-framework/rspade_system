<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Files\File_Blob_Audit;
use Illuminate\Console\Command;

/**
 * rsx:files:unreferenced_blobs - list the blobs in the store that nothing appears to reference
 * (File_Blob_Audit). Read-only.
 *
 * Output contract, for scripts: STDOUT is one blob path per line, relative to the blob root
 * ("ab/cd/<hash>"); STDERR carries each blob's size beside it and the total as the last line.
 * On a terminal the two interleave into "ab/cd/<hash> (1.2 MB)"; `2>/dev/null` leaves only
 * the paths.
 */
class Files_Unreferenced_Blobs_Command extends Command
{
    protected $signature = 'rsx:files:unreferenced_blobs';

    protected $description = 'List blobs nothing references (paths on stdout, sizes and the total on stderr) - what keep-forever retention leaves behind';

    protected $help = <<<'HELP'
Lists every blob in the file store that nothing appears to reference: a _file_storage row no
attachment (or other declared reference) holds, and a file in the blob tree with no row at
all. Read-only - it never removes anything.

WHY IT EXISTS. With rsx.files.deleted_retention_days = 0 ("keep forever") the framework never
removes a blob for any reason: no disposal pass, no deep sweep and no force_destroy() releases
one. That setting is also how an install whose blob store is shared by several environments
(one uploads mount for dev and prod) keeps one environment from deleting bytes another still
references - a database cannot see the other environments' references. The cost is that bytes
nothing references any more stay on disk. This command shows what that costs.

UNDER NORMAL OPERATION the output is minimal or empty: with a retention window set, the daily
disposal releases unreferenced blobs within days, so very little ever accumulates.

OUTPUT. STDOUT is one path per line, relative to the blob root (ab/cd/<hash>). STDERR carries
each blob's size beside its path, and the total count and size as the last line. A script can
take the paths alone:

    php artisan rsx:files:unreferenced_blobs 2>/dev/null

"Apparently" unreferenced: on a SHARED store, a blob listed here may be referenced by another
environment's database. Check before removing anything.

See: php artisan rsx:man file_disposal
HELP;

    public function handle(): int
    {
        $totals = File_Blob_Audit::each_unreferenced(function (string $relative_path, int $bytes): void {
            fwrite(STDOUT, $relative_path);
            fflush(STDOUT);
            fwrite(STDERR, ' (' . bytes_to_human($bytes) . ')');
            fflush(STDERR);
            fwrite(STDOUT, "\n");
            fflush(STDOUT);
        });

        fwrite(STDERR, 'Total: ' . $totals['count'] . ' unreferenced blob(s), ' . bytes_to_human($totals['bytes']) . "\n");

        return 0;
    }
}
