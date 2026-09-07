<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;

/**
 * A NOTIFICATION-category email, for the blocklist tests.
 *
 * The framework ships exactly one real email (Rsx_Mail_Test_Email) and it is
 * TRANSACTIONAL by design, so it can never exercise the opt-out path. This fixture
 * exists to be blocked.
 */
class Mail_Notification_Fixture_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::NOTIFICATION;

    /**
     * @param string $note The body line and the subject.
     * @param bool $show_image Render the `cid:fixture_image` inline image, so a builder
     *                         test can exercise the embed path. The sender must then
     *                         have called ->embed('fixture_image', ...) - an unbound
     *                         cid is a build error, deliberately.
     */
    public function __construct(public string $note = "Notice", public bool $show_image = false)
    {
    }

    public function subject(): string
    {
        return $this->note;
    }

    public function data(): array
    {
        return [
            "note" => $this->note,
            "show_image" => $this->show_image,
            // An absolute URL, as every link in a real email is. The template
            // renders it inside an .email-button so the CSS-inlining step has
            // something to act on.
            "view_url" => rsx_absolute_url("/"),
        ];
    }

    public static function sample(): static
    {
        return new static();
    }
}
