<?php

namespace App\RSpade\Commands\Rsx;

use App\RSpade\Core\Database\Rsx_Rederive;
use App\RSpade\Core\Manifest\Manifest;
use Illuminate\Console\Command;

/**
 * The `<table> | --all` selection shared by rsx:derived:check and rsx:derived:rebuild.
 */
class Derived_Command_Support
{
    /**
     * The tables a command acts on, as table => model class: the one named, or with --all
     * every table whose model declares derived columns. Exactly one of the two is given;
     * neither, or both, is refused. A named table is refused when no model owns it or its
     * model declares no derived columns. A refusal prints an [ERROR] and answers null.
     *
     * @return array<string, string>|null
     */
    public static function resolve_tables(Command $command, ?string $table, bool $all): ?array
    {
        if ($all === ($table !== null)) {
            $command->error('[ERROR] Name one table, or pass --all for every table whose model declares derived columns.');

            return null;
        }

        if ($all) {
            return Rsx_Rederive::tables_with_derived_columns();
        }

        $model = Manifest::model_for_table($table);

        if ($model === null) {
            $command->error("[ERROR] No model owns the table '{$table}'.");

            return null;
        }

        $fqcn = Rsx_Rederive::model_for($table);

        if ($fqcn === null) {
            $command->error("[ERROR] {$model} (table '{$table}') declares no derived columns.");

            return null;
        }

        return [$table => $fqcn];
    }
}
