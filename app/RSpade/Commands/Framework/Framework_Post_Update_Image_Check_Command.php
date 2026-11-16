<?php

namespace App\RSpade\Commands\Framework;

use App\RSpade\Lib\Operations\Health\Rsx_Image_Contract;
use Illuminate\Console\Command;
use RuntimeException;

class Framework_Post_Update_Image_Check_Command extends Command
{
    protected $signature = 'rsx:framework:post_update_image_check';

    protected $description = '[internal] Warn when the pulled checkout needs a newer container image than the one running';

    protected $hidden = true;

    public function handle()
    {
        // Runs from the NEW checkout on the pull that delivered it, so a checkout that needs a
        // newer image than this container runs says so before maintenance mode is lifted.
        // Advisory: the services are started either way, and rsx:health's "Container Image"
        // row keeps reporting it. Silent when the image is current or outside the container.
        try {
            $problem = Rsx_Image_Contract::problem();
        } catch (RuntimeException $e) {
            $this->line('[ERROR] ' . $e->getMessage());

            return 1;
        }

        if ($problem === null) {
            return 0;
        }

        $this->line('[WARNING] ' . $problem);
        $this->line('  Services this image cannot run will not start until the container runs an image'
            . ' built from this checkout.');

        return 0;
    }
}
