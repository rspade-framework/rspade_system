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
 * Claude_Skills_Health_Checks - the probe and healer for APPLICATION-authored Claude
 * Code skills.
 *
 * An app authors skills at rsx/resource/skills/<name>/SKILL.md, and the framework wires
 * each one in as a relative symlink:
 *
 *     .claude/skills/<name> -> ../../rsx/resource/skills/<name>
 *
 * The wiring itself lives in system/bin/environment_updates/070_app_skills.sh, which
 * every environment-update trigger runs. This class answers the two operator questions
 * that script cannot: "is my environment actually wired?" (the health row) and "wire it
 * now" (the heal target), for a box whose last build/pull predates the skill.
 *
 * WHY IT IS A SEPARATE CLASS. Environment_Health_Checks holds the runtime posture probes
 * (PHP, node, storage writability, mode). This is a different subject - an agent-tooling
 * wiring with a healer of its own - and a heal target is declared WHERE ITS FEATURE
 * LIVES, so the feature gets a file.
 *
 * WHY WARN AND NEVER FAIL. An unwired skill costs the site nothing; it costs the
 * developer a skill their agent does not load. rsx:health exits non-zero only on FAIL, so
 * this can never break a deploy gate or a container healthcheck.
 *
 * THE HEALER CREATES AND PRUNES, NOTHING ELSE. A real directory or a foreign symlink
 * sitting on a skill's name is REPORTED, never overwritten - the Heal_Runner boundary.
 * That refusal is the script's own behavior; the healer just runs it.
 */
class Claude_Skills_Health_Checks
{
    /** Where an application authors its skills, relative to the project root. */
    private const SKILLS_SOURCE = 'rsx/resource/skills';

    /** Where Claude Code discovers them, relative to the project root. */
    private const SKILLS_LINK_DIR = '.claude/skills';

    /** The literal target prefix this framework writes (relative, never absolute). */
    private const LINK_TARGET_PREFIX = '../../rsx/resource/skills/';

    /**
     * Link targets that identify a link as OURS for pruning purposes. Matches the
     * prune rule in 070_app_skills.sh exactly.
     */
    private const OURS_PREFIXES = ['../../rsx/resource/skills/', '../../system/app/RSpade/'];

    /** The framework plugin's name (owned by 060_claude_docs.sh); no app skill may take it. */
    private const RESERVED_NAME = 'rspade';

    /**
     * Report the wiring state of every application skill.
     *
     * @return array
     */
    #[Health_Check('Claude Skills')]
    public static function claude_skills(): array
    {
        $state = static::inspect(dirname(base_path()));

        $rows = [];
        $remediation = 'php artisan rsx:heal claude-skills';

        foreach ($state['unlinked'] as $name) {
            $rows[] = [
                'status' => 'WARN',
                'detail' => "Application skill '{$name}' is not linked into "
                    . self::SKILLS_LINK_DIR . ", so Claude Code does not load it.",
                'remediation' => $remediation,
            ];
        }

        foreach ($state['dangling'] as $name) {
            $rows[] = [
                'status' => 'WARN',
                'detail' => self::SKILLS_LINK_DIR . "/{$name} is a framework-created symlink"
                    . ' that no longer resolves (its skill was renamed or deleted).',
                'remediation' => $remediation,
            ];
        }

        foreach ($state['blocked'] as $name) {
            $rows[] = [
                'status' => 'WARN',
                'detail' => "Application skill '{$name}' cannot be linked: "
                    . self::SKILLS_LINK_DIR . "/{$name} already exists and is not"
                    . ' the framework symlink. It is left untouched.',
                'remediation' => 'move ' . self::SKILLS_LINK_DIR . "/{$name} aside, then run "
                    . $remediation,
            ];
        }

        foreach ($state['reserved'] as $name) {
            $rows[] = [
                'status' => 'WARN',
                'detail' => "Application skill '{$name}' uses the reserved framework plugin"
                    . ' name and is never linked. Framework skills are the '
                    . self::RESERVED_NAME . ':* plugin.',
                'remediation' => 'rename ' . self::SKILLS_SOURCE . "/{$name}",
            ];
        }

        if (!empty($rows)) {
            return $rows;
        }

        $count = count($state['linked']);

        if ($count === 0) {
            return [
                'status' => 'OK',
                'detail' => 'No application skills declared (' . self::SKILLS_SOURCE . ' is empty or absent).',
                'remediation' => null,
            ];
        }

        return [
            'status' => 'OK',
            'detail' => $count . ' application skill(s) linked into ' . self::SKILLS_LINK_DIR . '.',
            'remediation' => null,
        ];
    }

