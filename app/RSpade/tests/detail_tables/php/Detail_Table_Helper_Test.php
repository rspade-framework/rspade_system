<?php

namespace App\RSpade\Tests\DetailTables\Php;

use App\RSpade\Core\Database\DetailTables\Rsx_Detail_Table;
use App\RSpade\Core\Database\SqlQueryTransformer;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * DT-01..DT-05: Rsx_Detail_Table emits the correct CTI detail-table DDL (surrogate id +
 * UNIQUE FK + CASCADE, nullable-by-default columns, audit columns) and composes with the
 * migration-time SqlQueryTransformer. Pure logic - no database.
 */
class Detail_Table_Helper_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private static function __person_columns(): array
    {
        return [
            ['name' => 'first_name', 'type' => 'VARCHAR(255)'],
            ['name' => 'last_name', 'type' => 'VARCHAR(255)'],
            ['name' => 'date_of_birth', 'type' => 'DATE'],
        ];
    }

    // DT-01
    public static function test_surrogate_id_and_unique_fk()
    {
        $sql = Rsx_Detail_Table::build_sql('party_person_details', 'parties', static::__person_columns());

        static::__assert_contains('id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY', $sql, 'detail table keeps a surrogate id PK');
        static::__assert_contains('party_id BIGINT NOT NULL', $sql, 'FK column derived from parent table');
        static::__assert_contains('UNIQUE KEY uk_party_person_details_party_id (party_id)', $sql, '1:1 enforced by UNIQUE on the FK');
        static::__assert_contains('FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE', $sql, 'cascade ties detail lifecycle to base');
    }

    // DT-02
    public static function test_columns_nullable_by_default()
    {
        $sql = Rsx_Detail_Table::build_sql('d', 'parties', [
            ['name' => 'first_name', 'type' => 'VARCHAR(255)'],
            ['name' => 'legal_name', 'type' => 'VARCHAR(255)', 'nullable' => false],
        ]);

        static::__assert_contains('first_name VARCHAR(255) NULL', $sql, 'detail columns default NULL so shells auto-create');
        static::__assert_contains('legal_name VARCHAR(255) NOT NULL', $sql, 'explicit nullable=false honored');
    }

    // DT-03
    public static function test_audit_and_softdelete_columns()
    {
        $sql = Rsx_Detail_Table::build_sql('d', 'parties', static::__person_columns());
        static::__assert_contains('created_at TIMESTAMP(3)', $sql, 'audit created_at present');
        static::__assert_contains('updated_at TIMESTAMP(3)', $sql, 'audit updated_at present');
        static::__assert_contains('created_by_id BIGINT', $sql, 'audit created_by_id present');
        static::__assert_contains('created_by_type BIGINT', $sql, 'audit created_by_type present');
        static::__assert_contains('updated_by_id BIGINT', $sql, 'audit updated_by_id present');
        static::__assert_contains('updated_by_type BIGINT', $sql, 'audit updated_by_type present');
        static::__assert_false(str_contains($sql, 'deleted_at'), 'no soft-delete columns by default');

        $soft = Rsx_Detail_Table::build_sql('d', 'parties', static::__person_columns(), null, true);
        static::__assert_contains('deleted_at TIMESTAMP(3)', $soft, 'soft-delete columns added on request');
        static::__assert_contains('deleted_by_id BIGINT', $soft, 'soft-delete deleted_by pair added on request');
        static::__assert_contains('deleted_by_type BIGINT', $soft, 'soft-delete deleted_by pair added on request');
    }

    // DT-04
    public static function test_parent_key_derivation_and_override()
    {
        static::__assert_equals('party_id', Rsx_Detail_Table::parent_key_for('parties'), 'parties -> party_id');
        static::__assert_equals('company_id', Rsx_Detail_Table::parent_key_for('companies'), 'companies -> company_id');

        $sql = Rsx_Detail_Table::build_sql('d', 'parties', static::__person_columns(), 'owner_id');
        static::__assert_contains('owner_id BIGINT NOT NULL', $sql, 'explicit parent_key override used');
        static::__assert_contains('REFERENCES parties(id)', $sql, 'FK still targets parent table id');
    }

    // DT-05
    public static function test_emitted_ddl_composes_with_transformer()
    {
        $sql = Rsx_Detail_Table::build_sql('d', 'parties', [
            ['name' => 'rank', 'type' => 'INT'],
            ['name' => 'note', 'type' => 'VARCHAR(255)'],
        ]);

        SqlQueryTransformer::enable();
        try {
            $transformed = SqlQueryTransformer::transform($sql);
        } finally {
            SqlQueryTransformer::disable();
        }

        static::__assert_contains('rank BIGINT', $transformed, 'INT normalized to BIGINT');
        static::__assert_false((bool) preg_match('/\brank INT\b/', $transformed), 'no bare INT remains');
        static::__assert_contains('CHARACTER SET utf8mb4', $transformed, 'VARCHAR gets utf8mb4 charset');
    }
}
