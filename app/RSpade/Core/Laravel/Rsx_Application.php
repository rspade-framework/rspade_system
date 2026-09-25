<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Laravel;

use Illuminate\Foundation\Application;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * The Laravel application container, taught where RSpade's build tree is.
 *
 * Laravel writes cached artifacts - config, events, services, packages - and resolves
 * each through bootstrapPath('cache/...'). (Its fifth, the route cache, does not exist in
 * RSX: Laravel's router is not in the request path and holds no routes.) They are BUILD OUTPUTS:
 * produced by the build, consumed by every served request, and read-only to the web
 * user on a correctly configured production box. So they belong in build/laravel,
 * with every other build output, rather than inside the framework checkout.
 *
 * The four getters are overridden rather than bootstrapPath() itself, because
 * bootstrapPath() also resolves bootstrap/providers.php - a SOURCE file that must stay
 * where it is. Every Laravel consumer of a cached artifact goes through one of these
 * getters, so overriding them moves all four and nothing else.
 *
 * The APP_*_CACHE environment escapes Laravel offers are deliberately not honoured
 * here: the build tree is one artifact, produced and sealed together, and a per-file
 * relocation mechanism would let one cached file drift out of the tree the seal covers.
 */
#[Instantiatable]
class Rsx_Application extends Application
{
    public function getCachedServicesPath()
    {
        return Rsx_Project_Paths::laravel_cache_file('services');
    }

    public function getCachedPackagesPath()
    {
        return Rsx_Project_Paths::laravel_cache_file('packages');
    }

    public function getCachedConfigPath()
    {
        return Rsx_Project_Paths::laravel_cache_file('config');
    }

    public function getCachedEventsPath()
    {
        return Rsx_Project_Paths::laravel_cache_file('events');
    }
}
