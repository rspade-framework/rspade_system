<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use Symfony\Component\Process\Process;

/**
 * Playwright_Stack - ONE probe of the node -> playwright -> chromium chain that
 * rsx:debug renders pages with, and ONE set of install commands describing how to
 * repair it.
 *
 * Two consumers read it and there is nothing to keep in step between them:
 * Playwright_Health_Checks turns a probe into rsx:health rows, and Route_Debug_Command
 * turns the same probe into its preflight refusal. So the operator is told to run the
 * same literal command by the health table and by the tool that refused - the install
 * line is written here and nowhere else.
 *
 * PROBE ONLY - this class never installs anything. rsx:health must be read-only, and
 * rsx:debug refuses with the command rather than running it, because a package install
 * and a browser download are large, network-bound operations an operator chooses to
 * start; a debug run that silently turns into one is a surprise, not a convenience.
 *
 * It lives in the Health domain rather than beside Route_Debug_Command because
 * #[Health_Check] discovery is a manifest attribute scan and app/RSpade/Commands is NOT
 * in the manifest scan_directories - an attribute there would never be discovered.
 */
class Playwright_Stack
{
    /** Install node. */
    public const NODE_INSTALL = 'apt-get install -y nodejs';

    /** Install the playwright npm package (from the framework root). */
    public const PACKAGE_INSTALL = 'npm install playwright';

    /** Download the chromium build that playwright package expects. */
    public const CHROMIUM_INSTALL = 'npx playwright install chromium';

    /**
     * Probe the chain, stopping at the first link that is absent (the later probes
     * cannot run without the earlier ones).
     *
     * @return array{ok: bool, missing: ?string, detail: string, remediation: ?string, chromium_path: ?string}
     */
    public static function probe(): array
    {
        if (!static::_node_present()) {
            return static::_missing(
                'node',
                'node not found on PATH - required for Playwright and rsx:debug',
                self::NODE_INSTALL
            );
        }

        if (!static::_package_present()) {
            return static::_missing(
                'package',
                "the 'playwright' npm package is not installed",
                self::PACKAGE_INSTALL
            );
        }

        $chromium = static::_chromium_path();

        if ($chromium === null) {
            return static::_missing(
                'chromium',
                'the chromium browser binary Playwright expects is not installed',
                self::CHROMIUM_INSTALL
            );
        }

        if (!is_file($chromium)) {
            return static::_missing(
                'chromium',
                'the chromium browser binary is missing at ' . $chromium,
                self::CHROMIUM_INSTALL
            );
        }

        return [
            'ok' => true,
            'missing' => null,
            'detail' => 'node, playwright and chromium present (chromium at ' . $chromium . ')',
            'remediation' => null,
            'chromium_path' => $chromium,
        ];
    }

    /**
     * The refusal a tool prints when the stack it needs is absent: what is missing, the
     * literal command that installs it, and what the tool was going to do.
     *
     * @param array $probe A probe() result whose 'ok' is false.
     */
    public static function refusal_message(array $probe, string $tool): string
    {
        return '[ERROR] ' . $tool . ' needs Playwright to render a page, and this box does not have it.' . PHP_EOL
            . '  Missing: ' . $probe['detail'] . PHP_EOL
            . '  Install: ' . $probe['remediation'] . PHP_EOL
            . '  Then run ' . $tool . ' again. See rsx:man health for the matching health row.';
    }

    // =========================================================================
    // the individual links, each overridable by a test seam
    // =========================================================================

    /**
     * The link a probe should report as absent regardless of what this box has.
     *
     * Every branch of this class is a state a development box is not in - which is
     * exactly the state the refusal text has to be right for. Tests (and a developer
     * reproducing the refusal) force one link absent through this seam.
     *
     * @var string|null 'node' | 'package' | 'chromium' | null
     */
    private static $_testing_absent_link = null;

    /**
     * Force a link absent for the next probes (tests only; null restores the real probe).
     */
    public static function _testing_set_absent_link(?string $link): void
    {
        self::$_testing_absent_link = $link;
    }

    protected static function _node_present(): bool
    {
        if (self::$_testing_absent_link === 'node') {
            return false;
        }

        return static::_run(['node', '--version']) !== null;
    }

    protected static function _package_present(): bool
    {
        if (self::$_testing_absent_link === 'package') {
            return false;
        }

        return static::_run(['node', '-e', "require('playwright')"]) !== null;
    }

    /**
     * The chromium executable Playwright resolves to, or null when it resolves to none.
     * A pure resolution - it never LAUNCHES a browser.
     */
    protected static function _chromium_path(): ?string
    {
        if (self::$_testing_absent_link === 'chromium') {
            return null;
        }

        $script = "const {chromium}=require('playwright');process.stdout.write(chromium.executablePath()||'');";
        $output = static::_run(['node', '-e', $script]);

        if ($output === null || trim($output) === '') {
            return null;
        }

        return trim($output);
    }

    /**
     * Run a probe command, returning its stdout or null when it failed.
     *
     * NO TIMEOUT (null). Symfony's Process caps at 60 seconds when you say nothing, and
     * an inherited default is still a deadline: a probe that hangs means the thing it
     * probes is wedged, which is a fault to SEE rather than to convert into a row that
     * reads identically to "not installed". See the no-timeout mandate.
     */
    private static function _run(array $command): ?string
    {
        $process = new Process($command, base_path());
        $process->setTimeout(null);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * @return array{ok: bool, missing: string, detail: string, remediation: string, chromium_path: null}
     */
    private static function _missing(string $link, string $detail, string $remediation): array
    {
        return [
            'ok' => false,
            'missing' => $link,
            'detail' => $detail,
            'remediation' => $remediation,
            'chromium_path' => null,
        ];
    }
}
