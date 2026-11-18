<?php

namespace App\RSpade\Commands\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use App\RSpade\Core\Database\Models\Site_Scoped;
use App\RSpade\Core\Database\Rsx_Schema;
use App\RSpade\Core\Manifest\Manifest;

/**
 * rsx:db:check_indexes - which indexes the application's queries and relationships need
 * and do not have.
 *
 * This class GATHERS: the models and their site scope, the live schema and every table's
 * existing indexes, the application's source files and its relationships. Every decision -
 * what a query chain requires, whether an index serves it, what to recommend - is
 * Index_Advisor's, as pure functions over the context assembled here (rsx:man model_indexes).
 *
 * Nothing the analysis could not read is skipped silently: a source file that does not parse
 * and a relationship that cannot be introspected are counted in a [WARNING] line and listed
 * under --show-details, because their requirements are unknown, not absent.
 */
class Check_Indexes_Command extends Command
{
    protected $signature = 'rsx:db:check_indexes
        {--generate-migration : Write a reviewed migration creating the recommended indexes (template tables to rsx/resource/migrations, framework tables to system/database/migrations)}
        {--table= : Report only this table}
        {--format=text : Output format (text|json)}
        {--no-relationships : Skip relationship analysis}
        {--no-queries : Skip query pattern analysis}
        {--include-system : Also analyze framework tables (_-prefixed, or declared by a framework model)}
        {--include-dev : Also count development-only sources (rsx/app/dev and seeders) as reasons}
        {--include-low-value : Put low-value recommendations in the generated migration too}
        {--show-all : Show every recommendation even past the per-table quota}
        {--show-details : List every call site, every excluded or unreadable source, and every chain not analyzed}';

    protected $description = 'Analyze database index requirements from relationships and query patterns';

    /**
     * Maximum indexes allowed per table (MySQL InnoDB limit)
     */
    const MAX_INDEXES_PER_TABLE = 64;

    /**
     * Available quota for auto-generated indexes per table
     */
    const AUTO_INDEX_QUOTA = 32;

    /**
     * The analysis context (Index_Advisor's CONTEXT shape).
     */
    protected array $context = ['models' => [], 'tables' => []];

    /**
     * Every requirement found: ['requirement', 'source', 'kind', 'type'].
     */
    protected array $items = [];

    /**
     * What the analysis could not read: ['parse' => [file => message], 'relationship' =>
     * ['Model::method' => message]].
     */
    protected array $problems = ['parse' => [], 'relationship' => []];

    /**
     * Sources left out: reason => [file, ...].
     */
    protected array $excluded_sources = [];

    /**
     * Requirements on framework tables left out (without --include-system).
     */
    protected int $excluded_framework = 0;

    /**
     * Chains on a model that could not be analyzed (an OR, a dynamic column, a table absent
     * from the database): reason => [source, ...].
     */
    protected array $unanalyzed_chains = [];

    protected int $files_scanned = 0;

    protected int $relationship_count = 0;

