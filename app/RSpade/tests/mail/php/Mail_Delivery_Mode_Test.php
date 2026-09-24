<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use App\RSpade\Tests\Mail\Php\Mail_Notification_Fixture_Email;
use App\RSpade\Tests\Mail\Php\Mail_Transport_Stub;

/**
 * Mail_Delivery_Mode_Test - the four-valued switch, and what each value actually does.
 *
 * rsx.mail.delivery is the one setting that decides whether an email reaches a person,
 * and the four modes are four DIFFERENT promises - not four levels of the same one:
 *
 *   aiosmtpd   it lands on this box and nowhere else, and the server has to prove it
 *              is the catcher before we believe it
 *   live       it goes wherever the transport block points
 *   suppressed it is built and recorded, and handed to nobody
 *   disabled   the queue is FROZEN - nothing is claimed, nothing is aged, nothing moves
 *
 * A mode that quietly degraded into another one would be the worst defect this subsystem
 * could have, so every promise gets its own test, and an unrecognised value is proven to
 * throw rather than to pick one.
 *
 * THE STALE SWEEP lives here too, because it is a property of the drain that only some
 * modes run. It is QUEUE HYGIENE, not a timeout (rsx.mail.stale_after_days): it stops a
 * queue that silently broke for a month from flooding a month of stale notices the moment
 * somebody repairs it, and hands the decision to resend to a human. What it must NOT do -
 * set aside a message that is not due yet, or touch anything at all while delivery is
 * disabled - matters as much as what it does.
 */
