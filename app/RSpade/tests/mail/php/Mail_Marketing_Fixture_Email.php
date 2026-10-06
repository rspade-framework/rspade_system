<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;

/**
 * A MARKETING-category email, so the site block list can be shown to hold in every
 * category (the transactional case is the shipped Rsx_Mail_Test_Email; the notification
 * case is Mail_Notification_Fixture_Email).
 */
class Mail_Marketing_Fixture_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::MARKETING;

    public function __construct(public string $note = 'Offer')
    {
    }

    public function subject(): string
    {
        return $this->note;
    }

    public function data(): array
    {
        return ['note' => $this->note];
    }

    public static function sample(): static
    {
        return new static();
    }
}
