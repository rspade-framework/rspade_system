# Test catalog: acl

Status legend: `implemented` | `deferred` (reason) | `blocked` (see issues) | `planned`.
Type: php / cli / asset / http / playwright. Last updated: 2026-10-05.

## User_Permission_Cache_Test (php, default isolation) - mutations seen by a loaded instance

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| ACL-CACHE-01 | grant() is visible on an instance that already cached | role lacking P, has_permission, grant P | true on the same instance | implemented |
| ACL-CACHE-02 | deny() is visible on an instance that already cached | role having P, has_permission, deny P | false on the same instance | implemented |
| ACL-CACHE-03 | remove() is visible on an instance that already cached | granted P, has_permission, remove P | false on the same instance | implemented |

## Acl_Administration_Api_Test (php, default isolation) - the administration API

Each read is checked against has_permission() for every catalogue permission, never against a
hand-written expectation.

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| ACL-ADMIN-01 | the catalogue defines every permission any role grants, each with label and description and a matching PERM_* constant | permission_definitions(), role_id__enum() | all present; unknown id: permission_exists false, permission_label throws | implemented |
| ACL-ADMIN-02 | grant() and deny() refuse an id outside the catalogue and write nothing | unknown id | Rsx_Caller_Exception x2; no rows | implemented |
| ACL-ADMIN-03 | the breakdown's from_role / override / effective, and effective == has_permission(); a preloaded for_users() entry gives the same result | deny a role permission, grant an outside one | rows as expected; equal breakdowns | implemented |
| ACL-ADMIN-04 | for_users() returns every requested id, empty lists for none, equal to for_user() | two users, one with rows | keyed result; [] for an empty request | implemented |
| ACL-ADMIN-05 | remove_all() reverts to role defaults, visible on a loaded instance | one deny + one grant | 2 removed; role default back; then 0 | implemented |
| ACL-ADMIN-06 | withEffectivePermission() lists exactly the users has_permission() is true for | by-role, denied, granted users x every permission | identical id sets; unknown id throws | implemented |
