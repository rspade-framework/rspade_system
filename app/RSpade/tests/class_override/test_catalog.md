# Test Catalog: class_override

| ID | Purpose (what it proves) | Type | Input | Expected | Status | Last updated |
|----|--------------------------|------|-------|----------|--------|--------------|
| CO-A01 | A twin named by the index but absent from DISK shadows nothing (the 2026-08-25 stale-list repro) | php | synthetic file list naming a deleted rsx/ twin + a framework file | map empty, framework record live, stale entry dropped and reported | implemented | 2026-10-14 |
| CO-A02 | A build that already marked its manifest bad shadows nothing at all | php | override on disk, `manifest_is_bad = true` | map empty, both records live, notice printed | implemented | 2026-10-14 |
| CO-A03 | POSITIVE CONTROL: a real twin IS shadowed - recorded in the map, its record moved to `shadowed_files` - and the notice names both files | php | override on disk, healthy index | map entry `{kind, name, by, fqcn, by_fqcn}`, loader map pair, both paths in the notice | implemented | 2026-10-14 |
| CO-D01 | The member reader sees the declared public surface and nothing else (privates, method locals and nested anonymous classes excluded) | php | one fixture class with every shape | 6 members, staticness recorded | implemented | 2026-09-07 |
| CO-D02 | The reader reports nothing for a class the file does not declare | php | file declaring a different class | empty | implemented | 2026-09-07 |
| CO-D03 | A method the framework file declares and the override does not is ONE finding naming class, member and both files | php | fixture pair | 1 violation, HIGH | implemented | 2026-09-07 |
| CO-D04 | An override that lacks nothing reports nothing, however much it adds | php | fixture pair | 0 violations | implemented | 2026-09-07 |
| CO-D05 | A missing PROPERTY is a finding too | php | fixture pair | 1 violation naming the property | implemented | 2026-09-07 |
| CO-D06 | A missing `use Trait;` adoption is a finding (it drops every member the trait supplied) | php | fixture pair | 1 violation naming the adoption | implemented | 2026-09-07 |
| CO-D07 | The finding lists the override's OWN additions as INFO context, and offers the seam alternative | php | fixture pair with additions | suggestion names each addition + Staff_Authorizable | implemented | 2026-09-07 |
| CO-D08 | An override with no additions is told it can simply be deleted | php | pure stale copy | suggestion says restoring loses nothing | implemented | 2026-09-07 |
| CO-D09 | `@CLASS-OVERRIDE-DRIFT-01-EXCEPTION` in the OVERRIDE file suppresses it | php | marker in the override | 0 violations | implemented | 2026-09-07 |
| CO-H01 | Health is OK when no override is active | php | empty override map | OK | implemented | 2026-10-14 |
| CO-H02 | A drifting override WARNs, counts the missing members and names them; never FAILs | php | synthetic override map entry + fixture files | WARN, "1 member(s)", rsx:check in the remediation | implemented | 2026-10-14 |
| CO-H03 | An override that carries everything is reported OK and still counted | php | synthetic override map entry + fixture files | OK, "1 class override(s) active" | implemented | 2026-10-14 |
| CO-A04 | An override of a SPLIT framework class that does not extend its base is a manifest-build FATAL naming the base | php | synthetic file list: framework `X extends X_Abstract`, rsx/ `X extends Rsx_Site_Model_Abstract` | RuntimeException naming X_Abstract, the override file and rsx:man class_override | implemented | 2026-10-14 |
| CO-A05 | An override of a split class that DOES extend the base is shadowed normally | php | both extend X_Abstract | framework shell in the map, notice names the override | implemented | 2026-10-14 |
| CO-A06 | Extending the shell itself is refused, and the refusal tells a split-class developer to extend the base | php | rsx/ `X extends X` over a split framework `X extends X_Abstract` | message contains the split recipe and `extends X_Abstract` | implemented | 2026-09-08 |
| CO-A07 | Extending a NON-split framework class still gets the clone-and-replace recipe | php | rsx/ `X extends X` over framework `X extends Rsx_Controller_Abstract` | message contains "Copy the framework file to your rsx/ directory" | implemented | 2026-09-08 |
| CO-D10 | A split pair (framework shell + override sharing the base) reports NO drift - the override inherits every member | php | fixture pair both `extends X_Abstract` | missing 0, added 0 | implemented | 2026-09-08 |
| CO-D11 | A namespaced/qualified `extends` names the same base | php | override extends `\App\RSpade\Fixture\X_Abstract` | missing 0 | implemented | 2026-09-08 |
| CO-D12 | A clone of a NON-split class is still compared in full | php | fixture pair, no extends, one member dropped | 1 violation | implemented | 2026-09-08 |
| CO-D13 | `declared_parent()` answers the SIMPLE name and ignores comments and strings | php | source with decoy comment/string plus a real qualified extends | 'Right_Parent'; null when nothing is extended | implemented | 2026-09-08 |
| CO-S01 | Every framework concrete model extends `<Same>_Abstract` declared in the same directory | php | `php_get_extending('Rsx_Model_Abstract')`, framework files, minus `Session` and fixtures | no offenders | implemented (RED until the split lands) | 2026-09-08 |
| CO-S02 | A framework model's concrete shell declares no methods, properties or constants of its own | php | the same set; reflection records plus a token pass for class-body `const` | no offenders | implemented (RED until the split lands) | 2026-09-08 |
| CO-S03 | Every split base has exactly one concrete - the framework shell, or the application class that replaced it | php | bases derived as `<concrete>_Abstract`; concretes framework AND application | exactly one per base | implemented | 2026-09-08 |

