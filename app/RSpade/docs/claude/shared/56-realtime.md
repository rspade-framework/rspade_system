<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL HOME of the refresh()-vs-reload() rule. -->

## REALTIME (WEBSOCKET)

Pages update themselves when data changes elsewhere (PHP -> Redis -> a Node relay -> the browser; `REALTIME_ENABLED`). **Frames are notification-only, never confidential data**: a frame says "something changed, go look" and the client refetches through ordinary gated Ajax. Emit with `public static $realtime = true;` (a `Model_Changed_Topic` on every committed `save()`/`delete()`), `#[Realtime_Touch]` (cascades to the parent), `Realtime::publish('Some_Topic', $filter)` or `#[Emitter]`; a write that bypassed the model layer (raw SQL, `DB::table()`, `->raw_bulk()`) emits nothing until `$record->realtime_emit()`.

Subscribe in `on_create()` - `this.subscribe(Client_Model, this.args.id, () => this.refresh());` (`Rsx_Realtime.watch()` outside components; throws if the model is not `$realtime`). **Every (re)subscribe fires the callback once as a resync, so write every callback as an idempotent refetch, and use `refresh()`, not `reload()`** (the SERVER says something may have changed -> `refresh()`; the USER did something -> `reload()`). **A topic's `can_subscribe(array $filter = []): bool` is the SOLE enforcement boundary** - `Realtime_Controller` mints a token for anonymous callers too.

Skill `rspade:realtime` (topics, emitters, the touch cascade, `REALTIME-AUTH-01`/`REALTIME-BULK-01`, delivery guarantees). Details: `rsx:man realtime`.
