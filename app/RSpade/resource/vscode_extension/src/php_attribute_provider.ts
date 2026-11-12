import * as vscode from 'vscode';
import { DocReferenceIndex } from './doc_reference_provider';
import { recognize_php_attributes } from './rspade_recognizers';

/**
 * Semantic tokens for PHP files: framework attributes and resolvable man-page /
 * skill references in comments, both amber.
 *
 * Which attributes are framework vocabulary is framework_vocabulary.ts, and where
 * they are is recognize_php_attributes() - the same tokens the hover describes.
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

        for (const token of recognize_php_attributes(text)) {
            pushed.push({ line: token.line, character: token.start, length: token.end - token.start });
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
