<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Sso\Cli;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Session\Session;
use App\RSpade\Core\Sso\Sso_Identity_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Sso\Php\Fake_Sso_Provider;

/**
 * rsx:users:sso:dump / :unlink - the operator path, end to end.
 *
 * THE SUBJECT IS THE OPERATOR'S TRUST IN WHAT THESE COMMANDS PRINT. Both make a claim that
 * nothing will contradict until somebody tries to sign in: dump says "these are the accounts
 * that can reach this identity", unlink says "that one cannot any more". A connection missing
 * from a dump, or an id reported disconnected that belonged to somebody else, is an operator
 * who believes an account is secured when it is not - which is why the ownership refusal and
 * the "no longer configured" row both have tests of their own rather than being left to read
 * correctly.
 *
 * THE DISABLED-PROVIDER ROW IS THE ONE THAT WOULD BE MISSED. Switching a provider off does
 * not delete anything, so a connection can outlive its configuration; the browser's list lets
 * the provider key stand in for a label, and a terminal must not, because an operator reading
 * a bare 'google' has no way to tell a live connection from a dormant one.
 *
 * Run in-process through Artisan::call(): these commands write ordinary rows on this
 * connection, so the per-test transaction rolls every connection back.
 */
class Sso_Cli_Test extends Rsx_Test_Abstract
{
    private const PASSWORD = 'correct-horse-battery-staple';

    /** Config keys these tests overwrite, restored in teardown. */
    private static array $config_backup = [];

    public static function setup()
    {
        parent::setup();

        // ONE provider live, so a dump can prove it prints the configured label - and every
        // other provider_key a row might carry is therefore a dormant connection.
        self::__override('rsx.sso.custom', [
            'fake' => [
                'enabled' => true,
                'provider' => Fake_Sso_Provider::class,
                'label' => 'Fake Provider',
                'client_id' => 'FAKE-CLIENT',
                'client_secret' => 'FAKE-SECRET',
            ],
        ]);

        Session::cli_set_impersonator_login_user_id(null);
        Session::logout();
        static::__reset_session();
    }

