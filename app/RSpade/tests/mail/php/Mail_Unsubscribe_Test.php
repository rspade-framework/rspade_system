<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Mail\Mail_Unsubscribe_Controller;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Email_Recipient_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Notification_Fixture_Email;

/**
 * Mail_Unsubscribe_Test - the other half of every non-transactional footer link.
 *
 * THE SIGNATURE IS THE ENTIRE AUTHORIZATION, and these tests are what hold that claim
 * up in both directions. Possession of the link proves the holder received a message
 * addressed to that address at that tenant, which is exactly the authority needed to
 * stop more of them - so there is no login and no session lookup. A recipient is
 * usually not a user of this application at all, and demanding a login before honouring
 * an opt-out is how a sender earns a spam complaint instead of an unsubscribe.
 *
 * Every refusal is a 404 and nothing else. A tampered signature, an unknown site, a
 * category that cannot be unsubscribed from - all one answer, with no hint about which
 * part of the tuple was wrong and no confirmation that the address is known here.
 *
 * The round trip at the end is the assertion that matters most: the link a message
 * carried, followed, must actually stop the next message.
 */
class Mail_Unsubscribe_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    private static function __address(string $prefix): string
    {
        return $prefix . '_' . uniqid() . '@example.com';
    }

    /**
     * The signed URL a NOTIFICATION footer would carry for this address, split into the
     * path and the query the dispatcher needs.
     *
     * @return array{0: string, 1: array<string,string>}
     */
    private static function __signed(string $email, int $category = Email_Queue_Model::CATEGORY_NOTIFICATION): array
    {
        $url = Rsx_Mail::unsubscribe_url($email, $category, self::SITE_ID);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [Mail_Unsubscribe_Controller::PATH, $query];
    }

    /**
     * Dispatch the unsubscribe route the way the web entry point does.
     */
    private static function __dispatch(string $method, array $query, array $body = [])
    {
        $url = Mail_Unsubscribe_Controller::PATH . '?' . http_build_query($query);

        return Dispatcher::dispatch(
            $url,
            $method,
            [],
            Request::create($url, $method, $body)
        );
    }

    private static function __recipient(string $email): ?Email_Recipient_Model
    {
        return Email_Recipient_Model::where('site_id', self::SITE_ID)
            ->where('email', strtolower($email))
            ->first();
    }

    // =========================================================================
    // THE CONFIRMATION PAGE
    // =========================================================================

    public static function test_a_valid_link_renders_the_confirmation_page()
    {
        $email = static::__address('confirm');
        [, $query] = static::__signed($email);

        $response = static::__dispatch('GET', $query);
        $body = $response->getContent();

        static::__assert_equals(200, $response->getStatusCode(), 'a signed link opens');
        static::__assert_contains($email, $body, 'the page names the address the link already carried');
        static::__assert_contains(
            'notification email',
            $body,
            'and the category it is about, in the sentence a person reads'
        );
    }

    /**
     * A GET decides nothing. A mail client or a link scanner PREFETCHES URLs, and an
     * opt-out recorded by a scanner is an opt-out the recipient never asked for.
     */
    public static function test_a_get_records_no_opt_out()
    {
        $email = static::__address('get_only');
        [, $query] = static::__signed($email);

        static::__dispatch('GET', $query);

        static::__assert_null(
            static::__recipient($email),
            'merely opening the page changes nothing - only a POST decides'
        );
    }

    // =========================================================================
    // RECORDING THE OPT-OUT
    // =========================================================================

    public static function test_posting_the_form_blocks_the_named_category_only()
    {
        $email = static::__address('scope_category');
        [, $query] = static::__signed($email);

        $response = static::__dispatch('POST', $query, ['scope' => 'category']);

        static::__assert_equals(200, $response->getStatusCode(), 'the POST is answered with the done page');

        static::__assert_true(
            Email_Recipient_Model::is_blocked(self::SITE_ID, $email, Email_Queue_Model::CATEGORY_NOTIFICATION),
            'notifications are blocked'
        );
        static::__assert_false(
            Email_Recipient_Model::is_blocked(self::SITE_ID, $email, Email_Queue_Model::CATEGORY_MARKETING),
            'and marketing is not - the visitor asked to stop THESE emails'
        );
    }

    public static function test_posting_scope_all_blocks_every_opt_outable_category()
    {
        $email = static::__address('scope_all');
        [, $query] = static::__signed($email);

        static::__dispatch('POST', $query, ['scope' => 'all']);

        static::__assert_true(
            Email_Recipient_Model::is_blocked(self::SITE_ID, $email, Email_Queue_Model::CATEGORY_NOTIFICATION),
            'notifications are blocked'
        );
        static::__assert_true(
            Email_Recipient_Model::is_blocked(self::SITE_ID, $email, Email_Queue_Model::CATEGORY_MARKETING),
            'and so is marketing'
        );
        static::__assert_not_null(static::__recipient($email)->unsubscribed_at, 'and the moment is stamped');
    }

    /**
     * RFC 8058: a mail provider POSTs this body with no browser, no form and no token,
     * and expects a 2xx with no redirect. Anything else and the provider records a
     * failure - which is how a working unsubscribe button turns into a spam report.
     */
    public static function test_a_one_click_post_answers_with_plain_text_and_blocks_the_category()
    {
        $email = static::__address('one_click');
        [, $query] = static::__signed($email);

        $response = static::__dispatch('POST', $query, ['List-Unsubscribe' => 'One-Click']);

        static::__assert_equals(200, $response->getStatusCode(), 'a provider gets a 2xx');
        static::__assert_contains(
            'text/plain',
            (string) $response->headers->get('Content-Type'),
            'and a line of text, never a page'
        );
        static::__assert_contains('Unsubscribed', $response->getContent(), 'saying what happened');

        static::__assert_true(
            Email_Recipient_Model::is_blocked(self::SITE_ID, $email, Email_Queue_Model::CATEGORY_NOTIFICATION),
            'the category is blocked'
        );
        static::__assert_false(
            Email_Recipient_Model::is_blocked(self::SITE_ID, $email, Email_Queue_Model::CATEGORY_MARKETING),
            'and only that one - a machine may not opt somebody out of everything on their behalf'
        );
    }

    // =========================================================================
    // REFUSALS - all one answer
    // =========================================================================

    public static function test_a_tampered_signature_is_a_404()
    {
        $email = static::__address('tampered');
        [, $query] = static::__signed($email);
        $query['sig'] = str_repeat('0', strlen((string) $query['sig']));

        static::__assert_equals(404, static::__dispatch('GET', $query)->getStatusCode(), 'the HMAC did not verify');
    }

    /**
     * The signature covers the SITE too. The blocklist is per tenant, so a link minted
     * for one site must not be replayable to unsubscribe the same address from another.
     */
    public static function test_a_link_replayed_at_another_site_is_a_404()
    {
        $email = static::__address('other_site');
        [, $query] = static::__signed($email);
        $query['site'] = (string) (self::SITE_ID + 1);

        static::__assert_equals(404, static::__dispatch('GET', $query)->getStatusCode(), 'the site is part of the signature');
    }

    public static function test_swapping_the_address_or_the_category_is_a_404()
    {
        $email = static::__address('swap');

        [, $query] = static::__signed($email);
        $query['email'] = static::__address('somebody_else');

        static::__assert_equals(404, static::__dispatch('GET', $query)->getStatusCode(), 'the address is part of the signature');

        [, $query] = static::__signed($email);
        $query['category'] = (string) Email_Queue_Model::CATEGORY_MARKETING;

        static::__assert_equals(404, static::__dispatch('GET', $query)->getStatusCode(), 'and so is the category');
    }

    /**
     * A link naming TRANSACTIONAL would render a page promising something block() cannot
     * do, so it is refused even though its signature verifies.
     */
    public static function test_a_transactional_category_is_a_404()
    {
        $email = static::__address('transactional');
        [, $query] = static::__signed($email, Email_Queue_Model::CATEGORY_TRANSACTIONAL);

        static::__assert_equals(
            404,
            static::__dispatch('GET', $query)->getStatusCode(),
            'there is no opting out of a message the recipient\'s own action asked for'
        );
    }

    public static function test_a_missing_parameter_is_a_404()
    {
        $email = static::__address('incomplete');
        [, $complete] = static::__signed($email);

        foreach (['email', 'category', 'site', 'sig'] as $missing) {
            $query = $complete;
            unset($query[$missing]);

            static::__assert_equals(
                404,
                static::__dispatch('GET', $query)->getStatusCode(),
                "a link with no '{$missing}' is refused"
            );
        }
    }

    public static function test_an_unknown_scope_is_a_404()
    {
        $email = static::__address('bad_scope');
        [, $query] = static::__signed($email);

        static::__assert_equals(
            404,
            static::__dispatch('POST', $query, ['scope' => 'everything_everywhere'])->getStatusCode(),
            'the two scopes are the only two'
        );
        static::__assert_null(static::__recipient($email), 'and nothing was recorded');
    }

    // =========================================================================
    // THE ROUND TRIP
    // =========================================================================

    /**
     * The whole point, end to end: send a NOTIFICATION, follow the link its footer
     * carried, and the NEXT one is recorded BLOCKED instead of being sent.
     */
    public static function test_following_the_link_from_a_message_stops_the_next_one()
    {
        static::__acting_as_site(self::SITE_ID);

        $email = static::__address('round_trip');

        $previous = config('rsx.mail.dev_site.domain_whitelist');
        config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            $first = (new Mail_Notification_Fixture_Email('First notice'))->to($email)->send();

            static::__assert_equals(
                Email_Queue_Model::STATUS_PENDING,
                (int) $first->status_id,
                'the first message queues normally'
            );

            [, $query] = static::__signed($email);
            static::__dispatch('POST', $query, ['scope' => 'category']);

            $second = (new Mail_Notification_Fixture_Email('Second notice'))->to($email)->send();

            static::__assert_equals(
                Email_Queue_Model::STATUS_BLOCKED,
                (int) $second->status_id,
                'the opt-out reaches the send path - the next notification is never queued'
            );
        } finally {
            config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
        }
    }

    /**
     * TRANSACTIONAL ignores the blocklist entirely. A password reset the recipient just
     * asked for must not be silenced by an unsubscribe from last year.
     */
    public static function test_an_opt_out_never_silences_a_transactional_message()
    {
        static::__acting_as_site(self::SITE_ID);

        $email = static::__address('still_transactional');
        [, $query] = static::__signed($email);
        static::__dispatch('POST', $query, ['scope' => 'all']);

        $previous = config('rsx.mail.dev_site.domain_whitelist');
        config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            $record = (new \App\RSpade\Core\Mail\Rsx_Mail_Test_Email())->to($email)->send();

            static::__assert_equals(
                Email_Queue_Model::STATUS_PENDING,
                (int) $record->status_id,
                'a transactional message still goes out'
            );
        } finally {
            config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
        }
    }
}
