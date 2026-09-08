/**
 * The Dockerfile.test GENERATOR.
 *
 * `system/app/RSpade/resource/docker/Dockerfile.test` is a TEMPLATE: it holds the whole
 * build, its comments and both provisioning RUN layers verbatim, plus two markers this
 * module fills in. The result is written into the run directory as
 * `Dockerfile.test.generated` and `docker build -f` is pointed at it.
 *
 * WHY THE COPY BLOCK CANNOT BE STATIC. A single `COPY . /var/www/html` is invalidated by
 * ANY file in the project, so every one-character edit re-transferred and re-exported the
 * whole 700 MB context. Splitting it into one COPY per top-level entry, cheapest-changing
 * first, means an edit inside `system/app/RSpade` reuses the cached layers for
 * node_modules, vendor, the rest of system/ and the whole application tree. That listing is
 * a fact about the checkout - and about the .dockerignore the same run generated - so it is
 * read at build time rather than restated in a file that would silently rot.
 *
 * ORDER, and why the last two entries swap. The busiest tree must be LAST, because every
 * layer after it is rebuilt with it. A framework developer edits `system/app/RSpade` all
 * day and `rsx/` rarely; a downstream developer does the exact opposite. PHP knows which
 * box this is (`rsx.code_quality.is_framework_developer`) and says so in argv.
 *
 * A SYMLINK IS RECREATED, NEVER COPIED. `COPY system/rsx /var/www/html/system/rsx`
 * dereferences the link and bakes a second copy of the application tree where a six-byte
 * link belongs (verified against BuildKit, 2026-09-08). Every symlinked entry is therefore
 * emitted as one `ln -sfn` in a trailing RUN instead.
 *
 * @FILENAME-CONVENTION-EXCEPTION - Node.js module of the rsx-testd daemon
 */

const fs = require('fs');
const path = require('path');

const SNAPSHOT_MARKER = '@RSX_GENERATED_SNAPSHOT_COPY@';
const CODE_MARKER = '@RSX_GENERATED_CODE_COPY@';

// Where the code lands inside the image. The project root, not base_path().
const IMAGE_ROOT = '/var/www/html';

// The two shipped archives a fresh database restores instead of replaying every migration.
// Copied into the snapshot layer under /opt/rspade, which is outside the application tree
// and therefore cannot be disturbed by a later COPY.
const SNAPSHOT_FILES = [
    { source: 'rsx/resource/db/schema_cache.sql.gz', target: '/opt/rspade/schema_cache.sql.gz' },
    { source: 'rsx/resource/db/uploads_cache.tar.gz', target: '/opt/rspade/uploads_cache.tar.gz' },
];

/**
 * The .dockerignore this build uses, as a list of patterns. Read from the generated file
 * itself so the generator can never disagree with the filter docker applies - a COPY of an
 * entry the filter excludes is not a smaller image, it is a build that fails with "no
 * source files were specified".
 *
 * @param {string} project_root
 * @return {Array<string>}
 */
function read_ignore_patterns(project_root) {
    const file = path.join(project_root, '.dockerignore');
    if (!fs.existsSync(file)) {
        return [];
    }

    return fs.readFileSync(file, 'utf8')
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '' && !line.startsWith('#'));
}

/**
 * Is this TOP-LEVEL-ish entry excluded by the build filter?
 *
 * Only the forms the generated filter actually produces are interpreted: a bare name, a
 * leading `/`, a trailing `/`, a `**\/` prefix, and `*`/`?` globs within one segment. A
 * pattern naming something DEEPER than the candidate (`foo/bar`) never excludes the
 * candidate itself - docker will drop the inner path on its own. A negation (`!`) is
 * treated as "keep", which is what it means.
 *
 * @param {string} rel_path e.g. "system/vendor"
 * @param {Array<string>} patterns
 * @return {boolean}
 */
