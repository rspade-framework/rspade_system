<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Commands\Rsx;


use App\RSpade\Core\Bundle\Cdn_Cache;
use App\RSpade\Core\Console\Rsx_Artisan;
use App\RSpade\Core\Prod\Rsx_Prod_Seal;
use Illuminate\Console\Command;

/**
 * rsx:cdn_externals:refresh - THE ONE EXPIRY for the CDN externals mirror store.
 *
 * The store (rsx/resource/.cdn-cache/) is URL-keyed and content-addressed by md5, so a
 * present file is by definition the right file and nothing ever expires on its own: the
 * compile only ever ADDS, and no code path deletes. That is deliberate - a mirror that
 * quietly re-downloaded would make a build depend on a CDN being reachable and identical.
 *
 * So there is exactly one way to move the store forward, and this is it: EMPTY IT AND
 * RE-RUN EVERY PRODUCER. Run it when a declared URL's bytes changed upstream, when
 * rsx.cdn_externals.user_agent changed, or when the store has accumulated orphans no declaration
 * names any more.
 *
 * WHY THERE IS NO PARTIAL MODE. A stylesheet's mirrored entry is not one file: the CSS
 * localizer splices its remote @imports and mirrors every url() it references into further
 * entries of this same store. Nothing records which entry pulled in which font, so a
 * --url refresh could not follow those nested files and would leave the store internally
 * inconsistent. One way to do this: empty, then rebuild.
 *
 * WHY IT REFUSES WHILE SEALED. The mirror is a SOURCE artifact - git-tracked, refreshed
 * on a development box, reviewed and committed. A sealed host serves out of the copy it
 * shipped with; the command that rebuilds a sealed host's assets is rsx:build --force.
 *
 * See: php artisan rsx:man external_resources
 */
class Cdn_Externals_Refresh_Command extends Command
{
    protected $signature = 'rsx:cdn_externals:refresh';

    protected $description = 'Empty the CDN externals mirror store and re-download every declared external asset';

    /**
     * Testing seam: record the artisan subprocesses instead of running them.
     *
     * A unit test drives this command in-process to assert the store's before/after
     * state; spawning a real rsx:clean and a real full compile from inside the suite
     * would wipe the developer's build tree and take minutes. When this is an array the
     * command appends each command name to it and runs nothing - the accounting is
     * untouched, so the steps still print and the store is still reported.
     *
     * @var array<int, string>|null
     */
    public static ?array $_testing_spawned = null;

    public function handle(): int
    {
        if (Rsx_Prod_Seal::is_sealed()) {
            $this->error('[ERROR] The CDN externals mirror is a source artifact, not a build output.');
            $this->line('  It is refreshed on a development box and committed, so a sealed host serves');
            $this->line('  the copy it shipped with and never re-downloads.');
            $this->line('  To rebuild a sealed host\'s assets: php artisan rsx:build --force');

            return 1;
        }

        $this->info('Refreshing the CDN externals mirror store...');
        $this->newLine();

        $this->line('[1/4] Emptying the CDN externals store');
        $removed = Cdn_Cache::clear();
        $this->line("      {$removed} files removed");

        // The compiled bundles NAME /_vendor/ files, and the SCSS second-level cache under
        // the tmp tree holds already-localized stylesheets. Both must go or the localize
        // pass never re-runs and the store comes back short. rsx:clean owns that wipe.
        $this->line('[2/4] Clearing compiled bundle caches');
        $clean_exit = $this->_spawn('rsx:clean', ['--silent', Clean_Command::FLAG_NO_SYSTEM_RESET]);
        if ($clean_exit !== 0) {
            $this->error("[ERROR] rsx:clean failed (exit {$clean_exit}). The store was not refreshed.");

            return 1;
        }

        $this->line('[3/4] Mirroring declared externals');
        $mirrored = Cdn_Cache::mirror_externals(fn ($line) => $this->line($line));
        $this->line("      {$mirrored} declared urls mirrored");

        $this->line('[4/4] Rebuilding every bundle');
        $this->newLine();

        $exit_code = $this->_compile_bundles();

        if ($exit_code !== 0) {
            $this->newLine();
            $this->error("[ERROR] Bundle compilation failed (exit {$exit_code}). The store is incomplete.");

            return 1;
        }

        $this->newLine();
        $this->info('[OK] CDN externals store refreshed: ' . $this->_store_file_count() . ' files');

        return 0;
    }

    /**
     * Recompile every bundle, repopulating every CDN asset and every localized reference.
     *
     * A fresh process is mandatory (the manifest and every cache must be re-read), and
     * an artisan subprocess is spawned only through Rsx_Artisan - ARTISAN-SPAWN-01.
     */
    protected function _compile_bundles(): int
    {
        return $this->_spawn('rsx:build', []);
    }

    /**
     * Run one artisan subprocess, or record it when the testing seam is armed.
     *
     * @param array<int, string> $args
     */
    protected function _spawn(string $command, array $args): int
    {
        if (self::$_testing_spawned !== null) {
            self::$_testing_spawned[] = $command;

            return 0;
        }

        return Rsx_Artisan::passthru($command, $args);
    }

    /**
     * How many files the store holds right now.
     */
    protected function _store_file_count(): int
    {
        $files = glob(Cdn_Cache::get_cache_directory() . '/*');

        return count(array_filter($files ?: [], 'is_file'));
    }
}
