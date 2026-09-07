<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\ModelFetch\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Database\Orm_Controller;
use App\RSpade\Core\Database\Orm_Fetch_Preload;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Response\Rsx_Response_Abstract;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The ORM batch fetch endpoint: its wire contract, its refusals, and the IN-clause
 * preload that makes N ids cost one query.
 *
 * CONTRACT UNDER TEST
 *   in:  {model, ids: []}
 *   out: {records: {"<id>": record}} - found ids only
 *
 * Per-id ABSENCE is the single answer for every unresolvable id (missing row, model
 * refusal, gate denial). Batch-level problems - a malformed request, an unknown model -
 * are still coded errors, because they are about the REQUEST, not about a record.
 *
 * Behavior of record: php artisan rsx:man model_fetch
 */
class Orm_Batch_Fetch_Test extends Rsx_Test_Abstract
{
    // =========================================================================
    // REQUEST VALIDATION
    // =========================================================================

    /**
     * A request naming no model is a validation error, not an empty result.
     */
    public static function test_missing_model_is_a_validation_error()
    {
        $result = Orm_Controller::fetch(static::__ajax_request(), ['ids' => [1]]);

        static::__assert_instance_of(Rsx_Response_Abstract::class, $result);
        static::__assert_equals(Ajax::ERROR_VALIDATION, $result->get_type());
        static::__assert_equals('Model name is required', $result->get_reason());
    }

    /**
     * Absent, non-array and empty id sets are all the same malformed request. NONE of
     * them may be read as "fetch nothing, successfully".
     */
    public static function test_missing_or_empty_ids_is_a_validation_error()
    {
        $shapes = [
            'absent' => ['model' => 'Task_Model'],
            'empty' => ['model' => 'Task_Model', 'ids' => []],
            'scalar' => ['model' => 'Task_Model', 'ids' => 7],
        ];

        foreach ($shapes as $label => $params) {
            $result = Orm_Controller::fetch(static::__ajax_request(), $params);

            static::__assert_instance_of(Rsx_Response_Abstract::class, $result, "ids {$label}");
            static::__assert_equals(Ajax::ERROR_VALIDATION, $result->get_type(), "ids {$label}");
            static::__assert_equals('ids parameter is required', $result->get_reason(), "ids {$label}");
        }
    }

    /**
     * One non-numeric id fails the WHOLE request. Silently dropping it would answer a
     * question nobody asked.
     */
    public static function test_non_numeric_id_is_a_validation_error()
    {
        $result = Orm_Controller::fetch(
            static::__ajax_request(),
            ['model' => 'Task_Model', 'ids' => [1, 'not-an-id']]
        );

        static::__assert_instance_of(Rsx_Response_Abstract::class, $result);
        static::__assert_equals(Ajax::ERROR_VALIDATION, $result->get_type());
        static::__assert_contains('numeric', $result->get_reason());
    }

    /**
     * Past the cap the request is REFUSED, naming the limit and its config key - never
     * truncated, because a partial records map is indistinguishable from missing rows.
     */
    public static function test_over_cap_request_is_refused_not_truncated()
    {
        $cap = (int) config('rsx.model_fetch.batch_max_ids');

        $ids = [];
        for ($i = 1; $i <= $cap + 1; $i++) {
            $ids[] = $i;
        }

        $result = Orm_Controller::fetch(
            static::__ajax_request(),
            ['model' => 'Task_Model', 'ids' => $ids]
        );

        static::__assert_instance_of(Rsx_Response_Abstract::class, $result);
        static::__assert_equals(Ajax::ERROR_VALIDATION, $result->get_type());
        static::__assert_contains('rsx.model_fetch.batch_max_ids', $result->get_reason());
        static::__assert_contains((string) $cap, $result->get_reason());
    }

    /**
     * The cap counts DISTINCT ids: a repeated id is one lookup, so it must not consume
     * budget twice.
     */
    public static function test_cap_counts_distinct_ids()
    {
        $cap = (int) config('rsx.model_fetch.batch_max_ids');

        // cap + 1 entries, but only 2 distinct ids.
        $ids = [];
        for ($i = 0; $i <= $cap; $i++) {
            $ids[] = ($i % 2) + 1;
        }

        $result = Orm_Controller::fetch(
            static::__ajax_request(),
            ['model' => 'Task_Model', 'ids' => $ids]
        );

        static::__assert_true(is_array($result), 'a duplicate-heavy request stays under the cap');
        static::__assert_array_has_key('records', $result);
    }

    /**
     * An unknown model gets the one generic not-found message - the anti-enumeration
     * answer, unchanged by batching.
     */
    public static function test_unknown_model_is_generic_not_found()
    {
        $result = Orm_Controller::fetch(
            static::__ajax_request(),
            ['model' => 'No_Such_Model_Anywhere', 'ids' => [1]]
        );

        static::__assert_instance_of(Rsx_Response_Abstract::class, $result);
        static::__assert_equals(Ajax::ERROR_NOT_FOUND, $result->get_type());
        static::__assert_contains('not found', $result->get_reason());
    }

    // =========================================================================
    // RECORDS MAP
    // =========================================================================

