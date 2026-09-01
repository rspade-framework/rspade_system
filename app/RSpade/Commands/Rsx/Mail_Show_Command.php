<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Files\File_Storage_Model;
use App\RSpade\Core\Models\Email_Queue_Model;
use Illuminate\Console\Command;

/**
 * rsx:mail:show - everything one queue row knows about itself.
 *
 * THE RENDERED BODIES ARE REPORTED BY LENGTH, NOT PRINTED. A rendered email is tens of
 * kilobytes of inlined HTML; dumping it into a terminal buries the fields somebody
 * opened this command to read. --json carries them in full, which is the shape anything
 * that actually wants the body is using anyway.
 *
 * See: php artisan rsx:man email
 */
class Mail_Show_Command extends Command
{
    protected $signature = 'rsx:mail:show
                            {id : The email_queue row id}
                            {--json : Machine-readable JSON output, including the rendered bodies}';

    protected $description = 'Show every recorded detail of one queued email';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $json = (bool) $this->option('json');

        $record = Email_Queue_Model::find($id);

        if ($record === null) {
            $this->error("[ERROR] There is no email queue row #{$id}.");

            return 1;
        }

        $attachments = $this->__attachments($record);

        $payload = [
            'id' => (int) $record->id,
            'site_id' => (int) $record->site_id,
            'status' => $record->status_id__label,
            'category' => $record->category_id__label,
            'email_class' => $record->email_class,
            'subject' => $record->subject,
            'to_address' => $record->to_address,
            'to_name' => $record->to_name,
            'dev_original_to' => $record->dev_original_to,
            'reply_to' => $record->reply_to,
            'reply_to_name' => $record->reply_to_name,
            'cc' => $record->cc,
            'bcc' => $record->bcc,
            'headers' => $record->headers,
            'template_data' => $record->template_data,
            'dedupe_key' => $record->dedupe_key,
            'related_type' => $record->related_type,
            'related_id' => $record->related_id,
            'attempt_count' => (int) $record->attempt_count,
            'last_error' => $record->last_error,
            'message_id_header' => $record->message_id_header,
            'transport' => $record->transport,
            'transport_response' => $record->transport_response,
            'next_attempt_at' => $record->next_attempt_at,
            'last_attempt_at' => $record->last_attempt_at,
            'sent_at' => $record->sent_at,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
            'rendered_html_length' => strlen((string) $record->rendered_html),
            'rendered_text_length' => strlen((string) $record->rendered_text),
            'attachments' => $attachments,
        ];

        if ($json) {
            $payload['rendered_html'] = $record->rendered_html;
            $payload['rendered_text'] = $record->rendered_text;

            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->__render($payload);

        return 0;
    }

    /**
     * Each attachment row, with the size of the blob it points at.
     */
    private function __attachments(Email_Queue_Model $record): array
    {
        $attachments = [];

        foreach ($record->attachments as $attachment) {
            $storage = $attachment->file_storage_id === null
                ? null
                : File_Storage_Model::find($attachment->file_storage_id);

            $attachments[] = [
                'file_name' => $attachment->file_name,
                'mime_type' => $attachment->mime_type,
                'disposition' => $attachment->disposition_id__label,
                'cid' => $attachment->cid,
                'size' => $storage === null ? null : (int) $storage->size,
            ];
        }

        return $attachments;
    }

    /**
     * Print the report a human reads.
     */
    private function __render(array $payload): void
    {
        $fields = [
            'Queue row' => '#' . $payload['id'],
            'Site' => $payload['site_id'],
            'Status' => $payload['status'],
            'Category' => $payload['category'],
            'Email class' => $payload['email_class'],
            'Subject' => $payload['subject'],
            'To' => $this->__address($payload['to_address'], $payload['to_name']),
            'Originally to' => $payload['dev_original_to'],
            'Reply-To' => $this->__address($payload['reply_to'], $payload['reply_to_name']),
            'CC' => $this->__list($payload['cc']),
            'BCC' => $this->__list($payload['bcc']),
            'Dedupe key' => $payload['dedupe_key'],
            'Related' => $payload['related_id'] === null
                ? null
                : $payload['related_type'] . ':' . $payload['related_id'],
            'Attempts' => $payload['attempt_count'],
            'Transport' => $payload['transport'],
            'Message-ID' => $payload['message_id_header'],
            'Last error' => $payload['last_error'],
            'Next attempt' => $payload['next_attempt_at'],
            'Last attempt' => $payload['last_attempt_at'],
            'Sent at' => $payload['sent_at'],
            'Created at' => $payload['created_at'],
            'Updated at' => $payload['updated_at'],
            'Rendered HTML' => bytes_to_human($payload['rendered_html_length']),
            'Rendered text' => bytes_to_human($payload['rendered_text_length']),
        ];

        foreach ($fields as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $this->line(str_pad($label . ':', 16) . $value);
        }

        if (!empty($payload['headers'])) {
            $this->line('');
            $this->line('Headers:');
            $this->line(json_encode($payload['headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (!empty($payload['template_data'])) {
            $this->line('');
            $this->line('Template data:');
            $this->line(json_encode($payload['template_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if ($payload['transport_response']) {
            $this->line('');
            $this->line('Transport says:');
            foreach (explode("\n", trim((string) $payload['transport_response'])) as $line) {
                $this->line('  ' . $line);
            }
        }

        if ($payload['attachments'] !== []) {
            $this->line('');
            $this->line('Attachments:');

            $rows = [];
            foreach ($payload['attachments'] as $attachment) {
                $rows[] = [
                    $attachment['file_name'],
                    $attachment['mime_type'],
                    $attachment['disposition'],
                    $attachment['cid'] ?: '-',
                    $attachment['size'] === null ? '(released)' : bytes_to_human($attachment['size']),
                ];
            }

            $this->table(['File', 'MIME', 'Disposition', 'CID', 'Size'], $rows);
        }
    }

    /**
     * "Name <address>", or just the address, or null when there is none.
     */
    private function __address(?string $address, ?string $name): ?string
    {
        if (!$address) {
            return null;
        }

        return $name ? $name . ' <' . $address . '>' : $address;
    }

    /**
     * A CC/BCC list rendered on one line.
     */
    private function __list($value): ?string
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        $parts = [];

        foreach ($value as $entry) {
            if (is_array($entry)) {
                $parts[] = $this->__address($entry['address'] ?? null, $entry['name'] ?? null) ?? '';
                continue;
            }

            $parts[] = (string) $entry;
        }

        return implode(', ', array_filter($parts));
    }
}
