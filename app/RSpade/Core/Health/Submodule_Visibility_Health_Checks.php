<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Rsx;

/**
 * Submodule_Visibility_Health_Checks - is the framework's submodule POINTER visible in
 * ordinary git output?
 *
 * Downstream, system/ is a git submodule, so its gitlink IS the framework version - the
 * single most consequential fact in an update commit. Two configurations hide it, and
 * both are silent by construction, which is exactly why they need a health row:
 *
 *   1. .gitmodules carrying no `ignore` for the submodule. `git submodule add` writes
 *      path/url/branch only, so a project CONVERTED from the old vendored layout inherits
 *      the default `none`, which reports the submodule modified on any working-tree change
 *      inside it. system/ is permanently dirty with build churn (.php <-> .php.upstream
 *      renames applied by the manifest build), so `none` means ` M system` forever.
 *
 *   2. A repo-wide `[diff] ignoreSubmodules` in .git/config - the natural answer to that
 *      noise. The value operators reach for is `all`, which suppresses submodule reporting
 *      ENTIRELY, the recorded pointer included: a framework update then lands leaving no
 *      trace in status, diff or the commit summary (field report, 2026-08-22). It also
 *      applies silently to every other gitlink the app keeps.
 *
 * `dirty` is the one value that shows a moved pointer and hides the churn, and it belongs
 * in the TRACKED .gitmodules so every clone inherits it. bin/publish writes it into the
 * starter and framework-pull-upstream.sh sets it during the vendored -> submodule
 * conversion; this row plus its healer serve the boxes converted before that landed.
 *
 * WHY WARN AND NEVER FAIL. Nothing is broken, corrupted or at risk - a review surface is
 * quieter than it should be. rsx:health exits non-zero only on FAIL, so this can never
 * break a deploy gate or a container healthcheck.
 *
 * THE HEALER RUNS THE ENVIRONMENT UPDATE, which owns the wiring
 * (system/bin/environment_updates/080_submodule_ignore_dirty.sh). One writer, one
 * behavior; the heal target is declared where its feature lives.
 */
class Submodule_Visibility_Health_Checks
{
    /** The submodule the framework ships as. */
    private const SUBMODULE_PATH = 'system';

    /** The only correct value: pointer visible, build churn hidden. */
    private const WANTED_IGNORE = 'dirty';

    /**
     * Report whether a framework update would be visible in this repository's git output.
     *
     * @return array
     */
    #[Health_Check('Submodule Visibility')]
    public static function submodule_visibility(): array
    {
        // The monorepo authors system/ rather than tracking it; there is no gitlink here
        // and nothing that could hide one.
        if (config('rsx.code_quality.is_framework_developer', false)) {
            return [
                'status' => 'INFO',
                'detail' => 'Framework monorepo: system/ is authored source, not a submodule.',
                'remediation' => null,
            ];
        }

        $state = static::inspect(dirname(base_path()));

        if (!$state['is_submodule_project']) {
            return [
                'status' => 'INFO',
                'detail' => 'Not a submodule project: .gitmodules declares no '
                    . self::SUBMODULE_PATH . ' submodule.',
                'remediation' => null,
            ];
        }

        $rows = [];
        $remediation = 'php artisan rsx:heal submodule-ignore-dirty';

        if ($state['gitmodules_ignore'] !== self::WANTED_IGNORE) {
            $found = $state['gitmodules_ignore'] === null
                ? 'is not set'
                : "is '{$state['gitmodules_ignore']}'";

            $rows[] = [
                'status' => 'WARN',
                'detail' => "submodule." . self::SUBMODULE_PATH . ".ignore {$found} in .gitmodules"
                    . " (wanted '" . self::WANTED_IGNORE . "'). Without it the build's own churn"
                    . ' inside ' . self::SUBMODULE_PATH . '/ reports the submodule modified on'
                    . ' every status, forever.',
                'remediation' => $remediation,
            ];
        }

        if ($state['blanket_ignore_submodules'] !== null) {
            $rows[] = [
                'status' => 'WARN',
                'detail' => "This repository's .git/config sets diff.ignoreSubmodules ="
                    . " '{$state['blanket_ignore_submodules']}'. A repo-wide value hides the"
                    . ' framework pointer - and every other gitlink - from status and diff;'
                    . ' scoping belongs per-submodule in .gitmodules.',
                'remediation' => $remediation,
            ];
        }

        if (!empty($rows)) {
            return $rows;
        }

        return [
            'status' => 'OK',
            'detail' => 'A framework update is visible in git output ('
                . self::SUBMODULE_PATH . " carries ignore = " . self::WANTED_IGNORE
                . ', with no repo-wide diff.ignoreSubmodules).',
            'remediation' => null,
        ];
    }

