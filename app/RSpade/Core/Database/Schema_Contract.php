<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Database;

/**
 * Schema_Contract - what the FRAMEWORK assumes about application-owned tables.
 *
 * An application-owned table is one with no leading underscore: users, login_users,
 * sites, portal_users and the rest of the roster below. The framework models them,
 * reads and writes named columns on them, joins them, and - in four places - points a
 * foreign key from one of its OWN tables into them. An application may alter every one
 * of these tables, and is expected to: that is the difference between them and the
 * `_`-prefixed tables the framework owns outright. This class is the declaration of
 * what may NOT be altered away, table by table, with the framework code that reads or
 * writes each column named beside it so a finding can say whose code is about to fail.
 *
 * WHY A SECOND CHECK EXISTS AT ALL. rsx:migrate:check_consistency compares the sealed
 * MANIFEST with the database, in production modes only. The manifest's column map is
 * DERIVED from the database at build time, so a column the framework CODE needs but
 * this schema never had is invisible to it - both sides agree, and the framework still
 * throws on the first request that touches the column. This map is the other direction:
 * what the code needs, stated independently of any database, checkable on every box in
 * every mode. Schema_Contract_Health_Checks is the check; this class is the contract.
 *
 * WHAT IS DELIBERATELY NOT HERE:
 *
 *   - Audit pairs (created_by_id/_type, updated_by_id/_type, deleted_by_id/_type) and
 *     created_at/updated_at. migrate:normalize_schema adds them to every table on every
 *     migrate, and Rsx_Model_Abstract skips a pair it does not find, so nothing here
 *     could fail that normalization does not already repair.
 *   - deleted_at, which normalization never adds, IS required - on the four tables that
 *     soft-delete (users, login_users, portal_users, sites), where losing it fatals
 *     every query the model issues.
 *   - Column TYPES, nullability and defaults. Presence is what the framework depends on
 *     and what an ALTER realistically removes; a narrowed type is the province of the
 *     SchemaQuality rules and of check_consistency.
 *   - Columns the framework only DECLARES on a model and never reads (users.phone,
 *     user_profiles.title/department/bio, ip_addresses' geocoding columns). A column
 *     nothing reads cannot break anything by being absent.
 *   - Tables no framework code names. The framework models 12 application-owned tables;
 *     every other non-underscore table in a database belongs to the application alone.
 *
 * EXTENDING IT. A new framework read or write of an application-owned table adds its
 * column here, in the same commit, with the consumer string naming the code that does
 * the reading. That string is the whole value of the map: an operator who sees
 * "users.login_user_id missing (Session::get_user())" knows it is the framework, not
 * their own feature, that will stop working. A new framework migration that CREATES the
 * column names itself in 'migration' so the remediation can point at it.
 *
 * @see rsx:man database_schema_architecture
 * @see rsx:man health
 */
