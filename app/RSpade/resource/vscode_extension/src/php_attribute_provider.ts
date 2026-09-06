import * as vscode from 'vscode';
import { DocReferenceIndex } from './doc_reference_provider';

/**
 * Framework PHP attributes that should be highlighted.
 *
 * The whole declarative surface of the framework, so that an attribute reads as
 * framework vocabulary at a glance and a misspelling shows up as an uncoloured
 * one. Kept in step with what the manifest actually scans for - a name here that
 * nothing reads would colour a lie.
 */
const FRAMEWORK_ATTRIBUTES = [
    'Ajax_Endpoint_Model_Fetch',
    'Ajax_Endpoint',
    'Api_Endpoint',
    'Api_Param',
    'Auth_Check',
    'Auth_Realm',
    'Auth',
    'Command',
    'Debounce',
    'Emitter',
    'Exclusive',
    'FPC',
    'Health_Check',
    'Health_Heal',
    'Instantiatable',
    'Monoprogenic',
    'OnEvent',
    'Portal_Route',
    'Realtime_Touch',
    'Relationship',
    'Replaceable',
    'Revision_Parent',
    'Route',
    'Schedule',
    'Sealed',
    'SPA',
    'Task_Attribute',
    'Task',
];

/**
 * Semantic tokens for PHP files: framework attributes and resolvable man-page /
 * skill references in comments, both amber.
 *
 * One provider, because VS Code honours ONE semantic tokens legend per language.
 */
export class PhpAttributeSemanticTokensProvider implements vscode.DocumentSemanticTokensProvider {
    private readonly doc_reference_index: DocReferenceIndex | undefined;

    constructor(doc_reference_index?: DocReferenceIndex) {
        this.doc_reference_index = doc_reference_index;
    }

    async provideDocumentSemanticTokens(document: vscode.TextDocument): Promise<vscode.SemanticTokens> {
        const tokens_builder = new vscode.SemanticTokensBuilder();

        if (document.languageId !== 'php') {
            return tokens_builder.build();
        }

        const text = document.getText();
        const pushed: { line: number; character: number; length: number }[] = [];

        // Find all PHP attributes: #[AttributeName] or #[\AttributeName]
        // The list is ordered longest-prefix-first, and an already-covered range is
        // skipped, so #[Ajax_Endpoint_Model_Fetch] is not also matched as
        // #[Ajax_Endpoint].
        for (const attribute_name of FRAMEWORK_ATTRIBUTES) {
            const regex = new RegExp(`#\\[\\\\?(${attribute_name})(?:\\s|\\(|\\]|,)`, 'g');
            let match;

            while ((match = regex.exec(text)) !== null) {
                const attr_start = match.index + match[0].indexOf(match[1]);
                const position = document.positionAt(attr_start);

                if (pushed.some(t => t.line === position.line && t.character === position.character)) {
                    continue;
                }

                pushed.push({ line: position.line, character: position.character, length: attribute_name.length });
            }
        }

        if (this.doc_reference_index) {
            for (const token of this.doc_reference_index.resolved_tokens(document)) {
                pushed.push({ line: token.line, character: token.start, length: token.end - token.start });
            }
        }

        pushed.sort((a, b) => (a.line - b.line) || (a.character - b.character));

        for (const token of pushed) {
            tokens_builder.push(token.line, token.character, token.length, 0, 0);
        }

        return tokens_builder.build();
    }
}
