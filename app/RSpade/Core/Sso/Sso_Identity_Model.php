<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Sso;

use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;

/**
 * Sso_Identity_Model - one connection between a login identity and one account at one
 * identity provider.
 *
 * A row says: "login_users id 7 is also Google subject 1029384756". Nothing more. It holds
 * no token and no refresh token, deliberately - RSpade uses a provider to ANSWER A QUESTION
 * ("is this the person who owns that account?") and never to act on the user's behalf
 * afterwards, so an access token has nothing to do here except sit in a table waiting to
 * leak. If an application ever needs one, it stores it in its own table with its own
 * lifecycle and its own reason for existing.
 *
 * THE OWNER IS A LOGIN IDENTITY, NOT A SITE USER, for the same reason a second factor is:
 * signing in with Google proves who is holding the browser, which is exactly what
 * login_users is. A users row is an authorization scope inside one tenant.
 *
 * provider_key IS A REGISTRY STRING, NOT AN ENUM (owner ruling, 2026-09-04), so $enums is
 * EMPTY here and will stay empty. The set of providers is open by design - an application
 * adds one with a composer package and a config block - and a new key must not need a
 * migration and a constant to become storable. The five constants on Rsx_Sso name the
 * BUILT-INS; they are not the permitted set.
 *
 * (provider_key, provider_user_key) IS UNIQUE at the database, and that is the security
 * property the whole subsystem rests on: one provider account connects to at most one local
 * identity. Rsx_Sso refuses a second link before it gets here; the index is what makes the
 * refusal hold under a race.
 *
 * NOTHING HERE IS PUBLIC. There is no fetch() and there should not be - the browser is
 * never handed a row, only the metadata Rsx_Sso::identities_list() assembles. Read and
 * write it through Rsx_Sso, which is the only class application code touches.
 *
 * See: php artisan rsx:man sso
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _sso_identities
 *
 * @property int $id
 * @property int $login_user_id
 * @property string $provider_key
 * @property string $provider_user_key
 * @property string $email
 * @property string $name
 * @property string $avatar_url
 * @property string $last_login_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $created_at
 * @property string $updated_at
 *
 * @mixin \Eloquent
 */
class Sso_Identity_Model extends Rsx_System_Model_Abstract
{
    /**
     * UNBOUNDED: this table's row count grows with the user base, not with the codebase -
     * one row per identity per connected provider. No reader may assume the whole table is
     * small.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule. It is a DECLARATION, not a runtime
     * gate: the narrow per-identity queries in this subsystem are still fine.
     *
     * @var bool
     */
    public static $unbounded = true;

    /**
     * Declared and EMPTY, not omitted: every model states its enum vocabulary, and this
     * one's is deliberately nothing. provider_key is a registry string - see the class
     * docblock and the migration.
     *
     * @var array
     */
    public static $enums = [];

    protected $table = '_sso_identities';
}
