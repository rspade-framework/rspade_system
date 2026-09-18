<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Health\Php;

use App\RSpade\Core\Database\Schema_Contract;
use App\RSpade\Core\Database\Schema_Contract_Health_Checks;
use App\RSpade\Core\Health\Health_Check_Runner;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The framework's contract on application-owned tables, and the rsx:health rows over it.
 *
 * Two halves. The first runs the REAL check against the live test database and asserts
 * that it is clean - that is the contract's own regression test, and the only thing
 * that keeps the map honest as the framework's reads move. The second drives each row
 * builder with an INJECTED shape: a database that is missing users.login_user_id or
 * uk_login_users_email is not one any test may create, so the shape is handed in
 * instead, which is exactly why the builders take it as a parameter.
 */
class Schema_Contract_Test extends Rsx_Test_Abstract
{
    // -------------------------------------------------------------------------
    // The live schema
    // -------------------------------------------------------------------------

    public static function test_the_contract_resolves_against_this_database_with_no_failures()
    {
        $rows = Health_Check_Runner::run_one(
            Schema_Contract_Health_Checks::class,
            'schema_contract',
            'Schema Contract'
        );

        static::__assert_not_empty($rows, 'the check reports a row per contracted table plus three summaries');

        $failures = [];
        foreach ($rows as $row) {
            if ($row['status'] === 'FAIL') {
                $failures[] = $row['label'] . ': ' . $row['detail'];
            }
        }

        static::__assert_equals(
            [],
            $failures,
            'the schema this framework ships satisfies its own contract'
        );
    }

    public static function test_the_check_reports_one_row_per_table_plus_the_three_summaries()
    {
        $rows = Health_Check_Runner::run_one(
            Schema_Contract_Health_Checks::class,
            'schema_contract',
            'Schema Contract'
        );

        $labels = array_column($rows, 'label');

        foreach (array_keys(Schema_Contract::tables()) as $table) {
            static::__assert_true(
                in_array('schema: ' . $table, $labels, true),
                "schema: {$table} is reported"
            );
        }

        foreach (['schema: foreign keys', 'schema: rows', 'schema: semantics'] as $summary) {
            static::__assert_true(in_array($summary, $labels, true), "{$summary} is reported");
        }

        static::__assert_equals(
            count(Schema_Contract::tables()) + 3,
            count($rows),
            'one row per contracted table and nothing else'
        );
    }

    public static function test_the_check_applies_in_every_mode()
    {
        $modes = null;
        $found = false;

        foreach (Health_Check_Runner::discover() as $check) {
            if ($check['fqcn'] === Schema_Contract_Health_Checks::class) {
                $found = true;
                $modes = $check['modes'];
            }
        }

        static::__assert_true($found, 'the check is discovered through the manifest');
        static::__assert_null(
            $modes,
            'the question has the same answer and the same consequence on every box, so no mode axis'
        );
    }

    // -------------------------------------------------------------------------
    // The table builder, against an injected shape
    // -------------------------------------------------------------------------

    public static function test_a_missing_required_column_fails_naming_the_framework_consumer()
    {
        $contract = Schema_Contract::tables()['users'];

        $present = array_keys($contract['columns']);
        $present = array_values(array_diff($present, ['login_user_id']));

        $row = Schema_Contract_Health_Checks::_table_row(
            'users',
            $contract,
            $present,
            [['login_user_id', 'site_id'], ['invite_code']]
        );

        static::__assert_equals('FAIL', $row['status'], 'the framework reads the column by name');
        static::__assert_contains('users.login_user_id', $row['detail'], 'the finding names the column');
        static::__assert_contains(
            'Session::get_user()',
            $row['detail'],
            'the finding names the framework code that will fail, not the application'
        );
        static::__assert_contains(
            'framework requirement',
            $row['remediation'],
            'the remediation says whose column this is'
        );
    }

    public static function test_a_missing_table_fails_and_names_its_creating_migration()
    {
        $contract = Schema_Contract::tables()['login_users'];

        $row = Schema_Contract_Health_Checks::_table_row('login_users', $contract, null, []);

        static::__assert_equals('FAIL', $row['status'], 'a modelled table that does not exist is structural');
        static::__assert_contains('does not exist', $row['detail'], 'the finding says what is wrong');
        static::__assert_contains(
            '2025_11_04_051746_create_login_users_table',
            $row['remediation'],
            'the remediation names the framework migration that created it'
        );
    }

