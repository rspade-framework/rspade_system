#!/bin/bash
#
# prod_probe_migration.sh - a REAL pending migration for the production-mode scripts, and
# the signed-in account their page probe uses. Sourced, never executed.
#
# The production-mode lifecycle proves that a sealed box migrates with no rebuild. That
# takes a migration that is genuinely pending, and `migrate` accepts one only as a file in
# a migration directory that the directory's .migration_whitelist lists (--path is refused,
# and the whitelist is enforced in every mode and written only in development). So the
# probe is a real file in system/database/migrations and a real whitelist entry, both
# written here and both removed again:
#
#   probe_migration_create        write the file and the whitelist entry
#   probe_migration_remove_files  remove the file, put the whitelist back byte for byte
#   probe_migration_remove_rows   drop the table, delete its _migrations row, purge the
#                                 schema state
#
# THE MIGRATION IS PURELY ADDITIVE: one new table, named for this process
# (_prod_mode_probe_<pid>), with one VARCHAR column. It lands in whatever database the box
# is connected to - the production-mode scripts run against the development database, which
# has no migration snapshot once the box is sealed - so it is kept to that one table, and
# every remove_* function is idempotent so an EXIT trap can call them whatever happened.
#
# THE ACCOUNT. A sealed box has no development grant store, so a browser arrives as
# somebody only by signing in. probe_account_seed creates (or revives) one login identity
# with a password generated for this run, marked a developer so the framework's own /_sys
# page admits it; probe_account_retire scrambles the password, clears the developer mark
# and disables the membership. The row is retired rather than deleted because an identity
# is an actor: sessions and login history name it.
#
# The caller provides PROJECT_ROOT, SYSTEM_DIR and LOG, and has cd'd to PROJECT_ROOT.

PROBE_TABLE="_prod_mode_probe_$$"
PROBE_COLUMN="probe_label"
PROBE_COLUMN_LENGTH=40
PROBE_MIGRATION="$(date +%Y_%m_%d_%H%M%S)_create_prod_mode_probe_$$_table"
PROBE_MIGRATION_DIR="$SYSTEM_DIR/database/migrations"
PROBE_MIGRATION_FILE="$PROBE_MIGRATION_DIR/$PROBE_MIGRATION.php"
PROBE_WHITELIST="$PROBE_MIGRATION_DIR/.migration_whitelist"
PROBE_WHITELIST_BACKUP="/tmp/rsx_prod_probe_whitelist_$$"
PROBE_ROWS_MAY_EXIST=false

PROBE_EMAIL="prod-mode-probe@rspade.test"
PROBE_PASSWORD=""
PROBE_ACCOUNT_SEEDED=false

PROBE_PAGE_JS="$SYSTEM_DIR/app/RSpade/tests/prod_mode/playwright/sealed_page_probe.js"

# Run PHP inside the booted framework: the code arrives on stdin, after the one include
# that brings the shell up (rsx:man scripting). A fresh process every time, which is the
# point - what it answers is what any new process on the box would see.
probe_php() {
    {
        echo "<?php \$app = require '$SYSTEM_DIR/script.php';"
        cat
    } | php
}

