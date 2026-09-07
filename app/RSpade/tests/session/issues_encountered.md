# Session - Issues Encountered

Issues discovered while cross-checking the man page against the actual source in
`app/RSpade/Core/Session/Session.php`.

All clear documentation errors were fixed directly in `app/RSpade/man/session.txt`.
The entries below are either ambiguous or potential code-level concerns that
warrant human review.

---

## 1. has_session() in CLI: checks site_id OR user_id, NOT login_user_id

**What the man page said (before fix):**
> Returns true if session exists (web mode) or if site_id/user_id set (CLI mode).

**What the code does:**
```php
public static function has_session(): bool
{
    if (self::__is_cli()) {
        return self::$_cli_site_id !== null || self::$_cli_user_id !== null;
    }
    ...
}
```

Setting `set_login_user_id()` in CLI mode writes `$_cli_login_user_id` only.
`has_session()` does NOT check that property - it checks `$_cli_site_id` and
`$_cli_user_id`. So after `Session::set_login_user_id(123)` alone,
`has_session()` returns false.

**Concern:** This is a surprising inconsistency: you can be "logged in"
(is_logged_in() returns true) while has_session() returns false, in CLI mode.
The man page was ambiguous enough that this was corrected to "site_id/user_id"
(not login_user_id), but the behavior may surprise callers who expect
has_session() to track the full authenticated state.

---

## 2. set_site_id() does NOT create a session

**What the man page said (before fix):**
> Session::set_site_id(int $site_id): void
>     Convenience method, same as set_site().
>     CREATES session if none exists.

**What the code does:**
```php
public static function set_site_id(int $site_id): void
{
    if (self::__is_cli()) {
        self::$_cli_site_id = $site_id;
        ...
        return;
    }

    self::init();

    if (empty(self::$_session)) {
        self::$_request_site_id_override = $site_id;  // request-scoped, no DB write
        ...
        return;
    }

    self::$_session->site_id = $site_id;
    ...
}
```

In web mode with no existing session, set_site_id() stores the value as a
request-scoped override only - no session row is created. Session creation only
happens from set_login_user_id(), get_session_id(), or get_session(). The man
page was corrected, but the behavior diverges from set_site() (which no longer
exists) and from what callers may expect when reading the old docs.

---

## 3. set_user() / set_user_id() / set_site() methods do not exist

**What the man page said (before fix):**
The man page extensively documented `Session::set_user()`, `Session::set_user_id()`,
and `Session::set_site()` as the primary login/site methods.

**What the code has:**
Only `Session::set_login_user_id(int|null)` and `Session::set_site_id(int)`.
There is no `set_user()`, `set_user_id()`, or `set_site()` method anywhere in
the class.

**Status:** All man-page references corrected. No code impact since the missing
methods would simply throw "Call to undefined method" if called.

---

## 4. get_site_user() method does not exist

**What the man page said (before fix):**
> Session::get_site_user(): Site_User_Model|null
>     Returns Site_User_Model matching both current user_id and site_id...

**What the code has:** No such method. There is no `get_site_user()` in Session.php.

**Status:** Removed from man page. If this method is needed, it would need to be
added to Session.php.

---

## 5. Cookie name mismatch

**What the man page said (before fix):** Cookie name is `rsx_session`

**What the code does:**
```php
setcookie('rsx', self::$_session_token, [...]);
```

Cookie is named `rsx`. Corrected in man page. Not a code bug.
