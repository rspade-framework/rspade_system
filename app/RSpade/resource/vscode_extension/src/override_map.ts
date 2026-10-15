/**
 * The manifest's OVERRIDE MAP, for the editor.
 *
 * An application overrides a framework file by declaring the same name under rsx/
 * (rsx:man class_override). Nothing on disk says so: the framework file stays where it is,
 * under its own name, and is simply never loaded or compiled - the manifest records which
 * application file answers for it instead. Without help, a developer who opens that
 * framework file reads code that does not run.
 *
 * This module fetches the map from the IDE bridge (`/_ide/service/overrides`) and:
 *
 *   - badges a shadowed framework file in the explorer and on its editor tab ("OV",
 *     tooltip "Overridden by rsx/...");
 *   - puts a CodeLens on its first line that opens the override, and says so once per
 *     file per session when the file is brought to the front;
 *   - lets every definition provider redirect a location that would land on a shadowed
 *     file to the file that overrides it (effective_location / effective_uri).
 *
 * WHEN IT REFRESHES - events only, never a timer: when the bridge comes online, and when a
 * source file under rsx/ is created, changed or deleted (an override appears or goes away
 * only through such a file). The bridge answers from the manifest, which a request brings
 * up to date first, so the map it returns reflects the change that triggered the refresh.
 * Refreshes are single-flight: events arriving while one is in progress collapse into ONE
 * follow-up, which is what a burst of saves needs.
 *
 * An offline bridge keeps the last map; a server too old to serve the endpoint answers
 * "Unknown service", after which the map stays empty until the bridge reconnects.
 */

import * as vscode from 'vscode';
import * as fs from 'fs';
import * as path from 'path';
import { IdeBridgeClient, Bridge_Offline_Error } from './ide_bridge_client';
import { Bridge_Connectivity } from './bridge_connectivity';

export interface Override_Record {
    /** php | js | jqhtml */
    kind: string;
    /** The declared name both files carry. */
    name: string;
    /** The application file, relative to the project root (rsx/...). */
    by: string;
    /** The application file, absolute. */
    by_path: string;
}

/** Path key: absolute, forward slashes, so a Windows and a POSIX spelling agree. */
function key_of(fs_path: string): string {
    return fs_path.replace(/\\/g, '/');
}

export class Override_Map {
    private readonly rspade_root: string;
    private readonly bridge: IdeBridgeClient;
    private readonly output: vscode.OutputChannel;

    private records = new Map<string, Override_Record>();

    private in_flight = false;
    private again = false;
    private unsupported = false;

    private readonly notified = new Set<string>();

    private readonly on_change_emitter = new vscode.EventEmitter<vscode.Uri[]>();

    /** Fires with every framework file whose shadowed state changed. */
    public readonly onDidChange = this.on_change_emitter.event;

    constructor(rspade_root: string, output: vscode.OutputChannel) {
        this.rspade_root = rspade_root;
        this.output = output;
        this.bridge = new IdeBridgeClient(output);
    }

    activate(context: vscode.ExtensionContext, connectivity: Bridge_Connectivity): void {
        const decorations = new Override_Decoration_Provider(this);
        const lenses = new Override_Code_Lens_Provider(this);

        context.subscriptions.push(
            this.on_change_emitter,
            vscode.window.registerFileDecorationProvider(decorations),
            vscode.languages.registerCodeLensProvider(
                { scheme: 'file', pattern: new vscode.RelativePattern(this.rspade_root, 'system/app/RSpade/**') },
                lenses
            )
        );

        context.subscriptions.push(
            connectivity.onDidChangeConnectivity(online => {
                if (online) {
                    this.unsupported = false;
                    this.refresh();
                }
            })
        );

        // A workspace-relative pattern: VS Code applies files.watcherExclude to it, so the
        // extension keeps no exclusion list of its own.
        const watcher = vscode.workspace.createFileSystemWatcher(
            new vscode.RelativePattern(this.rspade_root, 'rsx/**/*.{php,js,jsx,ts,tsx,jqhtml}')
        );
        watcher.onDidCreate(() => this.refresh());
        watcher.onDidChange(() => this.refresh());
        watcher.onDidDelete(() => this.refresh());
        context.subscriptions.push(watcher);

        context.subscriptions.push(
            vscode.window.onDidChangeActiveTextEditor(editor => this.notify(editor))
        );

        this.refresh();
    }

    /** The override record for a framework file, or undefined when nothing overrides it. */
    public override_for(uri: vscode.Uri): Override_Record | undefined {
        if (uri.scheme !== 'file') {
            return undefined;
        }
        return this.records.get(key_of(uri.fsPath));
    }

    /** Ask the bridge for the current map. Coalesces calls made while one is running. */
    public refresh(): void {
        if (this.unsupported) {
            return;
        }

        if (this.in_flight) {
            this.again = true;
            return;
        }

        this.in_flight = true;

        void this.fetch().finally(() => {
            this.in_flight = false;

            if (this.again) {
                this.again = false;
                this.refresh();
            }
        });
    }

