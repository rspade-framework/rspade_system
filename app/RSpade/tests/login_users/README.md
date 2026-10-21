# Concern: login_users

## Domain overview & applicability

Operator administration of a staff LOGIN IDENTITY (`login_users`, the cross-site record that
signs in) from the command line, where there is no signed-in operator to authorize against:
`rsx:users:password:set`, `rsx:users:email:set` and `rsx:users:force_password_reset`. Each
names the identity with `--user` (a `login_users.id` or an email address), resolved once by
`Login_User_Cli_Support` for every `rsx:users:*` command, and each answers the shared `--json`
envelope. `rsx:users:force_password_reset` also takes a client portal user (`--portal-user`,
with `--site` for an email, resolved by `Portal_User_Cli_Support`) and sets or clears
`password_reset_required`, writing nothing else.

The properties pinned are the ones an operator cannot see until somebody tries to sign in:
a password change replaces the hash and ends every session the identity held (the identity
model's own consequence of a password change, `Session::_end_sessions_for_password_change()`), and an email change moves the sign-in address together with every
membership (`users` row, any site, trashed included) that still showed the old address, while
refusing an address any other identity holds - a soft-deleted one included, because the UNIQUE
index on `login_users.email` does not know about soft deletes.

Adjacent pieces this concern leans on but does not own: `Session::_end_sessions_for_password_change()`
(the password-change sign-out, tested in `session`), `Site_Scoped::without_site_scope()`,
and the two-factor and SSO operator commands, which share the resolver and live in their own
concerns.

## Source files

- `app/RSpade/Commands/Rsx/Login_User_Password_Set_Command.php` - rsx:users:password:set
- `app/RSpade/Commands/Rsx/Login_User_Email_Set_Command.php` - rsx:users:email:set
- `app/RSpade/Commands/Rsx/Login_User_Cli_Support.php` - the --user resolver and identity output
- `app/RSpade/Commands/Rsx/Force_Password_Reset_Command.php` - rsx:users:force_password_reset (both realms)
- `app/RSpade/Commands/Rsx/Portal_User_Cli_Support.php` - the --portal-user (+ --site) resolver

## Tests

- `cli/Login_User_Cli_Test.php` - both commands end to end, through Artisan::call()
- `cli/Force_Password_Reset_Cli_Test.php` - rsx:users:force_password_reset for a login identity and a portal user
