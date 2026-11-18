<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\UrlFetch\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Lib\Support\UrlFetch\Rsx_Url_Fetch;
use App\RSpade\Lib\Support\UrlFetch\Rsx_Url_Fetch_Exception;

/**
 * Rsx_Url_Fetch::address_class() - the destination policy's one classifier - and
 * check_destination() over IP-literal hosts. Pure logic: no network, no database.
 */
class Url_Fetch_Address_Class_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    public static function test_ipv4_ranges_are_classified()
    {
        $expected = [
            '127.0.0.1' => 'loopback',
            '127.255.255.254' => 'loopback',
            '0.0.0.0' => 'unspecified',
            '0.1.2.3' => 'unspecified',
            '10.0.0.1' => 'private',
            '172.16.0.1' => 'private',
            '172.31.255.255' => 'private',
            '192.168.1.1' => 'private',
            '169.254.169.254' => 'link-local',
            '100.64.0.1' => 'carrier-grade NAT',
            '100.127.255.255' => 'carrier-grade NAT',
            '224.0.0.1' => 'multicast',
            '239.255.255.250' => 'multicast',
            '255.255.255.255' => 'broadcast',
            '240.0.0.1' => 'reserved',
            '192.0.2.10' => 'reserved',
            '198.18.0.1' => 'reserved',
            '203.0.113.5' => 'reserved',
        ];
        foreach ($expected as $ip => $class) {
            static::__assert_equals($class, Rsx_Url_Fetch::address_class($ip), $ip);
        }
    }

    public static function test_public_ipv4_neighbours_of_each_range_are_public()
    {
        foreach (['8.8.8.8', '1.1.1.1', '172.15.255.255', '172.32.0.1', '100.63.255.255', '100.128.0.1', '169.253.255.255', '11.0.0.1', '223.255.255.255'] as $ip) {
            static::__assert_null(Rsx_Url_Fetch::address_class($ip), "{$ip} is public");
        }
    }

    public static function test_ipv6_ranges_are_classified()
    {
        $expected = [
            '::1' => 'loopback',
            '::' => 'unspecified',
            'fc00::1' => 'private',
            'fd12:3456::1' => 'private',
            'fe80::1' => 'link-local',
            'febf::1' => 'link-local',
            'ff02::1' => 'multicast',
            '2001:db8::1' => 'reserved',
            '[::1]' => 'loopback',
        ];
        foreach ($expected as $ip => $class) {
            static::__assert_equals($class, Rsx_Url_Fetch::address_class($ip), $ip);
        }

        static::__assert_null(Rsx_Url_Fetch::address_class('2606:4700:4700::1111'), 'a public IPv6 address');
    }

    public static function test_ipv6_forms_embedding_ipv4_answer_for_the_embedded_address()
    {
        static::__assert_equals('loopback', Rsx_Url_Fetch::address_class('::ffff:127.0.0.1'), 'IPv4-mapped loopback');
        static::__assert_equals('link-local', Rsx_Url_Fetch::address_class('::ffff:169.254.169.254'), 'IPv4-mapped metadata address');
        static::__assert_equals('private', Rsx_Url_Fetch::address_class('::ffff:a00:1'), 'IPv4-mapped 10.0.0.1, hex spelling');
        static::__assert_equals('private', Rsx_Url_Fetch::address_class('64:ff9b::10.0.0.1'), 'NAT64 of a private address');
        static::__assert_equals('loopback', Rsx_Url_Fetch::address_class('2002:7f00:1::1'), '6to4 of 127.0.0.1');
        static::__assert_null(Rsx_Url_Fetch::address_class('::ffff:8.8.8.8'), 'IPv4-mapped public address');
    }

    public static function test_not_an_address_is_reserved()
    {
        static::__assert_equals('reserved', Rsx_Url_Fetch::address_class('not-an-ip'));
    }

    public static function test_check_destination_refuses_literal_private_hosts_and_honours_allow_private()
    {
        foreach (['http://127.0.0.1/', 'http://[::1]:8080/', 'https://169.254.169.254/latest/meta-data/', 'http://[::ffff:127.0.0.1]/', 'http://10.1.2.3/'] as $url) {
            $e = static::__assert_throws(Rsx_Url_Fetch_Exception::class, fn () => Rsx_Url_Fetch::check_destination($url), 'address');
            static::__assert_equals(Rsx_Url_Fetch_Exception::REFUSED_DESTINATION, $e->reason, $url);
        }

        static::__assert_equals(['127.0.0.1'], Rsx_Url_Fetch::check_destination('http://127.0.0.1/', true));
        static::__assert_equals(['8.8.8.8'], Rsx_Url_Fetch::check_destination('http://8.8.8.8/'));
    }
}
