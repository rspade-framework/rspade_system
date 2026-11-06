<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use Rsx\Models\File_Attachment_Model;
use App\RSpade\Core\Locks\RsxLocks;
use App\RSpade\Core\Locks\Site_Lock;

/**
 * File Attach Command
 * ====================
 *
 * PURPOSE:
 * Attach an existing file to a different model or update attachment metadata.
 *
 * FEATURES:
 * - Change polymorphic relationship (fileable_type/fileable_id)
 * - Update metadata fields (category, type_meta, meta JSON)
 * - Set fileable_order for ordering
 * - Uses file locking to prevent race conditions
 */
class RsxFileAttachCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:file:attach
                            {key : 64-char hex attachment key}
                            {--model= : Model to attach to (Model:ID)}
                            {--category= : Update fileable_category}
                            {--type-meta= : Update fileable_type_meta}
                            {--meta= : Update fileable_meta JSON}
                            {--order= : Set fileable_order}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Attach file to model or update attachment metadata';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $key = $this->argument('key');

        // Validate at least one option provided
        if (!$this->option('model') &&
            !$this->option('category') &&
            !$this->option('type-meta') &&
            !$this->option('meta') &&
            !$this->option('order')) {
            $this->error('Error: Must specify at least one update option');
            $this->info('');
            $this->info('Update options:');
            $this->info('  --model=Model:ID       Attach to model');
            $this->info('  --category="cat"       Set category');
            $this->info('  --type-meta="meta"     Set type meta');
            $this->info('  --meta=\'{"key":"val"}\'  Set meta JSON');
            $this->info('  --order=1              Set order');
            return 1;
        }

        // Find attachment
        $attachment = File_Attachment_Model::where('key', $key)->first();

        if (!$attachment) {
            $this->error("Error: File attachment not found with key: {$key}");
            return 1;
        }

        // Acquire file write lock
        $lock = RsxLocks::named_write_lock(RsxLocks::LOCK_FILE_WRITE);

        try {
            // The options are read and checked before anything is written.
            $model_spec = $this->option('model');
            if ($model_spec && !str_contains($model_spec, ':')) {
                $this->error('Error: --model format must be Model:ID (e.g., User_Model:42)');
                return 1;
            }

            $meta_json = $this->option('meta');
            $meta = null;
            if ($meta_json) {
                $meta = json_decode($meta_json, true);
                if ($meta === null && json_last_error() !== JSON_ERROR_NONE) {
                    $this->error('Error: Invalid JSON in --meta option');
                    return 1;
                }
            }

            $category = $this->option('category');
            $type_meta = $this->option('type-meta');
            $order = $this->option('order');

            // Under the attachment's site write lock (a command is enforced), re-reading it.
            [$attachment, $updated_fields] = Site_Lock::write((int) $attachment->site_id, function () use ($attachment, $model_spec, $meta_json, $meta, $category, $type_meta, $order) {
                $attachment = File_Attachment_Model::find($attachment->id);
                $updated_fields = [];

                // Update model attachment
                if ($model_spec) {
                    list($model_class, $model_id) = explode(':', $model_spec, 2);
                    $attachment->fileable_type = $model_class;
                    $attachment->fileable_id = (int)$model_id;
                    $updated_fields[] = "Attached to {$model_class}:{$model_id}";
                }

                // Update category
                if ($category) {
                    $attachment->fileable_category = $category;
                    $updated_fields[] = "Category: {$category}";
                }

                // Update type meta
                if ($type_meta) {
                    $attachment->fileable_type_meta = $type_meta;
                    $updated_fields[] = "Type Meta: {$type_meta}";
                }

                // Update meta JSON
                if ($meta_json) {
                    $attachment->set_meta($meta);
                    $updated_fields[] = "Meta JSON updated";
                }

                // Update order
                if ($order !== null) {
                    $attachment->fileable_order = (int)$order;
                    $updated_fields[] = "Order: " . (int)$order;
                }

                $attachment->save();

                return [$attachment, $updated_fields];
            }, 'File attachment updated from the command line');

            // Output success
            $this->info('');
            $this->info('[OK] File attachment updated');
            $this->info('');
            $this->info("  File: {$attachment->file_name}");
            $this->info("  Key:  {$attachment->key}");
            $this->info('');
            $this->info('Updated fields:');
            foreach ($updated_fields as $field) {
                $this->info("  - {$field}");
            }
            $this->info('');

            return 0;

        } finally {
            RsxLocks::release_lock($lock);
        }
    }
}
