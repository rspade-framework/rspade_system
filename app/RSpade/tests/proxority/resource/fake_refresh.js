/**
 * A scripted stand-in for `php artisan rsx:proxority:routes --json`.
 *
 *     node fake_refresh.js <mode_file>
 *
 * The mode file holds JSON the test rewrites between refreshes:
 *
 *     {"mode": "ok", "answer": {...}}   print the answer, exit 0
 *     {"mode": "fail"}                  print an error to stderr, exit 1
 *     {"mode": "badjson"}               print something that is not JSON, exit 0
 *     {"mode": "maintenance"}           print {"maintenance": true}, exit 3
 *     {"mode": "hang"}                  write its pid to <mode_file>.pid and never exit
 *
 * Every run appends a line to <mode_file>.runs. A hanging run exits when its parent goes away.
 */

const fs = require('fs');

const mode_file = process.argv[2];
const config = JSON.parse(fs.readFileSync(mode_file, 'utf8'));

fs.appendFileSync(mode_file + '.runs', config.mode + '\n');

if (config.mode === 'ok') {
    process.stdout.write(JSON.stringify(config.answer));
    process.exit(0);
}
if (config.mode === 'fail') {
    process.stderr.write('the fake command failed on purpose\n');
    process.exit(1);
}
if (config.mode === 'badjson') {
    process.stdout.write('this is not json');
    process.exit(0);
}
if (config.mode === 'maintenance') {
    process.stdout.write(JSON.stringify({ maintenance: true }));
    process.exit(3);
}

fs.writeFileSync(mode_file + '.pid', String(process.pid));
// The parent is gone when this process has been reparented - asked of our own ppid, never of a
// remembered pid, which the kernel may since have handed to another process.
const parent = process.ppid;
setInterval(() => {
    if (process.ppid !== parent) {
        process.exit(0);
    }
}, 1000);