    public function handle()
    {
        $this->_build_context();

        if (!$this->option('no-relationships')) {
            $this->_collect_relationship_requirements();
        }

        if (!$this->option('no-queries')) {
            $this->_collect_query_requirements();
        }

        $result = $this->_analyze();

        if ($this->option('format') === 'json') {
            $this->line(json_encode($this->_json_report($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->_output_text($result);
        }

        if ($this->option('generate-migration')) {
            return $this->_generate_migrations($result);
        }

        return 0;
    }

    // =====================================================================================
    // CONTEXT
    // =====================================================================================

    /**
     * Gather the models (table, site scope, scope flag holder), the live schema and each
     * table's existing indexes.
     */
    protected function _build_context(): void
    {
        $table_framework = [];
        $table_enums = [];

        foreach (Manifest::php_get_extending('Rsx_Model_Abstract') as $metadata) {
            $fqcn = $metadata['fqcn'];
            $facts = static::model_facts($fqcn);

            $this->context['models'][class_basename($fqcn)] = [
                'table' => $facts['table'],
                'site_scoped' => $facts['site_scoped'],
                'scope_holder' => $facts['scope_holder'],
            ];

            $table_framework[$facts['table']] = $facts['framework'];
            $table_enums[$facts['table']] = $facts['enum_columns'];
        }

        foreach (Rsx_Schema::tables() as $table => $columns) {
            $indexes = $this->_read_table_indexes($table);
            $primary_key = [];

            foreach ($indexes as $index) {
                if ($index['name'] === 'PRIMARY') {
                    $primary_key = $index['columns'];
                }
            }

            $this->context['tables'][$table] = [
                'columns' => array_map(fn ($shape) => $shape['type'], $columns),
                'indexes' => $indexes,
                'primary_key' => $primary_key,
                'enum_columns' => $table_enums[$table] ?? [],
                'framework' => str_starts_with($table, '_') || ($table_framework[$table] ?? false),
            ];
        }
    }

    /**
     * What the analysis needs to know about one model class:
     *
     *   table         its table
     *   site_scoped   it adopts Site_Scoped (directly or through Rsx_Site_Model_Abstract)
     *   scope_holder  the class whose without_site_scope() flag governs it (the class that
     *                 adopts the trait - Rsx_Site_Model_Abstract for the whole family beneath
     *                 it), null when not site-scoped
     *   framework     the class that DECLARES its table name is framework source
     *                 (app/RSpade): a core model, or an application class extending a core
     *                 model's base, is a framework table
     *   enum_columns  its declared enum columns
     */
    public static function model_facts(string $fqcn): array
    {
        $site_scoped = in_array(Site_Scoped::class, class_uses_recursive($fqcn), true);

        $scope_holder = $site_scoped
            ? (new \ReflectionProperty($fqcn, 'apply_site_scope'))->getDeclaringClass()->getShortName()
            : null;

        $table_declarer = (new \ReflectionProperty($fqcn, 'table'))->getDeclaringClass()->getFileName();
        $framework_root = realpath(base_path('app/RSpade')) . '/';

        return [
            'table' => $fqcn::get_table_static(),
            'site_scoped' => $site_scoped,
            'scope_holder' => $scope_holder,
            'framework' => str_starts_with(realpath($table_declarer), $framework_root),
            'enum_columns' => array_keys($fqcn::enum_columns()),
        ];
    }

    /**
     * A table's indexes, each ['name', 'unique', 'columns' (in index order)].
     */
    protected function _read_table_indexes(string $table): array
    {
        $indexes = [];

        foreach (DB::select("SHOW INDEX FROM `{$table}`") as $row) {
            $name = $row->Key_name;

            if (!isset($indexes[$name])) {
                $indexes[$name] = ['name' => $name, 'unique' => $row->Non_unique == 0, 'columns' => []];
            }

            $indexes[$name]['columns'][$row->Seq_in_index - 1] = $row->Column_name;
        }

        foreach ($indexes as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }

        return array_values($indexes);
    }

    // =====================================================================================
    // COLLECTION
    // =====================================================================================

    /**
     * Requirements from every model's relationships: a hasMany / hasOne needs its foreign
     * key on the related table (with site_id when the related model is site-scoped - the
     * relation query carries its global scope); a morphMany / morphOne the type + id pair;
     * a belongsToMany each pivot key. belongsTo and morphTo resolve through the target's
     * primary key and need nothing.
     */
    protected function _collect_relationship_requirements(): void
    {
        foreach (Manifest::php_get_extending('Rsx_Model_Abstract') as $metadata) {
            $fqcn = $metadata['fqcn'];

            // Relationship names come from the model API, not the raw manifest entry: the
            // manifest indexes methods per file, so an inherited relation is absent from this
            // class's own method map. get_relationships() unions the lineage.
            foreach ($fqcn::get_relationships() as $method_name) {
                $label = class_basename($fqcn) . '::' . $method_name;

                try {
                    $relation = (new $fqcn())->$method_name();
                } catch (\Throwable $e) {
                    $this->problems['relationship'][$label] = $e->getMessage();
                    continue;
                }

                if (!$relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                    $this->problems['relationship'][$label] = 'returned ' . get_debug_type($relation) . ', not a relation';
                    continue;
                }

                $this->relationship_count++;

                $source = $this->_method_source($fqcn, $method_name);

                if (Index_Advisor::source_exclusion($source) !== null) {
                    continue;
                }

                $this->_process_relationship($relation, $source);
            }
        }
    }

    /**
     * 'file:line' of the declaration of a model method (an inherited relation names the
     * ancestor that declares it).
     */
    protected function _method_source(string $fqcn, string $method_name): string
    {
        $method = new \ReflectionMethod($fqcn, $method_name);
        $record = Manifest::php_class_metadata($method->getDeclaringClass()->getShortName());
        $file = $record['file'] ?? $method->getFileName();

        return $file . ':' . $method->getStartLine();
    }

    /**
     * The requirement(s) one relation places.
     */
    protected function _process_relationship(\Illuminate\Database\Eloquent\Relations\Relation $relation, string $source): void
    {
        $type = class_basename(get_class($relation));

        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo
            || $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo) {
            return;
        }

        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
            // The pivot table is joined, never queried through a model's global scope.
            $pivot = $relation->getTable();

            foreach ([$relation->getForeignPivotKeyName(), $relation->getRelatedPivotKeyName()] as $key) {
                $this->_add_item(Index_Advisor::make_requirement($pivot, [$key], [], [], false, $this->context), $source, 'relationship', $type);
            }

            return;
        }

        $related = $relation->getRelated();
        $related_model = $this->context['models'][class_basename($related)] ?? null;
        $site_scoped = $related_model !== null && $related_model['site_scoped'];

        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\MorphOneOrMany) {
            $columns = [$relation->getMorphType(), $relation->getForeignKeyName()];
        } elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\HasOneOrMany) {
            $columns = [$relation->getForeignKeyName()];
        } else {
            // HasManyThrough and the rest: not analyzed
            return;
        }

