<?php

namespace App\RSpade\Core\Bundle;

use FilesystemIterator;
use RuntimeException;

/**
 * Bundle_Include_Pattern - the type-filtered directory include.
 *
 * A bundle 'include' entry names one file, or one directory and everything of every type
 * beneath it. A pattern is the third granularity: one directory, ONE file type.
 *
 *     'rsx/theme/*.scss'        that directory's own .scss files, and no subdirectory's
 *     'rsx/theme/** /*.scss'    that directory's .scss files and those of everything beneath it
 *
 * (The recursive form has no space in it. It is printed with one here, and in every other
 * block comment, because the unspaced sequence ends a PHP block comment; ACCEPTED_FORMS
 * below is the literal spelling.)
 *
 * THE GRAMMAR IS THOSE TWO FORMS AND NOTHING ELSE: a literal directory, an optional
 * recursion segment, and a file name that is exactly `*.<ext>` with one extension. No brace
 * sets, no character classes, no `?`, no exclusions, no `*` anywhere else. Any other use of
 * `*` is refused naming the accepted forms.
 *
 * WHY SO NARROW. A pattern is an ALLOWLIST: a file is in the bundle because the pattern
 * names its directory and its type. That is what a bundle served to visitors who have not
 * signed in needs - a new file should fail toward being absent (a visibly unstyled page),
 * never toward being published - and an exclusion list inverts it.
 *
 * A PATTERN THAT MATCHES NOTHING IS AN ERROR. A bundle that quietly stops including its
 * stylesheets because a directory was renamed renders a working page that looks wrong and
 * reports nothing, so the empty match is refused naming the pattern.
 *
 * ORDER. A pattern expands in place, in its position in the include list, to its matches
 * sorted by project-relative path - the order a directory include uses, and the same on
 * every checkout.
 *
 * The recursive form skips the directory names a directory include skips
 * (config rsx.manifest.excluded_dirs: vendor, node_modules, resource, public, ...).
 *
 * This class is the ONE definition of the grammar. BundleCompiler expands with it, and
 * every other reader of an include list asks is_pattern() rather than testing for `*`.
 */
class Bundle_Include_Pattern
{
    /**
     * The accepted forms, as printed in every refusal.
     */
    public const ACCEPTED_FORMS = "    '<directory>/*.<ext>'     the directory's own files of one type\n"
        . "    '<directory>/**/*.<ext>'  the directory's files of one type, and those of everything beneath it";

    /**
     * Is this include entry a pattern - that is, does it carry a wildcard at all?
     *
     * True for a MALFORMED pattern too: the question is "must this be parsed as one", and
     * parse() is what refuses the malformed ones.
     */
    public static function is_pattern($item): bool
    {
        return is_string($item) && str_contains($item, '*');
    }

    /**
     * Split a pattern into its parts.
     *
     * @return array{directory: string, recursive: bool, extension: string}
     * @throws RuntimeException when the string uses `*` in any way but the two accepted forms
     */
    public static function parse(string $pattern, ?string $declared_by = null): array
    {
        $normalized = str_replace('\\', '/', $pattern);

        if (!preg_match('#^(?<directory>[^*?\[\]{}!]+)/(?<recursive>\*\*/)?\*\.(?<extension>[A-Za-z0-9]+)$#', $normalized, $matches)) {
            throw new RuntimeException(
                "Bundle include pattern is not one of the accepted forms.\n\n" .
                ($declared_by !== null ? "Bundle: {$declared_by}\n" : '') .
                "Pattern: {$pattern}\n\n" .
                "An include entry containing '*' is a type-filtered directory include, and it has\n" .
                "exactly two forms:\n\n" .
                static::ACCEPTED_FORMS . "\n\n" .
                "One directory, one extension. There are no brace sets, no '?', no exclusions and no\n" .
                "'*' in any other position: name a second extension or a second directory with a\n" .
                'second entry.'
            );
        }

        return [
            'directory' => rtrim($matches['directory'], '/'),
            'recursive' => $matches['recursive'] !== '',
            'extension' => strtolower($matches['extension']),
        ];
    }

    /**
     * The files a pattern names, as absolute paths sorted by project-relative path.
     *
     * @param string $pattern base_path()-relative pattern (an absolute path under the
     *                        project is the compiler's to convert before calling)
     * @param string|null $declared_by the bundle whose include list carries the pattern,
     *                        named in a refusal
     * @return array<int,string>
     * @throws RuntimeException when the pattern is malformed, its directory does not exist,
     *                          or no file matches
     */
    public static function expand(string $pattern, ?string $declared_by = null): array
    {
        $parts = static::parse($pattern, $declared_by);
        $bundle_line = $declared_by !== null ? "Bundle: {$declared_by}\n" : '';
        $directory = base_path($parts['directory']);

        if (!is_dir($directory)) {
            throw new RuntimeException(
                "Bundle include pattern names a directory that does not exist.\n\n" .
                $bundle_line .
                "Pattern: {$pattern}\n" .
                "Directory: {$directory}\n\n" .
                "A pattern that matches nothing is refused: the bundle would compile without the\n" .
                "files it was written to include, and a missing stylesheet reports no error.\n" .
                'Fix the path, or remove the entry.'
            );
        }

        $files = [];
        static::_collect($directory, $parts['recursive'], $parts['extension'], $files);

        if ($files === []) {
            throw new RuntimeException(
                "Bundle include pattern matches no files.\n\n" .
                $bundle_line .
                "Pattern: {$pattern}\n" .
                "Directory: {$directory}\n\n" .
                "A pattern that matches nothing is refused: the bundle would compile without the\n" .
                "files it was written to include, and a missing stylesheet reports no error.\n" .
                ($parts['recursive']
                    ? "No .{$parts['extension']} file exists in that directory or beneath it.\n"
                    : "No .{$parts['extension']} file exists directly in that directory. '*.{$parts['extension']}' does not\n" .
                      "descend: '{$parts['directory']}/**/*.{$parts['extension']}' is the form that does.\n") .
                'Fix the pattern, or remove the entry.'
            );
        }

        usort($files, static fn ($a, $b) => strcmp(_rsx_relative_build_path($a), _rsx_relative_build_path($b)));

        return $files;
    }

    /**
     * Gather the matching files of one directory, descending when asked to.
     */
    protected static function _collect(string $directory, bool $recursive, string $extension, array &$files): void
    {
        $excluded_dirs = config('rsx.manifest.excluded_dirs', ['vendor', 'node_modules', 'storage', '.git', 'public', 'resource']);

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir()) {
                // Same walk a directory include makes: excluded names are passed over, and
                // a symlinked directory is not descended into.
                if ($recursive && !$entry->isLink() && !in_array($entry->getBasename(), $excluded_dirs, true)) {
                    static::_collect($entry->getPathname(), true, $extension, $files);
                }

                continue;
            }

            if ($entry->isFile() && strtolower($entry->getExtension()) === $extension) {
                $files[] = $entry->getPathname();
            }
        }
    }
}
