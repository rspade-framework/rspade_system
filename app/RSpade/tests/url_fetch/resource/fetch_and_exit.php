<?php
/**
 * A process that fetches one URL with Rsx_Url_Fetch and exits WITHOUT moving or deleting the
 * temp file, for Url_Fetch_Test: the subject is that the file is gone once the process ends.
 *
 *     php fetch_and_exit.php <url>
 *
 * Prints "<path> <1 if the file existed before exit>". The fetch allows private addresses
 * because the URL is the test's own loopback fixture server.
 *
 * Lives under resource/, which the manifest never scans.
 */

$app = require dirname(__DIR__, 5) . '/script.php';

use App\RSpade\Lib\Support\UrlFetch\Rsx_Url_Fetch;

$result = Rsx_Url_Fetch::to_temp_file($argv[1], 1024, true);
echo $result->path . ' ' . (file_exists($result->path) ? '1' : '0');
