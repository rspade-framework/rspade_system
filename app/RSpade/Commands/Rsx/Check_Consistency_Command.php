<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Database\ModelHelper;
use App\RSpade\Core\Paths\Rsx_Project_Paths;
use App\RSpade\Core\Rsx;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rsx:migrate:check_consistency - does the SEALED manifest describe the schema this
 * database actually has?
 *
 * A production build bakes every model's column map into the manifest index at BUILD
 * time. The database moves at MIGRATE time. Those are two separate deployment steps and
 * an operator can run them in either order, so the one question worth asking after a
 * production migrate is whether the build that is being served still matches the schema
 * it is being served against. `migrate` runs this on the bare path and PROPAGATES its
 * exit code.
 *
 * THE TWO FINDINGS ARE NOT SYMMETRIC:
 *
 *   - A manifest column MISSING from its table is an ERROR. The served code believes a
 *     column exists; field_length() answers for it, a generated JS stub declares it, a
 *     query selects it, and the first request that touches it is a 500.
 *   - A table column UNKNOWN to the manifest is a WARNING. The database is simply ahead
 *     of the build. Nothing served reads that column, so nothing breaks; it is reported
 *     because it is the signature of a migrate that has not been followed by a build.
 *
 * EVERY table is walked and EVERY discrepancy is printed before the exit code is decided.
 * A check that stops at the first fault makes an operator re-run it once per fault,
 * which on a production box is once per maintenance window.
 *
 * COLUMNS ARE COMPARED BY THEIR SOURCE TABLE. A base model in a class-table-inheritance
 * entity carries its detail tables' columns in ONE merged map (see
 * Model_ManifestSupport::__merge_detail_columns), so comparing that map against
 * SHOW COLUMNS on the base table reports every detail column as missing - which is how
 * this check used to false-positive on every detail-table application. Each manifest
 * column records the `source_table` it came from; only the ones belonging to the table
 * being examined are compared, and the detail models are walked as their own rows.
 */
class Check_Consistency_Command extends Command
{
    protected $signature = 'rsx:migrate:check_consistency';

    protected $description = 'Verify the sealed manifest describes the schema this database has';

    public function handle(): int
    {
        // A DEVELOPMENT box rebuilds its manifest from the live schema on the next
        // request, so "the manifest disagrees with the database" is a statement with no
        // shelf life there - it is true for a few seconds after every migrate and then it
        // is not. Answering 0 in development would be reporting a check that never ran.
        if (!Rsx::is_production()) {
            $this->error('[ERROR] rsx:migrate:check_consistency runs in a production mode only.');
            $this->info('');
            $this->info('  It compares a SEALED manifest against the live schema. In development the');
            $this->info('  manifest is rebuilt from that same schema on the next request, so there is');
            $this->info('  nothing to compare - the answer would be "consistent" by construction.');
            $this->info('');
            $this->info('  Current mode: ' . Rsx::get_mode_label());

            return 1;
        }

        // No manifest is not "nothing to check" - it is a box with nothing to serve.
        $index_file = Rsx_Project_Paths::manifest_index_file();
        if (!is_file($index_file)) {
            $this->error('[ERROR] There is no manifest to check.');
            $this->info('');
            $this->info('  Expected: ' . $index_file);
            $this->info('');
            $this->info('  This box cannot serve a request either. Build it:');
            $this->info('');
            $this->info('      php artisan rsx:build --force');
            $this->info('');
            $this->info('  See rsx:man prod.');

            return 1;
        }

        $this->info('Checking the manifest against the live database schema...');
        $this->info('');

        $tables = ModelHelper::get_all_table_names();

        if (empty($tables)) {
            $this->warn('[WARNING]  The manifest lists no model tables.');

            return 0;
        }

        sort($tables);

        $errors = 0;
        $warnings = 0;

        foreach ($tables as $table_name) {
            [$table_errors, $table_warnings] = $this->check_table($table_name);

            $errors += $table_errors;
            $warnings += $table_warnings;
        }

        if ($warnings > 0) {
            $this->info('');
            $this->warn(
                '[WARNING]  ' . $warnings . ' column(s) exist in the database and not in the manifest.'
                . ' Nothing served reads them.'
            );
        }

        if ($errors > 0) {
            $this->info('');
            $this->explain_failure($errors);

            return 1;
        }

        $this->info('');
        $this->info('[OK] The manifest matches the database schema (' . count($tables) . ' tables checked).');

        return 0;
    }

    /**
     * Compare one model table against its manifest columns.
     *
     * @return array{0:int,1:int} [errors, warnings]
     */
    protected function check_table(string $table_name): array
    {
        if (!Schema::hasTable($table_name)) {
            $this->error("[ERROR] Table '{$table_name}' is in the manifest and not in the database");

            return [1, 0];
        }

        try {
            $manifest_columns = $this->manifest_columns_for($table_name);
        } catch (\Throwable $e) {
            $this->error("[ERROR] Could not read manifest columns for '{$table_name}': " . $e->getMessage());

            return [1, 0];
        }

        $database_columns = [];
        foreach (DB::select("SHOW COLUMNS FROM `{$table_name}`") as $column) {
            $database_columns[] = $column->Field;
        }

        $missing_from_table = array_values(array_diff($manifest_columns, $database_columns));
        $unknown_to_manifest = array_values(array_diff($database_columns, $manifest_columns));

        foreach ($missing_from_table as $column) {
            $this->error("[ERROR] {$table_name}.{$column} is in the manifest and not in the table");
        }

        foreach ($unknown_to_manifest as $column) {
            $this->warn("[WARNING]  {$table_name}.{$column} is in the table and not in the manifest");
        }

        return [count($missing_from_table), count($unknown_to_manifest)];
    }

    /**
     * The manifest columns that live on THIS table - never the ones a base model merged
     * in from a detail table, which are checked when that detail model's own row is
     * walked.
     *
     * The filter is here rather than in ModelHelper because the merged map is what every
     * other consumer wants: field_length(), the JS stubs and the model codegen all treat
     * base + detail as one logical model. This check is the one place that needs the
     * columns separated back out by the table they physically live in.
     *
     * @return string[]
     */
    protected function manifest_columns_for(string $table_name): array
    {
        $columns = [];

        foreach (ModelHelper::get_columns_by_table($table_name) as $name => $meta) {
            if (($meta['source_table'] ?? $table_name) !== $table_name) {
                continue;
            }

            $columns[] = $name;
        }

        return $columns;
    }

    /**
     * What a mismatch means and what to do about it.
     *
     * An operator reading this has a live site serving a build that does not match its
     * database, so the message says which two steps disagreed and gives both the repair
     * and the ordering that avoids the situation next time.
     */
    protected function explain_failure(int $errors): void
    {
        $this->error('[ERROR] ' . $errors . ' column(s) the manifest declares are missing from the database.');
        $this->info('');
        $this->info('  This build was compiled against a DIFFERENT schema than the one this');
        $this->info('  database has. The manifest bakes every model\'s columns in at build time;');
        $this->info('  the database moves at migrate time. Code that is being served right now');
        $this->info('  believes those columns exist.');
        $this->info('');
        $this->info('  Repair it by rebuilding against the schema you now have:');
        $this->info('');
        $this->info('      php artisan rsx:build --force');
        $this->info('');
        $this->info('  Avoid it next time by migrating BEFORE the build - that is the recommended');
        $this->info('  deployment order:');
        $this->info('');
        $this->info('      php artisan migrate');
        $this->info('      php artisan rsx:mode:set prod');
        $this->info('');
        $this->info('  See rsx:man prod.');
    }
}
