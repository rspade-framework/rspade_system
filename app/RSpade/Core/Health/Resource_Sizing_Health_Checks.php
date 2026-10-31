<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Health;

use App\RSpade\Core\Health\Rsx_Container_Sizing;
use App\RSpade\Core\Rsx;

/**
 * Resource_Sizing_Health_Checks - what the container sized this machine for.
 *
 * An INFO row: the budget rspade-sizing chose at the last container start (the php-fpm
 * workers and which ceiling decided them, the task workers, the memory limit, the Redis cap),
 * each figure marked when a switch set it rather than the calculation. WARN only for a
 * container whose image predates the record - pull or rebuild it.
 */
class Resource_Sizing_Health_Checks
{
    private const CEILINGS = [
        'ram' => 'RAM',
        'worst' => 'RAM + swap at the memory limit',
        'cpu' => 'cores',
        'floor' => 'the floor of 2',
        'cap' => 'the 140-process cap',
        'override' => 'PHP_FPM_WORKER_COUNT',
    ];

    #[Health_Check('Resource Sizing')]
    public static function resource_sizing(): array
    {
        $budget = Rsx_Container_Sizing::read();

        if ($budget === null) {
            if (Rsx::is_rspade_container()) {
                return [
                    'status' => 'WARN',
                    'detail' => 'This container recorded no resource budget (' . Rsx_Container_Sizing::FILE . ' is absent):'
                        . ' the image predates resource sizing. Pull or rebuild it.',
                ];
            }

            return [
                'status' => 'INFO',
                'detail' => 'Not an RSpade container: php-fpm, the memory limit (' . ini_get('memory_limit')
                    . ') and Redis are sized by this host\'s own configuration.',
            ];
        }

        $mark = fn (string $source) => $source === 'override' ? ' (set)' : '';
        $machine = $budget['machine'];
        $php = $budget['php'];
        $fpm = $budget['php_fpm'];
        $tasks = $budget['tasks'];
        $redis = $budget['redis'];

        $parts = [
            "{$machine['cores']} cores, {$machine['memory_mb']} MB memory, {$machine['swap_mb']} MB swap",
            "php-fpm {$fpm['total']}{$mark($fpm['source'])}: web {$fpm['web']}, ajax {$fpm['ajax']}"
                . ($fpm['pools_sized'] ? '' : ' (development pools unchanged)')
                . ', limited by ' . (self::CEILINGS[$budget['ceilings']['winner']] ?? $budget['ceilings']['winner']),
            "{$tasks['total']} task workers ({$tasks['scheduled']}{$mark($tasks['scheduled_source'] ?? 'calculated')} scheduled + {$tasks['on_demand']}{$mark($tasks['on_demand_source'])} on demand)",
            "memory_limit {$php['memory_limit_mb']}M{$mark($php['memory_limit_source'])}",
            "redis {$redis['maxmemory_mb']} MB{$mark($redis['source'])}",
            "{$budget['reserved_mb']['total']} MB reserved",
        ];

        $detail = implode('; ', $parts) . '.';
        if (!empty($budget['ceilings']['cap_notice'])) {
            $detail .= ' The machine could run more: past ' . (int) $budget['ceilings']['process_cap']
                . ' PHP processes, set the worker counts and size the database (rsx:man server_sizing).';
        }

        return ['status' => 'INFO', 'detail' => $detail];
    }
}
