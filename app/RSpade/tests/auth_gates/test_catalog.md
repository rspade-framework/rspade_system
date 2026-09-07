# auth_gates - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| AG-IDX-01 | `#[Auth('a','b')]` variadic arguments are collected in order | php | Synthetic route method with two-argument `Auth` | surface auth == `['alpha','beta']`, kind route, realm staff | implemented | 2026-08-07 |
| AG-IDX-02 | Class-level gates precede method-level; repeats de-duplicate | php | Class `Auth('is_logged_in','shared')` + method `Auth('shared','can_edit')` | `['is_logged_in','shared','can_edit']` | implemented | 2026-08-07 |
| AG-IDX-03 | A repeated `#[Auth]` on one declaration merges rather than dropping | php | Two `Auth` instances on one method | union, order preserved | implemented | 2026-08-07 |
| AG-IDX-04 | A non-string `#[Auth]` argument fails the build | php | `Auth(42)` | RuntimeException "must be a non-empty check-name string" | implemented | 2026-08-07 |
| AG-IDX-05 | Every surface attribute maps to its kind and realm | php | One class carrying all seven surface attributes + a fetchable relationship | route/spa/api/model_fetch staff, portal_route/portal_fetch portal, ajax/relationship any | implemented | 2026-08-07 |
| AG-REG-01 | Marked checks register into their own realm only | php | One staff-lineage and one portal-lineage fixture class | each name in its realm, absent from the other | implemented | 2026-08-07 |
| AG-REG-02 | Most-derived declaration wins within a lineage | php | Base + subclass both declaring one marked check | registry resolves the subclass | implemented | 2026-08-07 |
| AG-REG-03 | One name on two unrelated branches is a collision | php | Two sibling classes declaring the same check | RuntimeException "Duplicate auth check name" | implemented | 2026-08-07 |
| AG-REG-04 | A check may not take parameters | php | Marked method with one parameter | RuntimeException "takes NO PARAMETERS" | implemented | 2026-08-07 |
| AG-REG-05 | A check must declare `: bool` | php | Marked method with no return type | RuntimeException "must declare a ': bool' return type" | implemented | 2026-08-07 |
| AG-REG-06 | `: ?bool` is not a bool declaration | php | Marked method, nullable bool | same RuntimeException | implemented | 2026-08-07 |
| AG-REG-07 | A check must be static | php | Marked public instance method | RuntimeException "must be a PUBLIC STATIC method" | implemented | 2026-08-07 |
| AG-REG-08 | An unmarked override would shadow a marked check silently | php | Subclass redeclaring a marked check without the marker | RuntimeException "Unmarked override of auth check" | implemented | 2026-08-07 |
| AG-LIVE-01 | The support module is registered and the index is built | php | Persisted manifest | `checks` + `surfaces` present, surfaces non-empty | implemented | 2026-08-07 |
| AG-LIVE-02 | Built-ins exist in both realms | php | Persisted manifest | `public` and `is_logged_in` in staff and portal | implemented | 2026-08-07 |
| AG-LIVE-03 | Staff built-ins resolve to `Permission_Abstract` | php | Persisted manifest | declaring class is the framework base | implemented | 2026-08-07 |
| AG-LIVE-04 | Most-derived-wins against real code | php | Persisted manifest | portal `is_logged_in` resolves to `Rsx\Portal_Permission` | implemented | 2026-08-07 |
| AG-LIVE-05 | The engine reaches the live registry | php | No test index installed | `evaluate('public')` true in both realms | implemented | 2026-08-07 |
| AG-LIVE-06 | A real declaration's class+method gates merge in the persisted index | php | `Auth_Gates_Surface_Fixture` | `['is_logged_in','public']` and `['is_logged_in']` | implemented | 2026-08-07 |
| AG-LIVE-07 | Real surfaces of each PHP kind are indexed with the right realm | php | Persisted manifest | route/spa staff, ajax any, model fetch staff | implemented | 2026-08-07 |
| AG-LIVE-08 | Route rows and the surface index agree on every gate list | php | Every `type=standard` route row | `route['auth']` equals the surface's `auth` | implemented | 2026-08-07 |
| AG-ROOT-01 | `is_sysadmin` is in the staff registry, resolving to `Permission_Abstract` | php | Live registry | present, class + method match | implemented | 2026-09-07 |
| AG-ROOT-02 | `is_sysadmin` is staff-only - the control panel is not a portal surface | php | Persisted manifest | in `checks.staff`, absent from `checks.portal` | implemented | 2026-09-07 |
| AG-ROOT-03 | It evaluates staff-side; naming it from portal is the unknown-name failure | php | `evaluate()` in both realms | false, then RuntimeException | implemented | 2026-09-07 |
| AG-ROOT-04 | The generated staff JS mirror exports it, so `can_access()` answers for a panel link | php | Generated mirror stub | contains `Permission.is_sysadmin` | implemented | 2026-09-07 |
| AG-EVAL-01 | Only an exact `true` grants | php | Bodies returning true/false/1/'true'/nothing | only the first grants | implemented | 2026-08-07 |
| AG-EVAL-02 | AND semantics; an empty gate list passes | php | `[]`, `[grant]`, `[grant,deny]`, `[deny,grant]` | true, true, false, false | implemented | 2026-08-07 |
| AG-EVAL-03 | A check body runs LIVE on every ask - no cache between asks | php | Four staff consultations then one portal | counter 1,2,3,4 then 5 | implemented | 2026-08-31 |
| AG-EVAL-06 | Evaluation follows the IDENTITY that is asking, in one process | php | Identity-dependent check asked as a denied user, then as the granted user (`Session::_set_api_identity()` swap) | false, then true - and the grants export moves with it | implemented | 2026-08-31 |
| AG-EVAL-04 | An unknown name throws and lists the realm's defined names | php | Misspelled check | RuntimeException naming the realm + the sorted name list | implemented | 2026-08-07 |
| AG-EVAL-05 | Realms are separate namespaces | php | A staff-only name evaluated in portal | RuntimeException "in the portal realm" | implemented | 2026-08-07 |
| AG-CA-01 | `can_access` on a route target | php | Gated open vs gated closed surfaces | true / false | implemented | 2026-08-07 |
| AG-CA-02 | A bare controller name implies `::index` | php | `'Eval_Open_Controller'` | resolves to `::index` | implemented | 2026-08-07 |
| AG-CA-03 | `can_access` on a SPA action target | php | Action class names | true / false | implemented | 2026-08-07 |
| AG-CA-04 | An ungated surface is reachable pre-validation | php | Surface with `auth == []` | true | implemented | 2026-08-07 |
| AG-CA-05 | A realm-agnostic surface evaluates in the caller's realm | php | `'any'` surface, one gate name with opposite bodies per realm | true staff / false portal | implemented | 2026-08-07 |
| AG-CA-06 | An unknown target throws | php | Nonexistent target | RuntimeException "Unknown auth target" | implemented | 2026-08-07 |
| AG-CA-07 | A cross-realm target throws | php | Portal target from the staff realm | RuntimeException "belongs to the portal realm" | implemented | 2026-08-07 |
| AG-SEAM-01 | The matched route carries its gate list into the dispatcher | php | Routed fixture (`/_test/auth-gates/gated`, `/_test/auth-gates/open`) | `auth` == `['is_logged_in']` / `['public']` | implemented | 2026-08-07 |
| AG-SEAM-02 | A passing route gate dispatches the action unchanged | php | Gated fixture route, acting as a user | 200 + the fixture marker | implemented | 2026-08-07 |
| AG-SEAM-03 | A denied ANONYMOUS route caller is sent to login | php | Gated fixture route, no session | 302 to /login | implemented | 2026-08-07 |
| AG-SEAM-04 | A denied AUTHENTICATED route caller gets 403 | php | The shared unauthorized channel the seam feeds | 403, not a redirect | implemented (dispatch concern: `Dispatcher_Auth_Rejection_Test::test_unauthorized_logged_in_is_403_not_redirect`) | 2026-08-07 |
| AG-SEAM-05 | The portal route row carries its gate list | php | `#[Portal_Route]` fixture | `auth` == `['is_logged_in']` | implemented | 2026-08-07 |
| AG-SEAM-06 | A denied anonymous portal caller lands on the PORTAL login | php | Gated portal fixture route, no portal session | 302 to the portal login route | implemented | 2026-08-07 |
| AG-SEAM-07 | Ajax internal() denies before the endpoint body runs | php | Fixture endpoint, denying gate via the test index | AjaxUnauthorizedException | implemented | 2026-08-07 |
| AG-SEAM-08 | Ajax internal() runs the body when the gates pass | php | Same, granting gate | the endpoint's marker | implemented | 2026-08-07 |
| AG-SEAM-09 | Ajax browser entry denies with the coded unauthorized error | php | `handle_browser_request` on a denied endpoint | `_success` false, `error_code` unauthorized | implemented | 2026-08-07 |
| AG-SEAM-10 | Ajax browser entry runs the body when the gates pass | php | Same, granting gate | `_success` true | implemented | 2026-08-07 |
| AG-SEAM-11 | An unknown check name DENIES at a seam and logs one warning | php | Endpoint gated on a name defined in no realm | coded unauthorized + a warning naming surface, check and realm | implemented | 2026-08-07 |
| AG-SEAM-12 | ORM fetch denial is indistinguishable from a missing row | php | Denied fetch vs an absent id | identical code + message ("Record not found") | implemented | 2026-08-07 |
| AG-SEAM-13 | ORM fetch denial honors or_null | php | Denied fetch with `or_null` | null (what an absent record gives) | implemented | 2026-08-07 |
| AG-SEAM-14 | A passing ORM gate reaches the model | php | `User_Model::fetch` with a granting gate | the record | implemented | 2026-08-07 |
| AG-SEAM-15 | A relationship is gated on its own list, before the relation runs | php | fetch open, relationship closed | generic not-found | implemented | 2026-08-07 |
| AG-SEAM-16 | Every `#[Api_Endpoint]` row carries a gate list | php | Persisted manifest api rows | every row has `auth` | implemented | 2026-08-07 |
| AG-SEAM-17 | API dispatch denies with a 403 error shape | http | Gated API endpoint, valid bearer key | 403 `{"error":{"code":"forbidden",...}}` + a 403 log row | verified by live probe; automatable once a permanently gated API endpoint exists (W5/W6) | 2026-08-07 |
| AG-SEAM-18 | An empty gate list passes at a seam (transition contract) | php | `gates_pass_at_seam([], realm)` | true in both realms | implemented | 2026-08-07 |
| AG-EXPORT-01 | `export_grants()` ships granted names only, value 1 | php | Fixture registry with granting and denying bodies | granted keys only; no `=> false` entry for a denied check | implemented | 2026-08-07 |
| AG-EXPORT-02 | The grants map is realm-scoped | php | One name with opposite bodies per realm | present for staff, absent for portal | implemented | 2026-08-07 |
| AG-EXPORT-03 | Building the map re-evaluates live on every build | php | Seam evaluation, then two export builds | check body executed 1, 2, 3 times | implemented | 2026-08-31 |
| AG-EXPORT-04 | `export_route_grants()` covers PAGE surfaces only | php | route/spa/portal_route vs ajax/model_fetch surfaces | pages present, ajax + model absent | implemented | 2026-08-07 |
| AG-EXPORT-05 | A denied page surface is omitted (grants only) | php | Surface gated on a denying check | absent from the map | implemented | 2026-08-07 |
| AG-EXPORT-06 | A GATELESS page surface is excluded | php | Surface with `auth == []` | absent - absence keeps the map honest during the transition | implemented | 2026-08-07 |
| AG-EXPORT-07 | A gate name unknown in the realm denies rather than throwing | php | Surface gated on an undefined name | absent + one Log::warning | implemented | 2026-08-07 |
| AG-EXPORT-08 | The route-grants map is realm-scoped | php | Staff and portal page surfaces | neither realm's pages leak into the other | implemented | 2026-08-07 |
| AG-EXPORT-09 | `rsx.auth.export_php_route_grants` defaults off | php | Framework config | false, so `rsxapp.auth_routes` is never defined | implemented | 2026-08-07 |
| AG-CLIENT-01 | `rsxapp.auth` ships grants only | playwright | Rendered page as a role-limited user | denied names absent, not `false` | shipped; verified by rsx:debug probe (staff `{is_logged_in,public}`, anonymous `{public}`), playwright automation pending | 2026-08-07 |
| AG-CLIENT-02 | `@auth` on a SPA action gates `Spa.dispatch` | playwright | Denied action URL | unauthorized body in the layout content area, action never constructs, URL/history untouched | shipped; verified by rsx:debug probe, playwright automation pending (re-do against Error_Screens once W4 lands) | 2026-08-07 |
| AG-CLIENT-03 | `Permission.can_access` matches the PHP answer | playwright | Action + `::` targets | same verdicts; `::` without the opt-in export logs a console error naming `rsx.auth.export_php_route_grants` and returns false | shipped; verified by rsx:debug probe, playwright automation pending | 2026-08-07 |
| AG-CLIENT-04 | Generated JS mirrors attach to the Permission classes | playwright | `Permission.public()` on a rendered page | true, and hand-written `is_logged_in` is NOT overwritten | shipped; verified by rsx:debug probe, playwright automation pending | 2026-08-07 |
| AG-VALID-01 | A gateless surface fails the build | php | Synthetic surface with `auth == []` | RuntimeException naming file + member | implemented | 2026-08-07 |
| AG-VALID-02 | The missing-gate message carries the full remediation | php | Same | the exact `#[Auth('...')]` syntax, both Permission file paths, the realm's defined names, the man-page pointer | implemented | 2026-08-07 |
| AG-VALID-03 | A gateless JS action reports the decorator spelling | php | Synthetic `js_action` surface | `@auth('...')` on the action class, beside `@route` | implemented | 2026-08-07 |
| AG-VALID-04 | Every real `@route` action carries `@auth` | php | Built index | no gateless js_action/portal_js_action surface exists | implemented | 2026-08-07 |
| AG-VALID-05 | An unknown check name fails the build | php | `#[Auth('val_nonexistent')]` | RuntimeException quoting the name + the realm + the realm's defined names | implemented | 2026-08-07 |
| AG-VALID-06 | Realms never blur: a portal-only name on a staff surface is unknown | php | Staff surface gated on a portal-only name | RuntimeException | implemented | 2026-08-07 |
| AG-VALID-07 | An `any` surface accepts a name defined in ONE realm | php | `model_relationship` gated on a portal-only name; then on a nowhere-defined name | passes; then fails | implemented | 2026-08-07 |
| AG-VALID-08 | A class-level restricting gate plus member-level `public` is contradictory | php | Class `#[Auth('is_logged_in')]`, member `#[Auth('public')]` | RuntimeException explaining that gates AND | implemented | 2026-08-07 |
| AG-VALID-09 | A class-level `public` plus member-level `public` is NOT contradictory | php | Both `public` | no violation (redundant, not contradictory) | implemented | 2026-08-07 |
| AG-VALID-10 | Every finding rides in ONE numbered exception | php | Three surfaces, three different findings | `3 violations`, `[1]`/`[2]`/`[3]`, all three members named | implemented | 2026-08-07 |
| AG-VALID-11 | A portal surface is offered the PORTAL registry's floor check | php | Gateless portal_route surface | message states the portal realm and suggests its `is_logged_in` | implemented | 2026-08-07 |
| AG-VALID-12 | The live application index validates | php | Persisted manifest index | no violation - the regression guard for the flip | implemented | 2026-08-07 |
