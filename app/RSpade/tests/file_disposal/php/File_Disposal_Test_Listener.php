<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\FileDisposal\Php;

/**
 * Manifest-discovered #[OnEvent] listeners for the file-disposal destruction hooks, used by
 * File_Disposal_Test. Both are INERT by default (static flags off) so they never interfere
 * with other tests or a live run; a test opts in by setting the flag, and resets it after.
 *
 * The hold gate follows the framework convention: return TRUE to PERMIT destruction; a
 * non-true value HOLDS the attachment.
 */
class File_Disposal_Test_Listener
{
    /** When true, hold (veto) every attachment's destruction. */
    public static bool $hold = false;

    /** When true, the destroyed action throws (simulating a failing app integration). */
    public static bool $throw_on_destroyed = false;

    /** Ids passed to the file.attachment.destroyed action, in order. */
    public static array $destroyed_ids = [];

    public static function reset(): void
    {
        self::$hold = false;
        self::$throw_on_destroyed = false;
        self::$destroyed_ids = [];
    }

    /**
     * Hold gate. Permit (true) unless the test asked to hold.
     */
    #[OnEvent('file.attachment.destroy.hold')]
    public static function on_hold($attachment)
    {
        return self::$hold ? 'held-by-test' : true;
    }

    /**
     * Destroyed action. Record the id; optionally throw to exercise the per-attachment abort.
     */
    #[OnEvent('file.attachment.destroyed')]
    public static function on_destroyed($attachment): void
    {
        if (self::$throw_on_destroyed) {
            throw new \RuntimeException('test: destroyed listener refused');
        }
        self::$destroyed_ids[] = (int) $attachment->id;
    }
}
