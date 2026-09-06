/**
 * Hover text for an #[Auth('...')] / @auth('...') check name.
 *
 * Says which #[Auth_Check] method answers the name and in which realm, so the
 * question "where does this gate actually get decided" is answered without
 * leaving the line. The lookup goes through the definition provider's own bridge
 * client - one client, one recognizer, one answer.
 */

import * as vscode from 'vscode';
import { RspadeDefinitionProvider } from './definition_provider';
import { recognize_auth_checks, token_at } from './rspade_recognizers';

export class AuthCheckHoverProvider implements vscode.HoverProvider {
    private readonly definition_provider: RspadeDefinitionProvider;

    constructor(definition_provider: RspadeDefinitionProvider) {
        this.definition_provider = definition_provider;
    }

    async provideHover(
        document: vscode.TextDocument,
        position: vscode.Position
    ): Promise<vscode.Hover | undefined> {
        const line_text = document.lineAt(position.line).text;
        const token = token_at(recognize_auth_checks(line_text, position.line), position.line, position.character);
        if (!token) {
            return undefined;
        }

        let result: any;
        try {
            result = await this.definition_provider.lookup_auth_check(
                token.value,
                this.definition_provider.auth_realm_for_document(document)
            );
        } catch (error) {
            return undefined;
        }

        if (!result || !result.found) {
            return undefined;
        }

        const simple_class = String(result.class ?? '').split('\\').pop();
        const markdown = new vscode.MarkdownString();
        markdown.appendMarkdown(`**${simple_class}::${result.method}()**\n\n`);
        markdown.appendMarkdown(`Auth check \`${token.value}\` - ${result.realm} realm\n\n`);
        markdown.appendMarkdown(`\`${result.file}\``);

        const range = new vscode.Range(
            new vscode.Position(token.line, token.start),
            new vscode.Position(token.line, token.end)
        );

        return new vscode.Hover(markdown, range);
    }
}
