<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Companions\Php;

use Symfony\Component\Process\Process;
use App\RSpade\Core\Ajax\Exceptions\AjaxUnauthorizedException;
use App\RSpade\Core\Companion\Companion_Changed_Topic;
use App\RSpade\Core\Companion\Companion_Flags;
use App\RSpade\Core\Companion\Companion_Gates;
use App\RSpade\Core\Companion\Companion_Health_Checks;
use App\RSpade\Core\Companion\Companion_Output_Topic;
use App\RSpade\Core\Companion\Companion_Store;
use App\RSpade\Core\Companion\Rsx_Companion;
use App\RSpade\Core\Events\Event_Registry;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * What the application sees of its companions: Companion_Gates (deny by default, a developer
 * passes, staff only), the Rsx_Companion facade's reads over a hand-written Redis state under
 * the test database's prefix (no live daemon can see or write it), the health row mapping, and
 * the daemon's realtime frame builder (lib/store.js, run under node).
 *
 * Handlers stand in through Event_Registry::_set_test_handlers(). Verbs are proved only up to
 * the gate here: a verb that passes it reaches the box's daemon, which a test never touches.
 */
class Companion_Facade_Test extends Rsx_Test_Abstract
{
    private const NAME = 'companion_fixture';
    private const OTHER = 'companion_fixture_other';
    private const INSTANCE = 'facade-test-instance';
    private const HOST = 'facade-test-host';

    /**
     * Each test starts here: setup() runs once per class, and a CLI identity declaration and the
     * handler stand-ins outlive a test method.
     */
    private static function __fresh(): void
    {
        static::__clear_redis();
        Companion_Flags::set_desired(self::NAME, false);
        Event_Registry::_set_test_handlers(Companion_Gates::VIEW, []);
        Event_Registry::_set_test_handlers(Companion_Gates::CONTROL, []);
        Rsx_Portal::set_portal_request(false);
        static::__reset_session();
    }

    public static function teardown()
    {
        static::__clear_redis();
        Companion_Flags::set_desired(self::NAME, false);
        Event_Registry::_clear_test_handlers();
        Rsx_Portal::set_portal_request(false);
        static::__reset_session();
    }

    // =========================================================================
    // GATES
    // =========================================================================

    public static function test_no_handler_denies_everything()
    {
        static::__fresh();
        static::__assert_false(Companion_Gates::can_view(self::NAME));
        foreach (Companion_Gates::ACTIONS as $action) {
            static::__assert_false(Companion_Gates::can_control(self::NAME, $action), "{$action} denied");
        }
        static::__assert_equals([], Rsx_Companion::names(), 'a list shows nothing');
        static::__assert_null(Rsx_Companion::status(self::NAME), 'a status reads as not found');
        static::__assert_null(Rsx_Companion::output_after(self::NAME, null), 'output reads as not found');
        static::__assert_false(Companion_Changed_Topic::can_subscribe(['name' => self::NAME]));
        static::__assert_false(Companion_Output_Topic::can_subscribe(['name' => self::NAME]));
        static::__assert_throws(AjaxUnauthorizedException::class, fn () => Rsx_Companion::pause(self::NAME));
    }

    public static function test_a_developer_passes_every_gate_without_a_handler()
    {
        static::__fresh();
        static::__acting_as_user(1);

        static::__assert_true(Companion_Gates::can_view(self::NAME));
        foreach (Companion_Gates::ACTIONS as $action) {
            static::__assert_true(Companion_Gates::can_control(self::NAME, $action), "{$action} allowed");
        }
        static::__assert_true(Companion_Changed_Topic::can_subscribe([]), 'every companion: all are viewable');
        static::__assert_true(Companion_Output_Topic::can_subscribe(['name' => self::NAME]));
        static::__assert_false(Companion_Output_Topic::can_subscribe([]), 'the output feed is per companion');
        static::__assert_false(Companion_Gates::can_view('no_such_companion'), 'an undeclared name is never viewable');
    }

    public static function test_the_gates_are_staff_only()
    {
        static::__fresh();
        static::__acting_as_user(1);
        Event_Registry::_set_test_handlers(Companion_Gates::VIEW, [fn ($data) => true]);
        Rsx_Portal::set_portal_request(true);

        static::__assert_false(Companion_Gates::can_view(self::NAME), 'a portal request is refused, developer or handler');
    }

