<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use App\RSpade\Core\Database\Rsx_Page_Query;
use App\RSpade\Core\Session\Audit_Session;
use App\RSpade\Core\Session\Login_History;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Login_History::list_page() - the display-page reader over _login_history, and through it
 * the standard every such reader follows (Rsx_Page_Query): one $query array in, {rows,
 * total, offset, limit} out, and every caller-supplied value validated by the reader.
 *
 * What is pinned: the scope is required and bounds the answer; total counts every match
 * while rows holds one page; the caller sorts by a published NAME and anything else is
 * refused rather than passed to SQL; the filter searches the whole history - the user
 * agent included, which lives in another table - before the page is cut.
 */
class Login_History_List_Page_Test extends Rsx_Test_Abstract
{
    private const IDENTITY = 424201;
    private const OTHER_IDENTITY = 424202;

    /**
     * Five rows for IDENTITY and one for OTHER_IDENTITY, a second apart, oldest first.
     */
    private static function __seed(): void
    {
        $rows = [
            [self::IDENTITY, 'ada@example.com', '10.0.0.5', 'Mozilla/5.0 Firefox/128.0', Login_History::STATUS_SUCCESS],
            [self::IDENTITY, 'ada@example.com', '10.0.0.1', 'Mozilla/5.0 Chrome/126.0', Login_History::STATUS_SUCCESS],
            [self::IDENTITY, 'ada+alias@example.com', '10.0.0.3', 'Mozilla/5.0 Firefox/128.0', Login_History::STATUS_PASSKEY_ENROLLED],
            [self::IDENTITY, 'ada@example.com', '10.0.0.4', null, Login_History::STATUS_SUCCESS],
            [self::IDENTITY, 'ada@example.com', '10.0.0.2', 'Mozilla/5.0 Safari/17.5', Login_History::STATUS_SUCCESS],
            [self::OTHER_IDENTITY, 'grace@example.com', '10.0.0.9', 'Mozilla/5.0 Firefox/128.0', Login_History::STATUS_SUCCESS],
        ];

        foreach ($rows as $index => [$login_user_id, $email, $ip_address, $user_agent, $status]) {
            DB::table('_login_history')->insert([
                'login_user_id' => $login_user_id,
                'email_attempted' => $email,
                'ip_address' => $ip_address,
                'user_agent_id' => Audit_Session::user_agent_id($user_agent),
                'status' => $status,
                'created_at' => now()->subMinutes(10)->addSeconds($index),
            ]);
        }
    }

    public static function test_the_answer_is_one_page_and_the_total_of_every_match()
    {
        static::__seed();

        $page = Login_History::list_page(['login_user_id' => self::IDENTITY, 'limit' => 2]);

        static::__assert_equals(['rows', 'total', 'offset', 'limit'], array_keys($page));
        static::__assert_count(2, $page['rows']);
        static::__assert_equals(5, $page['total'], 'total counts every match, not the page');
        static::__assert_equals(0, $page['offset']);
        static::__assert_equals(2, $page['limit']);
        static::__assert_equals('10.0.0.2', $page['rows'][0]['ip_address'], 'newest first by default');
        static::__assert_array_has_key('user_agent_parsed', $page['rows'][0], 'rows are present_record() rows');
    }

    public static function test_the_scope_bounds_the_answer_and_is_required()
    {
        static::__seed();

        $other = Login_History::list_page(['login_user_id' => self::OTHER_IDENTITY]);

        static::__assert_equals(1, $other['total'], 'another identity sees only its own rows');
        static::__assert_equals('grace@example.com', $other['rows'][0]['email']);

        static::__assert_throws(InvalidArgumentException::class, fn () => Login_History::list_page([]), 'login_user_id is required');
        static::__assert_throws(InvalidArgumentException::class, fn () => Login_History::list_page(['login_user_id' => 0]), 'login_user_id is required');
    }

    public static function test_offset_walks_the_pages()
    {
        static::__seed();

        $seen = [];

        foreach ([0, 2, 4] as $offset) {
            $page = Login_History::list_page(['login_user_id' => self::IDENTITY, 'limit' => 2, 'offset' => $offset]);

            foreach ($page['rows'] as $row) {
                $seen[] = $row['ip_address'];
            }
        }

        static::__assert_equals(['10.0.0.2', '10.0.0.4', '10.0.0.3', '10.0.0.1', '10.0.0.5'], $seen, 'every row once, in order');
    }

