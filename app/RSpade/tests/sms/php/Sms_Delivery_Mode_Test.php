<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Sms\Php;

use App\RSpade\Core\Models\Sms_Queue_Model;
use App\RSpade\Core\Sms\Rsx_Sms;
use App\RSpade\Core\Task\Task;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * Sms_Delivery_Mode_Test - rsx.sms.delivery, and why it has only two values.
 *
 * The SMS side deliberately MIRRORS the mail vocabulary so an operator who knows what
 * `disabled` means for mail knows what it means here - but it mirrors only the two modes
 * that need no transport. THERE IS NO SMS PROVIDER, so 'live' and 'aiosmtpd' are refused
 * out loud rather than accepted and silently doing nothing, which is the failure this
 * subsystem is most likely to hide.
 *
 * There is NO stale-message sweep here and there must not be: nothing can time out
 * against a delivery path that does not exist.
 */
class Sms_Delivery_Mode_Test extends Rsx_Test_Abstract
{
    private const SITE_ID = 1;

    public static function setup()
    {
        static::__acting_as_site(self::SITE_ID);
    }

    /**
     * Queue one message, past the dev-host gate.
     */
    private static function __queue(): Sms_Queue_Model
    {
        static::__acting_as_site(self::SITE_ID);

        $number = '+1555' . random_int(1000000, 9999999);

        $previous = config('rsx.sms.dev_site.number_whitelist');
        config(['rsx.sms.dev_site.number_whitelist' => $number]);

        try {
            return Rsx_Sms::send($number, 'Mode probe', Rsx_Sms::TRANSACTIONAL);
        } finally {
            config(['rsx.sms.dev_site.number_whitelist' => $previous]);
        }
    }

    private static function __drain_in_mode(string $mode): array
    {
        $previous = config('rsx.sms.delivery');
        config(['rsx.sms.delivery' => $mode]);

        try {
            return Task::internal('Sms_Queue_Service', 'send_pending_queue');
        } finally {
            config(['rsx.sms.delivery' => $previous]);
        }
    }

    public static function test_a_mode_that_needs_a_transport_is_refused()
    {
        $previous = config('rsx.sms.delivery');

        try {
            foreach (['live', 'aiosmtpd', 'sortof'] as $mode) {
                config(['rsx.sms.delivery' => $mode]);

                static::__assert_throws(
                    \RuntimeException::class,
                    fn () => Rsx_Sms::delivery_mode(),
                    'THERE IS NO SMS PROVIDER'
                );
            }
        } finally {
            config(['rsx.sms.delivery' => $previous]);
        }
    }

    public static function test_both_supported_modes_are_accepted()
    {
        $previous = config('rsx.sms.delivery');

        try {
            foreach (['suppressed', 'disabled'] as $mode) {
                config(['rsx.sms.delivery' => $mode]);

                static::__assert_equals($mode, Rsx_Sms::delivery_mode(), "'{$mode}' is a mode");
            }
        } finally {
            config(['rsx.sms.delivery' => $previous]);
        }
    }

    public static function test_suppressed_records_every_message_as_undeliverable()
    {
        $row = static::__queue();

        $counts = static::__drain_in_mode('suppressed');

        static::__assert_greater_than(0, $counts['suppressed'], 'the drain says what it recorded');
        static::__assert_equals(
            Sms_Queue_Model::STATUS_SUPPRESSED,
            (int) $row->fresh()->status_id,
            'a message nobody can deliver is not "pending", it is not going'
        );
    }

    public static function test_disabled_freezes_the_queue()
    {
        $row = static::__queue();

        $counts = static::__drain_in_mode('disabled');

        static::__assert_equals(0, $counts['suppressed'], 'nothing was recorded - freezing is not suppressing');
        static::__assert_equals(0, $counts['reclaimed'], 'and nothing was reclaimed');

        $row = $row->fresh();

        static::__assert_equals(
            Sms_Queue_Model::STATUS_PENDING,
            (int) $row->status_id,
            'the row is still PENDING, waiting for delivery to come back'
        );
        static::__assert_null($row->last_error, 'and nothing was written on it');
    }
}
