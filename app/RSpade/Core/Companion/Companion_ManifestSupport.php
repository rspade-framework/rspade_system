<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use ReflectionMethod;
use App\RSpade\Core\Api\Api_Endpoint_ManifestSupport;
use App\RSpade\Core\Companion\Companion_Dispatcher;
use App\RSpade\Core\Companion\Companion_Service_Abstract;
use App\RSpade\Core\Manifest\Full_ManifestSupport_Abstract;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\PHP\Php_Parser;

/**
 * Builds $manifest_data['data']['companions'] from every Companion_Service_Abstract subclass and
 * every #[Companion_Endpoint] attribute, and enforces the realm's rules.
 *
 * A FULL module although it loads each companion class to read its declaration: the section is
 * a pure function of the tree, and one lost carried-forward section is all it takes for a live
 * companion to be refused. Declarations and handler bodies are read through the build's
 * Source_Cache; a companion is a small set in any application. Nothing here issues a query.
 *
 * Every rule is a manifest-build FATAL naming the class, the method and the file:
 *
 *   - a name outside NAME_PATTERN, or two companions declaring one name
 *   - a malformed declaration: an empty description or command, a working directory leaving
 *     the companion's own, a non-string environment value, an unknown restart or code-change
 *     policy, a grace above MAX_SHUTDOWN_GRACE_SECONDS, backoff seconds that are not positive
 *   - a write call in ready_to_run()'s own body, the guardrail of API-GET-PURE-01
 *   - #[Companion_Endpoint] with no name, or naming no declared companion
 *   - an endpoint on an instance method, or named outside NAME_PATTERN, or named
 *     SDK_PROBE_ENDPOINT (the SDK's reserved probe)
 *   - two methods claiming one endpoint name on one companion
 *   - #[Companion_Endpoint] beside any other surface attribute or #[FPC]
 *   - #[Auth] on an endpoint or on its class: the signature is the gate, and a gate that
 *     reads as applying while none runs is worse than none
 *   - a #[Route], #[Portal_Route] or @route under the reserved /_companion path
 *
 * THE CONTRACT KEY (endpoints_hash) is a sha256 over GENERATOR_VERSION, each endpoint's name and
 * its handler's body with comments and whitespace dropped. Every endpoint has one PHP signature,
 * so a hash of signatures would notice nothing. It is a guardrail, not a proof: a helper a
 * handler calls is not followed. source_hash is a sha256 over the companion's own directory.
 * PHP verifies the first; the daemon alone compares the second. Both are deterministic: sorted
 * relative paths and content only, never an mtime or an absolute path.
 *
 * The section is hot: the dispatcher reads it on every request.
 */
class Companion_ManifestSupport extends Full_ManifestSupport_Abstract
{
    /**
     * Folded into every contract key. Moved when the wire protocol changes, so a protocol
     * change restarts every companion.
     */
    public const GENERATOR_VERSION = 1;

    /** The base every companion extends, by simple name. */
    private const BASE_CLASS = 'Companion_Service_Abstract';

    private const ATTRIBUTE = 'Companion_Endpoint';

    /** Attributes that may not share a method with #[Companion_Endpoint]. */
    private const CONFLICTING_ATTRIBUTES = [
        'Route', 'SPA', 'Portal_Route', 'Ajax_Endpoint', 'Ajax_Endpoint_Model_Fetch', 'Api_Endpoint', 'FPC', 'Auth',
    ];

    public static function get_name(): string
    {
        return 'Companion Applications';
    }

    /**
     * Rebuild the section in full.
     */
    public static function rebuild(array &$manifest_data): void
    {
        $data = $manifest_data['data'];
        $declarations = [];

        foreach (Manifest::php_get_extending(self::BASE_CLASS) as $entry) {
            Manifest::_load_class_hierarchy($entry['fqcn'], $manifest_data);

            $declarations[] = static::read_declaration($entry['fqcn'], $entry['file']);
        }

        static::assert_routes_not_reserved(array_merge(
            array_keys($data['routes'] ?? []),
            array_keys($data['portal_routes'] ?? [])
        ));

        $manifest_data['data']['companions'] = static::assemble(
            $declarations,
            $data['attribute_index'][self::ATTRIBUTE] ?? [],
            $data['files'] ?? []
        );
    }

