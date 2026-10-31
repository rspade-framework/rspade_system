# password_confirmation - "confirm it's you" before a sensitive action

**Applicability**: every install. The framework's factor and SSO link/unlink endpoints refuse
without a recent password confirmation, in both realms.

## Source under test

- `app/RSpade/Core/Confirmation/Rsx_Password_Confirmation.php` - the rule: give, check, clear,
  per identity and per realm, refused while impersonating
- `app/RSpade/Core/Confirmation/Rsx_Password_Confirmation_Controller.php` - the endpoints
- `app/RSpade/Core/Confirmation/Rsx_Password_Confirmation.js`, `Password_Confirmation_Form.*`,
  `app/RSpade/Core/Forms/Password_Input.*` - the browser half
- the `require_confirmed()` calls in `Rsx_Two_Factor_Controller`, `Rsx_Portal_Two_Factor_Controller`,
  `Rsx_Sso_Controller`, `Rsx_Portal_Sso_Controller`

## Man page

`rsx:man password_confirmation`

## Testable surface

- php: the confirmation's life (given by password or by the identity's own passkey, belongs to
  one identity and one realm, ended by clear() and by expiry), impersonation refused, a gated
  endpoint refusing until confirmed.
- http: `two_factor/http/two_factor_endpoint_gates.sh` step 3 - a signed-in `totp_begin` is
  refused, the verify endpoint confirms (Turnstile sentinel), and `totp_begin` then answers.
- The application's dialog (try limit, closing on a passkey) is template code, pinned by
  `rsx/tests/playwright/password_confirmation_modal.js`.
- Not here: the rate-limit refusal, which needs a client address (`session/` pins the throttle).
