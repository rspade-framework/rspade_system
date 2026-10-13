<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Manifest\Manifest;
use Illuminate\Console\Command;

/**
 * rsx:manifest:get_state_key - print the state key and nothing else.
 *
 * The state key is the cache-break key: the code key (rsx:manifest:get_build_key) combined
 * with the migrations applied to the connected database. A rebuild that changed code moves
 * both; a migration moves this one and leaves the code key - and the build it names -
 * alone. Silent success: the only output is the key, so a script can capture it and compare
 * it across a migration.
 */
class Manifest_Get_State_Key_Command extends Command
{
    protected $signature = 'rsx:manifest:get_state_key';

    protected $description = 'Print the state key (the code key combined with the applied migrations)';

    public function handle()
    {
        Manifest::init();

        $this->line(Manifest::get_state_key());

        return 0;
    }
}
