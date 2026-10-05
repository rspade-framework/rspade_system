<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Api\Php;

use Illuminate\Http\Request;
use App\RSpade\Core\Api\Api_Key_Model;
use App\RSpade\Core\Api\Rsx_Api_Bearer;
use App\RSpade\Core\Dispatch\Dispatcher;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Core\Manifest\Manifest;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Api\Php\Api_Main_Hook_Probe;

/**
 * An API key on a file-serving web route (/_download, /_inline, /_download_zip,
 * /_thumbnail/*, /_preview/*) is authenticated by the page Dispatcher as soon as the route
 * matches - BEFORE the gates and BEFORE the application's Main::pre_dispatch - so the
 * application's account policy sees the key's holder exactly as it does on /api/vN, and a
 * non-null answer is the API's own 403 account_refused. A soft-deleted login identity's key
 * authenticates nowhere.
 *
 * Driven in-process through Dispatcher::dispatch() with a Bearer request. In the CLI harness a
 * declared test identity makes Session::has_session() true, which the bearer seam reads as "a
 * browser session is present, leave it alone" - so each dispatch first clears the declaration
 * (__reset_session()). The application's Main is swapped for Api_Main_Hook_Probe for one
 * dispatch at a time, as Api_Main_Pre_Dispatch_Test does for the API.
 *
 * Behavior of record: php artisan rsx:man external_api.
 */
class Api_File_Route_Bearer_Test extends Rsx_Test_Abstract
{
    /** @var array<int> attachments created by the class, removed in teardown. */
    private static $created_attachment_ids = [];

    /** The user every gate handler was handed, in call order. */
    private static array $gate_users = [];

    public static function setup()
    {
        config(['rsx.search.enabled' => false]);
    }

    public static function teardown()
    {
        Event_Registry::_clear_test_handlers();
        Api_Main_Hook_Probe::$refuse_with = null;
        Api_Main_Hook_Probe::$seen = [];

        foreach (static::$created_attachment_ids as $id) {
            $attachment = File_Attachment_Model::without_site_scope(fn () => File_Attachment_Model::find($id));
            if ($attachment) {
                $attachment->delete();
            }
        }
        static::$created_attachment_ids = [];

        config(['rsx.search.enabled' => true]);
    }

    private static function __key_user(): User_Model
    {
        $user = User_Model::without_site_scope(fn () => User_Model::find(1));
        static::__assert_not_null($user, 'the test baseline carries user 1');

        return $user;
    }

    private static function __mint_key(): string
    {
        // A previous dispatch cleared the CLI identity declaration; the save below needs the
        // key holder's site declared again.
        static::__acting_as_user(1);

        $user = static::__key_user();
        $user->is_api_access_enabled = 1;
        $user->save();

        return Api_Key_Model::generate(1, 'File route bearer probe (test)')['key'];
    }

    private static function __attachment(): File_Attachment_Model
    {
        $site_id = (int) static::__key_user()->site_id;
        $bytes = "file route bearer fixture " . bin2hex(random_bytes(12)) . "\n";
        $attachment = File_Attachment_Model::create_from_string($bytes, 'bearer_fixture.txt', ['site_id' => $site_id]);
        static::$created_attachment_ids[] = $attachment->id;

        return $attachment;
    }

    private static function __allow_reads_and_record(): void
    {
        static::$gate_users = [];
        $record = static function ($data) {
            static::$gate_users[] = $data['user'] ? (int) $data['user']->id : null;

            return true;
        };
        Event_Registry::_set_test_handlers('file.thumbnail.authorize', [$record]);
        Event_Registry::_set_test_handlers('file.download.authorize', [$record]);
    }

