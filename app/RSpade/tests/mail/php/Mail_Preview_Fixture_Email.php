<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Mail\Php;

use App\RSpade\Core\Mail\Rsx_Email_Abstract;

/**
 * A NOTIFICATION-category email carrying every kind of image a template can name and a
 * tag of its own, for the preview, catalogue and tag tests.
 *
 * Its template renders a ROOT-RELATIVE image (/favicon.ico - auto-embedded by a send,
 * a data: URI in a preview), optionally a cid: image (bound by ->embed('preview_chart',
 * ...)), an .email-button for the CSS-inlining step, and the unsubscribe footer its
 * category carries. tag() answers the $kind it was built with - the shape of an email
 * class that sends more than one kind of message.
 */
class Mail_Preview_Fixture_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::NOTIFICATION;

    /**
     * @param string      $note       The body line and the subject.
     * @param string|null $kind       What tag() answers.
     * @param bool        $show_chart Render `cid:preview_chart` - the sender must then call
     *                                ->embed('preview_chart', ...).
     */
    public function __construct(
        public string $note = 'Preview probe',
        public ?string $kind = null,
        public bool $show_chart = false
    ) {
    }

    public function tag(): ?string
    {
        return $this->kind;
    }

    public function subject(): string
    {
        return $this->note;
    }

    public function data(): array
    {
        return [
            'note' => $this->note,
            'show_chart' => $this->show_chart,
            'view_url' => rsx_absolute_url('/'),
        ];
    }

    public static function sample(): static
    {
        return new static();
    }
}
