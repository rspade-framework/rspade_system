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
     * The outbound queues are drained across every site at once, so their indexes serve the
     * drain rather than a tenant:
     *
     *   (status_id, created_at) replaces (status_id) on both queues - the claim is
     *   status_id = PENDING ordered by created_at, and the retention prune is status_id IN
     *   (...) AND created_at < cutoff.
     *
     * Dropped as redundant or never a predicate:
     *   - the site_id single-column indexes on all four tables: each is the prefix of the
     *     table's (site_id, ...) UNIQUE key, which the site_id foreign key binds to instead;
     *   - category_id: low cardinality and never the access path;
     *   - _email_queue.to_address: only ever matched by LIKE '%x%', which no BTREE serves.
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
            ALTER TABLE _email_queue
                DROP INDEX idx_email_queue_created_at,
                DROP INDEX idx_email_queue_category_id,
                DROP INDEX idx_email_queue_to_address,
                DROP INDEX idx_email_queue_site_id,
                DROP INDEX idx_email_queue_status_id,
                ADD INDEX idx_email_queue_status_created (status_id, created_at)
        ");

        DB::statement("
            ALTER TABLE _sms_queue
                DROP INDEX idx_sms_queue_created_at,
                DROP INDEX idx_sms_queue_category_id,
                DROP INDEX idx_sms_queue_site_id,
                DROP INDEX idx_sms_queue_status_id,
                ADD INDEX idx_sms_queue_status_created (status_id, created_at)
        ");

        DB::statement("
            ALTER TABLE _email_recipients DROP INDEX idx_email_recipients_site_id
        ");

        DB::statement("
            ALTER TABLE _sms_recipients DROP INDEX idx_sms_recipients_site_id
        ");
    }

    /**
     * down() method is prohibited in RSpade framework
     * Migrations should only move forward, never backward
     */
};
