<?php

namespace App\RSpade\CodeQuality\Rules\Convention;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Naming\Rsx_Identifier;
use App\RSpade\Core\Naming\Rsx_Paths;

/**
 * NAME-RESERVED-01 - the single leading underscore is the framework-application prefix.
 *
 * A name may carry ONE leading underscore before its capital (`_Sys_Layout`,
 * `<Define:_Sys_Card>`, `@rsx_id('_Apidocs_App')`). That form is RESERVED for the
 * framework's own application tree at `app/RSpade/Sys/`, exactly as a `_`-prefixed table
 * or column is reserved for the framework in the schema. One character says who owns the
 * name, with no registry to keep in step.
 *
 * The rule is therefore two-directional, and both directions are keyed on the PATH:
 *
 *   (a) A name declared under `rsx/` may NOT start with an underscore. Without this, an
 *       application `_Sys_Layout` would collide with the framework's - and worse, the
 *       manifest's class-override machinery would silently ARCHIVE the framework file as
 *       `.upstream` and serve the app's copy instead. The collision would look like the
 *       framework panel simply changing.
 *
 *   (b) A name declared under `app/RSpade/Sys/` MUST start with one. The prefix is what
 *       keeps the framework's application out of the namespace an application is free to
 *       fill; a bare name there is one rename away from an unannounced collision.
 *
 * The Sys tree does not have to exist for this rule to be correct - direction (b) keys on
 * the path prefix, so it simply finds nothing until the tree is there.
 *
 * The name shape itself (`/^_?[A-Z][A-Za-z0-9_]*$/`) belongs to
 * App\RSpade\Core\Naming\Rsx_Identifier; this rule only decides WHO may spell it which way.
 */
class NameReservedPrefix_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'NAME-RESERVED-01';

    /** The framework-application tree. Everything declared under it is `_`-prefixed. */
    private const SYS_TREE = Rsx_Paths::FRAMEWORK_PREFIX . 'Sys/';

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Reserved Framework-Application Name Prefix';
    }

    public function get_description(): string
    {
        return 'A single leading underscore on a class, component or blade id is the framework-application '
            . 'prefix: reserved from rsx/, and required under app/RSpade/Sys/';
    }

    public function get_file_patterns(): array
    {
        return ['*.php', '*.js', '*.jqhtml', '*.blade.php'];
    }

    /**
     * Blocking: a colliding name does not fail, it SHADOWS - the manifest archives the
     * framework file and serves the application's copy with no error anywhere.
     */
    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Manifest-time, because the failure mode is silent shadowing rather than an error, and
     * because the very next build is what would archive the framework file.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $normalized = str_replace('\\', '/', $file_path);

        $in_sys_tree = str_contains($normalized, self::SYS_TREE);
        // Rsx_Paths answers both spellings - the manifest's RELATIVE `rsx/app/...` and the
        // absolute `/var/www/html/rsx/...` a fixture or the IDE passes.
        $in_app_tree = !Rsx_Paths::is_framework($normalized) && Rsx_Paths::is_application($normalized);

        if (!$in_sys_tree && !$in_app_tree) {
            return;
        }

        foreach ($this->__declared_names($normalized, $contents, $metadata) as $entry) {
            [$name, $line_number, $kind] = $entry;

            $reserved = Rsx_Identifier::is_framework_reserved($name);

            if ($in_app_tree && $reserved) {
                $this->__report_app_violation($file_path, $line_number, $contents, $name, $kind);
                continue;
            }

            if ($in_sys_tree && !$reserved) {
                $this->__report_sys_violation($file_path, $line_number, $contents, $name, $kind);
            }
        }
    }

    /**
     * Every name this file DECLARES, as [name, line_number, kind].
     *
     * Only declarations count. A reference to somebody else's name is not this file
     * claiming it, so `extends _Sys_Layout` and `<_Sys_Card />` are ignored.
     *
     * @return array<int,array{0:string,1:int,2:string}>
     */
    private function __declared_names(string $normalized, string $contents, array $metadata): array
    {
        $names = [];
        $lines = explode("\n", $contents);

        if (str_ends_with($normalized, '.blade.php')) {
            foreach ($lines as $index => $line) {
                if (preg_match('/@rsx_id\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $line, $matches)) {
                    $names[] = [$matches[1], $index + 1, 'blade @rsx_id'];
                }
            }

            return $names;
        }

        if (str_ends_with($normalized, '.jqhtml')) {
            foreach ($lines as $index => $line) {
                if (preg_match('/<Define:(\w+)/', $line, $matches)) {
                    $names[] = [$matches[1], $index + 1, 'jqhtml component'];
                }
            }

            return $names;
        }

        // PHP and JS: class declarations.
        $kind = str_ends_with($normalized, '.js') ? 'JavaScript class' : 'PHP class';

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:export\s+)?(?:abstract\s+|final\s+|default\s+)*class\s+([A-Za-z_]\w*)/', $line, $matches)) {
                $names[] = [$matches[1], $index + 1, $kind];
            }
        }

        return $names;
    }

    /**
     * A `_`-prefixed name declared in application code.
     */
    private function __report_app_violation(string $file_path, int $line_number, string $contents, string $name, string $kind): void
    {
        $this->add_violation(
            $file_path,
            $line_number,
            "{$kind} '{$name}' starts with an underscore, which is the framework-application prefix.",
            $this->__snippet($contents, $line_number),
            "A SINGLE leading underscore marks a name declared by the framework's own application tree "
                . "(app/RSpade/Sys/) and is RESERVED from application code, the same way a `_`-prefixed table "
                . "or column is.\n"
                . "\n"
                . "This does not merely look wrong: a `_`-prefixed name in rsx/ that matches a framework one is "
                . "read by the manifest as a CLASS OVERRIDE. The framework's file is renamed to .upstream and "
                . "yours is served in its place, with nothing reported anywhere.\n"
                . "\n"
                . "FIX: rename '{$name}' to '" . Rsx_Identifier::bare($name) . "' (or to any name of your own that "
                . "does not begin with an underscore).\n"
                . "\n"
                . "Rule: " . self::RULE_ID . ". See: rsx:man coding_standards",
            $this->get_default_severity()
        );
    }

    /**
     * A bare name declared inside the framework-application tree.
     */
    private function __report_sys_violation(string $file_path, int $line_number, string $contents, string $name, string $kind): void
    {
        $this->add_violation(
            $file_path,
            $line_number,
            "{$kind} '{$name}' is declared under " . self::SYS_TREE . " and must carry the framework-application prefix.",
            $this->__snippet($contents, $line_number),
            "Every name declared in the framework's own application tree carries ONE leading underscore, so "
                . "that it can never collide with a name an application is free to choose. A bare name here is "
                . "one application rename away from an unannounced collision.\n"
                . "\n"
                . "FIX: rename '{$name}' to '_{$name}'.\n"
                . "\n"
                . "Rule: " . self::RULE_ID . ". See: rsx:man coding_standards",
            $this->get_default_severity()
        );
    }

    private function __snippet(string $contents, int $line_number): string
    {
        $lines = explode("\n", $contents);

        return isset($lines[$line_number - 1]) ? trim($lines[$line_number - 1]) : '';
    }
}
