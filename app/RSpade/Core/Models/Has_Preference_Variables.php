<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Models;

use Illuminate\Support\Facades\DB;

/**
 * Has_Preference_Variables - a small key/value store on a user record.
 *
 * For a per-person fact that is not worth a column: a step completed or skipped, a prompt
 * dismissed, "do not show this again". Most people will never have most keys, the value is
 * read in one place, and it must NOT travel with the record - which is why it is a store
 * beside the row rather than a field on it:
 *
 *     $user->set_variable('passkey_prompt', 'declined');
 *     $user->get_variable('passkey_prompt');            // 'declined'
 *     $user->get_variable('welcome_tour', false);       // false - never set
 *     $user->has_variable('welcome_tour');              // false
 *     $user->forget_variable('passkey_prompt');
 *
 * WHICH RECORD is the application's decision. Login_User_Model is the IDENTITY, the same
 * person on every site they belong to ("declined a passkey"); User_Model is that person on
 * ONE site ("skipped connecting a storage account"); Portal_User_Model is a portal user.
 * The current person is Session::get_login_user() / Session::get_user() /
 * Portal_Session::get_portal_user().
 *
 * WHEN IT IS A COLUMN INSTEAD. A preference that every account has for its whole life -
 * dark mode, timezone, a default landing page, notification settings - is a column: it
 * deserves a type, a default and a migration, the application reads it constantly, and it
 * belongs in the record's payload. A variable that is queried across users, sorted by, or
 * read on every request has become a column.
 *
 * WHEN IT IS A SESSION VALUE INSTEAD. Session::put_value() is for something that belongs to
 * one browser session and should vanish with it (a wizard's progress). A preference
 * variable is for something that should still be true on another device next month.
 *
 * WHAT IT DELIBERATELY IS NOT:
 *   - It is never in the record's payload: not in toArray(), not in fetch(), not in the user
 *     object printed onto every page. A value is read on demand, one key at a time.
 *   - It has no expiry. A recorded decision does not expire; "ask again in 90 days" stores
 *     the date as the value.
 *   - It has no revision history and no realtime: these are not record fields.
 *
 * Values are JSON-encoded, so scalars, null and arrays round-trip unchanged, and null is a
 * value: has_variable() is true for a key set to null, and get_variable() answers null
 * rather than the default. A soft-deleted record keeps its variables; a hard delete removes
 * them by cascade. Each read is memoised on the instance for the rest of the request.
 */
trait Has_Preference_Variables
{
    /** The store behind each record's table: [variables table, owner column]. */
    private const PREFERENCE_VARIABLE_STORES = [
        'users' => ['_user_variables', 'user_id'],
        'login_users' => ['_login_user_variables', 'login_user_id'],
        'portal_users' => ['_portal_user_variables', 'portal_user_id'],
    ];

    /** key => [is set, decoded value], for keys this instance has already read or written. */
    private array $preference_variable_memo = [];

    /**
     * The value stored under $key, or $default when the key was never set.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get_variable(string $key, $default = null)
    {
        [$is_set, $value] = $this->__read_preference_variable($key);

        return $is_set ? $value : $default;
    }

    /**
     * Store $value under $key, replacing whatever was there.
     *
     * @param string $key
     * @param mixed $value Anything json_encode() accepts
     * @return void
     */
    public function set_variable(string $key, $value): void
    {
        [$table, $column] = $this->__preference_variable_store();

        DB::statement(
            "INSERT INTO {$table} ({$column}, variable_key, value) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$this->__preference_variable_owner_id(), $key, json_encode($value, JSON_THROW_ON_ERROR)]
        );

        $this->preference_variable_memo[$key] = [true, $value];
    }

    /**
     * Remove $key. Removing a key that was never set is not an error.
     *
     * @param string $key
     * @return void
     */
    public function forget_variable(string $key): void
    {
        [$table, $column] = $this->__preference_variable_store();

        DB::statement(
            "DELETE FROM {$table} WHERE {$column} = ? AND variable_key = ?",
            [$this->__preference_variable_owner_id(), $key]
        );

        $this->preference_variable_memo[$key] = [false, null];
    }

    /**
     * Was $key ever set (and not since forgotten)? True for a key set to null.
     *
     * @param string $key
     * @return bool
     */
    public function has_variable(string $key): bool
    {
        return $this->__read_preference_variable($key)[0];
    }

    /**
     * @return array{0: bool, 1: mixed} [is set, decoded value]
     */
    private function __read_preference_variable(string $key): array
    {
        if (isset($this->preference_variable_memo[$key])) {
            return $this->preference_variable_memo[$key];
        }

        [$table, $column] = $this->__preference_variable_store();

        $rows = DB::select(
            "SELECT value FROM {$table} WHERE {$column} = ? AND variable_key = ?",
            [$this->__preference_variable_owner_id(), $key]
        );

        $found = $rows === []
            ? [false, null]
            : [true, json_decode($rows[0]->value, true, 512, JSON_THROW_ON_ERROR)];

        return $this->preference_variable_memo[$key] = $found;
    }

    /**
     * @return array{0: string, 1: string} [variables table, owner column]
     */
    private function __preference_variable_store(): array
    {
        $store = self::PREFERENCE_VARIABLE_STORES[$this->getTable()] ?? null;

        if ($store === null) {
            shouldnt_happen(static::class . ' has no preference variable store for table ' . $this->getTable());
        }

        return $store;
    }

    private function __preference_variable_owner_id(): int
    {
        if (!$this->exists) {
            throw new \RuntimeException(
                'Preference variables belong to a saved record: save this ' . class_basename($this) . ' before reading or writing one.'
            );
        }

        return (int) $this->getKey();
    }
}
