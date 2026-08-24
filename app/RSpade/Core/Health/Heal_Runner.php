<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use App\RSpade\Core\Manifest\Manifest;

/**
 * Heal_Runner - discovery and invocation for rsx:heal, the executable half of rsx:health.
 *
 * A health row can already carry a `remediation` string; a healer is that remedy made
 * runnable. Declared WHERE ITS FEATURE LIVES, next to the #[Health_Check] it answers,
 * tagged `#[Health_Heal('target-name')]` on a public static method. Discovery is a
 * manifest attribute scan (never reflection getAttributes() directly - PHP-ATTR-01) and
 * there is no attribute CLASS, matching Health_Check_Runner exactly.
 *
 * THE BOUNDARY, AND WHY IT IS NARROW. A healer may only create what is DEFINITIONALLY
 * ABSENT - a manifest an app never adopted, a symlink nobody made. It must NEVER repair
 * something that exists and is wrong: a corrupt file, a half-written manifest, a symlink
 * pointing somewhere unexpected. Those are broken installs, and a command that quietly
 * makes them look fixed is a silent repair wearing a helpful name - the exact thing the
 * fail-loud mandate forbids. A healer that finds its artifact present and unexpected
 * REFUSES and says what it found; the operator decides.
 *
 * Return contract of a heal method:
 *   ['status' => 'HEALED'|'ALREADY_OK'|'REFUSED', 'detail' => string]
 *
 * A healer that throws becomes a REFUSED result carrying the exception message - one
 * broken healer never aborts the surrounding run.
 */
class Heal_Runner
{
    /** The valid result statuses. */
    private const VALID_STATUSES = ['HEALED', 'ALREADY_OK', 'REFUSED'];

    /**
     * Every declared heal target, keyed by target name.
     *
     * @return array<string, array{fqcn: string, method: string, target: string}>
     */
    public static function discover(): array
    {
        $targets = [];

        foreach (Manifest::get_with_attribute('Health_Heal') as $row) {
            // Only method-level attributes are healers (a class-level #[Health_Heal]
            // has no method to invoke).
            if (($row['type'] ?? null) !== 'method') {
                continue;
            }

            $instances = $row['instances'] ?? [];
            $first = $instances[0] ?? null;
            $target = is_array($first) ? ($first[0] ?? null) : null;

            $fqcn = $row['fqcn'] ?? ($row['class'] ?? 'unknown');
            $method = $row['method'] ?? 'unknown';

            if (!is_string($target) || $target === '') {
                shouldnt_happen(
                    "#[Health_Heal] on {$fqcn}::{$method} is missing its required target argument"
                    . " (usage: #[Health_Heal('target-name')])"
                );
            }

            if (isset($targets[$target])) {
                $other = $targets[$target];
                shouldnt_happen(
                    "Duplicate #[Health_Heal] target '{$target}': declared on {$other['fqcn']}::{$other['method']}"
                    . " and again on {$fqcn}::{$method}. A target names exactly one healer."
                );
            }

            $targets[$target] = [
                'fqcn' => $fqcn,
                'method' => $method,
                'target' => $target,
            ];
        }

        ksort($targets);

        return $targets;
    }

    /**
     * Run one heal target. Returns the normalized result row.
     *
     * @return array{target: string, status: string, detail: string}
     */
    public static function run(string $target): array
    {
        $targets = static::discover();

        if (!isset($targets[$target])) {
            $known = implode(', ', array_keys($targets));

            return [
                'target' => $target,
                'status' => 'REFUSED',
                'detail' => "Unknown heal target '{$target}'."
                    . ($known === '' ? ' No heal targets are declared.' : " Known targets: {$known}"),
            ];
        }

        $fqcn = $targets[$target]['fqcn'];
        $method = $targets[$target]['method'];

        try {
            $result = $fqcn::$method();
        } catch (\Throwable $e) {
            return [
                'target' => $target,
                'status' => 'REFUSED',
                'detail' => get_class($e) . ': ' . $e->getMessage(),
            ];
        }

        $status = is_array($result) ? ($result['status'] ?? null) : null;

        if (!is_string($status) || !in_array($status, self::VALID_STATUSES, true)) {
            return [
                'target' => $target,
                'status' => 'REFUSED',
                'detail' => "{$fqcn}::{$method}() returned an out-of-contract result"
                    . ' (expected status HEALED|ALREADY_OK|REFUSED).',
            ];
        }

        return [
            'target' => $target,
            'status' => $status,
            'detail' => (string) ($result['detail'] ?? ''),
        ];
    }
}
