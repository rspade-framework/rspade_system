<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use App\RSpade\Core\Manifest\Class_Override_Drift;

/**
 * Class_Override_Drift_Health_Checks - the operator-facing view of CLASS-OVERRIDE-DRIFT-01.
 *
 * A class override is a frozen copy of a framework class that keeps moving. When the
 * framework adds a member and then calls it, the call lands on the application's older
 * copy and there is no error until a user finds one. rsx:check names each missing member;
 * this row is the thing an operator sees WITHOUT running the linter - on a box that just
 * took a framework pull, the pull is exactly when the drift appears.
 *
 * WARN AND NEVER FAIL. rsx:health exits non-zero only on FAIL, and drift is a condition
 * the site is already running with: it arrives with a pull, not with a deploy, and the fix
 * is a scheduled re-clone. Failing here would turn a latent defect into a broken deploy
 * gate and a red container healthcheck on a site that is serving fine.
 *
 * NO HEAL TARGET. There is nothing definitionally absent to create. Re-cloning an override
 * means re-applying the application's own additions to the new copy, which is a code
 * review, not a repair - and rsx:heal creates only what is absent and never overwrites
 * something present-but-wrong.
 *
 * @see \App\RSpade\Core\Manifest\Class_Override_Drift
 * @see rsx:man class_override
 */
class Class_Override_Drift_Health_Checks
{
    /**
     * Report every class override that has stopped carrying a member its framework
     * original declares.
     *
     * @return array
     */
    #[Health_Check('Class Override Drift')]
    public static function class_override_drift(): array
    {
        $pairs = Class_Override_Drift::pairs();

        if (empty($pairs)) {
            return [
                'status' => 'OK',
                'detail' => 'No class overrides are active.',
                'remediation' => null,
            ];
        }

        $drifted = Class_Override_Drift::analyze_all();

        if (empty($drifted)) {
            return [
                'status' => 'OK',
                'detail' => count($pairs) . ' class override(s) active; each still declares every'
                    . ' public and protected member its framework original declares.',
                'remediation' => null,
            ];
        }

        $rows = [];

        foreach ($drifted as $entry) {
            $count = count($entry['missing']);
            $names = [];

            foreach ($entry['missing'] as $member) {
                $names[] = Class_Override_Drift::describe($member);
            }

            $rows[] = [
                'status' => 'WARN',
                'detail' => $entry['class'] . ' (' . $entry['override_file'] . ') is missing '
                    . $count . ' member(s) its framework original still declares: '
                    . implode(', ', $names) . '. Framework code may call them.',
                'remediation' => 'php artisan rsx:check (CLASS-OVERRIDE-DRIFT-01) names each one'
                    . ' and what the override adds of its own; then re-clone from '
                    . $entry['upstream_file'],
            ];
        }

        return $rows;
    }
}
