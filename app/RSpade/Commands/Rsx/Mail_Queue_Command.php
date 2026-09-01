<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Mail\Rsx_Mail_Transport;
use App\RSpade\Core\Models\Email_Queue_Model;
use Illuminate\Console\Command;

/**
 * rsx:mail:queue - what is in the outgoing email queue right now.
 *
 * TWO QUESTIONS, TWO SHAPES. With no flags this answers "is the queue healthy" with a
 * count per status and the age of the oldest thing still waiting - the numbers that say
 * whether mail is moving. With a --status or --recipient it answers "what happened to
 * that message", listing rows newest first.
 *
 * THE LISTING IS BOUNDED ON PURPOSE. --limit is the caller saying how many rows they
 * want to look at, which is the one shape a LIMIT is always right in (see the Do The
 * Whole Job mandate): nothing here processes a set, it prints one.
 *
 * See: php artisan rsx:man email
 */
class Mail_Queue_Command extends Command
{
    protected $signature = 'rsx:mail:queue
                            {--status= : pending|sending|sent|failed|blocked|suppressed}
                            {--recipient= : Substring of the recipient address (matches the redirected and the original)}
                            {--limit=25 : How many rows to list}
                            {--json : Machine-readable JSON output}';

    protected $description = 'Summarise the outgoing email queue, or list the rows matching a filter';

    /** Status name -> id, the vocabulary --status accepts. */
    private const STATUS_NAMES = [
        'pending' => Email_Queue_Model::STATUS_PENDING,
        'sending' => Email_Queue_Model::STATUS_SENDING,
        'sent' => Email_Queue_Model::STATUS_SENT,
        'failed' => Email_Queue_Model::STATUS_FAILED,
        'blocked' => Email_Queue_Model::STATUS_BLOCKED,
        'suppressed' => Email_Queue_Model::STATUS_SUPPRESSED,
    ];

    public function handle(): int
    {
        $status = trim((string) $this->option('status'));
        $recipient = trim((string) $this->option('recipient'));
        $json = (bool) $this->option('json');

        if ($status !== '' && !isset(self::STATUS_NAMES[strtolower($status)])) {
            $this->error("[ERROR] '{$status}' is not a status.");
            $this->line('Statuses: ' . implode(', ', array_keys(self::STATUS_NAMES)));

            return 1;
        }

        if ($status === '' && $recipient === '') {
            return $this->__summary($json);
        }

        return $this->__listing($status, $recipient, $json);
    }

    /**
     * The count per status, plus how long the oldest unsent message has been waiting.
     */
    private function __summary(bool $json): int
    {
        $counts = [];
        $total = 0;

        foreach (self::STATUS_NAMES as $name => $id) {
            $count = Email_Queue_Model::where('status_id', $id)->count();
            $counts[$name] = $count;
            $total += $count;
        }

        $oldest = Email_Queue_Model::where('status_id', Email_Queue_Model::STATUS_PENDING)
            ->orderBy('created_at', 'asc')
            ->first();

        $payload = [
            'delivery' => Rsx_Mail_Transport::delivery_mode(),
            'counts' => $counts,
            'total' => $total,
            'oldest_pending' => $oldest === null ? null : [
                'id' => (int) $oldest->id,
                'created_at' => (string) $oldest->created_at,
                'age' => duration_to_human(max(0, time() - strtotime((string) $oldest->created_at))),
            ],
        ];

        if ($json) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->line('Delivery mode:  ' . $payload['delivery']);
        $this->line('');

        $rows = [];
        foreach ($counts as $name => $count) {
            $rows[] = [$name, $count];
        }
        $rows[] = ['total', $total];

        $this->table(['Status', 'Rows'], $rows);

        if ($oldest !== null) {
            $this->line(
                'Oldest pending: #' . $payload['oldest_pending']['id']
                . ' queued ' . $payload['oldest_pending']['age'] . ' ago'
            );
        }

        return 0;
    }

    /**
     * The rows matching the filters, newest first.
     */
    private function __listing(string $status, string $recipient, bool $json): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $query = Email_Queue_Model::query()->orderBy('created_at', 'desc');

        if ($status !== '') {
            $query->where('status_id', self::STATUS_NAMES[strtolower($status)]);
        }

        if ($recipient !== '') {
            // The ORIGINAL address matters as much as the envelope's: on a dev host the
            // envelope may point at a catchall, and searching for the person the message
            // was really for would find nothing.
            $query->where(function ($q) use ($recipient) {
                $q->where('to_address', 'like', '%' . $recipient . '%')
                    ->orWhere('dev_original_to', 'like', '%' . $recipient . '%');
            });
        }

        $records = $query->limit($limit)->get();

        $payload = [];

        foreach ($records as $record) {
            $payload[] = [
                'id' => (int) $record->id,
                'status' => $record->status_id__label,
                'to_address' => $record->to_address,
                'dev_original_to' => $record->dev_original_to,
                'subject' => $record->subject,
                'attempt_count' => (int) $record->attempt_count,
                'last_attempt_at' => $record->last_attempt_at,
                'sent_at' => $record->sent_at,
                'last_error' => $record->last_error,
            ];
        }

        if ($json) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($payload === []) {
            $this->line('No matching messages.');

            return 0;
        }

        $rows = [];

        foreach ($payload as $row) {
            $rows[] = [
                $row['id'],
                $row['status'],
                $this->__truncate($row['to_address'], 32),
                $this->__truncate((string) $row['subject'], 40),
                $row['attempt_count'],
                $row['sent_at'] ?: ($row['last_attempt_at'] ?: '-'),
                $this->__truncate((string) $row['last_error'], 40),
            ];
        }

        $this->table(
            ['ID', 'Status', 'To', 'Subject', 'Att', 'Sent / last attempt', 'Last error'],
            $rows
        );

        if (count($rows) === $limit) {
            $this->line('(showing ' . $limit . ' - raise --limit for more)');
        }

        return 0;
    }

    /**
     * $text, shortened to $length characters with an ellipsis when it does not fit.
     */
    private function __truncate(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - 3) . '...';
    }
}
