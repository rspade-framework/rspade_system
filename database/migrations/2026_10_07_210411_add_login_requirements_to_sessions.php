<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Login requirements (Login_Requirements): the requirements a signed-in identity has not yet
 * met, per realm, carried on the session row - {"staff": [...], "portal": [...]}, NULL when
 * nothing is outstanding. On the row rather than in _session_values so the identity readers,
 * which consult it on every request, read it with the row they already loaded.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE _sessions ADD COLUMN login_requirements JSON NULL AFTER portal_site_id");
    }
};
