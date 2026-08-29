import * as vscode from 'vscode';

export function get_config() {
    return vscode.workspace.getConfiguration('rspade');
}

export function get_python_command(): string {
    const custom_path = get_config().get<string>('pythonPath');
    if (custom_path && custom_path.trim() !== '') {
        return custom_path;
    }
    
    // Default based on platform
    return process.platform === 'win32' ? 'python' : 'python3';
}

/**
 * The exclude glob every file-enumerating call in this extension passes to
 * vscode.workspace.findFiles().
 *
 * findFiles(include, undefined) applies `files.exclude` ONLY. `search.exclude` - the
 * setting that hides a tree from search results while leaving it in the explorer - is
 * not consulted, so a workspace that excludes a directory from search would still see
 * this extension enumerate it. Anything the workspace says is not searchable is not
 * enumerable either: this merges the enabled keys of both settings into one brace glob,
 * which is what findFiles takes. Returns undefined when neither setting excludes
 * anything, which falls back to the API's own default.
 */
export function workspace_exclude_glob(): string | undefined {
    const patterns: string[] = [];
    for (const section of ['files.exclude', 'search.exclude']) {
        const setting = vscode.workspace.getConfiguration().get<Record<string, boolean>>(section, {});
        for (const [pattern, enabled] of Object.entries(setting)) {
            if (enabled === true && !pattern.startsWith('!')) {
                patterns.push(pattern);
            }
        }
    }
    if (patterns.length === 0) {
        return undefined;
    }
    return patterns.length === 1 ? patterns[0] : '{' + patterns.join(',') + '}';
}