    private async fetch(): Promise<void> {
        let response: any;

        try {
            response = await this.bridge.request('/_ide/service/overrides', {}, 'GET');
        } catch (error: any) {
            if (error instanceof Bridge_Offline_Error) {
                return;
            }

            if (String(error?.message ?? '').includes('Unknown service')) {
                this.unsupported = true;
                this.output.appendLine('[Overrides] This server does not serve /_ide/service/overrides; shadowed framework files are not marked.');
                return;
            }

            this.output.appendLine(`[Overrides] Could not read the override map: ${error?.message ?? error}`);
            return;
        }

        const next = new Map<string, Override_Record>();

        for (const [framework_file, record] of Object.entries<any>(response?.overrides ?? {})) {
            next.set(key_of(path.join(this.rspade_root, framework_file)), {
                kind: String(record.kind),
                name: String(record.name),
                by: String(record.by),
                by_path: path.join(this.rspade_root, String(record.by)),
            });
        }

        this.apply(next);
    }

    private apply(next: Map<string, Override_Record>): void {
        const changed: vscode.Uri[] = [];

        for (const [key, record] of next) {
            const previous = this.records.get(key);
            if (!previous || previous.by !== record.by) {
                changed.push(vscode.Uri.file(key));
            }
        }

        for (const key of this.records.keys()) {
            if (!next.has(key)) {
                changed.push(vscode.Uri.file(key));
                this.notified.delete(key);
            }
        }

        this.records = next;

        if (changed.length > 0) {
            this.on_change_emitter.fire(changed);
            this.notify(vscode.window.activeTextEditor);
        }
    }

    /** Say once per file per session that the file in front of the developer is shadowed. */
    private notify(editor: vscode.TextEditor | undefined): void {
        if (!editor) {
            return;
        }

        const record = this.override_for(editor.document.uri);
        if (!record) {
            return;
        }

        const key = key_of(editor.document.uri.fsPath);
        if (this.notified.has(key)) {
            return;
        }
        this.notified.add(key);

        void vscode.window
            .showInformationMessage(
                `This framework file is overridden by ${record.by} - it is never loaded or compiled.`,
                'Open Override'
            )
            .then(choice => {
                if (choice === 'Open Override') {
                    void vscode.window.showTextDocument(vscode.Uri.file(record.by_path));
                }
            });
    }
}

/** Explorer and tab badge for a shadowed framework file. */
class Override_Decoration_Provider implements vscode.FileDecorationProvider {
    public readonly onDidChangeFileDecorations: vscode.Event<vscode.Uri[]>;

    constructor(private readonly map: Override_Map) {
        this.onDidChangeFileDecorations = map.onDidChange;
    }

    provideFileDecoration(uri: vscode.Uri): vscode.ProviderResult<vscode.FileDecoration> {
        const record = this.map.override_for(uri);
        if (!record) {
            return undefined;
        }

        // Badge only - no colour, so it composes with the git status colours.
        return new vscode.FileDecoration('OV', `Overridden by ${record.by}`);
    }
}

/** First-line lens on a shadowed framework file, opening the file that overrides it. */
class Override_Code_Lens_Provider implements vscode.CodeLensProvider {
    public readonly onDidChangeCodeLenses: vscode.Event<void>;

    constructor(private readonly map: Override_Map) {
        this.onDidChangeCodeLenses = (listener, this_args, disposables) =>
            map.onDidChange(() => listener.call(this_args, undefined), null, disposables);
    }

    provideCodeLenses(document: vscode.TextDocument): vscode.CodeLens[] {
        const record = this.map.override_for(document.uri);
        if (!record) {
            return [];
        }

        return [
            new vscode.CodeLens(new vscode.Range(0, 0, 0, 0), {
                title: `Overridden by ${record.by} - this framework file is never loaded or compiled`,
                command: 'vscode.open',
                arguments: [vscode.Uri.file(record.by_path)],
            }),
        ];
    }
}

let instance: Override_Map | undefined;

export function init_override_map(rspade_root: string, output: vscode.OutputChannel): Override_Map {
    if (!instance) {
        instance = new Override_Map(rspade_root, output);
    }
    return instance;
}

/** The file that answers for $uri: the override when $uri is shadowed, else $uri itself. */
export function effective_uri(uri: vscode.Uri): vscode.Uri {
    const record = instance?.override_for(uri);
    return record ? vscode.Uri.file(record.by_path) : uri;
}

/**
 * A definition location that never lands on a shadowed framework file. A location in a
 * shadowed file is moved to the line of the override that declares the same name, or to
 * the top of the override when no declaration line is found.
 */
export function effective_location(location: vscode.Location): vscode.Location {
    const record = instance?.override_for(location.uri);
    if (!record) {
        return location;
    }

    return new vscode.Location(
        vscode.Uri.file(record.by_path),
        new vscode.Position(declaration_line(record), 0)
    );
}

function declaration_line(record: Override_Record): number {
    let source: string;
    try {
        source = fs.readFileSync(record.by_path, 'utf8');
    } catch (e) {
        return 0;
    }

    const name = record.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const pattern =
        record.kind === 'jqhtml' ? new RegExp(`<Define:${name}\\b`) :
        new RegExp(`\\bclass\\s+${name}\\b`);

    const lines = source.split('\n');
    for (let i = 0; i < lines.length; i++) {
        if (pattern.test(lines[i])) {
            return i;
        }
    }

    return 0;
}
