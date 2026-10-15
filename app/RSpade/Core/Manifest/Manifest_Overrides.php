<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Manifest;

use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Manifest\Manifest_Indexer;
use App\RSpade\Core\Naming\Rsx_Paths;
use App\RSpade\Core\Overrides\Class_Override_Boot_Set;

/**
 * Manifest_Overrides - THE OVERRIDE MAP: which framework PHP classes the application overrides.
 *
 * An application overrides a framework PHP class by declaring, under rsx/, a class of the same
 * simple name (rsx:man class_override). Only PHP classes are overridable:
 *
 *   - A JS class (.js, .jsx, .ts, .tsx) or a jqhtml component (<Define:Name>) declared under
 *     rsx/ with the name of a framework JS class or component is REFUSED - a manifest-build
 *     FATAL naming both files. It is neither shadowed nor ignored: either silent outcome would
 *     leave two definitions of one name in a bundle. Browser-side framework behaviour an
 *     application changes is changed through an extension point the framework publishes for
 *     it (Button_Utils.set_loader(), an event, a registration call).
 *   - A stylesheet is never an override and is never overridden: an application stylesheet
 *     that styles a framework component is compiled alongside the framework's own, after it.
 *
 * A class name declared by exactly ONE application file and by at least one framework file is
 * an override: every framework file declaring it is SHADOWED by the application file. Nothing
 * on disk moves. The framework file stays where it is, under its own name, and the index
 * records the fact instead:
 *
 *   data.overrides       (HOT, ksorted) shadowed framework path => [
 *                            'name'    => the class's simple name,
 *                            'by'      => the application file that overrides it,
 *                            'fqcn'    => the framework class,
 *                            'by_fqcn' => the application class,
 *                        ]
 *   data.shadowed_files  (COLD) the shadowed files' records, moved OUT of `files`
 *
 * WHY THE RECORD LEAVES `files`. Every consumer of the live index - the class maps, the
 * support modules, the code-quality rules, the bundle and route builders, Manifest::get_all()
 * - is then correct with no override awareness at all: a shadowed file is simply not part of
 * the tree they see. It stays in `file_index` and in the build key (path and content hash,
 * never mtime), so change detection treats it as indexed and an edit to it is still a change.
 * Each build pass merges the shadowed records back into the working set before discovery,
 * so detecting a change or a removal works the same for every file.
 *
 * DERIVED IN FULL, EVERY PASS. The map is a pure function of the working set and the disk,
 * recomputed from scratch at the end of Phase 2 - after Php_Fixer, before Phase 3 loads a
 * class - so it can never drift the way a carried-forward section can.
 *
 * WHAT IT REFUSES (each a manifest-build FATAL):
 *   - an application JS class or jqhtml component with the name of a framework one;
 *   - two application files declaring one PHP class name: nothing can choose between them
 *     (two application files declaring one JS class or one component are refused by the
 *     class-map and component-name duplicate detectors);
 *   - an override that extends the class it overrides;
 *   - an override of a SPLIT class (`X extends X_Abstract`) that does not extend X_Abstract;
 *   - an override of a class the framework declares before an override can answer for it
 *     (Class_Override_Boot_Set), or of one already declared in the building process;
 *   - an application class named like a framework class the manifest never indexes but the
 *     framework can load (the unscanned framework subtrees, and system/app/{Console,Http,...};
 *     never a resource/, public/ or other excluded directory, which holds data): it could be
 *     neither an override nor a distinct class;
 *   - a split base `X_Abstract` naming its own shell `X` in a method signature, which no
 *     override that redeclares that method can ever link against.
 *
 * Read through the facade: Manifest::overrides(), is_shadowed(), override_for(),
 * shadowed_fqcn_map().
 *
 * @internal Driven by Manifest::_refresh_manifest().
 */