function is_ignored(rel_path, patterns) {
    const candidate = rel_path.replace(/^\/+/, '').replace(/\/+$/, '');
    const name = candidate.split('/').pop();

    for (const raw of patterns) {
        if (raw.startsWith('!')) {
            continue;
        }

        let pattern = raw.replace(/^\/+/, '').replace(/\/+$/, '');

        // `**/x` excludes x at every depth, which includes this one.
        const any_depth = pattern.startsWith('**/');
        if (any_depth) {
            pattern = pattern.slice(3);
        }

        const subject = any_depth ? name : candidate;

        if (pattern === subject) {
            return true;
        }

        if (/[*?[]/.test(pattern)) {
            const expression = '^' + pattern
                .replace(/[.+^${}()|\\]/g, '\\$&')
                .replace(/\*/g, '[^/]*')
                .replace(/\?/g, '[^/]') + '$';
            if (new RegExp(expression).test(subject)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * The COPY (or `ln -sfn`) instructions for one directory's entries, in the given order.
 *
 * Files are batched into a single COPY - docker accepts many file sources into one
 * destination directory. Directories cannot be: `COPY a b /dest/` copies the CONTENTS of
 * each, which would flatten them into one another.
 *
 * @param {string} project_root
 * @param {string} rel_dir directory relative to the project root ('' for the root itself)
 * @param {Array<string>} names entry names within it, already ordered
 * @param {Array<string>} patterns ignore patterns
 * @param {Array<string>} links out-parameter: `ln -sfn` fragments for symlinked entries
 * @return {Array<string>} Dockerfile lines
 */
function copy_entries(project_root, rel_dir, names, patterns, links) {
    const lines = [];
    const files = [];

    for (const name of names) {
        const rel = rel_dir === '' ? name : rel_dir + '/' + name;

        if (is_ignored(rel, patterns)) {
            continue;
        }

        const absolute = path.join(project_root, rel);
        const stat = fs.lstatSync(absolute);

        if (stat.isSymbolicLink()) {
            links.push('ln -sfn ' + fs.readlinkSync(absolute) + ' ' + IMAGE_ROOT + '/' + rel);
            continue;
        }

        if (stat.isDirectory()) {
            lines.push('COPY ' + rel + ' ' + IMAGE_ROOT + '/' + rel);
            continue;
        }

        files.push(rel);
    }

    if (files.length > 0) {
        const destination = rel_dir === '' ? IMAGE_ROOT + '/' : IMAGE_ROOT + '/' + rel_dir + '/';
        lines.push('COPY ' + files.join(' ') + ' ' + destination);
    }

    return lines;
}

/** Directory listing, sorted, with the given names removed. */
function entries_except(project_root, rel_dir, excluded) {
    const absolute = rel_dir === '' ? project_root : path.join(project_root, rel_dir);

    return fs.readdirSync(absolute)
        .filter((name) => !excluded.includes(name))
        .sort();
}

/**
 * The ordered COPY block: cheapest-changing first, the two busy trees last.
 *
 * @param {string} project_root
 * @param {boolean} framework_developer
 * @return {string}
 */
function build_code_block(project_root, framework_developer) {
    const patterns = read_ignore_patterns(project_root);
    const links = [];
    const lines = [];

    const section = (title) => {
        lines.push('');
        lines.push('# ' + title);
    };

    // 1. The two dependency trees. Enormous, and they move only when a package does.
    section(
        'Dependencies - hundreds of megabytes that change when a package does, and never otherwise.'
    );
    for (const rel of ['system/node_modules', 'system/vendor']) {
        if (fs.existsSync(path.join(project_root, rel)) && !is_ignored(rel, patterns)) {
            lines.push('COPY ' + rel + ' ' + IMAGE_ROOT + '/' + rel);
        }
    }

    // 2. The rest of system/, except app/ (which splits further below).
    section('The framework runtime outside app/ - config, bootstrap, bin, the shipped resources.');
    lines.push(...copy_entries(
        project_root,
        'system',
        entries_except(project_root, 'system', ['app', 'node_modules', 'vendor']),
        patterns,
        links
    ));

    // 3. The project root, except the three trees that get their own layers.
    section('The project root, minus the trees that carry their own layers below.');
    lines.push(...copy_entries(
        project_root,
        '',
        entries_except(project_root, '', ['rsx', 'system', 'storage']),
        patterns,
        links
    ));

    // 4. system/app, except RSpade/.
    section('The Laravel application skeleton around the framework.');
    lines.push(...copy_entries(
        project_root,
        'system/app',
        entries_except(project_root, 'system/app', ['RSpade']),
        patterns,
        links
    ));

    // 5. The application's resource tree: migrations, config, skills, the db snapshot.
    //    Quieter than the code beside it, and it is what the layers above are waiting on.
    section('The application resource tree - quieter than the code it sits beside.');
    lines.push(...copy_entries(project_root, 'rsx', ['resource'], patterns, links));

    // 6-7. The two busy trees, busiest LAST.
    const application = () => {
        section('The application tree.');
        lines.push(...copy_entries(
            project_root,
            'rsx',
            entries_except(project_root, 'rsx', ['resource']),
            patterns,
            links
        ));
    };

    const framework = () => {
        section('The framework tree.');
        lines.push('COPY system/app/RSpade ' + IMAGE_ROOT + '/system/app/RSpade');
    };

    if (framework_developer) {
        application();
        framework();
    } else {
        framework();
        application();
    }

    if (links.length > 0) {
        section('Symlinks, recreated rather than copied - COPY dereferences them.');
        lines.push('RUN ' + links.join(' \\\n    && '));
    }

    return lines.join('\n').replace(/^\n/, '');
}

/** The snapshot COPY lines for layer 1 - nothing at all when the app ships no snapshot. */
function build_snapshot_block(project_root) {
    const lines = [];

    for (const entry of SNAPSHOT_FILES) {
        if (fs.existsSync(path.join(project_root, entry.source))) {
            lines.push('COPY ' + entry.source + ' ' + entry.target);
        }
    }

    return lines.join('\n');
}

/**
 * Fill the template and write the Dockerfile this build uses.
 *
 * @param {string} project_root
 * @param {string} out_path where to write the generated Dockerfile
 * @param {boolean} framework_developer
 * @return {string} out_path
 */
function generate(project_root, out_path, framework_developer) {
    const template_path = path.join(
        project_root,
        'system/app/RSpade/resource/docker/Dockerfile.test'
    );

    if (!fs.existsSync(template_path)) {
        throw new Error('the test Dockerfile template is missing: ' + template_path);
    }

    let contents = fs.readFileSync(template_path, 'utf8');

    for (const marker of [SNAPSHOT_MARKER, CODE_MARKER]) {
        if (!contents.includes(marker)) {
            throw new Error(
                'the test Dockerfile template has no ' + marker + ' marker: ' + template_path
            );
        }
    }

    contents = contents
        .replace(SNAPSHOT_MARKER, build_snapshot_block(project_root))
        .replace(CODE_MARKER, build_code_block(project_root, framework_developer));

    fs.writeFileSync(out_path, contents);

    return out_path;
}

module.exports = { generate, is_ignored };