    public static function test_the_caller_sorts_by_a_published_name()
    {
        static::__seed();

        $ascending = Login_History::list_page(['login_user_id' => self::IDENTITY, 'sort' => 'ip_address', 'order' => 'asc']);
        $email_first = Login_History::list_page(['login_user_id' => self::IDENTITY, 'sort' => 'email', 'order' => 'asc', 'limit' => 1]);
        $email_last = Login_History::list_page(['login_user_id' => self::IDENTITY, 'sort' => 'email', 'order' => 'desc', 'limit' => 1]);

        static::__assert_equals(
            ['10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4', '10.0.0.5'],
            array_column($ascending['rows'], 'ip_address')
        );
        // 'email' is the published name for the attempted-email column: the two ends of
        // that ordering are the two distinct addresses in the history.
        static::__assert_not_equals($email_first['rows'][0]['email'], $email_last['rows'][0]['email']);
    }

    /**
     * The sort value is a NAME looked up in the reader's map. A column name, an
     * expression or anything else that is not on the list is refused - it never reaches
     * the query.
     */
    public static function test_a_sort_the_reader_does_not_publish_is_refused()
    {
        foreach (['email_attempted', 'id', 'created_at desc', 'created_at; DROP TABLE _login_history', ['created_at']] as $sort) {
            static::__assert_throws(
                InvalidArgumentException::class,
                fn () => Login_History::list_page(['login_user_id' => self::IDENTITY, 'sort' => $sort]),
                'cannot sort by'
            );
        }
    }

    public static function test_order_limit_offset_and_unknown_keys_are_validated()
    {
        $base = ['login_user_id' => self::IDENTITY];

        foreach ([
            ['order' => 'DESC'],
            ['order' => 'asc, id'],
            ['limit' => 0],
            ['limit' => -5],
            ['limit' => 201],
            ['limit' => '10 OR 1=1'],
            ['offset' => -1],
            ['offset' => 1.5],
            ['filter' => ['x']],
            ['site_id' => 1],
            ['page' => 2],
        ] as $bad) {
            static::__assert_throws(InvalidArgumentException::class, fn () => Login_History::list_page($base + $bad));
        }

        static::__assert_equals(200, Login_History::list_page($base + ['limit' => 200])['limit'], 'the maximum itself is accepted');
        static::__assert_equals(25, Login_History::list_page($base)['limit'], 'the default page size');
        static::__assert_equals(3, Login_History::list_page($base + ['limit' => '3'])['limit'], 'a numeric string from a request is an integer');
    }

    /**
     * The filter runs in the query, so it finds a row that is not on the first page -
     * and it reaches the user agent, which is stored in another table.
     */
    public static function test_the_filter_searches_the_whole_history_before_the_page_is_cut()
    {
        static::__seed();

        $firefox = Login_History::list_page(['login_user_id' => self::IDENTITY, 'filter' => 'firefox', 'limit' => 1]);
        $alias = Login_History::list_page(['login_user_id' => self::IDENTITY, 'filter' => '+alias']);
        $address = Login_History::list_page(['login_user_id' => self::IDENTITY, 'filter' => '10.0.0.4']);
        $nothing = Login_History::list_page(['login_user_id' => self::IDENTITY, 'filter' => 'no such thing']);

        static::__assert_equals(2, $firefox['total'], 'two Firefox sign-ins, though the page holds one');
        static::__assert_count(1, $firefox['rows']);
        static::__assert_equals(1, $alias['total']);
        static::__assert_equals('ada+alias@example.com', $alias['rows'][0]['email']);
        static::__assert_equals(1, $address['total']);
        static::__assert_equals(0, $nothing['total']);
        static::__assert_empty($nothing['rows']);
    }

    /**
     * % and _ in what the user typed are characters to find, not wildcards.
     */
    public static function test_wildcard_characters_in_a_filter_are_literal()
    {
        static::__seed();

        static::__assert_equals(0, Login_History::list_page(['login_user_id' => self::IDENTITY, 'filter' => '%'])['total']);
        static::__assert_equals(0, Login_History::list_page(['login_user_id' => self::IDENTITY, 'filter' => 'ada_example'])['total']);
        static::__assert_equals('%a\\%b\\_c\\\\%', Rsx_Page_Query::like('a%b_c\\'));
    }
}
