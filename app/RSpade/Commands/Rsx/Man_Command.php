<?php

namespace App\RSpade\Commands\Rsx;

use Illuminate\Console\Command;

class Man_Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rsx:man {term? : The documentation term to look up (partial matches allowed)}
        {--tag=* : List only the topics carrying this tag (repeatable: a topic must carry every tag named)}
        {--tags : List the shared tag vocabulary, with how many topics carry each tag}
        {--agent-helper-message : Output agent helper message with available topics}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display RSX documentation from man pages (pretty formatted)';

    /**
     * THE SHARED TAG VOCABULARY: a man page's TAGS section names one or more of these, so the
     * framework and every application tag a subject the same way. An application may tag its
     * own pages with tags of its own besides; `rsx:man --tags` lists those separately.
     *
     * @var array<string,string> tag => what a page carrying it is about
     */
    public const TAG_VOCABULARY = [
        'api' => 'the external API: endpoints, keys, scopes',
        'auth' => 'sign-in, second factors, SSO, passwords, auth gates',
        'build' => 'the manifest, bundles, compilation, sealed builds',
        'components' => 'jqhtml components and their lifecycle',
        'config' => 'configuration, .env, paths',
        'debugging' => 'rsx:debug, console_debug, diagnostics',
        'deploy' => 'production, modes, hosting, upgrades, maintenance',
        'files' => 'uploads, attachments, documents, previews',
        'forms' => 'forms, inputs, validation, modals',
        'mail' => 'email and SMS',
        'models' => 'the ORM, model fetch, enums, revisions, text types',
        'permissions' => 'roles, ACLs, permission checks',
        'portal' => 'the client portal',
        'realtime' => 'realtime frames, subscriptions, emitters',
        'schema' => 'migrations, tables, columns',
        'security' => 'threats, hardening, CSP, throttles, secrets',
        'sessions' => 'the Session facade, sign-in lifetimes, session values',
        'spa' => 'SPA routing, actions, layouts, navigation',
        'stdlib' => 'the shared helper library in PHP and JS',
        'tasks' => 'background tasks, schedules, locks, commands',
        'tenancy' => 'sites, tenancy, site scoping',
        'testing' => 'the test suites and their harness',
        'time' => 'dates, times, timezones',
        'ui' => 'styling, SCSS, theme, responsive, UI widgets',
    ];

    /**
     * The documentation directory paths.
     *
     * @var array
     */
    protected $docs_dirs;

    /**
     * Create a new command instance.
     *
     * Directories are listed in priority order - first match wins for same-named files.
     * Project-specific docs in rsx/resource/man override framework docs in app/RSpade/man.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        // Priority order: project-specific > user docs > framework docs
        $this->docs_dirs = [
            base_path('../rsx/resource/man'),   // Project-specific (highest priority)
            base_path('docs/man'),               // User docs
            base_path('app/RSpade/man'),         // Framework docs (lowest priority)
        ];
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Check if agent helper message was requested
        if ($this->option('agent-helper-message')) {
            return $this->show_agent_helper_message();
        }

        // Create docs/man directory if it doesn't exist (first dir only)
        if (!is_dir($this->docs_dirs[0])) {
            mkdir($this->docs_dirs[0], 0755, true);
        }

        if ($this->option('tags')) {
            return $this->list_tags();
        }

        $tags = array_values(array_filter(array_map(fn ($tag) => strtolower(trim((string) $tag)), (array) $this->option('tag'))));
        if ($tags !== []) {
            return $this->list_all_documentation($tags);
        }

        $term = $this->argument('term');

        // If no term provided, list all available documentation
        if (!$term) {
            return $this->list_all_documentation();
        }

        // Find matching documentation files
        $matches = $this->find_matching_files($term);

        if (count($matches) === 0) {
            $this->error("No documentation found for '{$term}'");
            $this->line('');
            $this->line('Available documentation:');
            $this->list_all_documentation();
            return 1;
        }

        if (count($matches) === 1) {
            // Display the single matching file
            return $this->display_documentation($matches[0]);
        }

        // Multiple matches found - list them
        $this->warn("Multiple matches found for '{$term}':");
        $this->line('');
        foreach ($matches as $file) {
            $name = basename($file, '.txt');
            $this->line("  - <info>{$name}</info>");
        }
        $this->line('');
        $this->line('Please specify a more specific term.');
        return 1;
    }

    /**
     * The tags a man page carries: its TAGS section, one indented line of lowercase,
     * comma-separated tags right after the heading. An untagged page carries none.
     *
     * @param string $content
     * @return string[]
     */
    public static function page_tags(string $content): array
    {
        if (!preg_match('/^TAGS[ \t]*\n[ \t]+(\S[^\n]*)$/m', $content, $matches)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($tag) => strtolower(trim($tag)),
            explode(',', $matches[1])
        ))));
    }

    /**
     * `rsx:man --tags`: the shared vocabulary with a count per tag, then any tag an
     * application's pages use that the vocabulary does not have.
     *
     * @return int
     */
    protected function list_tags(): int
    {
        $counts = [];
        foreach ($this->get_all_files_deduplicated() as $file) {
            foreach (static::page_tags((string) file_get_contents($file)) as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        $this->info('Man page tags:');
        $this->line('');
        foreach (self::TAG_VOCABULARY as $tag => $meaning) {
            $this->line(sprintf("  <info>%-13s</info> %3d  %s", $tag, $counts[$tag] ?? 0, $meaning));
        }

        $other = array_diff_key($counts, self::TAG_VOCABULARY);
        if ($other !== []) {
            ksort($other);
            $this->line('');
            $this->info('Other tags (not in the shared vocabulary):');
            foreach ($other as $tag => $count) {
                $this->line(sprintf("  <info>%-13s</info> %3d", $tag, $count));
            }
        }

        $this->line('');
        $this->line('Usage: <comment>php artisan rsx:man --tag=<tag></comment> (repeat --tag to require several)');

        return 0;
    }

    /**
     * List the available documentation files - every one, or with $tags, only the pages
     * carrying every tag named.
     *
     * @param string[] $tags
     * @return int
     */
    protected function list_all_documentation(array $tags = [])
    {
        $files = $this->get_all_files_deduplicated();

        if (empty($files)) {
            $this->warn('No documentation files found in:');
            foreach ($this->docs_dirs as $dir) {
                $this->line('  - ' . $dir);
            }
            return 1;
        }

        $this->info($tags === [] ? 'Available Documentation:' : 'Documentation tagged ' . implode(' + ', $tags) . ':');
        $this->line('');

        $docs = [];
        foreach ($files as $file) {
            $name = basename($file, '.txt');
            // Read file to extract description from NAME section
            $content = file_get_contents($file);
            $description = '';

            if ($tags !== [] && array_diff($tags, static::page_tags($content)) !== []) {
                continue;
            }

            // Look for NAME section in man page format
            if (preg_match('/^NAME\s*\n\s*(.+?)(?:\n|$)/m', $content, $matches)) {
                $description = trim($matches[1]);
                // Remove the name part, keep just description after dash
                if (str_contains($description, ' - ')) {
                    $parts = explode(' - ', $description, 2);
                    $description = trim($parts[1]);
                }
            } elseif (preg_match('/^#\s*(.+?)(?:\n|$)/m', $content, $matches)) {
                // @PHP-FALLBACK-01-EXCEPTION - Fallback* parsing for man page description extraction
                // Fallback to first header line
                $description = trim($matches[1]);
            } else {
                // Fallback to first non-empty line
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (!empty($lines)) {
                    foreach ($lines as $line) {
                        if (!str_starts_with($line, '#') && !str_starts_with($line, '=')) {
                            $description = trim($line);
                            break;
                        }
                    }
                }
            }

            if (strlen($description) > 60) {
                $description = substr($description, 0, 57) . '...';
            }
            $docs[] = ['name' => $name, 'description' => $description];
        }

        // Sort alphabetically
        usort($docs, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        // Display in a nice table format
        foreach ($docs as $doc) {
            $this->line(sprintf("  <info>%-25s</info> %s", $doc['name'], $doc['description']));
        }

        if ($tags !== [] && $docs === []) {
            $this->line('  (none - rsx:man --tags lists the tags in use)');
        }

        $this->line('');
        $this->line('Usage: <comment>php artisan rsx:man <term></comment>  |  <comment>rsx:man --tag=<tag></comment>  |  <comment>rsx:man --tags</comment>');

        return 0;
    }

    /**
     * Get all documentation files, deduplicated by filename.
     * Files from earlier directories in docs_dirs take precedence.
     * Excludes .expect files (behavioral expectation documentation for testing).
     *
     * @return array
     */
    protected function get_all_files_deduplicated(): array
    {
        $files_by_name = [];
        foreach ($this->docs_dirs as $dir) {
            if (is_dir($dir)) {
                $dir_files = glob($dir . '/*.txt');
                if ($dir_files) {
                    foreach ($dir_files as $file) {
                        // Skip .expect files (behavioral expectation docs)
                        if (str_ends_with($file, '.expect')) {
                            continue;
                        }
                        $name = basename($file, '.txt');
                        // First occurrence wins (higher priority directory)
                        if (!isset($files_by_name[$name])) {
                            $files_by_name[$name] = $file;
                        }
                    }
                }
            }
        }
        return array_values($files_by_name);
    }

    /**
     * Find documentation files matching the given term.
     *
     * @param string $term
     * @return array
     */
    protected function find_matching_files(string $term): array
    {
        $files = $this->get_all_files_deduplicated();

        $term_lower = strtolower($term);
        $matches = [];

        foreach ($files as $file) {
            $filename = basename($file, '.txt');
            $filename_lower = strtolower($filename);

            // Exact match (case-insensitive)
            if ($filename_lower === $term_lower) {
                return [$file]; // Return immediately for exact match
            }

            // Partial match
            if (str_contains($filename_lower, $term_lower)) {
                $matches[] = $file;
            }
        }

        // If no partial matches, try fuzzy matching with underscores/hyphens
        if (empty($matches)) {
            $term_normalized = str_replace(['-', '_', ' '], '', $term_lower);

            foreach ($files as $file) {
                $filename = basename($file, '.txt');
                $filename_normalized = str_replace(['-', '_', ' '], '', strtolower($filename));

                if (str_contains($filename_normalized, $term_normalized)) {
                    $matches[] = $file;
                }
            }
        }

        return $matches;
    }

    /**
     * Display the contents of a documentation file with pretty formatting.
     *
     * @param string $file_path
     * @return int
     */
    protected function display_documentation(string $file_path): int
    {
        if (!file_exists($file_path)) {
            $this->error("Documentation file not found: {$file_path}");
            return 1;
        }

        $content = file_get_contents($file_path);

        // Check if this is a man page format
        $is_man_page = preg_match('/^[A-Z_]+\(\d+\)/', $content);

        $lines = explode("\n", $content);

        $this->newLine();

        $in_code_block = false;
        $in_section = false;
        $in_example = false;

        foreach ($lines as $line) {
            // Skip man page headers/footers. Both forms end in a TOPIC(N) token
            // after a wide column gap: the opening title also STARTS with TOPIC(N)
            // (TOPIC(N)  Manual  TOPIC(N)), while the closing footer starts with the
            // issuer/date instead (RSX Framework  <date>  TOPIC(N)). Anchoring on the
            // trailing token + the multi-space column gap catches both without eating
            // prose or comma-separated SEE ALSO lines that merely contain a foo(3) ref.
            if ($is_man_page && preg_match('/^\S.*\s{2,}[A-Z_]+\(\d+\)$/', $line)) {
                continue;
            }

            // Detect code examples in man pages (indented 4+ spaces)
            if ($is_man_page && !$in_code_block) {
                if (preg_match('/^    /', $line)) {
                    // Just display indented code without boxes
                    $this->line('    ' . substr($line, 4));
                    continue;
                }
            }
            // Handle code blocks
            if (str_starts_with($line, '```')) {
                $in_code_block = !$in_code_block;
                continue;
            }

            if ($in_code_block) {
                $this->line('    ' . $line);
                continue;
            }

            // Handle man page section headers (all caps)
            if ($is_man_page && preg_match('/^[A-Z][A-Z\s_]+$/', $line) && strlen($line) < 40) {
                if ($in_section) {
                    $this->newLine();
                }
                $this->line(strtoupper($line));
                $in_section = true;
                continue;
            }

            // Handle headers
            if (str_starts_with($line, '# ')) {
                $header = substr($line, 2);
                $this->newLine();
                $this->line(strtoupper($header));
                $this->newLine();
                $in_section = true;
                continue;
            }

            if (str_starts_with($line, '## ')) {
                $header = substr($line, 3);
                if ($in_section) {
                    $this->newLine();
                }
                $this->line($header);
                continue;
            }

            if (str_starts_with($line, '### ')) {
                $header = substr($line, 4);
                $this->line($header);
                continue;
            }

            // Handle lists
            if (preg_match('/^(\s*)[•\-\*]\s+(.+)/', $line, $matches)) {
                $indent = str_repeat(' ', strlen($matches[1]));
                $this->line($indent . '- ' . $matches[2]);
                continue;
            }

            // Handle numbered lists
            if (preg_match('/^(\s*)(\d+)\.\s+(.+)/', $line, $matches)) {
                $indent = str_repeat(' ', strlen($matches[1]));
                $this->line($indent . $matches[2] . '. ' . $matches[3]);
                continue;
            }

            // Handle separator lines
            if (preg_match('/^[\-=]{3,}$/', $line)) {
                $this->line('');
                continue;
            }

            // Regular text
            if (trim($line) === '') {
                $this->line('');
            } else {
                $this->line($line);
            }
        }

        $this->newLine();

        return 0;
    }

    /**
     * Show agent helper message with available topics
     *
     * @return int
     */
    protected function show_agent_helper_message(): int
    {
        $this->line('Comprehensive documentation is available for the following commands, and can be reviewed by calling "php artisan rsx:man (topic)". This text should be referenced whenever doing advanced integration work with these subsections to get necessary context on how the subsystems work before proceeding with edits, implementations, or generally using the subsystems.');
        $this->line('');

        // Get all available topics
        $topics = $this->get_all_topic_names();
        $this->line(implode(' ', $topics));

        $this->line('');

        return 0;
    }

    /**
     * Get all available topic names
     *
     * @return array
     */
    protected function get_all_topic_names(): array
    {
        $files = $this->get_all_files_deduplicated();

        $topics = [];
        foreach ($files as $file) {
            $topics[] = basename($file, '.txt');
        }

        sort($topics);
        return $topics;
    }
}