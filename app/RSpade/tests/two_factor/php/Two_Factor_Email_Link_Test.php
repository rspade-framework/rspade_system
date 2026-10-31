<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\TwoFactor\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Cache\Rsx_Counter;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\TwoFactor\Rsx_Email_Link_Controller;
use App\RSpade\Core\TwoFactor\Rsx_Portal_Two_Factor;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor_Abstract;
use App\RSpade\Core\TwoFactor\Rsx_Two_Factor_Controller;
use App\RSpade\Core\TwoFactor\Two_Factor_Failed_Exception;

/**
 * The EMAIL LINK that travels beside an issued code (issued_link()), its read-only landing
 * route (Rsx_Email_Link_Controller), and what a passed emailed answer does to the identity.
 *
 * PINNED:
 *  - issued_link() needs a challenge that accepts an issued code AND the live code it travels
 *    with, and a return URL with no fragment; it answers an absolute URL signed for its host;
 *  - a resend answers the SAME link, a new challenge a new one, and nothing secret is stored
 *    (the store keeps the token's hash, never the token);
 *  - the link is bound to the browser that minted it: another browser reads OTHER_BROWSER and
 *    the code still works there; the owning browser reads SAME_BROWSER with its return URL;
 *  - {email_link} answers the challenge as ANSWER_ISSUED_CODE: it signs in, consumes the code
 *    and the link, marks the address verified, and leaves the completed marker;
 *  - a wrong or dead link is a counted wrong answer;
 *  - the landing route only reads: it redirects the owning browser with the token in the
 *    fragment, shows another browser a notice, refuses a bad signature, and consumes and counts
 *    nothing either way;
 *  - the portal facade mints a portal-host link, and a link answer admits a portal user whose
 *    address was never verified.
 *
 * Redis-held counters live outside the rolled-back transaction, so every test uses a unique
 * email and clears what it touched - the shape of Two_Factor_Issued_Code_Test.
 */
class Two_Factor_Email_Link_Test extends Rsx_Test_Abstract
{
    /** The subject belongs to a browser: each test acts as one (Rsx_Test_Abstract::__as_browser()). */
    protected static $acts_as_browser = true;

    /** The challenge page every link in these tests returns to. */
    private const RETURN_URL = '/_test/two-factor-challenge';

    public static function teardown()
    {
        Portal_Session::cli_set_portal_user_id(0);
        Rsx_Portal::set_portal_request(false);
        Portal_Session::_testing_reset();
        Session::logout();
        static::__reset_session();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * A login identity with an enabled membership and no second factor, its address NOT yet
     * verified. Leaves the session anonymous.
     */
    private static function __make_login_user(string $suffix): Login_User_Model
    {
        Session::logout();
        static::__reset_session();

        $email = 'email_link_' . $suffix . '_' . uniqid() . '@example.com';

        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make('password');
        $login_user->is_activated = true;
        $login_user->is_verified = 0;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Fixture';
        $user->last_name = 'Link';
        $user->is_enabled = 1;
        $user->save();

        Rsx_Two_Factor::clear_failures($login_user);
        static::__engine(Rsx_Two_Factor::class, '__forget_issued_code', (int) $login_user->id);

        return $login_user;
    }

    /**
     * Call one of the engine's protected statics on a realm facade.
     */
    private static function __engine(string $facade, string $method, ...$args)
    {
        return (new \ReflectionMethod($facade, $method))->invokeArgs(null, $args);
    }

    /**
     * Begin an issued-code challenge, send the code, and mint its link.
     *
     * @return array{code: string, url: string, token: string}
     */
    private static function __challenge_with_link(Login_User_Model $login_user): array
    {
        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);
        $code = Rsx_Two_Factor::issue_code();
        $url = Rsx_Two_Factor::issued_link(self::RETURN_URL);

        return ['code' => $code, 'url' => $url, 'token' => static::__token_of($url)];
    }

    /**
     * The token in a link's path.
     */
    private static function __token_of(string $url): string
    {
        static::__assert_true((bool) preg_match('#/_email_link/([0-9a-f]{64})\?s=[0-9a-f]{64}$#', $url, $m), 'a signed link: ' . $url);

        return $m[1];
    }

    /**
     * The identity's wrong-answer count.
     */
    private static function __identity_failures(string $facade, int $identity_id): int
    {
        return Rsx_Counter::get(static::__engine($facade, '__identity_failure_key', $identity_id));
    }

    /**
     * Open a link through the landing route, as a GET to the URL itself.
     */
    private static function __open(string $url)
    {
        $request = Request::create($url, 'GET');

        return Rsx_Email_Link_Controller::open($request, ['token' => static::__token_of($url)]);
    }

