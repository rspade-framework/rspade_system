<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use App\RSpade\Core\Health\Claude_Skills_Health_Checks;
use App\RSpade\Core\Health\Heal_Runner;
use App\RSpade\Core\Health\Health_Check_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The "Claude Skills" health row and the `claude-skills` heal target.
 *
 * inspect() is driven against a sandbox project tree - the classification is pure
 * filesystem reading, so every case (linked, unlinked, blocked, dangling, reserved) is
 * reachable without touching this box's own .claude/skills. The heal target is exercised
 * for DISCOVERY and for a real invocation, which on a tree with no application skills is
 * a silent no-op.
 *
 * The wiring script itself is covered by tests/environment_updates/cli.
 */
class Claude_Skills_Health_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** The sandbox project root for the current test. */
    private static $root = null;

    /**
     * Build a sandbox project root. Torn down by __teardown_root().
     *
     * @return string
     */
    private static function __make_root(): string
    {
        $root = sys_get_temp_dir() . '/rsx-claude-skills-' . random_hash(12);
        ensure_directory($root . '/rsx/resource/skills');
        ensure_directory($root . '/.claude/skills');
        self::$root = $root;

        return $root;
    }

    private static function __teardown_root(): void
    {
        if (self::$root !== null && is_dir(self::$root)) {
            exec_safe('rm -rf ' . escapeshellarg(self::$root));
        }

        self::$root = null;
    }

    /**
     * Author a skill in the sandbox.
     */
    private static function __author_skill(string $root, string $name): void
    {
        ensure_directory($root . '/rsx/resource/skills/' . $name);
        file_put_contents(
            $root . '/rsx/resource/skills/' . $name . '/SKILL.md',
            "---\nname: {$name}\ndescription: fixture skill.\n---\n"
        );
    }

    public static function test_inspect_classifies_every_state()
    {
        $root = self::__make_root();

        try {
            // Linked correctly.
            self::__author_skill($root, 'linked-skill');
            symlink('../../rsx/resource/skills/linked-skill', $root . '/.claude/skills/linked-skill');

            // Authored but never linked.
            self::__author_skill($root, 'unlinked-skill');

            // A real directory occupying the name.
            self::__author_skill($root, 'blocked-skill');
            ensure_directory($root . '/.claude/skills/blocked-skill');

            // The reserved framework plugin name.
            self::__author_skill($root, 'rspade');

            // A directory with no SKILL.md is not a skill at all.
            ensure_directory($root . '/rsx/resource/skills/not-a-skill');

            // A dangling link we made, and a dangling link we did not.
            symlink('../../rsx/resource/skills/deleted', $root . '/.claude/skills/deleted');
            symlink('../../somewhere/else', $root . '/.claude/skills/foreign');

            $state = Claude_Skills_Health_Checks::inspect($root);

            static::__assert_equals(['linked-skill'], $state['linked']);
            static::__assert_equals(['unlinked-skill'], $state['unlinked']);
            static::__assert_equals(['blocked-skill'], $state['blocked']);
            static::__assert_equals(['rspade'], $state['reserved']);
            static::__assert_equals(['deleted'], $state['dangling']);
        } finally {
            self::__teardown_root();
        }
    }

    public static function test_inspect_reports_a_foreign_symlink_as_blocked()
    {
        $root = self::__make_root();

        try {
            self::__author_skill($root, 'taken');
            ensure_directory($root . '/elsewhere/taken');
            symlink('../../elsewhere/taken', $root . '/.claude/skills/taken');

            $state = Claude_Skills_Health_Checks::inspect($root);

            static::__assert_equals(['taken'], $state['blocked']);
            static::__assert_empty($state['linked']);
            static::__assert_empty($state['unlinked']);
        } finally {
            self::__teardown_root();
        }
    }

    public static function test_inspect_is_clean_on_a_project_with_no_skills()
    {
        $root = self::__make_root();

        try {
            $state = Claude_Skills_Health_Checks::inspect($root);

            static::__assert_empty($state['linked']);
            static::__assert_empty($state['unlinked']);
            static::__assert_empty($state['blocked']);
            static::__assert_empty($state['dangling']);
            static::__assert_empty($state['reserved']);
        } finally {
            self::__teardown_root();
        }
    }

    public static function test_health_row_is_declared_and_never_fails()
    {
        $rows = Health_Check_Runner::run_one(
            Claude_Skills_Health_Checks::class,
            'claude_skills',
            'Claude Skills'
        );

        static::__assert_greater_than(0, count($rows));

        foreach ($rows as $row) {
            static::__assert_equals('Claude Skills', $row['label']);
            static::__assert_true(
                in_array($row['status'], ['OK', 'WARN'], true),
                'the Claude Skills row is advisory only, never FAIL: ' . $row['status'] . ' - ' . $row['detail']
            );

            if ($row['status'] === 'WARN') {
                static::__assert_not_empty($row['remediation'], 'every WARN row names its remedy');
            }
        }
    }

    public static function test_heal_target_is_declared()
    {
        $targets = Heal_Runner::discover();

        static::__assert_true(isset($targets['claude-skills']), 'rsx:heal must declare claude-skills');
        static::__assert_equals(
            Claude_Skills_Health_Checks::class,
            $targets['claude-skills']['fqcn']
        );
    }

    public static function test_heal_runs_and_is_a_no_op_when_already_wired()
    {
        // This box carries no application skills, so the healer has nothing to create and
        // nothing to prune - which is exactly the path that must not report a repair.
        $result = Heal_Runner::run('claude-skills');

        static::__assert_true(
            in_array($result['status'], ['HEALED', 'ALREADY_OK'], true),
            'the healer must not refuse on a healthy tree: ' . $result['status'] . ' - ' . $result['detail']
        );
    }
}