    /**
     * Read both configurations under one project root. Pure reading - no writes - so a
     * test can drive it against a sandbox repository.
     *
     * @param string $project_root
     * @return array{is_submodule_project: bool, gitmodules_ignore: ?string, blanket_ignore_submodules: ?string}
     */
    public static function inspect(string $project_root): array
    {
        $gitmodules = $project_root . '/.gitmodules';

        $state = [
            'is_submodule_project' => false,
            'gitmodules_ignore' => null,
            'blanket_ignore_submodules' => null,
        ];

        if (is_file($gitmodules)) {
            $url = static::__git_config(['config', '--file', $gitmodules, '--get', 'submodule.' . self::SUBMODULE_PATH . '.url']);

            if ($url !== null) {
                $state['is_submodule_project'] = true;
                $state['gitmodules_ignore'] = static::__git_config(
                    ['config', '--file', $gitmodules, '--get', 'submodule.' . self::SUBMODULE_PATH . '.ignore']
                );
            }
        }

        // The repo-wide setting is LOCAL config only: a value the developer set globally
        // is their machine's business and not something the framework unsets.
        if (file_exists($project_root . '/.git')) {
            $state['blanket_ignore_submodules'] = static::__git_config(
                ['-C', $project_root, 'config', '--local', '--get', 'diff.ignoreSubmodules']
            );
        }

        return $state;
    }

    /**
     * One `git config` read. Returns null when the key is absent (git's exit code 1).
     *
     * @param array $args argv after `git` (the subcommand included - `-C <path>` must
     *                     precede it, so the caller spells the whole thing).
     * @return string|null
     */
    private static function __git_config(array $args): ?string
    {
        $command = array_merge(['git'], $args);

        $process = new Process($command);
        // NO TIMEOUT (null). Reading a config key takes no measurable time; a wedge here
        // is a fault to SEE, not to convert into a tidy failure. See the no-timeout mandate.
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $value = trim($process->getOutput());

        return $value === '' ? null : $value;
    }

    /**
     * Re-run the environment update that owns both settings.
     *
     * @return array
     */
    #[Health_Heal('submodule-ignore-dirty')]
    public static function heal_submodule_ignore_dirty(): array
    {
        $script = base_path('bin/environment_updates/080_submodule_ignore_dirty.sh');

        if (!is_file($script)) {
            return [
                'status' => 'REFUSED',
                'detail' => "The environment update that owns this setting is missing: {$script}",
            ];
        }

        if (!Rsx::is_rspade_container()) {
            // Every environment update is container-gated: it configures the environment
            // AROUND the project. Running it here would exit 0 having done nothing, which
            // must never be reported as a repair.
            return [
                'status' => 'REFUSED',
                'detail' => 'Environment updates run only inside the RSpade container'
                    . ' (/.rspade_container is absent), so the submodule configuration cannot'
                    . ' be written from here. Run this inside the container.',
            ];
        }

        $process = new Process(['bash', $script], dirname(base_path()));
        // NO TIMEOUT (null) - see __git_config().
        $process->setTimeout(null);
        $process->run();

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());

        if (!$process->isSuccessful()) {
            return [
                'status' => 'REFUSED',
                'detail' => '080_submodule_ignore_dirty.sh reported a problem: '
                    . ($stderr !== '' ? $stderr : $stdout),
            ];
        }

        if ($stdout === '' && $stderr === '') {
            return [
                'status' => 'ALREADY_OK',
                'detail' => 'The system/ submodule already carries ignore = ' . self::WANTED_IGNORE
                    . ' and no repo-wide diff.ignoreSubmodules is set.',
            ];
        }

        $detail = $stdout;

        if ($stderr !== '') {
            $detail = ($detail === '' ? '' : $detail . "\n") . $stderr;
        }

        return ['status' => 'HEALED', 'detail' => $detail];
    }
}
