<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Bundle\Minifier;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\JsParsers\Rsx_Node_Service;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Prod\Rsx_Build_Context;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use App\RSpade\Core\Rsx;
use Exception;
use Illuminate\Console\Command;

/**
 * THE build. One command produces everything the site serves.
 *
 * DEVELOPMENT builds what a page would otherwise build for itself on first request -
 * the manifest and every bundle - and nothing else. It seals nothing: a development box
 * rebuilds on change, which is the whole point of the mode.
 *
 * A PRODUCTION-LIKE MODE (debug or production) produces a deployment artifact, so the
 * pipeline is fixed and complete, in this order:
 *
 *   1. preflight   - build/ and tmp/ writable, checked before anything is destroyed
 *   2. rsx:clean   - a build starts from nothing, or it is not reproducible
 *   3. manifest    - the index every later step reads
 *   4. externals   - every declared external mirrored, so the build is self-contained
 *   5. bundles     - minified in strict production
 *   6. composer    - optimized autoloader (classmap-authoritative in strict production)
 *   7. laravel     - routes, events and compiled Blade into build/
 *   8. the seal    - the record that a build completed, and what it contained
 *
 * The seal is written LAST and only on success: a box whose pipeline failed halfway is
 * unsealed, and an unsealed production box refuses to run (Manifest::init()) rather than
 * serving half a build.
 *
 * THIS PROCESS IS THE BUILD CONTEXT (Rsx_Build_Context), recognised from argv so it holds
 * from the first line of boot, and carried to every subprocess as an internal flag. It is
 * the only thing that may write under build/ in a production-like mode.
 */
class Build_Command extends Command
{
    protected $signature = 'rsx:build
                            {--force : Rebuild even though a sealed build is already present}';

    protected $description = 'Build everything the site serves (manifest, bundles, caches; seals in a production-like mode)';

    public function handle(): int
    {
        // Belt on the argv recognition: a build driven any other way is still a build.
        Rsx_Build_Context::begin();

        if (!Rsx::is_production()) {
            return $this->_development_build();
        }

        return $this->_production_build();
    }

    // -------------------------------------------------------------------------
    // Development
    // -------------------------------------------------------------------------

    /**
     * Manifest + every bundle. Nothing is sealed and nothing is torn down: development
     * rebuilds what changed, and a full wipe is rsx:clean's job when it is wanted.
     */
    private function _development_build(): int
    {
        $start_time = microtime(true);

        $this->info('Building development assets...');
        $this->newLine();

        Rsx_Project_Paths::ensure_build_tree();

        $this->line('[1/2] Building manifest...');
        if (!$this->_build_manifest()) {
            return 1;
        }

        $this->line('[2/2] Compiling bundles...');
        if (!$this->_compile_bundles(false)) {
            return 1;
        }

        $elapsed = round(microtime(true) - $start_time, 2);

        $this->newLine();
        $this->info("[OK] Development build complete ({$elapsed}s)");

        return 0;
    }

    // -------------------------------------------------------------------------
    // Debug / production
    // -------------------------------------------------------------------------