    // -------------------------------------------------------------------------
    // Minting
    // -------------------------------------------------------------------------

    /**
     * The link is the code's twin: refused without an accepting challenge or a live code,
     * and with a fragment in the return URL.
     */
    public static function test_issued_link_requires_its_code()
    {
        $login_user = static::__make_login_user('requires');

        static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::issued_link(self::RETURN_URL), 'expired');

        Rsx_Two_Factor::begin_challenge($login_user, null);
        static::__assert_throws(\RuntimeException::class, fn () => Rsx_Two_Factor::issued_link(self::RETURN_URL), 'does not accept an issued code');

        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);
        static::__assert_throws(\RuntimeException::class, fn () => Rsx_Two_Factor::issued_link(self::RETURN_URL), 'Call issue_code() first');

        Rsx_Two_Factor::issue_code();
        static::__assert_throws(\InvalidArgumentException::class, fn () => Rsx_Two_Factor::issued_link('/_test/two-factor-challenge#x'), 'no fragment');
        static::__assert_throws(\InvalidArgumentException::class, fn () => Rsx_Two_Factor::issued_link(''), 'no fragment');
    }

    /**
     * A resend answers the same link; a new challenge a new one; the store holds a hash.
     */
    public static function test_a_resend_returns_the_same_link_and_stores_no_token()
    {
        $login_user = static::__make_login_user('resend');

        $first = static::__challenge_with_link($login_user);

        static::__assert_true(str_starts_with($first['url'], 'http'), 'absolute');
        static::__assert_equals($first['code'], Rsx_Two_Factor::issue_code(), 'the resend re-sends the code');
        static::__assert_equals($first['url'], Rsx_Two_Factor::issued_link(self::RETURN_URL), 'and the same link');

        $stored = Rsx_Counter::value(static::__engine(Rsx_Two_Factor::class, '__email_link_key', $first['token']));
        static::__assert_not_null($stored, 'the binding is stored');
        static::__assert_false(str_contains($stored, $first['token']), 'the token itself is not stored');

        $binding = json_decode($stored, true);
        static::__assert_equals(self::RETURN_URL, $binding['return_url']);
        static::__assert_equals(Rsx_Two_Factor::challenge_pending()['challenge_id'], $binding['challenge_id']);

        // A fresh sign-in on this browser is a new challenge: the same code, a different link.
        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);
        static::__assert_equals($first['code'], Rsx_Two_Factor::issue_code());
        static::__assert_true(Rsx_Two_Factor::issued_link(self::RETURN_URL) !== $first['url'], 'a new challenge gets a new link');
    }

    // -------------------------------------------------------------------------
    // Browser binding
    // -------------------------------------------------------------------------

    /**
     * The owning browser reads SAME_BROWSER; another reads OTHER_BROWSER, and the code from
     * the same email still signs in there.
     */
    public static function test_the_link_is_bound_to_its_browser()
    {
        $login_user = static::__make_login_user('bound');
        $sent = static::__challenge_with_link($login_user);

        $here = Rsx_Two_Factor::_email_link_state($sent['token']);
        static::__assert_equals(Rsx_Two_Factor_Abstract::EMAIL_LINK_SAME_BROWSER, $here['state']);
        static::__assert_equals(self::RETURN_URL, $here['return_url']);

        static::__as_browser();

        static::__assert_equals(
            Rsx_Two_Factor_Abstract::EMAIL_LINK_OTHER_BROWSER,
            Rsx_Two_Factor::_email_link_state($sent['token'])['state'],
            'another browser'
        );

        // That browser's own sign-in: the link is no answer there, the code is.
        Rsx_Two_Factor::begin_challenge($login_user, [Rsx_Two_Factor::ANSWER_ISSUED_CODE]);
        static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::verify_challenge(['email_link' => $sent['token']]), 'not valid');

        Rsx_Two_Factor::verify_challenge(['code' => $sent['code']]);
        static::__assert_equals((int) $login_user->id, (int) Session::get_login_user_id(), 'the code signed in the other browser');
        Rsx_Two_Factor::clear_failures($login_user);
    }

    // -------------------------------------------------------------------------
    // Answering with the link
    // -------------------------------------------------------------------------

    /**
     * {email_link} signs in, consumes the code and the link, verifies the address, and leaves
     * the completed marker.
     */
    public static function test_the_link_signs_in_and_verifies_the_address()
    {
        $login_user = static::__make_login_user('answer');
        $sent = static::__challenge_with_link($login_user);
        $challenge_id = Rsx_Two_Factor::challenge_pending()['challenge_id'];

        static::__assert_null(Rsx_Two_Factor::_challenge_completed_state(), 'nothing completed yet');

        $signed_in = Rsx_Two_Factor::verify_challenge(['email_link' => $sent['token']]);

        static::__assert_equals((int) $login_user->id, (int) $signed_in->id);
        static::__assert_equals((int) $login_user->id, (int) Session::get_login_user_id(), 'signed in');
        static::__assert_equals(1, (int) Login_User_Model::where('id', $login_user->id)->value('is_verified'), 'the address is verified');
        static::__assert_null(static::__engine(Rsx_Two_Factor::class, '__live_issued_code', (int) $login_user->id), 'the code is consumed');
        static::__assert_null(
            Rsx_Counter::value(static::__engine(Rsx_Two_Factor::class, '__email_link_key', $sent['token'])),
            'the link binding is gone'
        );
        static::__assert_equals(
            Rsx_Two_Factor_Abstract::EMAIL_LINK_EXPIRED_OR_USED,
            Rsx_Two_Factor::_email_link_state($sent['token'])['state'],
            'the link reads as used'
        );

        $completed = Rsx_Two_Factor::_challenge_completed_state();
        static::__assert_equals(['completed' => true, 'challenge_id' => $challenge_id, 'by_email_link' => true], $completed);
        static::__assert_equals(
            $completed,
            Rsx_Two_Factor_Controller::challenge_state(Request::create('/_ajax/_batch', 'POST')),
            'the challenge_state endpoint answers it when nothing is pending'
        );
    }

    /**
     * A typed code consumes the link too, and verifies the address the same way.
     */
    public static function test_the_code_consumes_the_link()
    {
        $login_user = static::__make_login_user('code');
        $sent = static::__challenge_with_link($login_user);

        Rsx_Two_Factor::verify_challenge(['code' => $sent['code']]);

        static::__assert_equals(1, (int) Login_User_Model::where('id', $login_user->id)->value('is_verified'), 'a typed code verifies the address too');
        static::__assert_equals(
            Rsx_Two_Factor_Abstract::EMAIL_LINK_EXPIRED_OR_USED,
            Rsx_Two_Factor::_email_link_state($sent['token'])['state']
        );
        static::__assert_false(Rsx_Two_Factor::_challenge_completed_state()['by_email_link'], 'completed by the code');
    }

    /**
     * A wrong link, and a link whose code has been replaced, are counted wrong answers.
     */
    public static function test_a_wrong_or_dead_link_is_a_counted_failure()
    {
        $login_user = static::__make_login_user('wrong');
        $sent = static::__challenge_with_link($login_user);
        $identity_id = (int) $login_user->id;

        $before = static::__identity_failures(Rsx_Two_Factor::class, $identity_id);

        static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::verify_challenge(['email_link' => str_repeat('0', 64)]), 'not valid');
        static::__assert_equals($before + 1, static::__identity_failures(Rsx_Two_Factor::class, $identity_id), 'counted once');
        static::__assert_not_null(Rsx_Two_Factor::challenge_pending(), 'the challenge survives one wrong answer');

        // The code is replaced: the link sent with the old one is dead.
        static::__engine(Rsx_Two_Factor::class, '__forget_issued_code', $identity_id);
        Rsx_Two_Factor::issue_code();

        static::__assert_throws(Two_Factor_Failed_Exception::class, fn () => Rsx_Two_Factor::verify_challenge(['email_link' => $sent['token']]), 'not valid');
        static::__assert_equals($before + 2, static::__identity_failures(Rsx_Two_Factor::class, $identity_id), 'counted again');
        static::__assert_null(Session::get_login_user_id(), 'nobody signed in');

        Rsx_Two_Factor::clear_failures($login_user);
    }

    // -------------------------------------------------------------------------
    // The landing route
    // -------------------------------------------------------------------------

    /**
     * The owning browser is redirected with the token in the fragment, and nothing is spent.
     */
    public static function test_the_route_redirects_the_owning_browser_and_consumes_nothing()
    {
        $login_user = static::__make_login_user('route_same');
        $sent = static::__challenge_with_link($login_user);
        $before = static::__identity_failures(Rsx_Two_Factor::class, (int) $login_user->id);

        $response = static::__open($sent['url']);

        static::__assert_equals(302, $response->getStatusCode());
        static::__assert_true(
            str_ends_with($response->headers->get('Location'), self::RETURN_URL . '#rsx_email_link=' . $sent['token']),
            'to the challenge page, token in the fragment: ' . $response->headers->get('Location')
        );
        static::__assert_equals('no-referrer', $response->headers->get('Referrer-Policy'));

        // A second open (a scanner, then the user) reads the same: nothing was consumed.
        static::__assert_equals(302, static::__open($sent['url'])->getStatusCode());
        static::__assert_not_null(Rsx_Two_Factor::challenge_pending(), 'the challenge is still pending');
        static::__assert_not_null(Rsx_Two_Factor::issued_code_expires_at(), 'the code is still live');
        static::__assert_null(Session::get_login_user_id(), 'nobody signed in by a GET');
        static::__assert_equals($before, static::__identity_failures(Rsx_Two_Factor::class, (int) $login_user->id), 'nothing counted');
    }

    /**
     * Another browser gets the notice, counts nothing, and leaves the code live; a forged
     * signature and a portal-realm presentation are refused.
     */
    public static function test_the_route_shows_another_browser_a_notice()
    {
        $login_user = static::__make_login_user('route_other');
        $sent = static::__challenge_with_link($login_user);
        $before = static::__identity_failures(Rsx_Two_Factor::class, (int) $login_user->id);

        static::__as_browser();

        $response = static::__open($sent['url']);
        static::__assert_equals(409, $response->getStatusCode());
        static::__assert_true(str_contains((string) $response->getContent(), 'different browser'), 'the different-browser notice');
        static::__assert_equals($before, static::__identity_failures(Rsx_Two_Factor::class, (int) $login_user->id), 'nothing counted');
        static::__assert_not_null(static::__engine(Rsx_Two_Factor::class, '__live_issued_code', (int) $login_user->id), 'the code is still live');

        $forged = preg_replace('/s=[0-9a-f]{64}$/', 's=' . str_repeat('0', 64), $sent['url']);
        static::__assert_equals(410, static::__open($forged)->getStatusCode(), 'a forged signature is refused');

        // The same token presented in the portal realm reads nothing: the binding is the staff realm's.
        static::__assert_equals(
            Rsx_Two_Factor_Abstract::EMAIL_LINK_EXPIRED_OR_USED,
            Rsx_Portal_Two_Factor::_email_link_state($sent['token'])['state']
        );
    }

    // -------------------------------------------------------------------------
    // The portal realm
    // -------------------------------------------------------------------------

    /**
     * The portal facade mints a portal-host link, and the link admits a portal user whose
     * address was never verified - the answer verifies it before can_login() is asked.
     */
    public static function test_the_portal_link_verifies_and_admits()
    {
        // The baseline site (tests/CLAUDE.md: the test baseline carries site 1).
        $site_id = 1;

        Portal_Session::_testing_reset();
        Portal_Session::set_site_id($site_id);
        Rsx_Portal::set_portal_request(true);
        Portal_Session::cli_set_portal_user_id(0);

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = $site_id;
        $portal_user->email = 'email_link_portal_' . uniqid() . '@example.com';
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = false;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->save();

        static::__assert_false($portal_user->can_login(), 'an unverified portal user is not admitted');

        Rsx_Portal_Two_Factor::clear_failures($portal_user);
        static::__engine(Rsx_Portal_Two_Factor::class, '__forget_issued_code', (int) $portal_user->id);
        Rsx_Portal_Two_Factor::begin_challenge($portal_user, [Rsx_Portal_Two_Factor::ANSWER_ISSUED_CODE]);
        Rsx_Portal_Two_Factor::issue_code();

        $url = Rsx_Portal_Two_Factor::issued_link(Rsx_Portal::portal_path('/_test/two-factor-challenge'));
        static::__assert_true(str_contains($url, Rsx_Portal::portal_path('/_email_link/')), 'on the portal: ' . $url);

        $token = static::__token_of($url);
        static::__assert_equals(
            Rsx_Two_Factor_Abstract::EMAIL_LINK_SAME_BROWSER,
            Rsx_Portal_Two_Factor::_email_link_state($token)['state']
        );
        static::__assert_equals(
            Rsx_Two_Factor_Abstract::EMAIL_LINK_EXPIRED_OR_USED,
            Rsx_Two_Factor::_email_link_state($token)['state'],
            'the staff realm knows nothing of it'
        );

        $signed_in = Rsx_Portal_Two_Factor::verify_challenge(['email_link' => $token]);

        static::__assert_equals((int) $portal_user->id, (int) $signed_in->id);
        static::__assert_true((bool) Portal_User_Model::where('id', $portal_user->id)->value('is_verified'), 'verified');
        static::__assert_true(Portal_Session::is_logged_in(), 'and admitted');
        static::__assert_true(Rsx_Portal_Two_Factor::_challenge_completed_state()['completed']);
    }
}
