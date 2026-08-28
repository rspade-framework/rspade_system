<?php

namespace App\RSpade\Core\PHP;

/**
 * Pre_Autoload_Reachability - which framework classes load BEFORE the RSX autoloader exists
 *
 * WHY THIS EXISTS (guard 3, the register-phase half of the Php_Fixer import mandate):
 *
 * Rsx_Framework_Provider::register() runs long before Rsx_Framework_Provider::boot()
 * calls Autoloader::register(). Everything the register phase touches - the manifest
 * modules named in config('rsx.manifest_modules'), the integration providers named in
 * config('rsx.integrations.providers'), and everything those files in turn name - is
 * therefore resolved by COMPOSER alone. Composer resolves FQCNs and nothing else, so a
 * class in that set whose `use` statement has been stripped cannot find its parent:
 * `extends BundleIntegration_Abstract` inside namespace App\RSpade\Integrations\Jqhtml
 * resolves to a class that does not exist, and every artisan command and every HTTP
 * request dies at boot with no autoloader available to rescue it.
 *
 * A field report on 2026-08-25 hit exactly that: the fixer stripped the imports out of
 * Jqhtml_BundleIntegration, IntegrationRegistry, Database_BundleIntegration and
 * Controller_BundleIntegration, and the tree could not boot far enough to rebuild.
 *
 * HOW THE SET IS DERIVED - from disk, never from the manifest. The manifest index is
 * exactly what is untrustworthy when this guard matters (a partial index is what makes
 * the fixer believe a class is gone), so the derivation may not consult it:
 *
 *   SEEDS   the two config lists the register phase iterates, plus the provider and the
 *           pre-boot service that run the phase.
 *   EXPAND  every App\RSpade\ class name that appears in a seed's FILE - its imports, its
 *           parent, its interfaces, its `X::class` references - transitively. That is a
 *           deliberate over-approximation: a register() body may instantiate anything its
 *           file names, and this guard only ever SUPPRESSES a deletion, so being generous
 *           costs nothing but a few imports the fixer declines to tidy.
 *
 * The `App\RSpade\` prefix maps to system/app/RSpade/ by PSR-4, so an FQCN resolves to a
 * path arithmetically - no index, no autoloader, no class loading of any kind.
 */
class Pre_Autoload_Reachability
{
    /** Relative-to-base_path() file paths in the set, keyed by path. Computed once per process. */
    private static ?array $files = null;

    private const NAMESPACE_PREFIX = 'App\\RSpade\\';

    /**
     * Is this file's class loaded before Autoloader::register() runs?
     *
     * @param string $file_path Relative path from base_path(), e.g. 'app/RSpade/Core/IntegrationRegistry.php'
     */
    public static function contains_file(string $file_path): bool
    {
        return isset(self::get_files()[$file_path]);
    }

    /**
     * The full set, as relative file paths keyed by path.
     */
    public static function get_files(): array
    {
        if (self::$files !== null) {
            return self::$files;
        }

        $seen = [];
        $queue = self::__seed_classes();

        while ($queue) {
            $fqcn = array_pop($queue);

            if (isset($seen[$fqcn])) {
                continue;
            }

            $seen[$fqcn] = true;

            $absolute_path = self::__absolute_path_for_class($fqcn);
            if ($absolute_path === null) {
                continue;
            }

            foreach (self::__referenced_framework_classes($absolute_path) as $referenced) {
                if (!isset($seen[$referenced])) {
                    $queue[] = $referenced;
                }
            }
        }

        self::$files = [];
        foreach (array_keys($seen) as $fqcn) {
            $absolute_path = self::__absolute_path_for_class($fqcn);
            if ($absolute_path !== null) {
                self::$files[self::__relative_path_for_class($fqcn)] = true;
            }
        }

        return self::$files;
    }

    /**
     * Test seam: drop the memoized set so a test can re-derive it.
     */
    public static function _reset(): void
    {
        self::$files = null;
    }

