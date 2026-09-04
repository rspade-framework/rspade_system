<?php

namespace App\RSpade\Core\Framework;

use App\RSpade\Core\Time\Rsx_Time;

/**
 * Breaking Changes tracking service.
 *
 * Downstream RSpade applications fork the /rsx/ starter template. When the
 * framework publishes a breaking-change mandate (a dated *.txt file in
 * app/RSpade/breaking_changes/) each application must track which mandates it
 * has already reviewed/replicated ("fulfilled") versus which are still pending.
 *
 * This static class is the single source of truth for that per-application
 * tracking state. All five rsx:framework:breaking_changes* commands call into
 * it - no manifest logic is duplicated per-command.
 *
 * The tracking state lives in a per-application dotfile committed alongside the
 * app's forked /rsx/ code: rsx/resource/.breaking_changes_manifest.json
 */
class Breaking_Changes
{
    /**
     * Directory holding the breaking-change mandate *.txt files.
     */
    public static function directory(): string
    {
        return base_path('app/RSpade/breaking_changes');
    }

    /**
     * Path to the per-application manifest dotfile.
     *
     * Calling this ADOPTS an existing manifest written under the directory's former name
     * (see __adopt_former_manifest_name()), so the caller never has to think about it.
     */
    public static function manifest_path(): string
    {
        $path = base_path('rsx/resource/.breaking_changes_manifest.json');

        static::__adopt_former_manifest_name($path);

        return $path;
    }

    /**
     * Carry an app's fulfillment record across the directory's rename.
     *
     * This system was called `upstream_changes` until 2026-09-04, and each application
     * records which mandates it has already dealt with in a dotfile named after it. Reading
     * the new name on an app that has the old file would silently discard that record and
     * re-raise every historical mandate as unfulfilled - a burst of noise, which is the
     * exact thing the rename was made to reduce.
     *
     * One rename, once, when the old file exists and the new one does not. Its EAGER twin
     * is system/bin/environment_updates/100_breaking_changes_manifest_rename.sh - the same
     * idempotent rename at framework-update time; this lazy side must stay because the
     * pull's own rsx:framework:post_update reads the manifest BEFORE the environment
     * updates run, and a fresh-baselined manifest would silently mark real pending
     * documents fulfilled. Whichever side runs first wins; delete both together once no
     * live application predates the rename.
     */
    private static function __adopt_former_manifest_name(string $current_path): void
    {
        if (file_exists($current_path)) {
            return;
        }

        $former_path = base_path('rsx/resource/.upstream_changes_manifest.json');

        if (!file_exists($former_path)) {
            return;
        }

        rename($former_path, $current_path);
    }

    /**
     * All breaking-change mandate filenames (basenames), sorted.
     *
     * Only *.txt files count as mandates. This naturally excludes CLAUDE.md and
     * the *_reference.js companion files that sit alongside some mandates.
     */
    public static function list_all_files(): array
    {
        $files = [];

        foreach (glob(static::directory() . '/*.txt') as $path) {
            $files[] = basename($path);
        }

        sort($files);

        return $files;
    }

    /**
     * Read the manifest as an associative array (filename => status record).
     *
     * This is the single choke point for manifest reads. If the manifest does
     * not yet exist, it is auto-populated from the current mandate files with
     * every file marked "fulfilled" (a one-time baseline: an existing app is
     * assumed already in sync at the moment tracking begins). New mandates added
     * after this baseline are absent from the manifest and therefore report as
     * unfulfilled via is_fulfilled().
     */
    public static function get_manifest(): array
    {
        $path = static::manifest_path();

        if (!file_exists($path)) {
            // First use: baseline every current mandate as already fulfilled.
            $timestamp = Rsx_Time::now_iso();
            $manifest = [];

            foreach (static::list_all_files() as $filename) {
                $manifest[$filename] = [
                    'status'     => 'fulfilled',
                    'updated_at' => $timestamp,
                ];
            }

            static::save_manifest($manifest);

            return $manifest;
        }

        $contents = file_get_contents($path);
        $manifest = json_decode($contents, true);

        if (!is_array($manifest)) {
            throw new \RuntimeException(
                'Corrupt breaking changes manifest (not valid JSON): ' . $path
            );
        }

        return $manifest;
    }

    /**
     * Persist the manifest: sort keys alphabetically and pretty-print for clean
     * git diffs when the downstream app commits this file.
     */
    public static function save_manifest(array $manifest): void
    {
        ksort($manifest);

        $directory = dirname(static::manifest_path());
        ensure_directory($directory);

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents(static::manifest_path(), $json . "\n");
    }

    /**
     * Whether a mandate has been marked fulfilled.
     *
     * A mandate absent from the manifest (e.g. a new breaking change added after
     * the baseline auto-populate) is unfulfilled by default.
     */
    public static function is_fulfilled(string $filename): bool
    {
        $manifest = static::get_manifest();

        return isset($manifest[$filename])
            && ($manifest[$filename]['status'] ?? null) === 'fulfilled';
    }

    /**
     * The mandate files still needing review, sorted.
     */
    public static function get_pending(): array
    {
        $pending = [];

        foreach (static::list_all_files() as $filename) {
            if (!static::is_fulfilled($filename)) {
                $pending[] = $filename;
            }
        }

        sort($pending);

        return $pending;
    }

    /**
     * Mark a mandate fulfilled or unfulfilled and persist the change.
     *
     * @throws \InvalidArgumentException if $filename is not a known mandate.
     */
    public static function mark(string $filename, bool $fulfilled): void
    {
        if (!in_array($filename, static::list_all_files(), true)) {
            throw new \InvalidArgumentException(
                "Not a breaking change file: '{$filename}'."
            );
        }

        $manifest = static::get_manifest();

        $manifest[$filename] = [
            'status'     => $fulfilled ? 'fulfilled' : 'unfulfilled',
            'updated_at' => Rsx_Time::now_iso(),
        ];

        static::save_manifest($manifest);
    }

    /**
     * Raw content of a mandate file.
     *
     * @throws \InvalidArgumentException if $filename is not a known mandate.
     */
    public static function get_content(string $filename): string
    {
        if (!in_array($filename, static::list_all_files(), true)) {
            throw new \InvalidArgumentException(
                "Not a breaking change file: '{$filename}'."
            );
        }

        return file_get_contents(static::directory() . '/' . $filename);
    }

    /**
     * Fuzzy-resolve a user-supplied needle to an exact mandate filename.
     *
     * Mirrors rsx:test --filter ergonomics (case-insensitive substring):
     *   1. Exact match (with .txt appended if missing) wins.
     *   2. Otherwise a single case-insensitive substring match wins.
     *   3. Zero matches -> throw.
     *   4. Multiple matches -> throw, listing them.
     *
     * @throws \InvalidArgumentException on no match or an ambiguous match.
     */
    public static function resolve_filename(string $needle): string
    {
        $files = static::list_all_files();

        // (1) Exact match, appending .txt if the user omitted it.
        $exact = str_ends_with($needle, '.txt') ? $needle : $needle . '.txt';
        if (in_array($exact, $files, true)) {
            return $exact;
        }

        // (2) Case-insensitive substring match.
        $matches = [];
        $lower_needle = strtolower($needle);
        foreach ($files as $filename) {
            if (str_contains(strtolower($filename), $lower_needle)) {
                $matches[] = $filename;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($matches) === 0) {
            throw new \InvalidArgumentException(
                "No breaking change file matches '{$needle}'."
            );
        }

        throw new \InvalidArgumentException(
            "Ambiguous match for '{$needle}': " . implode(', ', $matches)
            . ' - be more specific.'
        );
    }
}
