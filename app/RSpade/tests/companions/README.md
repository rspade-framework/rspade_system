# Concern: companions

## Domain overview & applicability

Companion applications: long-running processes from the application's own code tree, run by
`rsx-companiond` and calling the application through `#[Companion_Endpoint]` methods. This concern
covers the PHP core: the declaration, the manifest build, the realm's dispatcher and verifier, the
headless request, the actor and the install-state predicate. Authorization in this realm IS the
signature, so its failure paths are the feature.

## Source files

- `app/RSpade/Core/Companion/Companion_Service_Abstract.php` - the declaration
- `app/RSpade/Core/Companion/Companion_ManifestSupport.php` - `data.companions`, the FATALs, the contract key and source hash
- `app/RSpade/Core/Companion/Companion_Descriptor_ManifestSupport.php` - `build/companions/<name>/`
- `app/RSpade/Core/Companion/Companion_Dispatcher.php` - the pipeline, site declaration, daemon verbs
- `app/RSpade/Core/Companion/Companion_Verifier.php` - keys, canonical string, window, liveness, contract, nonce
- `app/RSpade/Core/Companion/Companion_Store.php` - Redis DB 4
- `app/RSpade/Core/Companion/Companion_Request_Log.php` - the log file and counters
- `app/RSpade/Core/Companion/Companion_Actor_Model(_Abstract).php` - `_companion_actors`
- `app/RSpade/Core/Env/Rsx_Install_State.php` - the install-ready predicate
- `app/RSpade/Core/Exceptions/Companion_Exception_Handler.php` - the channel's error policy
- `app/RSpade/Core/Session/Session.php` - `_begin_headless_request()`, `_end_headless_request()`

- `bin/rsx-companiond/` - the daemon and the SDK (its `CLAUDE.md` is the map)
- `app/RSpade/Core/Companion/Companion_Daemon_Client.php`, `Companion_Flags.php` - the socket and the flags
- `app/RSpade/Core/Companion/Rsx_Companion.php`, `Companion_Gates.php`, `Rsx_Companion_Controller.php` - the
  facade, its gates and the browser's controller; `Companion_Changed_Topic`, `Companion_Output_Topic`
- `app/RSpade/Core/Companion/Companion_Health_Checks.php` - the rsx:health rows
- `app/RSpade/Core/Companion/ui/` - `Companion_Output` and `Rsx_Companion.js`

## Testable surface

- `php/`: the manifest rules over synthetic rows, the wire literals Node mirrors, and the realm end
  to end through `Rsx_Front_Controller::handle()`, hand-signing against a hand-written instance
  record under the test database's Redis token.
- `php/Companion_Facade_Test`: the gates, the facade's reads over hand-written Redis state, the health
  rows and the daemon's realtime frame builder.
- `php/Companion_Sdk_Wire_Test`: the Node modules (`bin/rsx-companiond/lib/wire.js`,
  `fingerprint.js`, `backoff.js`, the RESP codec) computing the same values as PHP.
- `php/Companion_Daemon_Process_Test`: real daemons against a scratch tree (scratch storage,
  build and project roots, a scratch `DB_DATABASE` for a private Redis prefix), a fake application
  (`resource/fake_app.js`) and a scriptable child (`resource/fixture_child.js`): the process
  guarantees, the socket, the flags, the election and supervisor's stop. Needs node and Redis;
  the election test SKIPs without rsx-lockd, the supervisor test without supervisord.
- `http/`: what only real php-fpm can show. Under CLI every session is virtual, so a sent cookie
  being ignored is proved live, against the template's `hello` companion (SKIP when absent).
