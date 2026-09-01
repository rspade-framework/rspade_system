<?php

namespace App\RSpade\Core\Mail;

/**
 * Rsx_Mail_Layout - the chrome every email template wraps its content in.
 *
 *   {!! Rsx_Mail_Layout::header($subject) !!}
 *   ... email content ...
 *   {!! Rsx_Mail_Layout::footer($unsubscribe_url) !!}
 *
 * THERE IS NO <style> BLOCK HERE. The stylesheet is rsx/emails/email.scss (or the
 * framework default at Core/Mail/resource/email.scss when the app has not written
 * one); Rsx_Mail_Builder compiles it, inlines it onto the elements, and injects the
 * compiled CSS into <head> on its way out. A layout that carried its own copy of the
 * rules would be a second stylesheet nobody remembers to keep in step.
 *
 * Branding comes from config('rsx.mail.branding') - an app sets logo_url and
 * footer_text in rsx/resource/config/rsx.php and never edits this class.
 */
class Rsx_Mail_Layout
{
    /**
     * Everything before the content: the document, the card, and the masthead.
     *
     * The masthead is the logo when the app configured one, and the product name
     * otherwise - a fresh install looks finished without having supplied an image.
     */
    public static function header(string $subject = ''): string
    {
        $app_name = static::_app_name();
        $subject_html = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $logo_url = config('rsx.mail.branding.logo_url');

        $masthead = htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8');

        if ($logo_url) {
            $logo_src = htmlspecialchars((string) $logo_url, ENT_QUOTES, 'UTF-8');
            $masthead = '<img src="' . $logo_src . '" alt="' . htmlspecialchars($app_name, ENT_QUOTES, 'UTF-8') . '" class="email-logo">';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject_html}</title>
</head>
<body>
    <div class="email-wrapper">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr>
                <td align="center">
                    <div class="email-container">
                        <div class="email-header">
                            {$masthead}
                        </div>
                        <div class="email-body">
HTML;
    }

    /**
     * Everything after the content: the footer, its optional app-supplied line, and
     * the unsubscribe link when the builder passed one.
     *
     * The unsubscribe URL arrives as a template variable - the builder supplies it for
     * a non-transactional email and null for a transactional one, so a template never
     * decides whether the recipient may opt out.
     */
    public static function footer(?string $unsubscribe_url = null): string
    {
        $app_name = htmlspecialchars(static::_app_name(), ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        $footer_text = config('rsx.mail.branding.footer_text');
        $footer_text_html = '';

        if ($footer_text) {
            $footer_text_html = '<p>' . htmlspecialchars((string) $footer_text, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $unsubscribe_html = '';

        if ($unsubscribe_url) {
            $href = htmlspecialchars($unsubscribe_url, ENT_QUOTES, 'UTF-8');
            $unsubscribe_html = "<p><a href=\"{$href}\">Unsubscribe</a> from these emails.</p>";
        }

        return <<<HTML
                        </div>
                        <div class="email-footer">
                            <p>&copy; {$year} {$app_name}. All rights reserved.</p>
                            {$footer_text_html}
                            {$unsubscribe_html}
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
HTML;
    }

    /**
     * The product name shown in the masthead and the copyright line.
     */
    private static function _app_name(): string
    {
        return (string) config('rsx.name', 'RSpade');
    }
}
