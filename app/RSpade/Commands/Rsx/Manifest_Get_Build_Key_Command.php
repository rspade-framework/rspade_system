<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Manifest\Manifest;
use Illuminate\Console\Command;

/**
 * rsx:manifest:get_build_key - print the manifest build key and nothing else.
 *
 * The build key is the hash that identifies the current code state: every scanned source
 * file contributes to it, so two boxes (or two moments on one box) with the same key are
 * running the same code. Booting the framework builds the manifest when it is absent or
 * stale, which is why this command never has to ask - by the time handle() runs the key
 * exists. Silent success: the only output is the key, so a script can capture it.
 *
 * The full framework test suite records its results under this key (rsx:man testing,
 * system/bin/rsx-testd/CLAUDE.md), and a sealed build's seal carries it (rsx:man app_mode).
 */
class Manifest_Get_Build_Key_Command extends Command
{
    protected $signature = 'rsx:manifest:get_build_key';

    protected $description = 'Print the manifest build key (the hash of the current code state)';

    public function handle()
    {
        Manifest::init();

        $this->line(Manifest::get_build_key());

        return 0;
    }
}
