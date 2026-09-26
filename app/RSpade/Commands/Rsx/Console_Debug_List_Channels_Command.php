<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;
use App\RSpade\Core\Debug\Console_Debug_Channels;

class Console_Debug_List_Channels_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:console_debug:list_channels
                            {--php : Show only PHP channels}
                            {--js : Show only JavaScript channels}
                            {--count : Include usage count for each channel}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all console_debug channels used in the codebase';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $show_php = $this->option('php');
        $show_js = $this->option('js');
        $show_count = $this->option('count');

        // If neither specified, show both
        if (!$show_php && !$show_js) {
            $show_php = true;
            $show_js = true;
        }

        if ($show_php) {
            $this->info('Scanning PHP files...');
        }
        if ($show_js) {
            $this->info('Scanning JavaScript files...');
        }

        $channels = Console_Debug_Channels::scan($show_php, $show_js);

        // Display results
        $this->newLine();
        $this->info('Found ' . count($channels) . ' unique channels:');
        $this->newLine();

        // Prepare table data
        $table_data = [];
        foreach ($channels as $channel => $info) {
            $row = [$channel];

            // Add language column
            $languages = [];
            if ($info['php'] > 0) $languages[] = 'PHP';
            if ($info['js'] > 0) $languages[] = 'JS';
            $row[] = implode(', ', $languages);

            // Add count if requested
            if ($show_count) {
                $total = $info['php'] + $info['js'];
                $row[] = $total;
                if ($show_php && $show_js) {
                    $row[] = $info['php'];
                    $row[] = $info['js'];
                }
            }

            $table_data[] = $row;
        }

        // Build headers
        $headers = ['Channel', 'Language'];
        if ($show_count) {
            $headers[] = 'Total';
            if ($show_php && $show_js) {
                $headers[] = 'PHP';
                $headers[] = 'JS';
            }
        }

        $this->table($headers, $table_data);

        return Command::SUCCESS;
    }
}
