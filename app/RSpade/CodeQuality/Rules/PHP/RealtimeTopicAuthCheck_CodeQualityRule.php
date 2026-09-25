<?php

namespace App\RSpade\CodeQuality\Rules\PHP;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * RealtimeTopicAuthCheckRule - Validates realtime topic classes declare and honor
 * their auth intent.
 *
 * Every class extending Realtime_Topic_Abstract has a $requires_auth static
 * property (default true) and a can_subscribe() method, which is the SOLE
 * runtime enforcement boundary for who may subscribe to a realtime topic
 * (Realtime_Controller has no auth check of its own). This rule catches two
 * distinct mistakes:
 *
 * 1. $requires_auth is true (the default, including topics that never declare
 *    it) but can_subscribe() contains no recognizable auth-check pattern -
 *    almost certainly a topic that will let anyone subscribe despite claiming
 *    otherwise.
 * 2. $requires_auth is explicitly set to false - the topic is intentionally
 *    public. This is allowed, but flagged for mandatory manual review (a public
 *    topic must never leak site/tenant-scoped or otherwise sensitive data),
 *    requiring an explicit exception comment with rationale to suppress.
 *
 * Scope is every Realtime_Topic_Abstract subclass, through any number of intermediate
 * bases, and each class is judged on what it DECLARES: a declared can_subscribe() has its
 * body checked unless the nearest declaration of $requires_auth (its own or an
 * ancestor's) is false; a declared $requires_auth = false is flagged for review; a class
 * that declares neither inherits both, and the verdict was delivered at the ancestor that
 * declared them.
 *
 * Exemption: add @REALTIME-AUTH-01-EXCEPTION (with rationale) anywhere in the
 * file before the class declaration.
 */
class RealtimeTopicAuthCheck_CodeQualityRule extends CodeQualityRule_Abstract
{
    public function get_id(): string
    {
        return 'REALTIME-AUTH-01';
    }

    public function get_name(): string
    {
        return 'Realtime Topic Authentication Check';
    }

    public function get_description(): string
    {
        return 'Validates that realtime topic classes (Realtime_Topic_Abstract) have a real auth check in can_subscribe() when $requires_auth is true, and flags public ($requires_auth = false) topics for manual security review';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function is_called_during_manifest_scan(): bool
    {
        return false; // Only run during rsx:check
    }

    public function get_default_severity(): string
    {
        return 'high';
    }

    /**
     * Patterns that indicate a real auth check is present in can_subscribe()
     */
    private const AUTH_CHECK_PATTERNS = [
        'Session::is_logged_in',
        'Session::get_user_id',
        'Portal_Session::is_logged_in',
        'Portal_Session::get_portal_user_id',
        'Permission::',
        'Portal_Permission::',
    ];

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        $original_contents = $this->source()->content($file_path);

        // File-level exception: suppresses both violation kinds for the topic declared here.
        if (strpos($original_contents, '@' . $this->get_id() . '-EXCEPTION') !== false) {
            return;
        }

        $class_name = $metadata['class'] ?? null;
        if (!$class_name) {
            return;
        }

        // Every topic, however many intermediate bases stand between it and the abstract -
        // Realtime::subscribe_token() accepts any subclass, so this rule checks any subclass.
        if (!Manifest::php_is_subclass_of($class_name, 'Realtime_Topic_Abstract')) {
            return;
        }

        if (str_contains($file_path, '/archive/') || str_contains($file_path, '/archived/')) {
            return;
        }

        // Each class is judged on what it DECLARES. What it inherits was judged at the
        // ancestor that declared it, so a class declaring neither reports nothing.
        $declares_public = $this->declares_public_topic($contents);
        $declares_can_subscribe = isset($metadata['public_static_methods']['can_subscribe']);

        if ($declares_public) {
            $requires_auth_line = $this->find_requires_auth_line($contents);

            $this->add_violation(
                $file_path,
                $requires_auth_line,
                "Realtime topic '{$class_name}' is PUBLIC (\$requires_auth = false) - requires manual security review",
                'public static bool $requires_auth = false;',
                $this->build_public_topic_suggestion($class_name),
                'medium'
            );

            return;
        }

        if (!$declares_can_subscribe) {
            return;
        }

        // An inherited "$requires_auth = false" makes this topic public too: the body is
        // then the reviewed public rule's business, not a missing auth check.
        if ($this->inherits_public_declaration($metadata['extends'] ?? null)) {
            return;
        }

        $can_subscribe = $metadata['public_static_methods']['can_subscribe'];
        $can_subscribe_line = $can_subscribe['line'] ?? 1;

        // A can_subscribe() mixed in from a trait has its body in the trait's file.
        $body_source = isset($can_subscribe['file']) && rsxrealpath($can_subscribe['file']) !== rsxrealpath($file_path)
            ? $this->source()->content($can_subscribe['file'])
            : $contents;

        $method_body = $this->method_body($body_source, 'can_subscribe');
        if ($method_body && $this->body_has_auth_check($method_body)) {
            return;
        }

        $this->add_violation(
            $file_path,
            $can_subscribe_line,
            "Realtime topic '{$class_name}' requires auth (default) but can_subscribe() has no recognizable auth check",
            'public static function can_subscribe(array $filter = []): bool { ... }',
            $this->build_missing_auth_suggestion($class_name),
            'high'
        );
    }

    /**
     * Does the nearest ancestor that declares $requires_auth declare it false?
     *
     * Realtime_Topic_Abstract itself declares the default (true), so the walk ends on a
     * declaration. The value is the declaration's literal default, read from the declaring
     * class's parsed members - never from the file text, whose docblocks may quote the
     * public spelling as an example.
     */
    private function inherits_public_declaration(?string $parent_class): bool
    {
        if ($parent_class === null || $parent_class === '') {
            return false;
        }

        $declaring = $this->lineage_declaring_property($parent_class, 'requires_auth');

        if ($declaring === null) {
            return false;
        }

        $members = $this->source()->declared_members($declaring['file'], $declaring['class']);
        $property = $members['properties']['requires_auth'] ?? null;

        return $property !== null && !empty($property['has_default']) && $property['default'] === false;
    }

    private function declares_public_topic(string $contents): bool
    {
        return (bool) preg_match(
            '/public\s+static\s+bool\s+\$requires_auth\s*=\s*false\s*;/',
            $contents
        );
    }

    private function find_requires_auth_line(string $contents): int
    {
        if (preg_match('/public\s+static\s+bool\s+\$requires_auth\s*=\s*false\s*;/', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return substr_count($contents, "\n", 0, $matches[0][1]) + 1;
        }

        return 1;
    }

    private function body_has_auth_check(string $body): bool
    {
        foreach (self::AUTH_CHECK_PATTERNS as $pattern) {
            if (str_contains($body, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function build_missing_auth_suggestion(string $class_name): string
    {
        $suggestions = [];
        $suggestions[] = "'{$class_name}' requires auth by default but can_subscribe() has no recognizable check.";
        $suggestions[] = "";
        $suggestions[] = "Option 1: Add a real auth check to can_subscribe():";
        $suggestions[] = "    public static function can_subscribe(array \$filter = []): bool";
        $suggestions[] = "    {";
        $suggestions[] = "        return Session::is_logged_in();  // or Portal_Session::, Permission::...";
        $suggestions[] = "    }";
        $suggestions[] = "";
        $suggestions[] = "Option 2: If this topic is intentionally public, declare it explicitly:";
        $suggestions[] = "    public static bool \$requires_auth = false;";
        $suggestions[] = "";
        $suggestions[] = "(then confirm can_subscribe() and the published payload never leak";
        $suggestions[] = " site/tenant-scoped or otherwise sensitive data)";

        return implode("\n", $suggestions);
    }

    private function build_public_topic_suggestion(string $class_name): string
    {
        $suggestions = [];
        $suggestions[] = "'{$class_name}' declares \$requires_auth = false - it is subscribable by ANY";
        $suggestions[] = "caller, authenticated or not.";
        $suggestions[] = "";
        $suggestions[] = "Confirm:";
        $suggestions[] = "  - can_subscribe() does not depend on session state to stay safe";
        $suggestions[] = "  - every Realtime::publish() call for this topic sends only non-sensitive,";
        $suggestions[] = "    non-tenant-scoped data (record IDs and action types, never field values)";
        $suggestions[] = "  - if the topic IS scoped to a tenant, site_id scoping alone is not enough";
        $suggestions[] = "    for a public topic - site_id is derived from hostname for anonymous";
        $suggestions[] = "    callers, not from any real permission check";
        $suggestions[] = "";
        $suggestions[] = "Once reviewed, suppress with a rationale:";
        $suggestions[] = "    // @REALTIME-AUTH-01-EXCEPTION - reviewed 2026-01-01, public weather data only";

        return implode("\n", $suggestions);
    }
}
