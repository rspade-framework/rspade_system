<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Bundle\Bundle_Component_Shaker;
use App\RSpade\Core\Bundle\BundleCompiler;
use App\RSpade\Core\Manifest\Manifest;

/**
 * rsx:bundle:shake:report - what the sealed-build component shake keeps and drops.
 *
 * A sealed build (debug, production) removes from each bundle every jqhtml component that
 * nothing the bundle serves names (Bundle_Component_Shaker). This command runs the same
 * decision for a bundle and prints it WITHOUT building anything, in any mode - so the
 * effect can be read on a development box before a build, and a component that was dropped
 * (or kept) can be traced to the reason.
 */
class Bundle_Shake_Report_Command extends Command
{
    protected $signature = 'rsx:bundle:shake:report
        {bundle? : Bundle class name (every module bundle when omitted)}
        {--kept : List every kept component with the reason it was kept}
        {--why= : Trace one component: why it is kept, back to its root, or that it is dropped}
        {--json : Print the whole decision as JSON}';

    protected $description = 'Show which components a sealed build keeps in and drops from a bundle';

    public function handle()
    {
        $bundles = $this->module_bundles();
        $wanted = $this->argument('bundle');

        if ($wanted !== null) {
            $bundles = array_filter($bundles, static fn ($class, $fqcn) => $class === $wanted || $fqcn === $wanted, ARRAY_FILTER_USE_BOTH);
            if ($bundles === []) {
                $this->error("Bundle class not found: {$wanted}");

                return 1;
            }
        }

        $json = [];

        foreach ($bundles as $fqcn => $class) {
            $resolved = (new BundleCompiler())->resolve_for_shake($fqcn);
            $plan = Bundle_Component_Shaker::plan($class, $resolved['files'], $resolved['keep']);

            if ($this->option('json')) {
                $json[$class] = [
                    'kept' => $plan['kept'],
                    'dropped' => $plan['dropped'],
                    'dropped_files' => array_map([$this, 'relative'], $plan['dropped_files']),
                ];

                continue;
            }

            if ($this->option('why') !== null) {
                $this->why($class, $plan, (string) $this->option('why'));

                continue;
            }

            $this->summary($class, $resolved['files'], $plan);
        }

        if ($this->option('json')) {
            $this->line(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return 0;
    }

    /** @return array<string,string> fqcn => simple name, sorted by name */
    protected function module_bundles(): array
    {
        $bundles = [];

        foreach (Manifest::get_all() as $record) {
            $class = $record['class'] ?? null;
            if ($class !== null && ($record['extension'] ?? '') === 'php' && Manifest::php_is_subclass_of($class, 'Rsx_Module_Bundle_Abstract')) {
                $bundles[$record['fqcn'] ?? $class] = $class;
            }
        }

        asort($bundles, SORT_STRING);

        return $bundles;
    }

    protected function summary(string $class, array $files, array $plan): void
    {
        $size = static fn (array $paths) => array_sum(array_map('filesize', $paths));

        // The bundle's own source: what the shake can touch is compared with the JS, SCSS
        // and jqhtml the bundle was given, not with vendor code.
        $source = array_values(array_filter($files, static fn ($file) => preg_match('/\.(js|scss|css|jqhtml)$/', $file)));
        $total = $size($source);
        $dropped = $size($plan['dropped_files']);
        $components = count($plan['kept']) + count($plan['dropped']);

        $this->line('');
        $this->info($class);
        $this->line("  Components: {$components} - kept " . count($plan['kept']) . ', dropped ' . count($plan['dropped']));
        $this->line(
            '  Source (js + scss + jqhtml): ' . bytes_to_human($total) . ' - the dropped files are ' . bytes_to_human($dropped)
            . ($total > 0 ? ' (' . round($dropped * 100 / $total) . '%)' : '')
        );

        if ($this->option('kept')) {
            $this->line('  Kept:');
            foreach ($plan['kept'] as $name => $reason) {
                $this->line("    {$name} - {$reason}");
            }
        }

        $this->line('  Dropped:' . ($plan['dropped'] === [] ? ' none' : ''));
        foreach ($plan['dropped'] as $name) {
            $this->line("    {$name}");
        }
    }

    /** Follow a kept component's reason back to the root that started the chain. */
    protected function why(string $class, array $plan, string $name): void
    {
        $this->line('');
        $this->info($class);

        if (in_array($name, $plan['dropped'], true)) {
            $this->line("  {$name} is DROPPED: nothing this bundle serves names it.");

            return;
        }

        if (!isset($plan['kept'][$name])) {
            $this->line("  {$name} is not a component in this bundle.");

            return;
        }

        $seen = [];
        while (isset($plan['kept'][$name]) && !isset($seen[$name])) {
            $seen[$name] = true;
            $reason = $plan['kept'][$name];
            $this->line("  {$name} - {$reason}");

            if (!preg_match('/^named by ([A-Za-z0-9_]+) /', $reason, $match)) {
                break;
            }
            $name = $match[1];
        }
    }

    protected function relative(string $file): string
    {
        return str_replace(base_path() . '/', '', $file);
    }
}