    private function _production_build(): int
    {
        $mode = Rsx::get_mode();

        if (Rsx_Prod_Seal::exists() && !$this->option('force')) {
            $this->error('A sealed ' . $mode . ' build is already present.');
            $this->line('  Rebuilding replaces what this box is serving right now.');
            $this->line('  Run it deliberately: php artisan rsx:build --force');

            return 1;
        }

        // PREFLIGHT, before anything is touched. A build that discovers halfway through
        // that it cannot write has already destroyed the build it was replacing.
        $unwritable = $this->_unwritable_roots();
        if (!empty($unwritable)) {
            $this->error('Cannot build - these directories are not writable by this user:');
            foreach ($unwritable as $line) {
                $this->line('  ' . $line);
            }
            $this->line('  Nothing was changed.');

            return 1;
        }

        $this->info('Building sealed ' . $mode . ' assets...');
        $this->newLine();

        $start_time = microtime(true);

        // Step 1: start from nothing. A fresh process so the wipe is total (rsx:clean
        // refuses $this->call() for exactly that reason), carrying the build context so
        // the guard lets it discard the tree it is about to replace.
        $this->line('[1/7] Clearing the build and temp trees...');
        $clean_exit = Rsx_Artisan::passthru(
            'rsx:clean',
            array_merge(['--force', '--silent', Clean_Command::FLAG_NO_SYSTEM_RESET], Rsx_Build_Context::child_flags())
        );
        if ($clean_exit !== 0) {
            $this->error('      rsx:clean failed (exit ' . $clean_exit . '); nothing was built.');

            return 1;
        }
        Rsx_Project_Paths::ensure_build_tree();

        // Step 2: the manifest every later step reads.
        $this->line('[2/7] Building manifest...');
        if (!$this->_build_manifest()) {
            return 1;
        }

        // Everything below is the build pipeline, and only the build pipeline may populate
        // the CDN mirror (Cdn_Cache refuses a download outside it in a production mode).
        Cdn_Cache::$_build_phase = true;

        try {
            // Step 3: mirror the declared externals.
            //
            // A production build is SELF-CONTAINED: every mirrorable external resource is
            // served from our own /_vendor/ copy. Bundle CDN assets are mirrored lazily by
            // the compiler (step 4), but a *.externals.php declaration is loaded by the
            // client at runtime and nothing in the compile touches the network for it -
            // hence this step, which downloads exactly what Rsx_Externals::resolve_url()
            // will name. One line per download; silence for a file already verified present;
            // anything unfetchable fails the build.
            $this->line('[3/7] Mirroring external assets...');
            if (!$this->_mirror_declared_externals()) {
                return 1;
            }

            // Step 4: every bundle.
            $this->line('[4/7] Compiling bundles...');
            Minifier::force_restart();

            $compiled = $this->_compile_bundles(true);

            // Release the node service now that all bundles are compiled. Leaving a stale
            // unix socket around would confuse the next build's force_restart.
            Rsx_Node_Service::stop(force: true);

            if (!$compiled) {
                return 1;
            }

            // Step 5: the composer autoloader. This MUST run BEFORE the Laravel caches
            // below: composer's post-autoload-dump hook runs Laravel's
            // ComposerScripts::clearCompiled(), which unlinks the cached config/services -
            // so caching Laravel first and dumping the autoloader second would wipe the
            // freshly-built caches. --classmap-authoritative is strict-production only (it
            // forbids composer's filesystem fallback); it covers ONLY the system/ PSR-4
            // tree, while /rsx classes resolve through the manifest autoloader, so the
            // class-override pattern is unaffected.
            $this->line('[5/7] Optimizing composer autoloader...');
            if (!$this->_dump_autoloader()) {
                return 1;
            }

            // Step 6: Laravel's own caches - routes, events and compiled Blade, all into
            // build/. Precompiling Blade is what lets a served request read the build tree
            // without writing to it.
            $this->line('[6/7] Building Laravel caches...');
            $exit_code = Rsx_Artisan::passthru('optimize:cache', Rsx_Build_Context::child_flags());
            if ($exit_code !== 0) {
                $this->error('      Laravel cache optimization failed (exit ' . $exit_code . ')');

                return 1;
            }
            $this->line('      Routes, events and compiled views built');
        } finally {
            Cdn_Cache::$_build_phase = false;
        }

        // Step 7: the seal. LAST, and only here: it is the record that everything above
        // completed.
        $this->line('[7/7] Sealing the build...');
        $seal = Rsx_Prod_Seal::write($mode);

        $elapsed = round(microtime(true) - $start_time, 2);

        $this->newLine();
        $this->_print_summary($seal, $elapsed);

        return 0;
    }

    // -------------------------------------------------------------------------
    // Steps
    // -------------------------------------------------------------------------

    /**
     * Rebuild the manifest from scratch in this process.
     */
    private function _build_manifest(): bool
    {
        try {
            Manifest::clear();
            Manifest::init();
        } catch (Exception $e) {
            $this->error('      Failed to build manifest: ' . $e->getMessage());

            return false;
        }

        $this->line('      Build key: ' . Manifest::get_build_key());

        return true;
    }

    /**
     * Compile every module bundle the manifest knows about.
     *
     * @param bool $force Recompile regardless of what is already on disk (the production
     *                    pipeline, which just emptied the tree anyway).
     */
    private function _compile_bundles(bool $force): bool
    {
        $bundle_classes = [];

        foreach (Manifest::get_all() as $file_info) {
            $class_name = $file_info['class'] ?? null;
            if ($class_name && Manifest::php_is_subclass_of($class_name, 'Rsx_Module_Bundle_Abstract')) {
                $bundle_classes[$file_info['fqcn'] ?? $class_name] = $class_name;
            }
        }

        if (empty($bundle_classes)) {
            $this->warn('      No bundles found in manifest');

            return true;
        }

        $bundle_dir = Rsx_Project_Paths::bundles_dir();
        $compiled_count = 0;
        $failed_bundles = [];

        foreach ($bundle_classes as $fqcn => $class_name) {
            $this->line("      Compiling: {$class_name}");

            try {
                $compiler = new BundleCompiler();
                $compiled = $compiler->compile($fqcn, $force ? ['force_build' => true] : []);

                $js_size = 0;
                $css_size = 0;
                foreach (['vendor_js_bundle_path', 'app_js_bundle_path'] as $key) {
                    if (isset($compiled[$key])) {
                        $js_size += filesize("{$bundle_dir}/{$compiled[$key]}");
                    }
                }
                foreach (['vendor_css_bundle_path', 'app_css_bundle_path'] as $key) {
                    if (isset($compiled[$key])) {
                        $css_size += filesize("{$bundle_dir}/{$compiled[$key]}");
                    }
                }

                $this->line('        JS: ' . bytes_to_human($js_size) . ', CSS: ' . bytes_to_human($css_size));
                $compiled_count++;
            } catch (Exception $e) {
                $this->error("        Failed: {$e->getMessage()}");
                $failed_bundles[$class_name] = $e->getMessage();
            }
        }

        if (!empty($failed_bundles)) {
            $this->error("      {$compiled_count} compiled, " . count($failed_bundles) . ' failed');

            return false;
        }

        $this->line("      {$compiled_count} bundles compiled");

        return true;
    }