class Schema_Contract
{
    /**
     * The per-table contract.
     *
     * Each entry:
     *   'migration' => the framework migration that created the table (remediation)
     *   'columns'   => column => ['where' => consumer, 'migration' => ?creating migration]
     *   'unique'    => list of ['columns' => [...], 'name' => conventional index name,
     *                  'why' => what losing uniqueness silently does]
     *
     * @return array<string, array>
     */
    public static function tables(): array
    {
        return [
            // -----------------------------------------------------------------
            // Identity
            // -----------------------------------------------------------------
            'users' => [
                'migration' => '2025_09_03_054826_create_users_table',
                'columns' => [
                    'id' => ['where' => 'Rsx_Initial_User::create(), Session::get_user()'],
                    'login_user_id' => [
                        'where' => 'Session::get_user(), Rsx_Api_Bearer::resolve()',
                        'migration' => '2025_11_04_051828 (site_users refactor)',
                    ],
                    'site_id' => ['where' => 'Site_Scoped global scope - injected into every query'],
                    'first_name' => ['where' => 'User_Model::get_full_name(), REALTIME_REFRESH_FIELDS'],
                    'last_name' => ['where' => 'User_Model::get_full_name(), REALTIME_REFRESH_FIELDS'],
                    'role_id' => [
                        'where' => 'User_Model::has_role()/has_permission(), Permission checks',
                        'migration' => '2025_11_23_164439_reindex_user_roles_to_100_based',
                    ],
                    'is_enabled' => ['where' => 'RsxAuth::has_enabled_membership(), Rsx_Api_Bearer, User_Model::is_active()'],
                    'email' => ['where' => 'Rsx_Initial_User::create(), Identity_Api_Controller'],
                    'is_api_access_enabled' => [
                        'where' => 'Session::has_api_access(), Rsx_Api_Bearer - the sole API predicate',
                        'migration' => '2026_08_25_225510_add_is_api_access_enabled_to_users_table',
                    ],
                    'invite_code' => [
                        'where' => 'User_Model::fetch() (redacted to a prefix)',
                        'migration' => '2025_11_04_181832 (invite columns)',
                    ],
                    'invite_accepted_at' => [
                        'where' => 'User_Model::get_invitation_status()',
                        'migration' => '2025_11_04_181832 (invite columns)',
                    ],
                    'invite_expires_at' => [
                        'where' => 'User_Model::get_invitation_status()',
                        'migration' => '2025_11_04_181832 (invite columns)',
                    ],
                    'deleted_at' => ['where' => 'SoftDeletes - every query the model issues'],
                ],
                'unique' => [
                    [
                        'columns' => ['login_user_id', 'site_id'],
                        'name' => 'uk_users_login_user_site',
                        'why' => 'Session::get_user() first()s on that pair, so a duplicate makes the'
                            . ' identity a request resolves to non-deterministic',
                    ],
                    [
                        'columns' => ['invite_code'],
                        'name' => 'uk_users_invite_code',
                        'why' => 'an invite code must address one membership and no other',
                    ],
                ],
            ],

            'login_users' => [
                'migration' => '2025_11_04_051746_create_login_users_table',
                'columns' => [
                    'id' => ['where' => 'Session, RsxAuth, Rsx_Two_Factor, Rsx_Sso; FK target of _sso_identities and _two_factor_credentials'],
                    'email' => ['where' => 'Login_User_Model::find_by_email(), RsxAuth::attempt() - sign-in resolves on it'],
                    'password' => ['where' => 'RsxAuth::attempt() Hash::check'],
                    'is_activated' => ['where' => 'Login_User_Model::is_active()'],
                    'is_verified' => ['where' => 'Login_User_Model::is_active()'],
                    'status_id' => ['where' => 'Login_User_Model::is_active(), Rsx_Initial_User::create()'],
                    'timezone' => [
                        'where' => 'Rsx_Time::get_user_timezone()/set_user_timezone()',
                        'migration' => '2025_12_24_210213 (login_users.timezone)',
                    ],
                    'timezone_auto' => [
                        'where' => 'Rsx_Bundle_Abstract page payload, Rsx_Time::set_user_timezone()',
                        'migration' => '2026_08_18_145643 (login_users.timezone_auto)',
                    ],
                    'dark_mode' => [
                        'where' => 'Rsx_Dark_Mode::get_preference()/set_preference()',
                        'migration' => '2026_08_27_105609 (login_users.dark_mode)',
                    ],
                    'remember_token' => ['where' => "Laravel's Authenticatable trait"],
                    'last_login' => ['where' => 'Session::set_login_user_id() on every successful sign-in'],
                    'is_developer' => [
                        'where' => 'Session::is_developer(), rsxapp.is_developer',
                        'migration' => '2026_09_17_160704_add_is_developer_to_login_users',
                    ],
                    'deleted_at' => [
                        'where' => 'SoftDeletes - every query the model issues; ACTOR-01 requires it',
                        'migration' => '2026_08_09_112232 (deleted_at on the actor tables)',
                    ],
                ],
                'unique' => [
                    [
                        'columns' => ['email'],
                        'name' => 'uk_login_users_email',
                        'why' => 'find_by_email() and RsxAuth::attempt() first() on it, so losing uniqueness'
                            . ' makes WHICH identity signs in non-deterministic - silent and security-relevant',
                    ],
                ],
            ],

            'sites' => [
                'migration' => '2025_09_03_054656_create_sites_table',
                'columns' => [
                    'id' => ['where' => 'Site_Scoped, Session::get_site_id(), Portal_Session; FK target of 15+ tables'],
                    'slug' => ['where' => 'Site_Model::find_by_slug()'],
                    'name' => ['where' => 'Site_Model display, rsx:file:info'],
                    'timezone' => [
                        'where' => 'Rsx_Time site-default timezone resolution',
                        'migration' => '2026_01_12_073624 (sites.timezone)',
                    ],
                    'is_enabled' => ['where' => 'Site_Model::is_active()'],
                    'deleted_at' => ['where' => 'SoftDeletes - every query the model issues'],
                ],
                'unique' => [
                    [
                        'columns' => ['slug'],
                        'name' => 'uk_sites_slug',
                        'why' => 'a slug must address one tenant and no other',
                    ],
                ],
            ],

            'portal_users' => [
                'migration' => '2026_01_29_180540_create_portal_users_table',
                'columns' => [
                    'id' => ['where' => 'Portal_Session; FK target of _sessions.portal_user_id'],
                    'site_id' => ['where' => 'Portal_Session::get_site_id() scoping'],
                    'email' => ['where' => 'Portal_User_Model::find_by_email() - stored lower-cased and trimmed'],
                    'password' => ['where' => 'the portal sign-in Hash::check'],
                    'is_verified' => ['where' => 'Portal_User_Model::is_active()'],
                    'status_id' => ['where' => 'Portal_User_Model::is_active()'],
                    'metadata' => ['where' => 'Portal_User_Model JSON attribute'],
                    'last_login' => ['where' => 'Portal_Session on every portal sign-in'],
                    'deleted_at' => [
                        'where' => 'SoftDeletes - every query the model issues; ACTOR-01 requires it',
                        'migration' => '2026_08_09_112232 (deleted_at on the actor tables)',
                    ],
                ],
                'unique' => [
                    [
                        'columns' => ['site_id', 'email'],
                        'name' => 'uk_portal_users_site_email',
                        'why' => 'a portal address must address one account per tenant and no other',
                    ],
                ],
            ],

            // -----------------------------------------------------------------
            // Authorization and identity satellites
            // -----------------------------------------------------------------
            'user_permissions' => [
                'migration' => '2025_11_23_160855_create_user_permissions_table',
                'columns' => [
                    'user_id' => ['where' => 'Permission ACL resolution - a users.id, not a login_users.id'],
                    'permission_id' => ['where' => 'Permission ACL resolution (PERM_* ids)'],
                    'is_grant' => ['where' => 'Permission ACL resolution - false is a DENY and DENY wins'],
                ],
                'unique' => [
                    [
                        'columns' => ['user_id', 'permission_id'],
                        'name' => 'uk_user_permission',
                        'why' => 'grant and deny are written delete-then-insert, so a duplicate leaves a'
                            . ' stale row deciding an access question',
                    ],
                ],
            ],

            'user_profiles' => [
                'migration' => '2025_11_04_011050_create_user_profiles_table',
                'columns' => [
                    'user_id' => ['where' => 'User_Model::profile() hasOne'],
                    'site_id' => [
                        'where' => 'Site_Scoped global scope',
                        'migration' => '2026_09_09_105542 (user_profiles.site_id)',
                    ],
                ],
                'unique' => [],
            ],

            'user_invites' => [
                'migration' => '2025_09_03_054951_create_user_invites_table',
                'columns' => [
                    'user_id' => ['where' => 'Login_User_Model::invites() - holds a login_users.id despite the name'],
                    'site_id' => ['where' => 'the tenant the invitation is into'],
                    'invite_code' => ['where' => 'invitation redemption (never_export)'],
                    'expires_at' => ['where' => 'invitation expiry'],
                ],
                'unique' => [],
            ],

            'user_verifications' => [
                'migration' => '2025_09_03_054951_create_user_verifications_table',
                'columns' => [
                    'email' => ['where' => 'Login_User_Model::verifications() - joined on EMAIL, not on a key'],
                    'verification_code' => ['where' => 'verification redemption (never_export)'],
                    'verification_type_id' => ['where' => 'which verification this is'],
                    'verified_at' => ['where' => 'verification state'],
                    'expires_at' => ['where' => 'verification expiry'],
                ],
                'unique' => [],
            ],

            // -----------------------------------------------------------------
            // Geography - the two Select inputs 500 without them
            // -----------------------------------------------------------------
            'countries' => [
                'migration' => '2025_10_28_223500_create_geographic_data_tables',
                'columns' => [
                    'alpha2' => ['where' => 'Select_Country_Input; FK target of regions.country_alpha2'],
                    'alpha3' => ['where' => 'Country_Model'],
                    'name' => ['where' => 'Select_Country_Input option label'],
                    'common_name' => ['where' => 'Select_Country_Input option label'],
                    'enabled' => ['where' => 'Select_Country_Input option filter'],
                ],
                'unique' => [],
            ],

            'regions' => [
                'migration' => '2025_10_28_223500_create_geographic_data_tables',
                'columns' => [
                    'code' => ['where' => 'Select_State_Input value'],
                    'country_alpha2' => ['where' => 'Select_State_Input country filter'],
                    'name' => ['where' => 'Select_State_Input option label'],
                    'type' => ['where' => 'Region_Model'],
                    'enabled' => ['where' => 'Select_State_Input option filter'],
                ],
                'unique' => [],
            ],

            'ip_addresses' => [
                'migration' => '2025_09_03_054950_create_ip_addresses_table',
                'columns' => [
                    'id' => ['where' => '_sessions.login_ip_address_id / last_ip_address_id point at it'],
                    'ip_address' => ['where' => 'Ip_Address_Model::find_or_create()'],
                ],
                'unique' => [],
            ],

            // -----------------------------------------------------------------
            // Portal notifications
            // -----------------------------------------------------------------
            'portal_notifications' => [
                'migration' => '2026_06_23_035931_create_portal_notifications_table',
                'columns' => [
                    'site_id' => ['where' => 'Portal_Notification_Model tenant scope'],
                    'portal_user_id' => ['where' => 'Portal_Notification_Model::emit() recipient'],
                    'type' => ['where' => 'Portal_Notification_Model payload'],
                    'subject_type' => ['where' => 'the polymorphic subject of the notification'],
                    'subject_id' => ['where' => 'the polymorphic subject of the notification'],
                    'payload' => ['where' => 'Portal_Notification_Model payload'],
                    'read_at' => ['where' => 'the unread count the portal renders'],
                ],
                'unique' => [],
            ],
        ];
    }

