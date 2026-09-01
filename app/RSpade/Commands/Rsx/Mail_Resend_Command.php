<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Task\Task;
use Illuminate\Console\Command;

/**
 * rsx:mail:resend - put a finished message back on the queue.
 *
 * The row keeps everything that made it that message - its frozen subject and data, its
 * attachments, its id - and loses only the state that stops the drain looking at it.
 * This is the remedy the stale sweep names in last_error, and the ordinary answer to a
 * FAILED row once whatever broke has been fixed.
 *
 * A BLOCKED ROW IS DIFFERENT, AND --force IS NOT A FORMALITY. Blocked means the
 * recipient asked not to receive this category. That is a consent record, and a command
 * that quietly overrode it would make the unsubscribe link a lie - so resending one is
 * possible (an operator may genuinely have to reissue a transactional message that was
 * miscategorised) but never accidental.
 *
 * A PENDING or SENDING row is refused because there is nothing to do: the queue already
 * has it, and resetting a row a drain is mid-way through would send it twice.
 *
 * See: php artisan rsx:man email
 */
class Mail_Resend_Command extends Command
{
    protected $signature = 'rsx:mail:resend
                            {id : The email_queue row id}
                            {--force : Resend even though the recipient has unsubscribed (BLOCKED rows)}';

    protected $description = 'Reset a finished email queue row to PENDING and drain the queue';

    public function handle(): int
    {
        $id = (int) $this->argument('id');

        $record = Email_Queue_Model::find($id);

        if ($record === null) {
            $this->error("[ERROR] There is no email queue row #{$id}.");

            return 1;
        }

        $status_id = (int) $record->status_id;

        if ($status_id === Email_Queue_Model::STATUS_PENDING
            || $status_id === Email_Queue_Model::STATUS_SENDING
        ) {
            $this->line(
                "#{$id} is already {$record->status_id__label} - the queue has it. Nothing to do."
            );

            return 0;
        }

        if ($status_id === Email_Queue_Model::STATUS_BLOCKED && !$this->option('force')) {
            $this->error(
                "[WARNING] #{$id} is Blocked: {$record->to_address} has unsubscribed from "
                . $record->category_id__label . ' email.'
            );
            $this->line('That is a consent record, not a delivery failure.');
            $this->line("Re-send it anyway with: php artisan rsx:mail:resend {$id} --force");

            return 1;
        }

        $record->reset_for_resend();

        Task::dispatch('Mail_Queue_Service', 'send_pending_queue');

        $this->line("#{$id} to {$record->to_address} is now {$record->status_id__label} (attempts reset to 0).");
        $this->line('The queue drain has been dispatched; check it with:');
        $this->line("  php artisan rsx:mail:show {$id}");

        return 0;
    }
}