    /**
     * Read and validate one companion's declaration off its loaded class.
     *
     * @param string $fqcn
     * @param string $file Manifest-relative path of the class
     * @return array The section row, without its endpoints
     */
    public static function read_declaration(string $fqcn, string $file): array
    {
        $location = "{$fqcn} in {$file}";

        $name = $fqcn::name();
        $description = $fqcn::description();
        $command = $fqcn::command();
        $working_directory = $fqcn::working_directory();
        $environment = $fqcn::environment();
        $grace = $fqcn::shutdown_grace_seconds();
        $restart_policy = $fqcn::restart_policy();
        $backoff_initial = $fqcn::restart_backoff_initial_seconds();
        $backoff_ceiling = $fqcn::restart_backoff_ceiling_seconds();
        $on_code_change = $fqcn::on_code_change();

        if (!preg_match('/^' . Companion_Service_Abstract::NAME_PATTERN . '$/', $name)) {
            static::__fail($location, "name() answered '{$name}'. A name is lower case letters, digits and underscores, starting with a letter.");
        }

        if (trim($description) === '') {
            static::__fail($location, 'description() must say what the companion does, in one line.');
        }

        if (empty($command) || !array_is_list($command) || count(array_filter($command, fn ($part) => is_string($part) && $part !== '')) !== count($command)) {
            static::__fail($location, "command() must answer a non-empty argv of strings: ['node', 'resource/main.js'].");
        }

        if (str_starts_with($working_directory, '/') || in_array('..', explode('/', $working_directory), true)) {
            static::__fail($location, "working_directory() answered '{$working_directory}'. It is relative to the companion's own directory and stays inside it.");
        }

        foreach ($environment as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                static::__fail($location, 'environment() must answer string keys and string values.');
            }
        }

        if ($grace < 1 || $grace > Companion_Service_Abstract::MAX_SHUTDOWN_GRACE_SECONDS) {
            static::__fail($location, 'shutdown_grace_seconds() must be between 1 and ' . Companion_Service_Abstract::MAX_SHUTDOWN_GRACE_SECONDS
                . ": the container supervisor's own wait is fixed above that ceiling, so a longer grace would be cut short.");
        }

        $restart_policies = [Companion_Service_Abstract::RESTART_ALWAYS, Companion_Service_Abstract::RESTART_ON_FAILURE, Companion_Service_Abstract::RESTART_NEVER];
        if (!in_array($restart_policy, $restart_policies, true)) {
            static::__fail($location, "restart_policy() answered '{$restart_policy}'; it is one of: " . implode(', ', $restart_policies) . '.');
        }

        if ($backoff_initial < 1 || $backoff_ceiling < $backoff_initial) {
            static::__fail($location, 'restart_backoff_initial_seconds() must be at least 1, and restart_backoff_ceiling_seconds() no smaller than it.');
        }

        $code_change_policies = [
            Companion_Service_Abstract::CODE_CHANGE_IGNORE,
            Companion_Service_Abstract::CODE_CHANGE_PAUSE,
            Companion_Service_Abstract::CODE_CHANGE_RESTART_IF_AFFECTED,
            Companion_Service_Abstract::CODE_CHANGE_RESTART,
        ];
        if (!in_array($on_code_change, $code_change_policies, true)) {
            static::__fail($location, "on_code_change() answered '{$on_code_change}'; it is one of: " . implode(', ', $code_change_policies) . '.');
        }

        static::__assert_ready_to_run_reads_only($fqcn, $location);

        $directory = dirname($file);

