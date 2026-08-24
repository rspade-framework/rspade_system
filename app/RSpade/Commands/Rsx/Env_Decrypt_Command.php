<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Foundation\Console\EnvironmentDecryptCommand;
use Illuminate\Support\Str;

/**
 * env:decrypt, writing to the PROJECT ROOT.
 *
 * Laravel's encrypt/decrypt pair is asymmetric about where the environment file
 * lives. env:encrypt reads $app->environmentFilePath() and writes the .encrypted
 * file beside it; stock env:decrypt defaults its OUTPUT directory to base_path()
 * instead. In RSpade base_path() is system/ - a git submodule that every
 * framework pull resets and cleans - so a stock decrypt would drop the recovered
 * .env into the one directory guaranteed to lose it, next to an .encrypted file
 * the encrypt step had written at the project root.
 *
 * bootstrap/app.php anchors environmentPath() at the project root, which fixes
 * env:encrypt and key:generate outright. This override is the remaining half:
 * the default output directory becomes environmentPath(), so decrypt writes the
 * .env back exactly where encrypt read it from. --path and --filename keep their
 * stock meaning and still override.
 *
 * The parent is a VENDOR class, so PHP-PARENT-CHAIN-01 does not require a
 * parent:: call here (and calling it would defeat the override).
 */
class Env_Decrypt_Command extends EnvironmentDecryptCommand
{
    /**
     * Where a decrypted environment file is written when --path is not given.
     *
     * @return string
     */
    protected function outputFilePath()
    {
        $path = Str::finish($this->option('path') ?: $this->laravel->environmentPath(), DIRECTORY_SEPARATOR);

        $output_file = $this->option('filename') ?: ('.env' . ($this->option('env') ? '.' . $this->option('env') : ''));
        $output_file = ltrim($output_file, DIRECTORY_SEPARATOR);

        return $path . $output_file;
    }
}
