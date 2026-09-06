/**
 * Man-page and skill references (feature D).
 *
 * A reference to `rsx:man tasks` or `rspade:background-tasks` becomes an amber
 * token you can F12 into. Resolution is LOCAL - the topic either exists as a file
 * in this workspace or it does not - so no bridge call is made and the feature
 * works with the server stopped.
 *
 * The four resolution roots:
 *
 *     rsx/resource/man/<topic>.txt              application man page (wins)
 *     system/app/RSpade/man/<topic>.txt         framework man page
 *     rsx/resource/skills/<name>/SKILL.md       application skill (wins)
 *     system/app/RSpade/docs/skills/{shared,framework,app}/<name>/SKILL.md
 *
 * A token that resolves to nothing gets no colour and no link, silently: the
 * recognizers accept generous spellings on purpose, and the index is what decides
 * whether a candidate is a real reference.
 */

import * as vscode from 'vscode';
import * as fs from 'fs';
import * as path from 'path';
import {
    Recognized_Token,
    comment_flavor_for,
    recognize_doc_references,
    token_at,
} from './rspade_recognizers';

const MAN_DIRECTORIES = [
    // Application first: an application page wins a name collision.
    path.join('rsx', 'resource', 'man'),
    path.join('system', 'app', 'RSpade', 'man'),
];

const SKILL_DIRECTORIES = [
    path.join('rsx', 'resource', 'skills'),
    path.join('system', 'app', 'RSpade', 'docs', 'skills', 'shared'),
    path.join('system', 'app', 'RSpade', 'docs', 'skills', 'framework'),
    path.join('system', 'app', 'RSpade', 'docs', 'skills', 'app'),
];

/**
 * The set of resolvable topics and skills, read once and refreshed when a page or
 * a SKILL.md appears, moves or disappears.
 */
export class DocReferenceIndex {
    private readonly rspade_root: string;
    private man_topics: Map<string, string> | undefined;
    private skills: Map<string, string> | undefined;

    constructor(rspade_root: string) {
        this.rspade_root = rspade_root;
    }

    activate(context: vscode.ExtensionContext): void {
        const watcher = vscode.workspace.createFileSystemWatcher('**/{man/*.txt,skills/*/SKILL.md}');
        watcher.onDidCreate(() => this.invalidate());
        watcher.onDidDelete(() => this.invalidate());
        context.subscriptions.push(watcher);
    }

    invalidate(): void {
        this.man_topics = undefined;
        this.skills = undefined;
    }

    /** Absolute path of a man page, or undefined when the topic does not exist. */
    resolve_man_topic(topic: string): string | undefined {
        if (!this.man_topics) {
            this.man_topics = this.build_index(MAN_DIRECTORIES, name => {
                return name.endsWith('.txt') ? name.substring(0, name.length - 4) : undefined;
            });
        }
        return this.man_topics.get(topic);
    }

    /** Absolute path of a skill's SKILL.md, or undefined. */
    resolve_skill(name: string): string | undefined {
        if (!this.skills) {
            this.skills = this.build_index(SKILL_DIRECTORIES, (entry_name, directory) => {
                const skill_file = path.join(directory, entry_name, 'SKILL.md');
                return fs.existsSync(skill_file) ? entry_name : undefined;
            }, true);
        }
        return this.skills.get(name);
    }

    /**
     * Walk the roots in priority order; the FIRST root that carries a name keeps
     * it, which is what makes an application page win over a framework one.
     */
    private build_index(
        directories: string[],
        key_of: (entry_name: string, directory: string) => string | undefined,
        is_skill_directory = false
    ): Map<string, string> {
        const index = new Map<string, string>();

        for (const relative_directory of directories) {
            const directory = path.join(this.rspade_root, relative_directory);
            let entries: string[];
            try {
                entries = fs.readdirSync(directory);
            } catch (e) {
                continue;
            }

            for (const entry_name of entries) {
                const key = key_of(entry_name, directory);
                if (key === undefined || index.has(key)) {
                    continue;
                }
                index.set(key, is_skill_directory
                    ? path.join(directory, entry_name, 'SKILL.md')
                    : path.join(directory, entry_name));
            }
        }

        return index;
    }

    /** Recognized tokens in a document, filtered to the ones that resolve. */
    resolved_tokens(document: vscode.TextDocument): Recognized_Token[] {
        const flavor = comment_flavor_for(document.fileName, document.languageId);
        const candidates = recognize_doc_references(document.getText(), flavor);
        return candidates.filter(token => this.resolve(token) !== undefined);
    }

    resolve(token: Recognized_Token): string | undefined {
        if (token.kind === 'man_topic') {
            return this.resolve_man_topic(token.value);
        }
        if (token.kind === 'skill') {
            return this.resolve_skill(token.value);
        }
        return undefined;
    }
}

/**
 * Document selectors the doc-reference providers register for. PHP and
 * JavaScript/TypeScript are absent on purpose: they already carry a semantic
 * tokens provider each, and VS Code honours ONE legend per language, so their doc
 * references are merged into those providers instead of registered separately.
 */
export function doc_reference_selectors(): vscode.DocumentSelector {
    return [
        { pattern: '**/*.scss' },
        { pattern: '**/*.jqhtml' },
        { pattern: '**/*.blade.php' },
        { pattern: '**/system/app/RSpade/man/*.txt' },
        { pattern: '**/rsx/resource/man/*.txt' },
    ];
}

/** Amber tokens for resolvable references. Token type 0 is `conventionMethod`. */
export class DocReferenceSemanticTokensProvider implements vscode.DocumentSemanticTokensProvider {
    private readonly index: DocReferenceIndex;

    constructor(index: DocReferenceIndex) {
        this.index = index;
    }

    async provideDocumentSemanticTokens(document: vscode.TextDocument): Promise<vscode.SemanticTokens> {
        const builder = new vscode.SemanticTokensBuilder();
        for (const token of this.index.resolved_tokens(document)) {
            builder.push(token.line, token.start, token.end - token.start, 0, 0);
        }
        return builder.build();
    }
}

/** F12 on a resolvable reference opens the page or the skill at line 1. */
export class DocReferenceDefinitionProvider implements vscode.DefinitionProvider {
    private readonly index: DocReferenceIndex;

    constructor(index: DocReferenceIndex) {
        this.index = index;
    }

    async provideDefinition(
        document: vscode.TextDocument,
        position: vscode.Position
    ): Promise<vscode.Definition | undefined> {
        const flavor = comment_flavor_for(document.fileName, document.languageId);
        const tokens = recognize_doc_references(document.getText(), flavor);
        const token = token_at(tokens, position.line, position.character);
        if (!token) {
            return undefined;
        }

        const file = this.index.resolve(token);
        if (!file) {
            return undefined;
        }

        return new vscode.Location(vscode.Uri.file(file), new vscode.Position(0, 0));
    }
}
