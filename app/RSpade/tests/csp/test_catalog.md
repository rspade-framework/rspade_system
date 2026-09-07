# csp - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| CSP-01 | The base policy is hardened and nonce-based, never `'unsafe-inline'` in script-src | php | staff realm, fixture registry | default-src/object-src/base-uri/frame-ancestors as declared; nonce in script-src; report-uri present | implemented | 2026-08-18 |
| CSP-02 | style-src keeps `'unsafe-inline'` and carries NO nonce (a nonce makes browsers ignore it) | php | staff realm | style-src contains `'unsafe-inline'`, contains no `nonce-` | implemented | 2026-08-18 |
| CSP-03 | Declared external origins reach the realm that declared them | php | staff-only + both-realm entries | staff: both origins; portal: only the both-realm one | implemented | 2026-08-18 |
| CSP-04 | An entry's runtime csp extras create the directive, seeded with `'self'` | php | entry declaring frame-src | `frame-src 'self' https://widget.example.net` | implemented | 2026-08-18 |
| CSP-05 | A mirror:false stylesheet's origin joins style-src and NOT font-src (the heuristic is gone; font hosts are declared via csp extras) | php | mirror:false entry declaring a css URL | that origin in style-src; `font-src 'self' data:` untouched | implemented | 2026-09-01 |
| CSP-06 | Bundle cdn_assets contribute no origin in any mode - they are mirrored and served same-origin | php | fixture registry, development AND production mode | script-src is exactly `'self' 'nonce-...'` plus the declared mirror:false origins | implemented | 2026-09-01 |
| CSP-07 | connect-src names no websocket when realtime is off | php | realtime disabled | `connect-src 'self'` | implemented | 2026-08-18 |
| CSP-08 | connect-src mirrors the client's ws:// downgrade exactly | php | realtime enabled | wss://host always; ws://host iff not strict production | implemented | 2026-08-18 |
| CSP-09 | The policy ALWAYS enforces, and still names the collector | php | default config | header is `Content-Security-Policy`, never the report-only spelling; `report-uri` present | implemented | 2026-08-31 |
| CSP-09b | A stray `report_only` config key is inert (no schema, no resurrection of observe mode) | php | `rsx.csp.report_only => true` | header is still `Content-Security-Policy` | implemented | 2026-08-31 |
| CSP-10 | Disabled means no policy composed and no header stamped (the emergency kill-switch) | php | `csp.enabled => false` | compose() null; response carries no policy header | implemented | 2026-08-31 |
| CSP-11 | additional_sources APPEND - the framework's own sources always survive | php | extra script-src host | extra host present alongside 'self' and the declared origins | implemented | 2026-08-18 |
| CSP-12 | Widening object-src is refused loudly (appending to 'none' would loosen it) | php | `additional_sources.object-src` | RuntimeException naming the directive + rsx:man csp | implemented | 2026-08-18 |
| CSP-13 | The nonce is stable for a request and fresh after a reset | php | nonce(), compose(), _reset_request_state() | same value twice, present in the header, different after reset | implemented | 2026-08-18 |
| CSP-14 | The header is HTML-only (a JSON envelope carries no policy) | php | html / typed html / json responses | stamped, stamped, not stamped | implemented | 2026-08-18 |
| CSP-15 | A response already declaring a policy is never overwritten or doubled | php | response with its own CSP header | original value intact, no second policy header added | implemented | 2026-08-31 |
| CSP-16 | A dispatched page CARRIES the ENFORCING policy, and its nonce IS the inline script's nonce | http | GET /login | `Content-Security-Policy` present, report-only absent; header nonce == `<script nonce="...">` value | implemented | 2026-08-31 |
| CSP-17 | The HTML-only rule holds over the wire | http | POST to a nonexistent ajax endpoint | no CSP header on the JSON response | implemented | 2026-08-18 |
| CSP-18 | The collector still logs under enforcement, with no session or CSRF token | http | POST /_csp-report, application/csp-report | 204 + exactly one line appended carrying the report | implemented | 2026-08-31 |
| CSP-19 | A malformed body is recorded, never rejected (no browser retry loop) | http | POST /_csp-report with non-JSON | 204 + the raw body recorded under `invalid` | implemented | 2026-08-18 |
| CSP-20 | A portal page carries the PORTAL realm's policy | http | GET the portal login under the configured prefix | policy present, staff-only origins absent | planned - the portal prefix is app config (`/_portal` here), so a shipped test would hardcode an install-specific path; verified manually 2026-08-18 | 2026-08-18 |
| CSP-21 | The Debugger's shutdown console echo carries the request's nonce | http | a page rendered with SHOW_CONSOLE_DEBUG_HTTP on | every inline script's nonce equals the header's | planned - requires an env flip the test would have to make and revert | 2026-08-18 |
| CSP-22 | An enforcing policy blocks an undeclared external script in a real browser | playwright | page injecting an undeclared script | the load is blocked and reported | planned | 2026-08-31 |
| CSP-23 | A template declaration reaches the composed header for its realm only, against the REAL registry | php | real manifest, staff + portal | googletagmanager in staff script-src, absent from portal; transitive google-analytics origins absent (they are config, not derived) | implemented | 2026-08-18 |