class Manifest_Overrides
{
    /** Every extension a JavaScript class is declared in. */
    public const JS_EXTENSIONS = ['js', 'jsx', 'ts', 'tsx'];

    /** Test-tree namespaces: indexed only while a test run is in progress. */
    private const TEST_TREE_NAMESPACES = ['App\\RSpade\\Tests\\', 'App\\RSpade\\Temp\\'];

    /** simple name => FQCN, for the framework application directories outside app/RSpade. */
    private static ?array $__unscanned_application_classes = null;

    /**
     * Derive the override map over the working set, and move every shadowed record out of
     * `files` into `shadowed_files`.
     *
     * Writes Manifest::$data['data']['overrides'] and ['shadowed_files'].
     *
     * @param array<int,string> $previously_shadowed Paths that were shadowed when this pass began
     * @param array<int,string> $changed_files       Files this build re-parsed (the split-signature check reads these)
     * @return array{entering: array<int,string>, leaving: array<int,string>, dropped: array<int,string>}
     *         entering: shadowed now and not before; leaving: shadowed before, live now;
     *         dropped: application entries the index named but the disk no longer has
     */
    public static function derive(array $previously_shadowed, array $changed_files): array
    {
        $files = &Manifest::$data['data']['files'];

        // PHP class name => side => [paths]
        $php_declarers = [];

        // js | jqhtml => name => side => [paths]
        $browser_declarers = [];

        foreach ($files as $path => $record) {
            $extension = $record['extension'] ?? '';

            if ($extension === 'php') {
                $kind = 'php';
                $name = $record['class'] ?? null;
            } elseif ($extension === 'jqhtml') {
                $kind = 'jqhtml';
                $name = $record['id'] ?? null;
            } elseif (in_array($extension, self::JS_EXTENSIONS, true)) {
                $kind = 'js';
                $name = $record['class'] ?? null;
            } else {
                continue;
            }

            if (!is_string($name) || $name === '') {
                continue;
            }

            $side = Rsx_Paths::is_application($path)
                ? 'application'
                : (Rsx_Paths::is_framework($path) ? 'framework' : 'other');

            if ($kind === 'php') {
                $php_declarers[$name][$side][] = $path;
            } else {
                $browser_declarers[$kind][$name][$side][] = $path;
            }
        }

        $dropped = self::__refuse_browser_namesakes($browser_declarers);

        $map = [];

        foreach ($php_declarers as $name => $sides) {
            $application = $sides['application'] ?? [];
            $framework = $sides['framework'] ?? [];
            $other = $sides['other'] ?? [];

            if (count($application) > 1) {
                throw new \RuntimeException(self::__two_application_declarations_message($name, $application));
            }

            if (count($application) === 1 && count($framework) >= 1 && $other === []) {
                $application_file = $application[0];

                $settled = self::__settle_override($name, $application_file, $framework);

                if ($settled === 'dropped') {
                    $dropped[] = $application_file;

                    continue;
                }

                if ($settled === 'declined') {
                    continue;
                }

                foreach ($framework as $framework_file) {
                    $map[$framework_file] = self::__record($name, $application_file, $framework_file);
                }

                continue;
            }

            // A class name is unique across the tree; anything else declaring one twice is
            // a duplicate.
            if (count($application) + count($framework) + count($other) > 1) {
                throw new \RuntimeException(
                    "Fatal: Duplicate PHP class name '{$name}' found in multiple files:\n"
                    . '  - ' . implode("\n  - ", array_merge($application, $framework, $other)) . "\n"
                    . 'Each class name must be unique within the same file type.'
                );
            }
        }

        foreach ($dropped as $path) {
            unset($files[$path]);
        }

        self::__assert_no_unindexed_framework_namesake($php_declarers);
        self::__assert_split_bases_never_name_their_shell($changed_files);

        ksort($map);

        $shadowed = [];
        foreach (array_keys($map) as $path) {
            $shadowed[$path] = $files[$path];
            unset($files[$path]);
        }

        Manifest::$data['data']['overrides'] = $map;
        Manifest::$data['data']['shadowed_files'] = $shadowed;

        $previous = array_flip($previously_shadowed);
        $entering = [];
        $leaving = [];

        foreach ($map as $path => $record) {
            if (!isset($previous[$path])) {
                $entering[] = $path;

                // Announced as it happens - a file leaving the live tree is always attributable
                // to the override that took it out. Silent on every pass after.
                error_log(
                    '[Manifest] Override: ' . $record['name'] . ' - the framework class file '
                    . $path . ' is shadowed by ' . $record['by']
                );
            }
        }

        foreach ($previously_shadowed as $path) {
            if (!isset($map[$path]) && isset($files[$path])) {
                $leaving[] = $path;

                error_log('[Manifest] Override removed: the framework file ' . $path . ' is live again');
            }
        }

        return ['entering' => $entering, 'leaving' => $leaving, 'dropped' => $dropped];
    }

