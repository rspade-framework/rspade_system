<?php
/**
 * A PHP lock holder in its OWN process, for Lock_Watchdog_Test: the subject is how OTHER
 * processes see a holder (the status report, kill-holder's identity checks, the stall
 * watchdog), so the holder must be a separate process with its own daemon connection.
 *
 *     php lock_watchdog_holder.php <port> <lock> <mode> <state_dir> [<hold_seconds>]
 *
 *   <port>      the scratch rsx-lockd to talk to (Lockd_Client::_use_endpoint())
 *   <lock>      an UNSCOPED cluster lock name ($db_scoped = false), so this process and the
 *               test process name the same wire lock whichever database each is booted on
 *   <mode>      silent   take the lock and sleep until killed, never signalling progress
 *               working  take it, call RsxLocks::still_working() every quarter second for
 *                        <hold_seconds> by this process's own clock, release, exit 0
 *   <state_dir> where it publishes `state` - "ACQUIRED <pid>", later "RELEASING" - written
 *               beside its name and renamed into place, so a poll never reads half of it.
 *               "RELEASING" is published BEFORE the release, so a waiter granted by that
 *               release always reads it
 *
 * Lives under resource/, which the manifest never scans.
 */

$app = require dirname(__DIR__, 5) . '/script.php';

use App\RSpade\Core\Locks\Lockd_Client;
use App\RSpade\Core\Locks\RsxLocks;

[, $port, $lock, $mode, $state_dir] = $argv;
$hold_seconds = (float) ($argv[5] ?? 0);

$publish = function (string $state) use ($state_dir) {
    $tmp = $state_dir . '/state.' . getmypid() . '.tmp';
    file_put_contents($tmp, $state);
    rename($tmp, $state_dir . '/state');
};

Lockd_Client::_use_endpoint('127.0.0.1', (int) $port);

$token = RsxLocks::get_lock(RsxLocks::CLUSTER_LOCK, $lock, RsxLocks::WRITE_LOCK, null, false);
$publish('ACQUIRED ' . getmypid());

if ($mode === 'silent') {
    while (true) {
        sleep(3600);
    }
}

$started = microtime(true);
while (microtime(true) - $started < $hold_seconds) {
    RsxLocks::still_working();
    usleep(250000);
}

$publish('RELEASING');
RsxLocks::release_lock($token);
exit(0);
