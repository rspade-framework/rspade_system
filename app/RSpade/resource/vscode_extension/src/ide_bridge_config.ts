/**
 * RSpade IDE Bridge - shared configuration helper
 *
 * Single source of truth for locating the RSpade project, resolving the IDE
 * bridge server URL, and reading the local-file grant token. Every IDE client
 * (bridge client, formatter, git status/diff) routes through here so there is
 * exactly ONE copy of this logic.
 *
 * SERVER URL, in priority order: the `rspade.serverUrl` setting, then the app_url
 * the SERVER wrote into the grant document, then APP_URL parsed out of the
 * project-root .env. The grant outranks .env because APP_URL may hold the literal
 * sentinel `APP_URL=https://$HOSTNAME`, which resolves to whichever machine expands
 * it - the server's own name on the server, the workstation's name in an editor.
 * Only the first of those is the address the application answers on. APP_URL already
 * carries the scheme, so there is no protocol probing at any tier.
 *
 * AUTH: the framework (dev only) writes a mode-restricted ide-grant-<random>.token
 * file into storage/rsx-ide-bridge/ (outside the web docroot). It is a JSON grant
 * document, {"secret", "app_url"}; the client reads it from local disk and presents
 * the SECRET in the X-Ide-Token request header. Possession of the file IS the grant.
 *
 * FAIL LOUD: every resolver throws a descriptive Error when it cannot resolve.
 * There are NO silent fallbacks.
 */

import * as vscode from 'vscode';
import * as os from 'os';
import * as path from 'path';
import * as fs from 'fs';

/**
 * Locate the RSpade project root: the workspace folder that contains
 * system/app/RSpade (current layout) or app/RSpade (legacy layout).
 */
export function find_rspade_root(): string | undefined {
    if (!vscode.workspace.workspaceFolders) {
        return undefined;
    }

    for (const folder of vscode.workspace.workspaceFolders) {
        // Current structure: <root>/system/app/RSpade
        if (fs.existsSync(path.join(folder.uri.fsPath, 'system', 'app', 'RSpade'))) {
            return folder.uri.fsPath;
        }
        // Legacy structure: <root>/app/RSpade
        if (fs.existsSync(path.join(folder.uri.fsPath, 'app', 'RSpade'))) {
            return folder.uri.fsPath;
        }
    }

    return undefined;
}

/**
 * Resolve the IDE bridge server URL from <rspadeRoot>/.env APP_URL.
 * Expands a literal $HOSTNAME sentinel via os.hostname(). Optional override:
 * VS Code setting `rspade.serverUrl`. Returns the URL with no trailing slash.
 * Throws (fail loud) when .env is missing or APP_URL is undefined/empty.
 */
export function resolveServerUrl(rspadeRoot: string): string {
    // 1. Optional explicit override for non-standard setups. Always wins.
    const configured = vscode.workspace.getConfiguration('rspade').get<string>('serverUrl');
    if (configured && configured.trim() !== '') {
        return configured.trim().replace(/\/+$/, '');
    }

    // 2. The grant document's app_url. AUTHORITATIVE, and preferred over .env for one
    //    reason: APP_URL may hold the literal `$HOSTNAME` token, and only the SERVER
    //    can resolve that correctly. The framework substitutes its own gethostname()
    //    before writing this value; an editor expanding the token locally would
    //    substitute the WORKSTATION's name instead - harmless when the two are the same
    //    machine, and completely wrong when the project is on a remote mount.
    const from_grant = grantAppUrl(rspadeRoot);
    if (from_grant) {
        return from_grant;
    }

    // 3. .env, read directly. Reached when no grant is established yet (the site has
    //    not been loaded in a browser). $HOSTNAME is expanded locally here, which is
    //    correct only on a co-located setup - hence its place at the bottom.
    const env_file = path.join(rspadeRoot, '.env');
    if (!fs.existsSync(env_file)) {
        throw new Error(`RSpade IDE bridge: .env not found at ${env_file} - cannot resolve APP_URL.`);
    }

    const env_content = fs.readFileSync(env_file, 'utf8');
    let app_url: string | null = null;

    // phpdotenv semantics: first definition wins.
    for (const raw_line of env_content.split(/\r?\n/)) {
        const line = raw_line.trim();
        if (line.startsWith('#') || !line.startsWith('APP_URL=')) {
            continue;
        }
        let value = line.substring('APP_URL='.length).trim();
        if ((value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))) {
            value = value.substring(1, value.length - 1);
        }
        app_url = value;
        break;
    }

    if (app_url === null || app_url === '') {
        throw new Error(`RSpade IDE bridge: APP_URL is undefined or empty in ${env_file}.`);
    }

    // Expand the $HOSTNAME sentinel (matches PHP gethostname()).
    app_url = app_url.split('$HOSTNAME').join(os.hostname());

    if (app_url === '') {
        throw new Error(`RSpade IDE bridge: APP_URL resolved to empty after $HOSTNAME expansion in ${env_file}.`);
    }

    return app_url.replace(/\/+$/, '');
}

