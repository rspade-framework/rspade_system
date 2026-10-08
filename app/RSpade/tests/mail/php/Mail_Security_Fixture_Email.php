<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;

/**
 * A SECURITY-category email - a sign-in code - for the tests that pin its exemption from the
 * site block list and the recipient opt-out.
 */
class Mail_Security_Fixture_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::SECURITY;

    public function __construct(public string $note = 'Your sign-in code')
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