    public static function test_handlers_decide_per_companion_and_per_action()
    {
        static::__fresh();
        $seen = [];
        Event_Registry::_set_test_handlers(Companion_Gates::VIEW, [function ($data) use (&$seen) {
            $seen[] = $data;

            return $data['companion'] === self::NAME;
        }]);
        Event_Registry::_set_test_handlers(Companion_Gates::CONTROL, [fn ($data) => $data['action'] === 'pause' ? true : false]);

        static::__assert_true(Companion_Gates::can_view(self::NAME));
        static::__assert_false(Companion_Gates::can_view(self::OTHER));
        static::__assert_true(array_key_exists('user', $seen[0]), 'the gate is handed the user');
        static::__assert_true(Companion_Gates::can_control(self::NAME, 'pause'));
        static::__assert_false(Companion_Gates::can_control(self::NAME, 'stop'));
        static::__assert_false(Companion_Gates::can_control(self::OTHER, 'pause'), 'a companion that cannot be seen cannot be acted on');
        static::__assert_equals([self::NAME], array_values(array_intersect(Rsx_Companion::names(), [self::NAME, self::OTHER])));
        static::__assert_false(Companion_Changed_Topic::can_subscribe([]), 'an empty filter needs every companion viewable');
        static::__assert_throws(\InvalidArgumentException::class, fn () => Companion_Gates::can_control(self::NAME, 'delete'), "Unknown companion action 'delete'");
    }

    public static function test_a_denied_verb_throws_unauthorized_before_the_daemon_is_asked()
    {
        static::__fresh();
        Event_Registry::_set_test_handlers(Companion_Gates::VIEW, [fn ($data) => true]);

        static::__assert_throws(AjaxUnauthorizedException::class, fn () => Rsx_Companion::restart(self::NAME), 'You may not restart');
    }

    // =========================================================================
    // STATUS
    // =========================================================================

    public static function test_a_running_companion_is_composed_from_the_summary_and_its_record()
    {
        static::__fresh();
        static::__acting_as_user(1);
        Companion_Flags::set_desired(self::NAME, true);
        $now = static::__now_ms();
        static::__write_summary(['status' => 'running', 'instance_id' => self::INSTANCE, 'started_at' => (string) ($now - 125000), 'restart_count' => 2]);
        static::__write_record(['last_heartbeat' => (string) ($now - 5000), 'status_line' => 'Draining page 3', 'state' => '{"cursor":7}', 'progress_current' => '3', 'progress_total' => '12']);

        $status = Rsx_Companion::status(self::NAME);

        static::__assert_equals('running', $status['status']);
        static::__assert_equals('running', $status['desired']);
        static::__assert_equals(self::HOST, $status['host']);
        static::__assert_equals(self::INSTANCE, $status['instance_id']);
        static::__assert_true($status['is_live']);
        static::__assert_greater_than(120, $status['uptime_seconds']);
        static::__assert_equals(2, $status['restart_count']);
        static::__assert_equals('Draining page 3', $status['status_line'], 'the record is the fresher source');
        static::__assert_equals(['cursor' => 7], $status['state']);
        static::__assert_equals(['current' => 3.0, 'total' => 12.0], $status['progress']);
        static::__assert_false($status['heartbeat_stale']);
        static::__assert_true($status['can']['pause']);
        static::__assert_equals('OK', Companion_Health_Checks::row($status)['status']);
    }

    public static function test_a_stale_heartbeat_is_reported()
    {
        static::__fresh();
        static::__acting_as_user(1);
        $now = static::__now_ms();
        static::__write_summary(['status' => 'running', 'instance_id' => self::INSTANCE, 'started_at' => (string) ($now - 600000)]);
        static::__write_record(['last_heartbeat' => (string) ($now - (Rsx_Companion::HEARTBEAT_STALE_SECONDS + 30) * 1000)]);
        Companion_Flags::set_desired(self::NAME, true);

        $status = Rsx_Companion::status(self::NAME);

        static::__assert_true($status['heartbeat_stale']);
        static::__assert_equals('WARN', Companion_Health_Checks::row($status)['status']);
    }

    public static function test_a_daemon_whose_own_heartbeat_is_stale_reports_nothing()
    {
        static::__fresh();
        static::__acting_as_user(1);
        static::__write_summary(['status' => 'running', 'instance_id' => self::INSTANCE], static::__now_ms() - (Rsx_Companion::SUMMARY_STALE_SECONDS + 30) * 1000);
        Companion_Flags::set_desired(self::NAME, true);

        $status = Rsx_Companion::status(self::NAME);

        static::__assert_equals('unknown', $status['status']);
        static::__assert_not_null($status['unknown_reason']);
        static::__assert_equals('FAIL', Companion_Health_Checks::row($status)['status'], 'desired running and nobody reports it');
    }

