/**
 * The orphan sweep: what is left when a daemon is killed with no chance to stop its children.
 *
 * A child that uses the SDK exits by itself when its control pipe reaches EOF. A child that
 * does not has no such signal, and Linux offers node core no way to tie its life to ours, so
 * the daemon records every process group in a state file and a new daemon kills what survives.
 *
 * Identity is pid + start time (field 22 of /proc/<pid>/stat), because a pid is reused. When the
 * leader has gone, the group's other members are found by session id: each child leads a session
 * of its own, and a reused pid would be a live process AT that pid, which is checked first.
 */

const fs = require('fs');
const path = require('path');

/** pid's {start_time, session}, or null when no such process exists. */
function proc_stat(pid) {
    let text;
    try {
        text = fs.readFileSync('/proc/' + pid + '/stat', 'utf8');
    } catch (err) {
        return null;
    }

    // comm may hold spaces and parentheses: the fields proper start after the LAST ')'.
    const fields = text.slice(text.lastIndexOf(')') + 2).split(' ');

    return { state: fields[0], session: Number(fields[3]), start_time: fields[19] };
}

/** Every pid whose session id is `session`. */
function session_members(session) {
    const members = [];

    for (const entry of fs.readdirSync('/proc')) {
        if (!/^\d+$/.test(entry)) continue;

        const stat = proc_stat(entry);
        if (stat !== null && stat.session === session) {
            members.push(Number(entry));
        }
    }

    return members;
}

function kill_group(pgid) {
    try {
        process.kill(-pgid, 'SIGKILL');
    } catch (err) {
        if (err.code !== 'ESRCH') throw err;
    }
}

function read_children(file) {
    try {
        return JSON.parse(fs.readFileSync(file, 'utf8'));
    } catch (err) {
        return [];
    }
}

/** Atomic, so a daemon killed mid-write leaves the previous list. */
function write_children(file, children) {
    const scratch = file + '.' + process.pid;
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(scratch, JSON.stringify(children), { mode: 0o600 });
    fs.renameSync(scratch, file);
}

/**
 * Kill what a previous daemon left behind. Answers one line per group killed.
 */
function sweep(file) {
    const swept = [];

    for (const child of read_children(file)) {
        const leader = proc_stat(child.pid);

        if (leader !== null && leader.start_time === child.start_time) {
            kill_group(child.pid);
            swept.push(child.name + ' pid ' + child.pid);
        } else if (leader === null && session_members(child.pid).length > 0) {
            kill_group(child.pid);
            swept.push(child.name + ' process group ' + child.pid + ' (leader already gone)');
        }
    }

    write_children(file, []);

    return swept;
}

module.exports = { proc_stat, read_children, write_children, sweep, kill_group };
