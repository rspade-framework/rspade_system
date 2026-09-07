# Test Catalog: class_override

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| CO-P01 | Include warning from composer ClassLoader.php is tolerated | php | `_should_tolerate_classloader_warning(E_WARNING, '.../vendor/composer/ClassLoader.php')` | true | implemented | 2026-07-24 |
| CO-P02 | Same-level warning from any other file is NOT tolerated (stays fail-loud) | php | `(E_WARNING, '.../rsx/models/foo_model.php')` | false | implemented | 2026-07-24 |
| CO-P03 | A non-warning (E_ERROR) from ClassLoader.php is NOT swallowed | php | `(E_ERROR, '.../vendor/composer/ClassLoader.php')` | false | implemented | 2026-07-24 |
| CO-P04 | Backslash paths normalized before suffix match | php | `(E_WARNING, 'C:\\...\\vendor\\composer\\ClassLoader.php')` | true | implemented | 2026-07-24 |
| CO-P05 | Only ClassLoader.php basename matches (not autoload_real.php) | php | `(E_WARNING, '.../vendor/composer/autoload_real.php')` | false | implemented | 2026-07-24 |
| CO-P06 | Matching warning is swallowed: handler returns true, no throw | php | `_handle_php_error(E_WARNING, msg, ClassLoader.php, 577)` | returns true | implemented | 2026-07-24 |
| CO-P07 | Non-matching error is delegated to the previous handler | php | injected spy previous handler + non-matching warning | spy invoked | implemented | 2026-07-24 |
| CO-P08 | A swallowed warning never delegates | php | injected spy + matching warning | spy NOT invoked | implemented | 2026-07-24 |
| CO-V01 | Detector surfaces exactly the missing-file entry | php | fixture classmap {present, missing} | 1 stale (the missing FQCN) | implemented | 2026-07-24 |
| CO-V02 | All-present classmap reports no staleness | php | fixture classmap {present, present} | empty | implemented | 2026-07-24 |
| CO-V03 | Stale classmap triggers the dump runner with the stale entries | php | stale fixture + spy runner | spy invoked with stale set | implemented | 2026-07-24 |
| CO-V04 | Clean classmap does NOT trigger a dump | php | clean fixture + spy runner | spy NOT invoked | implemented | 2026-07-24 |
| CO-V05 | Missing classmap file is a silent no-op | php | nonexistent path + spy runner | no throw, spy NOT invoked | implemented | 2026-07-24 |
| CO-E01 | Full override: framework file -> .upstream, validator dumps, old FQCN aliases to override | e2e | override `Rsx_Webhook` + rebuild + tinker ref | resolves to `Rsx\Lib\Rsx_Webhook`, no crash | deferred (manual - mutates real vendor/framework tree) | 2026-07-24 |
| CO-E02 | Mid-transition: with stale classmap restored, old FQCN STILL resolves via tolerance | e2e | restore stale classmap + tinker ref | resolves to override, no crash, classmap stays stale | deferred (manual) | 2026-07-24 |
| CO-E03 | Remove override: .upstream restored, classmap consistent, old FQCN resolves to framework | e2e | delete override + rebuild + tinker ref | resolves to framework class, no `which()` marker | deferred (manual) | 2026-07-24 |
| CO-V06 | Real composer dump fail-loud on non-zero exit | php | forced composer failure | RuntimeException with output | deferred (would require breaking composer; seam covers invocation) | 2026-07-24 |
| CO-V07 | The classmap heal invokes composer with --no-scripts (composer's post-autoload-dump spawns `artisan package:discover`, which the framework-update maintenance gate blocks with exit 75 - the pull's argv override cannot reach a grandchild) | php | reflect `_run_composer_dump()` source | contains `composer dump-autoload` AND `--no-scripts` | implemented | 2026-08-05 |
| CO-A01 | A twin named by the index but absent from DISK archives nothing (the 2026-08-25 stale-list repro) | php | synthetic file list naming a deleted rsx/ twin + a real framework file | framework file untouched, no `.upstream`, stale entry reported | implemented | 2026-08-27 |
| CO-A02 | A build that already marked its manifest bad archives nothing at all | php | both files on disk, `manifest_is_bad = true` | framework file untouched, notice printed | implemented | 2026-08-27 |
| CO-A03 | POSITIVE CONTROL: a real twin IS archived, and the notice names both files | php | both files on disk, healthy index | framework file renamed to `.upstream`, both paths in the notice | implemented | 2026-08-27 |

CO-A01..A03 (`Override_Archive_Guard_Test`) come from a downstream field report on 2026-08-25. A new
framework-core JS class tripped a scan rule on its first scan while the index still carried the
just-deleted rsx/ copies of those class names; the failure set `manifest_is_bad`, the next build
discarded the manifest and ran the override pass against the surviving stale list, and the new core
files were renamed to `.js.upstream` as framework twins of app classes that no longer existed. Nothing
was printed. The pass now re-checks the rsx/ twin against disk before archiving anything, refuses to
archive at all in a build that has poisoned its own manifest, and announces every archive by name.