    /**
     * The class override loader's map, projected from the override map:
     * framework FQCN => ['by_fqcn', 'by_file', 'file'], paths relative to the framework tree.
     *
     * @param array<string,array> $overrides Manifest::$data['data']['overrides']
     * @return array<string, array{by_fqcn: string, by_file: string, file: string}>
     */
    public static function loader_map(array $overrides): array
    {
        $map = [];

        foreach ($overrides as $path => $record) {
            $map[$record['fqcn']] = [
                'by_fqcn' => $record['by_fqcn'],
                'by_file' => $record['by'],
                'file' => $path,
            ];
        }

        ksort($map);

        return $map;
    }

    // -----------------------------------------------------------------------
    // JS and jqhtml: not overridable
    // -----------------------------------------------------------------------

    /**
     * Refuse every application JS class and jqhtml component that carries the name of a
     * framework one. Returns the application entries the index named but the disk no longer
     * has: those are stale, collide with nothing, and are dropped.
     *
     * Two application files of one name with no framework namesake are not this pass's
     * concern: the JS class map (Manifest_Indexer::_collate_files_by_classes()) and the
     * component-name check (Manifest_Store::_validate_manifest_data()) refuse those.
     *
     * @param array<string, array<string, array<string, array<int,string>>>> $browser_declarers kind => name => side => paths
     * @return array<int,string>
     */
    private static function __refuse_browser_namesakes(array $browser_declarers): array
    {
        $dropped = [];

        foreach ($browser_declarers as $kind => $names) {
            foreach ($names as $name => $sides) {
                if (empty($sides['application']) || empty($sides['framework'])) {
                    continue;
                }

                foreach ($sides['application'] as $application_file) {
                    // A stale index entry (the file was deleted since the last build) is not
                    // a collision - a downstream field report, 2026-08-25, is why the disk is
                    // asked before a name in the file list is believed.
                    if (!file_exists(base_path($application_file))) {
                        error_log(
                            '[Manifest] Override pass: ignoring stale index entry for ' . $name . ' - '
                            . $application_file . ' is in the file list but not on disk. The entry is dropped.'
                        );

                        $dropped[] = $application_file;

                        continue;
                    }

                    throw new \RuntimeException(self::__browser_namesake_message($kind, $name, $application_file, $sides['framework']));
                }
            }
        }

        return $dropped;
    }

