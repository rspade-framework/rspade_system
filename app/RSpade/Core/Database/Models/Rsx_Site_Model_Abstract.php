<?php

namespace App\RSpade\Core\Database\Models;

use App\RSpade\Core\Database\Models\Rsx_Model_Abstract;
use App\RSpade\Core\Database\Models\Site_Scoped;
/**
 * Abstract base model for site-scoped models with automatic concurrency control
 *
 * THE IMPLEMENTATION LIVES IN THE Site_Scoped TRAIT, and this class is a model that
 * adopts it and declares nothing else. Extending this class and adopting that trait
 * therefore install the same controls from the same source - there is one tenant
 * boundary, not two.
 *
 * Extend this when the model IS site-scoped from the start (the ordinary case). Adopt
 * Site_Scoped directly when a class cannot reach this one: an override of a split core
 * model must extend the framework's X_Model_Abstract base DIRECTLY, so if the framework
 * table is not site-scoped and the application's copy of it is, `use Site_Scoped` is how
 * that model gets the boundary. Site_Scoped's own docblock is the contract.
 *
 * Models extending this class:
 * - Automatically scope queries by site_id from session
 * - Include site_id column in the database
 * - Support soft deletes when configured
 * - Provide automatic site-level database locking for write operations
 * - Strict enforcement of site boundaries - no cross-site data access
 *
 * SITE ISOLATION:
 * - All queries automatically filtered by the CURRENT EXPERIENCE's site_id
 * - All saves automatically set site_id from the same source
 * - Changing site_id on existing records is FATAL
 * - Site ID 0 used when no staff site is selected (global/unscoped data)
 * - No caching of site_id - always reads fresh
 *
 * WHICH SITE (see Site_Scoped::get_current_site_id):
 * - Staff request / CLI: the staff session's site_id
 * - Portal request: the site the app declared via Portal_Session::set_site_id
 *   (throws if it declared none - a portal request has no site to guess at)
 *
 * CONCURRENCY CONTROL:
 * Site locks key on that same resolved site, so a portal request locks the portal's
 * tenant and never the staff session's:
 * - A READ-ONLY request takes NO lock at all and never contacts the lock daemon
 * - The first save() takes the CLUSTER-wide WRITE lock for that site (rsx-lockd), held
 *   until the end of the script
 * - No automatic transactions - handle manually when needed
 *
 * This prevents race conditions for critical operations like:
 * - Inventory management
 * - Auction bidding
 * - Financial transactions
 * - Any operation requiring strict consistency within a site
 */
abstract class Rsx_Site_Model_Abstract extends Rsx_Model_Abstract
{
    use Site_Scoped;

}
