<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every recorded user agent becomes a reference into _user_agents.
 *
 * _sessions and _session_devices held the string cut to 255 characters and
 * _login_history cut to 512, so the same browser did not compare equal across them. Each
 * now holds user_agent_id; the string lives once, whole, in _user_agents, and readers get
 * it back through the framework (Session listings, Login_History).
 *
 * The strings already recorded are moved across as they were stored. 'CLI', which a
 * command's session row carried in place of an agent, becomes no agent at all.
 *
 * _user_agents gains last_seen_at, advanced whenever a string is resolved. A row no
 * session, device, login-history row or audit session points at is deleted by a weekly
 * sweep once it has not been resolved for a day - a browser session can be
 * minted by anybody, so the table must not grow with every string ever presented. Rows
 * an audit session points at are permanent with it.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE _user_agents
                ADD COLUMN last_seen_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER first_seen_at,
                ADD INDEX idx_user_agents_last_seen (last_seen_at)
        ");

        foreach (['_sessions', '_session_devices', '_login_history'] as $table) {
            DB::statement("
                INSERT IGNORE INTO _user_agents (ua_hash, user_agent)
                SELECT DISTINCT SHA2(user_agent, 256), user_agent
                FROM {$table}
                WHERE user_agent IS NOT NULL AND user_agent <> '' AND user_agent <> 'CLI'
            ");

            DB::statement("ALTER TABLE {$table} ADD COLUMN user_agent_id BIGINT NULL AFTER user_agent");

            DB::statement("
                UPDATE {$table} t
                JOIN _user_agents u ON u.ua_hash = SHA2(t.user_agent, 256)
                SET t.user_agent_id = u.id
                WHERE t.user_agent IS NOT NULL AND t.user_agent <> '' AND t.user_agent <> 'CLI'
            ");

            $key = ltrim($table, '_');

            DB::statement("
                ALTER TABLE {$table}
                    DROP COLUMN user_agent,
                    ADD INDEX idx_{$key}_user_agent (user_agent_id),
                    ADD CONSTRAINT {$key}_user_agent_fk FOREIGN KEY (user_agent_id) REFERENCES _user_agents (id) ON DELETE RESTRICT
            ");
        }
    }
};
