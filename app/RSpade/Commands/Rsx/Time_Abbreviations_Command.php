<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Time\Rsx_Timestamp_Format;

/**
 * rsx:time:abbreviations - regenerate the browser's zone abbreviation table from PHP's own tz
 * database, so Rsx_Time prints the same zone names in both languages.
 *
 * Writes Core/Time/vendor/rsx_timezone_abbreviations.js (Rsx_Timestamp_Format::
 * abbreviation_table_js()). Framework authoring only: the file is part of system/, which a
 * downstream install never edits, so the command refuses anywhere but a framework-development
 * tree. Run it when PHP's tz database moves (a PHP upgrade) - the framework test
 * Rsx_Timestamp_Format_Test fails until it is.
 */
class Time_Abbreviations_Command extends Command
{
    protected $signature = 'rsx:time:abbreviations';

    protected $description = "Regenerate the browser's timezone abbreviation table from PHP's tz database (framework development)";

    public function handle()
    {
        if (!config('rsx.code_quality.is_framework_developer', false)) {
            $this->error('rsx:time:abbreviations writes a file under system/ and runs only on a framework-development tree (IS_FRAMEWORK_DEVELOPER=true).');

            return 1;
        }

        $path = Rsx_Timestamp_Format::abbreviation_table_js_path();
        $content = Rsx_Timestamp_Format::abbreviation_table_js();

        if (is_file($path) && file_get_contents($path) === $content) {
            return 0;
        }

        file_put_contents($path, $content);
        $this->line('[OK] Wrote ' . $path);

        return 0;
    }
}
