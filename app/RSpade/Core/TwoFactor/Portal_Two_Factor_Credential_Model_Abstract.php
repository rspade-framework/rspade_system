<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\TwoFactor;

use App\RSpade\Core\Database\Models\Rsx_System_Model_Abstract;

/**
 * Portal_Two_Factor_Credential_Model_Abstract - one second factor or passkey belonging to one
 * PORTAL user.
 *
 * The portal realm's twin of Two_Factor_Credential_Model: the same three kinds in one table,
 * discriminated by the same type_id vocabulary, with secret, counter and credential_key
 * meaning exactly what they mean there (see that model and its migration). What differs is
 * the OWNER - a portal_users row - and therefore the table, because a foreign key names one
 * table and the realm boundary is the table boundary.
 *
 * THE TYPE VOCABULARY IS DECLARED HERE AS WELL AS ON THE STAFF MODEL, WITH THE SAME IDS. The
 * two tables hold the same kinds of thing, and the realm-generic engine reads TYPE_* and
 * factor_types() from whichever model its realm names - so the ids must agree, and an
 * addition to one enum is an addition to both.
 *
 * NOTHING HERE IS PUBLIC. There is no fetch() and there never should be. Read and write it
 * through Rsx_Portal_Two_Factor, which is the only class application code touches.
 *
 * See: php artisan rsx:man two_factor
 *
 * THE BASE OF A SPLIT MODEL. Every member of the framework's Portal_Two_Factor_Credential_Model
 * lives here; `Portal_Two_Factor_Credential_Model.php` beside it is a shell an application
 * replaces by declaring `class Portal_Two_Factor_Credential_Model extends
 * Portal_Two_Factor_Credential_Model_Abstract` under rsx/models/.
 *
 * See: php artisan rsx:man class_override
 */
/**
 * _AUTO_GENERATED_ Database type hints - do not edit manually
 * Table: _portal_two_factor_credentials
 *
 * @property int $id
 * @property int $portal_user_id
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
abstract class Portal_Two_Factor_Credential_Model_Abstract extends Rsx_System_Model_Abstract
{
    /**
     * _AUTO_GENERATED_ Enum constants
     */
    const TYPE_TOTP = 1;
    const TYPE_PASSKEY = 2;
    const TYPE_RECOVERY_CODE = 3;

    /**
     * UNBOUNDED: the row count grows with the portal user base, not with the codebase.
     *
     * @var bool
     */
    public static $unbounded = true;

    protected $table = '_portal_two_factor_credentials';

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
            // Not selectable: a recovery code is minted as a consequence of enrolling a real
            // factor, never picked on its own.
            3 => [
                'constant' => 'TYPE_RECOVERY_CODE',
                'label' => 'Recovery Code',
                'order' => 3,
                'selectable' => false,
            ],
        ],
    ];

    /**
     * The two types that ARE a second factor - see Two_Factor_Credential_Model_Abstract::
     * factor_types(), whose reasoning (and whose reason for being a method) holds here.
     *
     * @return array
     */
    public static function factor_types(): array
    {
        return [self::TYPE_TOTP, self::TYPE_PASSKEY];
    }
}
