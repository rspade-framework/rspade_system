/**
 * system/ is framework property (feature E).
 *
 * Downstream, system/ is a git submodule that `rsx:framework:pull` resets hard and
 * cleans - an edit made there is destroyed on the next update with nothing to show
 * for it. This guard makes that visible before the edit rather than after the
 * update: the tree is marked read-only, an open file says so at the top, the
 * explorer badges the directory, and a first keystroke warns.
 *
 * THE GATE. Read from the project-root .env: protection is ON unless
 * IS_FRAMEWORK_DEVELOPER is exactly `true`. In the framework monorepo, where
 * system/ IS the authored source, that key is true and none of this activates.
 * Every other outcome - key absent, .env absent, unreadable, any other value -
 * leaves the protection on, because a false positive costs a warning and a false
 * negative costs somebody's work.
 *
 * The real enforcement is files.readonlyInclude (VS Code 1.79+), written into the
 * WORKSPACE configuration only. The banner and the first-edit warning are the belt
 * for an older editor, where the setting does nothing.
 */

import * as vscode from 'vscode';
import * as fs from 'fs';
import * as path from 'path';
import { framework_property_gate_is_on } from './rspade_recognizers';

const READONLY_GLOB = 'system/**';

const BANNER_TEXT =
    'RSpade framework file - overwritten on every framework update. ' +
    'Customize with a class override in rsx/ (rsx:man class_override).';

/** Explorer badge for the system/ directory while the gate is on. */
class FrameworkFileDecorationProvider implements vscode.FileDecorationProvider {
    private readonly _on_did_change = new vscode.EventEmitter<vscode.Uri | vscode.Uri[] | undefined>();
    public readonly onDidChangeFileDecorations = this._on_did_change.event;

    private readonly rspade_root: string;
    private active = false;

    constructor(rspade_root: string) {
        this.rspade_root = rspade_root.replace(/\\/g, '/');
    }

    set_active(active: boolean): void {
        if (this.active === active) {
            return;
        }
        this.active = active;
        this._on_did_change.fire(undefined);
    }

    provideFileDecoration(uri: vscode.Uri): vscode.ProviderResult<vscode.FileDecoration> {
        if (!this.active) {
            return undefined;
        }

        const uri_path = uri.fsPath.replace(/\\/g, '/');
        // The directory itself only. Badging every file inside it would fight the
        // git status badges, which are the ones that change from minute to minute.
        if (uri_path !== this.rspade_root + '/system') {
            return undefined;
        }

        // Badge only - no colour. FolderColorProvider already mutes system/, and a
        // decoration that carries only a badge composes with one that carries only
        // a colour instead of replacing it.
        return new vscode.FileDecoration('FW', 'RSpade framework property - overwritten on every framework update');
    }
}

export class FrameworkPropertyGuard {
    private readonly rspade_root: string;
    private readonly decoration_provider: FrameworkFileDecorationProvider;
    private readonly banner: vscode.TextEditorDecorationType;
    private readonly warned_documents = new Set<string>();
    private gate_on = false;

    constructor(rspade_root: string) {
        this.rspade_root = rspade_root;
        this.decoration_provider = new FrameworkFileDecorationProvider(rspade_root);
        this.banner = vscode.window.createTextEditorDecorationType({
            isWholeLine: true,
            before: {
                contentText: ' ' + BANNER_TEXT + ' ',
                backgroundColor: '#FFC107',
                color: '#1F1300',
                fontWeight: 'bold',
                margin: '0 0 0 0',
                width: '100%',
            },
        });
    }

