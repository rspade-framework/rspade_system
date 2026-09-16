<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Prod;

use App\RSpade\Core\Console\Rsx_Internal_Flags;
use App\RSpade\Core\Console\Rsx_Script;

/**
 * THE build context: is this process producing the build tree right now?
 *
 * In a production-like mode the build tree is a deployment artifact - written by one
 * command and read by everything else. That rule needs exactly one answer to "may this
 * process write build/", and this class is it:
 *
 *   - rsx:build IS the build context. It is recognised from argv, so the answer holds
 *     from the first line of boot (the manifest gate asks before any handler runs).
 *   - Every subprocess rsx:build spawns inherits the context through the internal
 *     FLAG below (the `--_` convention: stripped from argv pre-boot, declared as no
 *     InputOption, invisible in help output).
 *   - begin() is the in-process declaration, for a build driven from PHP rather than
 *     from the command line.
 *
 * A WEB REQUEST IS NEVER A BUILD CONTEXT. The SAPI check is first and unconditional:
 * nothing a browser sends can make a served request eligible to rewrite the build.
 */
class Rsx_Build_Context
{
    /**
     * Internal flag carrying the context to a spawned child.
     */
    public const FLAG = '--_build-context';

    /**
     * The command whose whole job is to produce the build tree.
     */
    public const BUILD_COMMAND = 'rsx:build';

    /**
     * In-process declaration (see begin()).
     */
    private static bool $active = false;

    /**
     * Declare THIS process a build context.
     *
     * Idempotent. Never call it from anything but the build pipeline: it is the single
     * key to every guarded write under the build root.
     */
    public static function begin(): void
    {
        self::$active = true;
    }

    /**
     * Is this process producing the build tree?
     */
    public static function is_active(): bool
    {
        if (PHP_SAPI !== 'cli') {
            return false;
        }

        if (self::$active) {
            return true;
        }

        if (Rsx_Internal_Flags::has(self::FLAG)) {
            return true;
        }

        // An external script has no command name in argv[1] - only the explicit flag
        // above can put such a process in the build context.
        if (Rsx_Script::is_active()) {
            return false;
        }

        return (($_SERVER['argv'][1] ?? '') === self::BUILD_COMMAND);
    }

    /**
     * The argv tokens that carry this context to a child process.
     *
     * @return array<int, string>
     */
    public static function child_flags(): array
    {
        return self::is_active() ? [self::FLAG] : [];
    }

    /**
     * Drop the in-process declaration (tests only).
     */
    public static function _testing_reset(): void
    {
        self::$active = false;
    }
}