    /**
     * Download every declared external asset the build will serve from /_vendor/.
     *
     * One code path with the resolver: Cdn_Cache::mirror_externals() writes exactly the
     * files Rsx_Externals::resolve_url() names, and a failed download throws.
     */
    private function _mirror_declared_externals(): bool
    {
        try {
            $mirrored = Cdn_Cache::mirror_externals(fn ($line) => $this->line($line));
        } catch (Exception $e) {
            $this->error("      Failed: {$e->getMessage()}");

            return false;
        }

        $this->line("      {$mirrored} external assets mirrored");

        return true;
    }

    /**
     * Dump the optimized composer autoloader.
     */
    private function _dump_autoloader(): bool
    {
        if (!$this->_composer_available()) {
            $this->warn('      composer not found on PATH - skipping autoloader optimization');

            return true;
        }

        $strict_prod = Rsx::is_production() && !Rsx::is_debug();
        $flags = '--optimize --no-interaction --no-scripts' . ($strict_prod ? ' --classmap-authoritative' : '');

        // --no-scripts, and the discovery run BY HAND below. composer's
        // post-autoload-dump hook spawns a bare `php artisan package:discover`, and a
        // bare artisan is not a build context: on a box whose seal has not been written
        // yet - which is every box in the middle of a build - it is refused by the seal
        // gate, and composer reports the refusal as a failed dump. Running the same
        // discovery in THIS process keeps it inside the context that owns the build tree.
        passthru(
            'bash -c ' . escapeshellarg('cd ' . escapeshellarg(base_path()) . ' && composer dump-autoload ' . $flags),
            $exit_code
        );

        if ($exit_code !== 0) {
            $this->error('      composer dump-autoload failed (exit ' . $exit_code . ')');

            return false;
        }

        \Illuminate\Support\Facades\Artisan::call('package:discover');

        $this->line('      Composer autoloader optimized' . ($strict_prod ? ' (classmap-authoritative)' : ''));
        $this->line('      Package manifest discovered');

        return true;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The volatile roots this build writes.
     *
     * A root that does not exist yet is judged by its parent: that is where the mkdir
     * will happen.
     *
     * @return array<int, string> One line per unwritable root; empty when all good.
     */
    private function _unwritable_roots(): array
    {
        $problems = [];

        foreach ([Rsx_Project_Paths::build_root(), Rsx_Project_Paths::tmp_root()] as $root) {
            $target = is_dir($root) ? $root : dirname($root);

            if (!is_dir($target) || !is_writable($target)) {
                $problems[] = $root . (is_dir($root) ? '' : ' (its parent ' . dirname($root) . ')');
            }
        }

        return $problems;
    }

    /**
     * Is the composer binary available on PATH?
     */
    private function _composer_available(): bool
    {
        $path = trim((string) @shell_exec('bash -c ' . escapeshellarg('command -v composer 2>/dev/null')));

        return $path !== '';
    }

    /**
     * Print the post-seal summary (mode, build key, asset count, total size).
     */
    private function _print_summary(array $seal, float $elapsed): void
    {
        $build_root = Rsx_Project_Paths::build_root();
        $total_bytes = 0;
        foreach ($seal['assets'] as $asset) {
            $abs = $build_root . '/' . $asset['file'];
            if (is_file($abs)) {
                $total_bytes += filesize($abs);
            }
        }

        $this->info('[OK] Sealed ' . $seal['rsx_mode'] . " build ({$elapsed}s)");
        $this->line('     Build key:  ' . $seal['build_key']);
        $this->line('     Assets:     ' . count($seal['assets']) . ' files, ' . bytes_to_human($total_bytes));
        $this->line('     Git commit: ' . ($seal['git_commit'] ?? 'unknown'));
        $this->line('     Sealed at:  ' . $seal['created_at']);
        $this->newLine();
        $this->line('Verify at any time:    php artisan rsx:prod:verify');
        $this->line('Rebuild the assets:    php artisan rsx:build --force');
        $this->line('Return to development: php artisan rsx:prod:disable');
        $this->newLine();
        $this->line('Deployment recipes and the administration levers: php artisan rsx:man prod');
    }
}