    public static function teardown()
    {
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
     * A live login identity, connected to nothing.
     */
    private static function __make_login_user(): Login_User_Model
    {
        $login_user = new Login_User_Model();
        $login_user->email = 'sso_cli_' . uniqid() . '@example.com';
        $login_user->password = Hash::make(self::PASSWORD);
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        return $login_user;
    }

    /**
     * One connection, written directly: what the ceremony would have left behind, without
     * running a ceremony to get it. The ceremony has its own tests.
     */
    private static function __connect(
        Login_User_Model $login_user,
        string $provider_key,
        ?string $email = null
    ): Sso_Identity_Model {
        $identity = new Sso_Identity_Model();
        $identity->login_user_id = $login_user->id;
        $identity->provider_key = $provider_key;
        $identity->provider_user_key = 'subject-' . uniqid();
        $identity->email = $email;
        $identity->name = 'Test Person';
        $identity->save();

        return $identity;
    }

    /**
     * Run a command with --json, returning [exit_code, decoded_envelope].
     */
    private static function __json_call(string $command, array $args): array
    {
        $code = Artisan::call($command, $args + ['--json' => true]);

        return [$code, json_decode(Artisan::output(), true)];
    }

    /**
     * Run a command in its human form, returning [exit_code, output].
     */
    private static function __human_call(string $command, array $args): array
    {
        $code = Artisan::call($command, $args);

        return [$code, Artisan::output()];
    }

    /**
     * How many connections this identity actually holds, read past the commands.
     */
    private static function __count_connections(Login_User_Model $login_user): int
    {
        return Sso_Identity_Model::where('login_user_id', $login_user->id)->count();
    }

    // -------------------------------------------------------------------------
    // dump
    // -------------------------------------------------------------------------

    /**
     * sso-cli-01: dump reports every connection with the metadata a support call needs, and
     * an email names the same identity an id does.
     */
    public static function test_dump_round_trips_a_connection()
    {
        $login_user = static::__make_login_user();
        $identity = static::__connect($login_user, 'fake', 'connected@example.com');

        [$code, $envelope] = static::__json_call('rsx:users:sso:dump', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(0, $code);
        static::__assert_true($envelope['ok']);
        static::__assert_equals((int) $login_user->id, $envelope['data']['user']['id']);
        static::__assert_count(1, $envelope['data']['identities']);

        $row = $envelope['data']['identities'][0];

        static::__assert_equals((int) $identity->id, $row['id']);
        static::__assert_equals('fake', $row['provider_key']);
        static::__assert_equals('Fake Provider', $row['provider_label'], 'the configured label');
        static::__assert_equals('connected@example.com', $row['email']);
        static::__assert_equals('Test Person', $row['name']);
        static::__assert_null($row['last_login_at'], 'never signed in through it yet');
        static::__assert_not_null($row['created_at']);

        // NOTHING SECRET IS PRINTED, because there is nothing secret to print: the table
        // holds no token. A field appearing here later would be one worth arguing about.
        static::__assert_equals(
            ['id', 'provider_key', 'provider_label', 'email', 'name', 'last_login_at', 'created_at'],
            array_keys($row),
            'exactly the metadata fields'
        );

        [$by_email_code, $by_email] = static::__json_call('rsx:users:sso:dump', [
            '--user' => $login_user->email,
        ]);

        static::__assert_equals(0, $by_email_code);
        static::__assert_equals((int) $login_user->id, $by_email['data']['user']['id']);
        static::__assert_count(1, $by_email['data']['identities']);

        [$human_code, $output] = static::__human_call('rsx:users:sso:dump', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(0, $human_code);
        static::__assert_contains('[OK] 1 connected account', $output);
        static::__assert_contains('  User:    ' . $login_user->id, $output, 'the house identity header');
        static::__assert_contains('Fake Provider', $output);
        static::__assert_contains('connected@example.com', $output);
    }

    /**
     * sso-cli-04: a connection whose provider is no longer configured is REPORTED AS SUCH,
     * never as a live one and never as an error. Switching a provider off deletes nothing.
     */
    public static function test_dump_names_a_connection_whose_provider_is_gone()
    {
        $login_user = static::__make_login_user();
        static::__connect($login_user, 'fake', 'live@example.com');
        static::__connect($login_user, 'google', 'dormant@example.com');

        [$code, $envelope] = static::__json_call('rsx:users:sso:dump', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(0, $code);
        static::__assert_count(2, $envelope['data']['identities'], 'a dormant connection is still a connection');

        $by_key = [];

        foreach ($envelope['data']['identities'] as $identity) {
            $by_key[$identity['provider_key']] = $identity;
        }

        static::__assert_equals('Fake Provider', $by_key['fake']['provider_label']);
        static::__assert_null(
            $by_key['google']['provider_label'],
            'null, never the key standing in for a label a script would read as configured'
        );

        [$human_code, $output] = static::__human_call('rsx:users:sso:dump', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(0, $human_code);
        static::__assert_contains('(no longer configured)', $output);
    }

    /**
     * sso-cli-05: an identity connected to nothing is a normal, SUCCESSFUL answer. This is a
     * state dump, not an assertion, and a non-zero exit would make every script treat an
     * ordinary password-only account as a failure.
     */
    public static function test_dump_of_an_unconnected_identity_is_empty_and_succeeds()
    {
        $login_user = static::__make_login_user();

        [$code, $envelope] = static::__json_call('rsx:users:sso:dump', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(0, $code, 'zero connections is not a failure');
        static::__assert_true($envelope['ok']);
        static::__assert_equals([], $envelope['data']['identities']);

        [$human_code, $output] = static::__human_call('rsx:users:sso:dump', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(0, $human_code);
        static::__assert_contains('[OK] No connected accounts', $output);
    }

    // -------------------------------------------------------------------------
    // unlink
    // -------------------------------------------------------------------------

    /**
     * sso-cli-02: --id disconnects exactly the connection named, and --all takes what is
     * left. Neither prompts: a disconnection is re-created by signing in with the provider
     * once, so there is nothing here worth stopping a script for.
     */
    public static function test_unlink_by_id_then_all()
    {
        $login_user = static::__make_login_user();
        $first = static::__connect($login_user, 'fake', 'first@example.com');
        static::__connect($login_user, 'google', 'second@example.com');

        [$code, $envelope] = static::__json_call('rsx:users:sso:unlink', [
            '--user' => (string) $login_user->id,
            '--id' => (string) $first->id,
        ]);

        static::__assert_equals(0, $code);
        static::__assert_equals('removed', $envelope['data']['action']);
        static::__assert_count(1, $envelope['data']['removed']);
        static::__assert_equals((int) $first->id, $envelope['data']['removed'][0]['id']);
        static::__assert_equals('fake', $envelope['data']['removed'][0]['provider_key']);
        static::__assert_equals(1, static::__count_connections($login_user), 'the other one survives');

        [$all_code, $all] = static::__json_call('rsx:users:sso:unlink', [
            '--user' => (string) $login_user->id,
            '--all' => true,
        ]);

        static::__assert_equals(0, $all_code);
        static::__assert_equals('removed_all', $all['data']['action']);
        static::__assert_count(1, $all['data']['removed']);
        static::__assert_equals('google', $all['data']['removed'][0]['provider_key']);
        static::__assert_equals(0, static::__count_connections($login_user), 'nothing is left');

        // Nothing left to do is still a success, and it says so rather than inventing a row.
        [$again_code, $again] = static::__json_call('rsx:users:sso:unlink', [
            '--user' => (string) $login_user->id,
            '--all' => true,
        ]);

        static::__assert_equals(0, $again_code);
        static::__assert_equals('none', $again['data']['action']);
        static::__assert_equals([], $again['data']['removed']);
    }

    /**
     * sso-cli-06: the human form reports what it removed and what is left, so an operator
     * working an account by eye never has to run dump again to find out.
     */
    public static function test_unlink_human_output_reports_what_went_and_what_stayed()
    {
        $login_user = static::__make_login_user();
        $first = static::__connect($login_user, 'fake', 'first@example.com');
        static::__connect($login_user, 'google', 'second@example.com');

        [$code, $output] = static::__human_call('rsx:users:sso:unlink', [
            '--user' => (string) $login_user->id,
            '--id' => (string) $first->id,
        ]);

        static::__assert_equals(0, $code);
        static::__assert_contains('[OK] Disconnected 1 connected account', $output);
        static::__assert_contains('first@example.com', $output);
        static::__assert_contains('1 connected account remaining.', $output);
        static::__assert_equals(1, static::__count_connections($login_user));
    }

    /**
     * sso-cli-03: NEITHER FLAG IS DEFAULTED TO EITHER MEANING, and both together is refused
     * too. Guessing --all would disconnect an entire account where one connection was meant.
     */
    public static function test_unlink_requires_exactly_one_of_id_and_all()
    {
        $login_user = static::__make_login_user();
        $identity = static::__connect($login_user, 'fake', 'kept@example.com');

        [$neither_code, $neither] = static::__json_call('rsx:users:sso:unlink', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(1, $neither_code);
        static::__assert_false($neither['ok']);
        static::__assert_equals('invalid_options', $neither['error']['code']);
        static::__assert_contains('--id', $neither['error']['message'], 'it names both flags');
        static::__assert_contains('--all', $neither['error']['message']);

        [$both_code, $both] = static::__json_call('rsx:users:sso:unlink', [
            '--user' => (string) $login_user->id,
            '--id' => (string) $identity->id,
            '--all' => true,
        ]);

        static::__assert_equals(1, $both_code);
        static::__assert_equals('invalid_options', $both['error']['code']);
        static::__assert_contains('--id', $both['error']['message']);
        static::__assert_contains('--all', $both['error']['message']);

        [$human_code, $output] = static::__human_call('rsx:users:sso:unlink', [
            '--user' => (string) $login_user->id,
        ]);

        static::__assert_equals(1, $human_code, 'non-zero for a human too');
        static::__assert_contains('[ERROR]', $output);

        static::__assert_equals(1, static::__count_connections($login_user), 'and nothing was removed');
    }

    /**
     * sso-cli-07: THE OWNERSHIP CHECK. Rsx_Sso::unlink() treats another identity's row as a
     * no-op, which would print "disconnected" over a connection that is still live - so the
     * id is resolved against this identity first and a miss is an error.
     */
    public static function test_unlink_refuses_another_identitys_connection()
    {
        $victim = static::__make_login_user();
        $victim_identity = static::__connect($victim, 'fake', 'victim@example.com');

        $other = static::__make_login_user();
        static::__connect($other, 'google', 'other@example.com');

        [$code, $envelope] = static::__json_call('rsx:users:sso:unlink', [
            '--user' => (string) $other->id,
            '--id' => (string) $victim_identity->id,
        ]);

        static::__assert_equals(1, $code, 'refused, never a silent no-op');
        static::__assert_false($envelope['ok']);
        static::__assert_equals('identity_not_found', $envelope['error']['code']);
        static::__assert_contains('rsx:users:sso:dump', $envelope['error']['message'], 'it names the way to look');

        static::__assert_equals(1, static::__count_connections($victim), 'the victim keeps their connection');
        static::__assert_equals(1, static::__count_connections($other), 'and the caller keeps theirs');
    }

    // -------------------------------------------------------------------------
    // Argument failures, in both output forms
    // -------------------------------------------------------------------------

    /**
     * sso-cli-08: an unresolvable --user is a non-zero exit in BOTH forms - a script that gets
     * parseable JSON back on a failure but a zero exit carries on as though an account had
     * been disconnected.
     */
    public static function test_unknown_user_fails_loudly_in_both_forms()
    {
        // --all only exists on unlink; dump would reject an option it does not declare, which
        // would be a Symfony parse error and not the refusal under test.
        foreach (['rsx:users:sso:dump' => [], 'rsx:users:sso:unlink' => ['--all' => true]] as $command => $extra) {
            [$json_code, $envelope] = static::__json_call($command, $extra + [
                '--user' => 'nobody_' . uniqid() . '@example.com',
            ]);

            static::__assert_equals(1, $json_code, $command . ' exits non-zero');
            static::__assert_false($envelope['ok']);
            static::__assert_equals('user_not_found', $envelope['error']['code']);

            [$human_code, $output] = static::__human_call($command, $extra + [
                '--user' => '99999999',
            ]);

            static::__assert_equals(1, $human_code, $command . ' exits non-zero for a human too');
            static::__assert_contains('[ERROR]', $output);
        }
    }

    /**
     * sso-cli-09: --user is REQUIRED and is never defaulted - a command that quietly picked
     * identity 1 would disconnect the wrong account, and this one destroys rows.
     */
    public static function test_user_is_required_and_never_defaulted()
    {
        $before = Sso_Identity_Model::where('login_user_id', 1)->count();

        foreach (['rsx:users:sso:dump' => [], 'rsx:users:sso:unlink' => ['--all' => true]] as $command => $extra) {
            [$code, $envelope] = static::__json_call($command, $extra);

            static::__assert_equals(1, $code);
            static::__assert_equals('user_required', $envelope['error']['code']);
            static::__assert_contains('--user', $envelope['error']['message']);
        }

        static::__assert_equals(
            $before,
            Sso_Identity_Model::where('login_user_id', 1)->count(),
            'and identity 1 was never touched'
        );
    }
}
