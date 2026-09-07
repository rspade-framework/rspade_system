<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Codegen\Php;

use ReflectionClass;
use App\RSpade\Core\PHP\Php_Fixer;
use App\RSpade\Core\PHP\Pre_Autoload_Reachability;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Php_Fixer's source-safety guarantees (backlog B-68 and the relationship-attribute defect
 * filed alongside it).
 *
 * Php_Fixer REWRITES SOURCE FILES on every manifest build, so a mistake here is not a bad
 * report - it is committed damage to code nobody touched. Two families of defect are
 * pinned down:
 *
 * IMPORT DELETION. The fixer removes a `Rsx\`/`App\RSpade\` import it cannot resolve
 * through the manifest, on the reasoning that the re-add pass restores anything still
 * referenced. That reasoning failed twice in production (2026-08-05, 2026-08-09), both
 * times stripping a trait import out of four app models, because:
 *   - the re-add scanner could not see a trait `use` inside a class body, so the import
 *     never came back; and
 *   - re-adding resolves through the SAME manifest that just failed to resolve the
 *     deletion, so the pass that deletes can never be the pass that restores.
 * Recovery both times was `git restore`. Guard 2 (index health) and guard 1 (on-disk proof)
 * make deletion require positive evidence; the new scanner arms make the cycle symmetric.
 *
 * ATTRIBUTE DUPLICATION. The #[Relationship] detector walked backwards token by token
 * against an allow-list and aborted on anything outside it - including a string inside an
 * attribute's arguments. `#[Auth('is_logged_in')]` between #[Relationship] and the function
 * keyword therefore hid the attribute, and the fixer inserted a duplicate on EVERY build
 * (observed 1 -> 3 -> 7). The auth-gates release makes #[Auth] mandatory on gated
 * relationship surfaces, so this was on course to hit every downstream app.
 *
 * The private token seams are driven directly through reflection: they are pure functions
 * over token_get_all() output, which is exactly what production feeds them, and testing
 * them this way needs no manifest and writes no files.
 */
class Php_Fixer_Import_Safety_Test extends Rsx_Test_Abstract
{
    // Pure token parsing - no database access.
    protected static $use_database_transactions = false;

    /** Call a private static seam on Php_Fixer. */
    private static function __invoke_seam(string $method, array $args)
    {
        $reflection = new ReflectionClass(Php_Fixer::class);
        $seam = $reflection->getMethod($method);
        $seam->setAccessible(true);

        return $seam->invokeArgs(null, $args);
    }

    /** Clear guard 2's per-process memo so an index-health assertion is about the index. */
    private static function __reset_class_index_health(): void
    {
        $reflection = new ReflectionClass(Php_Fixer::class);
        $memo = $reflection->getProperty('__class_index_healthy');
        $memo->setAccessible(true);
        $memo->setValue(null, null);
    }

