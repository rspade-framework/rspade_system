/**
 * The size of each php-fpm pool: `pm.max_children` from its pool file.
 *
 *     www.conf   -> the web pool
 *     ajax.conf  -> the ajax pool
 *
 * Read at start and again on SIGHUP. Proxority schedules against exactly the number of
 * workers php-fpm will run, so a file that is missing, unreadable or names no
 * `pm.max_children` is an error, never a guess.
 */

const fs = require('fs');
const path = require('path');

const POOL_FILES = { web: 'www.conf', ajax: 'ajax.conf' };

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

/** `{web: N, ajax: N}`. Throws when either pool cannot be read. */
function read_capacities(pool_dir) {
    const capacities = {};

    for (const [pool, name] of Object.entries(POOL_FILES)) {
        const file = path.join(pool_dir, name);
        let text;
        try {
            text = fs.readFileSync(file, 'utf8');
        } catch (error) {
            throw new Error('Cannot read the ' + pool + ' pool file ' + file + ': ' + error.message);
        }
        capacities[pool] = parse_max_children(text, file);
    }

    return capacities;
}

module.exports = { read_capacities, parse_max_children, POOL_FILES };
