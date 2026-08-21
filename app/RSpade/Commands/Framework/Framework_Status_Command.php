<?php

namespace App\RSpade\Commands\Framework;

use Illuminate\Console\Command;

/**
 * rsx:framework:status - what framework revision is this project running, and is
 * there a newer one?
 *
 * system/ is a git submodule, so the whole answer is two revisions and whether they
 * agree:
 *
 *   RECORDED  the gitlink this project commits - what it CLAIMS to run
 *   CHECKED OUT  the submodule's own HEAD - what it ACTUALLY runs
 *
 * They disagree after a plain `git pull` moves the gitlink without updating the
 * submodule, which is the failure this command exists to make visible. The pre-boot
 * guard (bootstrap/rsx_submodule_sync.php) refuses to start in that state, so anyone
 * reading this output is either checking before it bites or has just been stopped by
 * it.
 *
 * Everything here is read-only and offline EXCEPT the update check, which is opt-in
 * (--fetch) because a status command should not require a network.
 */
class Framework_Status_Command extends Command
{
    protected $signature = 'rsx:framework:status {--fetch : Contact the remote to report whether an update is available}';

    protected $description = 'Report the framework revision this project runs, and whether it is current';

    public function handle(): int
    {
        $system_dir   = base_path();
        $project_root = dirname($system_dir);

        $this->line('');
        $this->info('=== RSpade Framework Status ===');
        $this->line('');

        if (!file_exists($system_dir . '/.git')) {
            $this->line('<fg=yellow>system/ is not a git submodule.</>');
            $this->line('');
            $this->line('  This is either the framework monorepo (where system/ IS the authored');
            $this->line('  source) or a project that predates the submodule model. In the latter');
            $this->line('  case, converting is what the next framework update does:');
            $this->line('');
            $this->line('      php artisan rsx:framework:pull');
            $this->line('');

            return 0;
        }

        $recorded = $this->__recorded_revision($project_root);
        $actual   = $this->__git($system_dir, ['rev-parse', 'HEAD']);
        $url      = $this->__git($system_dir, ['remote', 'get-url', 'origin']);
        $branch   = $this->__gitmodules_value($project_root, 'branch') ?: 'master';

        $this->line('<fg=green>Running:</>  ' . ($actual !== null ? substr($actual, 0, 12) : '(unknown)'));
        if ($actual !== null) {
            $subject = $this->__git($system_dir, ['log', '-1', '--format=%s', $actual]);
            $date    = $this->__git($system_dir, ['log', '-1', '--format=%ad', '--date=short', $actual]);
            if ($subject !== null) {
                $this->line('           ' . $date . '  ' . $subject);
            }
        }

        $this->line('<fg=green>Recorded:</> ' . ($recorded !== null ? substr($recorded, 0, 12) : '(unknown)'));
        $this->line('<fg=green>Tracking:</> ' . ($url ?: '(no origin remote)') . '  (' . $branch . ')');
        $this->line('');

        // THE ONE ANSWER THAT MATTERS.
        if ($recorded !== null && $actual !== null && $recorded !== $actual) {
            $this->error('  system/ is OUT OF STEP with what this project records.');
            $this->line('');
            $this->line('  The framework running is not the one this project claims to use - a plain');
            $this->line('  `git pull` moves the recorded revision without checking the submodule out.');
            $this->line('');
            $this->line('      git submodule update --init --recursive');
            $this->line('');

            return 1;
        }

        // A dirty submodule is churn, not drift: the manifest build renames
        // .php <-> .php.upstream to apply class overrides. Worth mentioning, never a
        // problem - the next update discards it along with everything else.
        $dirty = $this->__git($system_dir, ['status', '--porcelain']);
        if ($dirty !== null && $dirty !== '') {
            $count = count(array_filter(explode("\n", $dirty)));
            $this->line('  <fg=yellow>' . $count . ' local change(s) under system/</> - build churn, discarded by the next');
            $this->line('  update. All of system/ is framework property.');
            $this->line('');
        }

        if (!$this->option('fetch')) {
            $this->line('  <fg=gray>Add --fetch to check whether a newer release is available.</>');
            $this->line('');

            return 0;
        }

        if ($url === null || $url === '') {
            $this->warn('  No origin remote on the submodule; cannot check for updates.');

            return 0;
        }

        $this->line('  Checking ' . $url . ' (' . $branch . ')...');
        $this->__git($system_dir, ['fetch', '--quiet', $url, $branch]);
        $tip = $this->__git($system_dir, ['rev-parse', 'FETCH_HEAD']);

        if ($tip === null) {
            $this->warn('  Could not reach the remote.');

            return 0;
        }

        if ($tip === $actual) {
            $this->line('  <fg=green>Up to date.</>');
            $this->line('');

            return 0;
        }

        $behind = $this->__git($system_dir, ['rev-list', '--count', $actual . '..' . $tip]);
        $this->line('');
        $this->line('  <fg=yellow>Update available:</> ' . substr($tip, 0, 12)
            . ($behind !== null && $behind !== '' ? '  (' . $behind . ' release(s) ahead)' : ''));
        $this->line('');
        $this->line('      php artisan rsx:framework:pull');
        $this->line('');

        return 0;
    }

    /**
     * The gitlink this project records for system/, read from the parent index - the
     * same value `git status` compares against.
     */
    private function __recorded_revision(string $project_root): ?string
    {
        $out = $this->__git($project_root, ['ls-files', '-s', '--', 'system']);
        if ($out === null || $out === '') {
            return null;
        }

        foreach (explode("\n", $out) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (($parts[0] ?? '') === '160000' && isset($parts[1])) {
                return $parts[1];
            }
        }

        return null;
    }

    private function __gitmodules_value(string $project_root, string $key): ?string
    {
        $file = $project_root . '/.gitmodules';
        if (!is_file($file)) {
            return null;
        }

        $out = $this->__git($project_root, ['config', '--file', $file, '--get', 'submodule.system.' . $key]);

        return ($out === null || $out === '') ? null : $out;
    }

    /**
     * Run git in $dir and return trimmed stdout, or null when it failed.
     *
     * The inherited git context is stripped: this command may be reached from inside a
     * hook, where git exports GIT_DIR / GIT_INDEX_FILE and would re-target every call
     * at an in-progress commit's index.
     */
    private function __git(string $dir, array $args): ?string
    {
        $command = 'env -u GIT_DIR -u GIT_WORK_TREE -u GIT_INDEX_FILE git -C '
            . escapeshellarg($dir);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $command .= ' 2>/dev/null';

        $output = [];
        $rc = 0;
        exec_safe($command, $output, $rc);

        if ($rc !== 0) {
            return null;
        }

        return trim(implode("\n", $output));
    }
}
