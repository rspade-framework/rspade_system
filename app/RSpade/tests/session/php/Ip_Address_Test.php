<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Session\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Session\Ip_Address;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Ip_Address: every recorded client address stored once in _ip_addresses, referenced by id.
 *
 * What is pinned: an address resolves to one row however often it is asked for, and the
 * answer is memoized for the process; no address (null, '', 'CLI') is null; a full-length
 * IPv6 address is kept whole; address() and describe() read a row back, describe() with
 * every geocode field present and null until a geocoder fills it; join() puts the address
 * and its geocode on a caller's query under the caller's prefix and column() names a field
 * for where()/orderBy(); a row minted in a rolled-back transaction is not answered again.
 *
 * The memo is a process static a transaction rollback does not undo by itself, so every
 * test resets it first.
 */
class Ip_Address_Test extends Rsx_Test_Abstract
{
    public static function setup()
    {
        Ip_Address::_reset();
    }

    private static function __row_count(string $ip_address): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) AS c FROM _ip_addresses WHERE ip_address = ?', [$ip_address])->c;
    }

    public static function test_an_address_is_one_row_however_often_it_is_resolved()
    {
        $first = Ip_Address::id('203.0.113.61');
        $second = Ip_Address::id('203.0.113.61');

        Ip_Address::_reset();
        $after_reset = Ip_Address::id('203.0.113.61');

        $other = Ip_Address::id('203.0.113.62');

        static::__assert_equals($first, $second);
        static::__assert_equals($first, $after_reset, 'a fresh process lands on the same row');
        static::__assert_not_equals($first, $other);
        static::__assert_equals(1, static::__row_count('203.0.113.61'));
    }

    /**
     * The second resolve is answered from the process memo - proven by removing the row
     * behind it: the memo still answers until _reset(), after which the address is minted
     * again.
     */
    public static function test_resolving_is_memoized_until_reset()
    {
        $id = Ip_Address::id('203.0.113.63');
        DB::statement('DELETE FROM _ip_addresses WHERE id = ?', [$id]);

        static::__assert_equals($id, Ip_Address::id('203.0.113.63'), 'answered from the memo');
        static::__assert_equals('203.0.113.63', Ip_Address::address($id), 'address() is memoized too');

        Ip_Address::_reset();

        $again = Ip_Address::id('203.0.113.63');

        static::__assert_not_equals($id, $again, 'minted again after the reset');
        static::__assert_null(Ip_Address::address($id), 'the deleted row reads as no address');
        static::__assert_equals(1, static::__row_count('203.0.113.63'));
    }

    public static function test_no_address_is_null()
    {
        static::__assert_null(Ip_Address::id(null));
        static::__assert_null(Ip_Address::id(''));
        static::__assert_null(Ip_Address::id('CLI'));
        static::__assert_null(Ip_Address::address(null));
        static::__assert_null(Ip_Address::address(0));
        static::__assert_equals(0, static::__row_count('CLI'));
    }

    public static function test_a_full_length_ipv6_address_is_kept_whole()
    {
        $ipv6 = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
        $id = Ip_Address::id($ipv6);

        Ip_Address::_reset();

        static::__assert_equals($ipv6, Ip_Address::address($id));
    }

    /**
     * describe() answers the address and every geocode field; until a geocoder has located
     * the address those are null, and once a row carries them they are answered as stored.
     */
    public static function test_describe_answers_the_address_and_its_geocode()
    {
        $id = Ip_Address::id('198.51.100.64');

        static::__assert_equals(
            [
                'ip_address' => '198.51.100.64',
                'city' => null,
                'state' => null,
                'country' => null,
                'latitude' => null,
                'longitude' => null,
            ],
            Ip_Address::describe($id),
            'every geocode field is present and null before a geocoder runs'
        );

        DB::statement(
            "UPDATE _ip_addresses SET city = 'Springfield', state = 'Illinois', country = 'United States', latitude = 39.7817, longitude = -89.6501 WHERE id = ?",
            [$id]
        );

        $described = Ip_Address::describe($id);

        static::__assert_equals('Springfield', $described['city']);
        static::__assert_equals('Illinois', $described['state']);
        static::__assert_equals('United States', $described['country']);
        static::__assert_equals_approx(39.7817, $described['latitude'], 0.00001);
        static::__assert_equals_approx(-89.6501, $described['longitude'], 0.00001);

        static::__assert_null(Ip_Address::describe(0), 'no such row');
    }

    /**
     * join() puts the address and its geocode on the caller's query as <prefix>_<name>,
     * keeps the query's own columns, and lists a row with no address.
     */
    public static function test_join_adds_the_address_fields_under_the_prefix()
    {
        $located = Ip_Address::id('198.51.100.65');
        DB::statement("UPDATE _ip_addresses SET city = 'Lisbon', country = 'Portugal', latitude = 38.7223, longitude = -9.1393 WHERE id = ?", [$located]);

        $with = DB::table('_login_history')->insertGetId([
            'login_user_id' => 0, 'email_attempted' => 'ip-join-a@rspade.test', 'ip_address_id' => $located, 'status' => 'success',
        ]);
        $without = DB::table('_login_history')->insertGetId([
            'login_user_id' => 0, 'email_attempted' => 'ip-join-b@rspade.test', 'ip_address_id' => null, 'status' => 'success',
        ]);

        $query = DB::table('_login_history')->whereIn('_login_history.id', [$with, $without]);
        $returned = Ip_Address::join($query, '_login_history.ip_address_id', 'visitor');
        $rows = $query->orderBy('_login_history.id')->get()->all();

        static::__assert_true($returned === $query, 'the same query comes back');
        static::__assert_count(2, $rows, 'a row with no address is still listed');
        static::__assert_equals('ip-join-a@rspade.test', $rows[0]->email_attempted, "the query's own columns are kept");

        static::__assert_equals('198.51.100.65', $rows[0]->visitor_ip_address);
        static::__assert_equals('Lisbon', $rows[0]->visitor_city);
        static::__assert_null($rows[0]->visitor_state);
        static::__assert_equals('Portugal', $rows[0]->visitor_country);
        static::__assert_equals_approx(38.7223, (float) $rows[0]->visitor_latitude, 0.00001);
        static::__assert_equals_approx(-9.1393, (float) $rows[0]->visitor_longitude, 0.00001);

        static::__assert_null($rows[1]->visitor_ip_address);
        static::__assert_null($rows[1]->visitor_city);

        static::__assert_equals(
            array_keys(Ip_Address::describe($located)),
            array_map(fn ($key) => substr($key, 8), array_values(array_filter(array_keys((array) $rows[0]), fn ($key) => str_starts_with($key, 'visitor_')))),
            'the joined fields are exactly the fields describe() answers'
        );
    }

    /**
     * A condition or an ordering on a joined address field is written with column().
     */
    public static function test_column_names_a_joined_field_for_filtering()
    {
        DB::table('_login_history')->insert([
            ['login_user_id' => 0, 'email_attempted' => 'ip-col-a@rspade.test', 'ip_address_id' => Ip_Address::id('198.51.100.66'), 'status' => 'success'],
            ['login_user_id' => 0, 'email_attempted' => 'ip-col-b@rspade.test', 'ip_address_id' => Ip_Address::id('198.51.100.67'), 'status' => 'success'],
        ]);

        $query = DB::table('_login_history')->whereIn('email_attempted', ['ip-col-a@rspade.test', 'ip-col-b@rspade.test']);
        Ip_Address::join($query, '_login_history.ip_address_id', 'origin');

        $matches = $query->where(Ip_Address::column('origin', 'ip_address'), '198.51.100.67')
            ->orderBy(Ip_Address::column('origin', 'country'))
            ->get()
            ->all();

        static::__assert_count(1, $matches);
        static::__assert_equals('ip-col-b@rspade.test', $matches[0]->email_attempted);

        static::__assert_throws(\InvalidArgumentException::class, fn () => Ip_Address::column('origin', 'id'), 'no address field');
        static::__assert_throws(\InvalidArgumentException::class, fn () => Ip_Address::column('1 or 1', 'city'), 'not a usable prefix');
        static::__assert_throws(
            \InvalidArgumentException::class,
            fn () => Ip_Address::join(DB::table('_login_history'), 'ip_address_id', 'origin'),
            'not a qualified column'
        );
    }

    /**
     * The memo must not outlive the row: an address minted inside a transaction that rolls
     * back is gone, and the next resolve has to mint it again.
     */
    public static function test_a_row_rolled_back_is_not_answered_again()
    {
        DB::beginTransaction();
        $rolled_back = Ip_Address::id('203.0.113.68');
        DB::rollBack();

        static::__assert_equals(0, static::__row_count('203.0.113.68'), 'the rolled-back row is gone');

        $again = Ip_Address::id('203.0.113.68');

        static::__assert_equals(1, static::__row_count('203.0.113.68'), 'the address was minted again');
        static::__assert_equals('203.0.113.68', Ip_Address::address($again));
        static::__assert_not_null($rolled_back);
    }
}
