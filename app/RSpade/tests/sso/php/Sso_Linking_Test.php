<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Sso\Php;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Sso\Rsx_Sso;
use App\RSpade\Core\Sso\Sso_Failed_Exception;
use App\RSpade\Core\Sso\Sso_Identity_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Tests\Sso\Php\Fake_Sso_Provider;

/**
 * Connections: writing one, refusing one, listing them, and removing them.
 *
 * THE REFUSAL IS THE SECURITY PROPERTY. A provider account already connected to a DIFFERENT
 * identity is refused, because the alternative is two rows for one Google account and a
 * "sign in with Google" that resolves to whichever is read first - an account takeover with
 * no password involved. The unique index enforces the same rule one layer down, so the
 * refusal holds under a race; this file pins the refusal a user would actually meet.
 *
 * IMPERSONATION IS REFUSED, the same rule and the same reason as 2FA enrollment: an
 * administrator viewing an account must never be able to change what that account can sign
 * in with, because the user whose account it is would have no way to see it happen.
 *
 * WHAT IS DELIBERATELY NOT HERE: any check that an account keeps a way to sign in. Whether an
 * account may be left with no password and no connection is application vocabulary, and the
 * framework has no grounds for an opinion.
 */
class Sso_Linking_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    /** Config keys these tests overwrite, restored in teardown. */
    private static array $config_backup = [];

    public static function setup()
    {
        parent::setup();

        self::__override('rsx.sso.custom', [
            'fake' => [
                'enabled' => true,
                'provider' => Fake_Sso_Provider::class,
                'label' => 'Fake Provider',
                'client_id' => 'FAKE-CLIENT',
                'client_secret' => 'FAKE-SECRET',
            ],
        ]);

        Event_Registry::_set_test_handlers('sso.identity.unlinked', [fn ($identity) => '/finish-signup']);

        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    public static function teardown()
    {
        Event_Registry::_clear_test_handlers();

        foreach (self::$config_backup as $key => $value) {
            config()->set($key, $value);
        }

        self::$config_backup = [];

        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    private static function __override(string $key, $value): void
    {
        if (!array_key_exists($key, self::$config_backup)) {
            self::$config_backup[$key] = config($key);
        }

        config()->set($key, $value);
    }

    /**
     * Back to a clean anonymous browser, KEEPING the class-level event handler.
     *
     * Called at the top of EVERY test because setup() and teardown() are per-CLASS, not
     * per-test: the impersonation tests sign somebody in, and the tests after them assert
     * that nobody is.
     */
    private static function __anonymous(): void
    {
        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    private static function __make_login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'ssolink_' . uniqid() . '@example.com';
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    /**
     * Park a pending identity the way a real ceremony would, so link_pending() is exercised
     * against the state it is actually written against.
     */
    private static function __park_pending(string $subject, ?string $email = null): void
    {
        Rsx_Sso::begin('fake');

        $state = Session::get_value(Rsx_Sso::STATE_KEY)['state'];

        $url = '/_sso/fake/callback?' . http_build_query([
            'code' => 'FAKE-CODE',
            'state' => $state,
            Fake_Sso_Provider::IDENTITY_PARAM => json_encode([
                'id' => $subject,
                'email' => $email,
                'name' => 'Fake Person',
            ]),
        ]);

        Rsx_Sso::handle_callback('fake', Request::create($url, 'GET'));
    }

    /**
     * Park a pending identity DIRECTLY, without a ceremony.
     *
     * Needed wherever a test is about a subject that is ALREADY connected, because a real
     * ceremony can no longer produce a pending identity for one - handle_callback() takes the
     * linked branch and signs straight in, which is exactly the behaviour tested elsewhere.
     * The value written here is byte-for-byte what the ceremony parks.
     */
    private static function __park_pending_value(string $subject, ?string $email = null): void
    {
        Session::put_value(
            Rsx_Sso::PENDING_KEY,
            [
                'provider_key' => 'fake',
                'provider_user_key' => $subject,
                'email' => $email,
                'email_verified' => false,
                'name' => 'Fake Person',
                'avatar_url' => null,
            ],
            Rsx_Sso::pending_expires_at()
        );
    }

    // -------------------------------------------------------------------------

    /**
     * The happy path: the pending identity becomes a row, and is consumed so it cannot be
     * redeemed twice.
     */
    public static function test_link_pending_writes_the_row_and_consumes_the_pending_identity()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();

        static::__park_pending('subject-link', 'link@example.com');

        $row = Rsx_Sso::link_pending($login_user);

        static::__assert_equals((int) $login_user->id, (int) $row->login_user_id);
        static::__assert_equals('fake', $row->provider_key);
        static::__assert_equals('subject-link', $row->provider_user_key);
        static::__assert_equals('link@example.com', $row->email, 'the snapshot the settings screen shows');
        static::__assert_null(Rsx_Sso::pending(), 'and the pending identity is spent');
    }

    /**
     * With nothing pending there is nothing to link, and the message is the one a user can
     * act on. It is a Sso_Failed_Exception and not a bare RuntimeException because a
     * finish-registration screen renders it.
     */
    public static function test_link_pending_refuses_when_nothing_is_pending()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();

        static::__assert_throws(Sso_Failed_Exception::class, fn () => Rsx_Sso::link_pending($login_user));
    }

    /**
     * THE TAKEOVER REFUSAL. One provider account, at most one local identity.
     */
    public static function test_an_identity_bound_elsewhere_is_refused()
    {
        static::__anonymous();

        $first = static::__make_login_user();
        $second = static::__make_login_user();

        static::__park_pending('subject-contested', 'contested@example.com');
        Rsx_Sso::link_pending($first);

        static::__park_pending_value('subject-contested', 'contested@example.com');

        static::__assert_throws(
            Sso_Failed_Exception::class,
            fn () => Rsx_Sso::link_pending($second),
            'already connected'
        );

        static::__assert_equals(
            1,
            Sso_Identity_Model::where('provider_user_key', 'subject-contested')->count(),
            'and no second row was written'
        );
    }

    /**
     * Re-linking to the SAME identity is not an error - it refreshes the snapshot, which is
     * what a user who disconnected and reconnected expects.
     */
    public static function test_relinking_the_same_identity_refreshes_the_snapshot()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();

        static::__park_pending('subject-again', 'old@example.com');
        $first = Rsx_Sso::link_pending($login_user);

        static::__park_pending_value('subject-again', 'new@example.com');
        $second = Rsx_Sso::link_pending($login_user);

        static::__assert_equals((int) $first->id, (int) $second->id, 'the same row');
        static::__assert_equals('new@example.com', $second->email, 'with the current snapshot');
    }

    /**
     * An expired pending identity reads as absent, so the window closes on a
     * finish-registration screen somebody walked away from.
     */
    public static function test_an_expired_pending_identity_cannot_be_redeemed()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();

        static::__park_pending('subject-stale', 'stale@example.com');

        Session::put_value(
            Rsx_Sso::PENDING_KEY,
            Rsx_Sso::pending(),
            Rsx_Time::add(Rsx_Time::now_iso(), -60)
        );

        static::__assert_null(Rsx_Sso::pending(), 'an expired window reads as absent');
        static::__assert_throws(Sso_Failed_Exception::class, fn () => Rsx_Sso::link_pending($login_user));
    }

    /**
     * abandon_pending() is what a cancel button calls, and it leaves nothing redeemable.
     */
    public static function test_abandon_pending_discards_the_identity()
    {
        static::__anonymous();

        static::__park_pending('subject-abandon', 'abandon@example.com');

        static::__assert_not_null(Rsx_Sso::pending());

        Rsx_Sso::abandon_pending();

        static::__assert_null(Rsx_Sso::pending());
    }

    /**
     * identities_list() is built for a settings screen, so it is METADATA ONLY and carries
     * nothing a browser has no business holding.
     */
    public static function test_identities_list_is_metadata_only()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();

        static::__park_pending('subject-list', 'list@example.com');
        Rsx_Sso::link_pending($login_user);

        $list = Rsx_Sso::identities_list($login_user);

        static::__assert_count(1, $list);
        static::__assert_equals(
            ['id', 'provider_key', 'provider_label', 'email', 'name', 'last_login_at', 'created_at'],
            array_keys($list[0])
        );
        static::__assert_equals('Fake Provider', $list[0]['provider_label'], 'the label comes from config');
    }

    /**
     * A connection to a provider that has since been switched OFF is still listed and still
     * removable. Hiding it would leave the user unable to remove something that is really
     * there.
     */
    public static function test_a_connection_to_a_disabled_provider_is_still_listed()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();

        static::__park_pending('subject-disabled', 'disabled@example.com');
        Rsx_Sso::link_pending($login_user);

        // Restored explicitly: setup()/teardown() are per-CLASS, so a test that simply
        // switched the provider off would leak the change into every test after it.
        $restore = config('rsx.sso.custom');
        config()->set('rsx.sso.custom', []);

        try {
            $list = Rsx_Sso::identities_list($login_user);

            static::__assert_count(1, $list, 'the row is still theirs');
            static::__assert_equals('fake', $list[0]['provider_label'], 'the key stands in for the label');
        } finally {
            config()->set('rsx.sso.custom', $restore);
        }
    }

    /**
     * Removal is scoped to its own identity, and removing somebody else's row is a NO-OP
     * rather than an error - a stale settings screen is a race, not an attack.
     */
    public static function test_unlink_is_scoped_to_its_own_identity()
    {
        static::__anonymous();

        $owner = static::__make_login_user();
        $stranger = static::__make_login_user();

        static::__park_pending('subject-scoped', 'scoped@example.com');
        $row = Rsx_Sso::link_pending($owner);

        Rsx_Sso::unlink($stranger, (int) $row->id);

        static::__assert_count(1, Rsx_Sso::identities_list($owner), 'a stranger removes nothing');

        Rsx_Sso::unlink($owner, (int) $row->id);

        static::__assert_count(0, Rsx_Sso::identities_list($owner), 'the owner removes their own');
    }

    /**
     * unlink_all() is the operator path and the account-deletion path.
     */
    public static function test_unlink_all_removes_every_connection()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();

        static::__park_pending('subject-all-1', 'a@example.com');
        Rsx_Sso::link_pending($login_user);

        static::__park_pending_value('subject-all-2', 'b@example.com');
        Rsx_Sso::link_pending($login_user);

        static::__assert_count(2, Rsx_Sso::identities_list($login_user));

        Rsx_Sso::unlink_all($login_user);

        static::__assert_count(0, Rsx_Sso::identities_list($login_user));
    }

    /**
     * IMPERSONATION IS REFUSED. An administrator viewing an account must not be able to
     * change what it can sign in with.
     */
    public static function test_unlink_refuses_while_impersonating()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();
        $admin = static::__make_login_user();

        static::__park_pending('subject-imp', 'imp@example.com');
        $row = Rsx_Sso::link_pending($login_user);

        Session::set_login_user_id((int) $login_user->id);
        Session::cli_set_impersonator_login_user_id((int) $admin->id);

        static::__assert_throws(
            RuntimeException::class,
            fn () => Rsx_Sso::unlink($login_user, (int) $row->id),
            'impersonating'
        );

        Session::cli_set_impersonator_login_user_id(null);

        static::__assert_count(1, Rsx_Sso::identities_list($login_user), 'and nothing was removed');
    }

    /**
     * unlink_all() does NOT refuse while impersonating, because its caller is a shell - a
     * strictly higher privilege than any session, with no impersonation question to ask.
     * Pinned so the asymmetry is deliberate rather than an oversight somebody "fixes".
     */
    public static function test_unlink_all_is_the_operator_path_and_does_not_consult_impersonation()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();
        $admin = static::__make_login_user();

        static::__park_pending('subject-imp-all', 'impall@example.com');
        Rsx_Sso::link_pending($login_user);

        Session::set_login_user_id((int) $login_user->id);
        Session::cli_set_impersonator_login_user_id((int) $admin->id);

        Rsx_Sso::unlink_all($login_user);

        Session::cli_set_impersonator_login_user_id(null);

        static::__assert_count(0, Rsx_Sso::identities_list($login_user));
    }

    /**
     * Starting a LINK ceremony requires a signed-in identity - "connect my Google account to
     * that account over there" is not an operation this subsystem offers.
     */
    public static function test_a_link_ceremony_requires_a_signed_in_identity()
    {
        static::__anonymous();

        static::__assert_throws(
            RuntimeException::class,
            fn () => Rsx_Sso::begin('fake', Rsx_Sso::INTENT_LINK),
            'signed-in identity'
        );
    }

    /**
     * And it refuses while impersonating, before the browser ever leaves for the provider.
     */
    public static function test_a_link_ceremony_refuses_while_impersonating()
    {
        static::__anonymous();

        $login_user = static::__make_login_user();
        $admin = static::__make_login_user();

        Session::set_login_user_id((int) $login_user->id);
        Session::cli_set_impersonator_login_user_id((int) $admin->id);

        static::__assert_throws(
            RuntimeException::class,
            fn () => Rsx_Sso::begin('fake', Rsx_Sso::INTENT_LINK),
            'impersonating'
        );

        Session::cli_set_impersonator_login_user_id(null);
    }
}