    public static function test_the_host_running_an_instance_is_the_one_shown()
    {
        static::__fresh();
        static::__acting_as_user(1);
        static::__write_summary(['status' => 'waiting', 'waiting_reason' => 'another host holds the instance', 'instance_id' => null], null, 'aaa-other-host');
        static::__write_summary(['status' => 'running', 'instance_id' => self::INSTANCE, 'started_at' => (string) static::__now_ms()]);

        $status = Rsx_Companion::status(self::NAME);

        static::__assert_equals(self::HOST, $status['host']);
        static::__assert_count(2, $status['hosts']);
    }

    // =========================================================================
    // INSTANCES, OUTPUT, STATS
    // =========================================================================

    public static function test_instances_answer_live_records_and_history()
    {
        static::__fresh();
        static::__acting_as_user(1);
        static::__write_record(['started_at' => '1700000000000', 'last_heartbeat' => '1700000005000', 'state' => '{"a":1}']);
        Companion_Store::connection()->lPush(Companion_Store::history_key(self::NAME), json_encode([
            'instance_id' => 'ended-1', 'name' => self::NAME, 'status' => 'crashed', 'how_ended' => 'crashed',
            'exit_code' => '3', 'exit_signal' => '', 'started_at' => '1700000000000', 'ended_at' => '1700000060000',
        ]));

        $instances = Rsx_Companion::instances(self::NAME);

        static::__assert_count(1, $instances['live']);
        static::__assert_equals('2023-11-14T22:13:20.000Z', $instances['live'][0]['started_at']);
        static::__assert_equals(['a' => 1], $instances['live'][0]['state']);
        static::__assert_equals(3, $instances['history'][0]['exit_code']);
        static::__assert_equals('2023-11-14T22:14:20.000Z', $instances['history'][0]['ended_at']);
    }

    public static function test_output_reads_after_an_opaque_cursor_and_filters_streams()
    {
        static::__fresh();
        static::__acting_as_user(1);
        $ids = static::__write_output([['stdout', 'one'], ['stderr', 'two'], ['daemon', 'three'], ['stdout', 'four']]);

        $all = Rsx_Companion::output_after(self::NAME, null);
        static::__assert_equals(['one', 'two', 'three', 'four'], array_column($all['lines'], 'line'));
        static::__assert_equals($ids[3], $all['last_id']);
        static::__assert_false($all['trimmed']);
        static::__assert_false($all['more']);

        $after = Rsx_Companion::output_after(self::NAME, $ids[1]);
        static::__assert_equals(['three', 'four'], array_column($after['lines'], 'line'), 'the cursor is exclusive');

        $stdout = Rsx_Companion::output_after(self::NAME, null, ['stdout']);
        static::__assert_equals(['one', 'four'], array_column($stdout['lines'], 'line'));
        static::__assert_equals($ids[3], $stdout['last_id'], 'the cursor moves past filtered lines too');

        $paged = Rsx_Companion::output_after(self::NAME, null, Rsx_Companion::STREAMS, 2);
        static::__assert_true($paged['more']);
        static::__assert_equals($ids[1], $paged['last_id']);

        $empty = Rsx_Companion::output_after(self::NAME, $ids[3]);
        static::__assert_equals([], $empty['lines']);
        static::__assert_equals($ids[3], $empty['last_id'], 'nothing new keeps the cursor');

        static::__assert_throws(\InvalidArgumentException::class, fn () => Rsx_Companion::output_after(self::NAME, 'not-a-cursor'));
        static::__assert_throws(\InvalidArgumentException::class, fn () => Rsx_Companion::output_after(self::NAME, null, ['operator']));
    }

