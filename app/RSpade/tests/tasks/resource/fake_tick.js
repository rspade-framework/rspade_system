#!/usr/bin/env node
/**
 * A stand-in for `php artisan rsx:task:process --_report-next-wake`, for Task_Daemon_Process_Test.
 *
 *     node fake_tick.js <scratch dir>
 *
 * Every invocation appends one line to <dir>/ticks.log, then behaves as <dir>/mode.json says:
 *
 *     {"mode": "ok", "next": 300 | "none", "hold": false}
 *
 *   ok           print RSX_TASK_NEXT_WAKE_MS=<next>, exit 0
 *   maintenance  exit 75, as the maintenance gate refuses the task runners
 *   error        print a line to stderr, exit 1
 *
 * With "hold": true it first waits until <dir>/release exists - so a test can keep a tick
 * running for as long as it needs to. The wait has no deadline: the test owns it.
 */
const fs = require('fs');
const path = require('path');

const dir = process.argv[2];
fs.appendFileSync(path.join(dir, 'ticks.log'), new Date().toISOString() + '\n');

const mode = JSON.parse(fs.readFileSync(path.join(dir, 'mode.json'), 'utf8'));

function finish() {
    if (mode.mode === 'maintenance') {
        process.stderr.write('503 - System is in maintenance mode (test); background task runners are blocked.\n');
        process.exit(75);
    }
    if (mode.mode === 'error') {
        process.stderr.write('the fake tick failed on purpose\n');
        process.exit(1);
    }
    process.stdout.write('[WORKER SPAWN] fake narration line\n');
    process.stdout.write('RSX_TASK_NEXT_WAKE_MS=' + mode.next + '\n');
    process.exit(0);
}

if (mode.hold) {
    const wait = () => (fs.existsSync(path.join(dir, 'release')) ? finish() : setTimeout(wait, 20));
    wait();
} else {
    finish();
}
