<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Core\Dependencies\Dependency_Manager;
use Illuminate\Console\Command;

class Framework_Post_Update_Dependency_Check_Command extends Command
{
    protected $signature = 'rsx:framework:post_update_dependency_check';

    protected $description = '[internal] Reconcile the app dependency layer against the framework after a pull';

    protected $hidden = true;

    public function handle()
    {
        // Purely local, zero network - a framework pull must stay offline-capable.
        //
        // THE TWO HALVES ARE INDEPENDENTLY OPTIONAL. The app dependency layer is two
        // manifests, root composer.json and root package.json, and an app may legitimately
        // carry either, both, or neither - a framework-only install has no layer at all,
        // and an app that has never installed an npm package has no package.json even
        // though it has a composer.json. Each half is therefore guarded on ITS OWN file.
        //
        // This used to be one guard on composer.json covering both halves, so an app with
        // a composer.json and no package.json fell through it into
        // Dependency_Manager::read_root_package_json(), whose "this file ships with the
        // project; its absence is a broken install" assertion is true of a fresh
        // provision and false of a real app that simply never adopted app-layer npm.
        // A framework update must never fail on a configuration the framework supports.
        // Reported downstream 2026-08-22.
        if (file_exists(Dependency_Manager::root_composer_json_path())) {
            // Refresh the app-layer replace map against the (possibly updated) framework
            // installed set. A change here is routine after a framework update that moved
            // framework deps - report it as information, not a warning.
            if (Dependency_Manager::regenerate_replace_map()) {
                $this->line('Framework dependency baseline refreshed (app-layer composer replace map regenerated).');
            }

            // Notices for recorded framework-provided packages that drifted. These are
            // findings, not failures - never a non-zero exit.
            foreach (Dependency_Manager::check_recorded_composer() as $problem) {
                $this->_report_problem('composer', $problem);
            }
        }

        if (file_exists(Dependency_Manager::root_package_json_path())) {
            foreach (Dependency_Manager::check_recorded_npm() as $problem) {
                $this->_report_problem('npm', $problem);
            }
        }

        // A MISSING MANIFEST IS SILENT HERE - not an error, not a warning. A framework
        // update reports on the update; whether this app ought to have an app dependency
        // layer is a question for `php artisan rsx:health`, which raises it as a WARN row
        // carrying `rsx:heal app-npm-layer` as its remedy (Dependency_Health_Checks).

        // All-clean with no map change: completely silent (Unix silent-success).
        return 0;
    }

    /**
     * Print one actionable notice for a drifted recorded provided package.
     * $platform is 'composer' or 'npm' (selects the adopt command in the remedy).
     */
    private function _report_problem(string $platform, array $problem): void
    {
        $package = $problem['package'];
        $adopt = $platform === 'npm' ? 'rsx:npm install' : 'rsx:composer require';

        $this->line('');

        if ($problem['problem'] === 'removed') {
            $this->line("The framework no longer provides '{$package}' (you recorded it at {$problem['recorded']}). Run: php artisan {$adopt} {$package}  to adopt it into your application layer.");
            return;
        }

        // major_change
        $this->line("Framework-provided '{$package}' moved {$problem['recorded']} -> {$problem['installed']} (major version change). Review your usage; see pending upstream_changes documents. Re-record with: php artisan {$adopt} {$package}");
    }
}
