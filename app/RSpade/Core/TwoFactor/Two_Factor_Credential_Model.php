<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;

/**
 * Two_Factor_Credential_Model - one second factor belonging to one login identity.
 *
 * ONE table holds all three factor kinds, discriminated by type_id: an authenticator-app
 * seed, a passkey, and a single recovery code. They share every column that matters and a
 * login challenge has to consider all of them together, so splitting them across three
 * tables would buy nothing and cost a three-way union on the hottest read.
 *
 * THE OWNER IS A LOGIN IDENTITY, NOT A SITE USER. A second factor proves who is holding the
 * browser, which is exactly what login_users is; a users row is an authorization scope
 * inside one tenant, and enrolling a phone once per tenant would be wrong.
 *
 * CONFIRMED vs NOT. confirmed_at is NULL until the enrollment is PROVEN - a live code
 * entered, an attestation verified. An unconfirmed row must never satisfy a login
 * challenge, so every read that gates a login filters on it. That is the whole reason the
 * column exists: a half-finished enrollment that could log somebody in would be worse than
 * no second factor at all.
 *
 * THREE COLUMNS MEAN THREE THINGS, one per type - see the migration for the full narrative:
 *   secret  - TOTP: the RFC 6238 seed, encrypted at rest. RECOVERY_CODE: the bcrypt hash of
 *             one single-use code. PASSKEY: the stored public key material, not a secret.
 *   counter - PASSKEY: the authenticator's signature counter (anti-cloning).
 *             TOTP: the last ACCEPTED timestep (anti-replay).
 *   credential_key - PASSKEY only: the raw credential id, base64url encoded, globally
 *             UNIQUE. Named _key and not _id because SCHEMA-TYPE-01 reserves an _id suffix
 *             for integers and this is an opaque string handle minted by an authenticator.
 *
 * NOTHING HERE IS PUBLIC. There is no fetch() and there never should be: the browser is
 * never given a row from this table, only the metadata Rsx_Two_Factor::list_credentials()
 * assembles. Read and write it through Rsx_Two_Factor, which is the only class application
 * code touches.
 *
 * See: php artisan rsx:man two_factor
 *
 * @property int $id
 * @property int $login_user_id
 * @property int $type_id
 * @property string|null $label
 * @property string|null $secret
 * @property string|null $credential_key
 * @property int $counter
 * @property string|null $confirmed_at
 * @property string|null $last_used_at
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _two_factor_credentials
 *
 * @property int $id
 * @property int $login_user_id
 * @property int $type_id
 * @property string $label
 * @property string $secret
 * @property string $credential_key
 * @property int $counter
 * @property string $confirmed_at
 * @property string $last_used_at
 * @property int $created_by_id
 * @property int $created_by_type
 * @property int $updated_by_id
 * @property int $updated_by_type
 * @property string $created_at
 * @property string $updated_at
 *
 * @property-read string $type_id__label
 * @property-read string $type_id__constant
 *
 * @method static array type_id__enum() Get all enum definitions with full metadata
 * @method static array type_id__enum_select() Get [{value, label}] array for dropdowns
 * @method static array type_id__enum_labels() Get simple id => label map
 * @method static array type_id__enum_ids() Get array of all valid enum IDs
 *
 * @mixin \Eloquent
 */
class Two_Factor_Credential_Model extends Rsx_System_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const TYPE_TOTP = 1;
    const TYPE_PASSKEY = 2;
    const TYPE_RECOVERY_CODE = 3;
    /**
     * UNBOUNDED: this table's row count grows with the user base, not with the codebase.
     * Every identity that enrolls carries at least eleven rows (a factor plus ten recovery
     * codes), so no reader may assume the whole table is small.
     *
     * Consumed by the DB-UNBOUNDED-01 code-quality rule. It is a DECLARATION, not a runtime
     * gate - the narrow per-identity queries in this subsystem are still fine.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_two_factor_credentials';

    public static $enums = [
        'type_id' => [
            1 => [
                'constant' => 'TYPE_TOTP',
                'label' => 'Authenticator App',
                'order' => 1,
            ],
            2 => [
                'constant' => 'TYPE_PASSKEY',
                'label' => 'Passkey',
                'order' => 2,
            ],
            // Not selectable: a recovery code is never something a user picks to enroll.
            // The set is minted as a consequence of enrolling a real factor.
            3 => [
                'constant' => 'TYPE_RECOVERY_CODE',
                'label' => 'Recovery Code',
                'order' => 3,
                'selectable' => false,
            ],
        ],
    ];

    /**
     * The two types that ARE a second factor. A recovery code is a way back in when you
     * have lost one of these, never a factor you enroll on its own - so "does this identity
     * have 2FA?" asks about exactly this set.
     *
     * A METHOD and not a const: the TYPE_* constants are generated into this class by
     * rsx:constants:regenerate, so a const initialiser naming them cannot be evaluated on
     * the pass that is about to write them. A method body is not evaluated at class load.
     *
     * @return array
     */
    public static function factor_types(): array
    {
        return [self::TYPE_TOTP, self::TYPE_PASSKEY];
    }
}