    /**
     * @param array<int,string> $framework_files
     */
    private static function __browser_namesake_message(string $kind, string $name, string $application_file, array $framework_files): string
    {
        $what = $kind === 'jqhtml' ? "jqhtml component '{$name}'" : "JavaScript class '{$name}'";
        $kind_name = $kind === 'jqhtml' ? 'jqhtml component' : 'JavaScript class';

        return "Fatal: {$what} is not overridable.\n\n"
            . "  Application: {$application_file}\n"
            . '  Framework:   ' . implode("\n               ", $framework_files) . "\n\n"
            . "The application declares a framework {$kind_name} name. Only PHP\n"
            . "classes are overridable: JavaScript classes and jqhtml components are\n"
            . "not. The framework file is not shadowed and the application file is\n"
            . "not ignored - two definitions of one name cannot share a bundle - so\n"
            . "the build stops.\n\n"
            . "Rename the application class or component and use it by its own name,\n"
            . "or change the behaviour through an extension point the framework\n"
            . "publishes for it (a registration call such as Button_Utils.set_loader(),\n"
            . "an event hook, a subclass under a name of your own). Behaviour with no\n"
            . "extension point needs one: that is a framework change request.\n\n"
            . 'See: php artisan rsx:man class_override';
    }

    // -----------------------------------------------------------------------
    // One override, settled
    // -----------------------------------------------------------------------

    /**
     * Decide one application/framework PHP pair: 'shadow', 'dropped' (the application file
     * is not on disk) or 'declined' (the build has poisoned its own index). Every refusal throws.
     *
     * @param array<int,string> $framework_files
     */
    private static function __settle_override(string $name, string $application_file, array $framework_files): string
    {
        // A build that has already poisoned its own manifest (manifest_is_bad) has declared its
        // file list untrustworthy. It shadows nothing: the two declarations stay in the tree,
        // the class maps refuse the duplicate, and the next build - which starts from the disk -
        // decides.
        if (Manifest::$_manifest_is_bad) {
            error_log(
                '[Manifest] Override pass: NOT shadowing the framework class file of ' . $name
                . ' - this build already marked its manifest bad, so its file list is not evidence of '
                . 'anything. The next build decides.'
            );

            return 'declined';
        }

        // THE OVERRIDE MUST BE ON DISK NOW. A field report on 2026-08-25 is why: a scan rule
        // failed on a brand-new framework class while the index still carried just-deleted
        // rsx/ copies of the same class names, the failure poisoned the manifest, and the next
        // build took the framework classes out of the tree as twins of application classes that
        // no longer existed. An entry the disk does not back is dropped and shadows nothing.
        if (!file_exists(base_path($application_file))) {
            error_log(
                '[Manifest] Override pass: ignoring stale index entry for ' . $name . ' - '
                . $application_file . ' is in the file list but not on disk. Nothing is shadowed; '
                . 'the entry is dropped.'
            );

            return 'dropped';
        }

        self::__assert_override_shape($name, $application_file, $framework_files[0]);

        foreach ($framework_files as $framework_file) {
            self::__assert_overridable($name, $application_file, $framework_file);
        }

        return 'shadow';
    }

    /**
     * The override map record for one shadowed framework file.
     */
    private static function __record(string $name, string $application_file, string $framework_file): array
    {
        return [
            'name' => $name,
            'by' => $application_file,
            'fqcn' => self::__fqcn(Manifest::$data['data']['files'][$framework_file]),
            'by_fqcn' => self::__fqcn(Manifest::$data['data']['files'][$application_file]),
        ];
    }

