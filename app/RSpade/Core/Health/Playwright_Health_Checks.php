<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use App\RSpade\Core\Health\Playwright_Stack;

/**
 * Playwright_Health_Checks - the rsx:health view of the Playwright + Chromium stack
 * that rsx:debug renders pages with.
 *
 * MODE: development only. Playwright is a development tool - rsx:debug refuses to run
 * in any other mode, and nothing a sealed build serves touches it. A production box
 * reporting a missing browser is reporting an absence it is correct to have.
 *
 * WARN AND NEVER FAIL. Nothing the site serves depends on this: an absent browser costs
 * the developer one command, and rsx:health exits non-zero only on FAIL, so a missing
 * development convenience can never break a deploy gate or a container healthcheck.
 *
 * The probe and the install commands live in Playwright_Stack, which rsx:debug's
 * preflight reads too - so the remediation this row prints is the literal command that
 * refusal prints.
 */
class Playwright_Health_Checks
{
    /**
     * Report whether rsx:debug could render a page on this box.
     *
     * @return array
     */
    #[Health_Check('Playwright / Chromium', modes: 'development')]
    public static function playwright_stack(): array
    {
        return static::_row(Playwright_Stack::probe());
    }

    /**
     * The row for a probe result. Public so the tests can drive every branch - the
     * interesting states are ones a working development box is never in.
     *
     * @return array{status: string, detail: string, remediation: ?string}
     */
    public static function _row(array $probe): array
    {
        if ($probe['ok']) {
            return ['status' => 'OK', 'detail' => $probe['detail'], 'remediation' => null];
        }

        return [
            'status' => 'WARN',
            'detail' => $probe['detail'] . ' - rsx:debug cannot render a page until it is installed',
            'remediation' => $probe['remediation'],
        ];
    }
}
