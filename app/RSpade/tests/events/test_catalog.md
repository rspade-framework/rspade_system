# events - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| LIFECYCLE-ACCESSOR | rebuild_occurred() reflects the $_rebuild_occurred flag | php | flag set true then false | accessor returns true then false | implemented | 2026-07-21 |
| LIFECYCLE-WARM-READY-ONLY | a warm boot (no rebuild) fires ONLY rsx.ready with rebuilt=false | php | $_rebuild_occurred=false, drive __fire_lifecycle_events() | one recorded event: rsx.ready, data.rebuilt=false | implemented | 2026-07-21 |
| LIFECYCLE-REBUILT-ORDER | a rebuild fires rsx.rebuilt -> rsx.rebuilt.dev -> rsx.ready in order, with the changed-file list threaded and rebuilt=true | php | $_rebuild_occurred=true, $_changed_files=[one path], dev runner | 3 events in exact order; rebuilt* carry files list; rsx.ready rebuilt=true | implemented | 2026-07-21 |
| LIFECYCLE-LIVE-TOUCH | an mtime-only touch dirties the scan so the next request fires the rebuilt family; the following warm request fires only rsx.ready(false) | php-subprocess | touch rsx file, two boots | run1: rsx.rebuilt+.dev+ready(true); run2: ready(false) | deferred (runner boots once; verified manually at implementation, see README) | 2026-07-21 |
| LIFECYCLE-PROD-VARIANT | in a production-like mode the mode variant is rsx.rebuilt.prod (not .dev) | php | is_production() true during a rebuild | second event is rsx.rebuilt.prod | deferred (test runner is dev-mode only; branch is a single is_production() ternary, covered by inspection) | 2026-07-21 |
| MIGRATE-EVENT-DISCOVERED | the #[OnEvent] fixture for migrate.normalize_schema.complete is manifest-discovered | php | Event_Registry::has_handlers(event) | true | implemented | 2026-08-28 |
| MIGRATE-EVENT-FIRES-ONCE | Rsx::trigger_action reaches the handler exactly once, with an empty payload | php | trigger_action(event, []) while recording | 1 recorded entry, data === [] | implemented | 2026-08-28 |
| MIGRATE-EVENT-INERT | the live fixture records nothing while $recording is false (so a real migrate is unaffected) | php | trigger_action(event, []) with recording off | 0 recorded entries | implemented | 2026-08-28 |
