<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Locks\Site_Lock_Directives;
use Illuminate\Console\Command;

/**
 * rsx:site_lock:endpoints - every server-dispatched surface and the site lock mode it runs
 * under: the inventory for finding the endpoints that write but are still readers.
 *
 * One line per surface (Ajax, external API and companion endpoints, page routes, model
 * fetches): MODE, KIND, SURFACE, FILE. MODE is reader / writer / manual / none (a page route
 * that declares nothing takes no automatic lock); a mode the surface DECLARED (rather than
 * took by default) is marked with '*'. The data is the auth surface index the dispatchers
 * read (Site_Lock_Directives::inventory()).
 *
 *   --mode=<m>   only this mode (reader | writer | manual | none); repeatable
 *   --kind=<k>   only surfaces of this kind (ajax | api | companion | route | spa |
 *                portal_route | model_fetch | model_relationship); repeatable
 *   --app        only the application's own surfaces (rsx/), not the framework's
 *   --json       the rows as a JSON array: {surface, kinds, mode, declared, file}
 *
 * Exit 0 with the list (an empty list prints nothing); 1 on an unknown --mode or --kind.
 */
class Site_Lock_Endpoints_Command extends Command
{
    private const MODES = ['reader', 'writer', 'manual', 'none'];

    private const KINDS = ['ajax', 'api', 'companion', 'route', 'spa', 'portal_route', 'model_fetch', 'model_relationship'];

    protected $signature = 'rsx:site_lock:endpoints
                            {--mode=* : Only this site lock mode: reader, writer, manual or none (repeatable)}
                            {--kind=* : Only this surface kind: ajax, api, companion, route, spa, portal_route, model_fetch, model_relationship (repeatable)}
                            {--app : Only the application\'s own surfaces (rsx/)}
                            {--json : Print the rows as JSON}';

    protected $description = 'List every Ajax, API, companion, page route and model fetch surface with its site lock mode';

    public function handle()
    {
        $modes = (array) $this->option('mode');
        $kinds = (array) $this->option('kind');

        foreach ($modes as $mode) {
            if (!in_array($mode, self::MODES, true)) {
                $this->error("[ERROR] Unknown --mode '{$mode}'. One of: " . implode(', ', self::MODES));

                return 1;
            }
        }

        foreach ($kinds as $kind) {
            if (!in_array($kind, self::KINDS, true)) {
                $this->error("[ERROR] Unknown --kind '{$kind}'. One of: " . implode(', ', self::KINDS));

                return 1;
            }
        }

        $rows = array_values(array_filter(Site_Lock_Directives::inventory(), function (array $row) use ($modes, $kinds) {
            if ($modes !== [] && !in_array($row['mode'], $modes, true)) {
                return false;
            }

            if ($kinds !== [] && array_intersect($row['kinds'], $kinds) === []) {
                return false;
            }

            return !$this->option('app') || str_starts_with($row['file'], 'rsx/');
        }));

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return 0;
        }

        if ($rows === []) {
            return 0;
        }

        $kind_width = max(array_map(fn (array $row) => strlen(implode(',', $row['kinds'])), $rows));
        $surface_width = max(array_map(fn (array $row) => strlen($row['surface']), $rows));

        foreach ($rows as $row) {
            $this->line(sprintf(
                '%-7s %-' . $kind_width . 's  %-' . $surface_width . 's  %s',
                $row['mode'] . ($row['declared'] ? '*' : ''),
                implode(',', $row['kinds']),
                $row['surface'],
                $row['file']
            ));
        }

        return 0;
    }
}