    /**
     * GET a path through the page Dispatcher as a web request carrying a Bearer key, with the
     * probe standing in as the application's Main.
     */
    private static function __dispatch(string $path, string $key)
    {
        $request = Request::create($path, 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        Rsx_Request_Channel::classify($request);

        // No browser session: the key is the only credential on this request.
        static::__reset_session();

        $index = &Manifest::$data['data']['php_subclass_index'];
        $original = $index['Main_Abstract'] ?? null;
        $index['Main_Abstract'] = ['Api_Main_Hook_Probe'];

        try {
            return Dispatcher::dispatch($path, 'GET', [], $request);
        } finally {
            if ($original === null) {
                unset($index['Main_Abstract']);
            } else {
                $index['Main_Abstract'] = $original;
            }
        }
    }

    // API-FILE-BEARER-POLICY-SEES-HOLDER: Main::pre_dispatch runs with the key's identity
    // already established, and the gates see the key's user.
    public static function test_the_account_policy_sees_the_key_holder_on_a_file_route()
    {
        $key = static::__mint_key();
        $attachment = static::__attachment();
        static::__allow_reads_and_record();

        Api_Main_Hook_Probe::$seen = [];
        Api_Main_Hook_Probe::$refuse_with = null;

        $response = static::__dispatch('/_download/' . $attachment->key, $key);
        $seen_identity = Session::is_api_request() ? Session::get_user_id() : null;

        static::__assert_equals(200, $response->getStatusCode(), 'the key downloads the file');
        static::__assert_count(1, Api_Main_Hook_Probe::$seen, 'the application hook ran exactly once');
        static::__assert_contains('File_Attachment_Controller', (string) (Api_Main_Hook_Probe::$seen[0]['_handler'] ?? ''));
        static::__assert_equals(1, $seen_identity, 'the request carries the key holder as a headless API identity');
        static::__assert_true(in_array(1, static::$gate_users, true), 'the file gates saw the key holder');
    }

    // API-FILE-BEARER-ACCOUNT-REFUSED: a non-null Main::pre_dispatch for a key on a file route
    // is the API's 403 account_refused, and the file is never served.
    public static function test_a_refusing_account_policy_is_403_account_refused_on_a_file_route()
    {
        $key = static::__mint_key();
        $attachment = static::__attachment();
        static::__allow_reads_and_record();

        Api_Main_Hook_Probe::$seen = [];
        Api_Main_Hook_Probe::$refuse_with = false;

        try {
            $response = static::__dispatch('/_download/' . $attachment->key, $key);
        } finally {
            Api_Main_Hook_Probe::$refuse_with = null;
        }

        static::__assert_equals(403, $response->getStatusCode(), 'a suspended account is refused');
        $body = json_decode((string) $response->getContent(), true);
        static::__assert_equals('account_refused', $body['error']['code'] ?? null, 'the same code the API answers');
        static::__assert_count(0, static::$gate_users, 'no file gate ran - the action never started');
    }

    // API-FILE-BEARER-BAD-KEY: a bad key on a file route denies before the application hook.
    public static function test_a_bad_key_on_a_file_route_is_401_before_the_hook()
    {
        $attachment = static::__attachment();
        static::__allow_reads_and_record();
        Api_Main_Hook_Probe::$seen = [];

        $response = static::__dispatch('/_download/' . $attachment->key, 'rsx_not_a_real_key');

        static::__assert_equals(401, $response->getStatusCode());
        static::__assert_count(0, Api_Main_Hook_Probe::$seen, 'the hook runs only after the key authenticated');
    }

    // API-FILE-BEARER-SURFACES: the accepting surfaces are exactly the file-serving routes, each
    // a GET-only route in both realms (the read-only reasoning depends on it), and nothing else.
    public static function test_every_accepting_surface_is_a_get_only_file_route_in_both_realms()
    {
        $surfaces = (new \ReflectionClassConstant(Rsx_Api_Bearer::class, 'FILE_ROUTE_SURFACES'))->getValue();
        static::__assert_count(7, $surfaces, 'download, inline, zip, two thumbnails, two previews');

        $tables = [
            'staff' => Manifest::get_routes(),
            'portal' => Manifest::get_full_manifest()['data']['portal_routes'] ?? [],
        ];

        foreach ($surfaces as $surface) {
            static::__assert_true(Rsx_Api_Bearer::is_file_route($surface), "{$surface} accepts a key");

            foreach ($tables as $realm => $routes) {
                $rows = array_filter($routes, fn ($row) => ($row['surface'] ?? null) === $surface);
                static::__assert_count(1, $rows, "{$surface} is one {$realm} route");
                static::__assert_equals(['GET'], array_values(array_values($rows)[0]['methods']), "{$surface} is GET-only in the {$realm} realm");
            }
        }

        static::__assert_false(Rsx_Api_Bearer::is_file_route('File_Attachment_Controller::upload'), 'the upload route does not take a key');
        static::__assert_false(Rsx_Api_Bearer::is_file_route('File_Preview_Controller::get_preview_info'), 'an Ajax endpoint does not take a key');
    }

    // API-KEY-TRASHED-LOGIN: a soft-deleted login identity's key stops authenticating.
    public static function test_a_soft_deleted_login_identity_key_is_refused()
    {
        $key = static::__mint_key();
        $request = Request::create('/api/v1/me', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $key]);

        $login_user = Login_User_Model::find((int) static::__key_user()->login_user_id);
        static::__assert_not_null($login_user, 'the key holder has a live login identity');
        $login_user->delete();

        $auth = Rsx_Api_Bearer::authenticate($request);

        static::__assert_not_null($auth['error'], 'the key no longer authenticates');
        static::__assert_equals('unauthorized', $auth['error'][0], 'the uniform refusal - a caller never learns why');
        static::__assert_false(Session::is_api_request(), 'no identity was established');
    }
}