    /**
     * A mixed batch answers per id: real ids present and keyed by id, absent ids simply
     * missing, and no per-id reason anywhere in the response.
     */
    public static function test_mixed_batch_returns_exactly_the_resolved_ids()
    {
        $ids = static::__make_tasks(2);

        $result = Orm_Controller::fetch(
            static::__ajax_request(),
            ['model' => 'Task_Model', 'ids' => [$ids[0], 999999999, $ids[1]]]
        );

        // PHP coerces a numeric-string array key back to int, so the keys read as ints
        // here. On the wire they are object property names, and because record ids are
        // always >= 1 the map never degenerates into a JSON array.
        static::__assert_equals([$ids[0], $ids[1]], array_keys($result['records']));

        foreach ($ids as $id) {
            $record = $result['records'][(string) $id];
            static::__assert_equals($id, (int) (is_array($record) ? $record['id'] : $record->id));
        }

        static::__reset_session();
    }

    /**
     * A batch of nothing but nonexistent ids succeeds with an EMPTY map. That is the same
     * response a denial produces (Auth_Gates_Seam_Test asserts the denial half), which is
     * the whole point: absence carries no reason.
     */
    public static function test_all_missing_ids_yield_an_empty_records_map()
    {
        // Signed in on purpose: an empty map has to be the answer to a PERMITTED request
        // for absent rows, not the denial answer wearing the same clothes.
        static::__sign_in();

        $result = Orm_Controller::fetch(
            static::__ajax_request(),
            ['model' => 'Task_Model', 'ids' => [999999998, 999999999]]
        );

        static::__assert_equals([], $result['records']);

        static::__reset_session();
    }

    /**
     * An array return without __MODEL still throws - the JavaScript ORM cannot hydrate a
     * class out of it, and a silently unhydrated plain object would surface as a missing
     * method far from the cause. (Model_Fetch_Fixture_Model returns exactly that shape.)
     */
    public static function test_array_return_without_model_marker_throws()
    {
        $user_id = static::__first_user_id();
        if ($user_id === null) {
            static::__skip('no User_Model record in the test database');

            return;
        }

        // The fixture surface is gated on is_logged_in; a denial would return an empty
        // map instead of reaching the model at all. Gates evaluate live, so signing in
        // is the whole setup.
        static::__acting_as_user($user_id);

        $exception = static::__assert_throws(
            \RuntimeException::class,
            function () {
                Orm_Controller::fetch(
                    static::__ajax_request(),
                    ['model' => 'Model_Fetch_Fixture_Model', 'ids' => [1]]
                );
            }
        );

        static::__assert_contains('__MODEL', $exception->getMessage());

        static::__reset_session();
    }

    // =========================================================================
    // PRELOAD (the reason batching is worth anything)
    // =========================================================================

    /**
     * N ids cost ONE query against the model's table, not N.
     *
     * Counted precisely: every statement issued against `tasks` during the call. The
     * preload's `whereIn` is the one allowed; a per-id `where id = ?` lookup would push
     * the count up by one per id, which is exactly the regression this guards. Queries
     * against OTHER tables (whatever the model's fetch body augments with) are not this
     * test's business and are not counted.
     */
    public static function test_batch_preloads_all_ids_in_one_query()
    {
        $ids = static::__make_tasks(3);

        $queries = static::__capture_queries(function () use ($ids) {
            return Orm_Controller::fetch(
                static::__ajax_request(),
                ['model' => 'Task_Model', 'ids' => $ids]
            );
        });

        $task_queries = static::__queries_against('tasks', $queries);

        static::__assert_count(
            1,
            $task_queries,
            'expected exactly one tasks query for ' . count($ids) . ' ids, got: ' . implode(' | ', $task_queries)
        );
        static::__assert_contains(' in (', $task_queries[0]);

        static::__reset_session();
    }

    /**
     * The plural relationship branch preloads too: its related ids go through the same
     * one-query-then-fetch-each path, and the per-id lookups are eliminated.
     *
     * Two statements against `contacts` is the whole cost of the branch, whatever the
     * related-record count: the id pluck, then the IN-clause preload. Every subsequent
     * Contact_Model::fetch() is served from it - which holds only because that fetch body
     * looks the record up under the model's DEFAULT scopes (MODEL-FETCH-TRASHED-01). A
     * scope-stripped body misses the preload and puts one `where id = ?` per record back.
     */
    public static function test_relationship_plural_branch_preloads_related_ids()
    {
        $client_id = static::__make_client_with_contacts(2);

        $queries = static::__capture_queries(function () use ($client_id) {
            return Orm_Controller::fetch_relationship(static::__ajax_request(), [
                'model' => 'Client_Model',
                'id' => $client_id,
                'relationship' => 'contacts',
            ]);
        });

        $contact_queries = static::__queries_against('contacts', $queries);

        $preloads = 0;
        foreach ($contact_queries as $sql) {
            if (str_contains($sql, ' in (')) {
                $preloads++;
            }
        }

        static::__assert_equals(1, $preloads, 'expected one contacts IN-clause preload');
        static::__assert_count(
            2,
            $contact_queries,
            'expected the id pluck plus the preload and nothing per record, got: ' . implode(' | ', $contact_queries)
        );

        static::__reset_session();
    }

