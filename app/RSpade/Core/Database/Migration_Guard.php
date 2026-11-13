<?php

namespace App\RSpade\Core\Database;

use RuntimeException;

/**
 * Whether a migration's up() is executing, and the refusal of model writes while it is.
 *
 * A migration is a historical record: it replays, unchanged, on every fresh install for as
 * long as the application exists. A model is current code: its columns, derive methods,
 * hooks and side effects are whatever they are TODAY. A migration that writes through a
 * model therefore runs today's code against the schema of its own moment in history, and
 * breaks the day the model moves on. So while an up() executes, a model save() or delete()
 * (Rsx_Model_Abstract) and the query builder's bulk update() / delete()
 * (RestrictedEloquentBuilder) THROW. A migration manipulates records with DB::statement()
 * or DB::table().
 *
 * run() is the window: Maint_Migrate wraps exactly the one call that executes one
 * migration file, so schema normalization, the post-normalize hook, the seed, the initial
 * user, the revision dictionary and the rederive drain all run outside it.
 *
 * allow() is the one sanctioned exception, for framework helpers a migration calls whose
 * writes are framework-owned and settled (the file-attachment repairs, File_Mime_Repair).
 * Application code never needs it: a value derived from other columns is recomputed by
 * Rsx_Rederive::enqueue('<table>'), which is a DB::table() write and works inside the
 * window.
 */
class Migration_Guard
{
    /** Whether a migration's up() is executing. */
    private static bool $active = false;

    /** Whether an allow() scope is open inside the current window. */
    private static bool $allowed = false;

    /**
     * Whether a migration's up() is executing right now (an allow() scope does not change
     * the answer).
     */
    public static function is_active(): bool
    {
        return self::$active;
    }

    /**
     * Run $work as the body of ONE migration: model writes are refused until it returns or
     * throws. A run() nested in an allow() opens a fresh window with no allowance; both
     * states are put back as they were in a finally.
     *
     * @param callable $work
     * @return mixed Whatever $work returns
     */
    public static function run(callable $work): mixed
    {
        $was_active = self::$active;
        $was_allowed = self::$allowed;

        self::$active = true;
        self::$allowed = false;

        try {
            return $work();
        } finally {
            self::$active = $was_active;
            self::$allowed = $was_allowed;
        }
    }

    /**
     * Run $work with model writes permitted inside a migration window - for framework
     * helpers whose model writes are settled framework behaviour (the file-attachment
     * repairs). Outside a window it simply runs $work.
     *
     * @param callable $work
     * @return mixed Whatever $work returns
     */
    public static function allow(callable $work): mixed
    {
        $was_allowed = self::$allowed;

        self::$allowed = true;

        try {
            return $work();
        } finally {
            self::$allowed = $was_allowed;
        }
    }

    /**
     * Throw when a migration's up() is executing and no allow() scope is open. Called by the
     * model's save() / delete() and the builder's bulk update() / delete().
     *
     * FRAMEWORK INTERNAL.
     *
     * @param string $model_class The model being written (any spelling; its basename is used)
     * @param string $operation What was called, for the message (e.g. 'save()', 'where(...)->update()')
     * @throws RuntimeException
     */
    public static function _refuse_model_write(string $model_class, string $operation): void
    {
        if (!self::$active || self::$allowed) {
            return;
        }

        $model = class_basename($model_class);

        throw new RuntimeException(
            "{$model}::{$operation} refused: a migration is executing. A migration manipulates records with "
            . 'direct SQL - DB::statement() or the DB::table query builder - never through a model: a model is current code, and a '
            . 'migration is a historical record that must replay unchanged forever. To recompute a model\'s derived '
            . "columns after changing what they are computed from, call Rsx_Rederive::enqueue('<table>'); migrate "
            . 'rederives the table in its final step. See: php artisan rsx:man migrations'
        );
    }
}
