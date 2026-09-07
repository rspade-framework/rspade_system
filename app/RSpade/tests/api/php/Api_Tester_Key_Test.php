<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use App\RSpade\Core\Api\Api_Catalog;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Api\Api_Tester_Key;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Api_Tester_Key::accessible_targets_for_key() - gates INTERSECTED with the key's scopes.
 *
 * The composition is the whole subject. accessible_targets_for_user() already has its own
 * meaning (what #[Auth] admits) and Api_Scopes has its own (what the rules reach); what these
 * assert is that the key answer is the AND of the two and never one of them alone - a
 * console that showed either half by itself would list endpoints the reader gets a 403 from,
 * or hide endpoints they can call.
 *
 * Keys are written inside the default per-test transaction, so nothing survives the class.
 */
class Api_Tester_Key_Test extends Rsx_Test_Abstract
{
    private const USER_ID = 1;

    /**
     * Every published target, as accessible_targets_for_user() keys them.
     *
     * @return array<int, string>
     */
    private static function __all_targets(): array
    {
        $targets = [];

        foreach (Api_Catalog::get_endpoint_list(false) as $endpoint) {
            $targets[] = $endpoint['class'] . '::' . $endpoint['method'];
        }

        return $targets;
    }

    /**
     * The targets an answer map admits.
     *
     * @return array<int, string>
     */
    private static function __allowed(array $map): array
    {
        return array_keys(array_filter($map));
    }

    private static function __user(): User_Model
    {
        return User_Model::without_site_scope(fn () => User_Model::find(self::USER_ID));
    }

    // -------------------------------------------------------------------------
    // The unrestricted case: the key answer IS the user answer
    // -------------------------------------------------------------------------

    public static function test_unrestricted_key_matches_its_user_exactly()
    {
        $key = Api_Key_Model::generate(self::USER_ID, 'tester key unrestricted')['model'];

        static::__assert_equals(
            Api_Tester_Key::accessible_targets_for_user(static::__user()),
            Api_Tester_Key::accessible_targets_for_key($key),
            'a key with no rules narrows nothing'
        );
    }

    // -------------------------------------------------------------------------
    // The intersection
    // -------------------------------------------------------------------------

    public static function test_scoped_key_is_a_strict_subset_of_its_user()
    {
        $user_targets = static::__allowed(Api_Tester_Key::accessible_targets_for_user(static::__user()));
        static::__assert_not_empty($user_targets, 'user 1 reaches at least one v1 endpoint');

        $key = Api_Key_Model::generate(
            self::USER_ID,
            'tester key scoped',
            'live',
            null,
            null,
            '/api/v1/contacts/*'
        )['model'];

        $key_targets = static::__allowed(Api_Tester_Key::accessible_targets_for_key($key));

        static::__assert_not_empty($key_targets, 'the grant reaches the contacts endpoints');
        static::__assert_less_than(
            count($user_targets),
            count($key_targets),
            'the scoped answer is narrower than the user answer'
        );

        foreach ($key_targets as $target) {
            static::__assert_true(
                in_array($target, $user_targets, true),
                $target . ' is admitted by the gates too - scopes subtract only'
            );
        }
    }

    public static function test_scoped_key_admits_exactly_what_the_rules_reach()
    {
        // Asserted against Api_Scopes directly rather than against a hardcoded endpoint
        // list: the template app's endpoints change, the composition rule does not.
        $scopes = '/api/v1/contacts/*';

        $key = Api_Key_Model::generate(
            self::USER_ID,
            'tester key rules check',
            'live',
            null,
            null,
            $scopes
        )['model'];

        $gate_answers = Api_Tester_Key::accessible_targets_for_user(static::__user());
        $key_answers = Api_Tester_Key::accessible_targets_for_key($key);

        foreach (Api_Catalog::get_endpoint_list(false) as $endpoint) {
            $target = $endpoint['class'] . '::' . $endpoint['method'];
            $scopes_reach = Api_Scopes::reaches_route($scopes, (string) $endpoint['pattern']);
            $expected = !empty($gate_answers[$target]) && $scopes_reach;

            static::__assert_equals(
                $expected,
                !empty($key_answers[$target]),
                $target . ' answers gates AND rules'
            );
        }
    }

    public static function test_a_read_only_key_reaches_no_write_endpoint()
    {
        // The console draws its listing from this map, so a read-only key must not be shown
        // an endpoint it would be refused on. Asserted against the endpoint's declared verbs
        // rather than a hardcoded list: the template app's endpoints change, the rule does
        // not.
        $key = Api_Key_Model::generate(
            self::USER_ID,
            'tester key read only',
            'live',
            null,
            null,
            null,
            true
        )['model'];

        $gate_answers = Api_Tester_Key::accessible_targets_for_user(static::__user());
        $key_answers = Api_Tester_Key::accessible_targets_for_key($key);

        $reachable = 0;

        foreach (Api_Catalog::get_endpoint_list(false) as $endpoint) {
            $target = $endpoint['class'] . '::' . $endpoint['method'];
            $has_get = in_array('GET', (array) $endpoint['methods'], true);
            $expected = !empty($gate_answers[$target]) && $has_get;

            static::__assert_equals(
                $expected,
                !empty($key_answers[$target]),
                $target . ' answers gates AND the read-only verb rule'
            );

            if ($expected) {
                $reachable++;
            }
        }

        static::__assert_true($reachable > 0, 'a read-only key still reaches the GET endpoints');
    }

    public static function test_a_read_only_key_composes_with_its_scopes()
    {
        $scopes = '/api/v1/contacts/*';

        $key = Api_Key_Model::generate(
            self::USER_ID,
            'tester key read only scoped',
            'live',
            null,
            null,
            $scopes,
            true
        )['model'];

        $gate_answers = Api_Tester_Key::accessible_targets_for_user(static::__user());
        $key_answers = Api_Tester_Key::accessible_targets_for_key($key);

        foreach (Api_Catalog::get_endpoint_list(false) as $endpoint) {
            $target = $endpoint['class'] . '::' . $endpoint['method'];
            $expected = !empty($gate_answers[$target])
                && Api_Scopes::reaches_route($scopes, (string) $endpoint['pattern'])
                && in_array('GET', (array) $endpoint['methods'], true);

            static::__assert_equals(
                $expected,
                !empty($key_answers[$target]),
                $target . ' answers gates AND scopes AND the verb rule'
            );
        }
    }

    public static function test_current_is_read_only_is_null_with_no_adopted_key()
    {
        Api_Tester_Key::forget();

        static::__assert_null(Api_Tester_Key::current_is_read_only());
    }

    public static function test_current_is_read_only_distinguishes_the_two_kinds_of_key()
    {
        $read_write = Api_Key_Model::generate(self::USER_ID, 'badge read write')['model'];
        $read_only = Api_Key_Model::generate(self::USER_ID, 'badge read only', 'live', null, null, null, true)['model'];

        Api_Tester_Key::adopt($read_write);
        static::__assert_false(Api_Tester_Key::current_is_read_only());

        Api_Tester_Key::adopt($read_only);
        static::__assert_true(Api_Tester_Key::current_is_read_only());

        Api_Tester_Key::forget();
    }

    public static function test_a_key_scoped_to_nothing_reaches_nothing()
    {
        // Deny by default is the whole point of the feature: a scope set naming a resource
        // that does not exist must not degrade to the user's full answer.
        $key = Api_Key_Model::generate(
            self::USER_ID,
            'tester key empty scope',
            'live',
            null,
            null,
            '/api/v1/no_such_resource/*'
        )['model'];

        static::__assert_equals([], static::__allowed(Api_Tester_Key::accessible_targets_for_key($key)));
    }

    public static function test_every_published_target_is_answered()
    {
        // The map is a lookup the console indexes into, so a missing key would silently read
        // as "not accessible" and hide an endpoint rather than failing.
        $key = Api_Key_Model::generate(
            self::USER_ID,
            'tester key coverage',
            'live',
            null,
            null,
            '/api/v1/contacts/*'
        )['model'];

        $answers = Api_Tester_Key::accessible_targets_for_key($key);

        foreach (static::__all_targets() as $target) {
            static::__assert_array_has_key($target, $answers);
        }
    }

    // -------------------------------------------------------------------------
    // current_is_scoped() - the console's three-valued badge
    // -------------------------------------------------------------------------

    public static function test_current_is_scoped_is_null_with_no_adopted_key()
    {
        Api_Tester_Key::forget();

        static::__assert_null(Api_Tester_Key::current_is_scoped());
    }

    public static function test_current_is_scoped_distinguishes_scoped_from_unrestricted()
    {
        $open = Api_Key_Model::generate(self::USER_ID, 'badge unrestricted')['model'];
        Api_Tester_Key::adopt($open);
        static::__assert_false(Api_Tester_Key::current_is_scoped(), 'a rule-free key reads as unrestricted');

        $scoped = Api_Key_Model::generate(
            self::USER_ID,
            'badge scoped',
            'live',
            null,
            null,
            '/api/v1/contacts'
        )['model'];
        Api_Tester_Key::adopt($scoped);
        static::__assert_true(Api_Tester_Key::current_is_scoped(), 'a key carrying rules reads as scoped');

        Api_Tester_Key::forget();
    }
}
