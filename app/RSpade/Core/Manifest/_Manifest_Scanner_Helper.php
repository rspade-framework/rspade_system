<?php

namespace App\RSpade\Core\Manifest;

use App\RSpade\CodeQuality\RuntimeChecks\ManifestErrors;
use App\RSpade\Core\Cache\File_Content_Cache;
use App\RSpade\Core\ExtensionRegistry;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Naming\Rsx_Paths;

/**
 * _Manifest_Scanner_Helper - File discovery, change detection, extraction
 *
 * This helper class contains function implementations for Manifest.
 * Functions in this class are called via delegation from Manifest.php.
 *
 * @internal Do not use directly - use Manifest:: methods instead.
 */
class _Manifest_Scanner_Helper
{
    /**
     * Derived-cache namespace for the PHP reflection extracts, keyed by the manifest's own
     * sha1 rather than by the build hash. See App\RSpade\Core\Cache\File_Content_Cache.
     */
    public const REFLECTION_NAMESPACE = 'php-reflection';

    /**
    * The keys _extract_reflection_data() writes, and therefore the keys the derived
    * reflection cache must carry. A key missing from this list is a key that silently
    * disappears from every record restored from cache.
    */
    /**
    * Payload version of the derived reflection cache.
    *
    * BUMP IT WHEN REFLECTION_CACHED_KEYS CHANGES. An entry written by an older version is
    * treated as a miss and overwritten under the same key (the file's hash), so the fix is
    * self-healing and leaves no orphan directory behind. v2 added `extends_fqcn`, which v1
    * omitted.
    */
    public const REFLECTION_CACHE_VERSION = 2;

    public const REFLECTION_CACHED_KEYS = [
        'abstract',
        'extends_fqcn',
        'attributes',
        'public_static_methods',
        'public_instance_methods',
        'properties',
        'implements',
        'traits',
        'is_trait',
    ];

    // Static properties are defined on Manifest class and accessed via Manifest::$property

    /**
     * The three trees that hold test fixtures, and are indexed ONLY while the process is a
     * test run. See _scan_directories().
     */
    public const TEST_SCAN_DIRECTORIES = ['app/RSpade/tests', 'app/RSpade/temp', 'rsx/tests'];

    /**
     * The directories this build indexes.
     *
     * The list belongs to the BUILD (Manifest_Build), not to this helper: config is where a
     * served site's answer comes from, and a test builds a fixture tree by handing the facade
     * a build with different roots.
     *
     * @return array<int,string>
     */
    public static function _scan_directories(): array
    {
        return Manifest::build()->scan_directories();
    }