/**
 * Locate the grant file: <rspadeRoot>/storage/rsx-ide-bridge/ide-grant-*.token.
 * When several exist the newest by mtime wins, so the answer is deterministic.
 * Throws (fail loud) when the directory or the file is missing.
 *
 * Volatile storage was relocated out of system/ to the project root; the historic
 * system/storage location is still probed as a fallback for an environment that
 * has not yet run the relocation.
 */
function find_grant_file(rspadeRoot: string): string {
    const relocated_dir = path.join(rspadeRoot, 'storage', 'rsx-ide-bridge');
    const legacy_dir = path.join(rspadeRoot, 'system', 'storage', 'rsx-ide-bridge');
    const bridge_dir = fs.existsSync(relocated_dir) ? relocated_dir : legacy_dir;

    let entries: string[];
    try {
        entries = fs.readdirSync(bridge_dir);
    } catch (e) {
        throw new Error(`RSpade IDE bridge: token directory not found at ${bridge_dir}. Load the site in a browser (dev mode) to mint the grant token.`);
    }

    const token_files = entries
        .filter(name => name.startsWith('ide-grant-') && name.endsWith('.token'))
        .map(name => path.join(bridge_dir, name));

    if (token_files.length === 0) {
        throw new Error(`RSpade IDE bridge: no ide-grant-*.token found in ${bridge_dir}. Load the site in a browser (dev mode) to mint the grant token.`);
    }

    // Deterministically pick the newest token when more than one is present.
    let chosen = token_files[0];
    if (token_files.length > 1) {
        let newest_mtime = fs.statSync(chosen).mtimeMs;
        for (const candidate of token_files.slice(1)) {
            const mtime = fs.statSync(candidate).mtimeMs;
            if (mtime > newest_mtime) {
                newest_mtime = mtime;
                chosen = candidate;
            }
        }
    }

    return chosen;
}

/**
 * The secret to present in the X-Ide-Token header.
 * Throws (fail loud) when no grant is established.
 */
export function readIdeToken(rspadeRoot: string): string {
    return read_grant_document(find_grant_file(rspadeRoot)).secret;
}

/**
 * Parse a grant file into its secret and the server-resolved application URL.
 *
 * The file is a JSON document written by Ide_Bridge_Token: {"secret", "app_url"}.
 * Throws (fail loud) when it is missing, unparseable, or carries no secret - an
 * unreadable grant is never downgraded into a guess.
 */
function read_grant_document(grant_path: string): { secret: string; app_url: string | null } {
    const raw = fs.readFileSync(grant_path, 'utf8').trim();
    if (raw === '') {
        throw new Error(`RSpade IDE bridge: grant token file ${grant_path} is empty.`);
    }

    let parsed: any;
    try {
        parsed = JSON.parse(raw);
    } catch (e) {
        throw new Error(`RSpade IDE bridge: grant token file ${grant_path} is not a valid grant document (expected JSON).`);
    }

    const secret = typeof parsed?.secret === 'string' ? parsed.secret.trim() : '';
    if (secret === '') {
        throw new Error(`RSpade IDE bridge: grant token file ${grant_path} carries no secret.`);
    }

    const app_url = typeof parsed?.app_url === 'string' && parsed.app_url.trim() !== ''
        ? parsed.app_url.trim().replace(/\/+$/, '')
        : null;

    return { secret, app_url };
}

/**
 * The server URL the grant document reports, or null when no grant is established
 * (or it predates the app_url field). Never throws - the caller decides what an
 * absent answer means.
 */
function grantAppUrl(rspadeRoot: string): string | null {
    try {
        return read_grant_document(find_grant_file(rspadeRoot)).app_url;
    } catch (e) {
        return null;
    }
}