        return [
            'name' => $name,
            'class' => substr((string) strrchr('\\' . $fqcn, '\\'), 1),
            'fqcn' => $fqcn,
            'file' => $file,
            // Project-root relative: a companion's descriptor is the same on every box.
            'directory' => str_starts_with($directory, 'rsx/') ? $directory : 'system/' . $directory,
            'description' => $description,
            'command' => $command,
            'working_directory' => $working_directory,
            'environment' => $environment,
            'shutdown_grace_seconds' => $grace,
            'restart_policy' => $restart_policy,
            'restart_backoff_initial_seconds' => $backoff_initial,
            'restart_backoff_ceiling_seconds' => $backoff_ceiling,
            'single_instance' => (bool) $fqcn::single_instance(),
            'on_code_change' => $on_code_change,
            'source_hash' => static::source_hash(base_path($directory)),
        ];
    }

    /**
     * Join the declarations and the attribute rows into the section, enforcing the realm's
     * rules. Pure over its input apart from reading handler bodies, so a rule can be provoked
     * with synthetic rows.
     *
     * @param array $declarations read_declaration() rows
     * @param array $attribute_rows attribute_index['Companion_Endpoint']
     * @param array $files The manifest's file records
     * @return array name => row, sorted
     */
    public static function assemble(array $declarations, array $attribute_rows, array $files): array
    {
        $companions = [];

        foreach ($declarations as $declaration) {
            $name = $declaration['name'];

            if (isset($companions[$name])) {
                static::__fail(
                    "{$declaration['fqcn']} in {$declaration['file']}",
                    "two companions declare the name '{$name}'; the other is {$companions[$name]['fqcn']}."
                );
            }

            $companions[$name] = $declaration + ['endpoints' => []];
        }

        foreach ($attribute_rows as $row) {
            static::__collect_endpoint($companions, $row, $files);
        }

        foreach ($companions as $name => $companion) {
            ksort($companions[$name]['endpoints'], SORT_STRING);
            $companions[$name]['endpoints_hash'] = static::__endpoints_hash($companions[$name]['endpoints']);
        }

        ksort($companions, SORT_STRING);

        return $companions;
    }

    /**
     * The reserved path. Such a route would build and then never be reached.
     *
     * @param array<int, string> $patterns Route patterns
     */
    public static function assert_routes_not_reserved(array $patterns): void
    {
        foreach ($patterns as $pattern) {
            if (Companion_Dispatcher::is_companion_request(rtrim($pattern, '/'))) {
                throw new \RuntimeException(
                    "Reserved route pattern: {$pattern}\n" .
                    "  /_companion is reserved for the companion realm; a route there is never reached.\n" .
                    "  A companion's endpoint is a #[Companion_Endpoint] method."
                );
            }
        }
    }

    /**
     * The hash of a companion's own directory: relative paths and contents, skipping
     * node_modules and dotfiles. Moves when the process's code moves; the daemon compares it.
     */
    public static function source_hash(string $directory): string
    {
        $paths = [];

        if (is_dir($directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                    fn ($file) => $file->getFilename() !== 'node_modules' && !str_starts_with($file->getFilename(), '.')
                )
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $paths[substr($file->getPathname(), strlen($directory) + 1)] = $file->getPathname();
                }
            }
        }

        ksort($paths, SORT_STRING);

        $material = '';
        foreach ($paths as $relative => $absolute) {
            $material .= $relative . "\0" . hash_file('sha256', $absolute) . "\n";
        }

        return hash('sha256', $material);
    }

    /**
     * Validate one method's #[Companion_Endpoint] and record it on each companion it names.
     */
    private static function __collect_endpoint(array &$companions, array $row, array $files): void
    {
        $file = $row['file'];
        $method = $row['member'] ?? null;
        $metadata = $files[$file] ?? [];
        $fqcn = $metadata['fqcn'] ?? $metadata['class'] ?? '(unknown)';
        $location = "{$fqcn}::{$method} in {$file}";

        if ($method === null) {
            static::__fail("{$fqcn} in {$file}", '#[Companion_Endpoint] goes on a public static method, never on a class.');
        }

        $method_data = $metadata['public_static_methods'][$method] ?? null;

        if ($method_data === null) {
            static::__fail($location, 'a companion endpoint is a PUBLIC STATIC method with the (Request $request, array $params = []) signature.');
        }

        if (!preg_match('/^' . Companion_Service_Abstract::NAME_PATTERN . '$/', $method)) {
            static::__fail($location, 'an endpoint is named by its method, which must be lower case letters, digits and underscores, starting with a letter.');
        }

        if ($method === Companion_Service_Abstract::SDK_PROBE_ENDPOINT) {
            static::__fail($location, "'" . Companion_Service_Abstract::SDK_PROBE_ENDPOINT . "' is reserved by the companion SDK, whose ready() calls it expecting no such endpoint. Name the method otherwise.");
        }

        if (static::__has_attribute($metadata['attributes'] ?? [], 'Auth')) {
            static::__fail($location, 'its class declares #[Auth], and no gate runs here: authorization is the signature. Move the endpoint to a class with no class-level #[Auth].');
        }

        foreach (self::CONFLICTING_ATTRIBUTES as $conflict) {
            if (static::__has_attribute($method_data['attributes'] ?? [], $conflict)) {
                static::__fail($location, "a method carrying #[Companion_Endpoint] must not also carry #[{$conflict}].");
            }
        }

        foreach (($row['instances'] ?? []) as $arguments) {
            $name = $arguments[0] ?? $arguments['name'] ?? null;

            if (!is_string($name) || $name === '') {
                static::__fail($location, "#[Companion_Endpoint] takes the companion's name: #[Companion_Endpoint('my_companion')].");
            }

            if (!isset($companions[$name])) {
                static::__fail($location, "no Companion_Service_Abstract subclass declares the name '{$name}'.");
            }

            if (isset($companions[$name]['endpoints'][$method])) {
                $other = $companions[$name]['endpoints'][$method];

                static::__fail($location, "companion '{$name}' already has an endpoint '{$method}', on {$other['fqcn']}.");
            }

            $companions[$name]['endpoints'][$method] = [
                'class' => $metadata['class'] ?? $fqcn,
                'fqcn' => $fqcn,
                'method' => $method,
                'file' => $file,
            ];
        }
    }

    /**
     * The contract key. See the class docblock.
     */
    private static function __endpoints_hash(array $endpoints): string
    {
        $material = 'v' . self::GENERATOR_VERSION . "\n";

        foreach ($endpoints as $name => $endpoint) {
            $body = static::__handler_body(base_path($endpoint['file']), $endpoint['method']);

            if ($body === null) {
                static::__fail(
                    "{$endpoint['fqcn']}::{$endpoint['method']} in {$endpoint['file']}",
                    'the body of this method could not be located, so its contract cannot be computed.'
                );
            }

            $material .= $name . "\0" . $body . "\n";
        }

        return hash('sha256', $material);
    }

    /**
     * One method's body with comments and whitespace dropped and string literals KEPT: changing
     * a literal changes what the endpoint means.
     */
    private static function __handler_body(string $file_path, string $method): ?string
    {
        if (!file_exists($file_path)) {
            return null;
        }

        $tokens = token_get_all(Manifest::build()->source_cache()->content($file_path));
        $span = Php_Parser::method_body_span($tokens, $method);

        if ($span === null) {
            return null;
        }

        $body = '';
        for ($i = $span[0] + 1; $i < $span[1]; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT && $token[0] !== T_WHITESPACE) {
                    $body .= $token[1];
                }

                continue;
            }

            $body .= $token;
        }

        return $body;
    }

    /**
     * ready_to_run() is called by a read-only daemon check on every backoff tick. A guardrail
     * over the method's own body: a helper it calls is not followed.
     */
    private static function __assert_ready_to_run_reads_only(string $fqcn, string $location): void
    {
        $declaring = (new ReflectionMethod($fqcn, 'ready_to_run'))->getDeclaringClass();

        if ($declaring->getName() === Companion_Service_Abstract::class) {
            return;
        }

        $body = Api_Endpoint_ManifestSupport::_read_method_body((string) $declaring->getFileName(), 'ready_to_run');

        if ($body === null) {
            static::__fail($location, 'the body of ready_to_run() could not be located, so it cannot be checked for writes.');
        }

        $token = Api_Endpoint_ManifestSupport::find_write_call($body);

        if ($token !== null) {
            static::__fail($location, "ready_to_run() must be read-only, and it calls '{$token}'. It runs on every backoff tick; work belongs in the companion or a task.");
        }
    }

    /**
     * Whether an attributes bucket carries an attribute, bare or fully qualified.
     */
    private static function __has_attribute(array $attributes, string $short_name): bool
    {
        foreach (array_keys($attributes) as $attr_name) {
            if ($attr_name === $short_name || str_ends_with((string) $attr_name, '\\' . $short_name)) {
                return true;
            }
        }

        return false;
    }

    private static function __fail(string $location, string $problem): never
    {
        throw new \RuntimeException("Invalid companion application: {$location}\n  {$problem}\n  See: php artisan rsx:man companion_apps");
    }
}