class Mail_Delivery_Mode_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * Queue one fixture message and return its row.
     *
     * The dev-host gate is whitelisted past - the test host is a `.dev.` hostname, and
     * that gate is Rsx_Email_Enqueue_Test's subject, not this class's.
     */
    private static function __queue(string $note = 'Mode probe'): Email_Queue_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $previous = config('rsx.mail.dev_site.domain_whitelist');
        config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            return (new Mail_Notification_Fixture_Email($note))
                ->to('mode_' . uniqid() . '@example.com')
                ->send();
        } finally {
            config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
        }
    }

    /**
     * Run the drain in $mode, and hand back what the task returned.
     *
     * Both overrides are ALWAYS cleared, including when the drain throws: a transport
     * stub or a fake banner surviving into another test class would make its failures
     * unattributable.
     */
    private static function __drain_in_mode(string $mode, ?Mail_Transport_Stub $stub = null, ?string $banner = null): array
    {
        $previous = config('rsx.mail.delivery');

        config(['rsx.mail.delivery' => $mode]);
        Rsx_Mail_Transport::$override_for_tests = $stub;
        Rsx_Mail_Transport::$banner_for_tests = $banner;

        try {
            return Task::internal('Mail_Queue_Service', 'send_pending_queue');
        } finally {
            Rsx_Mail_Transport::$override_for_tests = null;
            Rsx_Mail_Transport::$banner_for_tests = null;
            config(['rsx.mail.delivery' => $previous]);
        }
    }

    /**
     * Backdate a row so the stale sweep can see it, without waiting days for it.
     */
    private static function __age(Email_Queue_Model $row, int $days): void
    {
        Email_Queue_Model::where('id', $row->id)->update([
            'created_at' => now()->subDays($days),
        ]);
    }

    // =========================================================================
    // THE VOCABULARY
    // =========================================================================

    public static function test_an_unknown_mode_throws_rather_than_guessing()
    {
        $previous = config('rsx.mail.delivery');
        config(['rsx.mail.delivery' => 'sortof']);

        try {
            static::__assert_throws(
                \RuntimeException::class,
                fn () => Rsx_Mail_Transport::delivery_mode(),
                "rsx.mail.delivery is 'sortof'"
            );
        } finally {
            config(['rsx.mail.delivery' => $previous]);
        }
    }

    public static function test_every_documented_mode_is_accepted()
    {
        $previous = config('rsx.mail.delivery');

        try {
            foreach (['aiosmtpd', 'live', 'suppressed', 'disabled'] as $mode) {
                config(['rsx.mail.delivery' => $mode]);

                static::__assert_equals(
                    $mode,
                    Rsx_Mail_Transport::delivery_mode(),
                    "'{$mode}' is a mode"
                );
            }
        } finally {
            config(['rsx.mail.delivery' => $previous]);
        }
    }

    // =========================================================================
    // AIOSMTPD: Laravel's mail config is IGNORED
    // =========================================================================

    /**
     * A box whose MAIL_HOST still names last year's relay must not be able to mail
     * anybody merely because nobody noticed the leftover value. In aiosmtpd mode
     * Laravel's mail config is unread - which is the difference between a DEFAULT and
     * this mode's promise.
     */
    public static function test_aiosmtpd_mode_ignores_the_configured_mailer()
    {
        $previous = [config('rsx.mail.delivery'), config('mail.default'), config('mail.mailers.smtp')];

        config([
            'rsx.mail.delivery' => 'aiosmtpd',
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'host' => 'smtp.somewhere-real.example.com',
                'port' => 587,
                'username' => 'someuser',
                'password' => 'somepass',
            ],
        ]);

        try {
            $dsn = Rsx_Mail_Transport::aiosmtpd_dsn();
            $transport = (string) Rsx_Mail_Transport::make();

            static::__assert_contains('127.0.0.1:1025', $dsn, 'the DSN is the catcher');
            static::__assert_contains('auto_tls=false', $dsn, 'and TLS is explicitly off, not merely unmentioned');
            static::__assert_contains('127.0.0.1:1025', $transport, 'the transport built is the catcher');
            static::__assert_true(
                strpos($transport, 'somewhere-real') === false,
                'and names nothing from the configured mailer: ' . $transport
            );

            static::__assert_equals(
                'smtp 127.0.0.1:1025 (development catcher)',
                Rsx_Mail_Transport::describe(),
                'what an operator is shown says the same thing'
            );
            static::__assert_equals('aiosmtpd', Rsx_Mail_Transport::transport_label(), 'and so does the row label');
        } finally {
            config([
                'rsx.mail.delivery' => $previous[0],
                'mail.default' => $previous[1],
                'mail.mailers.smtp' => $previous[2],
            ]);
        }
    }

    /**
     * Live mode is Laravel's: the transport is the mailer MAIL_MAILER names, built by
     * Laravel's MailManager - host, port, scheme and user all read from it.
     */
    public static function test_live_mode_builds_the_laravel_mailer()
    {
        $previous = [config('rsx.mail.delivery'), config('mail.default'), config('mail.mailers.smtp')];

        config([
            'rsx.mail.delivery' => 'live',
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => 'smtps',
                'host' => 'smtp.somewhere-real.example.com',
                'port' => 465,
                'username' => 'someuser',
                'password' => 'somepass',
            ],
        ]);

        try {
            $transport = (string) Rsx_Mail_Transport::make();

            // Symfony leaves 465 out of the name of an smtps transport - it is the default.
            static::__assert_equals('smtps://smtp.somewhere-real.example.com', $transport, 'the configured host is the one built, over TLS');
            static::__assert_equals(
                "mailer 'smtp': smtps smtp.somewhere-real.example.com:465 as someuser",
                Rsx_Mail_Transport::describe(),
                'and the description names the mailer, never the password'
            );
            static::__assert_equals('smtp', Rsx_Mail_Transport::transport_label(), 'the row records the mailer name');
        } finally {
            config([
                'rsx.mail.delivery' => $previous[0],
                'mail.default' => $previous[1],
                'mail.mailers.smtp' => $previous[2],
            ]);
        }
    }

    /**
     * MAIL_URL supplies the SMTP settings in one string, resolved exactly as Laravel's
     * MailManager resolves it - the transport built and the description agree.
     */
    public static function test_a_mail_url_is_resolved_the_way_laravel_resolves_it()
    {
        $previous = [config('rsx.mail.delivery'), config('mail.default'), config('mail.mailers.smtp')];

        config([
            'rsx.mail.delivery' => 'live',
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'url' => 'smtp://urluser:urlpass@relay.url-probe.example.com:2525',
            ],
        ]);

        try {
            static::__assert_equals(
                'smtp://relay.url-probe.example.com:2525',
                (string) Rsx_Mail_Transport::make(),
                'the url names the host and port built'
            );
            static::__assert_equals(
                "mailer 'smtp': smtp relay.url-probe.example.com:2525 as urluser",
                Rsx_Mail_Transport::describe(),
                'and the description reads the same resolution, password left out'
            );
        } finally {
            config([
                'rsx.mail.delivery' => $previous[0],
                'mail.default' => $previous[1],
                'mail.mailers.smtp' => $previous[2],
            ]);
        }
    }

    /**
     * 'log' and 'array' accept a message and deliver it nowhere, so under 'live' the
     * queue would record every row SENT while nothing left the box. Refused, naming the
     * setting that records without sending.
     */
    public static function test_live_mode_refuses_a_mailer_that_delivers_nothing()
    {
        $previous = [config('rsx.mail.delivery'), config('mail.default')];

        config(['rsx.mail.delivery' => 'live', 'mail.default' => 'log']);

        try {
            static::__assert_throws(
                \RuntimeException::class,
                fn() => Rsx_Mail_Transport::make(),
                "'log' transport, which delivers nothing"
            );
        } finally {
            config(['rsx.mail.delivery' => $previous[0], 'mail.default' => $previous[1]]);
        }
    }

    public static function test_live_mode_refuses_a_mailer_that_is_not_configured()
    {
        $previous = [config('rsx.mail.delivery'), config('mail.default')];

        config(['rsx.mail.delivery' => 'live', 'mail.default' => 'no-such-mailer']);

        try {
            static::__assert_throws(
                \RuntimeException::class,
                fn() => Rsx_Mail_Transport::make(),
                "has no such mailer"
            );
        } finally {
            config(['rsx.mail.delivery' => $previous[0], 'mail.default' => $previous[1]]);
        }
    }

    // =========================================================================
    // AIOSMTPD: the greeting has to say so
    // =========================================================================

    public static function test_a_greeting_that_does_not_say_aiosmtpd_is_reported()
    {
        $previous = config('rsx.mail.delivery');
        config(['rsx.mail.delivery' => 'aiosmtpd']);
        Rsx_Mail_Transport::$banner_for_tests = '220 relay.example.com ESMTP Postfix';

        try {
            $error = Rsx_Mail_Transport::aiosmtpd_banner_error();

            static::__assert_not_null($error, 'an unrecognised greeting is refused');
            static::__assert_contains('expected server aiosmtpd', $error, 'and says what was expected');
            static::__assert_contains('Postfix', $error, 'and what actually answered');
        } finally {
            Rsx_Mail_Transport::$banner_for_tests = null;
            config(['rsx.mail.delivery' => $previous]);
        }
    }

    public static function test_a_catcher_greeting_is_trusted_and_other_modes_never_ask()
    {
        $previous = config('rsx.mail.delivery');
        Rsx_Mail_Transport::$banner_for_tests = '220 box aiosmtpd 1.4.4 (RSpade dev mail catcher)';

        try {
            config(['rsx.mail.delivery' => 'aiosmtpd']);
            static::__assert_null(Rsx_Mail_Transport::aiosmtpd_banner_error(), 'the catcher is the catcher');

            // A real relay's greeting is not ours to have an opinion about.
            Rsx_Mail_Transport::$banner_for_tests = '220 relay.example.com ESMTP Postfix';
            config(['rsx.mail.delivery' => 'live']);
            static::__assert_null(Rsx_Mail_Transport::aiosmtpd_banner_error(), 'live mode never checks the greeting');
        } finally {
            Rsx_Mail_Transport::$banner_for_tests = null;
            config(['rsx.mail.delivery' => $previous]);
        }
    }

    /**
     * A connection that cannot be opened at all is an OUTAGE, and the drain already has
     * the right handling for one (release, reconnect once, then die loudly). Reporting
     * it as a banner problem would burn every message's retry budget on it instead.
     */
    public static function test_nothing_answering_is_an_outage_not_a_banner_problem()
    {
        $previous = config('rsx.mail.delivery');
        config(['rsx.mail.delivery' => 'aiosmtpd']);
        Rsx_Mail_Transport::$banner_for_tests = '';

        try {
            static::__assert_null(
                Rsx_Mail_Transport::aiosmtpd_banner_error(),
                'silence is not an identity failure'
            );
        } finally {
            Rsx_Mail_Transport::$banner_for_tests = null;
            config(['rsx.mail.delivery' => $previous]);
        }
    }

    /**
     * The refusal reaches the ROW, on the ordinary server-error clock: the connection is
     * up, so this is not the transport-outage path, and each message is refused on its
     * own rather than the whole drain dying.
     */
    public static function test_a_bad_greeting_fails_the_message_as_a_server_error()
    {
        $row = static::__queue('Imposter probe');

        $counts = static::__drain_in_mode(
            'aiosmtpd',
            new Mail_Transport_Stub(Mail_Transport_Stub::MODE_ACCEPT),
            '220 relay.example.com ESMTP Postfix'
        );

        static::__assert_greater_than(0, $counts['server_errors'], 'the drain counted a server error');

        $row = $row->fresh();

        static::__assert_equals(
            Email_Queue_Model::STATUS_PENDING,
            (int) $row->status_id,
            'the message is held for a retry, not destroyed'
        );
        static::__assert_equals(1, (int) $row->attempt_count, 'and the attempt is counted');
        static::__assert_contains(
            'expected server aiosmtpd',
            (string) $row->last_error,
            'and the row says exactly why nothing was sent'
        );
    }

    // =========================================================================
    // DISABLED: the queue is frozen
    // =========================================================================

    public static function test_disabled_leaves_every_row_exactly_as_it_was()
    {
        $row = static::__queue('Frozen probe');

        $counts = static::__drain_in_mode('disabled', new Mail_Transport_Stub(Mail_Transport_Stub::MODE_ACCEPT));

        static::__assert_equals(0, $counts['sent'], 'nothing was sent');
        static::__assert_equals(0, $counts['suppressed'], 'and nothing was suppressed - suppressing is not freezing');
        static::__assert_equals(0, $counts['reclaimed'], 'nothing was reclaimed');
        static::__assert_equals(0, $counts['stale'], 'and nothing was aged out');

        $row = $row->fresh();

        static::__assert_equals(
            Email_Queue_Model::STATUS_PENDING,
            (int) $row->status_id,
            'the row is still PENDING, waiting for delivery to come back'
        );
        static::__assert_equals(0, (int) $row->attempt_count, 'it was never attempted');
        static::__assert_null($row->last_error, 'and nothing was written on it');
    }

    /**
     * The stale sweep must not run while the queue is frozen: an install that turns
     * delivery off for a week would otherwise come back to a queue of FAILED rows, which
     * is precisely the outcome "the queue is frozen" promises against.
     */
    public static function test_disabled_does_not_age_anything_out()
    {
        $row = static::__queue('Frozen and old');
        static::__age($row, 30);

        static::__drain_in_mode('disabled');

        static::__assert_equals(
            Email_Queue_Model::STATUS_PENDING,
            (int) $row->fresh()->status_id,
            'a month-old row survives a frozen queue untouched'
        );
    }

    // =========================================================================
    // THE STALE SWEEP (rsx.mail.stale_after_days - queue hygiene, not a timeout)
    // =========================================================================

    public static function test_a_message_due_longer_ago_than_the_window_is_refused_and_names_its_remedy()
    {
        $row = static::__queue('Forgotten probe');
        static::__age($row, (int) config('rsx.mail.stale_after_days', 2) + 1);

        $counts = static::__drain_in_mode('suppressed');

        static::__assert_greater_than(0, $counts['stale'], 'the drain reports what it gave up on');

        $row = $row->fresh();

        static::__assert_equals(
            Email_Queue_Model::STATUS_FAILED,
            (int) $row->status_id,
            'a message that should have gone out days ago is not sent now - a human decides'
        );
        static::__assert_equals(
            Email_Queue_Model::STALE_ERROR,
            $row->last_error,
            'with the exact message an operator greps for'
        );
        static::__assert_contains(
            'rsx:mail:resend',
            (string) $row->last_error,
            'which names the command that puts it back'
        );
        static::__assert_null($row->next_attempt_at, 'and it is off the retry clock');
    }

    /**
     * COALESCE(next_attempt_at, created_at), not created_at: a message deliberately
     * scheduled for later is not late until its due moment, and must not be born stale.
     */
    public static function test_a_message_scheduled_for_the_future_is_never_stale()
    {
        static::__acting_as_site(self::SITE_ID);

        $previous = config('rsx.mail.dev_site.domain_whitelist');
        config(['rsx.mail.dev_site.domain_whitelist' => 'example.com']);

        try {
            $row = (new Mail_Notification_Fixture_Email('Scheduled probe'))
                ->to('scheduled_' . uniqid() . '@example.com')
                ->send_at(now()->addDays(30)->toIso8601String())
                ->send();
        } finally {
            config(['rsx.mail.dev_site.domain_whitelist' => $previous]);
        }

        // Queued long ago, due long from now. Only the DUE date may decide.
        static::__age($row, 30);

        $counts = static::__drain_in_mode('suppressed');

        static::__assert_equals(0, $counts['stale'], 'a scheduled message is not an abandoned one');
        static::__assert_equals(
            Email_Queue_Model::STATUS_PENDING,
            (int) $row->fresh()->status_id,
            'and it is still waiting for its moment'
        );
    }

    public static function test_a_fresh_message_is_left_alone()
    {
        $row = static::__queue('Fresh probe');

        static::__drain_in_mode('suppressed');

        static::__assert_equals(
            Email_Queue_Model::STATUS_SUPPRESSED,
            (int) $row->fresh()->status_id,
            'it was processed normally, not aged out'
        );
    }

    /**
     * rsx:mail:resend is the deliberate escape hatch the stale message names, so it has
     * to work: a reset that left the row aging from its original created_at would be set
     * aside again by the very next drain, making the instruction a lie.
     */
    public static function test_resending_a_stale_row_actually_puts_it_back_on_the_queue()
    {
        $row = static::__queue('Resend probe');
        static::__age($row, 30);

        static::__drain_in_mode('suppressed');

        static::__assert_equals(Email_Queue_Model::STATUS_FAILED, (int) $row->fresh()->status_id, 'stale first');

        $row = $row->fresh();
        $row->reset_for_resend();

        static::__drain_in_mode('suppressed');

        static::__assert_equals(
            Email_Queue_Model::STATUS_SUPPRESSED,
            (int) $row->fresh()->status_id,
            'the resent row is processed, not immediately re-staled'
        );
    }
}
