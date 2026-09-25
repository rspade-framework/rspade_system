<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * MODEL-FETCH-SYSTEM-01 - no browser fetch surface on a framework system table.
 *
 * A table whose name starts with an underscore is a SYSTEM table: the framework owns it,
 * and its rows describe the machinery rather than the application's records - the mail
 * and SMS queues (rendered bodies, invite and password-reset links), sessions, keys,
 * logs. #[Ajax_Endpoint_Model_Fetch] hands such a row to any browser the gate admits,
 * and a gate is a per-USER question, so it can never express "this row is infrastructure
 * and nobody's to read". The mail queue carried exactly this surface, gated on
 * is_logged_in, and any low-role user could walk the ids and redeem an administrator's
 * invite link.
 *
 * So the declaration itself is refused, at manifest build: any method (fetch(),
 * portal_fetch(), a fetchable relationship) carrying #[Ajax_Endpoint_Model_Fetch] on a
 * model whose $table - declared on the class or inherited from its lineage - begins
 * with '_'. A screen that needs such a row writes an explicit #[Ajax_Endpoint] that
 * selects what it shows.
 *
 * Suppressed by @MODEL-FETCH-SYSTEM-01-EXCEPTION on the member (docblock, declaration
 * line or the line above) with a rationale, for a system table whose row IS the public
 * face of a feature and whose fetch() enforces record-level rules itself.
 */
class ModelFetchSystemTable_CodeQualityRule extends CodeQualityRule_Abstract
{
    private const RULE_ID = 'MODEL-FETCH-SYSTEM-01';

    public function get_id(): string
    {
        return self::RULE_ID;
    }

    public function get_name(): string
    {
        return 'Model Fetch On System Table';
    }

    public function get_description(): string
    {
        return 'Refuses #[Ajax_Endpoint_Model_Fetch] on a model whose table is underscore-prefixed (a framework system table)';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * Runs during the manifest scan: the failure mode is a data leak, which announces
     * itself to nobody.
     */
    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    /**
     * CROSS-FILE: a model's table may be declared by an ancestor in another file.
     */
    public function kind(): string
    {
        return self::KIND_CROSS_FILE;
    }

    /**
     * @return array<int,string>
     */
    public function depends_on(): array
    {
        return [
            'files:*.php',
            'php_classes',
            'php_subclass_index',
        ];
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        static $already_checked = false;
        if ($already_checked) {
            return;
        }
        $already_checked = true;

        foreach (Manifest::get_all() as $rel_path => $file_metadata) {
            if (($file_metadata['extension'] ?? '') !== 'php' || empty($file_metadata['class'])) {
                continue;
            }

            $normalized_rel = str_replace('\\', '/', $rel_path);

            // Meta-code ABOUT the attribute (this rule, its fixtures) is not an instance of it.
            if (str_contains($normalized_rel, '/CodeQuality/')) {
                continue;
            }

            $this->evaluate_file(base_path($rel_path), $file_metadata['class']);
        }
    }

    /**
     * Judge every fetch-surface member declared in one class file.
     *
     * The testable seam: production passes manifest-resolved paths.
     */
    public function evaluate_file(string $abs_file, string $class_name): void
    {
        $members = $this->source()->declared_members($abs_file, $class_name);

        $surfaces = [];
        foreach ($members['methods'] as $method) {
            if (isset($method['attributes']['ajax_endpoint_model_fetch'])) {
                $surfaces[] = $method;
            }
        }

        if (empty($surfaces)) {
            return;
        }

        $table = $this->resolve_table($class_name, $members);
        if ($table === null || !str_starts_with($table, '_')) {
            return;
        }

        $contents = $this->source()->content($abs_file);
        $lines = $contents === false ? [] : explode("\n", $contents);

        foreach ($surfaces as $method) {
            if (isset($method['exceptions'][self::RULE_ID])) {
                continue;
            }

            $line = (int) $method['line'];
            $snippet = isset($lines[$line - 1]) ? trim($lines[$line - 1]) : '';

            $this->add_violation(
                $abs_file,
                $line,
                "{$class_name}::{$method['name']}() declares #[Ajax_Endpoint_Model_Fetch], but "
                . "{$class_name} is stored in the framework system table `{$table}`.\n\n"
                . "An underscore-prefixed table holds framework machinery (queues, sessions, keys, "
                . "logs), and a model fetch hands its whole row to any browser the gate admits. A "
                . "gate answers \"may this USER use the surface\", never \"is this row anybody's to "
                . "read\", so no gate can make this surface safe.",
                $snippet,
                "Remove #[Ajax_Endpoint_Model_Fetch] (and its #[Auth]) from this member. A screen "
                . "that needs this data gets an explicit #[Ajax_Endpoint] on a controller, gated for "
                . "that screen, returning only the fields it shows.\n"
                . "If this row genuinely IS the public face of a feature and fetch() enforces "
                . "record-level rules itself, mark the member with a rationale:\n"
                . "    // @" . self::RULE_ID . "-EXCEPTION - <why this row is safe to serve>\n"
                . "See: php artisan rsx:man model_fetch",
                'critical'
            );
        }
    }

    /**
     * The model's $table, from the class itself or the nearest ancestor that declares it.
     * Null when no class in the manifest-visible lineage declares one (Eloquent then
     * derives a name from the class, which never begins with an underscore).
     */
    private function resolve_table(string $class_name, array $members): ?string
    {
        if (isset($members['properties']['table']) && is_string($members['properties']['table']['default'])) {
            return $members['properties']['table']['default'];
        }

        try {
            $lineage = Manifest::php_get_lineage($class_name);
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($lineage as $ancestor) {
            try {
                $file = base_path(Manifest::php_find_class($ancestor));
            } catch (\Throwable $e) {
                continue;
            }

            $ancestor_members = $this->source()->declared_members($file, $ancestor);
            if (isset($ancestor_members['properties']['table'])) {
                $default = $ancestor_members['properties']['table']['default'];

                return is_string($default) ? $default : null;
            }
        }

        return null;
    }
}