    public static function test_a_missing_unique_index_fails()
    {
        $contract = Schema_Contract::tables()['login_users'];

        $row = Schema_Contract_Health_Checks::_table_row(
            'login_users',
            $contract,
            array_keys($contract['columns']),
            [] // every column present, no unique index at all
        );

        static::__assert_equals('FAIL', $row['status'], 'sign-in resolves on the unique email');
        static::__assert_contains('UNIQUE(email)', $row['detail'], 'the finding names the index columns');
        static::__assert_contains(
            'uk_login_users_email',
            $row['detail'],
            'the conventional index name is carried so an operator can recreate it'
        );
        static::__assert_contains(
            'non-deterministic',
            $row['detail'],
            'the finding says what losing uniqueness silently does'
        );
    }

    public static function test_a_unique_index_matches_by_column_set_not_by_name()
    {
        $contract = Schema_Contract::tables()['users'];

        // The contract asks for UNIQUE(login_user_id, site_id) and UNIQUE(invite_code);
        // the index NAME is never part of the comparison, and column ORDER is not either.
        $row = Schema_Contract_Health_Checks::_table_row(
            'users',
            $contract,
            array_keys($contract['columns']),
            [['site_id', 'login_user_id'], ['invite_code']]
        );

        static::__assert_equals('OK', $row['status'], 'the same column set in another order is the same constraint');
    }

    // -------------------------------------------------------------------------
    // Foreign keys
    // -------------------------------------------------------------------------

    public static function test_a_missing_foreign_key_fails_naming_both_sides()
    {
        $contract = Schema_Contract::foreign_keys();

        $present = [];
        foreach ($contract as $fk) {
            if ($fk['table'] === '_sso_identities') {
                continue;
            }
            $present[] = $fk['table'] . '.' . $fk['column'] . '->' . $fk['references'] . '.' . $fk['referenced_column'];
        }

        $row = Schema_Contract_Health_Checks::_foreign_keys_row($contract, $present);

        static::__assert_equals('FAIL', $row['status'], 'a framework table pointing into an application table is the contract at its strongest');
        static::__assert_contains(
            '_sso_identities.login_user_id->login_users.id',
            $row['detail'],
            'the finding names both sides of the key'
        );
    }

    // -------------------------------------------------------------------------
    // Rows
    // -------------------------------------------------------------------------

    public static function test_the_rows_builder_fails_when_the_sessionless_site_is_absent()
    {
        $contract = Schema_Contract::rows();

        $found = [];
        foreach ($contract as $row) {
            $found[$row['label']] = ($row['label'] !== 'sites id 0');
        }

        $result = Schema_Contract_Health_Checks::_rows_row($contract, $found);

        static::__assert_equals('FAIL', $result['status'], 'site 0 is the sessionless site and is undeletable');
        static::__assert_contains('sites id 0', $result['detail'], 'the finding names the row');
        static::__assert_contains(
            'sessionless site',
            $result['detail'],
            'the finding says what the row is for'
        );
    }

    public static function test_a_row_whose_guard_table_is_empty_is_not_asked_about()
    {
        // A database with no login_users at all is a fresh install waiting to be seeded,
        // not a broken one - so the initial-user pair contributes no row and no finding.
        $contract = Schema_Contract::rows();

        $found = [
            'sites id 0' => true,
            'sites id ' . (int) config('multi-tenant.default_site_id', 1) . ' (multi-tenant.default_site_id)' => true,
        ];

        $result = Schema_Contract_Health_Checks::_rows_row($contract, $found);

        static::__assert_equals('OK', $result['status'], 'an unseeded database is not a contract violation');
        static::__assert_contains('2 required row(s)', $result['detail'], 'only the rows that were asked are counted');
    }

    // -------------------------------------------------------------------------
    // Semantics
    // -------------------------------------------------------------------------

