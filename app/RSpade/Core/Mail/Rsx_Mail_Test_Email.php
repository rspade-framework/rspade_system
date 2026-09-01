<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Mail;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;
use App\RSpade\Core\Rsx;
use App\RSpade\Core\Time\Rsx_Time;

/**
 * Rsx_Mail_Test_Email - the framework's own smoke-test email.
 *
 * The message `rsx:mail:test` sends: it proves the whole chain (enqueue -> claim ->
 * build -> transport) end to end without an application having written a single email
 * class. Deliberately dependency-free - it takes no constructor arguments and reads
 * only the hostname and the clock, so it can be sent from a fresh install, from a CLI
 * with no session, and from a framework-only tree that has no rsx/emails at all.
 *
 * TRANSACTIONAL: an operator explicitly asked for this one, and it must not be
 * silenced by a blocklist row that has nothing to do with it.
 */
class Rsx_Mail_Test_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::TRANSACTIONAL;

    public function subject(): string
    {
        return 'RSpade mail test';
    }

    public function data(): array
    {
        return [
            'hostname' => Rsx::get_hostname(),
            'sent_at' => Rsx_Time::now_iso(),
            'app_url' => rsx_absolute_url('/'),
        ];
    }

    /**
     * Nothing to vary - the message is the same every time it is sent.
     */
    public static function sample(): static
    {
        return new static();
    }
}
