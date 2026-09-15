<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\FPC\Rsx_FPC;
use App\RSpade\Core\Manifest\Manifest;
use Illuminate\Console\Command;

/**
 * rsx:fpc:clear - purge full-page-cache entries for this build.
 *
 * The operator lever for "that page is stale now". A page is cached because a route
 * carries #[FPC], and a cached page with no declared TTL lives until something removes
 * it - so something has to be typeable.
 *
 * WHOLE BUILD BY DEFAULT, one page with --url. The default is the build's OWN entries
 * (fpc:{build_key}:*), never the whole Redis database: another build's entries are
 * another build's business, and rsx:clean is the command that empties the database
 * outright.
 *
 * It fails loud on an unreachable Redis rather than reporting "0 cleared" - a purge that
 * silently did nothing leaves stale pages serving, which is the exact thing the operator
 * ran this to stop.
 *
 * See: php artisan rsx:man fpc
 */
class Fpc_Clear_Command extends Command
{
    protected $signature = 'rsx:fpc:clear
                            {--url= : Clear one page (path with optional query string, e.g. /about or /search?q=x)}';

    protected $description = 'Clear full page cache entries for the current build';

    public function handle(): int
    {
        $url = (string) $this->option('url');

        if ($url !== '') {
            $cleared = Rsx_FPC::clear_url($url);

            // A URL that was not cached is not a failure: it is the state the operator
            // wanted, reached before they asked. Say which it was and exit 0 either way.
            $this->info($cleared
                ? '[OK] Cleared the cached page for ' . $url
                : '[OK] Nothing cached for ' . $url);

            return 0;
        }

        $count = Rsx_FPC::clear();

        $this->info('[OK] Cleared ' . $count . ' cached page(s) for build ' . Manifest::get_build_key());

        return 0;
    }
}
