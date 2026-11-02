<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Core\Env;

use Throwable;
use App\RSpade\Core\Database\MigrationValidator;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * Rsx_Install_State - is this install ready to run what depends on it?
 *
 * One framework fact: a build is present, the database answers with no migration pending, and
 * at least one site is provisioned. It keeps everything that waits for an install (companion
 * applications first of all) quiet on a fresh box, and pending migrations hold them in
 * production too. Read-only.
 */
class Rsx_Install_State
{
    /**
     * Whether the install is ready.
     */
    public static function is_ready(): bool
    {
        return static::not_ready_reason() === null;
    }

    /**
     * Why the install is not ready, or null when it is. A database that cannot answer is a
     * reason: this is asked while an install is being provisioned, when that is expected.
     */
    public static function not_ready_reason(): ?string
    {
        if (!is_file(Rsx_Project_Paths::build_key_file())) {
            return 'no build is present';
        }

        try {
            $pending = count(MigrationValidator::get_pending_migrations(app('migrator')->getRepository()));
            $has_site = Site_Model::where('id', '>', 0)->exists();
        } catch (Throwable $e) {
            return 'the database cannot answer: ' . $e->getMessage();
        }

        if ($pending > 0) {
            return "{$pending} migration" . ($pending === 1 ? ' is' : 's are') . ' pending';
        }

        if (!$has_site) {
            return 'no site is provisioned';
        }

        return null;
    }
}
