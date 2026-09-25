<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * PortalModelFetchAuthCheckRule - Validates the RECORD-LEVEL contract of a model
 * exposed to the portal ORM.
 *
 * AUTHORIZATION IS DECLARED, NOT DETECTED. Whether portal_fetch() is gated is no
 * longer this rule's business: the surface must carry an #[Auth(...)] gate naming a
 * portal-realm check, and the manifest build FAILS by name when it does not
 * (Auth_ManifestSupport's validation pass). The portal auth-check pattern matching
 * and the @portal-auth-exempt docblock this rule used to honor are therefore gone.
 *
 * What remains is the layer the gates deliberately do NOT cover. A gate answers
 * "may this user use this surface at all"; portal_can_read() answers "may they see
 * THIS ROW", and it is the framework's fail-closed per-record contract. So a model
 * exposing portal_fetch() with #[Ajax_Endpoint_Model_Fetch] MUST:
 *   - declare portal_can_read(), and
 *   - if it defines portal_fetch() itself rather than using the Portal_Authorizable
 *     trait, actually call portal_can_read() from that body.
 *
 * Scope is every Rsx_Model_Abstract subclass through any number of abstract bases, and
 * both declarations resolve through the lineage: a portal_fetch() or portal_can_read()
 * declared on a shared intermediate base counts for every model beneath it. The
 * portal_can_read() requirement applies to CONCRETE models; the body check applies where
 * portal_fetch() is written.
 *
 * The standard pattern is `use Portal_Authorizable;` (the trait supplies a
 * portal_fetch() that defers to portal_can_read()) plus portal_can_read() on the
 * model; then only the declaration is checked here.
 *
 * See: php artisan rsx:man portal, php artisan rsx:man auth_gates
 */
class PortalModelFetchAuthCheck_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'PORTAL-MODEL-FETCH-01';
    }

    public function get_name(): string
    {
        return 'Portal Model Fetch Record-Level Contract';
    }

    public function get_description(): string
    {
        return 'Validates that models exposing portal_fetch() declare and use portal_can_read()';
    }

    public function get_file_patterns(): array
    {
        return ['*_model.php', '*_Model.php'];
    }

    public function is_called_during_manifest_scan(): bool
    {
        return false; // Only run during rsx:check
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $class_name = $metadata['class'] ?? null;
        if (!$class_name) {
            return;
        }

        // Every model, however many abstract bases stand between it and Rsx_Model_Abstract
        // (a site-scoped model reaches it only through Rsx_Site_Model_Abstract).
        if (!Manifest::php_is_subclass_of($class_name, 'Rsx_Model_Abstract')) {
            return;
        }

        // Skip archived files
        if (str_contains($file_path, '/archive/') || str_contains($file_path, '/archived/')) {
            return;
        }

        // The effective portal_fetch(): declared (or mixed in) here, else inherited from the
        // nearest base that declares it. Without one carrying the fetch attribute the model
        // is not portal-fetchable and this rule does not apply.
        $declared_fetch = $metadata['public_static_methods']['portal_fetch'] ?? null;
        $parent = $metadata['extends'] ?? null;

        $portal_fetch_info = $declared_fetch;
        if ($portal_fetch_info === null && $parent !== null && $parent !== '') {
            $inherited = $this->lineage_declaring_method($parent, 'portal_fetch', 'Rsx_Model_Abstract');
            $portal_fetch_info = $inherited['method'] ?? null;
        }

        if ($portal_fetch_info === null || !$this->has_fetch_attribute($portal_fetch_info)) {
            return;
        }

        $line_number = $declared_fetch['line'] ?? 1;

        // Every CONCRETE portal-fetchable model must have portal_can_read() - the per-row,
        // fail-closed visibility rule the framework relies on - declared here or on a base.
        // An abstract base is never fetched itself; its concrete children are checked.
        if (empty($metadata['abstract']) && !$this->lineage_defines_portal_can_read($metadata)) {
            $this->add_violation(
                $file_path,
                $line_number,
                "Model exposes portal_fetch() but does not define portal_can_read()",
                "#[Ajax_Endpoint_Model_Fetch]\npublic static function portal_fetch(\$id)",
                $this->build_can_read_suggestion(),
                'high'
            );
            return;
        }

        // The body is judged where it is written: only a class that defines portal_fetch()
        // in its own file has it checked here. A trait's portal_fetch() (Portal_Authorizable)
        // calls portal_can_read() by construction, and an inherited one was checked at the
        // base that wrote it.
        if ($declared_fetch === null) {
            return;
        }

        $is_trait_provided = isset($declared_fetch['file'])
            && rsxrealpath($declared_fetch['file']) !== rsxrealpath($file_path);

        if ($is_trait_provided) {
            return;
        }

        // A declared-but-uncalled portal_can_read() is the same dead-security-metadata
        // failure as an unreachable endpoint.
        $method_body = $this->method_body($contents, 'portal_fetch');
        if ($method_body === null) {
            return;
        }

        if (str_contains($method_body, 'portal_can_read')) {
            return;
        }

        $this->add_violation(
            $file_path,
            $line_number,
            "Model portal_fetch() does not call portal_can_read()",
            "#[Ajax_Endpoint_Model_Fetch]\npublic static function portal_fetch(\$id)",
            $this->build_fetch_suggestion(),
            'high'
        );
    }

    private function has_fetch_attribute(array $method_info): bool
    {
        foreach (array_keys($method_info['attributes'] ?? []) as $attr_name) {
            if (basename(str_replace('\\', '/', $attr_name)) === 'Ajax_Endpoint_Model_Fetch') {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this model declare portal_can_read(), or inherit one from an intermediate base?
     *
     * The class's own declarations come from $metadata (the file in front of the rule); the
     * ancestors' from the manifest. A shared base that declares the rule for several models
     * satisfies each of them.
     */
    private function lineage_defines_portal_can_read(array $metadata): bool
    {
        if (isset($metadata['public_instance_methods']['portal_can_read'])
            || isset($metadata['public_static_methods']['portal_can_read'])) {
            return true;
        }

        $parent = $metadata['extends'] ?? null;

        return $parent !== null && $parent !== ''
            && $this->lineage_declaring_method($parent, 'portal_can_read', 'Rsx_Model_Abstract') !== null;
    }

    private function build_can_read_suggestion(): string
    {
        $s = [];
        $s[] = "A portal-fetchable model must declare portal_can_read(): bool (fail-closed).";
        $s[] = "";
        $s[] = "The #[Auth] gate on portal_fetch() answers 'may this user use this surface';";
        $s[] = "portal_can_read() answers 'may they see THIS ROW'. Both are required.";
        $s[] = "";
        $s[] = "Recommended: use the trait and implement portal_can_read():";
        $s[] = "    use App\\RSpade\\Core\\Portal\\Portal_Authorizable;";
        $s[] = "    class My_Model extends Rsx_Site_Model_Abstract {";
        $s[] = "        use Portal_Authorizable;";
        $s[] = "        public function portal_can_read(): bool {";
        $s[] = "            return Portal_Permission::has_client_access((int) \$this->client_id);";
        $s[] = "        }";
        $s[] = "    }";

        return implode("\n", $s);
    }

    private function build_fetch_suggestion(): string
    {
        $s = [];
        $s[] = "A hand-rolled portal_fetch() must defer to portal_can_read().";
        $s[] = "";
        $s[] = "Prefer the Portal_Authorizable trait, which supplies a correct portal_fetch().";
        $s[] = "If you must define it yourself:";
        $s[] = "    #[Ajax_Endpoint_Model_Fetch]";
        $s[] = "    #[Auth('is_logged_in')]";
        $s[] = "    public static function portal_fetch(\$id) {";
        $s[] = "        \$row = static::find(\$id);";
        $s[] = "        return (\$row && \$row->portal_can_read()) ? \$row->toArray() : false;";
        $s[] = "    }";

        return implode("\n", $s);
    }
}