CO-A01..A07 (`Override_Shadow_Guard_Test`). A01..A03 come from a downstream field report on
2026-08-25. A new framework-core JS class tripped a scan rule on its first scan while the index still
carried the just-deleted rsx/ copies of those class names; the failure set `manifest_is_bad`, the next
build discarded the manifest and ran the override pass against the surviving stale list, and the new
core files were taken out of the tree as framework twins of app classes that no longer existed.
Nothing was printed. The derivation re-checks the rsx/ twin against disk before shadowing anything,
shadows nothing in a build that has poisoned its own manifest, and announces every file entering or
leaving the shadow by name.

CO-D01..D09 / CO-H01..H03 (`Class_Override_Drift_Test`) come from a downstream field report on
2026-09-07. An application had cloned a core model to attach four additions; the framework later
added a background render queue to that model and CALLED into it. Three members never reached the
app copy: every uploaded document silently failed to queue a render (6,347 blobs), and the
framework's own preview controller answered 500 on every Office document because the loaded class
did not define the method it called. Nothing compared the two files. The rule is that comparison,
and the health row is the operator-facing view of it on a box that has just taken a pull.

CO-S01..S03 (`Core_Model_Split_Test`) are B-109 as a structural assertion. The 2026-09-07 field
report found five downstream overrides of core models drifted by hundreds of lines, one of them
missing members core later added and CALLED. The answer is that a core model is an abstract base
plus a three-line shell, so an application's override EXTENDS the base and its drift surface is
only what it declared. These three tests are that shape stated over the real tree; CO-A04..A07
are the manifest refusal that stops a clone coming back in by the side door, and CO-D10..D13 are
the drift analyzer knowing a split pair has nothing to compare.
| CO-T01 | An application template shadows the framework TEMPLATE of that component name and leaves the framework's class and stylesheet of that name live | php | framework trio + an rsx template, differently named file | only the `.jqhtml` in the map; notice names both files | implemented | 2026-10-14 |
| CO-T02 | An application stylesheet wrapped in the class of a framework stylesheet shadows NOTHING: both stay live, and `scss` is no override kind | php | framework template + stylesheet, rsx `some_styles.scss` wrapped in the same class | map empty; both stylesheets in `files`; `kind_of()` null for scss | implemented | 2026-10-15 |
| CO-T03 | Nothing is shadowed when the application declares no such name | php | framework files only | map empty | implemented | 2026-10-14 |
| CO-T04 | An override named by the index but absent from disk shadows nothing | php | stale rsx entry | map empty; "stale index entry" | implemented | 2026-10-14 |
| CO-T05 | A build that marked its manifest bad shadows nothing | php | real override, `manifest_is_bad` | map empty; "NOT shadowing" | implemented | 2026-10-14 |
| CO-T09 | Two application templates of one component name are a FATAL naming both files | php | two rsx jqhtml entries of one name | RuntimeException "Duplicate jqhtml component" | implemented | 2026-10-15 |
| CO-T10 | Two application stylesheets wrapped in one class (beside a framework stylesheet of that class) are not refused and shadow nothing | php | framework stylesheet + two rsx stylesheets, one wrapper class | no exception; map empty; all three in `files` | implemented | 2026-10-15 |
| CO-M01 | Adding an override of each kind (php, js, jqhtml) to a built tree shadows each framework file in ONE pass, and an application stylesheet wrapped in a framework stylesheet's class shadows nothing: map records the kind, `by`, and (php) both FQCNs; the record leaves `files`, stays in `file_index` and the cold half; the class map and component registry name the override; the loader artifact carries the php pair; nothing in the framework fixture tree changes | php (child builds) | fixture trees under `resource/` dirs, scratch `--_rsx-build-root` | all of the above; `MANIFEST_RESTARTS=0` | implemented | 2026-10-14 |
| CO-M02 | An unchanged rebuild with overrides is a no-op with the same build key | php (child builds) | same tree, built again | "Rebuilt: no", equal `build_key` | implemented | 2026-10-14 |
| CO-M03 | Removing the overrides returns every framework file to the live tree, reflected, in one pass, writing nothing in the framework tree | php (child builds) | overrides deleted | map and loader map empty, framework record carries `abstract` and its own method map, class map and registry name the framework files, hashes unchanged | implemented | 2026-10-14 |
| CO-M04 | Two application templates of one name fail a real child build | php (child build) | two rsx jqhtml files, one id | non-zero exit naming the component and both files | implemented | 2026-10-14 |
| CO-M09 | The downstream shape: application classes sharing their names with REAL (non-symlink) classes in framework data directories - a copy of the reference app under `app/RSpade/resource/` (one class with the identical namespace and name, one in the root namespace like `Main`), a `resource/` directory inside a scanned framework root and a `public/` directory inside one - build as ordinary application classes | php (child build) | fixture classes under `app/RSpade/resource/<tag>/reference_app/`, `app/RSpade/temp/resource/<tag>/excluded/`, `<framework root>/public/`; rsx namesakes | build exits 0, `MANIFEST_RESTARTS=0`; override map and loader map empty; each name's `php_classes` row and `autoloader_class_map` entry name only the application class | implemented | 2026-10-15 |
| CO-M05 | An override of a boot class (`Class_Override_Boot_Set`) is refused | php | synthetic list: framework `App\RSpade\Core\Rsx` + rsx `Rsx` | "'Rsx' cannot be overridden", "while the framework boots" | implemented | 2026-10-14 |
| CO-M06 | An application class named like a class in an unindexed framework subtree is refused | php | rsx `Manifest_Build_Command` | names `App\RSpade\Commands\Rsx\Manifest_Build_Command`, "does not index" | implemented | 2026-10-14 |
| CO-M07 | An override of a framework class already declared in the building process is refused | php | framework record naming the loaded test class | "already declared in this process" | implemented | 2026-10-14 |
| CO-M08 | A split base naming its own shell in a method signature is refused (a return type, past an attribute and a comment); naming the base or `static` is accepted | php | fixture `X_Abstract` + shell `X` | "X_Abstract::make() names its own overridable class X", file:line; accepted once rewritten | implemented | 2026-10-14 |
| CO-L01 | The override loader is first on the autoload stack in a booted process | php | `spl_autoload_functions()[0]` | `Class_Override_Loader::load` | implemented | 2026-10-14 |
| CO-L02 | A mapped framework name resolves to the override; the shadowed framework file is never included | php | tmp fixture: framework base + shell + override in an application namespace | ReflectionClass name is the override; shell not in the include table | implemented | 2026-10-14 |
| CO-L03 | A name that is not an exact key is declined (no simple-name matching) | php | unmapped FQCN, `Illuminate\Support\Collection` | `load()` false | implemented | 2026-10-14 |
| CO-L04 | An override loaded by its OWN name through the include seam satisfies a framework parameter and return type naming the framework class, before anything autoloaded the framework name | php | fixture consumer `take(Target)` / `give(): Target` | accepted; instanceof and string is_a() hold | implemented | 2026-10-14 |
| CO-L05 | `alias_all()` makes instanceof hold for an override declared behind the seam's back | php | override required directly | false before, true after | implemented | 2026-10-14 |
| CO-L06 | A framework name already declared as the framework's own class throws, naming both | php | shell required, then mapped | LogicException from `load()` and `alias_all()` | implemented | 2026-10-14 |
| CO-L07 | Composer asked first answers with the trip-wire, which names the requested class and the override | php | `ClassLoader::loadClass()` on a mapped name | LogicException; nothing declared | implemented | 2026-10-14 |
| CO-L08 | A key that leaves the map gives composer its own answer back | php | map set, then restored | `findFile()` answers the framework file | implemented | 2026-10-14 |
| CO-L09 | The include seam refuses a shadowed framework file | php | `include_declaration(shell)` | RuntimeException; file not included | implemented | 2026-10-14 |
| CO-L10 | The map artifact round-trips, a missing one is empty, an unchanged one is not rewritten | php | tmp artifact | equal map; same mtime; no temp file | implemented | 2026-10-14 |
| CO-LC01 | A fresh process on the committed OPTIMISED classmap resolves real framework FQCNs (a copy-and-replace class and a split model) to probe overrides | cli | child requiring `system/vendor/autoload.php`, map in a scratch `--_rsx-build-root` | probes resolve; trip-wire in composer; shells never included | implemented | 2026-10-14 |
| CO-LC02 | The same with an AUTHORITATIVE classmap | cli | child sets `setClassMapAuthoritative(true)` | same | implemented | 2026-10-14 |
| CO-LC03 | With the override loader unregistered, composer hits the trip-wire, never the framework file | cli | child unregisters the loader | message names both classes; nothing declared | implemented | 2026-10-14 |
| CO-LC04 | No map file is an empty map; the framework class loads as itself | cli | scratch build root without a map | empty map; framework FQCN | implemented | 2026-10-14 |
| CO-LC05 | A framework base whose signature names its own overridable class, redeclared by the override with its own name, fails loud: by the override's name with the loader's explanation, by the framework name with PHP's "is not available" | cli | fixture base + override, two children | non-zero exit, nothing after the declaration ran, the named message | implemented | 2026-10-14 |

