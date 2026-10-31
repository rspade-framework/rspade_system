<?php

/**
 * THE ORDER AN AMBIGUOUS IDENTIFIER RESOLVES IN, for go-to-definition.
 *
 * One name can be declared by several kinds of file at once - a jqhtml component, a PHP
 * class, a JS class, a Blade view's @rsx_id, and the stylesheet that styles it all carry
 * `Contacts_View_Action`-shaped names. When the editor gives no context that settles which
 * is meant, the answer is the first of these that declares it:
 *
 *     jqhtml template  ->  PHP class  ->  JS class  ->  Blade view id  ->  stylesheet id
 *
 * Pure functions over manifest data, required by handler.php - kept apart from it because
 * including handler.php runs a request, and the order is what a test needs to pin.
 */

/**
 * The resolve_class type list for an identifier the caller gave no type for.
 *
 * `bundle_alias` comes last: an alias is a lowercase bundle key, never one of the
 * class-shaped names this order is about.
 *
 * @return array<int,string>
 */
function ide_ambiguous_type_order(): array
{
    return ['jqhtml_template', 'php_class', 'js_class', 'view', 'scss', 'bundle_alias'];
}

/**
 * Where a file whose record carries an `id` ranks for that id: jqhtml template 0, Blade
 * view 1, stylesheet 2; null for any other file (an `id` on it is not a declaration this
 * order knows).
 */
function ide_id_rank(array $record): ?int
{
    $extension = $record['extension'] ?? '';

    if ($extension === 'jqhtml') {
        return 0;
    }
    if ($extension === 'blade.php') {
        return 1;
    }
    if ($extension === 'scss') {
        return 2;
    }

    return null;
}

/**
 * The best file declaring `$id`, as `['file' => path] + record`, or null.
 *
 * Every candidate is considered and the lowest rank wins (ide_id_rank), ties going to the
 * first path in sort order - never to whichever record the map happens to list first.
 * `$extensions` narrows the candidates (['scss'] for a stylesheet-only lookup).
 *
 * @param array<string,array> $files the manifest file map, path => record
 * @param array<int,string>|null $extensions
 */
function ide_best_id_match(array $files, string $id, ?array $extensions = null): ?array
{
    $best_path = null;
    $best_rank = null;

    foreach ($files as $path => $record) {
        if (($record['id'] ?? null) !== $id) {
            continue;
        }
        if ($extensions !== null && !in_array($record['extension'] ?? '', $extensions, true)) {
            continue;
        }

        $rank = ide_id_rank($record);
        if ($rank === null) {
            continue;
        }

        if ($best_rank === null || $rank < $best_rank || ($rank === $best_rank && strcmp($path, $best_path) < 0)) {
            $best_path = $path;
            $best_rank = $rank;
        }
    }

    return $best_path === null ? null : ['file' => $best_path] + $files[$best_path];
}
