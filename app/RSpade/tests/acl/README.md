# Concern: acl

## Domain overview & applicability

The per-user ACL layer beneath the role hierarchy: `_user_permissions` GRANT/DENY rows that
add to or remove from the permissions a user's role grants by default, with DENY winning.
`User_Permission_Model` is the write and read API (`grant()`, `deny()`, `remove()`,
`remove_all()`, `for_user()`, `for_users()`); `User_Model` resolves the effective set
(`get_resolved_permissions()`, `has_permission()`), owns the permission catalogue
(`$permission_definitions`, `permission_definitions()`, `permission_exists()`,
`permission_label()`), explains one user's position per permission
(`get_permission_breakdown()`) and lists the holders of a permission
(`withEffectivePermission()`). Together they are the API an ACL administration screen is
written against.

Roles and permissions are application vocabulary, so every test derives its roles and
permissions from `User_Model`'s own `$enums` and catalogue at runtime.

Adjacent pieces this concern leans on but does not own: the `Permission` facade and its
`#[Auth_Check]` gates (`auth_gates`), and the realtime user-refresh push an ACL change sends
(`realtime`).

## Source files

- `app/RSpade/Core/Models/User_Permission_Model_Abstract.php` - the ACL rows and their API
- `app/RSpade/Core/Models/User_Model_Abstract.php` - the catalogue, resolution, breakdown, scope

## Man pages

- `rsx:man acls`

## Tests

- `php/User_Permission_Cache_Test.php` - grant/deny/remove visible on an already-loaded instance
- `php/Acl_Administration_Api_Test.php` - the administration API agrees with has_permission()
