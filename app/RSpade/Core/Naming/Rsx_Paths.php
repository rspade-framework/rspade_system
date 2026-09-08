<?php

namespace App\RSpade\Core\Naming;

use App\RSpade\Core\Manifest\_Manifest_Scanner_Helper;

/**
 * THE ONE ANSWER TO "WHERE IS THIS FILE".
 *
 * The manifest speaks in RELATIVE paths (`rsx/models/client_model.php`,
 * `app/RSpade/Core/Rsx.php`) and the filesystem speaks in absolute ones, and the framework
 * used to convert between them in five different spellings that agreed only by accident:
 * two built `base_path('../' . $file)` for an `rsx/` path, the others `base_path($file)`,
 * and both work ONLY because `system/rsx` is a symlink to the project's `rsx/`. Ten more
 * places open-coded "is this a framework file" as `str_starts_with($p, 'app/RSpade/')` or
 * `str_contains($p, '/app/RSpade/')` - two tests that disagree the moment the caller's path
 * is spelled the other way.
 *
 * THE VOCABULARY:
 *
 *   relative()     absolute path -> manifest key form (the index's own spelling)
 *   absolute()     manifest key form -> a real path on disk
 *   is_framework() the file is framework property (app/RSpade/...)
 *   is_application() the file is application code (rsx/...)
 *   is_test_tree() the file is under one of the three test trees
 *
 * Every predicate accepts BOTH spellings - relative, absolute, and the `system/rsx`
 * symlink form of an application path - because a caller that has to know which spelling
 * it holds is a caller that will one day hold the other one.
 *
 * See: Core/Manifest/CLAUDE.md, rsx:man manifest_api.
 */
class Rsx_Paths
{
    /** The framework's own tree, as a manifest-relative prefix. */
    public const FRAMEWORK_PREFIX = 'app/RSpade/';

    /** The application tree, as a manifest-relative prefix. */
    public const APPLICATION_PREFIX = 'rsx/';

    /**
     * The manifest key form of an absolute path.
     *
     * `base_path()` is `<project>/system`, so an application file reached through the
     * `system/rsx` symlink and the same file reached through the real `<project>/rsx`
     * mount produce the SAME key - that convergence is the whole reason the second
     * `dirname(base_path())` attempt exists.
     *
     * A path that is already relative is returned unchanged.
     */
    public static function relative(string $path): string
    {
        if ($path === '' || !str_starts_with($path, '/')) {
            return $path;
        }

        return _rsx_relative_build_path($path);
    }

    /**
     * The absolute path of a manifest key.
     *
     * THREE CASES, and they are the reason this exists once instead of five times:
     *
     *   - `storage/...` is NOT under `base_path()` at all (volatile storage lives one level
     *     above it), so it goes through `rsx_project_file_path()`;
     *   - `rsx/...` resolves through the `system/rsx` symlink, which `base_path()` gives us
     *     for free - the `base_path('../' . $file)` spelling two call sites used reaches the
     *     same file by the real mount, and `rsxrealpath()` then made the two agree;
     *   - everything else is `base_path()` relative.
     *
     * An absolute path is passed through: a caller that already holds one is not made to
     * care.
     */
    public static function absolute(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return $path;
        }

        if (str_starts_with($path, 'storage/')) {
            return rsx_project_file_path($path);
        }

        return base_path($path);
    }

    /**
     * The CANONICAL path of a manifest key - the `system/rsx` symlink stepped through.
     *
     * `absolute()` is what you OPEN; this is what you COMPARE, hash, or hand to a cache
     * key, because `<project>/system/rsx/x.php` and `<project>/rsx/x.php` are one file and
     * only one of those spellings can be the answer.
     *
     * THE PROJECT-ROOT SPELLING IS THE CANONICAL ONE, and this is load-bearing rather than a
     * preference: the reflection pass compares `ReflectionMethod::getFileName()` against
     * this path to decide whether a method is DECLARED by the file or inherited, and PHP
     * reports the file it actually included - the project mount. Answer with the symlink
     * spelling and every method of every application class looks inherited, so the class
     * ends up with no method map at all (observed as "Email class has no sample()" on a
     * class whose sample() is right there).
     *
     * `rsxrealpath()` is a LEXICAL normalizer - it collapses `..` and does not resolve
     * symlinks - which is why the `../` has to be written rather than resolved.
     */
    public static function real(string $path): string
    {
        if (str_starts_with($path, static::APPLICATION_PREFIX)) {
            $real = rsxrealpath(base_path('../' . $path));

            return is_string($real) && $real !== '' ? $real : $path;
        }

        $absolute = static::absolute($path);
        $real = rsxrealpath($absolute);

        return is_string($real) && $real !== '' ? $real : $absolute;
    }

    /**
     * Is this framework property - a file under `app/RSpade/`?
     */
    public static function is_framework(string $path): bool
    {
        return static::__has_segment_prefix($path, static::FRAMEWORK_PREFIX);
    }

    /**
     * Is this application code - a file under `rsx/`?
     *
     * The `system/rsx` symlink means an absolute application path can be spelled either
     * `<project>/rsx/...` or `<project>/system/rsx/...`; both contain the `/rsx/` segment,
     * so both answer true, and no framework path contains it.
     */
    public static function is_application(string $path): bool
    {
        return static::__has_segment_prefix($path, static::APPLICATION_PREFIX);
    }

    /**
     * Is this file under one of the three TEST TREES?
     *
     * `app/RSpade/tests`, `app/RSpade/temp` and `rsx/tests` are indexed only while the
     * process is a test run, so a fixture ENTERS and LEAVES the index on the boundary of
     * every run. Two build-time decisions have to ignore that transition or they turn a
     * fixture appearing into work over the whole tree: the fixer's structure fingerprint
     * and a cross-file rule's dependency fingerprint. This predicate is what they ask.
     */
    public static function is_test_tree(string $path): bool
    {
        foreach (_Manifest_Scanner_Helper::TEST_SCAN_DIRECTORIES as $tree) {
            if (static::__has_segment_prefix($path, rtrim($tree, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does $path begin with $prefix, as WHOLE SEGMENTS, in either spelling?
     *
     * Relative: `app/RSpade/Core/Rsx.php` starts with `app/RSpade/`.
     * Absolute: `/var/www/html/system/app/RSpade/Core/Rsx.php` contains `/app/RSpade/`.
     *
     * The leading-slash form is what makes the absolute test a SEGMENT test rather than a
     * substring one - a directory called `my_app/RSpade` matches neither.
     */
    private static function __has_segment_prefix(string $path, string $prefix): bool
    {
        return str_starts_with($path, $prefix) || str_contains($path, '/' . $prefix);
    }
}