    /**
     * Classify every declared skill and every framework-created link under one project
     * root. Pure filesystem inspection - no writes - so a test can drive it against a
     * sandbox tree.
     *
     * @param string $project_root
     * @return array{linked: array, unlinked: array, blocked: array, dangling: array, reserved: array}
     */
    public static function inspect(string $project_root): array
    {
        $source_dir = $project_root . '/' . self::SKILLS_SOURCE;
        $link_dir = $project_root . '/' . self::SKILLS_LINK_DIR;

        $state = ['linked' => [], 'unlinked' => [], 'blocked' => [], 'dangling' => [], 'reserved' => []];

        // 1. Every authored skill: a directory carrying a SKILL.md. Anything else in
        //    there is not a skill and is not this check's business.
        if (is_dir($source_dir)) {
            foreach (static::__directory_entries($source_dir) as $name) {
                if (!is_file($source_dir . '/' . $name . '/SKILL.md')) {
                    continue;
                }

                if ($name === self::RESERVED_NAME) {
                    $state['reserved'][] = $name;
                    continue;
                }

                $link = $link_dir . '/' . $name;

                if (is_link($link)) {
                    if (readlink($link) === self::LINK_TARGET_PREFIX . $name) {
                        $state['linked'][] = $name;
                    } else {
                        $state['blocked'][] = $name;
                    }

                    continue;
                }

                // file_exists() follows symlinks; is_link() above already handled those,
                // so anything left that exists is a real file or directory.
                if (file_exists($link)) {
                    $state['blocked'][] = $name;
                    continue;
                }

                $state['unlinked'][] = $name;
            }
        }

        // 2. Links WE made that no longer resolve. Decided on the LITERAL target, never
        //    a resolved one - a resolved path says nothing about who wrote the link.
        if (is_dir($link_dir)) {
            foreach (static::__directory_entries($link_dir) as $name) {
                $link = $link_dir . '/' . $name;

                if (!is_link($link) || file_exists($link)) {
                    continue;
                }

                $target = (string) readlink($link);

                foreach (self::OURS_PREFIXES as $prefix) {
                    if (str_starts_with($target, $prefix)) {
                        $state['dangling'][] = $name;
                        break;
                    }
                }
            }
        }

        sort($state['linked']);
        sort($state['unlinked']);
        sort($state['blocked']);
        sort($state['dangling']);
        sort($state['reserved']);

        return $state;
    }

    /**
     * Re-run the environment update that owns the links.
     *
     * Create-and-prune only: the script refuses to overwrite anything present and
     * unexpected, which is exactly the Heal_Runner boundary. Spawning it is a plain
     * bash subprocess - it is not an artisan command, so the Rsx_Artisan mandate does
     * not apply, but the shell is still named EXPLICITLY (never sh, never an implicit
     * /bin/sh, never the exec bit).
     *
     * @return array
     */
    #[Health_Heal('claude-skills')]
    public static function heal_claude_skills(): array
    {
        $script = base_path('bin/environment_updates/070_app_skills.sh');

        if (!is_file($script)) {
            return [
                'status' => 'REFUSED',
                'detail' => "The environment update that owns these links is missing: {$script}",
            ];
        }

        if (!Rsx::is_rspade_container()) {
            // Every environment update is container-gated: it configures the environment
            // AROUND the project, and that environment is the container's. Running it
            // here would exit 0 having done nothing, which must never be reported as a
            // repair.
            return [
                'status' => 'REFUSED',
                'detail' => 'Environment updates run only inside the RSpade container'
                    . ' (/.rspade_container is absent), so the skill links cannot be'
                    . ' written from here. Run this inside the container.',
            ];
        }

        $process = new Process(['bash', $script], dirname(base_path()));
        // NO TIMEOUT (null). A few symlinks take no measurable time; a wedge here is a
        // fault to SEE, not to convert into a tidy failure. See the no-timeout mandate.
        $process->setTimeout(null);
        $process->run();

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());

        if (!$process->isSuccessful()) {
            return [
                'status' => 'REFUSED',
                'detail' => '070_app_skills.sh reported a problem: '
                    . ($stderr !== '' ? $stderr : $stdout),
            ];
        }

        if ($stdout === '' && $stderr === '') {
            return [
                'status' => 'ALREADY_OK',
                'detail' => 'Every application skill is already linked into '
                    . self::SKILLS_LINK_DIR . '.',
            ];
        }

        $detail = $stdout;

        if ($stderr !== '') {
            // A non-fatal report: something present-but-unexpected was left alone.
            $detail = ($detail === '' ? '' : $detail . "\n") . $stderr;
        }

        return ['status' => 'HEALED', 'detail' => $detail];
    }

    /**
     * The entries of a directory, dot entries excluded, sorted.
     *
     * @param string $dir
     * @return array
     */
    private static function __directory_entries(string $dir): array
    {
        $entries = scandir($dir);

        if ($entries === false) {
            return [];
        }

        $entries = array_values(array_diff($entries, ['.', '..']));
        sort($entries);

        return $entries;
    }
}
