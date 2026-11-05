<!-- single-source: never duplicate into another fragment. -->

## ENDPOINTS

**Ajax endpoints are browser-only.** A `#[Ajax_Endpoint]` static method on a controller is callable from JS under the controller's own name - no route, no URL, no wiring: `await My_Controller.save({name: 'Test'})`. **`$.ajax()` to a local URL THROWS.** **Every Ajax response is HTTP 200**: the `{_success, _ajax_return_value}` envelope carries the outcome, failures are `response_error(Ajax::ERROR_*, $message)`, and unhandled errors already log and surface to the user, so catch only what you handle. Skill `rspade:ajax-error-handling` (the codes, the `rsx:ajax` CLI).

**The external API** is `#[Api_Endpoint]` + `#[Api_Param]` on a `Rsx_Api_Controller_Abstract`: `/api/vN/` paths, GET/POST only, `Authorization: Bearer rsx_...` only, running as the key's owner (site scope automatic - never hand-write `where('site_id')`). A key may be narrowed by path-pattern scopes (403 `insufficient_scope`; `Api_Scopes::validate()` / `matches()` are the ONE validator and matcher, never a JS twin) or minted read-only (403 `read_only_key`); **`API-GET-PURE-01`** (manifest FATAL) keeps a GET handler side-effect-free - GET reads, POST writes, never both. **There is no privileged CLI channel into the API**: a script mints its own key (`rsx:api:key:create` / `key:temp`, `--user=` required) and calls `/api/vN` over HTTP. Skill `rspade:external-api`.

**Proxority** fair-queues every PHP request in the RSpade container; a surface steers it with at most ONE of `#[Unthrottled]` / `#[Priority]` / `#[Media]` - misplaced is `PROXORITY-01`, a manifest FATAL; inert outside the container. `rsx:man proxority`.
