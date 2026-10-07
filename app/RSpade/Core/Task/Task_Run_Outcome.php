<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Task;

use Throwable;

/**
 * Task_Run_Outcome - how one run ended, decided in ONE place.
 *
 * THE RETURN CONTRACT. A task method's return value is its return code:
 *
 *   null, true, 0        success   (compared with ===: 0 is never "false")
 *   false                failure, return code 1
 *   any other integer    failure, that return code
 *   anything else        failure, return code 1 - a task reports data with summary(),
 *                        state() or an attachment, never through its return value
 *   a throw              failure, return code 1, the exception recorded as the error
 *
 * A successful run settles COMPLETED, or STOPPED when a stop had been requested; a failed one
 * settles FAILED.
 */
#[Instantiatable]
class Task_Run_Outcome
{
    public function __construct(
        public readonly bool $success,
        public readonly int $return_code,
        public readonly ?string $error = null,
        public readonly ?Throwable $throwable = null
    ) {
    }

    /**
     * The outcome a task's return value means.
     */
    public static function from_return(mixed $value): self
    {
        if ($value === null || $value === true || $value === 0) {
            return new self(true, 0);
        }

        if ($value === false) {
            return new self(false, 1, 'The task returned false.');
        }

        if (is_int($value)) {
            return new self(false, $value, "The task returned exit code {$value}.");
        }

        return new self(false, 1, 'The task returned ' . get_debug_type($value) . '; a task returns null, true, false or an integer'
            . ' and reports data with summary(), state() or an attachment.');
    }

    /**
     * The outcome of a task that threw.
     */
    public static function from_throwable(Throwable $e): self
    {
        return new self(false, 1, get_class($e) . ': ' . $e->getMessage(), $e);
    }

    /**
     * The process exit code a console runner exits with: 0, or the return code clamped to
     * 1..255 (a negative or oversized code still exits as a failure).
     */
    public function exit_code(): int
    {
        if ($this->success) {
            return 0;
        }

        return $this->return_code >= 1 && $this->return_code <= 255 ? $this->return_code : 1;
    }
}