    public static function test_an_out_of_range_dark_mode_warns_and_never_fails()
    {
        $probe = null;
        foreach (Schema_Contract::probes() as $candidate) {
            if ($candidate['key'] === 'dark_mode_range') {
                $probe = $candidate;
            }
        }

        static::__assert_not_null($probe, 'the dark_mode range probe is declared');

        $row = Schema_Contract_Health_Checks::_semantics_row([
            [
                'label' => $probe['label'],
                'finding' => '3 row(s) where ' . $probe['predicate'] . ' - ' . $probe['why'],
                'remediation' => $probe['remediation'],
            ],
        ]);

        static::__assert_equals(
            'WARN',
            $row['status'],
            'a coerced value is not a shape the framework cannot run against, and the exit code gates deploys'
        );
        static::__assert_contains('login_users.dark_mode', $row['detail'], 'the finding names the column');
        static::__assert_contains('AUTO', $row['detail'], 'the finding says what the framework silently does instead');
        static::__assert_contains('UPDATE login_users', $row['remediation'], 'the remediation is runnable');
    }

    public static function test_clean_probes_are_ok()
    {
        $row = Schema_Contract_Health_Checks::_semantics_row([
            ['label' => 'a', 'finding' => null, 'remediation' => 'x'],
            ['label' => 'b', 'finding' => null, 'remediation' => 'y'],
        ]);

        static::__assert_equals('OK', $row['status'], 'nothing found is nothing reported');
        static::__assert_contains('2 semantic probe(s)', $row['detail'], 'the count is stated');
    }

    public static function test_the_role_enum_probe_reads_the_live_declaration()
    {
        // The one PHP-side probe: the roles the whole permission layer resolves against.
        static::__assert_null(
            Schema_Contract_Health_Checks::_role_enum_finding(),
            "User_Model::\$enums['role_id'] is declared with permissions and can_admin_roles on every entry"
        );
    }

    // -------------------------------------------------------------------------
    // The map itself
    // -------------------------------------------------------------------------

    public static function test_every_contracted_column_names_the_framework_code_that_reads_it()
    {
        foreach (Schema_Contract::tables() as $table => $contract) {
            static::__assert_not_empty(
                $contract['columns'] ?? [],
                "{$table} declares at least one required column"
            );

            foreach ($contract['columns'] as $column => $spec) {
                static::__assert_not_empty(
                    $spec['where'] ?? '',
                    "{$table}.{$column} names the framework consumer that reads or writes it"
                );
            }
        }
    }

    public static function test_the_audit_pairs_and_timestamps_are_deliberately_not_contracted()
    {
        // migrate:normalize_schema adds them to every table on every migrate, so a
        // finding here could only ever duplicate what normalization already repairs.
        $excluded = [
            'created_at', 'updated_at',
            'created_by_id', 'created_by_type',
            'updated_by_id', 'updated_by_type',
            'deleted_by_id', 'deleted_by_type',
        ];

        foreach (Schema_Contract::tables() as $table => $contract) {
            foreach ($excluded as $column) {
                static::__assert_false(
                    array_key_exists($column, $contract['columns']),
                    "{$table}.{$column} is normalization's job, not the contract's"
                );
            }
        }
    }

    public static function test_deleted_at_is_contracted_on_exactly_the_soft_deleting_tables()
    {
        // normalize_schema never adds deleted_at, and losing it fatals every query the
        // model issues - so it is the one column of that family the contract does carry.
        $soft_deleting = ['users', 'login_users', 'portal_users', 'sites'];

        foreach (Schema_Contract::tables() as $table => $contract) {
            $declared = array_key_exists('deleted_at', $contract['columns']);

            static::__assert_equals(
                in_array($table, $soft_deleting, true),
                $declared,
                "{$table} declares deleted_at only if it soft-deletes"
            );
        }
    }

    public static function test_every_probe_declares_a_known_kind_and_a_remediation()
    {
        foreach (Schema_Contract::probes() as $probe) {
            static::__assert_true(
                in_array($probe['kind'], ['enum', 'count'], true),
                $probe['key'] . ' declares a kind the check knows how to run'
            );
            static::__assert_not_empty($probe['remediation'], $probe['key'] . ' names its remedy');

            if ($probe['kind'] === 'count') {
                static::__assert_not_empty($probe['table'], $probe['key'] . ' names the table it counts over');
                static::__assert_not_empty($probe['predicate'], $probe['key'] . ' names the predicate it counts');
            }
        }
    }
}