    /**
     * The classes the register phase names directly.
     */
    private static function __seed_classes(): array
    {
        $seeds = [
            'App\\RSpade\\Core\\Providers\\Rsx_Framework_Provider',
            'App\\RSpade\\Core\\Providers\\Rsx_Preboot_Service',
        ];

        foreach (config('rsx.manifest_modules', []) as $module_class) {
            $seeds[] = ltrim((string) $module_class, '\\');
        }

        foreach (config('rsx.integrations.providers', []) as $provider_class) {
            $seeds[] = ltrim((string) $provider_class, '\\');
        }

        return $seeds;
    }

    private static function __relative_path_for_class(string $fqcn): ?string
    {
        if (!str_starts_with($fqcn, self::NAMESPACE_PREFIX)) {
            return null;
        }

        $tail = substr($fqcn, strlen(self::NAMESPACE_PREFIX));

        return 'app/RSpade/' . str_replace('\\', '/', $tail) . '.php';
    }

    private static function __absolute_path_for_class(string $fqcn): ?string
    {
        $relative = self::__relative_path_for_class($fqcn);

        if ($relative === null) {
            return null;
        }

        $absolute = base_path($relative);

        return is_file($absolute) ? $absolute : null;
    }

    /**
     * Every App\RSpade\ class name this file names: qualified references as written, plus
     * extends/implements simple names resolved through the file's imports or its own
     * namespace.
     */
    private static function __referenced_framework_classes(string $absolute_path): array
    {
        $tokens = token_get_all(file_get_contents($absolute_path));
        $token_count = count($tokens);

        $namespace = '';
        $imports = [];
        $qualified = [];
        $unqualified_parents = [];

        for ($i = 0; $i < $token_count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = self::__read_name_after($tokens, $i, $token_count) ?? '';
                continue;
            }

            if ($token[0] === T_USE) {
                // Only framework imports matter here, and restricting to them also keeps a
                // trait `use Foo;` inside a class body from overwriting the file-level
                // import of the same simple name with a namespace-less version of it.
                $imported = self::__read_name_after($tokens, $i, $token_count);
                if ($imported !== null && str_starts_with($imported, self::NAMESPACE_PREFIX)) {
                    $imports[self::__simple_name($imported)] = $imported;
                }
                continue;
            }

            if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($token[1], '\\');
                if (str_starts_with($name, self::NAMESPACE_PREFIX)) {
                    $qualified[$name] = true;
                }
                continue;
            }

            if ($token[0] === T_EXTENDS || $token[0] === T_IMPLEMENTS) {
                for ($j = $i + 1; $j < $token_count; $j++) {
                    $following = $tokens[$j];

                    if ($following === '{' || $following === ';') {
                        break;
                    }

                    if (!is_array($following)) {
                        continue;
                    }

                    if ($following[0] === T_STRING) {
                        $unqualified_parents[$following[1]] = true;
                    } elseif (in_array($following[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        $name = ltrim($following[1], '\\');
                        if (str_starts_with($name, self::NAMESPACE_PREFIX)) {
                            $qualified[$name] = true;
                        }
                    }
                }
            }
        }

        foreach (array_keys($unqualified_parents) as $simple_name) {
            $resolved = $imports[$simple_name] ?? ($namespace !== '' ? $namespace . '\\' . $simple_name : $simple_name);

            if (str_starts_with($resolved, self::NAMESPACE_PREFIX)) {
                $qualified[$resolved] = true;
            }
        }

        return array_keys($qualified);
    }

    /**
     * Read the class-ish name that follows a T_NAMESPACE / T_USE token, stopping at the
     * statement terminator. The LAST name token wins, so `use A\B as C` and grouped
     * bodies both yield something usable rather than a partial name.
     */
    private static function __read_name_after(array $tokens, int $index, int $token_count): ?string
    {
        $name = null;

        for ($j = $index + 1; $j < $token_count; $j++) {
            $token = $tokens[$j];

            if ($token === ';' || $token === '{' || $token === '(') {
                break;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($token[1], '\\');
            }
        }

        return $name;
    }

    private static function __simple_name(string $fqcn): string
    {
        $last_backslash = strrpos($fqcn, '\\');

        return $last_backslash === false ? $fqcn : substr($fqcn, $last_backslash + 1);
    }
}
