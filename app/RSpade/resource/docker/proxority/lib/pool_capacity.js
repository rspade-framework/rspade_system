/**
 * The size of php-fpm's one pool: `pm.max_children` from www.conf.
 *
 * Read at start and again on SIGHUP. Proxority schedules against exactly the number of
 * workers php-fpm will run, so a file that is missing, unreadable or names no
 * `pm.max_children` is an error, never a guess.
 */

const fs = require('fs');
const path = require('path');

const POOL_FILE = 'www.conf';

/** `pm.max_children = N` from one pool file's text. Throws naming the file when absent. */
function parse_max_children(text, file) {
    let found = null;

    for (const raw of text.split('\n')) {
        const line = raw.trim();
        if (line === '' || line.startsWith(';') || line.startsWith('#')) continue;

        const match = /^pm\.max_children\s*=\s*(\S+)/.exec(line);
        if (!match) continue;

        const value = Number(match[1]);
        if (!Number.isInteger(value) || value < 1) {
            throw new Error(file + ': pm.max_children must be a whole number of at least 1, got "' + match[1] + '"');
        }
        found = value;
    }

    if (found === null) {
        throw new Error(file + ' sets no pm.max_children');
    }
    return found;
}

/** The pool's worker count. Throws when the pool file cannot be read. */
function read_capacity(pool_dir) {
    const file = path.join(pool_dir, POOL_FILE);
    let text;
    try {
        text = fs.readFileSync(file, 'utf8');
    } catch (error) {
        throw new Error('Cannot read the pool file ' + file + ': ' + error.message);
    }
    return parse_max_children(text, file);
}

module.exports = { read_capacity, parse_max_children, POOL_FILE };