    /**
     * The extends-itself refusal and the SPLIT refusal.
     */
    private static function __assert_override_shape(string $name, string $application_file, string $framework_file): void
    {
        $application_extends = Manifest::$data['data']['files'][$application_file]['extends'] ?? null;
        $framework_extends = Manifest::$data['data']['files'][$framework_file]['extends'] ?? null;

        // A SPLIT class: the framework ships an abstract base carrying every member and a
        // concrete shell named after it. `X extends X_Abstract` is the whole signature - the
        // base is the framework's, the shell is what an override replaces.
        $split_base = ($framework_extends === $name . '_Abstract') ? $framework_extends : null;

        if ($application_extends === $name) {
            $correct = $split_base !== null
                ? "CORRECT OVERRIDE PATTERN FOR A SPLIT FRAMEWORK CLASS:\n"
                . "  The framework carries every member of {$name} on the abstract\n"
                . "  base {$split_base}, and ships {$name} as an empty shell so an\n"
                . "  application can replace it. Extend the BASE, not the shell:\n\n"
                . "     class {$name} extends {$split_base}\n"
                . "     {\n"
                . "         // only the members this application changes\n"
                . "     }\n\n"
                . "  Your class then overrides {$name} for the whole tree ({$framework_file} is\n"
                . "  shadowed and never loaded), still inheriting everything the framework adds\n"
                . "  to the base from now on."
                : "CORRECT OVERRIDE PATTERN:\n"
                . "  1. Copy the framework file to your rsx/ directory:\n"
                . "     cp system/{$framework_file} {$application_file}\n\n"
                . "  2. Customize the copy as needed (change namespace, add methods, etc.)\n\n"
                . "  3. The manifest detects the override: your class answers for {$name}\n"
                . "     everywhere, and the framework file is shadowed - left in place and\n"
                . "     never loaded.\n\n"
                . "This pattern replaces the framework class entirely, allowing full\n"
                . "customization while maintaining the same class name for compatibility.";

            throw new \RuntimeException(
                "Fatal: Invalid class override pattern for '{$name}'.\n\n"
                . "The file {$application_file} extends the framework class {$name},\n"
                . "but RSX requires unique class names - you cannot have two classes\n"
                . "with the same name, even if one extends the other.\n\n"
                . $correct . "\n\n"
                . 'See: php artisan rsx:man class_override'
            );
        }

        // THE OVERRIDE OF A SPLIT CLASS MUST EXTEND ITS BASE. A copy of the framework file is
        // a SECOND IMPLEMENTATION of a class the framework keeps developing: every member the
        // framework adds to the base from then on is absent from the application's copy, and
        // core calls it anyway. That is the exact failure the split exists to end, so it is
        // refused rather than reported.
        if ($split_base !== null && $application_extends !== $split_base) {
            $declared = $application_extends === null || $application_extends === '' ? 'nothing' : $application_extends;

            throw new \RuntimeException(
                "Fatal: Invalid override of the split framework class '{$name}'.\n\n"
                . "  Override: {$application_file} (extends {$declared})\n"
                . "  Required: extends {$split_base}\n\n"
                . "{$name} is a SPLIT framework class: {$framework_file} declares\n"
                . "nothing but `class {$name} extends {$split_base}`, and every member\n"
                . "lives on the base. The shell exists so an application can replace it\n"
                . "WITHOUT holding a copy of the implementation.\n\n"
                . "REWRITE THE OVERRIDE AS:\n\n"
                . "     class {$name} extends {$split_base}\n"
                . "     {\n"
                . "         // only what this application changes\n"
                . "     }\n\n"
                . "A copy of the framework file is a second implementation of a class the\n"
                . "framework keeps developing: every member added to {$split_base} after the\n"
                . "copy was taken is missing from it, and framework code calls those members\n"
                . "on this class regardless.\n\n"
                . 'See: php artisan rsx:man class_override'
            );
        }
    }

