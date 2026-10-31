<?php

namespace App\RSpade\Core\Mail;

/**
 * Test recipients: the email addresses and phone numbers that go through the whole sending
 * path and are NEVER delivered, in every mode, production included.
 *
 * A message to one is queued, rendered and recorded exactly like any other, then settled
 * as status TEST instead of being handed to a transport or a provider. So sample data,
 * demo accounts and a developer's own experiments behave as sent everywhere the system
 * looks - the history screens, the per-recipient counts, a flow that waits on a message -
 * while no real inbox or phone can ever receive one.
 *
 *   EMAIL  the domain matches one of config('rsx.mail.test_domains'), shell-style and
 *          case-insensitive. The default `test.*` covers test.com, test.org, test.co.uk.
 *   PHONE  a North American number whose exchange is 555 - (nnn) 555-nnnn, written any
 *          way (+1 555 ..., (212) 555-0142, 2125550142). Fixed, not configurable: it is the
 *          number range films and sample data have always used.
 */
class Rsx_Test_Recipients
{
    /**
     * Whether $address is a test email address.
     */
    public static function is_test_email(string $address): bool
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return false;
        }

        $domain = strtolower(trim(substr($address, $at + 1)));
        if ($domain === '') {
            return false;
        }

        foreach ((array) config('rsx.mail.test_domains', []) as $pattern) {
            if (fnmatch(strtolower((string) $pattern), $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $number is a test phone number: North American, exchange 555.
     */
    public static function is_test_phone(string $number): bool
    {
        $digits = preg_replace('/\D/', '', $number);

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 && substr($digits, 3, 3) === '555';
    }
}