    /**
     * The foreign keys the framework relies on.
     *
     * The four from FRAMEWORK-owned (`_`-prefixed) tables INTO application-owned tables
     * are the strongest form of this contract: the framework's own storage cannot be
     * written at all if the target is gone. The rest are the application-owned
     * references the framework's own models traverse.
     *
     * Matching is by (table, column) -> (referenced_table, referenced_column); the
     * constraint NAME is not part of the contract, because renaming one breaks nothing.
     *
     * @return array<int, array{table: string, column: string, references: string, referenced_column: string, why: string}>
     */
    public static function foreign_keys(): array
    {
        return [
            // Framework tables pointing into application-owned tables.
            [
                'table' => '_file_attachments',
                'column' => 'site_id',
                'references' => 'sites',
                'referenced_column' => 'id',
                'why' => 'every uploaded file is scoped to a tenant by the framework, not by the app',
            ],
            [
                'table' => '_sessions',
                'column' => 'portal_user_id',
                'references' => 'portal_users',
                'referenced_column' => 'id',
                'why' => 'the one session row carries the portal identity',
            ],
            [
                'table' => '_sso_identities',
                'column' => 'login_user_id',
                'references' => 'login_users',
                'referenced_column' => 'id',
                'why' => 'a federated sign-in is linked to a login identity',
            ],
            [
                'table' => '_two_factor_credentials',
                'column' => 'login_user_id',
                'references' => 'login_users',
                'referenced_column' => 'id',
                'why' => 'a second factor belongs to a login identity',
            ],

            // Application-owned references the framework's own models traverse.
            [
                'table' => 'users',
                'column' => 'login_user_id',
                'references' => 'login_users',
                'referenced_column' => 'id',
                'why' => 'Session::get_user() resolves the membership from the signed-in identity',
            ],
            [
                'table' => 'users',
                'column' => 'site_id',
                'references' => 'sites',
                'referenced_column' => 'id',
                'why' => 'Site_Scoped requires users.site_id to be a real tenant',
            ],
            [
                'table' => 'user_permissions',
                'column' => 'user_id',
                'references' => 'users',
                'referenced_column' => 'id',
                'why' => 'an ACL row that outlives its membership grants access to nobody and denies to nobody',
            ],
            [
                'table' => 'user_profiles',
                'column' => 'user_id',
                'references' => 'users',
                'referenced_column' => 'id',
                'why' => 'User_Model::profile() hasOne',
            ],
            [
                'table' => 'user_invites',
                'column' => 'user_id',
                'references' => 'login_users',
                'referenced_column' => 'id',
                'why' => 'the column holds a login_users id despite the name',
            ],
            [
                'table' => 'portal_users',
                'column' => 'site_id',
                'references' => 'sites',
                'referenced_column' => 'id',
                'why' => 'a portal account belongs to exactly one tenant',
            ],
            [
                'table' => 'portal_notifications',
                'column' => 'portal_user_id',
                'references' => 'portal_users',
                'referenced_column' => 'id',
                'why' => 'a notification belongs to one portal account',
            ],
            [
                'table' => 'regions',
                'column' => 'country_alpha2',
                'references' => 'countries',
                'referenced_column' => 'alpha2',
                'why' => 'Select_State_Input filters regions by country',
            ],
        ];
    }