    /**
     * A PHP framework class can be overridden only when nothing has declared it before the
     * override can answer for its name.
     */
    private static function __assert_overridable(string $name, string $application_file, string $framework_file): void
    {
        $framework_fqcn = self::__fqcn(Manifest::$data['data']['files'][$framework_file]);
        $application_fqcn = self::__fqcn(Manifest::$data['data']['files'][$application_file]);

        if (strcasecmp($framework_fqcn, $application_fqcn) === 0) {
            throw new \RuntimeException(
                "Fatal: Duplicate PHP class name '{$name}' found in multiple files:\n"
                . "  - {$framework_file}\n  - {$application_file}\n"
                . "Both declare {$framework_fqcn}. An override declares its class in the namespace its\n"
                . "rsx/ path gives it, never in the framework's."
            );
        }

        if (Class_Override_Boot_Set::is_boot_class($framework_fqcn)) {
            throw new \RuntimeException(self::__not_overridable_message(
                $name,
                $application_file,
                $framework_fqcn,
                "{$framework_fqcn} is declared while the framework boots or while the manifest is being\n"
                . 'built - before an override could answer for its name - so no application class can replace it.'
            ));
        }

        if (\App\RSpade\Core\Autoloader::_is_declared($framework_fqcn)) {
            $declared = (new \ReflectionClass($framework_fqcn))->getName();

            if (strcasecmp($declared, $application_fqcn) !== 0) {
                throw new \RuntimeException(self::__not_overridable_message(
                    $name,
                    $application_file,
                    $framework_fqcn,
                    "{$framework_fqcn} was already declared in this process (as {$declared}) before the\n"
                    . 'override map was settled, so this process cannot make it an alias of your class.'
                ));
            }
        }
    }

    /**
     * An application PHP class must not share its simple name with a framework class the
     * manifest never indexes but the framework can still load - the unscanned app/RSpade
     * subtrees (Commands, SchemaQuality, ...) and the framework application directories
     * outside app/RSpade. Such a pair is neither an override (the framework class is never
     * shadowed) nor two distinct classes (RSX resolves classes by simple name).
     *
     * A resource/, public/ or other directory the scanner excludes is data, never loaded
     * code, so a class declared there is no namesake (Manifest_Scanner::_walk_framework_files()).
     *
     * @param array<string, array<string, array<int,string>>> $php_declarers name => side => paths
     */
    private static function __assert_no_unindexed_framework_namesake(array $php_declarers): void
    {
        $unindexed = null;

        foreach ($php_declarers as $name => $sides) {
            if (empty($sides['application'])) {
                continue;
            }

            if ($unindexed === null) {
                $unindexed = self::__unindexed_framework_classes();
            }

            if (!isset($unindexed[$name])) {
                continue;
            }

            throw new \RuntimeException(self::__not_overridable_message(
                $name,
                $sides['application'][0],
                $unindexed[$name],
                "{$unindexed[$name]} lives in a framework directory the manifest does not index, so it\n"
                . 'cannot be shadowed - and RSX resolves classes by simple name, so the two cannot coexist.'
            ));
        }
    }

    /**
     * simple name => one framework FQCN, for every framework class the manifest does not
     * index outside the test trees.
     *
     * @return array<string,string>
     */
    private static function __unindexed_framework_classes(): array
    {
        $classes = [];

        foreach (Manifest_Indexer::_unindexed_framework_classes() as $simple => $fqcns) {
            foreach ($fqcns as $fqcn) {
                $fqcn = ltrim($fqcn, '\\');

                // The test trees are indexed while a test run is in progress and only then;
                // nothing outside a test run resolves a class there.
                foreach (self::TEST_TREE_NAMESPACES as $prefix) {
                    if (str_starts_with($fqcn, $prefix)) {
                        continue 2;
                    }
                }

                $classes[$simple] = $fqcn;
            }
        }

        return $classes + self::__unscanned_application_classes();
    }

    /**
     * simple name => FQCN for system/app/{Console,Database,Exceptions,Http,Notifications,Providers}.
     *
     * @return array<string,string>
     */
    private static function __unscanned_application_classes(): array
    {
        if (self::$__unscanned_application_classes !== null) {
            return self::$__unscanned_application_classes;
        }

        $classes = [];

        foreach (Class_Override_Boot_Set::unscanned_framework_namespaces() as $namespace) {
            $directory = base_path('app/' . str_replace('\\', '/', trim(substr($namespace, strlen('App\\')), '\\')));

            if (!is_dir($directory)) {
                continue;
            }

            foreach (Manifest::_scan_directory_for_classes($directory) as $simple => $fqcns) {
                foreach ($fqcns as $fqcn) {
                    $classes[$simple] = ltrim($fqcn, '\\');
                }
            }
        }

        return self::$__unscanned_application_classes = $classes;
    }

