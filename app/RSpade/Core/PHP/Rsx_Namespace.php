<?php

namespace App\RSpade\Core\PHP;

use App\RSpade\Core\Naming\Rsx_Paths;
use App\RSpade\Core\PHP\Php_Parser;

/**
 * THE ONE NAMESPACE-FROM-PATH RULE.
 *
 * RSpade derives a file's namespace from where it sits, and the derivation existed in one
 * place with three consumers reaching into it privately plus a validator that THREW when a
 * framework file disagreed with it. That is a contract - "this path implies this
 * namespace" - and a contract belongs somewhere it can be named.
 *
 * The rule itself: strip the extension and the filename, PascalCase every remaining
 * segment, and root the result at `Rsx` for an application path or `App` for a framework
 * one. `rsx/models/client_model.php` is `Rsx\Models`; `app/RSpade/Core/PHP/Php_Fixer.php`
 * is `App\RSpade\Core\PHP`.
 *
 * See: Core/Manifest/CLAUDE.md.
 */
class Rsx_Namespace
{
    /**
     * The namespace a file at this path must declare.
     *
     * Takes a manifest key or an absolute path; anything outside `rsx/` and `app/` has no
     * derivable namespace and is an impossible condition at every call site the framework
     * has, so it says so rather than inventing one.
     */
    public static function from_path(string $file_path): string
    {
        $relative = Rsx_Paths::relative($file_path);

        $parts = explode('/', substr($relative, 0, -strlen('.php')));

        if ($parts[0] === 'rsx') {
            $root = 'Rsx';
        } elseif ($parts[0] === 'app') {
            $root = 'App';
        } else {
            shouldnt_happen("Rsx_Namespace::from_path() called with a path outside rsx/ and app/: {$file_path}");
        }

        array_shift($parts);
        array_pop($parts);

        $segments = array_map([static::class, '__pascal_case'], $parts);

        return empty($segments) ? $root : $root . '\\' . implode('\\', $segments);
    }

    /**
     * The namespace a token stream DECLARES, or null when it declares none.
     *
     * One walker, in the parser that already owned the token-level one; the fixer's copy
     * differed only in that it stopped at `;` and never at `{`.
     */
    public static function from_tokens(array $tokens): ?string
    {
        return Php_Parser::namespace_from_tokens($tokens);
    }

    /**
     * `client_model` -> `ClientModel`; `data-grid` -> `DataGrid`; `Core` -> `Core`.
     *
     * The separator is DROPPED, not kept: a directory is one namespace segment.
     */
    private static function __pascal_case(string $segment): string
    {
        $out = '';

        foreach (explode(' ', str_replace(['_', '-'], ' ', $segment)) as $word) {
            $out .= ucfirst($word);
        }

        return $out;
    }
}
