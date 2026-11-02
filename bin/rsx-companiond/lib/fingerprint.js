/**
 * source_hash: the same recipe as Companion_ManifestSupport::source_hash().
 *
 * sha256 over, for each file under the directory sorted by relative path (byte order),
 * "<relative path>\0<sha256 hex of content>\n", skipping any entry named node_modules or
 * starting with a dot. A companion's own resource/ directory is invisible to the manifest, so
 * in development this is the only thing that notices an edit there.
 *
 * A file's content hash is reused while its size and mtime stand, which is the manifest's own
 * staleness test, so an idle tick costs a stat per file and no read.
 */

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const hash_cache = new Map();   // absolute path -> {size, mtime_ms, hash}

function hash_file(absolute) {
    return new Promise((resolve, reject) => {
        const hash = crypto.createHash('sha256');
        const stream = fs.createReadStream(absolute);
        stream.on('error', reject);
        stream.on('data', (chunk) => hash.update(chunk));
        stream.on('end', () => resolve(hash.digest('hex')));
    });
}

async function list_files(directory, relative, out) {
    const entries = await fs.promises.readdir(path.join(directory, relative), { withFileTypes: true });

    for (const entry of entries) {
        if (entry.name === 'node_modules' || entry.name.startsWith('.')) continue;

        const child = relative === '' ? entry.name : relative + '/' + entry.name;
        const absolute = path.join(directory, child);

        // PHP's iterator reads a symlink to a file and does not descend a symlink to a directory.
        const stat = await fs.promises.stat(absolute);
        if (stat.isDirectory()) {
            if (!entry.isSymbolicLink()) await list_files(directory, child, out);
        } else if (stat.isFile()) {
            out.push({ relative: child, absolute, size: stat.size, mtime_ms: stat.mtimeMs });
        }
    }
}

async function content_hash(file) {
    const cached = hash_cache.get(file.absolute);
    if (cached && cached.size === file.size && cached.mtime_ms === file.mtime_ms) {
        return cached.hash;
    }

    const hash = await hash_file(file.absolute);
    hash_cache.set(file.absolute, { size: file.size, mtime_ms: file.mtime_ms, hash });

    return hash;
}

/**
 * The directory's source hash. Rejects when a file vanishes mid-walk; the caller retries on
 * its next tick, because a half-saved tree has no meaningful hash.
 */
async function source_hash(directory) {
    const files = [];
    await list_files(directory, '', files);

    files.sort((a, b) => Buffer.compare(Buffer.from(a.relative), Buffer.from(b.relative)));

    const hash = crypto.createHash('sha256');
    for (const file of files) {
        hash.update(file.relative + '\0' + (await content_hash(file)) + '\n');
    }

    return hash.digest('hex');
}

module.exports = { source_hash };