    public static function test_a_trimmed_stream_says_so_to_a_reader_behind_it()
    {
        static::__fresh();
        static::__acting_as_user(1);
        $redis = Companion_Store::connection();
        $key = Companion_Store::output_key(self::NAME);
        $first = $redis->xAdd($key, '*', ['stream' => 'stdout', 'line' => 'old', 'instance' => self::INSTANCE, 'at' => '2026-01-01T00:00:00Z']);
        for ($i = 0; $i < 5; $i++) {
            $redis->xAdd($key, '*', ['stream' => 'stdout', 'line' => 'line ' . $i, 'instance' => self::INSTANCE, 'at' => '2026-01-01T00:00:00Z']);
        }
        $redis->xTrim($key, 3);

        $from_start = Rsx_Companion::output_after(self::NAME, null);
        static::__assert_true($from_start['trimmed'], 'a reader from the start missed the trimmed lines');
        static::__assert_count(3, $from_start['lines']);

        $behind = Rsx_Companion::output_after(self::NAME, $first);
        static::__assert_true($behind['trimmed'], 'a cursor older than the oldest kept line missed lines');

        $current = Rsx_Companion::output_after(self::NAME, $from_start['last_id']);
        static::__assert_false($current['trimmed'], 'a reader that kept up missed nothing');
    }

    public static function test_request_stats_sum_the_minutes_per_endpoint()
    {
        static::__fresh();
        static::__acting_as_user(1);
        $redis = Companion_Store::connection();
        $now = time();
        $redis->hMSet(Companion_Store::request_stats_key(self::NAME, gmdate('YmdHi', $now)), ['ping:calls' => 3, 'ping:ms' => 30, 'ping:errors' => 1]);
        $redis->hMSet(Companion_Store::request_stats_key(self::NAME, gmdate('YmdHi', $now - 120)), ['ping:calls' => 1, 'ping:ms' => 50]);

        $stats = Rsx_Companion::request_stats(self::NAME);

        static::__assert_equals(['calls' => 4, 'errors' => 1, 'avg_ms' => 20], $stats['ping']);
    }

    // =========================================================================
    // HEALTH ROWS
    // =========================================================================

    public static function test_health_rows_follow_desired_against_actual()
    {
        static::__fresh();
        $base = static::__status_shape();

        static::__assert_equals('OK', Companion_Health_Checks::row(['desired' => 'stopped', 'status' => 'stopped'] + $base)['status']);
        static::__assert_equals('INFO', Companion_Health_Checks::row(['maintenance' => 'maintenance mode: test'] + $base)['status']);
        static::__assert_equals('WARN', Companion_Health_Checks::row(['status' => 'waiting', 'waiting_reason' => '2 migrations are pending'] + $base)['status']);
        static::__assert_equals('WARN', Companion_Health_Checks::row(['status' => 'crashed', 'crash_streak' => 1] + $base)['status']);
        static::__assert_equals('FAIL', Companion_Health_Checks::row(['status' => 'crashed', 'crash_streak' => 5, 'crash_looping' => true] + $base)['status']);
        static::__assert_equals('FAIL', Companion_Health_Checks::row(['status' => 'exited'] + $base)['status'], 'desired running, ended by its policy');
        static::__assert_equals('WARN', Companion_Health_Checks::row(['status' => 'stopped', 'held' => true] + $base)['status']);
        static::__assert_equals('OK', Companion_Health_Checks::row(['status' => 'paused', 'is_live' => true] + $base)['status']);
    }

    public static function test_the_companion_check_answers_a_row_per_declared_companion()
    {
        static::__fresh();
        $rows = Companion_Health_Checks::companions();
        $labels = array_column($rows, 'label');

        static::__assert_true(in_array('Companion: ' . self::NAME, $labels, true), 'a row for ' . self::NAME);
        static::__assert_true(in_array('Companion: ' . self::OTHER, $labels, true), 'a row for ' . self::OTHER);
    }

    // =========================================================================
    // THE DAEMON'S REALTIME FRAMES
    // =========================================================================