    // -----------------------------------------------------------------------
    // The split-signature rule
    // -----------------------------------------------------------------------

    /**
     * A split base `X_Abstract` never names its own shell `X` in a method signature.
     *
     * An application overrides X by declaring its own X extending X_Abstract. When that class
     * redeclares a method whose signature in X_Abstract names X, PHP compares the two types
     * WHILE the application's X is being declared - when the framework name X cannot yet be its
     * alias - and the override can never link. So the framework spells such a type as
     * X_Abstract (or `static`). Checked for every split pair whose shell or base this build
     * re-parsed.
     *
     * @param array<int,string> $changed_files
     */
    private static function __assert_split_bases_never_name_their_shell(array $changed_files): void
    {
        $files = Manifest::$data['data']['files'];
        $changed = array_flip($changed_files);

        $framework_classes = [];
        foreach ($files as $path => $record) {
            if (($record['extension'] ?? null) === 'php' && !empty($record['class']) && Rsx_Paths::is_framework($path)) {
                $framework_classes[$record['class']] = $path;
            }
        }

        foreach ($framework_classes as $shell => $shell_path) {
            $base = $shell . '_Abstract';

            if (($files[$shell_path]['extends'] ?? null) !== $base || !isset($framework_classes[$base])) {
                continue;
            }

            $base_path = $framework_classes[$base];

            if (!isset($changed[$shell_path]) && !isset($changed[$base_path])) {
                continue;
            }

            $offence = self::__signature_naming($base_path, $files[$base_path]['hash'] ?? '', $base, $shell);

            if ($offence === null) {
                continue;
            }

            throw new \RuntimeException(
                "Fatal: {$base}::{$offence['method']}() names its own overridable class {$shell} in its signature.\n\n"
                . "  {$base_path}:{$offence['line']}\n\n"
                . "An application overrides {$shell} by declaring its own {$shell} that extends {$base}.\n"
                . "PHP checks a redeclared method's signature against this one while that class is still\n"
                . "being declared, when the framework name {$shell} cannot yet be its alias - so an override\n"
                . "that redeclares {$offence['method']}() could never be loaded.\n\n"
                . "Spell the type as {$base}, or as `static` where the method returns the class it was\n"
                . "called on.\n\n"
                . 'See: php artisan rsx:man class_override'
            );
        }
    }

    /**
     * The first method of $class_name in $path whose parameter or return types name $shell,
     * or null.
     *
     * @return array{method: string, line: int}|null
     */
    private static function __signature_naming(string $path, string $hash, string $class_name, string $shell): ?array
    {
        $tokens = Manifest::build()->source_cache()->tokens(base_path($path), $hash);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_FUNCTION) {
                continue;
            }

            $j = self::__next_meaningful($tokens, $i + 1);

            if ($j !== null && $tokens[$j]->text === '&') {
                $j = self::__next_meaningful($tokens, $j + 1);
            }

            // A closure (`function (`) declares no member.
            if ($j === null || !$tokens[$j]->is(T_STRING)) {
                continue;
            }

            $method = $tokens[$j]->text;
            $open = self::__next_meaningful($tokens, $j + 1);

            if ($open === null || $tokens[$open]->text !== '(') {
                continue;
            }

            // Parameter types: everything in each top-level parameter before its variable,
            // attribute groups excepted.
            $depth = 0;
            $in_type = true;
            $close = null;

