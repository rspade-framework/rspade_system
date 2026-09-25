<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Use raw MySQL queries for clarity and auditability (DB::statement with raw SQL,
     * never Schema::create with Blueprint). Migrations must be self-contained.
     *
     * Indexes no query uses, on tables that are written far more than they are read:
     *
     *   - _api_request_log and _login_history are written per request / per sign-in and read
     *     only by their created_at retention prune (and, for _login_history, the documented
     *     per-identity history, whose login_user_id index stays); ip, site_id, email_attempted
     *     and status are never a predicate.
     *   - _throttle.last_executed_at appears only inside the upsert expression.
     *   - _settings.setting_group sits in a three-column ORDER BY that filesorts regardless;
     *     _setting_values.setting_id is the prefix of UNIQUE (setting_id, site_id).
     *   - _revisions (site_id, created_at) and _transactions (actor), (site_id, created_at)
     *     serve no predicate; the revision prune filters on created_at alone.
     *   - countries: idx_alpha2 duplicates UNIQUE alpha2 (still the target of the regions
     *     foreign key); alpha3 and enabled are never the access path on a ~250-row table.
     *   - regions: idx_country is the prefix of UNIQUE (country_alpha2, code), which also
     *     serves every region lookup (a region code is unique only within its country, so
     *     every lookup names both); enabled is always combined with country_alpha2.
     *
     * Every table keeps an index LEADING with created_at and one leading with updated_at
     * (migrate:normalize_schema guarantees both, detecting coverage by the leading column).
     * Where a migration-named index duplicated the one normalization added under the column's
     * own name, the named copy is the one dropped: the survivor is then the index
     * normalization re-creates on a database replayed from scratch, so both paths converge.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE _api_request_log
                DROP INDEX idx_api_request_log_created_at,
                DROP INDEX idx_api_request_log_ip,
                DROP INDEX idx_api_request_log_site_id
        ");

        DB::statement("
            ALTER TABLE _login_history
                DROP INDEX _login_history_created_at_idx,
                DROP INDEX _login_history_email_attempted_idx,
                DROP INDEX _login_history_status_idx,
                DROP INDEX _login_history_ip_address_idx
        ");

        DB::statement("
            ALTER TABLE _zip_download_requests DROP INDEX idx_zip_download_requests_created_at
        ");

        DB::statement("
            ALTER TABLE _throttle DROP INDEX idx_throttle_last_executed
        ");

        DB::statement("
            ALTER TABLE _settings DROP INDEX idx_setting_group
        ");

        DB::statement("
            ALTER TABLE _setting_values DROP INDEX idx_setting_id
        ");

        DB::statement("
            ALTER TABLE _revisions DROP INDEX idx_revisions_site_created
        ");

        DB::statement("
            ALTER TABLE _transactions
                DROP INDEX idx_transactions_actor,
                DROP INDEX idx_transactions_site_created
        ");

        DB::statement("
            ALTER TABLE countries
                DROP INDEX idx_alpha2,
                DROP INDEX idx_alpha3,
                DROP INDEX idx_enabled
        ");

        DB::statement("
            ALTER TABLE regions
                DROP INDEX idx_country,
                DROP INDEX idx_enabled,
                DROP INDEX idx_code
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
