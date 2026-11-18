<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Migrate\Php;

use RuntimeException;
use App\RSpade\Core\Database\SqlQueryTransformer;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * THE MIGRATION DDL REWRITE (SqlQueryTransformer), as rsx:man model_normalization states it.
 *
 * While `migrate` runs, every CREATE TABLE / ALTER TABLE handed to DB::statement() is
 * rewritten before MySQL sees it: INT / INTEGER / MEDIUMINT / SMALLINT / TINYINT(n>1) become
 * BIGINT with UNSIGNED dropped, TINYINT(1) (a boolean) is kept, FLOAT / REAL become DOUBLE,
 * TEXT / MEDIUMTEXT / TINYTEXT become LONGTEXT, CHAR(n) becomes VARCHAR(n), TIMESTAMP,
 * DATETIME and CURRENT_TIMESTAMP gain millisecond precision, and every string column is
 * utf8mb4 / utf8mb4_unicode_ci. ENUM, SET, YEAR and TIME are refused outright.
 *
 * Pure string transformation: the transformer is switched on for each call and off again,
 * and no statement reaches a database.
 */
class Sql_Query_Transformer_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const UTF8 = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    private static function __transform(string $query): string
    {
        $was_enabled = SqlQueryTransformer::is_enabled();
        SqlQueryTransformer::enable();

        try {
            return SqlQueryTransformer::transform($query);
        } finally {
            if (!$was_enabled) {
                SqlQueryTransformer::disable();
            }
        }
    }

    public static function test_a_disabled_transformer_changes_nothing()
    {
        $was_enabled = SqlQueryTransformer::is_enabled();
        SqlQueryTransformer::disable();

        try {
            $query = "CREATE TABLE t (a INT, b ENUM('x','y'))";
            static::__assert_equals($query, SqlQueryTransformer::transform($query), 'outside a migration run the query passes through, forbidden type and all');
        } finally {
            if ($was_enabled) {
                SqlQueryTransformer::enable();
            }
        }
    }

    public static function test_only_create_and_alter_table_are_rewritten()
    {
        foreach ([
            'SELECT CAST(x AS INT) FROM t',
            'INSERT INTO t (a) VALUES (1)',
            'DROP TABLE t',
            'UPDATE t SET a = 1',
        ] as $query) {
            static::__assert_equals($query, static::__transform($query), "not DDL the transformer owns: {$query}");
        }
    }

    public static function test_the_manual_integer_example_is_reproduced_exactly()
    {
        static::__assert_equals(
            'CREATE TABLE posts (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT, views BIGINT, is_published TINYINT(1), FOREIGN KEY (user_id) REFERENCES users(id))',
            static::__transform('CREATE TABLE posts (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, views INT UNSIGNED, is_published TINYINT(1), FOREIGN KEY (user_id) REFERENCES users(id))')
        );
    }

    public static function test_every_integer_width_becomes_signed_bigint_except_a_boolean()
    {
        static::__assert_equals(
            'CREATE TABLE t (a BIGINT, b BIGINT, c BIGINT, d BIGINT, e BIGINT, f BIGINT, g TINYINT(1) NOT NULL DEFAULT 0)',
            static::__transform('CREATE TABLE t (a INT(11), b SMALLINT, c MEDIUMINT, d INTEGER, e TINYINT(4), f BIGINT UNSIGNED, g TINYINT(1) NOT NULL DEFAULT 0)')
        );

        static::__assert_equals(
            'ALTER TABLE t ADD COLUMN n BIGINT NULL',
            static::__transform('ALTER TABLE t ADD COLUMN n INT NULL'),
            'ALTER TABLE is rewritten the same way'
        );
    }

    public static function test_float_and_real_become_double_and_decimal_is_kept()
    {
        static::__assert_equals(
            'CREATE TABLE t (a DOUBLE, b DOUBLE, c DOUBLE, d DECIMAL(10,2))',
            static::__transform('CREATE TABLE t (a FLOAT, b FLOAT(10,2), c REAL, d DECIMAL(10,2))')
        );
    }

    public static function test_an_identifier_beginning_with_real_is_not_a_type()
    {
        // `realm_id` once became `DOUBLEm_id`: the type match needs a word boundary on both ends.
        static::__assert_equals(
            'CREATE TABLE t (realm_id BIGINT, realtime_flag TINYINT(1), real_name VARCHAR(20) ' . self::UTF8 . ')',
            static::__transform('CREATE TABLE t (realm_id BIGINT, realtime_flag TINYINT(1), real_name VARCHAR(20))')
        );
    }

    public static function test_text_widens_to_longtext_and_char_becomes_varchar_all_utf8mb4()
    {
        $u = self::UTF8;

        static::__assert_equals(
            "CREATE TABLE t (a LONGTEXT {$u}, b LONGTEXT {$u}, c LONGTEXT {$u}, d VARCHAR(10) {$u}, e VARCHAR(255) {$u}, f LONGTEXT {$u})",
            static::__transform('CREATE TABLE t (a TEXT, b MEDIUMTEXT, c TINYTEXT, d CHAR(10), e VARCHAR(255), f LONGTEXT)')
        );
    }

    public static function test_temporal_types_gain_millisecond_precision()
    {
        static::__assert_equals(
            'CREATE TABLE t (a TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3), b DATETIME(3), c TIMESTAMP(6), d DATETIME(3), e DATE)',
            static::__transform('CREATE TABLE t (a TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, b DATETIME, c TIMESTAMP(6), d DATETIME(3), e DATE)'),
            'a stated precision is kept; DATE is untouched'
        );
    }

    public static function test_a_declared_legacy_charset_is_replaced_with_utf8mb4()
    {
        static::__assert_equals(
            'CREATE TABLE t (a VARCHAR(20) ' . self::UTF8 . ') DEFAULT CHARSET=utf8mb4',
            static::__transform('CREATE TABLE t (a VARCHAR(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci) DEFAULT CHARSET=latin1')
        );
    }

    public static function test_enum_is_refused_and_the_message_advises_a_bigint_enum()
    {
        foreach ([
            "CREATE TABLE t (status ENUM('a','b') NOT NULL)",
            "ALTER TABLE t ADD COLUMN status ENUM('a','b') NULL",
        ] as $query) {
            $e = static::__assert_throws(RuntimeException::class, fn () => static::__transform($query), 'ENUM column type is forbidden');

            static::__assert_contains('BIGINT', $e->getMessage(), 'the message names the BIGINT enum column');
            static::__assert_contains('$columns', $e->getMessage(), 'and where its values are declared');
            static::__assert_contains('rsx:man enums', $e->getMessage());
            static::__assert_contains('Query: ', $e->getMessage(), 'and quotes the statement');
        }
    }

    public static function test_set_year_and_time_are_refused()
    {
        static::__assert_throws(RuntimeException::class, fn () => static::__transform("CREATE TABLE t (tags SET('a','b'))"), 'SET column type is forbidden');
        static::__assert_throws(RuntimeException::class, fn () => static::__transform('CREATE TABLE t (y YEAR NOT NULL)'), 'YEAR column type is forbidden');
        static::__assert_throws(RuntimeException::class, fn () => static::__transform('CREATE TABLE t (t TIME NOT NULL)'), 'TIME column type is forbidden');
        static::__assert_throws(
            RuntimeException::class,
            fn () => static::__transform('CREATE TABLE t (a DATETIME NULL, b TIME NULL)'),
            'TIME column type is forbidden'
        );
    }

    public static function test_a_forbidden_word_inside_a_comment_or_string_is_not_a_type()
    {
        static::__assert_equals(
            "CREATE TABLE t (note VARCHAR(20) " . self::UTF8 . " COMMENT 'TIME of day, YEAR, ENUM(x)', at DATETIME(3), stamp TIMESTAMP(3))",
            static::__transform("CREATE TABLE t (note VARCHAR(20) COMMENT 'TIME of day, YEAR, ENUM(x)', at DATETIME, stamp TIMESTAMP)")
        );
    }

    public static function test_a_backtick_quoted_name_year_or_time_is_not_a_type()
    {
        static::__assert_equals(
            "CREATE TABLE t (note VARCHAR(20) " . self::UTF8 . ", `year` BIGINT NOT NULL, `time` DATETIME(3) NULL, KEY `year` (`year`))",
            static::__transform('CREATE TABLE t (note VARCHAR(20), `year` BIGINT NOT NULL, `time` DATETIME NULL, KEY `year` (`year`))'),
            'a column and an index NAMED year or time pass'
        );
        static::__assert_equals(
            'ALTER TABLE t ADD COLUMN `time` BIGINT NULL',
            static::__transform('ALTER TABLE t ADD COLUMN `time` BIGINT NULL')
        );
        static::__assert_throws(
            RuntimeException::class,
            fn () => static::__transform('CREATE TABLE t (`year` YEAR NOT NULL)'),
            'YEAR column type is forbidden'
        );
        static::__assert_throws(
            RuntimeException::class,
            fn () => static::__transform('CREATE TABLE t (`at` DATETIME NULL, `t` TIME NULL)'),
            'TIME column type is forbidden'
        );
    }
}
