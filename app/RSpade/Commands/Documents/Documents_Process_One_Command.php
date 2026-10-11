<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Documents;

use App\RSpade\Core\Files\Document_Render_Service;
use App\RSpade\Core\Files\File_Storage_Model;
use Illuminate\Console\Command;

/**
 * rsx:documents:process_one - render and extract ONE blob, in a process of its own.
 *
 * The child half of the document worker, and not an operator command (hidden from `list`):
 * Document_Render_Service::process_storage() spawns it for each blob it takes, having already
 * charged the attempt, and waits for it under the kill limit. One document per process is the
 * point - a document that drives a converter or a reader into the ground takes this process
 * with it and nothing else, and the parent settles the row from what this process left there.
 *
 * It runs the unit of work (render_storage) and reports NOTHING: the row is the result. The
 * queue columns are the parent's; an operator who wants a document processed re-queues it
 * (rsx:documents:rerender) and lets the worker spawn this.
 *
 * Exit 0 whenever the unit of work returned - a document that failed to render is a recorded
 * outcome, not a command failure. Any other ending (a fatal error, the kill) is the parent's to
 * read.
 */
class Documents_Process_One_Command extends Command
{
    protected $signature = 'rsx:documents:process_one
                            {storage_id : The _file_storage id to process}';

    protected $description = 'Internal: process one document blob for the render worker';

    protected $hidden = true;

    public function handle(): int
    {
        $storage_id = (int) $this->argument('storage_id');
        $storage = File_Storage_Model::find($storage_id);

        if (!$storage) {
            $this->error("[ERROR] No _file_storage row with id {$storage_id}");

            return 1;
        }

        Document_Render_Service::render_storage($storage);

        return 0;
    }
}