    public static function test_the_daemon_publishes_one_frame_per_watching_site()
    {
        static::__fresh();
        $members = [
            json_encode(['site_id' => 1, 'topic' => 'Companion_Changed_Topic', 'filter' => ['name' => 'alpha']]),
            json_encode(['site_id' => 1, 'topic' => 'Companion_Changed_Topic', 'filter' => (object) []]),
            json_encode(['site_id' => 2, 'topic' => 'Companion_Changed_Topic', 'filter' => (object) []]),
            json_encode(['site_id' => 3, 'topic' => 'Companion_Output_Topic', 'filter' => ['name' => 'beta']]),
            json_encode(['site_id' => 4, 'topic' => 'Task_Changed_Topic', 'filter' => ['id' => 1]]),
        ];
        $events = [['kind' => 'changed', 'name' => 'alpha'], ['kind' => 'output', 'name' => 'alpha']];

        $store = base_path('bin/rsx-companiond/lib/store.js');
        $script = 'const {realtime_frames} = require(' . json_encode($store) . ');'
            . 'process.stdout.write(JSON.stringify(realtime_frames(' . json_encode($events) . ', ' . json_encode($members) . ', 99)));';

        $process = new Process(['node', '-e', $script]);
        $process->setTimeout(null);
        $process->run();
        static::__assert_true($process->isSuccessful(), 'node ran the frame builder: ' . $process->getErrorOutput());

        $frames = json_decode($process->getOutput(), true);

        static::__assert_equals([
            ['rsx_rt:1', json_encode(['topic' => 'Companion_Changed_Topic', 'data' => ['name' => 'alpha'], 'site_id' => 1, 'ts' => 99])],
            ['rsx_rt:2', json_encode(['topic' => 'Companion_Changed_Topic', 'data' => ['name' => 'alpha'], 'site_id' => 2, 'ts' => 99])],
        ], $frames, 'site 1 once though two of its subscriptions match; nobody watches alpha\'s output');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function __now_ms(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    private static function __write_summary(array $fields, ?int $daemon_heartbeat = null, string $host = self::HOST): void
    {
        $redis = Companion_Store::connection();
        $redis->sAdd(Companion_Store::hosts_key(), $host);
        $redis->hMSet(Companion_Store::summary_key($host), [
            self::NAME => json_encode(array_merge([
                'name' => self::NAME, 'host' => $host, 'desired' => 'running', 'held' => false, 'status' => 'stopped',
                'waiting_reason' => null, 'instance_id' => null, 'pid' => 4242, 'started_at' => null, 'restart_count' => 0,
                'crash_streak' => 0, 'next_restart_at' => null, 'last_heartbeat' => null, 'status_line' => null,
                'state' => null, 'progress_current' => null, 'progress_total' => null, 'last_error' => null, 'last_exit' => null,
            ], $fields)),
            Companion_Store::SUMMARY_DAEMON_FIELD => json_encode(['pid' => 1, 'host' => $host, 'heartbeat' => $daemon_heartbeat ?? static::__now_ms()]),
        ]);
    }

    private static function __write_record(array $fields): void
    {
        $redis = Companion_Store::connection();
        $redis->hMSet(Companion_Store::instance_key(self::INSTANCE), array_merge([
            'instance_id' => self::INSTANCE, 'name' => self::NAME, 'host' => self::HOST, 'pid' => '4242',
            'started_at' => (string) static::__now_ms(), 'status' => 'running', 'last_heartbeat' => '', 'status_line' => '',
            'state' => '', 'progress_current' => '', 'progress_total' => '', 'last_error' => '',
        ], $fields));
        $redis->sAdd(Companion_Store::live_key(), self::INSTANCE);
    }

    /**
     * @return array<int, string> the entry ids, in order
     */
    private static function __write_output(array $lines): array
    {
        $ids = [];
        foreach ($lines as [$stream, $line]) {
            $ids[] = Companion_Store::connection()->xAdd(Companion_Store::output_key(self::NAME), '*', [
                'stream' => $stream, 'line' => $line, 'instance' => self::INSTANCE, 'at' => '2026-01-01T00:00:00.000Z',
            ]);
        }

        return $ids;
    }

    private static function __status_shape(): array
    {
        return [
            'name' => self::NAME, 'desired' => 'running', 'maintenance' => null, 'status' => 'running', 'unknown_reason' => null,
            'held' => false, 'waiting_reason' => null, 'host' => self::HOST, 'uptime_seconds' => 60, 'crash_streak' => 0,
            'crash_looping' => false, 'heartbeat_stale' => false, 'heartbeat_age_seconds' => 5, 'last_exit' => null, 'is_live' => false,
        ];
    }

    private static function __clear_redis(): void
    {
        $redis = Companion_Store::connection();
        $redis->del(
            Companion_Store::summary_key(self::HOST),
            Companion_Store::summary_key('aaa-other-host'),
            Companion_Store::instance_key(self::INSTANCE),
            Companion_Store::history_key(self::NAME),
            Companion_Store::output_key(self::NAME),
            Companion_Store::request_stats_key(self::NAME, gmdate('YmdHi')),
            Companion_Store::request_stats_key(self::NAME, gmdate('YmdHi', time() - 120))
        );
        $redis->sRem(Companion_Store::hosts_key(), self::HOST, 'aaa-other-host');
        $redis->sRem(Companion_Store::live_key(), self::INSTANCE);
    }
}