    /**
     * The rows the framework assumes exist.
     *
     * Two of them, and both are structural rather than data: site 0 is the SESSIONLESS
     * site (Site_Scoped::get_current_site_id() returns 0 with no session, and
     * Site_Model::booted() refuses to delete it), and the configured default site is
     * the tenant a first sign-in lands on. The initial user pair is checked only when
     * login_users has any row at all - a database that has never been seeded is a
     * fresh install, not a broken one.
     *
     * @return array<int, array{label: string, table: string, where: array<string, int>, only_if_any: ?string, why: string, remediation: string}>
     */
    public static function rows(): array
    {
        $default_site_id = (int) config('multi-tenant.default_site_id', 1);

        $rows = [
            [
                'label' => 'sites id 0',
                'table' => 'sites',
                'where' => ['id' => 0],
                'only_if_any' => null,
                'why' => 'the sessionless site: Site_Scoped::get_current_site_id() returns 0 when no session'
                    . ' has declared a tenant, and Site_Model::booted() refuses to delete it',
                'remediation' => 'restore it with a migration (the framework created it in'
                    . ' 2026_07_30_121017_seed_default_site, INSERT INTO sites (id, slug, name) VALUES (0, ...))',
            ],
            [
                'label' => 'sites id ' . $default_site_id . ' (multi-tenant.default_site_id)',
                'table' => 'sites',
                'where' => ['id' => $default_site_id],
                'only_if_any' => null,
                'why' => 'the default tenant a sign-in with no other membership lands on; with it absent'
                    . ' the framework falls back to MIN(id) > 0 and throws when there is none',
                'remediation' => 'create the tenant, or point DEFAULT_SITE_ID at the tenant this install uses',
            ],
            [
                'label' => 'initial user login_users 1',
                'table' => 'login_users',
                'where' => ['id' => 1],
                'only_if_any' => 'login_users',
                'why' => 'Rsx_Initial_User::INITIAL_USER_ID - the first-run screen, the post-migrate step'
                    . ' and the test baseline all assign id 1 explicitly',
                'remediation' => 'restore it with a migration, or seed a fresh install through'
                    . ' Rsx_Initial_User::create()',
            ],
            [
                'label' => 'initial user users 1',
                'table' => 'users',
                'where' => ['id' => 1],
                'only_if_any' => 'login_users',
                'why' => 'the membership half of the initial user pair (Rsx_Test_Command BASELINE_USER_ID)',
                'remediation' => 'restore it with a migration, or seed a fresh install through'
                    . ' Rsx_Initial_User::create()',
            ],
        ];

        return $rows;
    }