    /** Index of the first T_FUNCTION token, which is what the detector anchors on. */
    private static function __function_position(array $tokens): int
    {
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_FUNCTION) {
                return $index;
            }
        }

        static::__fail('fixture contains no function');

        return 0;
    }

    private static function __has_relationship(string $source): bool
    {
        $tokens = token_get_all($source);

        return (bool) static::__invoke_seam(
            '__has_relationship_attribute',
            [$tokens, static::__function_position($tokens)]
        );
    }

    /** @return array<int, string> */
    private static function __referenced_classes(string $source): array
    {
        return (array) static::__invoke_seam('__find_referenced_simple_classes', [token_get_all($source)]);
    }

    // =====================================================================
    // Reference scanning - what makes deletion recoverable
    // =====================================================================

    public static function test_a_trait_use_counts_as_a_reference()
    {
        // THE B-68 SHAPE. These four app models really did reference the trait; the
        // scanner just could not see this spelling, so the stripped import never returned
        // and every later build fataled on the missing trait.
        $source = "<?php\nnamespace X;\nclass M {\n    use Portal_Authorizable;\n}\n";

        static::__assert_true(
            in_array('Portal_Authorizable', static::__referenced_classes($source), true),
            'a trait use inside a class body is a class reference'
        );
    }

    public static function test_multiple_traits_in_one_use_statement_are_all_found()
    {
        $source = "<?php\nnamespace X;\nclass M {\n    use Alpha_Trait, Beta_Trait;\n}\n";
        $found = static::__referenced_classes($source);

        static::__assert_true(in_array('Alpha_Trait', $found, true), 'first trait in the list');
        static::__assert_true(in_array('Beta_Trait', $found, true), 'second trait in the list');
    }

    public static function test_the_trait_block_form_is_found()
    {
        $source = "<?php\nnamespace X;\nclass M {\n    use Alpha_Trait { foo as bar; }\n}\n";

        static::__assert_true(
            in_array('Alpha_Trait', static::__referenced_classes($source), true),
            'the conflict-resolution block form still names a trait'
        );
    }

    public static function test_a_typed_property_counts_as_a_reference()
    {
        // Found while fixing the trait gap: the type-hint arm only ran inside a function's
        // parameter list, so a property type was never a reference either.
        $source = "<?php\nnamespace X;\nclass M {\n    protected Portal_User_Model \$user;\n"
            . "    private ?Site_Model \$site;\n}\n";
        $found = static::__referenced_classes($source);

        static::__assert_true(in_array('Portal_User_Model', $found, true), 'a typed property is a reference');
        static::__assert_true(in_array('Site_Model', $found, true), 'a nullable typed property is too');
    }

    public static function test_a_closure_capture_is_not_a_class_reference()
    {
        // `use` has three meanings and only one of them is a trait. A closure's capture
        // list must never be mistaken for one.
        $source = "<?php\nnamespace X;\nclass M {\n    public function f() {\n"
            . "        \$c = function () use (\$captured) { return \$captured; };\n    }\n}\n";

        static::__assert_false(
            in_array('captured', static::__referenced_classes($source), true),
            'a closure capture list is not a trait use'
        );
    }

    // =====================================================================
    // Deletion guards
    // =====================================================================

    public static function test_a_degraded_class_index_blocks_import_deletion()
    {
        // The REAL failure state is a PARTIAL index - app entries present, framework class
        // metadata missing - not an empty one. An empty index would fix no files at all,
        // because _run_php_fixer() iterates it to choose the work.
        // Guard 2's answer is memoized per process, and a manifest rebuild earlier in this
        // same process will already have answered it. Clear it, or this asserts nothing.
        static::__reset_class_index_health();

        $degraded = ['data' => ['files' => [
            'rsx/models/portal_membership_model.php' => ['class' => 'Portal_Membership_Model'],
            'app/RSpade/Core/Portal/Portal_Authorizable.php' => [],
        ]]];

        static::__assert_false(
            (bool) static::__invoke_seam('__class_index_is_healthy', [&$degraded]),
            'a partial index is not evidence that anything is missing'
        );
    }

    public static function test_a_class_in_an_unscanned_framework_zone_is_proof_of_existence()
    {
        // config('rsx.manifest.scan_directories') deliberately omits Commands, Database,
        // Http, Ide and SchemaQuality, so imports of classes living there were unresolvable
        // on a PERFECTLY HEALTHY index and were being deleted every build.
        static::__assert_true(
            (bool) static::__invoke_seam('__class_file_exists_outside_the_index', ['Maint_Migrate']),
            'a real class under the unscanned Commands/ zone is found on disk'
        );

        static::__assert_false(
            (bool) static::__invoke_seam('__class_file_exists_outside_the_index', ['Totally_Made_Up_Class_Name']),
            'a class that exists nowhere is not resurrected by the guard'
        );
    }

    // =====================================================================
    // Guard 3 - the register phase (field report 2026-08-25)
    // =====================================================================

    public static function test_the_register_phase_set_holds_the_classes_that_died()
    {
        // These four are the ones the field report named. All extend or reference
        // BundleIntegration_Abstract, all are reached from a service provider's register(),
        // and all of them lost that import - after which composer resolved their parent
        // into their own namespace and nothing could boot.
        $register_phase_files = [
            'app/RSpade/Integrations/Jqhtml/Jqhtml_BundleIntegration.php',
            'app/RSpade/Core/IntegrationRegistry.php',
            'app/RSpade/Core/Database/Database_BundleIntegration.php',
            'app/RSpade/Core/Controller/Controller_BundleIntegration.php',
        ];

        foreach ($register_phase_files as $file) {
            static::__assert_true(
                Pre_Autoload_Reachability::contains_file($file),
                $file . ' loads before Autoloader::register()'
            );
        }

        static::__assert_true(
            Pre_Autoload_Reachability::contains_file('app/RSpade/Core/Bundle/BundleIntegration_Abstract.php'),
            'and so does the parent whose import was stripped'
        );
    }

    public static function test_the_set_is_derived_not_universal()
    {
        // A guard that swallowed the whole framework would be indistinguishable from
        // switching deletion off, so the derivation has to actually discriminate.
        static::__assert_false(
            Pre_Autoload_Reachability::contains_file('app/RSpade/Core/Api/Api_Catalog.php'),
            'a class nothing in the register phase reaches is outside the set'
        );

        static::__assert_false(
            Pre_Autoload_Reachability::contains_file('rsx/models/client_model.php'),
            'application code is never in the set - the derivation only follows App\\RSpade names'
        );
    }

    public static function test_a_register_phase_file_never_loses_an_import()
    {
        // 'Route' is the fixer's one unconditional deletion (it names Laravel's Route
        // helper, which collides with the #[Route] attribute), so it reaches the decision
        // with a verdict of DELETE no matter what the class index looks like. In a
        // register-phase file guard 3 has to overrule it: this file cannot afford to lose
        // an import for any reason, and there is no autoloader standing by to cover it.
        $index = ['data' => ['files' => []]];

        foreach ([
            'app/RSpade/Integrations/Jqhtml/Jqhtml_BundleIntegration.php',
            'app/RSpade/Core/IntegrationRegistry.php',
            'app/RSpade/Core/Database/Database_BundleIntegration.php',
            'app/RSpade/Core/Controller/Controller_BundleIntegration.php',
        ] as $file) {
            static::__assert_false(
                static::__invoke_seam('__should_remove_use_statement', ['Route', $file, &$index]),
                'no import is stripped from ' . $file
            );
        }
    }

    public static function test_a_redundant_import_elsewhere_is_still_stripped()
    {
        // POSITIVE CONTROL. Guard 3 suppresses deletions inside its set and nowhere else -
        // if this stopped passing, the guard would have quietly retired the fixer.
        $index = ['data' => ['files' => []]];

        static::__assert_true(
            static::__invoke_seam('__should_remove_use_statement', ['Route', 'rsx/models/client_model.php', &$index]),
            'an application file still loses its redundant Route import'
        );

        static::__assert_true(
            static::__invoke_seam('__should_remove_use_statement', ['Route', 'app/RSpade/Core/Api/Api_Catalog.php', &$index]),
            'and so does a framework file outside the register phase'
        );
    }

    // =====================================================================
    // #[Relationship] detection and de-duplication
    // =====================================================================

    public static function test_an_attribute_argument_does_not_hide_the_relationship_attribute()
    {
        // THE EXACT FIELD REPRO. The string inside #[Auth(...)] aborted the old walk.
        $source = "<?php\nclass M {\n    #[Relationship]\n    #[Ajax_Endpoint_Model_Fetch]\n"
            . "    #[Auth('is_logged_in')]\n    public function retainers() { return 1; }\n}\n";

        static::__assert_true(
            static::__has_relationship($source),
            '#[Relationship] is still seen through an attribute carrying a string argument'
        );
    }

    public static function test_an_array_argument_does_not_hide_it_either()
    {
        // Bracket counting, not "find the nearest #[": an array literal inside the
        // arguments contains brackets of its own.
        $source = "<?php\nclass M {\n    #[Relationship]\n    #[Api_Param('x', opts: [1, 2])]\n"
            . "    public function r() { return 1; }\n}\n";

        static::__assert_true(
            static::__has_relationship($source),
            'nested brackets in a later attribute do not break the group walk'
        );
    }

    public static function test_a_plain_relationship_attribute_is_still_detected()
    {
        $source = "<?php\nclass M {\n    #[Relationship]\n    public function r() { return 1; }\n}\n";

        static::__assert_true(static::__has_relationship($source), 'the ordinary case still works');
    }

    public static function test_a_missing_relationship_attribute_is_reported_missing()
    {
        // The detector must stay capable of saying NO, or the fixer would stop adding the
        // attribute where it genuinely belongs.
        $source = "<?php\nclass M {\n    #[Auth('x')]\n    public function r() { return 1; }\n}\n";

        static::__assert_false(static::__has_relationship($source), 'absence is still detected as absence');
    }

    public static function test_existing_duplicate_attributes_collapse_to_one()
    {
        // Downstream apps already carry this damage; it heals on the next build.
        $source = "<?php\nclass M {\n    #[Relationship]\n    #[Relationship]\n    #[Relationship]\n"
            . "    #[Auth('x')]\n    public function r() { return 1; }\n}\n";

        $fixed = (string) static::__invoke_seam('__deduplicate_relationship_attributes', [$source]);

        static::__assert_equals(1, substr_count($fixed, '#[Relationship]'), 'three copies become one');
        static::__assert_contains('#[Auth(', $fixed, 'other attributes are untouched');
    }

    public static function test_deduplication_does_not_merge_separate_methods()
    {
        // Each declaration keeps its own attribute - the run must reset between members.
        $source = "<?php\nclass M {\n    #[Relationship]\n    public function a() { return 1; }\n\n"
            . "    #[Relationship]\n    public function b() { return 2; }\n}\n";

        $fixed = (string) static::__invoke_seam('__deduplicate_relationship_attributes', [$source]);

        static::__assert_equals(
            2,
            substr_count($fixed, '#[Relationship]'),
            'two methods keep two attributes'
        );
    }
}
