<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Rsx_Mail::delivers() / deliverable_to() - "will this email arrive?", asked before sending.
 *
 * PINNED: only live delivers; the dev-site gate admits only a whitelisted address (a catchall
 * redirect is NOT arrival); the site block list refuses everything but SECURITY; the recipient
 * opt-out refuses its category except TRANSACTIONAL and SECURITY.
 *
 * The framework test host is a `.dev.` hostname, so the dev-site gate is live; the config each
 * test relies on is set by that test and restored by teardown(). Rows roll back with the
 * per-test transaction.
 */
class Mail_Deliverability_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private static array $__saved = [];

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);

        foreach (['rsx.mail.delivery', 'rsx.mail.dev_site.address_whitelist', 'rsx.mail.dev_site.domain_whitelist', 'rsx.mail.dev_site.catchall_address'] as $key) {
            static::$__saved[$key] = config($key);
        }
    }

    public static function teardown()
    {
        config(static::$__saved);
    }

    private static function __live_and_whitelisted(): void
    {
        config([
            'rsx.mail.delivery' => 'live',
            'rsx.mail.dev_site.domain_whitelist' => 'example.com',
            'rsx.mail.dev_site.address_whitelist' => '',
            'rsx.mail.dev_site.catchall_address' => '',
        ]);
    }

    private static function __address(string $prefix): string
    {
        return $prefix . '_' . uniqid() . '@example.com';
    }

    public static function test_only_live_delivers()
    {
        static::__live_and_whitelisted();
        $email = static::__address('mode');

        foreach (['suppressed', 'aiosmtpd', 'disabled'] as $mode) {
            config(['rsx.mail.delivery' => $mode]);
            static::__assert_false(Rsx_Mail::delivers(), "{$mode} does not deliver");
            static::__assert_false(Rsx_Mail::deliverable_to($email, Rsx_Mail::SECURITY), "nothing arrives in {$mode}");
        }

        config(['rsx.mail.delivery' => 'live']);
        static::__assert_true(Rsx_Mail::delivers());
        static::__assert_true(Rsx_Mail::deliverable_to($email, Rsx_Mail::SECURITY));
    }

    public static function test_the_dev_site_gate_admits_only_a_whitelisted_address()
    {
        static::__assert_true(Rsx_Mail::is_dev_mode(), 'the framework test host is a .dev. host');
        static::__live_and_whitelisted();
        config(['rsx.mail.dev_site.domain_whitelist' => '']);

        $email = static::__address('dev');
        static::__assert_false(Rsx_Mail::deliverable_to($email, Rsx_Mail::SECURITY), 'no whitelist, no catchall: nowhere');

        config(['rsx.mail.dev_site.catchall_address' => 'catchall@example.org']);
        static::__assert_false(Rsx_Mail::deliverable_to($email, Rsx_Mail::SECURITY), 'a catchall delivers to somebody else');

        config(['rsx.mail.dev_site.address_whitelist' => $email]);
        static::__assert_true(Rsx_Mail::deliverable_to($email, Rsx_Mail::SECURITY), 'a whitelisted address arrives');
    }

    public static function test_the_site_block_list_spares_only_security()
    {
        static::__live_and_whitelisted();
        $email = static::__address('listed');
        Rsx_Mail::block_address($email, 'test');

        static::__assert_true(Rsx_Mail::deliverable_to($email, Rsx_Mail::SECURITY));
        static::__assert_false(Rsx_Mail::deliverable_to($email, Rsx_Mail::TRANSACTIONAL));
        static::__assert_false(Rsx_Mail::deliverable_to($email, Rsx_Mail::NOTIFICATION));
        static::__assert_false(Rsx_Mail::deliverable_to($email, Rsx_Mail::MARKETING));
    }

    public static function test_the_recipient_opt_out_spares_transactional_and_security()
    {
        static::__live_and_whitelisted();
        $email = static::__address('optout');
        Rsx_Mail::block($email, Rsx_Mail::MARKETING);

        static::__assert_false(Rsx_Mail::deliverable_to($email, Rsx_Mail::MARKETING), 'the opted-out category');
        static::__assert_true(Rsx_Mail::deliverable_to($email, Rsx_Mail::NOTIFICATION), 'another category');

        Rsx_Mail::block($email, Rsx_Mail::TRANSACTIONAL);
        Rsx_Mail::block($email, Rsx_Mail::SECURITY);
        static::__assert_true(Rsx_Mail::deliverable_to($email, Rsx_Mail::TRANSACTIONAL), 'transactional ignores the opt-out');
        static::__assert_true(Rsx_Mail::deliverable_to($email, Rsx_Mail::SECURITY), 'security ignores the opt-out');
    }
}
