/**
 * Hover text for a framework PHP attribute (`#[Writer]`) or JS decorator
 * (`@spinner_until_ready`): what it does, where it goes, its arguments, and the
 * man page or skill to read.
 *
 * The tokens are the recognizers' (rspade_recognizers.ts) and the text is
 * framework_vocabulary.ts - the same tokens the semantic-token providers colour,
 * so every coloured name has a tooltip. It coexists with AuthCheckHoverProvider:
 * that one answers on the quoted check names inside #[Auth(...)] / @auth(...),
 * this one on the attribute or decorator name.
 */

import * as vscode from 'vscode';
import { vocabulary_markdown, Vocabulary_Kind } from './framework_vocabulary';
import {
    recognize_js_decorators,
    recognize_php_attributes,
    Recognized_Token,
    token_at,
} from './rspade_recognizers';

export class FrameworkVocabularyHoverProvider implements vscode.HoverProvider {
    /** The last document scanned, so moving the mouse over one file rescans nothing. */
    private cached: { uri: string; version: number; tokens: Recognized_Token[] } | undefined;

    provideHover(document: vscode.TextDocument, position: vscode.Position): vscode.Hover | undefined {
        const kind: Vocabulary_Kind = document.languageId === 'php' ? 'php_attribute' : 'js_decorator';
        const token = token_at(this.tokens_for(document, kind), position.line, position.character);
        if (!token) {
            return undefined;
        }

        const markdown = vocabulary_markdown(kind, token.value);
        if (markdown === undefined) {
            return undefined;
        }

        const range = new vscode.Range(
            new vscode.Position(token.line, token.start),
            new vscode.Position(token.line, token.end)
        );

        return new vscode.Hover(new vscode.MarkdownString(markdown), range);
    }

    private tokens_for(document: vscode.TextDocument, kind: Vocabulary_Kind): Recognized_Token[] {
        const uri = document.uri.toString();
        if (this.cached && this.cached.uri === uri && this.cached.version === document.version) {
            return this.cached.tokens;
        }

        const text = document.getText();
        const tokens = kind === 'php_attribute' ? recognize_php_attributes(text) : recognize_js_decorators(text);
        this.cached = { uri, version: document.version, tokens };
        return tokens;
    }
}
