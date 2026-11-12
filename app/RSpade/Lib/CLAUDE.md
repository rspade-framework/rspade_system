# Lib — the framework's supplementary features

## Core vs Lib

`app/RSpade/Core/` is the ENGINE: boot, build, request handling, the browser runtime, data and
identity, background work, live updates and the test harness. `app/RSpade/Lib/` holds the
SUPPLEMENTARY features - helper libraries an application could run without, the way a PHP
program can run without `md5()` - grouped by domain. A new feature goes in `Lib/<Domain>/`
unless the engine cannot function without it.

Both trees are always present, and nothing about a class depends on which one it sits in:

- **Both are scanned** (`rsx.manifest.scan_directories`), so every class is found by NAME.
- **Directory names are CamelCase, one word per segment** (`Lib/SignIn/TwoFactor`, as in
  `Core/GeoIp`), never underscored: the namespace is the path (`App\RSpade\Lib\SignIn\TwoFactor`),
  so composer's PSR-4 rule resolves every class here as well as the RSX autoloader does - which
  is what code running before `Autoloader::register()` relies on.
- **`Core_Bundle` includes the browser-facing parts only** - each feature's components, JS and
  the controllers its components call, named directory by directory or file by file. A whole
  server-only directory is never included: that would publish its Ajax stubs on every page.

## The domains

| Directory | What it holds |
|---|---|
| `Messaging/Mail` | Outbound email: `Rsx_Mail`, `Rsx_Email_Abstract`, the queue models and `Mail_Queue_Service`, the unsubscribe endpoint, the default `resource/email.scss` |
| `Messaging/Sms` | `Rsx_Sms` and the SMS queue models |
| `SignIn/TwoFactor` | `Rsx_Two_Factor` / `Rsx_Portal_Two_Factor` (TOTP, passkeys, recovery and emailed codes) and their components (`CLAUDE.md`) |
| `SignIn/Sso` | `Rsx_Sso` / `Rsx_Portal_Sso`, the identity models, `<Sso_Buttons>` (`CLAUDE.md`) |
| `SignIn/Confirmation` | `Rsx_Password_Confirmation` and `<Password_Confirmation_Form>` |
| `SignIn/Turnstile` | `Rsx_Turnstile` and `<Turnstile_Input>` |
| `SignIn/Redirect` | `Login_Redirect`, `Login_Notice` |
| `SignIn/Throttle` | `Rsx_Throttle` |
| `Portal` | The client portal: `Rsx_Portal`, `Portal_Session`, `Portal_Main_Abstract`, the portal routing manifest modules, `Portal_User_Model`, `Portal_Notification_Model` (`CLAUDE.md`) |
| `Revisions` | Revision history: `Revision`, the revision and transaction models, cleanup |
| `Geo/Ip` | `Rsx_Geo_Ip` (IP geolocation) and `<Geo_Ip_Credit>` (`CLAUDE.md`) |
| `Geo/Places` | `Country_Model`, `Region_Model` |
| `Geo/ReferenceData` | `Rsx_Reference_Data_Controller` |
| `Ui/Flash` | Flash alerts (`CLAUDE.md`) |
| `Ui/DarkMode` | `Rsx_Dark_Mode` and its preference endpoint |
| `Ui/RecordAuthor` | `<Record_Author>` |
| `Ui/Qr` | `Rsx_Qr_Code` |
| `Settings` | `Rsx_Settings`, the `Has_Preference_Variables` trait |
| `Operations/Health` | `rsx:health` / `rsx:heal`: the runner and the `#[Health_Check]` rows (`CLAUDE.md`) |
| `Operations/Metrics` | `Rsx_Metrics` |
| `Operations/Logging` | `rsx:logrotate`, log maintenance, the log timezone tap |
| `Developer/Debug` | `rsx:debug` support, `console_debug` channels, `Rsx_Diagnostics`, the JS tree debug components |
| `Developer/Ide` | The IDE bridge grant and service |
| `Developer/Scaffolding` | `StubProcessor` and the code-generation stubs |
| `Developer/Conformance` | `Conformance_Audits` (`rsx:conformance:*`) |
| `Support/Process` | `Process_Identity` |
| `Support/Fingerprint` | `Rsx_Fingerprint` |

What stays in `Core/` (the engine) is listed in `Core/CLAUDE.md`; files, previews and search,
time, the external API and companion applications are engine subsystems.