CO-L01..L10 (`Override_Loader_Test`) and CO-LC01..LC05 (`tests/class_override/cli/Override_Loader_Cli_Test`)
cover the override loader. Two PHP rules shape it: PHP's inheritance check
DOES autoload when the two spellings of a type differ, so an aliased framework name satisfies it; PHP's
parameter and return type checks do NOT autoload, which is why the alias is made the moment the override
is included (CO-L04) and why a framework base must never name its own overridable class in a signature
(CO-LC05).
| CO-L01 | `rsx:overrides:list` with no override in force prints "No class overrides." and exits 0; `--json` is `{"overrides": []}` | cli | empty synthetic map | that line, exit 0, empty list | implemented | 2026-10-14 |
| CO-L02 | A PHP override that lacks a framework member is listed with "1 missing", the member named under the table, and a pointer at rsx:check | cli | synthetic map + fixture pair under rsx/resource/ | row, count, member, rsx:check | implemented | 2026-10-14 |
| CO-L03 | A copy carrying every member is "current"; a split override extending the base is "extends base" | cli | fixture pairs | both verdicts | implemented | 2026-10-14 |
| CO-L04 | A jqhtml override is listed by component name with the framework path spelled from the project root (`system/...`) and no drift | cli | synthetic jqhtml entry | kind, name, both paths | implemented | 2026-10-14 |
| CO-L05 | `--json`: one object per override with kind, name, override_file, framework_file, fqcn, by_fqcn, drift (null for an asset kind) | cli | php + jqhtml entries | field list, verdict, missing members | implemented | 2026-10-14 |
