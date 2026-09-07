<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use App\RSpade\Core\Api\Api_Scope_Validation_Exception;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Api_Scopes - the API key scope grammar: what validate() accepts and refuses, what
 * normalize() strips, what matches() does with each wildcard, how parse_all() splits a stored
 * value, and the deny-by-default decision including the malformed-scope fail-closed rule.
 *
 * Pure static logic, so no database.
 */
class Api_Scopes_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    // -------------------------------------------------------------------------
    // validate() - what the grammar accepts
    // -------------------------------------------------------------------------

    private static function __assert_valid(string $scope)
    {
        Api_Scopes::validate($scope);

        // Reaching here IS the assertion; recorded so the accepting half of the table
        // contributes to the count rather than passing silently.
        static::__assert_true(true, "'{$scope}' should validate");
    }

    private static function __assert_invalid(string $scope, string $needle)
    {
        static::__assert_throws(
            Api_Scope_Validation_Exception::class,
            function () use ($scope) {
                Api_Scopes::validate($scope);
            },
            $needle
        );
    }

    public static function test_validate_accepts_literal_paths()
    {
        static::__assert_valid('/api/v1/me');
        static::__assert_valid('/api/v1/clients/42/view');
        static::__assert_valid('/api/v99/deeply/nested/path/here');
    }

    public static function test_validate_accepts_each_wildcard_as_a_whole_segment()
    {
        static::__assert_valid('/api/v1/clients/?/view');
        static::__assert_valid('/api/v1/clients/#/view');
        static::__assert_valid('/api/v1/clients/*');
        static::__assert_valid('/api/v1/*');
    }

    public static function test_validate_accepts_a_wildcard_version()
    {
        static::__assert_valid('/api/?/clients');

        // '#' never matches a 'vN' literal, so it grants nothing there - but it IS the
        // grammar, and the owner ruled it accepted rather than special-cased.
        static::__assert_valid('/api/#/clients');
    }

    public static function test_validate_accepts_a_trailing_slash_and_a_query_string()
    {
        // Both are stripped by normalize() before anything looks at the segments.
        static::__assert_valid('/api/v1/clients/');
        static::__assert_valid('/api/v1/clients?page=2');
    }

    // -------------------------------------------------------------------------
    // validate() - what the grammar refuses
    // -------------------------------------------------------------------------

    public static function test_validate_rejects_a_partial_segment_wildcard()
    {
        static::__assert_invalid('/api/v1/foo/bar*', 'a wildcard must be a whole segment');
        static::__assert_invalid('/api/v1/?bar/view', 'a wildcard must be a whole segment');
        static::__assert_invalid('/api/v1/b#r', 'a wildcard must be a whole segment');
        static::__assert_invalid('/api/v1/*x', 'a wildcard must be a whole segment');
    }

    public static function test_validate_rejects_a_star_that_is_not_last()
    {
        static::__assert_invalid('/api/v1/*/view', "'*' may only be the last segment");
        static::__assert_invalid('/api/*/clients', "'*' may only be the last segment");
    }

    public static function test_validate_rejects_a_missing_api_prefix_or_version()
    {
        static::__assert_invalid('/clients/42', 'must start with /api/<version>/');
        static::__assert_invalid('/api/clients', 'must start with /api/<version>/');
        static::__assert_invalid('/api/v1', 'must start with /api/<version>/');
        static::__assert_invalid('api/v1/clients', 'must start with /api/<version>/');
    }

    public static function test_validate_rejects_a_bad_version_literal()
    {
        static::__assert_invalid('/api/version1/clients', 'the version segment must be vN');
        static::__assert_invalid('/api/1/clients', 'the version segment must be vN');
    }

    public static function test_validate_rejects_an_empty_segment_or_a_blank_scope()
    {
        static::__assert_invalid('/api/v1//clients', 'empty segment');
        static::__assert_invalid('   ', 'cannot be blank');
    }

    public static function test_validate_rejects_the_old_rule_language()
    {
        // The whole point of the rewrite: an old Grant line is not a scope, and says so
        // rather than being read as a literal path that matches nothing.
        static::__assert_invalid('Grant GET /api/v1/contacts/**', 'must start with /api/<version>/');
    }

    // -------------------------------------------------------------------------
    // normalize()
    // -------------------------------------------------------------------------

    public static function test_normalize_strips_whitespace_trailing_slash_and_query()
    {
        static::__assert_equals('/api/v1/clients', Api_Scopes::normalize('  /api/v1/clients  '));
        static::__assert_equals('/api/v1/clients', Api_Scopes::normalize('/api/v1/clients/'));
        static::__assert_equals('/api/v1/clients', Api_Scopes::normalize('/api/v1/clients?page=2&q=x'));
        static::__assert_equals('/api/v1/clients', Api_Scopes::normalize('/api/v1/clients/?page=2'));

        // A query string can only begin in the LAST segment, which is what separates it from
        // the '?' wildcard - so that is the only place it is looked for.
        static::__assert_equals('/api/v1/?bar/view', Api_Scopes::normalize('/api/v1/?bar/view'));
    }

    public static function test_normalize_keeps_a_whole_segment_question_wildcard()
    {
        // '?' between slashes is a wildcard, not the start of a query string.
        static::__assert_equals('/api/?/clients', Api_Scopes::normalize('/api/?/clients'));
        static::__assert_equals('/api/v1/clients/?', Api_Scopes::normalize('/api/v1/clients/?'));
        static::__assert_equals('/api/?/clients', Api_Scopes::normalize('/api/?/clients?page=2'));
    }

    // -------------------------------------------------------------------------
    // matches()
    // -------------------------------------------------------------------------

    public static function test_matches_a_literal_scope_matches_only_itself()
    {
        static::__assert_true(Api_Scopes::matches('/api/v1/clients', '/api/v1/clients'));
        static::__assert_false(Api_Scopes::matches('/api/v1/clients', '/api/v1/clients/view'));
        static::__assert_false(Api_Scopes::matches('/api/v1/clients', '/api/v1/client'));
        static::__assert_false(Api_Scopes::matches('/api/v1/clients', '/api/v2/clients'));
    }

    public static function test_matches_is_case_sensitive()
    {
        // What RouteResolver does, so a scope can never admit a path the router would not
        // route in the first place.
        static::__assert_false(Api_Scopes::matches('/api/v1/clients', '/api/v1/Clients'));
    }

    public static function test_matches_question_takes_exactly_one_segment_of_any_shape()
    {
        static::__assert_true(Api_Scopes::matches('/api/v1/clients/?/view', '/api/v1/clients/42/view'));
        static::__assert_true(Api_Scopes::matches('/api/v1/clients/?/view', '/api/v1/clients/settings/view'));
        static::__assert_false(Api_Scopes::matches('/api/v1/clients/?/view', '/api/v1/clients/view'));
        static::__assert_false(Api_Scopes::matches('/api/v1/clients/?/view', '/api/v1/clients/42/7/view'));
    }

    public static function test_matches_hash_takes_exactly_one_all_digits_segment()
    {
        static::__assert_true(Api_Scopes::matches('/api/v1/clients/#/view', '/api/v1/clients/42/view'));
        static::__assert_false(Api_Scopes::matches('/api/v1/clients/#/view', '/api/v1/clients/settings/view'));
        static::__assert_false(Api_Scopes::matches('/api/v1/clients/#/view', '/api/v1/clients/4a/view'));
    }

    public static function test_matches_star_is_prefix_inclusive()
    {
        static::__assert_true(Api_Scopes::matches('/api/v1/foo/baz/*', '/api/v1/foo/baz'));
        static::__assert_true(Api_Scopes::matches('/api/v1/foo/baz/*', '/api/v1/foo/baz/x'));
        static::__assert_true(Api_Scopes::matches('/api/v1/foo/baz/*', '/api/v1/foo/baz/x/y'));
        static::__assert_false(Api_Scopes::matches('/api/v1/foo/baz/*', '/api/v1/foo'));
        static::__assert_false(Api_Scopes::matches('/api/v1/foo/baz/*', '/api/v1/foo/bazz'));
    }

    public static function test_matches_a_wildcard_version()
    {
        static::__assert_true(Api_Scopes::matches('/api/?/clients', '/api/v1/clients'));
        static::__assert_true(Api_Scopes::matches('/api/?/clients', '/api/v7/clients'));
        static::__assert_false(Api_Scopes::matches('/api/?/clients', '/api/v1/clients/42'));

        // '#' in the version position is legal grammar that matches no real version.
        static::__assert_false(Api_Scopes::matches('/api/#/clients', '/api/v1/clients'));
    }

    public static function test_matches_ignores_trailing_slashes_on_both_sides()
    {
        static::__assert_true(Api_Scopes::matches('/api/v1/clients/', '/api/v1/clients'));
        static::__assert_true(Api_Scopes::matches('/api/v1/clients', '/api/v1/clients/'));
        static::__assert_true(Api_Scopes::matches('/api/v1/clients/', '/api/v1/clients/'));
    }

    public static function test_matches_ignores_the_query_string()
    {
        static::__assert_true(Api_Scopes::matches('/api/v1/clients', '/api/v1/clients?page=2'));
        static::__assert_true(Api_Scopes::matches('/api/v1/clients/*', '/api/v1/clients/42?expand=notes'));
    }

    // -------------------------------------------------------------------------
    // parse_all()
    // -------------------------------------------------------------------------

    public static function test_parse_all_splits_valid_from_malformed()
    {
        $parsed = Api_Scopes::parse_all("/api/v1/me\n\n/api/v1/foo*\n/api/v1/clients/*\n");

        static::__assert_equals(['/api/v1/me', '/api/v1/clients/*'], $parsed['valid']);
        static::__assert_array_has_key('/api/v1/foo*', $parsed['malformed']);
        static::__assert_contains('whole segment', $parsed['malformed']['/api/v1/foo*']);
    }

    public static function test_parse_all_normalizes_and_dedupes()
    {
        $parsed = Api_Scopes::parse_all("  /api/v1/clients/  \n/api/v1/clients\n/api/v1/clients?page=2");

        static::__assert_equals(['/api/v1/clients'], $parsed['valid']);
    }

    public static function test_parse_all_never_throws_on_a_stored_value()
    {
        $parsed = Api_Scopes::parse_all("Grant GET /api/v1/contacts/**\nDeny POST /api/v1/x");

        static::__assert_equals([], $parsed['valid']);
        static::__assert_count(2, $parsed['malformed']);
    }

    public static function test_parse_all_of_null_is_empty()
    {
        $parsed = Api_Scopes::parse_all(null);

        static::__assert_equals([], $parsed['valid']);
        static::__assert_equals([], $parsed['malformed']);
    }

    // -------------------------------------------------------------------------
    // canonicalize() / count_scopes() / is_unrestricted()
    // -------------------------------------------------------------------------

    public static function test_canonicalize_normalizes_dedupes_and_returns_null_when_empty()
    {
        static::__assert_equals(
            "/api/v1/me\n/api/v1/clients",
            Api_Scopes::canonicalize("  /api/v1/me/ \n/api/v1/clients\n/api/v1/me\n")
        );

        static::__assert_null(Api_Scopes::canonicalize(null));
        static::__assert_null(Api_Scopes::canonicalize("\n   \n"));
    }

    public static function test_canonicalize_refuses_the_whole_set_on_the_first_bad_scope()
    {
        static::__assert_throws(
            Api_Scope_Validation_Exception::class,
            function () {
                Api_Scopes::canonicalize("/api/v1/me\n/api/v1/foo*\n/api/v1/clients");
            },
            'whole segment'
        );
    }

    public static function test_count_scopes_counts_malformed_scopes_too()
    {
        // A malformed scope grants nothing yet still makes the key deny-by-default, so a
        // count that omitted it would describe a key that can call nothing as unnarrowed.
        static::__assert_equals(2, Api_Scopes::count_scopes("/api/v1/me\n/api/v1/foo*"));
        static::__assert_equals(0, Api_Scopes::count_scopes(null));
    }

    public static function test_is_unrestricted_only_for_null_or_blank()
    {
        static::__assert_true(Api_Scopes::is_unrestricted(null));
        static::__assert_true(Api_Scopes::is_unrestricted(''));
        static::__assert_true(Api_Scopes::is_unrestricted("   \n  "));
        static::__assert_false(Api_Scopes::is_unrestricted('/api/v1/me'));

        // Malformed-only is a SCOPED key that grants nothing - never an unrestricted one.
        static::__assert_false(Api_Scopes::is_unrestricted('/api/v1/foo*'));
    }

    // -------------------------------------------------------------------------
    // decide()
    // -------------------------------------------------------------------------

    public static function test_decide_unrestricted_reaches_everything()
    {
        static::__assert_true(Api_Scopes::decide(null, '/api/v1/anything/at/all'));
        static::__assert_true(Api_Scopes::decide('', '/api/v1/anything'));
    }

    public static function test_decide_any_scope_makes_the_key_deny_by_default()
    {
        $scopes = '/api/v1/contacts/*';

        static::__assert_true(Api_Scopes::decide($scopes, '/api/v1/contacts'));
        static::__assert_true(Api_Scopes::decide($scopes, '/api/v1/contacts/42/update'));
        static::__assert_false(Api_Scopes::decide($scopes, '/api/v1/clients'));
        static::__assert_false(Api_Scopes::decide($scopes, '/api/v1/me'));
    }

    public static function test_decide_is_the_union_of_every_scope_and_order_independent()
    {
        $a = "/api/v1/me\n/api/v1/clients/#/view";
        $b = "/api/v1/clients/#/view\n/api/v1/me";

        foreach ([$a, $b] as $scopes) {
            static::__assert_true(Api_Scopes::decide($scopes, '/api/v1/me'));
            static::__assert_true(Api_Scopes::decide($scopes, '/api/v1/clients/42/view'));
            static::__assert_false(Api_Scopes::decide($scopes, '/api/v1/clients/x/view'));
        }
    }

    public static function test_decide_ignores_the_query_string()
    {
        static::__assert_true(Api_Scopes::decide('/api/v1/contacts', '/api/v1/contacts?page=2'));
    }

    public static function test_decide_is_version_specific()
    {
        static::__assert_true(Api_Scopes::decide('/api/v1/*', '/api/v1/contacts'));
        static::__assert_false(Api_Scopes::decide('/api/v1/*', '/api/v2/contacts'));
        static::__assert_true(Api_Scopes::decide('/api/?/*', '/api/v2/contacts'));
    }

    public static function test_decide_fails_closed_on_a_malformed_only_scope_set()
    {
        // The whole safety property: a stored value nobody can read denies everything rather
        // than being treated as absent (which would mean unrestricted).
        static::__assert_false(Api_Scopes::decide('/api/v1/foo*', '/api/v1/foo/bar'));
        static::__assert_false(Api_Scopes::decide('/api/v1/foo*', '/api/v1/anything'));
    }

    public static function test_decide_skips_a_malformed_scope_and_honors_the_valid_ones()
    {
        $scopes = "/api/v1/foo*\n/api/v1/me";

        static::__assert_true(Api_Scopes::decide($scopes, '/api/v1/me'));
        static::__assert_false(Api_Scopes::decide($scopes, '/api/v1/foo/bar'));
    }

    // -------------------------------------------------------------------------
    // reaches_route()
    // -------------------------------------------------------------------------

    public static function test_reaches_route_treats_a_param_token_as_opaque()
    {
        // ':id' stands for every value it could take, so every scope segment reaches it.
        static::__assert_true(Api_Scopes::reaches_route('/api/v1/clients/?/view', '/api/v1/clients/:id/view'));
        static::__assert_true(Api_Scopes::reaches_route('/api/v1/clients/#/view', '/api/v1/clients/:id/view'));
        static::__assert_true(Api_Scopes::reaches_route('/api/v1/clients/42/view', '/api/v1/clients/:id/view'));
        static::__assert_true(Api_Scopes::reaches_route('/api/v1/clients/*', '/api/v1/clients/:id/view'));
    }

    public static function test_reaches_route_still_compares_two_literals()
    {
        static::__assert_false(Api_Scopes::reaches_route('/api/v1/clients/*', '/api/v1/contacts/:id'));
        static::__assert_false(Api_Scopes::reaches_route('/api/v1/contacts', '/api/v1/contacts/:id'));
    }

    public static function test_reaches_route_unrestricted_reaches_every_route()
    {
        static::__assert_true(Api_Scopes::reaches_route(null, '/api/v1/anything/:id'));
    }
}