        $this->_add_item(
            Index_Advisor::make_requirement($related->getTable(), $columns, [], [], $site_scoped, $this->context),
            $source,
            'relationship',
            $type
        );
    }

    /**
     * Requirements from the model query chains in the application's source (rsx/).
     */
    protected function _collect_query_requirements(): void
    {
        foreach (Manifest::get_all() as $file => $metadata) {
            if (($metadata['extension'] ?? null) !== 'php' || !isset($metadata['class']) || !str_starts_with($file, 'rsx/')) {
                continue;
            }

            $reason = Index_Advisor::source_exclusion($file);

            if ($reason === 'test' || ($reason !== null && !$this->option('include-dev'))) {
                $this->excluded_sources[$reason][] = $file;
                continue;
            }

            $code = file_get_contents(base_path($file));

            if ($code === false) {
                $this->problems['parse'][$file] = 'could not be read';
                continue;
            }

            $parsed = Index_Advisor::parse_source($code);

            if ($parsed['error'] !== null) {
                $this->problems['parse'][$file] = $parsed['error'];
                continue;
            }

            if ($parsed['is_seeder'] && !$this->option('include-dev')) {
                $this->excluded_sources['seeder'][] = $file;
                continue;
            }

            $this->files_scanned++;

            foreach ($parsed['chains'] as $chain) {
                $outcome = Index_Advisor::chain_requirements($chain, $this->context);
                $source = $file . ':' . $chain['line'];

                // A chain on something that is not a model, or one that filters nothing
                // (X_Model::find(), X_Model::query()), has nothing to report.
                if ($outcome['reason'] !== null && !in_array($outcome['reason'], ['unknown_model', 'no_filter'], true)) {
                    $this->unanalyzed_chains[$outcome['reason']][] = $source;
                }

                foreach ($outcome['requirements'] as $requirement) {
                    $this->_add_item($requirement, $source, 'query', 'where');
                }
            }
        }
    }

    /**
     * Record one requirement, unless it is null or on a framework table left out.
     */
    protected function _add_item(?array $requirement, string $source, string $kind, string $type): void
    {
        if ($requirement === null) {
            return;
        }

        if ($this->context['tables'][$requirement['table']]['framework'] && !$this->option('include-system')) {
            $this->excluded_framework++;

            return;
        }

        $this->items[] = ['requirement' => $requirement, 'source' => $source, 'kind' => $kind, 'type' => $type];
    }

    // =====================================================================================
    // ANALYSIS
    // =====================================================================================

    /**
     * table => Index_Advisor::table_diff() result, for every table with a requirement.
     */
    protected function _analyze(): array
    {
        $by_table = [];

        foreach (Index_Advisor::aggregate($this->items) as $sig => $group) {
            $table = $group['requirement']['table'];

            if ($this->option('table') && $table !== $this->option('table')) {
                continue;
            }

            $by_table[$table][$sig] = $group;
        }

        ksort($by_table);

        $result = [];

        foreach ($by_table as $table => $groups) {
            $diff = Index_Advisor::table_diff($table, $groups, $this->context);

            if (!$this->option('show-all')) {
                $diff['creates'] = $this->_apply_quota_limits($table, $diff['creates']);
            }

            if (!empty($diff['creates']) || !empty($diff['low_value']) || !empty($diff['drops'])) {
                $result[$table] = $diff;
            }
        }

        return $result;
    }

    /**
     * Cut a table's recommendations to the quota: relationship indexes are never cut, then
     * multi-column query indexes, then single-column ones.
     */
    protected function _apply_quota_limits(string $table, array $creates): array
    {
        $user_indexes = array_filter(
            $this->context['tables'][$table]['indexes'],
            fn ($index) => !str_starts_with($index['name'], 'idx_auto_')
        );

        $available = min(self::AUTO_INDEX_QUOTA, self::MAX_INDEXES_PER_TABLE - count($user_indexes));

        if (count($creates) <= $available) {
            return $creates;
        }

        $tier = fn ($c) => isset($c['kinds']['relationship']) ? 0 : (count($c['columns']) >= 2 ? 1 : 2);
        usort($creates, fn ($a, $b) => $tier($a) <=> $tier($b));

        $kept = [];

        foreach ($creates as $create) {
            if ($tier($create) === 0 || count($kept) < $available) {
                $kept[] = $create;
            }
        }

        return $kept;
    }

    // =====================================================================================
    // OUTPUT
    // =====================================================================================

    protected function _output_text(array $result): void
    {
        $details = (bool) $this->option('show-details');

        $this->info('Database Index Analysis');
        $this->info(str_repeat('=', 50));
        $this->line('');

        $excluded_parts = [];

        foreach ($this->excluded_sources as $reason => $files) {
            $excluded_parts[] = count($files) . ' ' . $reason;
        }

        $this->line(
            'Sources: ' . $this->files_scanned . ' files scanned'
            . (empty($excluded_parts) ? '' : ', excluded ' . implode(', ', $excluded_parts))
            . '; ' . $this->relationship_count . ' relationships'
        );

        if ($this->excluded_framework > 0) {
            $this->line("Framework tables: {$this->excluded_framework} requirements left out (--include-system to analyze them)");
        }

        if ($details) {
            foreach ($this->excluded_sources as $reason => $files) {
                $this->line("  Excluded ({$reason}):");

                foreach ($files as $file) {
                    $this->line("    {$file}");
                }
            }

            foreach ($this->unanalyzed_chains as $reason => $sources) {
                $this->line('  Chains not analyzed (' . $reason . '): ' . count($sources));

                foreach ($sources as $source) {
                    $this->line("    {$source}");
                }
            }
        }

        $this->_output_problems($details);
        $this->line('');

        $creates = 0;
        $low = 0;
        $drops = 0;

        foreach ($result as $diff) {
            $creates += count($diff['creates']);
            $low += count($diff['low_value']);
            $drops += count($diff['drops']);
        }

        if ($creates === 0 && $drops === 0) {
            $this->info('[OK] No missing indexes found');
        } else {
            $this->info("Found {$creates} indexes to create and {$drops} to drop");
        }

        foreach ($result as $table => $diff) {
            if (empty($diff['creates']) && empty($diff['drops'])) {
                continue;
            }

            $this->line('');
            $this->line("Table: {$table}");

            foreach ($diff['creates'] as $create) {
                $this->_output_recommendation('+', $create, $details);
            }

            foreach ($diff['drops'] as $drop) {
                $this->line("  - Drop {$drop['name']} (" . implode(', ', $drop['columns']) . ') - no analyzed query uses it');
            }
        }

        if ($low > 0) {
            $this->line('');
            $this->warn("Low value: {$low} - only boolean, enum or status columns besides site_id (migrated only with --include-low-value)");

            foreach ($result as $table => $diff) {
                if (empty($diff['low_value'])) {
                    continue;
                }

                $this->line("Table: {$table}");

                foreach ($diff['low_value'] as $create) {
                    $this->_output_recommendation('~', $create, $details);
                }
            }
        }

        if (($creates > 0 || $drops > 0) && !$this->option('generate-migration')) {
            $this->line('');
            $this->line('To generate a migration: php artisan rsx:db:check_indexes --generate-migration');
        }

        $this->line('');
        $this->_output_limitations();
    }

    /**
     * One recommended index: its columns, how many call sites need it, the first (or every)
     * call site, and the existing index that nearly serves it.
     */
    protected function _output_recommendation(string $mark, array $create, bool $details): void
    {
        $count = count($create['sources']);
        $this->line("  {$mark} (" . implode(', ', $create['columns']) . ") - {$count} call site" . ($count === 1 ? '' : 's'));

        $shown = $details ? $create['sources'] : array_slice($create['sources'], 0, 1);

        foreach ($shown as $source) {
            $this->line("      {$source}");
        }

        if (!$details && $count > 1) {
            $this->line('      ... ' . ($count - 1) . ' more (--show-details)');
        }

        if ($create['nearly'] !== null) {
            $nearly = $create['nearly'];
            $extendable = count($nearly['index']['columns']) === count($nearly['leading']);
            $this->line("      nearly served by {$nearly['index']['name']}, which leads with (" . implode(', ', $nearly['leading']) . "): missing {$nearly['missing']}"
                . ($extendable ? ' - extend it' : ''));
        }
    }

    /**
     * What could not be read: a [WARNING] count always, the list under --show-details.
     */
    protected function _output_problems(bool $details): void
    {
        $labels = [
            'parse' => ['source file could not be parsed', 'source files could not be parsed'],
            'relationship' => ['relationship could not be introspected', 'relationships could not be introspected'],
        ];

        foreach ($labels as $kind => [$singular, $plural]) {
            $count = count($this->problems[$kind]);

            if ($count === 0) {
                continue;
            }

            $this->warn("[WARNING] {$count} " . ($count === 1 ? $singular : $plural) . ' - their requirements are unknown, not absent'
                . ($details ? ':' : ' (--show-details lists them)'));

            if ($details) {
                foreach ($this->problems[$kind] as $what => $message) {
                    $this->line("    {$what}: {$message}");
                }
            }
        }
    }

    protected function _output_limitations(): void
    {
        $this->line('Not analyzed: dynamic column names, whereRaw / whereHas / whereColumn, conditions inside');
        $this->line('where(function ...) groups, chains with orWhere, queries built on a variable or a relation');
        $this->line('method, and anything decided at run time. Verify critical queries with EXPLAIN.');
    }

    protected function _json_report(array $result): array
    {
        return [
            'tables' => $result,
            'problems' => $this->problems,
            'excluded_sources' => $this->excluded_sources,
            'excluded_framework_requirements' => $this->excluded_framework,
            'unanalyzed_chains' => $this->unanalyzed_chains,
            'files_scanned' => $this->files_scanned,
            'relationships' => $this->relationship_count,
        ];
    }

    // =====================================================================================
    // MIGRATIONS
    // =====================================================================================

    /**
     * Write the reviewed migration(s): template tables to rsx/resource/migrations, framework
     * tables to system/database/migrations (only on a framework-developer tree - elsewhere
     * system/ is replaced by every framework update, so a framework table's index is a
     * change request to RSpade). Each file is created by make:migration:safe, which records
     * it in that directory's whitelist, and then given its statements.
     */
    protected function _generate_migrations(array $result): int
    {
        $sets = ['template' => ['creates' => [], 'drops' => []], 'framework' => ['creates' => [], 'drops' => []]];

        foreach ($result as $table => $diff) {
            $set = $this->context['tables'][$table]['framework'] ? 'framework' : 'template';
            $creates = $diff['creates'];

            if ($this->option('include-low-value')) {
                $creates = array_merge($creates, $diff['low_value']);
            }

            if (!empty($creates)) {
                $sets[$set]['creates'][$table] = $creates;
            }

            if (!empty($diff['drops'])) {
                $sets[$set]['drops'][$table] = $diff['drops'];
            }
        }

        $paths = ['template' => 'rsx/resource/migrations', 'framework' => 'database/migrations'];
        $written = 0;

        $this->line('');

        foreach ($sets as $set => $statements) {
            if (empty($statements['creates']) && empty($statements['drops'])) {
                continue;
            }

            if ($set === 'framework' && !config('rsx.code_quality.is_framework_developer', false)) {
                $this->error('[ERROR] Framework tables need indexes (' . implode(', ', array_keys($statements['creates'] + $statements['drops']))
                    . '). system/ is replaced on every framework update: send these to RSpade as a change request.');
                continue;
            }

            $file = $this->_create_migration_file($paths[$set]);
            file_put_contents_safe($file, $this->_migration_content($statements));
            $written++;

            $this->info("[OK] Migration written: {$file}");
        }

        if ($written === 0) {
            $this->info('[OK] Nothing to migrate');

            return 0;
        }

        $this->line('Review it, then run: php artisan migrate');

        return 0;
    }

    /**
     * Create an empty migration through make:migration:safe in $path (relative to
     * base_path()) and answer the file it wrote.
     */
    protected function _create_migration_file(string $path): string
    {
        $name = 'auto_index_recommendations';
        $output = new BufferedOutput();

        $code = Artisan::call('make:migration:safe', ['name' => $name, '--path' => $path], $output);

        if ($code !== 0) {
            throw new \RuntimeException("make:migration:safe failed in {$path}:\n" . $output->fetch());
        }

        $files = glob(base_path($path) . "/*_{$name}.php");
        sort($files);

        if (empty($files)) {
            throw new \RuntimeException("make:migration:safe reported success but no *_{$name}.php exists in {$path}:\n" . $output->fetch());
        }

        return end($files);
    }

    protected function _migration_content(array $statements): string
    {
        $body = Index_Advisor::migration_up_body($statements['creates'], $statements['drops']);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Indexes recommended by rsx:db:check_indexes for the queries and relationships named
     * beside each statement.
     *
     * @return void
     */
    public function up()
    {
{$body}
    }
};

PHP;
    }
}