    /**
     * The preload never outlives the request that built it. If it did, a later caller
     * with a different identity could be served a row it was never allowed to load.
     */
    public static function test_preload_is_cleared_after_the_endpoint_returns()
    {
        $ids = static::__make_tasks(1);

        Orm_Controller::fetch(static::__ajax_request(), ['model' => 'Task_Model', 'ids' => $ids]);

        static::__assert_null(Orm_Fetch_Preload::get(static::__task_class(), $ids[0]));

        static::__reset_session();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * A request object for the ORM endpoints (which read nothing off it).
     */
    private static function __ajax_request(): Request
    {
        return Request::create('/_ajax/Orm_Controller/fetch', 'POST');
    }

    /**
     * The tenant these tests act as. Its rows are created per test and rolled back with
     * the test's transaction, so the endpoint's answers depend on nothing but this test.
     */
    private const SITE_ID = 1;

    /**
     * Sign in as the seeded user and act as the fixture tenant.
     *
     * Every model fetch surface in the template app is gated on is_logged_in, so a test
     * that only set a site would get an empty records map (the denial answer) and prove
     * nothing. The gate engine memoizes per request and a test process is many
     * "requests", so the identity change has to invalidate that snapshot the way a
     * request boundary does.
     */
    private static function __sign_in(): void
    {
        $user_id = static::__first_user_id();

        if ($user_id === null) {
            throw new \RuntimeException('the test database has no User_Model record to sign in as');
        }

        static::__acting_as_user($user_id);
        Session::set_site_id(self::SITE_ID);
    }

    /**
     * Create N tasks in the test tenant and return their ids, signed in.
     *
     * @return array
     */
    private static function __make_tasks(int $count): array
    {
        static::__sign_in();

        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $task = new Task_Model();
            $task->site_id = self::SITE_ID;
            $task->title = "Model fetch batch fixture {$i}";
            $task->save();

            $ids[] = (int) $task->id;
        }

        return $ids;
    }

    /**
     * Create a client with N contacts in the test tenant and return the client id.
     */
    private static function __make_client_with_contacts(int $contact_count): int
    {
        static::__sign_in();

        $client = new Client_Model();
        $client->site_id = self::SITE_ID;
        $client->name = 'Model fetch batch fixture client';
        $client->save();

        for ($i = 1; $i <= $contact_count; $i++) {
            $contact = new Contact_Model();
            $contact->site_id = self::SITE_ID;
            $contact->client_id = $client->id;
            $contact->first_name = 'Fixture';
            $contact->last_name = "Contact {$i}";
            $contact->save();
        }

        return (int) $client->id;
    }

    /**
     * The Task_Model FQCN, resolved from an instance.
     *
     * Template-app models are not `use`d here (their namespace is manifest-generated and
     * a hardcoded \Rsx\ FQCN is forbidden), so `Task_Model::class` inside this namespace
     * would resolve to a nonexistent local name. The autoloader resolves the STATIC CALL
     * `Task_Model::find()` by simple name; only the ::class constant needs this.
     */
    private static function __task_class(): string
    {
        return get_class(new Task_Model());
    }

    /**
     * The lowest User_Model id in the test database, or null when there is none.
     */
    private static function __first_user_id(): ?int
    {
        $user = User_Model::without_site_scope(function () {
            return User_Model::orderBy('id')->first();
        });

        if ($user) {
            return (int) $user->id;
        }

        // The migrated baseline ships NO user since the admin seed became conditional on
        // credentials being configured (first-run setup, 2026-08-19) - a fresh install has
        // nobody until the first-user screen runs. A test that needs an identity to sign in
        // as therefore provides its own. This class is transactional, so the row rolls back.
        static::__acting_as_site(self::SITE_ID);

        // A login identity is what is_logged_in() checks; a User_Model with no
        // login_user_id is a membership with nobody behind it and the gate denies.
        $login_user = new Login_User_Model();
        $login_user->email = 'batch_fetch_' . uniqid() . '@example.com';
        $login_user->password = Login_User_Model::hash_password('secret-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->site_id = self::SITE_ID;
        $user->login_user_id = (int) $login_user->id;
        $user->first_name = 'Batch';
        $user->last_name = 'Fetcher';
        $user->role_id = User_Model::ROLE_USER;
        $user->is_enabled = true;
        $user->save();

        return (int) $user->id;
    }

    /**
     * Run $fn with the query log on, and return the SQL of every statement it issued.
     *
     * @return array
     */
    private static function __capture_queries(callable $fn): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $fn();
        } finally {
            DB::disableQueryLog();
        }

        $sql = array_column(DB::getQueryLog(), 'query');
        DB::flushQueryLog();

        return $sql;
    }

    /**
     * The captured statements that touch one table.
     *
     * @return array
     */
    private static function __queries_against(string $table, array $queries): array
    {
        $matched = [];

        foreach ($queries as $sql) {
            if (str_contains($sql, "`{$table}`")) {
                $matched[] = $sql;
            }
        }

        return $matched;
    }
}
