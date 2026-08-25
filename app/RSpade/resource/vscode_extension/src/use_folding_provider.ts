/**
 * Collapse the `use` block in RSpade PHP files.
 *
 * WHY IT IS WORTH FOLDING HERE SPECIFICALLY. RSpade resolves classes by NAME through
 * the manifest, so the import list carries no information you act on: it is maintained
 * for you, nothing you write depends on reading it, and in a framework class it is
 * routinely longer than the code beneath it. Every file therefore opens with the reader
 * scrolling past a block they did not write and cannot learn anything from.
 *
 * TWO PARTS, because VS Code separates them:
 *
 *   1. A FoldingRangeProvider declaring the region. Nothing else does - VS Code's PHP
 *      folding is bracket- and indentation-based, and a run of `use` lines at one
 *      indentation with no braces produces no foldable region at all. Without this the
 *      block cannot be folded even by hand.
 *   2. An explicit fold when a document is first shown. A provider can only DESCRIBE a
 *      range; there is no "return this folded" flag, so collapsing it is a command.
 *
 * The range is declared with FoldingRangeKind.Imports, so a developer who prefers the
 * platform's own `editor.foldingImportsByDefault` gets the right behaviour from it too.
 *
 * FOLDED ONCE PER OPEN, NEVER RE-FOLDED. Someone who expands the block is reading it,
 * and an editor that collapses it again while they look at it is broken. The document is
 * remembered until it closes; reopening folds it fresh.
 *
 * GATED TO RSPADE PROJECTS. Registration happens inside activate(), which has already
 * established a project root, so this cannot reach a PHP file in an unrelated
 * repository. That is also why the behaviour is not contributed as a static
 * configurationDefaults entry: the extension activates in every window, and a manifest
 * contribution would change PHP folding everywhere it is installed.
 */

import * as vscode from 'vscode';

/** Setting that turns the automatic collapse off (the range provider stays). */
const SETTING = 'rspade.foldUseStatements';

/**
 * A run shorter than this is left alone: folding one import saves nothing and costs a
 * line of chrome, and VS Code will not render a fold control for it either.
 */
const MIN_LINES = 2;

/** `use Foo\Bar;` / `use Foo\Bar as Baz;` / `use function foo;` / `use const FOO;` */
const USE_LINE = /^\s*use\s+(?:function\s+|const\s+)?\\?[A-Za-z_][A-Za-z0-9_\\]*(?:\s+as\s+[A-Za-z_][A-Za-z0-9_]*)?\s*;\s*$/;

/**
 * The first line that ends the header. A `use` after this is a TRAIT use inside a class
 * body, which is code rather than an import and must never be folded away with them.
 */
const BODY_STARTS = /^\s*(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum)\s/;

/**
 * The span of the import block, or null when there is nothing worth folding.
 *
 * Returns first..last across the whole run, so blank lines separating groups collapse
 * with them - the block reads as one thing and folds as one thing.
 */
export function find_use_block(document: vscode.TextDocument): { start: number; end: number } | null {
    let first = -1;
    let last = -1;
    let count = 0;

    const limit = document.lineCount;
    for (let line_number = 0; line_number < limit; line_number++) {
        const text = document.lineAt(line_number).text;

        if (BODY_STARTS.test(text)) {
            break;
        }

        if (USE_LINE.test(text)) {
            if (first === -1) {
                first = line_number;
            }
            last = line_number;
            count++;
        }
    }

    if (count < MIN_LINES || first === -1 || last <= first) {
        return null;
    }

    return { start: first, end: last };
}

export class Use_Folding_Provider implements vscode.FoldingRangeProvider {
    public provideFoldingRanges(document: vscode.TextDocument): vscode.FoldingRange[] {
        const block = find_use_block(document);
        if (!block) {
            return [];
        }

        return [new vscode.FoldingRange(block.start, block.end, vscode.FoldingRangeKind.Imports)];
    }
}

/**
 * Register the provider and the fold-on-open behaviour.
 */
export function activate_use_folding(context: vscode.ExtensionContext): void {
    context.subscriptions.push(
        vscode.languages.registerFoldingRangeProvider({ language: 'php' }, new Use_Folding_Provider())
    );

    // Documents already collapsed once. Cleared when a document closes, so reopening a
    // file folds it again while an expansion the developer performed is never undone.
    const handled = new Set<string>();

    const fold_if_needed = async (editor: vscode.TextEditor | undefined): Promise<void> => {
        if (!editor || editor.document.languageId !== 'php') {
            return;
        }

        if (!vscode.workspace.getConfiguration().get<boolean>(SETTING, true)) {
            return;
        }

        const key = editor.document.uri.toString();
        if (handled.has(key)) {
            return;
        }

        const block = find_use_block(editor.document);
        if (!block) {
            // Remembered anyway: the answer will not change until the document is
            // edited, and re-scanning on every tab switch is work for nothing.
            handled.add(key);
            return;
        }

        handled.add(key);

        // editor.fold acts on the ACTIVE editor, so this must run while the document is
        // the one on screen - which is exactly when this fires.
        await vscode.commands.executeCommand('editor.fold', {
            selectionLines: [block.start],
            levels: 1,
            direction: 'down',
        });
    };

    context.subscriptions.push(
        vscode.window.onDidChangeActiveTextEditor(editor => void fold_if_needed(editor)),
        vscode.workspace.onDidCloseTextDocument(document => handled.delete(document.uri.toString()))
    );

    // The editor already open at activation never fires onDidChangeActiveTextEditor.
    void fold_if_needed(vscode.window.activeTextEditor);
}
