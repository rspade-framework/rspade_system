<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\_Manifest_Builder_Helper;
use App\RSpade\Core\Naming\Rsx_Paths;
use App\RSpade\Core\Rsx;

/**
 * _Manifest_Quality_Helper - Code quality and IDE support
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_Quality_Helper
{
    /**
    * Run the manifest-time code-quality pass.
    *
    * THE DRIVER OWNS THE PASS. Discovery, pattern compilation, source reading, tokenizing,
    * parsing, the per-file incremental decision and the cross-file dependency gate all live
    * in App\RSpade\CodeQuality\Manifest_Rule_Driver; this function decides only WHICH
    * files the pass is about and releases the driver when it is done.
    *
    * What used to be here: two rule discoveries, a metadata-extraction phase that ran a
    * separate `on_manifest_file_update` hook over every changed file and stored its findings
    * in the index, a loop that walked the WHOLE file map once PER RULE re-reading each file,
    * and every cross-file rule running unconditionally on every rebuild.
    *
    * @param array $changed_files Files that changed in this manifest scan (relative paths)
    */
    public static function _run_manifest_time_code_quality_checks(array $changed_files = []): void
    {
        $collector = new \App\RSpade\CodeQuality\Support\ViolationCollector();

        $driver = new \App\RSpade\CodeQuality\Manifest_Rule_Driver(
            collector: $collector,
            config: [],
            only_manifest_scan: true,
            throw_on_violation: true,
            source: Manifest::build()->source_cache(),
        );

        try {
            // An empty changed set still runs the cross-file rules: a REMOVED file changes
            // the tree without appearing in the changed list.
            $driver->run($changed_files);
        } finally {
            $driver->finish();
        }
    }

    /**
    * Check for duplicate base class names within the same file type
    *
    * This method handles two scenarios:
    * 1. RESTORE: If a .upstream file exists but no active override exists, restore it
    * 2. OVERRIDE: When a class exists in both rsx/ and app/RSpade/, rename framework to .upstream
    *
    * Throws a fatal error if duplicates exist within the same area (both rsx/ or both app/RSpade/)
    */
    public static function _check_unique_base_class_names(array $dirty_files = []): void
    {
        // ==================================================================================
        // STEP 1: Restore orphaned .upstream files
        // ==================================================================================
        // Check all php.upstream files - if their class no longer has an override in rsx/,
        // restore the framework file by renaming .upstream back to .php
        // ==================================================================================
        Manifest::_restore_orphaned_upstream_files();

        // ==================================================================================
        // STEP 2: Group classes by extension, then by class name
        // ==================================================================================
        // WHICH CLASS NAMES CAN HAVE MOVED. A duplicate appears only when a file that
        // declares the name arrived or changed, so the pass ACTS only on names a dirty file
        // declares. The grouping below is still one scalar pass over the index, because
        // answering "who else declares this name" needs the whole picture - what it no
        // longer does is re-adjudicate 640 settled class names on every rebuild.
        $dirty_classes = null;

        if (!empty($dirty_files)) {
            $dirty_classes = [];

            foreach ($dirty_files as $dirty_file) {
                $class = Manifest::$data['data']['files'][$dirty_file]['class'] ?? null;

                if ($class !== null && $class !== '') {
                    $dirty_classes[$class] = true;
                }
            }
        }
        // THE ONE GROUPING - the same one _collate_files_by_classes() reads. This pass used
        // to build its own, which is how the framework ended up with two answers to "which
        // files declare this class name" that could disagree about their own filters.
        $classes_by_extension = _Manifest_Builder_Helper::_group_files_by_class_name();

        // ==================================================================================
        // STEP 3: Check for duplicates and create overrides
        // ==================================================================================
        // A build that has already poisoned its own manifest (manifest_is_bad) has declared
        // its index untrustworthy, and archiving is the one thing in this pass that cannot
        // be taken back on the next build. So it does not run: duplicates are still reported,
        // nothing is renamed, and the rebuild that follows the poisoning decides.
        $index_is_trustworthy = !Manifest::$_manifest_is_bad;

        foreach ($classes_by_extension as $extension => $base_class_files) {
            foreach ($base_class_files as $class_name => $files) {
                if ($dirty_classes !== null && !isset($dirty_classes[$class_name])) {
                    continue;
                }

                if (count($files) > 1) {
                    // Check if this is a valid override (rsx/ vs app/RSpade/)
                    $rsx_files = array_filter($files, fn ($f) => Rsx_Paths::is_application($f));
                    $framework_files = array_filter($files, fn ($f) => Rsx_Paths::is_framework($f));

                    // Valid override: exactly one file in rsx/, rest in app/RSpade/
                    if (count($rsx_files) === 1 && count($framework_files) >= 1) {
                        $rsx_file = array_values($rsx_files)[0];

                        // ==========================================================================
                        // RE-VERIFY THE TWIN AGAINST DISK BEFORE ARCHIVING ANYTHING
                        // ==========================================================================
                        // Archiving is destructive and irreversible within a build: the framework
                        // file is renamed away and the class stops existing. The only justification
                        // for it is that an rsx/ file is standing in for that class RIGHT NOW - so
                        // that claim is checked against the filesystem, never taken from the index.
                        //
                        // A field report on 2026-08-25 is why: a scan rule failed on a brand-new
                        // framework class while the index still carried just-deleted rsx/ copies of
                        // the same class names, the failure poisoned the manifest, and the next
                        // build ran this pass against that file list and archived the new framework
                        // files as twins of app classes that no longer existed. They vanished with
                        // no error naming them.
                        //
                        // A stale entry is dropped and the build restarts, so the next pass sees a
                        // file list that matches the disk.
                        // ==========================================================================
                        if (!$index_is_trustworthy) {
                            error_log(
                                '[Manifest] Class override pass: NOT archiving a framework twin of '
                                . $class_name . ' - this build already marked its manifest bad, so '
                                . 'its file list is not evidence of anything. The rebuild decides.'
                            );

                            continue;
                        }

                        if (!file_exists(base_path($rsx_file))) {
                            error_log(
                                '[Manifest] Class override pass: ignoring stale index entry for '
                                . $class_name . ' - ' . $rsx_file . ' is in the file list but not on '
                                . 'disk. No framework file was archived; dropping the entry and '
                                . 'restarting the build.'
                            );

                            unset(Manifest::$data['data']['files'][$rsx_file]);
                            Manifest::flag_needs_restart(
                                'the class-override pass dropped a stale index entry for ' . $class_name
                                . ' (' . $rsx_file . ' is indexed but not on disk)'
                            );

                            continue;
                        }

                        $rsx_metadata = Manifest::$data['data']['files'][$rsx_file] ?? [];
                        $rsx_extends = $rsx_metadata['extends'] ?? null;

                        // Check if rsx/ class extends the framework class (wrong pattern)
                        // This happens when someone tries to use OOP inheritance instead of
                        // the correct RSX override pattern (copy and replace)
                        if ($rsx_extends === $class_name) {
                            $framework_file = array_values($framework_files)[0];
                            throw new \RuntimeException(
                                "Fatal: Invalid class override pattern for '{$class_name}'.\n\n" .
                                "The file {$rsx_file} extends the framework class {$class_name},\n" .
                                "but RSX requires unique class names - you cannot have two classes\n" .
                                "with the same name, even if one extends the other.\n\n" .
                                "CORRECT OVERRIDE PATTERN:\n" .
                                "  1. Copy the framework file to your rsx/ directory:\n" .
                                "     cp system/{$framework_file} {$rsx_file}\n\n" .
                                "  2. Customize the copy as needed (change namespace, add methods, etc.)\n\n" .
                                "  3. The framework will automatically detect this and rename the\n" .
                                "     original to .upstream, allowing your version to take over.\n\n" .
                                "This pattern replaces the framework class entirely, allowing full\n" .
                                "customization while maintaining the same class name for compatibility."
                            );
                        }

                        $did_change = false;
                        // Rename framework files to .upstream and remove from manifest
                        foreach ($framework_files as $framework_file) {
                            $full_framework_path = base_path($framework_file);
                            $upstream_path = $full_framework_path . '.upstream';

                            if (file_exists($full_framework_path) && !file_exists($upstream_path)) {
                                // Normal case: rename to .upstream
                                rename($full_framework_path, $upstream_path);
                                Manifest::$_override_pass_renamed = true;

                                // Loud, not debug-channel: a class leaving the build must always
                                // be attributable to the override that took it out.
                                error_log(
                                    '[Manifest] Class override: ' . $class_name . ' - archived '
                                    . $framework_file . ' to ' . $framework_file . '.upstream, '
                                    . 'overridden by ' . $rsx_file
                                );
                                $did_change = true;
                            } elseif (file_exists($full_framework_path) && file_exists($upstream_path)) {
                                // Self-healing: both .php and .php.upstream exist (e.g., after framework update)
                                // Remove the .php file since .upstream is the correct archived version
                                unlink($full_framework_path);
                                Manifest::$_override_pass_renamed = true;
                                error_log(
                                    '[Manifest] Class override: ' . $class_name . ' - removed duplicate '
                                    . $framework_file . ' (its .upstream archive already exists), '
                                    . 'overridden by ' . $rsx_file
                                );
                                $did_change = true;
                            }

                            // Remove from manifest data so it won't be indexed
                            unset(Manifest::$data['data']['files'][$framework_file]);
                        }

                        if ($did_change) {
                            Manifest::flag_needs_restart(
                                'a framework file was archived as .upstream because ' . $class_name
                                . ' is overridden in rsx/'
                            );
                        }
                        continue;
                    }

                    // Not a valid override - throw error
                    $file_type = $extension === 'php' ? 'PHP' : ($extension === 'js' ? 'JavaScript' : $extension);

                    throw new \RuntimeException(
                        "Fatal: Duplicate {$file_type} class name '{$class_name}' found in multiple files:\n" .
'  - ' . implode("\n  - ", $files) . "\n" .
'Each class name must be unique within the same file type.'
                    );
                }
            }
        }
    }

    /**
    * Testable seam for the composer autoloader dump. Null = the real runner
    * (_run_composer_dump). Tests swap in a spy to assert the dump was / was not
    * invoked without actually shelling out to composer.
    *
    * @var callable|null
    */
    public static $_composer_dump_runner = null;

    /**
    * Validate composer's committed classmap against the filesystem and regenerate it
    * (blocking `composer dump-autoload`) when it has gone stale.
    *
    * WHY HERE: this runs in the manifest rebuild immediately after the class-override
    * rename/restore pass (_check_unique_base_class_names) has reached its settled state
    * for this rebuild - the exact moment classmap staleness is created (an override was
    * added: framework `.php` -> `.php.upstream`) or cured (an override was removed).
    * Composer's committed classmap still maps the framework FQCN to the renamed `.php`
    * path; left alone, composer's ClassLoader would `include` a missing file on every
    * request (tolerated at runtime by Autoloader's scoped warning carve-out, but the
    * on-disk data is still wrong). Regenerating the classmap here heals the data so
    * future requests/processes never hit the miss at all.
    *
    * The two mechanisms are complementary: the error-handler tolerance is the RUNTIME
    * guarantee (keeps THIS process working, whose in-memory classmap is already loaded
    * and cannot be un-staled mid-request); this dump is the DATA HYGIENE (fixes the
    * file for next time).
    *
    * Cost: ~10-20k warm file_exists() stats (tens of ms). It runs ONLY on code-change
    * rebuilds (this whole pipeline is rebuild-only; a no-change dev request loads cache
    * and never reaches here) and MUST NOT be cache-skipped beyond that gating.
    *
    * DEV-MODE ONLY concern: prod/sealed builds never auto-rebuild, and rsx:prod:build
    * already regenerates the composer autoloader (Prod_Build_Command step 3). The
    * validator simply runs wherever the override pass runs (dev rebuild +
    * framework:pull-triggered rebuild); no special prod handling is needed. It writes
    * only into vendor/composer (via the composer subprocess) and a rsx-tmp temp file
    * (via exec_safe) - never under rsx-build - so the sealed-build write-choke guards
    * are not engaged.
    *
    * @param string|null $classmap_path Absolute path to autoload_classmap.php.
    *                                   Null = the framework's committed classmap.
    */
    public static function _validate_composer_classmap(?string $classmap_path = null): void
    {
        $classmap_path = $classmap_path ?? base_path('vendor/composer/autoload_classmap.php');

        // No classmap present (e.g. a minimal install) - nothing to validate.
        if (!file_exists($classmap_path)) {
            return;
        }

        $stale = static::_find_stale_classmap_entries($classmap_path);
        if (empty($stale)) {
            return;
        }

        console_debug('AUTOLOAD', 'Composer classmap has ' . count($stale) . ' stale entr' . (count($stale) === 1 ? 'y' : 'ies') . ' (points at renamed/removed files) - regenerating with composer dump-autoload');

        $runner = static::$_composer_dump_runner ?? [static::class, '_run_composer_dump'];
        $runner($stale);
    }

    /**
    * Return classmap entries whose mapped file no longer exists on disk (the staleness
    * created by the override pass renaming a framework `.php` to `.php.upstream`).
    * Pure and testable: includes the classmap file and file_exists()-checks every path.
    *
    * @return array<string,string> FQCN => missing absolute path
    */
    public static function _find_stale_classmap_entries(string $classmap_path): array
    {
        // The generated classmap file returns FQCN => absolute path.
        $class_map = include $classmap_path;
        if (!is_array($class_map)) {
            return [];
        }

        $stale = [];
        foreach ($class_map as $fqcn => $path) {
            if (!file_exists($path)) {
                $stale[$fqcn] = $path;
            }
        }

        return $stale;
    }

    /**
    * Default composer-dump runner: blocking `composer dump-autoload` in base_path()
    * (= system/, where the framework composer.json lives). Fails LOUD on a non-zero
    * exit - a broken dump must never be silent. If composer is not on PATH we cannot
    * heal the on-disk classmap here; that is logged (the runtime warning tolerance
    * still keeps things working) and we return rather than fatal every rebuild.
    *
    * @param array<string,string> $stale The stale entries that triggered the dump.
    */
    public static function _run_composer_dump(array $stale): void
    {
        // Mirror Prod_Build_Command::_composer_available(): a missing composer binary
        // is not a hard failure - the Autoloader warning carve-out covers runtime, and
        // failing loud on every rebuild would break dev entirely.
        $composer_path = trim((string) @shell_exec('bash -c ' . escapeshellarg('command -v composer 2>/dev/null')));
        if ($composer_path === '') {
            console_debug('AUTOLOAD', 'composer not found on PATH - cannot regenerate stale classmap (runtime warning tolerance will cover it)');

            return;
        }

        // --no-scripts is LOAD-BEARING, not an optimization. Composer's stock
        // post-autoload-dump scripts include `@php artisan package:discover`, i.e. a
        // brand-new artisan process spawned mid-pull, on a tree that is being swapped
        // underneath it, with the runtime services stopped. It also cannot inherit the
        // pull's --_framework-update-override (an argv token), so it depends entirely on
        // the maintenance gate's classification to run at all. Any failure there exits
        // the dump non-zero and fires the throw below mid-pull. The heal only needs
        // autoload_classmap.php regenerated;
        // package:discover warms Laravel's package manifest, which a class-override
        // rename cannot invalidate and the rebuild re-runs regardless. Skipping the
        // scripts removes the nested-artisan failure class entirely.
        $output = [];
        $return_var = 0;
        \exec_safe('cd ' . escapeshellarg(base_path()) . ' && composer dump-autoload --optimize --no-scripts --no-interaction 2>&1', $output, $return_var);

        if ($return_var !== 0) {
            throw new \RuntimeException(
                "Fatal: composer dump-autoload failed (exit {$return_var}) while regenerating a stale classmap.\n\n" .
                "The manifest override pass renamed framework files to .upstream, leaving " . count($stale) . " stale classmap entr" . (count($stale) === 1 ? 'y' : 'ies') . ".\n" .
                "Composer's regeneration must succeed to heal the on-disk classmap.\n\n" .
                "composer output:\n" . implode("\n", $output)
            );
        }

        console_debug('AUTOLOAD', 'composer classmap regenerated (' . count($stale) . ' stale entr' . (count($stale) === 1 ? 'y' : 'ies') . ' cleared)');
    }

    /**
    * Generate VS Code IDE helper stubs for attributes and class aliases
    */
    public static function _generate_vscode_stubs(): void
    {
        // Generate to project root (parent of system/)
        $project_root = dirname(base_path());
        $stub_file = $project_root . '/._rsx_helper.php';

        $output = "<?php\n";
        $output .= "/* @noinspection ALL */\n";
        $output .= "// @formatter:off\n";
        $output .= "// phpcs:ignoreFile\n\n";
        $output .= "/**\n";
        $output .= " * RSX Framework Attribute Stubs for IDE Support\n";
        $output .= " *\n";
        $output .= " * AUTO-GENERATED FILE - DO NOT EDIT MANUALLY\n";
        $output .= " *\n";
        $output .= " * This file is automatically regenerated during manifest builds when:\n";
        $output .= " * - Running `php artisan rsx:manifest:build`\n";
        $output .= " * - File changes detected in development mode\n";
        $output .= " * - Any PHP file with attributes is modified\n";
        $output .= " *\n";
        $output .= " * These are stub definitions to provide IDE autocomplete and eliminate warnings\n";
        $output .= " * for RSX framework attributes. These classes are never actually loaded or used\n";
        $output .= " * at runtime - they exist purely for IDE IntelliSense.\n";
        $output .= " *\n";
        $output .= " * This file should not be included in your code, only analyzed by your IDE!\n";
        $output .= " */\n\n";

        // Generate attribute stubs. The ATTRIBUTE INDEX is the one answer to "which
        // attributes does this tree declare", and it is ksorted - so the generated file is
        // deterministic, which matters because it is COMMITTED. This used to walk every file
        // and every method map in insertion order.
        $attributes = array_keys(Manifest::$data['data']['attribute_index'] ?? []);
        sort($attributes, SORT_STRING);

        if (!empty($attributes)) {
            $output .= "namespace {\n";
            foreach ($attributes as $attr_name) {
                $output .= "    #[\\Attribute(\\Attribute::TARGET_ALL | \\Attribute::IS_REPEATABLE)]\n";
                $output .= "    class {$attr_name} {\n";
                $output .= "        public function __construct(...\$args) {}\n";
                $output .= "    }\n\n";
            }
            $output .= "}\n";
        }

        // CONTENT-COMPARE BEFORE WRITING. ._rsx_helper.php is COMMITTED, sits in the project
        // root and is watched by the developer's IDE; rewriting it on every build churned an
        // mtime (and, before the attribute index made it deterministic, sometimes the bytes)
        // for a file whose content is a function of the tree's attribute vocabulary alone.
        if (!file_exists($stub_file) || file_get_contents($stub_file) !== $output) {
            file_put_contents_safe($stub_file, $output);
        }
    }

}