    /**
     * The semantic probes - things that are structurally present and still wrong.
     *
     * Every one of these is a WARN and never a FAIL: each describes a value the
     * framework coerces or tolerates rather than a shape it cannot run against, and
     * rsx:health's exit code gates deploys. They exist because each one is SILENT -
     * a NULL is_grant is a permission quietly lost, a NULL is_api_access_enabled is an
     * API quietly opened.
     *
     * Two kinds:
     *   'enum'  - a PHP-side assertion about a model's $enums declaration
     *   'count' - a COUNT(*) over a table with a framework-authored predicate; a
     *             non-zero count is the finding
     *
     * @return array<int, array{key: string, kind: string, label: string, table: ?string, predicate: ?string, why: string, remediation: string}>
     */
    public static function probes(): array
    {
        return [
            [
                'key' => 'role_enum',
                'kind' => 'enum',
                'label' => 'users.role_id enum',
                'table' => null,
                'predicate' => null,
                'why' => "User_Model::\$enums['role_id'] must be non-empty and every entry must carry"
                    . ' permissions and can_admin_roles: has_permission() reads them, and'
                    . ' Rsx_Test_Abstract::most_privileged_role_id() shouldnt_happen()s on an empty enum',
                'remediation' => 'declare the roles on the User_Model override (rsx:man acls)',
            ],
            [
                'key' => 'dark_mode_range',
                'kind' => 'count',
                'label' => 'login_users.dark_mode range',
                'table' => 'login_users',
                'predicate' => 'dark_mode IS NULL OR dark_mode NOT IN (0, 1, 2)',
                'why' => 'Rsx_Dark_Mode coerces anything outside {0,1,2} to AUTO, so an out-of-range'
                    . ' value is a preference the user set and never gets back',
                'remediation' => 'UPDATE login_users SET dark_mode = 0 WHERE dark_mode IS NULL'
                    . ' OR dark_mode NOT IN (0, 1, 2)',
            ],
            [
                'key' => 'api_access_null',
                'kind' => 'count',
                'label' => 'users.is_api_access_enabled',
                'table' => 'users',
                'predicate' => 'is_api_access_enabled IS NULL',
                'why' => 'it is the sole external-API predicate and its default is 1, so a NULL is an'
                    . ' identity whose API access nobody decided',
                'remediation' => 'UPDATE users SET is_api_access_enabled = 1 WHERE is_api_access_enabled IS NULL'
                    . ' (or 0 to close it), then decide the value deliberately',
            ],
            [
                'key' => 'is_grant_null',
                'kind' => 'count',
                'label' => 'user_permissions.is_grant',
                'table' => 'user_permissions',
                'predicate' => 'is_grant IS NULL',
                'why' => 'a NULL reads as a DENY and DENY wins, so it is a permission silently lost',
                'remediation' => 'decide each row: UPDATE user_permissions SET is_grant = 1 (grant) or 0 (deny)'
                    . ' WHERE is_grant IS NULL',
            ],
        ];
    }
}