probe_migration_create() {
    cp -p "$PROBE_WHITELIST" "$PROBE_WHITELIST_BACKUP" || return 1

    cat > "$PROBE_MIGRATION_FILE" <<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Written by the production-mode lifecycle test and removed by it again: one throwaway
 * table, so that a sealed box has a real migration to run.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            CREATE TABLE $PROBE_TABLE (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                $PROBE_COLUMN VARCHAR($PROBE_COLUMN_LENGTH) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
PHP

    # The whitelist is written by make:migration:safe in development and by nothing
    # elsewhere, so the entry is added here, to the JSON itself.
    PROBE_WHITELIST="$PROBE_WHITELIST" PROBE_ENTRY="$PROBE_MIGRATION.php" php -r '
        $path = getenv("PROBE_WHITELIST");
        $whitelist = json_decode(file_get_contents($path), true);
        if (!is_array($whitelist) || !isset($whitelist["migrations"])) {
            fwrite(STDERR, "the migration whitelist is not valid JSON\n");
            exit(1);
        }
        $whitelist["migrations"][getenv("PROBE_ENTRY")] = [
            "created_at" => date("c"),
            "created_by" => "prod_mode test",
            "command" => "tests/_lib/prod_probe_migration.sh",
        ];
        file_put_contents($path, json_encode($whitelist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    ' || return 1

    PROBE_ROWS_MAY_EXIST=true
}

probe_migration_remove_files() {
    rm -f "$PROBE_MIGRATION_FILE"

    if [ -f "$PROBE_WHITELIST_BACKUP" ]; then
        cp -p "$PROBE_WHITELIST_BACKUP" "$PROBE_WHITELIST" && rm -f "$PROBE_WHITELIST_BACKUP"
    fi
}

# Drop the table, delete the migration's row, and purge the schema state. Without the purge
# the column map cached under the migrated state key would outlive the table it describes.
probe_migration_remove_rows() {
    [ "$PROBE_ROWS_MAY_EXIST" = true ] || return 0

    PROBE_TABLE="$PROBE_TABLE" PROBE_MIGRATION="$PROBE_MIGRATION" probe_php >> "$LOG" 2>&1 <<'PHP'
        use Illuminate\Support\Facades\DB;

        \App\RSpade\Core\Database\Rsx_Schema::purge_schema_state();
        DB::statement('DROP TABLE IF EXISTS `' . getenv('PROBE_TABLE') . '`');
        DB::table('_migrations')->where('migration', getenv('PROBE_MIGRATION'))->delete();
        \App\RSpade\Core\Database\Rsx_Schema::purge_schema_state();
PHP
    local rc=$?

    [ $rc -eq 0 ] && PROBE_ROWS_MAY_EXIST=false

    return $rc
}

# "<column>:<max length>" for every column a fresh process sees on the probe table, through
# the framework's own schema reader; empty when the table is not there.
probe_schema_columns() {
    PROBE_TABLE="$PROBE_TABLE" probe_php 2>> "$LOG" <<'PHP'
        $columns = \App\RSpade\Core\Database\Rsx_Schema::table_columns(getenv('PROBE_TABLE')) ?? [];
        $out = [];
        foreach ($columns as $name => $shape) {
            $out[] = $name . ':' . ($shape['max_length'] ?? '');
        }
        echo implode(',', $out);
PHP
}

# What the PHP model answers for the column the page probe samples in the browser.
probe_php_sample_length() {
    probe_php 2>> "$LOG" <<'PHP'
        echo \App\RSpade\Core\Models\User_Model::field_length('first_name');
PHP
}

probe_account_seed() {
    PROBE_PASSWORD="$(head -c 24 /dev/urandom | base64 | tr -d '/+=')"

    PROBE_EMAIL="$PROBE_EMAIL" PROBE_PASSWORD="$PROBE_PASSWORD" probe_php >> "$LOG" 2>&1 <<'PHP'
        use App\RSpade\Core\Models\Login_User_Model;
        use App\RSpade\Core\Models\User_Model;
        use App\RSpade\Core\Session\Session;
        use Illuminate\Support\Facades\DB;
        use Illuminate\Support\Facades\Hash;

        $email = getenv('PROBE_EMAIL');
        $site_id = 1;
        Session::set_temporary_site_id($site_id);

        $login = Login_User_Model::withTrashed()->where('email', $email)->first() ?? new Login_User_Model();
        $login->email = $email;
        $login->password = Hash::make(getenv('PROBE_PASSWORD'));
        $login->is_activated = true;
        $login->is_verified = true;
        $login->status_id = Login_User_Model::STATUS_ACTIVE;
        $login->deleted_at = null;
        $login->save();

        $user = User_Model::without_site_scope(fn () => User_Model::withTrashed()
            ->where('login_user_id', $login->id)->where('site_id', $site_id)->first()) ?? new User_Model();
        $user->login_user_id = $login->id;
        $user->site_id = $site_id;
        $user->email = $email;
        $user->first_name = 'Prod';
        $user->last_name = 'Probe';
        $user->role_id = \App\RSpade\Core\Testing\Rsx_Test_Abstract::most_privileged_role_id();
        $user->is_enabled = 1;
        $user->deleted_at = null;
        $user->save();

        // is_developer is set in the database and nowhere else (rsx:man session). The fixed
        // timezone keeps the first page from adopting the browser's zone and reloading.
        DB::table('login_users')->where('id', $login->id)
            ->update(['is_developer' => 1, 'timezone' => 'UTC', 'timezone_auto' => 0]);

        echo "PROBE_ACCOUNT_SEEDED\n";
PHP
    local rc=$?

    [ $rc -eq 0 ] && PROBE_ACCOUNT_SEEDED=true

    return $rc
}

probe_account_retire() {
    [ "$PROBE_ACCOUNT_SEEDED" = true ] || return 0

    PROBE_EMAIL="$PROBE_EMAIL" probe_php >> "$LOG" 2>&1 <<'PHP'
        use Illuminate\Support\Facades\DB;
        use Illuminate\Support\Facades\Hash;

        $login_id = DB::table('login_users')->where('email', getenv('PROBE_EMAIL'))->value('id');

        if ($login_id !== null) {
            DB::table('login_users')->where('id', $login_id)
                ->update(['is_developer' => 0, 'password' => Hash::make(bin2hex(random_bytes(32)))]);
            DB::table('users')->where('login_user_id', $login_id)->update(['is_enabled' => 0]);
        }
PHP
    local rc=$?

    [ $rc -eq 0 ] && PROBE_ACCOUNT_SEEDED=false

    return $rc
}

# Render the signed-in pages against the keys the box should be serving. Prints the
# probe's own PASS:/FAIL: lines; exit status is the probe's. When the caller has set
# PROBE_ANON_PATH, PROBE_ANON_EXPRESSION and PROBE_ANON_MARKER, that page is rendered
# anonymously first and the expression must answer the marker there (sealed_page_probe.js).
#   $1 the application host   $2 the expected state key   $3 the expected code key
probe_pages() {
    PROBE_EMAIL="$PROBE_EMAIL" PROBE_PASSWORD="$PROBE_PASSWORD" \
        PROBE_ANON_PATH="${PROBE_ANON_PATH:-}" PROBE_ANON_EXPRESSION="${PROBE_ANON_EXPRESSION:-}" \
        PROBE_ANON_MARKER="${PROBE_ANON_MARKER:-}" \
        node "$PROBE_PAGE_JS" "$1" "$2" "$3" /_sys @landing 2>&1
}