    /**
    * Get all files in configured scan directories (returns relative paths)
    */
    public static function _get_rsx_files(): array
    {
        if (!empty(Manifest::$_get_rsx_files_cache)) {
            return Manifest::$_get_rsx_files_cache;
        }

        $base_path = base_path();
        $scan_paths = static::_scan_directories();
        // rsx/tests lives INSIDE the rsx/ scan root, so leaving it out of the list is not
        // enough to keep it out of an ordinary build - it has to be skipped by path.
        //
        // A scan path INSIDE one of those trees un-suppresses it: naming
        // `app/RSpade/temp/my_fixture_tree` as a root is a deliberate request to index
        // exactly that subtree, and a blanket suppression of its parent would silently
        // index nothing.
        $suppressed_trees = array_values(array_filter(
            self::TEST_SCAN_DIRECTORIES,
            function ($tree) use ($scan_paths) {
                $prefix = rtrim($tree, '/') . '/';

                foreach ($scan_paths as $scan_path) {
                    if ($scan_path === $tree || str_starts_with($scan_path, $prefix)) {
                        return false;
                    }
                }

                return true;
            }
        ));
        $files = [];

        foreach ($scan_paths as $scan_path) {
            $full_path = base_path($scan_path);

            // Check if path exists - a missing root is fatal, except for the test trees.
            if (!file_exists($full_path)) {
                // A TEST TREE IS OPTIONAL. The three entries of TEST_SCAN_DIRECTORIES are put
                // on the list by the test RUN (Manifest_Build::from_config()), not by the
                // operator, and every one of them is legitimately absent: app/RSpade/temp is
                // the framework developer's scratch tree, and an application that keeps its
                // suites beside the code it tests has no rsx/tests at all. A fatal here would
                // mean such an application could not start rsx:test until it created an empty
                // directory to satisfy a list it never wrote.
                //
                // Everything else in the list came from config('rsx.manifest.scan_directories')
                // and stays a fatal: a served site's own root going missing is a broken
                // install, and silence there is how a build indexes half an application.
                if (in_array($scan_path, self::TEST_SCAN_DIRECTORIES, true)) {
                    continue;
                }

                throw new \RuntimeException(
                    "Manifest scan path does not exist: '{$scan_path}'\n" .
"Please ensure all paths in config('rsx.manifest.scan_directories') exist.\n" .
'Current configuration includes: ' . implode(', ', $scan_paths)
                );
            }

            // If it's a file, add it directly
            if (is_file($full_path)) {
                // Convert to relative path and normalize to forward slashes
                $relative_path = str_replace($base_path . '/', '', $full_path);
                $relative_path = str_replace('\\', '/', $relative_path);
                // Remove leading slash if present
                $relative_path = ltrim($relative_path, '/');
                $files[] = $relative_path;
                continue;
            }

            // If it's a directory, recursively scan it
            if (is_dir($full_path)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveCallbackFilterIterator(
                        new \RecursiveDirectoryIterator($full_path, \RecursiveDirectoryIterator::SKIP_DOTS),
                        function ($file, $key, $iterator) {
                            $basename = $file->getBasename();

                            // Skip hidden files and directories (those starting with .)
                            if (strpos($basename, '.') === 0) {
                                return false;
                            }

                            // If it's a directory, check if it should be excluded
                            if ($iterator->hasChildren()) {
                                // Don't recurse into excluded directories
                                $excluded_dirs = config('rsx.manifest.excluded_dirs', Manifest::EXCLUDED_DIRS);

                                return !in_array($basename, $excluded_dirs);
                            }

                            // It's a file, check if filename should be excluded
                            $excluded_files = config('rsx.manifest.excluded_files', []);
                            if (in_array($basename, $excluded_files)) {
                                return false;
                            }

                            return true;
                        },
                    ),
                    \RecursiveIteratorIterator::SELF_FIRST,
                );

                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $absolute_path = $file->getPathname();
                        // Convert to relative path and normalize to forward slashes
                        $absolute_path = str_replace('\\', '/', $absolute_path);
                        $base_path_normalized = str_replace('\\', '/', $base_path);
                        $relative_path = str_replace($base_path_normalized . '/', '', $absolute_path);
                        // Remove leading slash if present
                        $relative_path = ltrim($relative_path, '/');

                        // Check for disallowed .old. naming pattern
                        if (preg_match('/\\.old\\.\\w+$/', $relative_path)) {
                            ManifestErrors::old_file_pattern($relative_path);
                        }

                        if (static::__is_under_tree($relative_path, $suppressed_trees)) {
                            continue;
                        }

                        $files[] = $relative_path;
                    }
                }
            }
        }

        // Hardcoded ./json includes
        $hardcoded_files = ['package-lock.json', 'composer.lock'];

        foreach ($hardcoded_files as $file) {
            if (file_exists($file) && !in_array($file, $files)) {
                $files[] = $file;
            }
        }

        // Remove duplicates and sort
        $files = array_unique($files);
        sort($files);

        Manifest::$_get_rsx_files_cache = $files;

        return $files;
    }

    /**
     * Is this relative path inside one of the given directory trees?
     *
     * @param array<int,string> $trees
     */
    private static function __is_under_tree(string $relative_path, array $trees): bool
    {
        foreach ($trees as $tree) {
            if (str_starts_with($relative_path, rtrim($tree, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
    * Check if a file has changed (expects relative path)
    */
    public static function _has_changed(string $file): bool
    {
        if (isset(Manifest::$_has_changed_cache[$file])) {
            return Manifest::$_has_changed_cache[$file];
        }

        // file_index is the WHOLE tree's [size, mtime] - the hot half of the index carries it
        // for exactly this sweep, so change detection never loads the cold half. During a
        // BUILD it is stale (the build is what writes it), so the live files map wins when it
        // has the entry.
        $live = Manifest::$data['data']['files'][$file] ?? null;
        $indexed = Manifest::$data['data']['file_index'][$file] ?? null;

        if ($live === null && $indexed === null) {
            // Only show the message once per page load
            if (!Manifest::$__shown_rescan_message) {
                console_debug('MANIFEST', '* New file ' . $file . ' is triggering manifest rescan *');
                Manifest::$__shown_rescan_message = true;
            }
            Manifest::$_has_changed_cache[$file] = true;

            return true;
        }

        $old = $live !== null
            ? ['size' => $live['size'] ?? null, 'mtime' => $live['mtime'] ?? null]
            : ['size' => $indexed[0] ?? null, 'mtime' => $indexed[1] ?? null];
        $absolute_path = base_path($file);

        // Make sure file exists
        if (!file_exists($absolute_path)) {
            // Only show the message once per page load
            if (!Manifest::$__shown_rescan_message) {
                console_debug('MANIFEST', '* File ' . $file . ' appears to be deleted in ' . $absolute_path . ', triggering manifest rescan *');
                Manifest::$__shown_rescan_message = true;
            }
            Manifest::$_has_changed_cache[$file] = true;

            return true;
        }

        $current_size = filesize($absolute_path);

        // Stage 1: Size check (guard for incomplete manifest entries)
        if ($old['size'] === null || $old['size'] != $current_size) {
            // Only show the message once per page load
            if (!Manifest::$__shown_rescan_message) {
                console_debug('MANIFEST', '* File ' . $file . ' has changed size, triggering manifest rescan *');
                Manifest::$__shown_rescan_message = true;
            }
            Manifest::$_has_changed_cache[$file] = true;

            return true;
        }

        // Stage 2: mtime check (guard for incomplete manifest entries)
        $current_mtime = filemtime($absolute_path);
        if ($old['mtime'] === null || $old['mtime'] != $current_mtime) {
            // Only show the message once per page load
            if (!Manifest::$__shown_rescan_message) {
                console_debug('MANIFEST', '* File ' . $file . ' has changed mtime, triggering manifest rescan *');
                Manifest::$__shown_rescan_message = true;
            }
            Manifest::$_has_changed_cache[$file] = true;

            return true;
        }

        Manifest::$_has_changed_cache[$file] = false;

        return false;
    }

    /**
    * simple class name => FQCNs declared under a directory, by TOKEN PARSING every php file.
    *
    * IT EXISTS FOR THE UNINDEXED FRAMEWORK SUBTREES. app/RSpade/Commands, /Database, /Http,
    * /Ide and friends are never scanned into the manifest, so nothing else knows their class
    * names - and the autoloader still has to resolve them by simple name. The callers hand it
    * only those subtrees and memoize the result on their stat fingerprint
    * (_Manifest_Builder_Helper::_unindexed_framework_classes); it is not a general "find the
    * classes" facility and must never be pointed at a tree the index already covers.
    */
    public static function _scan_directory_for_classes(string $directory): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            // Skip non-PHP files
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Skip vendor directories
            $path = $file->getPathname();
            if (strpos($path, '/vendor/') !== false) {
                continue;
            }

            foreach (static::_extract_classes_from_php_file($path) as $class_name => $fqcns) {
                foreach ($fqcns as $fqcn) {
                    $classes[$class_name][] = $fqcn;
                }
            }
        }

        return $classes;
    }

    /**
    * The single-file half of _scan_directory_for_classes(): class name => FQCNs declared in
    * ONE php file, read with token_get_all().
    */
    public static function _extract_classes_from_php_file(string $path): array
    {
        $classes = [];

        $content = file_get_contents($path);
        $tokens = token_get_all($content);
        $namespace = '';
        $class_name = '';
        $getting_namespace = false;
        $getting_class = false;
        $token_count = count($tokens);

        foreach ($tokens as $i => $token) {
            if (is_array($token)) {
                if ($token[0] === T_NAMESPACE) {
                    $getting_namespace = true;
                    $namespace = '';
                } elseif ($token[0] === T_CLASS || $token[0] === T_INTERFACE || $token[0] === T_TRAIT) {
                    // Make sure this isn't an anonymous class
                    $next_token_idx = $i + 1;
                    while ($next_token_idx < $token_count && is_array($tokens[$next_token_idx]) && $tokens[$next_token_idx][0] === T_WHITESPACE) {
                        $next_token_idx++;
                    }
                    if ($next_token_idx < $token_count && is_array($tokens[$next_token_idx]) && $tokens[$next_token_idx][0] === T_STRING) {
                        $getting_class = true;
                    }
                } elseif ($getting_namespace && ($token[0] === T_NAME_QUALIFIED || $token[0] === T_STRING || $token[0] === T_NS_SEPARATOR)) {
                    $namespace .= $token[1];
                } elseif ($getting_class && $token[0] === T_STRING) {
                    $class_name = $token[1];
                    $getting_class = false;

                    // We have both namespace and class name, add to map
                    if ($class_name) {
                        $fqcn = $namespace ? $namespace . '\\' . $class_name : $class_name;
                        $classes[$class_name][] = $fqcn;
                    }
                }
            } else {
                // Non-array token (like ; or {)
                if ($token === ';' || $token === '{') {
                    $getting_namespace = false;
                }
            }
        }

        return $classes;
    }

    /**
    * Process a single file and extract comprehensive metadata
    * @param string $file_path Relative path to file
    */
    public static function _process_file(string $file_path): array
    {
        $absolute_path = base_path($file_path);
        $stat = stat($absolute_path);
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        // Handle compound extensions as special cases
        if (str_ends_with($file_path, '.externals.php')) {
            // External-resource declarations return a bare array and define no class:
            // the dedicated extension keeps them out of every 'php' code path
            // (class scan, reflection, Php_Fixer). See Core/Externals/Rsx_Externals.php.
            $extension = 'externals.php';
        } elseif (str_ends_with($file_path, '.blade.php')) {
            $extension = 'blade.php';
        } elseif (str_ends_with($file_path, '.php.upstream')) {
            $extension = 'php.upstream';
        }

        $data = [
            'hash' => sha1_file($absolute_path),
            'mtime' => $stat['mtime'],
            'size' => $stat['size'],
            'extension' => $extension,
        ];

        // Use kernel for basic processing (kernel expects absolute path)
        $kernel = Manifest::_get_kernel();
        $kernel_data = $kernel->process($absolute_path, $data);
        $data = array_merge($data, $kernel_data);

        // Add advanced extraction based on file type (pass absolute path)
        switch ($extension) {
            case 'php':
                $php_metadata = \App\RSpade\Core\PHP\Php_Parser::parse($absolute_path);
                $data = array_merge($data, $php_metadata);

                // Php_Parser::parse() may have modified the file via Php_Fixer in development mode
                // Recalculate file stats to reflect any changes made during parsing.
                // RSX_MODE is the one mode oracle - app()->environment() derives from it.
                if (!\App\RSpade\Core\Rsx::is_production()) {
                    clearstatcache(true, $absolute_path);
                    $updated_stat = stat($absolute_path);
                    $data['hash'] = sha1_file($absolute_path);
                    $data['mtime'] = $updated_stat['mtime'];
                    $data['size'] = $updated_stat['size'];
                }
                break;

            case 'php.upstream':
                // Parse upstream files to extract class metadata
                // These are framework files that were renamed when an rsx/ override was created
                // Php_Fixer only runs on extension 'php', so upstream files are safe
                $php_metadata = \App\RSpade\Core\PHP\Php_Parser::parse($absolute_path);
                $data = array_merge($data, $php_metadata);
                break;

            case 'js':
                console_debug('BUILD', "Parsing JS file: {$file_path}");
                $js_metadata = \App\RSpade\Core\JsParsers\Js_Parser::extract_metadata($absolute_path);
                $data = array_merge($data, $js_metadata);
                break;

            case 'blade.php':
            case 'phtml':
                Manifest::_extract_view_info($absolute_path, $data);
                break;

            default:
                // Check if this extension has a registered handler
                if (ExtensionRegistry::process_file($extension, $absolute_path, $data)) {
                    // Handler processed the file
                    break;
                }
        }

        return $data;
    }

    /**
    * Extract public static methods and their attributes using PHP reflection
    * Note: This is called in Phase 4 after all PHP files have been loaded
    */
    public static function _extract_reflection_data(string $file_path, string $full_class_name, array &$data): void
    {
        // Skip method indexing for traits - methods will be indexed when we process classes that use them
        if (isset($data['is_trait']) && $data['is_trait']) {
            return;
        }

        // Class MUST exist if it's in the manifest
        // Fail loud if it doesn't - this indicates a serious problem
        if (!class_exists($full_class_name)) {
            shouldnt_happen("Class {$full_class_name} from manifest cannot be loaded. File: {$file_path}");
        }

        $reflection = new \ReflectionClass($full_class_name);

        // Extract whether the class is abstract
        if ($reflection->isAbstract()) {
            $data['abstract'] = true;
        } else {
            $data['abstract'] = false;
        }

        // Extract parent class FQCN if it exists
        $parent_class = $reflection->getParentClass();
        if ($parent_class !== false) {
            $data['extends_fqcn'] = $parent_class->getName();
        }

        // Extract class attributes (using simple names, not FQCNs)
        $class_attributes = [];
        foreach ($reflection->getAttributes() as $attribute) {
            $attr_full_name = $attribute->getName();
            // Extract just the simple class name (e.g., "Route" from "App\RSpade\Core\Attributes\Route")
            $attr_simple_name = substr(strrchr($attr_full_name, '\\'), 1) ?: $attr_full_name;
            $attr_args = $attribute->getArguments();

            if (!isset($class_attributes[$attr_simple_name])) {
                $class_attributes[$attr_simple_name] = [];
            }
            $class_attributes[$attr_simple_name][] = $attr_args;
        }

        if (!empty($class_attributes)) {
            $data['attributes'] = $class_attributes;
        }

        // Get list of trait files used by this class
        $trait_files = [];
        foreach ($reflection->getTraits() as $trait) {
            $trait_files[] = $trait->getFileName();
        }

        // Extract ONLY public static methods - the only methods we care about in RSX
        $public_static_methods = [];

        // Normalize file_path for comparison (without resolving symlinks)
        $normalized_file_path = rsxrealpath($file_path);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC) as $method) {
            // Include methods from:
            // 1. This file (the class itself)
            // 2. Traits used by this class (checked via file path)
            $method_file = $method->getFileName();
            $is_from_trait = in_array($method_file, $trait_files);

            // Skip inherited methods from parent classes (but include trait methods)
            // Use rsxrealpath for comparison to avoid symlink resolution
            if (rsxrealpath($method_file) !== $normalized_file_path && !$is_from_trait) {
                continue;
            }

            $method_data = [
                'name' => $method->getName(),
                'static' => true,  // Always true since we filtered for static
                'visibility' => 'public',  // Always public since we filtered for public
                'line' => $method->getStartLine(),
            ];

            // For trait methods, store the file path (only if different from class file)
            // This enables IDE helpers to locate trait methods correctly
            if ($is_from_trait) {
                $method_data['file'] = $method_file;
            }

            // Extract method attributes (using simple names, not FQCNs)
            $method_attributes = [];
            foreach ($method->getAttributes() as $attribute) {
                $attr_full_name = $attribute->getName();
                // Extract just the simple class name (e.g., "Route" from "App\RSpade\Core\Attributes\Route")
                $attr_simple_name = substr(strrchr($attr_full_name, '\\'), 1) ?: $attr_full_name;
                $attr_args = $attribute->getArguments();

                if (!isset($method_attributes[$attr_simple_name])) {
                    $method_attributes[$attr_simple_name] = [];
                }
                $method_attributes[$attr_simple_name][] = $attr_args;
            }

            if (!empty($method_attributes)) {
                $method_data['attributes'] = $method_attributes;
            }

            // Extract parameters with types
            $parameters = [];
            foreach ($method->getParameters() as $param) {
                $param_data = [
                    'name' => $param->getName(),
                    'optional' => $param->isOptional(),
                ];

                // Get type if available
                $type = $param->getType();
                if ($type !== null) {
                    $param_data['type'] = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
                    $param_data['nullable'] = $type->allowsNull();
                }

                // Get default value if available
                if ($param->isDefaultValueAvailable()) {
                    $param_data['default'] = $param->getDefaultValue();
                }

                $parameters[] = $param_data;
            }

            if (!empty($parameters)) {
                $method_data['parameters'] = $parameters;
            }

            // Extract return type if available
            $return_type = $method->getReturnType();
            if ($return_type !== null) {
                if ($return_type instanceof \ReflectionUnionType) {
                    // Union type (e.g., "array|null")
                    $method_data['return_type'] = [
                        'type' => 'union',
                        'types' => array_map(fn($t) => $t->getName(), $return_type->getTypes()),
                        'nullable' => $return_type->allowsNull()
                    ];
                } else {
                    // Single type (e.g., "array", "string", "int")
                    $method_data['return_type'] = [
                        'type' => $return_type->getName(),
                        'nullable' => $return_type->allowsNull()
                    ];
                }
            }

            $public_static_methods[$method->getName()] = $method_data;
        }

        if (!empty($public_static_methods)) {
            $data['public_static_methods'] = $public_static_methods;
        }

        // Extract public instance methods for code-quality rules
        $public_instance_methods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip inherited methods unless from same file
            if ($method->getFileName() !== $file_path) {
                continue;
            }

            // Skip static methods - we only want instance methods
            if ($method->isStatic()) {
                continue;
            }

            // `static` is recorded only when TRUE. An instance method's record used to
            // carry `'static' => false` - a byte-for-byte constant on every public instance
            // method in the index - and every reader already spells the test
            // `!isset(...) || !...`, so an omitted flag reads false.
            $method_data = [
                'name' => $method->getName(),
                'visibility' => 'public',  // Always public since we filtered for public
                'line' => $method->getStartLine(),
            ];

            // Extract method attributes (using simple names, not FQCNs)
            $method_attributes = [];
            foreach ($method->getAttributes() as $attribute) {
                $attr_full_name = $attribute->getName();
                // Extract just the simple class name (e.g., "Route" from "App\RSpade\Core\Attributes\Route")
                $attr_simple_name = substr(strrchr($attr_full_name, '\\'), 1) ?: $attr_full_name;
                $attr_args = $attribute->getArguments();

                if (!isset($method_attributes[$attr_simple_name])) {
                    $method_attributes[$attr_simple_name] = [];
                }
                $method_attributes[$attr_simple_name][] = $attr_args;
            }

            if (!empty($method_attributes)) {
                $method_data['attributes'] = $method_attributes;
            }

            // Extract parameters with types
            $parameters = [];
            foreach ($method->getParameters() as $param) {
                $param_data = [
                    'name' => $param->getName(),
                    'optional' => $param->isOptional(),
                ];

                // Get type if available
                $type = $param->getType();
                if ($type !== null) {
                    $param_data['type'] = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
                    $param_data['nullable'] = $type->allowsNull();
                }

                // Get default value if available
                if ($param->isDefaultValueAvailable()) {
                    $param_data['default'] = $param->getDefaultValue();
                }

                $parameters[] = $param_data;
            }

            if (!empty($parameters)) {
                $method_data['parameters'] = $parameters;
            }

            $public_instance_methods[$method->getName()] = $method_data;
        }

        // Always set public_instance_methods, even if empty, for consistency
        $data['public_instance_methods'] = $public_instance_methods;

        // Extract interfaces
        $interfaces = $reflection->getInterfaceNames();
        if (!empty($interfaces)) {
            $data['implements'] = $interfaces;
        }

        // Extract traits
        $traits = $reflection->getTraitNames();
        if (!empty($traits)) {
            $data['traits'] = $traits;
        }

        // Extract properties
        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $full_class_name) {
                $property_data = [
                    'name' => $property->getName(),
                    'visibility' => $property->isPublic() ? 'public' : ($property->isProtected() ? 'protected' : 'private'),
                ];

                // Recorded only when true; static properties have their own index.
                if ($property->isStatic()) {
                    $property_data['static'] = true;
                }

                $properties[] = $property_data;
            }
        }

        if (!empty($properties)) {
            $data['properties'] = $properties;
        }
    }

    /**
    * Extract reflection data for the CHANGED files, and for nobody else.
    *
    * THE UNCHANGED FILES ARE ALREADY DONE. Their records came out of the index this build
    * loaded, reflection keys and all, and they were carried forward verbatim - so re-reading
    * and json_decode()ing 1,000-odd derived JSON files to restore data that is already in
    * memory was pure cost. The loop below iterates the changed set only, and ASSERTS the
    * carried-forward invariant for everything else (see __assert_reflection_carried_forward).
    *
    * The derived JSON stays, because it is the SURVIVAL PATH: when the index is gone (a
    * clean, a corrupt cache) every file is "changed" and the cache is what keeps a cold
    * build from re-reflecting a tree that has not moved.
    */
    public static function _extract_reflection_for_changed_files(array $changed_files): void
    {
        foreach ($changed_files as $file) {
            if (!isset(Manifest::$data['data']['files'][$file])) {
                continue;
            }

            $metadata = &Manifest::$data['data']['files'][$file];

            // Skip non-PHP files
            if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
                unset($metadata);

                continue;
            }

            // Skip files without classes
            if (!isset($metadata['fqcn'])) {
                unset($metadata);

                continue;
            }

            $fqcn = $metadata['fqcn'];

            // KEYED BY THE MANIFEST'S OWN HASH, not by the build hash - this cache already
            // had the right key and keeps it; only the location moved, onto the shared
            // derived-cache helper (App\RSpade\Core\Cache\File_Content_Cache), which is
            // why the *_for_hash twins exist at all.
            $cache_key = $metadata['hash'];

            // Rsx_Paths::real() is the one spelling: it resolves the `system/rsx` symlink
            // so the project mount and the symlink converge on the same derived-cache key.
            $absolute_path = Rsx_Paths::real($file);

            // The derived cache still answers for a file whose CONTENT the build has seen
            // before - a cold build after a clean, a file reverted to a previous state.
            $cached_json = File_Content_Cache::get_for_hash(
                self::REFLECTION_NAMESPACE,
                $cache_key,
                '',
                'json',
                $absolute_path
            );

            if ($cached_json !== null) {
                $cached_data = json_decode($cached_json, true);

                if (is_array($cached_data)
                    && ($cached_data['__version'] ?? null) === self::REFLECTION_CACHE_VERSION) {
                    unset($cached_data['__version']);

                    // Merge cached reflection data into manifest without breaking the reference
                    foreach ($cached_data as $key => $value) {
                        $metadata[$key] = $value;
                    }

                    static::__normalize_reflection_key_order($metadata);
                    unset($metadata);

                    continue;
                }
            }

            // Need fresh reflection data - ensure class and its hierarchy are loaded
            Manifest::_load_class_hierarchy($fqcn, Manifest::$data);

            // Extract reflection data (path already normalized with realpath above)
            Manifest::_extract_reflection_data($absolute_path, $fqcn, $metadata);

            // Cache the reflection data.
            //
            // EVERY KEY _extract_reflection_data() WRITES, not a hand-picked subset. It used
            // to omit `extends_fqcn`, and the omission was invisible because the cache was
            // only consulted for files the build had NOT re-parsed: a cold build reflected
            // everything, so the key was always present in the index that got saved, and only
            // a warm restore lost it. The restore path is now the ordinary one, and two live
            // consumers read that key - InstanceMethods_CodeQualityRule walks the ancestry
            // through it, and Auth_ManifestSupport resolves realm membership through it - so
            // the omission became a wrong answer instead of a latent one.
            $reflection_data = [];

            foreach (self::REFLECTION_CACHED_KEYS as $reflection_key) {
                if (array_key_exists($reflection_key, $metadata)) {
                    $reflection_data[$reflection_key] = $metadata[$reflection_key];
                }
            }

            static::__normalize_reflection_key_order($metadata);

            if (!empty($reflection_data)) {
                $reflection_data['__version'] = self::REFLECTION_CACHE_VERSION;

                File_Content_Cache::put_for_hash(
                    self::REFLECTION_NAMESPACE,
                    $cache_key,
                    '',
                    'json',
                    json_encode($reflection_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            unset($metadata);
        }

        static::__assert_reflection_carried_forward($changed_files);
    }

    /**
    * Put the reflection keys in one canonical order at the end of the record.
    *
    * A record's KEY ORDER is part of the index's bytes and therefore part of the build key.
    * The two paths that produce reflection - fresh extraction and a restore from the derived
    * cache - naturally write those keys in different orders, so two builds of an identical
    * tree could disagree about their own hash purely on which path each file took.
    */
    private static function __normalize_reflection_key_order(array &$metadata): void
    {
        $reflection = [];

        foreach (self::REFLECTION_CACHED_KEYS as $key) {
            if (array_key_exists($key, $metadata)) {
                $reflection[$key] = $metadata[$key];
                unset($metadata[$key]);
            }
        }

        foreach ($reflection as $key => $value) {
            $metadata[$key] = $value;
        }
    }

    /**
    * The invariant the skip above rests on: a PHP class record this build did NOT re-parse
    * still carries its reflection.
    *
    * `abstract` is the marker, because _extract_reflection_data() always writes it (true or
    * false) for a class and nothing else does. A record without it is a record that was
    * carried forward from an index written by a build that never reflected it - which would
    * mean the skip is silently shipping a class with no attributes and no method map, and
    * every attribute-driven index would quietly lose its rows.
    *
    * shouldnt_happen(), not a repair: repairing it here would hide the defect that produced
    * it, and the index that produced it is on disk to be looked at.
    */
    private static function __assert_reflection_carried_forward(array $changed_files): void
    {
        $changed = array_flip($changed_files);

        foreach (Manifest::$data['data']['files'] as $file => $metadata) {
            if (isset($changed[$file])) {
                continue;
            }

            if (($metadata['extension'] ?? null) !== 'php' || !isset($metadata['fqcn'])) {
                continue;
            }

            // A trait's methods are indexed on the classes that use it; reflection returns
            // before writing `abstract` for one.
            if (!empty($metadata['is_trait'])) {
                continue;
            }

            if (!array_key_exists('abstract', $metadata)) {
                shouldnt_happen(
                    "Manifest reflection invariant broken: {$file} was not re-parsed by this build "
                    . 'and its record carries no reflection data. An unchanged file must arrive with '
                    . 'the reflection the previous build wrote.'
                );
            }
        }
    }

    /**
    * Extract view information from template files
    */
    public static function _extract_view_info(string $file_path, array &$data): void
    {
        $content = file_get_contents($file_path);

        // Look for @id comment or directive
        if (preg_match('/(?:<!--\s*@id\s+(\w+)\s*-->|@id\([\'"](\w+)[\'"]\))/', $content, $matches)) {
            $data['id'] = $matches[1] ?: $matches[2];
        }

        // Extract blade sections
        if (str_ends_with($file_path, '.blade.php')) {
            $sections = [];
            if (preg_match_all('/@section\([\'"](\w+)[\'"]\)/', $content, $matches)) {
                $sections = $matches[1];
            }
            if (!empty($sections)) {
                $data['sections'] = $sections;
            }

            // Extract extends
            if (preg_match('/@extends\([\'"]([^\'"\)]+)[\'"]\)/', $content, $matches)) {
                $data['extends'] = $matches[1];
            }
        }
    }

    /**
    * Load changed PHP files and their dependencies
    *
    * This method loads only the PHP files that have changed and ensures
    * their parent class hierarchies are loaded first.
    *
    * @param array $changed_files Array of changed file paths
    * @return void
    */
    public static function _load_changed_php_files(array $changed_files): void
    {
        // Filter to only PHP files
        $trait_files = [];
        $class_files = [];

        foreach ($changed_files as $file) {
            if (isset(Manifest::$data['data']['files'][$file]['extension']) &&
            Manifest::$data['data']['files'][$file]['extension'] === 'php' &&
            isset(Manifest::$data['data']['files'][$file]['fqcn'])) {
                // Separate traits from classes - traits must be loaded first
                if (isset(Manifest::$data['data']['files'][$file]['is_trait']) &&
                    Manifest::$data['data']['files'][$file]['is_trait']) {
                    $trait_files[] = $file;
                } else {
                    $class_files[] = $file;
                }
            }
        }

        // Load traits first (they have no dependencies and are used by classes)
        foreach ($trait_files as $file) {
            $fqcn = Manifest::$data['data']['files'][$file]['fqcn'];
            Manifest::_load_class_hierarchy($fqcn, Manifest::$data);
        }

        // Then load classes with their hierarchies
        foreach ($class_files as $file) {
            $fqcn = Manifest::$data['data']['files'][$file]['fqcn'];
            Manifest::_load_class_hierarchy($fqcn, Manifest::$data);
        }
    }

    public static function _load_php_files_in_dependency_order(): void
    {
        if (!isset(Manifest::$data['data']['files'])) {
            throw new \RuntimeException(
                'Fatal: Manifest::load_php_files_in_dependency_order() called but manifest data structure is not initialized. ' .
"This shouldn't happen - Phase 2 should have populated the files array."
            );
        }

        $files = Manifest::$data['data']['files'];
        $loaded = [];
        $to_load = [];

        // Build list of files with class information
        foreach ($files as $file_path => $metadata) {
            // Check for PHP files by extension
            if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
                continue;
            }

            if (!isset($metadata['class'])) {
                // No class defined, include it immediately
                $full_path = base_path($file_path);
                if (file_exists($full_path)) {
                    include_once $full_path;
                }
                continue;
            }

            $to_load[$file_path] = [
                'class' => $metadata['class'] ?? null,
                'extends' => $metadata['extends'] ?? null,
                'namespace' => $metadata['namespace'] ?? null,
                'fqcn' => $metadata['fqcn'] ?? null,
            ];
        }

        // Load files in dependency order
        $max_iterations = count($to_load) + 10; // Prevent infinite loop
        $iteration = 0;

        while (!empty($to_load) && $iteration < $max_iterations) {
            $iteration++;
            $loaded_this_round = false;

            foreach ($to_load as $file_path => $info) {
                // Check if dependencies are met
                $can_load = true;

                if (!empty($info['extends'])) {
                    // Check if parent class is already loaded
                    $parent_loaded = false;

                    // Check if it's a Laravel/PHP built-in class
                    if (class_exists($info['extends'], false) || interface_exists($info['extends'], false)) {
                        $parent_loaded = true;
                    } else {
                        // Check if parent is in our loaded list
                        foreach ($loaded as $loaded_info) {
                            if ($loaded_info['class'] === $info['extends']) {
                                $parent_loaded = true;
                                break;
                            }
                        }
                    }

                    if (!$parent_loaded) {
                        $can_load = false;
                    }
                }

                if ($can_load) {
                    // Load the file
                    $full_path = base_path($file_path);
                    if (file_exists($full_path)) {
                        include_once $full_path;
                    }

                    // Mark as loaded
                    $loaded[$file_path] = $info;
                    unset($to_load[$file_path]);
                    $loaded_this_round = true;
                }
            }

            // If nothing was loaded this round, we might have circular dependencies
            // Load remaining files anyway
            if (!$loaded_this_round && !empty($to_load)) {
                foreach ($to_load as $file_path => $info) {
                    $full_path = base_path($file_path);
                    if (file_exists($full_path)) {
                        include_once $full_path;
                    }
                }
                break;
            }
        }
    }

    // /**
    //  * Extract reflection data for all PHP files
    //  * Must be called after Phase 3 (dependency loading) completes
    //  */
    // public static function __extract_all_reflection_data(): void
    // {
    //     if (!isset(Manifest::$data['data']['files'])) {
    //         throw new \RuntimeException(
    //             'Fatal: Manifest::extract_all_reflection_data() called but manifest data structure is not initialized. ' .
    //             "This shouldn't happen - Phase 2 should have populated the files array."
    //         );
    //     }

    //     foreach (Manifest::$data['data']['files'] as $file_path => &$metadata) {
    //         // Only process PHP files with classes
    //         if (!isset($metadata['extension']) || $metadata['extension'] !== 'php') {
    //             continue;
    //         }

    //         if (!isset($metadata['fqcn'])) {
    //             continue;
    //         }
    //         var_dump($file_path);
    //         // Extract reflection data (class should already be loaded)
    //         Manifest::_extract_reflection_data(base_path($file_path), $metadata['fqcn'], $metadata);
    //     }
    // }

    /**
    * Load all PHP files in dependency order
    * This ensures base classes are loaded before their subclasses
    * Must be called after Phase 2 (basic metadata extraction) completes
    * @deprecated Use _load_changed_php_files() for incremental builds
    */
    /**
    * Run Php_Fixer over the files that can actually need fixing.
    *
    * TWO STRATEGIES, and the interesting one is the second.
    *
    * STRUCTURE UNCHANGED - fix the files that changed on disk. No class arrived, left,
    * moved or changed parent, so nothing else's imports can have gone wrong.
    *
    * STRUCTURE CHANGED - fix the changed files PLUS the files that REFERENCE a class in the
    * delta. It used to fix every PHP file in the tree, on the reasoning that a structural
    * change cascades; it does, but only along references. The scanner records a
    * `referenced_simple_names` list per PHP file for exactly this (a generous
    * over-approximation - a name it wrongly includes costs one file re-fixed, a name it
    * missed would cost a stale import), and the delta is computed from the STRUCTURE MAP
    * the previous build stored, not from a bare hash that could only ever say "something".
    *
    * THE STRUCTURE MAP IS STORED BY THE CALLER, AFTER THE RE-PARSE. The fixer rewrites
    * files, the caller re-parses what it rewrote, and only then is the structure the one
    * this build actually leaves behind. Storing it here recorded the PRE-fix shape, so a
    * genuine structural fix guaranteed a second full pass on the next build.
    *
    * @param array $changed_files Files re-parsed this build
    * @return array The files Php_Fixer modified
    */
    public static function _run_php_fixer(array $changed_files): array
    {
        $modified_files = [];

        $new_structure = static::_compute_class_structure();
        $previous_structure = static::_load_class_structure();

        $php_files_to_fix = [];
        $queued = [];

        $queue = function (string $file_path) use (&$php_files_to_fix, &$queued): void {
            if (isset($queued[$file_path])) {
                return;
            }

            $metadata = Manifest::$data['data']['files'][$file_path] ?? null;

            if ($metadata === null || ($metadata['extension'] ?? null) !== 'php') {
                return;
            }

            if (!isset($metadata['class'])) {
                return;
            }

            if (!str_starts_with($file_path, 'rsx/') && !str_starts_with($file_path, 'app/RSpade/')) {
                return;
            }

            $queued[$file_path] = true;
            $php_files_to_fix[] = $file_path;
        };

        foreach ($changed_files as $file_path) {
            $queue($file_path);
        }

        if ($previous_structure === null) {
            // NO MEMORY AT ALL (a clean, a first build). Every file is a candidate, which is
            // also what the changed set says on a cold build - this is the belt-and-braces
            // half, for an index that carried files forward but lost the structure map.
            foreach (array_keys(Manifest::$data['data']['files']) as $file_path) {
                $queue($file_path);
            }
        } elseif ($previous_structure !== $new_structure) {
            $delta = static::_class_structure_delta($previous_structure, $new_structure);

            if (!empty($delta)) {
                foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
                    // A record with NO reference list predates the field (an index written
                    // before this build's framework version). "I do not know what this file
                    // references" has exactly one safe answer, and it is the old behaviour:
                    // fix it. One rebuild re-parses it and the list is there from then on.
                    if (!array_key_exists('referenced_simple_names', $metadata)) {
                        if (($metadata['extension'] ?? null) === 'php') {
                            $queue($file_path);
                        }

                        continue;
                    }

                    foreach ($metadata['referenced_simple_names'] as $name) {
                        if (isset($delta[$name])) {
                            $queue($file_path);
                            break;
                        }
                    }
                }
            }
        }

        \App\RSpade\Core\PHP\Php_Fixer::begin_run(Manifest::$data);

        try {
            foreach ($php_files_to_fix as $file_path) {
                if (\App\RSpade\Core\PHP\Php_Fixer::fix($file_path, Manifest::$data)) {
                    $modified_files[] = $file_path;
                }
            }
        } finally {
            \App\RSpade\Core\PHP\Php_Fixer::end_run();
        }

        return $modified_files;
    }

    /** Where the fixer's structure memory lives for THIS build. */
    private static function _class_structure_path(): string
    {
        return Manifest::build()->storage_root() . '/' . Manifest::PHP_FIXER_STRUCTURE_FILE;
    }

    /**
    * The structure map the PREVIOUS build left behind, or null when there is none.
    */
    public static function _load_class_structure(): ?array
    {
        $path = static::_class_structure_path();

        if (!is_file($path)) {
            return null;
        }

        $structure = include $path;

        return is_array($structure) ? $structure : null;
    }

    /**
    * Record the shape this build leaves behind. Called AFTER the fixer's rewrites have been
    * re-parsed, so it describes the tree as it now is.
    */
    public static function _store_class_structure(): void
    {
        $path = static::_class_structure_path();
        ensure_directory(dirname($path));

        file_put_contents_safe(
            $path,
            "<?php\n// Php_Fixer class-structure memory - DO NOT EDIT\nreturn "
            . var_export(static::_compute_class_structure(), true) . ";\n"
        );
    }

    /**
    * class name => "file|parent" for every indexed PHP class.
    *
    * The fixer's memory of the tree's SHAPE. A map rather than a hash, because "did the
    * structure move" is a cheap comparison either way and "WHICH classes moved" is the
    * question that narrows the pass.
    *
    * THE TEST TREES ARE NOT IN IT, and that is the point. A fixture is indexed only while
    * the process is a test run, so 635 classes ARRIVE on entry to a run and LEAVE on the
    * first served request after it - and a structure map that counted them called that a
    * structural change in both directions, which re-fixed every file that referenced
    * anything in the delta and cost ~9 s of an otherwise idle request. A fixture entering
    * or leaving cannot change what application or framework code must import: nothing
    * outside a test tree may reference a fixture. Fixtures themselves are fixed from the
    * CHANGED set, under a test run, which is when they exist.
    */
    public static function _compute_class_structure(): array
    {
        $structure = [];

        foreach (Manifest::$data['data']['files'] as $file_path => $metadata) {
            if (($metadata['extension'] ?? null) !== 'php' || !isset($metadata['class'])) {
                continue;
            }

            if (Rsx_Paths::is_test_tree($file_path)) {
                continue;
            }

            $structure[$metadata['class']] = $file_path . '|' . ($metadata['extends'] ?? '');
        }

        ksort($structure);

        return $structure;
    }

    /**
    * The class names whose declaration ARRIVED, LEFT, MOVED or CHANGED PARENT.
    *
    * @return array<string,bool>
    */
    public static function _class_structure_delta(array $previous, array $current): array
    {
        $delta = [];

        foreach ($current as $class => $signature) {
            if (($previous[$class] ?? null) !== $signature) {
                $delta[$class] = true;
            }
        }

        foreach ($previous as $class => $signature) {
            if (!isset($current[$class])) {
                $delta[$class] = true;
            }
        }

        return $delta;
    }

    /**
    * Restore orphaned .upstream files when their override no longer exists
    *
    * Scans all php.upstream files in the manifest. For each one, checks if a .php file
    * with the same class name exists. If not, the override was removed and we should
    * restore the framework file.
    */
    public static function _restore_orphaned_upstream_files(): void
    {
        // ONE pass, two answers: the active class names, and the .upstream entries. The
        // second loop used to walk the whole index again looking for the handful of archived
        // files it is actually about.
        $active_php_classes = [];
        $upstream_entries = [];

        foreach (Manifest::$data['data']['files'] as $file => $metadata) {
            $extension = $metadata['extension'] ?? null;

            if ($extension === 'php' && !empty($metadata['class'])) {
                $active_php_classes[$metadata['class']] = $file;

                continue;
            }

            if ($extension === 'php.upstream' && !empty($metadata['class'])) {
                $upstream_entries[$file] = $metadata;
            }
        }

        // Check each .upstream file
        foreach ($upstream_entries as $file => $metadata) {

            $class_name = $metadata['class'];

            // Check if an active .php file with this class exists
            if (isset($active_php_classes[$class_name])) {
                // Override still exists - keep .upstream as-is.
                continue;
            }

            // No active override - restore this framework file
            $upstream_path = base_path($file);
            $restored_path = preg_replace('/\.upstream$/', '', $upstream_path);

            if (file_exists($upstream_path) && !file_exists($restored_path)) {
                rename($upstream_path, $restored_path);
                Manifest::$_override_pass_renamed = true;
                console_debug('MANIFEST', "Class restore: {$class_name} - restored {$file} to .php");

                // Remove the .upstream entry from manifest
                unset(Manifest::$data['data']['files'][$file]);

                Manifest::flag_needs_restart(
                    'an orphaned .upstream file was restored: ' . $class_name . ' (' . $file . ')'
                );
            }
        }
    }

}
