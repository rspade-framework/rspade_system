<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Lib\Operations\Health\Rsx_Image_Contract;
use Illuminate\Console\Command;
use RuntimeException;

class Framework_Post_Update_Image_Check_Command extends Command
{
    protected $signature = 'rsx:framework:post_update_image_check';

    protected $description = '[internal] Report when the pulled checkout needs a newer container image than the one running';

    protected $hidden = true;

    public function handle()
    {
        // Runs from the NEW checkout on the pull that delivered it (rsx:framework:post_update).
        // The updater refuses a release whose minimum image version this container's image
        // does not meet before applying it; an updater older than that check applies it
        // anyway, and then the pre-boot gate (bootstrap/rsx_image_contract.php) refuses
        // post_update itself with the same sentence. This is the booted report of the same
        // answer. Silent when the image is current, outside the container, or on the
        // framework authors' box.
        try {
            $problem = Rsx_Image_Contract::problem();
        } catch (RuntimeException $e) {
            $this->line('[ERROR] ' . $e->getMessage());

            return 1;
        }

        if ($problem === null) {
            return 0;
        }

        $this->line('[ERROR] ' . $problem);
        $this->line('  Until then every artisan command (but rsx:framework:pull, rsx:maintenance:*, rsx:git'
            . ' and rsx:health) and every web request refuses with this sentence.');

        return 1;
    }
}
