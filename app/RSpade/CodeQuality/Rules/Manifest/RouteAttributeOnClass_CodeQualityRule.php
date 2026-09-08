<?php

namespace App\RSpade\CodeQuality\Rules\Manifest;

use App\RSpade\CodeQuality\Rules\CodeQualityRule_Abstract;
use App\RSpade\Core\Manifest\Manifest;

/**
 * ROUTE-ATTR-01 - a route attribute belongs on a static METHOD, never on the class.
 *
 * #[Route] (and the verb spellings #[Get] / #[Post] / #[Put] / #[Delete] / #[Patch]) declare
 * ONE dispatchable surface. Written above the class instead of above a method, the attribute
 * declares nothing: the manifest indexes routes off `public_static_methods`, so the class-level
 * one is silently inert and the controller answers no URL at all.
 *
 * THIS USED TO RUN ON EVERY DEVELOPMENT REQUEST. `Dispatcher::__validate_route_attributes()`
 * walked every indexed file's class-level attributes at the top of `dispatch()` - a full pass
 * over the index per page view, to answer a question that is a property of the SOURCE and
 * changes only when the source does. It is a build-time judgement, so it is a build-time rule.
 */
class RouteAttributeOnClass_CodeQualityRule extends CodeQualityRule_Abstract
{
    /**
     * The attribute spellings that declare a route. Simple names: that is how the manifest
     * records an attribute, and how RSX writes one.
     */
    private const ROUTE_ATTRIBUTES = ['Route', 'Get', 'Post', 'Put', 'Delete', 'Patch'];

    public function get_id(): string
    {
        return 'ROUTE-ATTR-01';
    }

    public function get_name(): string
    {
        return 'Route Attribute On Class';
    }

    public function get_description(): string
    {
        return 'Detects a #[Route] (or verb) attribute placed on a class instead of a static method';
    }

    public function get_file_patterns(): array
    {
        return ['*.php'];
    }

    public function is_called_during_manifest_scan(): bool
    {
        return true;
    }

    public function get_default_severity(): string
    {
        return 'critical';
    }

    /**
     * CROSS-FILE: the subject is every indexed class's attribute list.
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
        return ['files:*.php', 'attribute_index'];
    }

    public function check(string $file_path, string $contents, array $metadata = []): void
    {
        foreach (self::ROUTE_ATTRIBUTES as $attribute) {
            foreach (Manifest::by_attribute($attribute) as $row) {
                // member === null is the class-level declaration; a method one is correct.
                if ($row['member'] !== null) {
                    continue;
                }

                $class_name = $row['class'] ?? 'Unknown';

                $this->add_violation(
                    $row['file'],
                    1,
                    "Class '{$class_name}' carries #[{$attribute}] on the class itself.",
                    "#[{$attribute}(...)]\nclass {$class_name} extends Rsx_Controller_Abstract",
                    $this->build_suggestion($class_name, $attribute),
                    'critical'
                );
            }
        }
    }

    private function build_suggestion(string $class_name, string $attribute): string
    {
        $lines = [];
        $lines[] = "A route attribute declares ONE dispatchable surface, so it goes on the static";
        $lines[] = "method that serves it. On the class it declares nothing: the manifest reads";
        $lines[] = "routes off public static methods, so the controller answers no URL at all.";
        $lines[] = '';
        $lines[] = 'TO FIX: move the attribute onto the method.';
        $lines[] = '';
        $lines[] = "  class {$class_name} extends Rsx_Controller_Abstract {";
        $lines[] = "      #[{$attribute}('/path')]";
        $lines[] = "      #[Auth('is_logged_in')]";
        $lines[] = '      public static function index(Request $request, array $params = []) {';
        $lines[] = '          // ...';
        $lines[] = '      }';
        $lines[] = '  }';
        $lines[] = '';
        $lines[] = 'See: php artisan rsx:man routing';

        return implode("\n", $lines);
    }
}
