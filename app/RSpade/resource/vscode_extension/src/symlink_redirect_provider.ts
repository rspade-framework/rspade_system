import * as vscode from 'vscode';
import * as path from 'path';
import * as fs from 'fs';

/**
 * Redirects a file opened through a symlinked path to the same file's real path.
 *
 * A workspace can reach one physical file by more than one path, and editing it
 * through the symlinked path splits the editor's view of it (two tabs, two dirty
 * states, git decorations on only one of them). Whenever the opened path is not
 * the real path, and the real path is inside the same workspace folder, the real
 * one is opened instead and the symlinked tab is closed.
 *
 * This is resolved with fs.realpathSync rather than by matching known symlink
 * names, so every symlink in the tree is covered by the same rule. The worked
 * example that motivated it: `system/rsx/` is a symlink to `rsx/` for framework
 * compatibility, but users should always edit files in the real `rsx/`
 * directory. In this monorepo `system/app/RSpade/resource/reference_app/` is a
 * second symlink to `rsx/` and gets the same treatment for free - a
 * go-to-definition or search result landing under it opens the real file.
 * Downstream, `reference_app/` is a real directory of vendored files, so realpath
 * equals the opened path and this provider does nothing at all.
 */
export class SymlinkRedirectProvider {
    private disposables: vscode.Disposable[] = [];

    public activate(context: vscode.ExtensionContext) {
        // Watch for document opens and switches
        this.disposables.push(
            vscode.window.onDidChangeActiveTextEditor(editor => {
                if (editor) {
                    this.check_and_redirect(editor.document);
                }
            })
        );

        // Also check when window first opens or tabs change
        this.disposables.push(
            vscode.workspace.onDidOpenTextDocument(document => {
                this.check_and_redirect(document);
            })
        );

        // Check currently active editor immediately
        if (vscode.window.activeTextEditor) {
            this.check_and_redirect(vscode.window.activeTextEditor.document);
        }

        console.log('[RSpade] Symlink redirect provider activated');
    }

    /**
     * The real path of a file opened through a symlink, or undefined when the
     * opened path is already real, is not on disk, or resolves outside the
     * workspace folder it was opened from (a symlink pointing out of the tree is
     * a deliberate reference, not a duplicate view of a workspace file).
     */
    private resolve_real_path(document: vscode.TextDocument): string | undefined {
        if (document.uri.scheme !== 'file') {
            return undefined;
        }

        const file_path = document.uri.fsPath;

        const workspace_folder = vscode.workspace.getWorkspaceFolder(document.uri);
        if (!workspace_folder) {
            return undefined;
        }

        let real_file: string;
        let workspace_root: string;
        try {
            // Not on disk (an unsaved or just-deleted document) throws here; that is
            // an ordinary state for a text document, not an error to report.
            real_file = fs.realpathSync(file_path);
            workspace_root = fs.realpathSync(workspace_folder.uri.fsPath);
        } catch (e) {
            return undefined;
        }

        if (real_file === file_path) {
            return undefined; // Opened path is already the real one
        }

        // The real file must sit inside the workspace folder under BOTH spellings of
        // the root: its real path and the path the folder was opened as. Checking the
        // opened spelling too is what keeps a workspace whose ROOT is itself a symlink
        // from redirecting every file in it - there the real file is outside the opened
        // root, and the duplicate-view problem this provider solves does not exist.
        const opened_root = workspace_folder.uri.fsPath;
        for (const root of [workspace_root, opened_root]) {
            const relative = path.relative(root, real_file);
            if (relative.startsWith('..') || path.isAbsolute(relative)) {
                return undefined; // Real file lives outside the workspace folder
            }
        }

        return real_file;
    }

    private async check_and_redirect(document: vscode.TextDocument) {
        const file_path = document.uri.fsPath;

        const real_file = this.resolve_real_path(document);
        if (!real_file) {
            return;
        }

        console.log(`[RSpade] Redirecting from symlinked path to real file:`);
        console.log(`  Symlink: ${file_path}`);
        console.log(`  Real:    ${real_file}`);

        // Check if the symlink version is pinned
        const is_pinned = document.uri.scheme === 'file' &&
                         vscode.window.tabGroups.activeTabGroup.activeTab?.isPinned;

        // If pinned, unpin it first
        if (is_pinned) {
            await vscode.commands.executeCommand('workbench.action.unpinEditor');
        }

        // Open the real file
        const real_uri = vscode.Uri.file(real_file);
        const real_document = await vscode.workspace.openTextDocument(real_uri);
        await vscode.window.showTextDocument(real_document);

        // If the original was pinned, pin the new one
        if (is_pinned) {
            await vscode.commands.executeCommand('workbench.action.pinEditor');
        }

        // Close the symlink version (now in background)
        // Find the tab with the symlink path and close it
        const tab_groups = vscode.window.tabGroups.all;
        for (const group of tab_groups) {
            for (const tab of group.tabs) {
                if (tab.input instanceof vscode.TabInputText &&
                    tab.input.uri.fsPath === file_path) {
                    await vscode.window.tabGroups.close(tab);
                    break;
                }
            }
        }

        // Show brief notification
        const workspace_folder = vscode.workspace.getWorkspaceFolder(document.uri);
        const shown_from = workspace_folder
            ? path.relative(workspace_folder.uri.fsPath, file_path)
            : file_path;
        vscode.window.setStatusBarMessage(`Redirected ${shown_from} to its real path`, 2000);
    }

    public dispose() {
        this.disposables.forEach(d => d.dispose());
    }
}
