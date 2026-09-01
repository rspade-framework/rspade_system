<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Mail;

use Illuminate\Http\Request;
use App\RSpade\Core\Controller\Rsx_Controller_Abstract;
use App\RSpade\Core\Errors\Error_Screens;
use App\RSpade\Core\Mail\Rsx_Mail;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Email_Recipient_Model;

/**
 * Mail_Unsubscribe_Controller - the other half of every non-transactional footer link.
 *
 * The builder puts a signed URL in the footer and in the List-Unsubscribe header of
 * every NOTIFICATION and MARKETING message. This is what that URL reaches.
 *
 * THE SIGNATURE IS THE ENTIRE AUTHORIZATION. The link carries email + category + site
 * and an HMAC over exactly those three (Rsx_Mail::unsubscribe_url()), so possession of
 * the link proves the person holding it received a message addressed to that address at
 * that tenant - which is precisely the authority needed to stop more of them. There is
 * no login, no session and no lookup of who is browsing: a recipient is usually not a
 * user of this application at all, and demanding a login before honouring an opt-out is
 * how a sender earns a spam complaint.
 *
 * THE SITE COMES FROM THE SIGNATURE, NEVER FROM THE SESSION. The blocklist is per
 * tenant; a staff member happening to be logged in while clicking a link minted for a
 * different site must not move that site's row.
 *
 * IT LEAKS NOTHING. The page shows the address that was already in the URL the visitor
 * holds and the category name, and nothing else - no name, no account, no confirmation
 * that the address belongs to anybody here.
 *
 * ONE-CLICK (RFC 8058): a mail provider POSTs `List-Unsubscribe=One-Click` here with no
 * browser, no form and no token, and expects a 2xx and no redirect. That body is treated
 * as scope 'category' and answered with a bare line of text.
 */
class Mail_Unsubscribe_Controller extends Rsx_Controller_Abstract
{
    /** The signed path, and the CSRF exemption Rsx_Csrf::enforce names. */
    public const PATH = '/_mail/unsubscribe';

    /** The standalone Blade page both states render through. */
    public const VIEW = 'mail.unsubscribe';

    /**
     * Show the confirmation page (GET) or record the opt-out (POST).
     *
     * Both verbs in one method per the house routing rule, and they share the whole
     * front half: a request whose signature does not verify is a 404 in either verb,
     * with no hint about which part of the tuple was wrong.
     *
     * @param Request $request
     * @param array $params
     * @return \Symfony\Component\HttpFoundation\Response
     */
    #[Route('/_mail/unsubscribe', methods: ['GET', 'POST'])]
    #[Auth('public')]
    public static function unsubscribe(Request $request, array $params = [])
    {
        $email = trim((string) $request->input('email', ''));
        $category = (string) $request->input('category', '');
        $site = (string) $request->input('site', '');
        $signature = (string) $request->input('sig', '');

        if ($email === '' || $category === '' || $site === '' || $signature === '') {
            return Error_Screens::not_found($request);
        }

        $category_id = (int) $category;
        $site_id = (int) $site;

        // Only the two opt-outable categories exist here. A link naming TRANSACTIONAL
        // would render a page that promises something block() cannot do.
        $category_label = static::_category_label($category_id);

        if ($category_label === null) {
            return Error_Screens::not_found($request);
        }

        if (!Rsx_Mail::verify_unsubscribe_signature($email, $category_id, $site_id, $signature)) {
            return Error_Screens::not_found($request);
        }

        // The signed URL, rebuilt from the verified tuple - the form posts back to the
        // same authority it arrived with, so the POST is signed exactly as the GET was.
        $action_url = Rsx_Mail::unsubscribe_url($email, $category_id, $site_id);

        if (!$request->isMethod('POST')) {
            return response()->view(static::VIEW, [
                'state' => 'confirm',
                'email' => $email,
                'category_label' => $category_label,
                'action_url' => $action_url,
                'product_name' => static::_product_name(),
                'scope' => null,
            ]);
        }

        // RFC 8058: the provider's one-click POST carries this exact body field and
        // nothing else. It means "this recipient wants no more of these", which is the
        // narrow scope - a machine may not opt somebody out of everything on their behalf.
        $one_click = $request->input('List-Unsubscribe') === 'One-Click';
        $scope = $one_click ? 'category' : (string) $request->input('scope', 'category');

        if ($scope !== 'category' && $scope !== 'all') {
            return Error_Screens::not_found($request);
        }

        // Both verbs stamp unsubscribed_at themselves (Email_Recipient_Model).
        if ($scope === 'all') {
            Email_Recipient_Model::block_all($site_id, $email);
        } else {
            Email_Recipient_Model::block($site_id, $email, $category_id);
        }

        // A one-click caller is not a browser: it gets a 200 and a line of text, never a
        // redirect and never a page. Anything else and the provider records a failure.
        if ($one_click) {
            return response('Unsubscribed.', 200)->header('Content-Type', 'text/plain; charset=utf-8');
        }

        return response()->view(static::VIEW, [
            'state' => 'done',
            'email' => $email,
            'category_label' => $category_label,
            'action_url' => $action_url,
            'product_name' => static::_product_name(),
            'scope' => $scope,
        ]);
    }

    /**
     * The human name of an opt-outable category, or null when the id is not one.
     */
    private static function _category_label(int $category_id): ?string
    {
        if ($category_id !== Email_Queue_Model::CATEGORY_NOTIFICATION
            && $category_id !== Email_Queue_Model::CATEGORY_MARKETING
        ) {
            return null;
        }

        return Email_Queue_Model::$enums['category_id'][$category_id]['label'];
    }

    /**
     * What this installation calls itself on the page.
     */
    private static function _product_name(): string
    {
        return (string) (config('rsx.name') ?: 'This application');
    }
}
