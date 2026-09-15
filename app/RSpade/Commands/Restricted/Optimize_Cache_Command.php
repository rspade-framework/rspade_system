<?php

namespace App\RSpade\Commands\Restricted;

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
        // compiling one.
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
                $command = new \Illuminate\Foundation\Console\ViewCacheCommand();
                break;

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
}