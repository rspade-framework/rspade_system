# api - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| SCAN-VALID-BAKE | a valid #[Api_Endpoint] bakes into routes + api_endpoints | php | synthetic manifest_data | routes[pattern] type=api, version=1, path_key, catalog entry present | implemented | 2026-07-23 |
| SCAN-VALID-PARAMS | declared #[Api_Param] specs bake onto the route | php | endpoint + :id param | api_params has name/type/required | implemented | 2026-07-23 |
| SCAN-BAD-PREFIX | a pattern not under /api/vN/ throws | php | /foo/v1/contacts | RuntimeException "Pattern must match" | implemented | 2026-07-23 |
| SCAN-NO-SEGMENT | /api/vN with no trailing segment throws | php | /api/v1 | RuntimeException "Pattern must match" | implemented | 2026-07-23 |
| SCAN-VERB-PUT | a non GET/POST verb throws | php | methods:[PUT] | RuntimeException "Only GET and POST" | implemented | 2026-07-23 |
| SCAN-EMPTY-METHODS | an empty methods list throws | php | methods:[] | RuntimeException "At least one HTTP verb" | implemented | 2026-07-23 |
| SCAN-MISSING-PARAM | a :token with no #[Api_Param] throws | php | /api/v1/x/:id, no param | RuntimeException "Missing #[Api_Param] for route token ':id'" | implemented | 2026-07-23 |
| SCAN-DUP-PARAM | duplicate #[Api_Param] name throws | php | two params named dup | RuntimeException "Duplicate #[Api_Param] name" | implemented | 2026-07-23 |
| SCAN-REQ-DEFAULT | required:true + default is contradictory | php | required + default | RuntimeException "required:true and also carry a default" | implemented | 2026-07-23 |
| SCAN-BAD-TYPE | a param type outside the scalar set throws | php | type:array | RuntimeException "Type must be one of" | implemented | 2026-07-23 |
| SCAN-UNKNOWN-ARG | an unknown named #[Api_Param] arg throws | php | requred:true | RuntimeException "Unknown #[Api_Param] argument 'requred'" | implemented | 2026-07-23 |
| SCAN-TOOMANY-ARGS | more positional args than the constructor accepts throws | php | 7 positional args | RuntimeException "Too many positional arguments" | implemented | 2026-07-23 |
| SCAN-CONFLICT-ATTR | #[Route] on the same method throws | php | Api_Endpoint + Route | RuntimeException "must not also carry #[Route]" | implemented | 2026-07-23 |
| SCAN-LINEAGE | a class not extending the API base throws | php | extends_fqcn other | RuntimeException "must extend" | implemented | 2026-07-23 |
| SCAN-DUP-ROUTE | a pattern already registered throws | php | seeded routes[pattern] | RuntimeException "Duplicate route definition" | implemented | 2026-07-23 |
| VAL-UNDECLARED | an undeclared key is rejected | php | id + bogus | invalid, fields[bogus] | implemented | 2026-07-23 |
| VAL-REQUIRED | a missing required param is reported | php | empty raw | invalid, fields[id] | implemented | 2026-07-23 |
| VAL-ABSENT-NO-DEFAULT | absent optional with no default stays out of params | php | empty raw | valid, params lacks key | implemented | 2026-07-23 |
| VAL-ABSENT-DEFAULT | absent optional with a default applies it | php | empty raw | params[per_page]=20 | implemented | 2026-07-23 |
| VAL-INT | int coercion matrix | php | '5' '-3' 5 / 'abc' '' '1.5' | ok values / invalid | implemented | 2026-07-23 |
| VAL-FLOAT | float coercion matrix | php | '1.5' 3 2.5 / 'nope' '' | ok values / invalid | implemented | 2026-07-23 |
| VAL-BOOL-TRUE | truthy string tokens -> true | php | 1/true/yes/on (+case) | valid, true | implemented | 2026-07-23 |
| VAL-BOOL-FALSE | falsy string tokens -> false | php | 0/false/no/off (+case) | valid, false | implemented | 2026-07-23 |
| VAL-BOOL-NATIVE | native bool + int 1/0 coerce | php | true/false/1/0 | true/false/true/false | implemented | 2026-07-23 |
| VAL-BOOL-GARBAGE | an unrecognized token is rejected | php | 'maybe' | invalid | implemented | 2026-07-23 |
| VAL-STRING | string coercion (numbers stringify, bool/array fail) | php | '5' 5 true [a] | '5' '5' / invalid | implemented | 2026-07-23 |
| VAL-ARRAY-REJECT | an array value rejects every scalar type | php | array vs int/float/bool/string | invalid each | implemented | 2026-07-23 |
| VAL-HAPPY | a valid mixed set returns only coerced params + default | php | id/active/name | coerced map + per_page default | implemented | 2026-07-23 |
| CAT-PARSE-VERSION | parse_version extracts the integer | php | /api/vN/... | N | implemented | 2026-07-23 |
| CAT-PARSE-ZERO | a non-api pattern parses to 0 | php | /contacts, /apix/v1 | 0 | implemented | 2026-07-23 |
| CAT-PATHKEY | path_key strips the /api/vN prefix | php | /api/vN/contacts/:id | /contacts/:id | implemented | 2026-07-23 |
| CAT-PATHKEY-AGNOSTIC | the same path key across versions | php | v1 vs v9 same tail | equal | implemented | 2026-07-23 |
| CAT-VERSIONS | get_versions contains 1, sorted desc | php | real manifest | in_array(1), descending | implemented | 2026-07-23 |
| CAT-RESOLVE-NONEMPTY | resolve_for_version(1) yields resource groups | php | real manifest | nonempty groups with endpoints | implemented | 2026-07-23 |
| CAT-RESOLVE-CEILING | no resolved endpoint exceeds the requested version | php | resolve(1) | every version <= 1 | implemented | 2026-07-23 |
| CAT-RESOLVE-ZERO | resolve_for_version(0) is empty | php | real manifest | [] | implemented | 2026-07-23 |
| CAT-LLM-KEYS | the LLM catalog carries the four top-level keys | php | real manifest | generated_for/version_resolution/versions/endpoints | implemented | 2026-07-23 |
| CAT-LLM-LATEST | every LLM endpoint carries latest_in_version | php | real manifest | pattern/version/path_key/api_params/latest_in_version map | implemented | 2026-07-23 |
| SESS-ACCESSORS | API identity serves login/site/user ids | php | _set_api_identity(1,1,1) | accessors return 1/1/1 | implemented | 2026-07-23 |
| SESS-LOGGED-IN | is_logged_in true under API identity | php | established | true | implemented | 2026-07-23 |
| SESS-IS-API | is_api_request true under API identity | php | established | true | implemented | 2026-07-23 |
| SESS-SESSION-ID-ZERO | get_session_id() == 0 in API mode | php | established | 0 | implemented | 2026-07-23 |
| SESS-CSRF-NULL | get_csrf_token() null in API mode | php | established | null | implemented | 2026-07-23 |
| SESS-HAS-SESSION-FALSE | has_session() false in API mode | php | established | false | implemented | 2026-07-23 |
| SESS-GET-SESSION-THROWS | get_session() throws in API mode | php | established | RuntimeException "API request" | implemented | 2026-07-23 |
| SESS-SET-LOGIN-THROWS | set_login_user_id throws in API mode | php | established | RuntimeException "API request" | implemented | 2026-07-23 |
| SESS-SET-SITE-THROWS | set_site_id throws in API mode | php | established | RuntimeException "API request" | implemented | 2026-07-23 |
| SESS-DOUBLE-SET | a second _set_api_identity throws | php | established + set | RuntimeException "called twice" | implemented | 2026-07-23 |
| SESS-RESET | _reset_api_identity restores the CLI tier | php | established + reset | is_api_request false, accessors revert | implemented | 2026-07-23 |
| KEY-GENERATE | generate returns plaintext + model with rsk_ prefix | php | user 1 | key/model present | implemented | 2026-07-23 |
| KEY-HASH | stored hash is sha256(plaintext), plaintext never stored | php | generated key | key_hash == sha256 | implemented | 2026-07-23 |
| KEY-PREFIX | key_prefix is env-tagged and masked | php | env test | rsk_test_...  | implemented | 2026-07-23 |
| KEY-ROUNDTRIP | find_by_key resolves the generated key | php | plaintext | same model id | implemented | 2026-07-23 |
| KEY-WRONG | a wrong key resolves null | php | bogus plaintext | null | implemented | 2026-07-23 |
| KEY-REVOKED | a revoked key resolves null | php | revoke() | null | implemented | 2026-07-23 |
| KEY-EXPIRED | a past-expiry key resolves null | php | expires_at past | null | implemented | 2026-07-23 |
| KEY-FUTURE | a future-expiry key still resolves | php | expires_at future | not null | implemented | 2026-07-23 |
| KEY-IS-VALID | is_valid reflects active/revoked/expired | php | three keys | true/false/false | implemented | 2026-07-23 |
| LOG-PERSIST | a log row persists and reads back intact | php | explicit fields | fields match | implemented | 2026-07-23 |
| LOG-CASCADE | purging a key CASCADE-deletes its request-log rows | php | key + row, then $key->delete() | the row is gone | implemented | 2026-08-27 |
| LOG-REVOKE-KEEPS | revoking a key preserves its request-log rows | php | key + row, then revoke() | the row survives | implemented | 2026-08-27 |
| LOG-ERROR-ENVELOPE | an error row records the envelope code + message + byte size | php | 422-shaped row | code/message/bytes match | implemented | 2026-08-27 |
| LOG-NULLABLE | nullable identity columns accept null | php | 401-shaped row | nulls preserved | implemented | 2026-07-23 |
| LOG-SILENT | the model is realtime_silent | php | static flag | true | implemented | 2026-07-23 |
| CLEAN-DEFAULT | old pruned / new kept at default retention | php | 40d/40d/1d rows | 2 deleted, recent kept | implemented | 2026-07-23 |
| CLEAN-CONFIG | the configured retention window is honored | php | window=7, 10d/1d | 10d deleted, 1d kept | implemented | 2026-07-23 |
| CLEAN-CHUNK | a backlog beyond one chunk fully clears | php | 12 old rows, chunk 5 | all deleted | implemented | 2026-07-23 |
| SER-MODEL-LABEL | model serializes with __MODEL + enum __label | php | User_Model role 100 | __MODEL, role_id__label=Developer | implemented | 2026-07-23 |
| SER-REDACT | neverExport columns are stripped | php | Session model | session_token/csrf_token/ip_address absent | implemented | 2026-07-23 |
| SER-ARRAY | an array of models recurses | php | [User, User] | two __MODEL arrays | implemented | 2026-07-23 |
| SER-COLLECTION | a Collection becomes an ordered array | php | collect([User,User]) | array of 2 | implemented | 2026-07-23 |
| SER-NESTED | a model nested in an array is serialized | php | {meta, record} | scalar + serialized model | implemented | 2026-07-23 |
| SER-PASSTHROUGH | scalars and null pass through | php | 'hello'/42/null | unchanged | implemented | 2026-07-23 |
| RESP-BUILD-ARRAY | build_response(array) -> 200 JSON | php | ['ok'=>true] | 200, decoded ok | implemented | 2026-07-23 |
| RESP-BUILD-NULL | build_response(null) -> 204 | php | null | 204 | implemented | 2026-07-23 |
| RESP-BUILD-PASS | a JsonResponse flows through untouched | php | JsonResponse | same instance | implemented | 2026-07-23 |
| RESP-BUILD-MODEL | build_response(model) -> 200 with __MODEL | php | User_Model | 200, __MODEL | implemented | 2026-07-23 |
| RESP-BUILD-SCALAR | a bare scalar fails loud | php | 'scalar' | RuntimeException "unsupported type" | implemented | 2026-07-23 |
| HELP-CREATED | created() is 201 with a bare body | php | ['id'=>5] | 201, no error envelope | implemented | 2026-07-23 |
| HELP-NOCONTENT | no_content() is 204 empty | php | - | 204, empty body | implemented | 2026-07-23 |
| HELP-NOTFOUND | not_found() is 404 error shape | php | - | 404, code not_found | implemented | 2026-07-23 |
| HELP-UNAUTH | unauthorized() is 401 | php | - | 401, code unauthorized | implemented | 2026-07-23 |
| HELP-FORBIDDEN | forbidden() is 403 | php | - | 403, code forbidden | implemented | 2026-07-23 |
| HELP-VALIDATION | validation_error() is 422 with fields | php | {email:Required} | 422, fields present | implemented | 2026-07-23 |
| HELP-ERROR | error() builds an arbitrary status, omits absent fields | php | 418 | 418, no fields key | implemented | 2026-07-23 |
| HELP-ERROR-FIELDS | error() includes fields when present | php | 422 + fields | fields present | implemented | 2026-07-23 |
| HTTP-401 | unauth -> 401 error shape, no Set-Cookie | http | no bearer | 401, auth_required, no cookie | implemented | 2026-07-23 |
| HTTP-200 | authed -> 200 bare JSON, no Set-Cookie | http | bearer | 200, items, no success envelope, no cookie | implemented | 2026-07-23 |
| HTTP-PUT-405 | PUT -> 405 | http | bearer + PUT | 405 | implemented | 2026-07-23 |
| HTTP-HEAD-405 | HEAD -> 405 | http | bearer + HEAD | 405 | implemented | 2026-07-23 |
| HTTP-404 | unknown endpoint -> 404 not_found | http | bearer /api/v9/nope | 404, not_found | implemented | 2026-07-23 |
| HTTP-422 | undeclared param -> 422 with fields | http | ?bogus=1 | 422, fields, bogus named | implemented | 2026-07-23 |
| HTTP-400 | invalid JSON body -> 400 invalid_json | http | POST bad json | 400, invalid_json | implemented | 2026-07-23 |
| HTTP-NO-SESSION | an authed call creates no _sessions row | http | bearer GET | session count unchanged | implemented | 2026-07-23 |
| HTTP-COOKIE-BEARER | cookie + bearer -> 200, bearer identity, no Set-Cookie | http | cookie + bearer | 200, no cookie | implemented | 2026-07-23 |
| HTTP-LOGGED | 401 (null key) and 200 (populated key) rows written | http | after the run | both log rows present | implemented | 2026-07-23 |
| SCOPE-VALID-LITERAL | validate accepts literal paths | php | /api/v1/me, /api/v1/clients/42/view | no throw | implemented | 2026-09-01 |
| SCOPE-VALID-WILDCARD | validate accepts ?, # and * as whole segments | php | four scopes | no throw | implemented | 2026-09-01 |
| SCOPE-VALID-VERSION | validate accepts a wildcard version (? and #) | php | /api/?/clients, /api/#/clients | no throw | implemented | 2026-09-01 |
| SCOPE-VALID-SLASHQUERY | a trailing slash and a query string validate (both stripped) | php | /api/v1/clients/, /api/v1/clients?page=2 | no throw | implemented | 2026-09-01 |
| SCOPE-BAD-PARTIAL | a wildcard inside a segment is refused | php | /api/v1/foo/bar*, ?bar/view, b#r, *x | "a wildcard must be a whole segment" | implemented | 2026-09-01 |
| SCOPE-BAD-STAR | '*' anywhere but last is refused | php | /api/v1/*/view, /api/*/clients | "'*' may only be the last segment" | implemented | 2026-09-01 |
| SCOPE-BAD-PREFIX | a missing api/version prefix is refused | php | /clients/42, /api/clients, /api/v1, api/v1/x | "must start with /api/<version>/" | implemented | 2026-09-01 |
| SCOPE-BAD-VERSION | a literal version that is not vN is refused | php | /api/version1/x, /api/1/x | "the version segment must be vN" | implemented | 2026-09-01 |
| SCOPE-BAD-EMPTY | an empty segment, or a blank scope, is refused | php | /api/v1//clients, "   " | "empty segment" / "cannot be blank" | implemented | 2026-09-01 |
| SCOPE-BAD-OLDLANG | the retired Grant/Deny rule language is refused | php | Grant GET /api/v1/contacts/** | Api_Scope_Validation_Exception | implemented | 2026-09-01 |
| SCOPE-NORM | normalize strips space, trailing slash and query string | php | four spellings | /api/v1/clients | implemented | 2026-09-01 |
| SCOPE-NORM-WILDCARD | a whole-segment '?' survives normalization | php | /api/?/clients(?page=2) | wildcard kept, query stripped | implemented | 2026-09-01 |
| SCOPE-MATCH-LITERAL | a literal scope matches only itself | php | /api/v1/clients | exact only | implemented | 2026-09-01 |
| SCOPE-MATCH-CASE | matching is case-sensitive | php | /api/v1/Clients | false | implemented | 2026-09-01 |
| SCOPE-MATCH-QUESTION | '?' takes exactly one segment of any shape | php | /api/v1/clients/?/view | 42 and settings yes, 0 and 2 segments no | implemented | 2026-09-01 |
| SCOPE-MATCH-HASH | '#' takes exactly one all-digits segment | php | /api/v1/clients/#/view | 42 yes, settings/4a no | implemented | 2026-09-01 |
| SCOPE-MATCH-STAR | '*' is prefix-inclusive and covers everything below | php | /api/v1/foo/baz/* | baz, baz/x, baz/x/y yes; foo, bazz no | implemented | 2026-09-01 |
| SCOPE-MATCH-VERSION | a wildcard version matches any vN; '#' matches none | php | /api/?/clients, /api/#/clients | true/true/false | implemented | 2026-09-01 |
| SCOPE-MATCH-SLASH | a trailing slash on either side changes nothing | php | three spellings | true | implemented | 2026-09-01 |
| SCOPE-MATCH-QUERY | the query string is not part of the match | php | ?page=2 | true | implemented | 2026-09-01 |
| SCOPE-PARSEALL-SPLIT | parse_all splits valid from malformed with reasons | php | mixed text | valid[], malformed[text=>reason] | implemented | 2026-09-01 |
| SCOPE-PARSEALL-DEDUPE | parse_all normalizes and dedupes | php | three spellings of one path | one entry | implemented | 2026-09-01 |
| SCOPE-PARSEALL-NOTHROW | reading a stored value never throws | php | old rule language | all malformed, no exception | implemented | 2026-09-01 |
| SCOPE-CANON | canonicalize normalizes, dedupes, null when empty | php | messy text / null / blank | canonical / null / null | implemented | 2026-09-01 |
| SCOPE-CANON-WHOLESET | the first bad scope refuses the whole set | php | good, bad, good | Api_Scope_Validation_Exception | implemented | 2026-09-01 |
| SCOPE-COUNT | count_scopes counts malformed scopes too | php | one valid + one malformed | 2 | implemented | 2026-09-01 |
| SCOPE-UNRESTRICTED | only null/blank is unrestricted; malformed-only is not | php | four texts | true x3 / false x2 | implemented | 2026-09-01 |
| SCOPE-DECIDE-OPEN | an unrestricted key reaches everything | php | null, '' | true | implemented | 2026-09-01 |
| SCOPE-DECIDE-DEFAULT | any scope makes the key deny-by-default | php | /api/v1/contacts/* | named yes, others no | implemented | 2026-09-01 |
| SCOPE-DECIDE-UNION | the decision is the union and is order-independent | php | two scopes in both orders | identical answers | implemented | 2026-09-01 |
| SCOPE-DECIDE-QUERY | the query string is ignored | php | /api/v1/contacts?page=2 | true | implemented | 2026-09-01 |
| SCOPE-DECIDE-VERSION | scopes are version-specific unless wildcarded | php | /api/v1/*, /api/?/* | v1 yes, v2 no / v2 yes | implemented | 2026-09-01 |
| SCOPE-DECIDE-FAILCLOSED | a malformed-only scope set denies EVERYTHING | php | /api/v1/foo* | false for every path | implemented | 2026-09-01 |
| SCOPE-DECIDE-SKIP | a malformed scope is skipped, the valid ones stand | php | one of each | valid path yes, other no | implemented | 2026-09-01 |
| SCOPE-ROUTE-OPAQUE | reaches_route treats a ':param' segment as opaque | php | ?, #, literal, * vs /clients/:id/view | true each | implemented | 2026-09-01 |
| SCOPE-ROUTE-LITERAL | two literal segments must still be equal | php | /clients/* vs /contacts/:id | false | implemented | 2026-09-01 |
| SCOPE-ROUTE-OPEN | an unrestricted scope set reaches every route | php | null | true | implemented | 2026-09-01 |
| KEY-SCOPE-DEFAULT | generate() defaults to an unrestricted key | php | no scopes argument | scopes null, is_unrestricted, no malformed | implemented | 2026-09-01 |
| KEY-SCOPE-NORM | generate() normalizes and dedupes what it stores | php | messy scope text | /api/v1/contacts | implemented | 2026-09-01 |
| KEY-SCOPE-REFUSE | a malformed scope refuses the mint entirely | php | /api/v1/contacts* | throws, no row written | implemented | 2026-09-01 |
| KEY-SCOPE-OLDLANG | the retired rule language refuses the mint | php | Grant GET /api/v1/contacts | Api_Scope_Validation_Exception | implemented | 2026-09-01 |
| KEY-SETSCOPES | set_scopes normalizes and persists | php | two scopes, one messy | two canonical lines | implemented | 2026-09-01 |
| KEY-SETSCOPES-NULL | set_scopes(null) restores full authority | php | scoped key | scopes null | implemented | 2026-09-01 |
| KEY-SETSCOPES-REFUSE | a malformed set_scopes writes nothing | php | /nope/v1/contacts | throws, stored scopes intact | implemented | 2026-09-01 |
| KEY-SCOPE-MALFORMED | has_malformed_scopes reads a hand-edited row | php | raw UPDATE planting an old rule | true, still scoped, valid scopes only | implemented | 2026-09-01 |
| CAT-SCOPE-OPEN | an unrestricted scope set returns the plain catalogue | php | null / '' / blank | identical to resolve_for_version(1) | implemented | 2026-09-01 |
| CAT-SCOPE-REACH | only the reachable resource survives | php | /api/v1/contacts/* | contacts endpoints only | implemented | 2026-09-01 |
| CAT-SCOPE-VERBS | a reachable endpoint keeps every verb it declares | php | /api/v1/* | identical to the plain catalogue | implemented | 2026-09-01 |
| CAT-SCOPE-NONE | a scope set matching nothing yields nothing | php | /api/v1/no_such_resource/* | [] | implemented | 2026-09-01 |
| CAT-SCOPE-SUBSET | a narrower scope set is a strict subset | php | one resource vs all | fewer endpoints | implemented | 2026-09-01 |
| CAT-SCOPE-MALFORMED | a malformed scope is skipped, never thrown | php | /api/v1/contacts* (+ a valid one) | [] / nonempty | implemented | 2026-09-01 |
| TKEY-OPEN-EQUALS-USER | an unrestricted key equals its user's gate answers | php | scope-free key | maps identical | implemented | 2026-09-01 |
| TKEY-SUBSET | a scoped key is a strict subset of its user's answers | php | contacts-only key | fewer targets, all admitted by gates | implemented | 2026-09-01 |
| TKEY-COMPOSITION | each target answers gates AND scopes | php | contacts-only key | per-target equality with the composition | implemented | 2026-09-01 |
| TKEY-EMPTY | a key scoped to a nonexistent resource reaches nothing | php | /api/v1/no_such_resource/* | no allowed targets | implemented | 2026-09-01 |
| TKEY-COVERAGE | every published target appears in the map | php | scoped key | array_has_key for each | implemented | 2026-09-01 |
| TKEY-BADGE-NULL | current_is_scoped() is null with no adopted key | php | forget() | null | implemented | 2026-09-01 |
| TKEY-BADGE | current_is_scoped() distinguishes scoped from unrestricted | php | two adopted keys | false / true | implemented | 2026-09-01 |
| CLI-SCOPE-ROUNDTRIP | a repeatable --scope round-trips canonically through key:list --json | php (cli) | messy + duplicate scopes | canonical two-line text both places | implemented | 2026-09-01 |
| CLI-SCOPE-ABSENT | no --scope mints an unrestricted key | php (cli) | no flag | scopes null, is_unrestricted | implemented | 2026-09-01 |
| CLI-SCOPE-NEWLINES | one --scope string may carry its own newlines | php (cli) | two scopes in one value | both stored | implemented | 2026-09-01 |
| CLI-SCOPE-REFUSE | a malformed scope exits 1 and mints nothing | php (cli) | /api/v1/contacts* | code 1, scopes_invalid, key count unchanged | implemented | 2026-09-01 |
| CLI-SCOPE-REFUSE-TEMP | key:temp refuses a malformed scope the same way | php (cli) | /not/an/api/path | code 1, scopes_invalid, count unchanged | implemented | 2026-09-01 |
| CLI-SCOPE-OLDLANG | the retired rule language is refused by the CLI | php (cli) | Grant GET /api/v1/contacts/** | code 1, scopes_invalid, count unchanged | implemented | 2026-09-01 |
| CLI-SCOPE-TEMP | key:temp carries its scope and still expires | php (cli) | /api/v1/me | scopes stored, expires_at set | implemented | 2026-09-01 |
| HTTP-SCOPE-REACH | a scoped key reaches an endpoint its scopes name | http | scoped bearer GET | 200 items | implemented | 2026-09-01 |
| HTTP-SCOPE-DENY | an unreachable endpoint is 403 insufficient_scope with required=route pattern | http | scoped bearer, other path | 403, code + "required":"/api/v1/clients" | implemented | 2026-09-01 |
| HTTP-SCOPE-METHODLESS | a path scope covers POST as well as GET | http | scoped bearer POST create | not 403 | implemented | 2026-09-01 |
| HTTP-SCOPE-BEFORE-VALIDATION | the scope check precedes param validation | http | unreachable path + bogus param | 403, not 422 | implemented | 2026-09-01 |
| HTTP-SCOPE-QUERY | a query string does not change the scope answer | http | scoped bearer ?page=1 | 200 | implemented | 2026-09-01 |
| HTTP-SCOPE-NULL-KEY | a NULL-scope key behaves exactly as before | http | unscoped bearer | 200 / 422 as applicable | implemented | 2026-09-01 |
| HTTP-SCOPE-WEB-FILES | /_download refuses a key scoped away from files | http | scoped vs unscoped bearer | 403 insufficient_scope vs 404 | implemented | 2026-09-01 |
| HTTP-SCOPE-WEB-GRANT | a files scope reopens the web download path | http | files-scoped bearer | 404 (route's own answer) | implemented | 2026-09-01 |
| HTTP-SCOPE-LOGGED | every scope denial reaches _api_request_log with its handler | http | after the run | 403 rows, code insufficient_scope, handler set | implemented | 2026-09-01 |
| HTTP-SCOPE-ME | /me reports the key's own scopes, null when unrestricted | http | scoped then unscoped bearer | scope text / "scopes":null | implemented | 2026-09-01 |
| HTTP-SCOPE-FAILCLOSED | a malformed-only scope set denies every path and logs one warning per scope | http | raw UPDATE planting /api/v1/contacts* | 403 x3 + "ignoring malformed scope" in laravel.log | implemented | 2026-09-01 |
| HTTP-NO-BYNAME | there is no by-name addressing channel | http | /api/v1/<Controller>/<action> style URLs | 404 not_found each | implemented | 2026-09-01 |
| KEY-RO-DEFAULT | generate() defaults to a read+write key | php | no read_only argument | read_only false, stored false | implemented | 2026-09-01 |
| KEY-RO-STORE | generate() stores the read_only flag | php | read_only true | true on the model and in the row | implemented | 2026-09-01 |
| KEY-RO-CAST | read_only reads back as a real boolean | php | TINYINT(1) row | === true | implemented | 2026-09-01 |
| KEY-RO-COMPOSE | read_only and the scopes are independent | php | read-only + a scope | flag set, scopes intact | implemented | 2026-09-01 |
| KEY-RO-NOSETTER | a minted key's read_only can never change | php | reflection over the model | no set_read_only / set*read_only method | implemented | 2026-09-01 |
| CAT-RO-GET-ONLY | a read-only catalogue lists GET verbs alone | php | resolve_for_scopes(1, null, false, true) | every endpoint methods == ['GET'] | implemented | 2026-09-01 |
| CAT-RO-SUBSET | a read-only catalogue is a strict subset | php | read_only true vs false | fewer endpoints | implemented | 2026-09-01 |
| CAT-RO-COMPOSE | read_only composes with the scopes | php | contacts scope + read_only | GET verbs, contacts paths, fewer than scoped alone | implemented | 2026-09-01 |
| CAT-RO-DEFAULT | the read_only flag defaults off | php | omitted vs false | identical | implemented | 2026-09-01 |
| TKEY-RO-NOWRITE | a read-only key reaches no endpoint without a GET verb | php | read-only key | per-target equality with gates AND has-GET | implemented | 2026-09-01 |
| TKEY-RO-COMPOSE | a read-only key composes gates, scopes and verbs | php | read-only contacts-only key | per-target equality with the three-way composition | implemented | 2026-09-01 |
| TKEY-RO-BADGE-NULL | current_is_read_only() is null with no adopted key | php | forget() | null | implemented | 2026-09-01 |
| TKEY-RO-BADGE | current_is_read_only() distinguishes the two kinds of key | php | two adopted keys | false / true | implemented | 2026-09-01 |
| CLI-RO-CREATE | --read-only is stored and reported by key:create | php (cli) | --read-only | envelope true, row true | implemented | 2026-09-01 |
| CLI-RO-DEFAULT | omitting --read-only mints a read+write key | php (cli) | no flag | envelope false, row false | implemented | 2026-09-01 |
| CLI-RO-INDEPENDENT | --read-only and --scope are independent | php (cli) | both / scope only | flag + scopes / scoped writer | implemented | 2026-09-01 |
| CLI-RO-TEMP | key:temp carries the flag and still expires | php (cli) | --read-only --expires | read_only true, expires_at set | implemented | 2026-09-01 |
| CLI-RO-LIST-JSON | key:list --json reports read_only per key | php (cli) | a read-only key | row read_only true | implemented | 2026-09-01 |
| CLI-RO-LIST-TABLE | key:list carries an Access column | php (cli) | a read-only key | 'Access' header + 'read-only' cell | implemented | 2026-09-01 |
| HTTP-RO-GET | a read-only key GETs normally | http | read-only bearer GET | 200 items | implemented | 2026-09-01 |
| HTTP-RO-POST | any non-GET with a read-only key is 403 read_only_key | http | read-only bearer POST | 403, code + the exact message | implemented | 2026-09-01 |
| HTTP-RO-BEFORE-ROUTE | the refusal precedes route resolution | http | read-only POST to an unknown path | 403 read_only_key, not 404 | implemented | 2026-09-01 |
| HTTP-RO-ORDER | read_only is decided before the scopes, proven both ways | http | read-only scoped key | out-of-scope GET -> insufficient_scope; in-scope POST -> read_only_key; in-scope GET 200 | implemented | 2026-09-01 |
| HTTP-RO-UNAFFECTED | a read+write key is untouched | http | read+write bearer POST | not 403, no read_only_key | implemented | 2026-09-01 |
| HTTP-RO-ME | /me reports read_only for both kinds of key | http | read-only then read+write bearer | "read_only":true / false | implemented | 2026-09-01 |
| HTTP-RO-LOGGED | every read-only denial reaches _api_request_log | http | after the run | 403 rows, code read_only_key | implemented | 2026-09-01 |
| SCAN-GET-PURE | a GET handler containing a write fails the scan | php | fixture mutating_get | RuntimeException "API-GET-PURE-01" | implemented | 2026-08-30 |
| SCAN-GET-PURE-NAMES | the refusal names the call it found | php | fixture mutating_get | message contains "calls '->save('" | implemented | 2026-08-30 |
| SCAN-GET-PURE-EXCEPT | a rationalised exception docblock passes | php | fixture excepted_get | route bakes | implemented | 2026-08-30 |
| SCAN-GET-PURE-BARE | the exception tag without a rationale throws | php | fixture bare_tag_get | RuntimeException "requires a rationale" | implemented | 2026-08-30 |
| SCAN-GET-PURE-PROSE | write words in a comment or string do not trip it | php | fixture pure_get | route bakes | implemented | 2026-08-30 |
| SCAN-GET-PURE-POST | the rule does not apply to a POST handler | php | fixture as POST | route bakes | implemented | 2026-08-30 |
| SCAN-RESPONSE-DOCBLOCK | @api-response / description parsing off a real fixture file | php | fixture controller | parsed description + response example | deferred (docblock reader returns '' for synthetic entries; a fixture-file parse test is a candidate) | 2026-07-23 |
| VAL-PRECEDENCE | route > GET > body precedence in raw assembly | http | overlapping keys | route param wins | deferred (precedence lives in Api_Dispatcher::_collect_raw_input, a private method; covered indirectly by the http path) | 2026-07-23 |
| EXC-HANDLER-500 | an uncaught endpoint throwable renders JSON 500 | http | forced-throw endpoint | 500 JSON, never HTML | deferred (no throwing endpoint exists to hit; candidate once a fixture endpoint lands) | 2026-07-23 |