            for ($k = $open + 1; $k < $count; $k++) {
                $token = $tokens[$k];

                if ($token->is(T_ATTRIBUTE)) {
                    $k = self::__skip_attribute($tokens, $k);

                    continue;
                }

                $text = $token->text;

                if ($text === '(' || $text === '[') {
                    $depth++;

                    continue;
                }

                if ($text === ')' || $text === ']') {
                    if ($depth === 0) {
                        $close = $k;

                        break;
                    }

                    $depth--;

                    continue;
                }

                if ($depth === 0 && $text === ',') {
                    $in_type = true;

                    continue;
                }

                if ($token->is(T_VARIABLE) || $token->is(T_ELLIPSIS)) {
                    $in_type = false;

                    continue;
                }

                if ($in_type && $depth === 0 && self::__names($token, $shell)) {
                    return ['method' => $method, 'line' => $token->line];
                }
            }

            if ($close === null) {
                continue;
            }

            // Return type: between the `:` after the parameter list and the body or `;`.
            $colon = self::__next_meaningful($tokens, $close + 1);

            if ($colon === null || $tokens[$colon]->text !== ':') {
                continue;
            }

            for ($k = $colon + 1; $k < $count; $k++) {
                $text = $tokens[$k]->text;

                if ($text === '{' || $text === ';') {
                    break;
                }

                if (self::__names($tokens[$k], $shell)) {
                    return ['method' => $method, 'line' => $tokens[$k]->line];
                }
            }
        }

        return null;
    }

    /** Does this token name the class $simple (bare or qualified)? */
    private static function __names(\PhpToken $token, string $simple): bool
    {
        if ($token->is(T_STRING)) {
            return $token->text === $simple;
        }

        if ($token->is(T_NAME_QUALIFIED) || $token->is(T_NAME_FULLY_QUALIFIED)) {
            $segments = explode('\\', $token->text);

            return end($segments) === $simple;
        }

        return false;
    }

    /**
     * Index of the `]` closing the attribute group opened at $index.
     *
     * @param array<int,\PhpToken> $tokens
     */
    private static function __skip_attribute(array $tokens, int $index): int
    {
        $depth = 1;
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $text = $tokens[$i]->text;

            if ($text === '[' || $tokens[$i]->is(T_ATTRIBUTE)) {
                $depth++;
            } elseif ($text === ']') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    /**
     * Index of the first token at or after $index that is not whitespace or a comment.
     *
     * @param array<int,\PhpToken> $tokens
     */
    private static function __next_meaningful(array $tokens, int $index): ?int
    {
        $count = count($tokens);

        for ($i = $index; $i < $count; $i++) {
            if ($tokens[$i]->is(T_WHITESPACE) || $tokens[$i]->is(T_COMMENT) || $tokens[$i]->is(T_DOC_COMMENT)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Messages and small readers
    // -----------------------------------------------------------------------

    /** The FQCN a PHP file record declares. */
    private static function __fqcn(array $record): string
    {
        if (!empty($record['fqcn'])) {
            return ltrim($record['fqcn'], '\\');
        }

        $namespace = trim((string) ($record['namespace'] ?? ''), '\\');

        return $namespace === '' ? $record['class'] : $namespace . '\\' . $record['class'];
    }

    /**
     * @param array<int,string> $application_files
     */
    private static function __two_application_declarations_message(string $name, array $application_files): string
    {
        return "Fatal: Duplicate PHP class '{$name}' declared by more than one application file:\n"
            . '  - ' . implode("\n  - ", $application_files) . "\n\n"
            . "Each class name must be unique within the same file type.\n"
            . "(When one of them overrides a framework class, the override must be exactly one\n"
            . "application file - see rsx:man class_override.)";
    }

    private static function __not_overridable_message(string $name, string $application_file, string $framework_fqcn, string $why): string
    {
        return "Fatal: '{$name}' cannot be overridden.\n\n"
            . "  Application class: {$application_file}\n"
            . "  Framework class:   {$framework_fqcn}\n\n"
            . $why . "\n\n"
            . "Rename the application class. Framework classes that boot the framework or build the\n"
            . "manifest, and framework classes in directories the manifest does not index, cannot be\n"
            . "replaced by an override.\n\n"
            . 'See: php artisan rsx:man class_override';
    }
}
