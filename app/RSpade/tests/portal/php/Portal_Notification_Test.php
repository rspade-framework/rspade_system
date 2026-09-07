<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Portal\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Models\Portal_Notification_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Tests for the T4 portal activity/notification primitive:
 *   - Portal_Notification_Model::emit() / feed() / unread_count() / mark_read() /
 *     mark_all_read()
 *   - site-scoping isolation (site A invisible in site B)
 *   - per-user isolation (user X never sees user Y's notifications)
 *   - the portal fetch endpoint returns ONLY the caller's own feed
 *   - clients.portal_last_activity_at is stamped on an authenticated portal request
 *
 * Site-scoped models scope by the STAFF Session site_id, so each test aligns that
 * via __acting_as_site() for seeding/querying; the portal IDENTITY is set via
 * Portal_Session CLI setters. Runs in the default per-test transaction.
 */
class Portal_Notification_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    private static function __make_portal_user(): Portal_User_Model
    {
        $user = new Portal_User_Model();
        $user->site_id = self::SITE_ID;
        $user->email = 'notif_' . uniqid() . '@example.com';
        $user->set_password('secret-password');
        $user->is_verified = true;
        $user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $user->save();

        return $user;
    }

    private static function __login_portal(int $portal_user_id, int $site_id = self::SITE_ID): void
    {
        Portal_Session::set_site_id($site_id);
        Portal_Session::cli_set_portal_user_id($portal_user_id);
    }

    public static function setup(): void
    {
        static::__acting_as_site(self::SITE_ID);
    }

    // =====================================================================
    // emit -> feed
    // =====================================================================

    public static function test_emit_then_feed_returns_notification()
    {
        $user = static::__make_portal_user();

        $created = Portal_Notification_Model::emit($user->id, 'announcement', [
            'payload' => ['title' => 'Welcome', 'body' => 'Hello there'],
        ]);
        static::__assert_count(1, $created, 'emit to one recipient writes one row');

        $feed = Portal_Notification_Model::feed($user->id);
        static::__assert_count(1, $feed, 'recipient feed contains the emitted notification');
        static::__assert_equals('announcement', $feed[0]->type);
        static::__assert_equals('Welcome', $feed[0]->payload['title'] ?? null);
        static::__assert_null($feed[0]->read_at, 'new notification is unread');
    }

    public static function test_emit_to_many_writes_one_row_each()
    {
        $a = static::__make_portal_user();
        $b = static::__make_portal_user();
        $c = static::__make_portal_user();

        $created = Portal_Notification_Model::emit([$a->id, $b->id, $c->id], 'broadcast');
        static::__assert_count(3, $created, 'emit to N recipients writes N rows');

        static::__assert_count(1, Portal_Notification_Model::feed($a->id));
        static::__assert_count(1, Portal_Notification_Model::feed($b->id));
        static::__assert_count(1, Portal_Notification_Model::feed($c->id));
    }

    public static function test_emit_with_polymorphic_subject_roundtrips()
    {
        $user = static::__make_portal_user();

        Portal_Notification_Model::emit($user->id, 'request_opened', [
            'subject_type' => 'Portal_User_Model',
            'subject_id' => $user->id,
        ]);

        $feed = Portal_Notification_Model::feed($user->id);
        static::__assert_equals('Portal_User_Model', $feed[0]->subject_type, 'subject_type reads back as class basename');
        static::__assert_equals($user->id, (int) $feed[0]->subject_id);
    }

    // =====================================================================
    // unread_count / mark_read / mark_all_read
    // =====================================================================

    public static function test_unread_count_and_mark_read_transitions()
    {
        $user = static::__make_portal_user();
        $rows = Portal_Notification_Model::emit($user->id, 'announcement');
        $rows2 = Portal_Notification_Model::emit($user->id, 'announcement');

        static::__assert_equals(2, Portal_Notification_Model::unread_count($user->id), 'two unread to start');

        $changed = Portal_Notification_Model::mark_read($user->id, $rows[0]->id);
        static::__assert_true($changed, 'mark_read flips an unread notification');
        static::__assert_equals(1, Portal_Notification_Model::unread_count($user->id), 'one unread after marking one');

        // Idempotent: marking an already-read row reports no change.
        $changed_again = Portal_Notification_Model::mark_read($user->id, $rows[0]->id);
        static::__assert_false($changed_again, 'mark_read is idempotent');
        static::__assert_equals(1, Portal_Notification_Model::unread_count($user->id));
    }

    public static function test_mark_all_read()
    {
        $user = static::__make_portal_user();
        Portal_Notification_Model::emit($user->id, 'announcement');
        Portal_Notification_Model::emit($user->id, 'announcement');
        Portal_Notification_Model::emit($user->id, 'announcement');

        $count = Portal_Notification_Model::mark_all_read($user->id);
        static::__assert_equals(3, $count, 'mark_all_read marks every unread row');
        static::__assert_equals(0, Portal_Notification_Model::unread_count($user->id));
    }

    public static function test_mark_read_cannot_touch_another_users_notification()
    {
        $owner = static::__make_portal_user();
        $attacker = static::__make_portal_user();
        $rows = Portal_Notification_Model::emit($owner->id, 'announcement');

        // Attacker attempts to mark the owner's notification read.
        $changed = Portal_Notification_Model::mark_read($attacker->id, $rows[0]->id);
        static::__assert_false($changed, 'a user cannot mark another user notification read');
        static::__assert_equals(1, Portal_Notification_Model::unread_count($owner->id), 'owner notification stays unread');
    }

    // =====================================================================
    // since (what's new since last login)
    // =====================================================================

    public static function test_feed_since_filter()
    {
        $user = static::__make_portal_user();

        $old = Portal_Notification_Model::emit($user->id, 'announcement')[0];
        // Backdate the first notification well into the past.
        $old->created_at = now()->subDays(2);
        $old->save();

        Portal_Notification_Model::emit($user->id, 'announcement');

        $cutoff = now()->subDay()->toIso8601String();
        $recent = Portal_Notification_Model::feed($user->id, ['since' => $cutoff]);
        static::__assert_count(1, $recent, 'since returns only notifications newer than the cutoff');
    }

    // =====================================================================
    // per-user isolation
    // =====================================================================

    public static function test_per_user_isolation()
    {
        $x = static::__make_portal_user();
        $y = static::__make_portal_user();

        Portal_Notification_Model::emit($x->id, 'announcement');

        static::__assert_count(1, Portal_Notification_Model::feed($x->id), 'X sees their own notification');
        static::__assert_count(0, Portal_Notification_Model::feed($y->id), 'Y sees none of X notifications');
        static::__assert_equals(0, Portal_Notification_Model::unread_count($y->id));
    }

    // =====================================================================
    // site-scoping isolation
    // =====================================================================

    public static function test_site_scoping_isolation()
    {
        // Emit a notification in site A for a recipient id, explicitly scoped to A.
        $user = static::__make_portal_user();
        Portal_Notification_Model::emit($user->id, 'announcement', ['site_id' => self::SITE_ID]);

        // Querying from site A sees it.
        static::__assert_count(1, Portal_Notification_Model::feed($user->id), 'visible within its own site');

        // Move the staff Session (which drives the model global scope) to another
        // site; the site-A notification must be invisible there.
        Session::set_site_id(self::SITE_ID + 999);
        static::__assert_count(0, Portal_Notification_Model::feed($user->id), 'notification in site A invisible from site B');
        static::__assert_equals(0, Portal_Notification_Model::unread_count($user->id));

        Session::set_site_id(self::SITE_ID);
    }

    // =====================================================================
    // fetch endpoint (returns only the caller's own feed)
    // =====================================================================

    public static function test_endpoint_returns_only_callers_feed()
    {
        $caller = static::__make_portal_user();
        $other = static::__make_portal_user();

        Portal_Notification_Model::emit($caller->id, 'announcement', ['payload' => ['title' => 'Mine']]);
        Portal_Notification_Model::emit($other->id, 'announcement', ['payload' => ['title' => 'Theirs']]);

        static::__login_portal($caller->id);

        $response = Portal_Notifications_Controller::feed(Request::create('/'), []);
        static::__assert_count(1, $response['notifications'], 'endpoint returns only the caller feed');
        static::__assert_equals('Mine', $response['notifications'][0]['payload']['title'] ?? null);
        static::__assert_equals(1, $response['unread_count'], 'endpoint reports caller unread count only');
    }

    public static function test_endpoint_mark_read_and_mark_all_read()
    {
        $caller = static::__make_portal_user();
        $rows = Portal_Notification_Model::emit($caller->id, 'announcement');
        Portal_Notification_Model::emit($caller->id, 'announcement');

        static::__login_portal($caller->id);

        $marked = Portal_Notifications_Controller::mark_read(Request::create('/'), ['id' => $rows[0]->id]);
        static::__assert_true($marked['changed'], 'endpoint mark_read flips the notification');
        static::__assert_equals(1, $marked['unread_count'], 'endpoint reports updated unread count');

        $all = Portal_Notifications_Controller::mark_all_read(Request::create('/'), []);
        static::__assert_equals(1, $all['count'], 'endpoint mark_all_read marks the remaining unread');
        static::__assert_equals(0, $all['unread_count']);
    }

    public static function test_endpoint_mark_read_rejects_foreign_notification()
    {
        $caller = static::__make_portal_user();
        $other = static::__make_portal_user();
        $other_rows = Portal_Notification_Model::emit($other->id, 'announcement');

        static::__login_portal($caller->id);

        // Caller tries to mark a notification that belongs to another user.
        $result = Portal_Notifications_Controller::mark_read(Request::create('/'), ['id' => $other_rows[0]->id]);
        static::__assert_false($result['changed'], 'endpoint refuses to mark a foreign notification');
        static::__assert_equals(1, Portal_Notification_Model::unread_count($other->id), 'foreign notification stays unread');
    }

    // =====================================================================
    // portal_last_activity_at stamping (essential #12)
    // =====================================================================

    public static function test_portal_last_activity_at_is_stamped()
    {
        $client = new Client_Model();
        $client->name = 'Activity Client';
        $client->save();
        static::__assert_null($client->portal_last_activity_at, 'starts unstamped');

        $user = static::__make_portal_user();
        $membership = new Portal_Membership_Model();
        $membership->portal_user_id = $user->id;
        $membership->client_id = $client->id;
        $membership->role_id = Portal_Membership_Model::ROLE_VIEWER;
        $membership->save();

        // Drive the REAL hook: an authenticated portal request runs
        // Portal_Main::pre_dispatch for a non-exempt handler, which stamps activity
        // on every client the portal user belongs to.
        static::__login_portal($user->id);

        $result = Portal_Main::pre_dispatch(
            Request::create('/'),
            ['_handler' => 'Portal_Notifications_Controller']
        );
        static::__assert_null($result, 'authenticated portal request continues dispatch');

        $reloaded = Client_Model::find($client->id);
        static::__assert_not_empty($reloaded->portal_last_activity_at, 'portal_last_activity_at is written after an authenticated portal request');
    }

    public static function teardown(): void
    {
        Portal_Session::cli_set_portal_user_id(0);
        static::__reset_session();
    }
}
