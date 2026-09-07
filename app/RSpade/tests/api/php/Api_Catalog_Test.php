<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Api_Catalog - the single implementation of the version display/resolution rule.
 *
 * parse_version() and path_key() are pure units. get_versions()/resolve_for_version()
 * read the FINALIZED real manifest (which carries the template app's v1 endpoints), so
 * these assert structural invariants rather than exact counts - the catalog cannot be
 * mocked without rebuilding the manifest.
 */
class Api_Catalog_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // -------------------------------------------------------------------------
    // parse_version() / path_key() units
    // -------------------------------------------------------------------------

    public static function test_parse_version_extracts_integer()
    {
        static::__assert_equals(1, Api_Catalog::parse_version('/api/v1/contacts'));
        static::__assert_equals(2, Api_Catalog::parse_version('/api/v2/contacts/:id'));
        static::__assert_equals(17, Api_Catalog::parse_version('/api/v17/x'));
    }

    public static function test_parse_version_zero_for_non_api_pattern()
    {
        static::__assert_equals(0, Api_Catalog::parse_version('/contacts'));
        static::__assert_equals(0, Api_Catalog::parse_version('/apix/v1/x'));
    }

    public static function test_path_key_strips_version_prefix()
    {
        static::__assert_equals('/contacts', Api_Catalog::path_key('/api/v1/contacts'));
        static::__assert_equals('/contacts/:id', Api_Catalog::path_key('/api/v3/contacts/:id'));
        static::__assert_equals('/contacts/:id/update', Api_Catalog::path_key('/api/v2/contacts/:id/update'));
    }

    public static function test_path_key_is_version_agnostic()
    {
        // The same path key regardless of version is what lets the display rule group
        // v1/v2/v3 of one endpoint together.
        static::__assert_equals(
            Api_Catalog::path_key('/api/v1/contacts/:id'),
            Api_Catalog::path_key('/api/v9/contacts/:id')
        );
    }

    // -------------------------------------------------------------------------
    // get_versions() - real manifest
    // -------------------------------------------------------------------------

    public static function test_get_versions_contains_v1_and_is_sorted_desc()
    {
        $versions = Api_Catalog::get_versions();

        static::__assert_not_empty($versions, 'the template app declares at least v1 endpoints');
        static::__assert_true(in_array(1, $versions, true), 'v1 is present');

        $sorted = $versions;
        rsort($sorted);
        static::__assert_equals($sorted, $versions, 'versions come back descending');
    }

    // -------------------------------------------------------------------------
    // resolve_for_version() - real manifest
    // -------------------------------------------------------------------------

    public static function test_resolve_for_version_groups_nonempty()
    {
        $grouped = Api_Catalog::resolve_for_version(1);
        static::__assert_not_empty($grouped, 'v1 resolves to at least one resource group');

        foreach ($grouped as $group) {
            static::__assert_array_has_key('endpoints', $group);
            static::__assert_not_empty($group['endpoints'], 'a resource group carries endpoints');
        }
    }

    public static function test_resolve_never_shows_a_version_above_requested()
    {
        // Requesting v1 must never surface an endpoint whose real version exceeds 1.
        $grouped = Api_Catalog::resolve_for_version(1);

        foreach ($grouped as $group) {
            foreach ($group['endpoints'] as $ep) {
                static::__assert_true($ep['version'] <= 1, 'resolved endpoint version <= requested version');
            }
        }
    }

    public static function test_resolve_for_version_zero_is_empty()
    {
        // No endpoint has version <= 0, so every group is skipped.
        static::__assert_equals([], Api_Catalog::resolve_for_version(0));
    }

    // -------------------------------------------------------------------------
    // resolve_for_scopes() - the same catalogue, narrowed by a scope set
    // -------------------------------------------------------------------------

    public static function test_resolve_for_scopes_unrestricted_is_the_plain_catalogue()
    {
        // The contract of an unrestricted key: nothing is narrowed, at all. Asserted as
        // identity rather than 'nonempty', because a subtly-lossy narrowing that still
        // returned endpoints would pass a weaker check.
        $plain = Api_Catalog::resolve_for_version(1);

        static::__assert_equals($plain, Api_Catalog::resolve_for_scopes(1, null));
        static::__assert_equals($plain, Api_Catalog::resolve_for_scopes(1, ''));
        static::__assert_equals($plain, Api_Catalog::resolve_for_scopes(1, "   \n  "));
    }

    public static function test_resolve_for_scopes_keeps_only_reachable_endpoints()
    {
        $groups = Api_Catalog::resolve_for_scopes(1, '/api/v1/contacts/*');

        static::__assert_not_empty($groups, 'the template app publishes v1 contacts endpoints');

        foreach ($groups as $group) {
            foreach ($group['endpoints'] as $ep) {
                static::__assert_true(
                    str_starts_with($ep['pattern'], '/api/v1/contacts'),
                    'only the reachable resource survives, got ' . $ep['pattern']
                );
            }
        }
    }

    public static function test_resolve_for_scopes_keeps_every_verb_of_a_reachable_endpoint()
    {
        // A scope carries no method, so there is nothing to narrow an endpoint's verbs
        // against: an endpoint is listed whole or not at all.
        $plain = Api_Catalog::resolve_for_version(1);
        $scoped = Api_Catalog::resolve_for_scopes(1, '/api/v1/*');

        static::__assert_equals($plain, $scoped, 'a scope reaching every v1 path narrows nothing');
    }

    public static function test_resolve_for_scopes_reaches_nothing_when_nothing_matches()
    {
        // Deny by default, projected: a scope naming a resource that does not exist reaches
        // nothing, and the whole catalogue drops out rather than degrading to 'everything'.
        static::__assert_equals([], Api_Catalog::resolve_for_scopes(1, '/api/v1/no_such_resource/*'));
    }

    public static function test_resolve_for_scopes_is_a_strict_subset()
    {
        $everything = Api_Catalog::resolve_for_scopes(1, '/api/v1/*');
        $narrowed = Api_Catalog::resolve_for_scopes(1, '/api/v1/contacts/*');

        static::__assert_not_empty($narrowed);
        static::__assert_less_than(
            static::__count_endpoints($everything),
            static::__count_endpoints($narrowed),
            'naming one resource reaches fewer endpoints than naming them all'
        );
    }

    public static function test_resolve_for_scopes_skips_a_malformed_scope_rather_than_throwing()
    {
        // Reading never throws - the preview panel asks this question about text an operator
        // is still typing. A malformed scope simply grants nothing.
        static::__assert_equals([], Api_Catalog::resolve_for_scopes(1, '/api/v1/contacts*'));

        $mixed = Api_Catalog::resolve_for_scopes(1, "/api/v1/contacts*\n/api/v1/contacts/*");
        static::__assert_not_empty($mixed, 'the valid scope still reaches its endpoints');
    }

    // -------------------------------------------------------------------------
    // resolve_for_scopes($read_only)
    // -------------------------------------------------------------------------

    public static function test_resolve_for_scopes_read_only_lists_only_get_verbs()
    {
        // The verb axis a scope cannot express. Every endpoint that survives must offer GET
        // and nothing else - the panel this feeds must never show a call the dispatcher
        // would answer with read_only_key.
        $groups = Api_Catalog::resolve_for_scopes(1, null, false, true);

        static::__assert_not_empty($groups, 'a read-only key still reaches the GET endpoints');

        foreach ($groups as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                static::__assert_equals(['GET'], $endpoint['methods'], $endpoint['pattern'] . ' lists GET alone');
            }
        }
    }

    public static function test_resolve_for_scopes_read_only_is_a_strict_subset()
    {
        $everything = Api_Catalog::resolve_for_scopes(1, null, false, false);
        $read_only = Api_Catalog::resolve_for_scopes(1, null, false, true);

        static::__assert_less_than(
            static::__count_endpoints($everything),
            static::__count_endpoints($read_only),
            'a read-only key reaches fewer endpoints than a read+write one'
        );
    }

    public static function test_resolve_for_scopes_composes_read_only_with_the_scopes()
    {
        // The two narrowings are independent and both apply: paths from the scopes, verbs
        // from the flag.
        $scoped = Api_Catalog::resolve_for_scopes(1, '/api/v1/contacts/*', false, false);
        $both = Api_Catalog::resolve_for_scopes(1, '/api/v1/contacts/*', false, true);

        static::__assert_not_empty($both, 'contacts still has a GET endpoint');
        static::__assert_less_than(
            static::__count_endpoints($scoped),
            static::__count_endpoints($both),
            'the read-only flag narrows what the scopes already narrowed'
        );

        foreach ($both as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                static::__assert_equals(['GET'], $endpoint['methods']);
                static::__assert_true(
                    str_starts_with((string) $endpoint['pattern'], '/api/v1/contacts'),
                    'and the scopes still decide the paths'
                );
            }
        }
    }

    public static function test_resolve_for_scopes_read_only_defaults_off()
    {
        static::__assert_equals(
            Api_Catalog::resolve_for_scopes(1, null),
            Api_Catalog::resolve_for_scopes(1, null, false, false),
            'omitting the flag is the read+write answer'
        );
    }

    private static function __count_endpoints(array $groups): int
    {
        $total = 0;

        foreach ($groups as $group) {
            $total += count($group['endpoints']);
        }

        return $total;
    }
}
