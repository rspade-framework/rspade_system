<?php

namespace App\RSpade\Commands\Restricted;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

use App\RSpade\Core\Prod\Rsx_Build_Context;
use Illuminate\Console\Command;

class Optimize_Cache_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'optimize:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cache all cacheable Laravel components (config, routes, views, events)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // THE BUILD OWNS THESE CACHES. They are build outputs like any other - they land
        // in build/ and a served request only reads them - so the one context permitted to
        // write them is the build (rsx:build, which forwards its context to this child).
        // Running it by hand in development would freeze routes and Blade against a tree
        // that is still changing, which is the confusion the whole mode exists to avoid.
        if (!Rsx_Build_Context::is_active()) {
            throw new \RuntimeException(
                "optimize:cache is part of the build, not a command to run by hand.\n\n" .
                "It writes the route, event and compiled-view caches into build/, and only a\n" .
                "build may write there.\n\n" .
                "  Build everything: php artisan rsx:build --force\n" .
                '  See:              php artisan rsx:man prod'
            );
        }

        $this->info('Optimizing application caches for production...');
        $this->newLine();

        // NOTE: we deliberately do NOT call cache:clear here. RSX overrides
        // cache:clear to also run rsx:clean, which would wipe the freshly-built
        // build assets mid-pipeline. Each *:cache command below clears its own
        // compiled artifact (config:clear, route:clear, ...) before rewriting it,
        // so an explicit up-front clear is both redundant and destructive.

        // NOTE: 'config' is intentionally omitted. Laravel SKIPS loading .env entirely
        // when a config cache exists, so every runtime env() call falls back to its
        // default. RSX reads env() pervasively at runtime (RSX_MODE, APP_URL, the email
        // dev flags, ...), so caching config silently breaks the app - most visibly
        // Rsx::get_mode(), which would read 'development' on a production box. RSX is
        // env-at-runtime by design; config caching is a broader incompatibility to
        // address separately, not here.
        //
        // VIEWS ARE CACHED. RSX's Blade layer is one precompiler plus a handful of
        // directives, and a jqhtml tag is opaque text to Blade, so every template
        // compiles ahead of time exactly as Laravel intends. Precompiling is what lets a
        // production request read build/views without writing to it - a template the
        // build did not precompile fails loud on a read-only box rather than silently
        // compiling one. The set and the path spelling are RSX's own (_compile_views()),
        // not Laravel's view:cache walk.
        //
        // Route, event and view caching do NOT skip Dotenv loading, so all three are safe.
        $steps = [
            'routes' => 'Routes',
            'events' => 'Events',
            'views' => 'Views',
        ];

        $failed = [];
        
        // For each cache type, we need to remove the override temporarily
        // and call the original Laravel command
        foreach ($steps as $cache_type => $label) {
            $this->info("Caching {$label}...");
            
            try {
                $exit_code = $this->cache_component($cache_type);
                
                if ($exit_code === 0) {
                    $this->line("  [OK] {$label} cached successfully");
                } else {
                    $failed[] = $label;
                    $this->error("  [ERROR] Failed to cache {$label}");
                }
            } catch (\Exception $e) {
                $failed[] = $label;
                $this->error("  [ERROR] Error caching {$label}: " . $e->getMessage());
            }

            $this->newLine();
        }

        if (empty($failed)) {
            $this->info('[OK] All caches optimized successfully');
            return 0;
        } else {
            $this->error('[WARNING] Some caches failed: ' . implode(', ', $failed));
            return 1;
        }
    }
    
    /**
     * Cache a specific component
     *
     * @param string $type
     * @return int
     */
    protected function cache_component($type)
    {
        // RSX overrides config:cache / route:cache / view:cache / event:cache with
        // restricted stubs, so calling them BY NAME (Artisan registry) would just
        // hit the stub. We instead construct and run the ORIGINAL Laravel command
        // object directly, which bypasses the name registry entirely. Their own
        // internal *:clear calls are not overridden, so they run cleanly.
        switch ($type) {
            case 'routes':
                $command = new \Illuminate\Foundation\Console\RouteCacheCommand(app('files'));
                break;

            case 'events':
                $command = new \Illuminate\Foundation\Console\EventCacheCommand();
                break;

            case 'views':
                return $this->_compile_views();

            default:
                throw new \Exception("Unknown cache type: {$type}");
        }

        // Both the container AND the console Application must be set: these
        // original commands call their own *:clear counterparts via the Artisan
        // Application internally (config:clear etc. - none of which are overridden).
        $command->setLaravel(app());
        $command->setApplication($this->getApplication());

        return $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), $this->output);
    }

    /**
     * Precompile every Blade template a served request can render, located the way the
     * request will locate it.
     *
     * Laravel's view:cache walks every view-finder path and namespace hint with a Finder
     * and compiles whatever it finds. Two things are wrong with that here. The rspade::
     * hint is the whole framework tree, reference application included - templates the
     * manifest deliberately does not index, extending layouts this application does not
     * declare, so compiling one throws "View not found in manifest". And the walk keys each
     * compiled file on the Finder's REAL path, while a served request resolves rsx:: through
     * the system/rsx symlink: a different spelling of the same file is a different compiled
     * filename, and the first request compiles it again into a tree that is read-only.
     *
     * So the set is exactly what a request can render - the manifest's blade_views (what
     * rsx_view() and the @rsx_* directives resolve) plus the templates under
     * config('view.paths') (framework-owned views rendered by name, such as the error
     * screen and the unsubscribe page) - and every one of them is compiled at the path
     * the view finder answers for its name, which is the path the request will ask for.
     */
    private function _compile_views(): int
    {
        $view = app('view');
        $finder = $view->getFinder();
        $compiler = $view->getEngineResolver()->resolve('blade')->getCompiler();

        $names = self::view_names();

        foreach ($names as $name) {
            $compiler->compile($finder->find($name));
        }

        $this->line('  Compiled ' . count($names) . ' templates');

        return 0;
    }

    /**
     * The view names the build precompiles: every manifest Blade view, spelled as
     * rsx_view() spells it, plus every template under config('view.paths').
     *
     * @return string[] Sorted, unique Laravel view names
     */
    public static function view_names(): array
    {
        Manifest::init();

        $names = [];

        foreach (Manifest::$data['data']['blade_views'] ?? [] as $path) {
            $names[] = self::_view_name_for($path);
        }

        foreach (config('view.paths') as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = \Symfony\Component\Finder\Finder::create()->files()->in($dir)->name('*.blade.php');
            foreach ($files as $file) {
                $relative = substr($file->getRelativePathname(), 0, -strlen('.blade.php'));
                $names[] = str_replace('/', '.', $relative);
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * The Laravel view name a manifest Blade path is rendered under (rsx_view()'s spelling).
     */
    private static function _view_name_for(string $path): string
    {
        $name = preg_replace('/\.blade\.php$/', '', $path);

        if (str_starts_with($name, 'resources/views/')) {
            $name = substr($name, strlen('resources/views/'));
        } elseif (str_starts_with($name, 'app/RSpade/')) {
            $name = 'rspade::' . substr($name, strlen('app/RSpade/'));
        } elseif (str_starts_with($name, 'rsx/')) {
            $name = 'rsx::' . substr($name, strlen('rsx/'));
        }

        return str_replace('/', '.', $name);
    }
}