    activate(context: vscode.ExtensionContext): void {
        context.subscriptions.push(
            this.banner,
            vscode.window.registerFileDecorationProvider(this.decoration_provider)
        );

        this.evaluate_gate();

        // The gate follows .env: a project that flips IS_FRAMEWORK_DEVELOPER does
        // not need a window reload to see the protection appear or disappear.
        const env_watcher = vscode.workspace.createFileSystemWatcher(
            new vscode.RelativePattern(this.rspade_root, '.env')
        );
        env_watcher.onDidCreate(() => this.evaluate_gate());
        env_watcher.onDidChange(() => this.evaluate_gate());
        env_watcher.onDidDelete(() => this.evaluate_gate());
        context.subscriptions.push(env_watcher);

        context.subscriptions.push(
            vscode.window.onDidChangeActiveTextEditor(editor => this.apply_banner(editor)),
            vscode.window.onDidChangeVisibleTextEditors(() => this.apply_banner_to_all())
        );

        // VS Code cannot cancel a save from onWillSaveTextDocument, so the warning
        // is raised on the first EDIT instead - once per document, because a
        // per-keystroke message would be noise, not a warning.
        context.subscriptions.push(
            vscode.workspace.onDidChangeTextDocument(event => {
                if (!this.gate_on || event.contentChanges.length === 0) {
                    return;
                }
                if (!this.is_framework_file(event.document.uri)) {
                    return;
                }
                const key = event.document.uri.toString();
                if (this.warned_documents.has(key)) {
                    return;
                }
                this.warned_documents.add(key);
                vscode.window.showWarningMessage(BANNER_TEXT);
            })
        );

        context.subscriptions.push(
            vscode.workspace.onWillSaveTextDocument(event => {
                if (this.gate_on && this.is_framework_file(event.document.uri)) {
                    vscode.window.showErrorMessage(BANNER_TEXT);
                }
            })
        );

        this.apply_banner_to_all();
    }

    /** Is protection currently on? Exposed for the status/report path. */
    public is_active(): boolean {
        return this.gate_on;
    }

    private read_env(): string | null {
        const env_file = path.join(this.rspade_root, '.env');
        try {
            return fs.readFileSync(env_file, 'utf8');
        } catch (e) {
            return null;
        }
    }

    private evaluate_gate(): void {
        const next = framework_property_gate_is_on(this.read_env());
        this.gate_on = next;
        this.decoration_provider.set_active(next);
        this.warned_documents.clear();
        void this.apply_readonly_setting(next);
        this.apply_banner_to_all();
    }

    /**
     * Add or remove exactly our own key in files.readonlyInclude, leaving every
     * other entry the developer put there untouched. Workspace target only - a
     * user-global read-only rule would follow them into unrelated projects.
     */
    private async apply_readonly_setting(enabled: boolean): Promise<void> {
        const configuration = vscode.workspace.getConfiguration('files');
        const inspected = configuration.inspect<Record<string, boolean>>('readonlyInclude');
        const current = { ...(inspected?.workspaceValue ?? {}) };

        if (enabled) {
            if (current[READONLY_GLOB] === true) {
                return;
            }
            current[READONLY_GLOB] = true;
        } else {
            if (!(READONLY_GLOB in current)) {
                return;
            }
            delete current[READONLY_GLOB];
        }

        try {
            await configuration.update(
                'readonlyInclude',
                Object.keys(current).length > 0 ? current : undefined,
                vscode.ConfigurationTarget.Workspace
            );
        } catch (error) {
            console.error('[RSpade] Could not update files.readonlyInclude:', error);
        }
    }

    private is_framework_file(uri: vscode.Uri): boolean {
        if (uri.scheme !== 'file') {
            return false;
        }
        const root = this.rspade_root.replace(/\\/g, '/');
        return uri.fsPath.replace(/\\/g, '/').startsWith(root + '/system/');
    }

    private apply_banner_to_all(): void {
        for (const editor of vscode.window.visibleTextEditors) {
            this.apply_banner(editor);
        }
    }

    private apply_banner(editor: vscode.TextEditor | undefined): void {
        if (!editor) {
            return;
        }
        if (!this.gate_on || !this.is_framework_file(editor.document.uri)) {
            editor.setDecorations(this.banner, []);
            return;
        }
        editor.setDecorations(this.banner, [new vscode.Range(0, 0, 0, 0)]);
    }
}
