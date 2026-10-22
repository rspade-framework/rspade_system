<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The two per-site facts the client portal reads.
     *
     * portal_domain - a hostname the application provisions for one site's portal
     * (jobs.myagency.com): bare and lowercase, no scheme, port or path, NULL when the site
     * has none. UNIQUE because a host addresses exactly one tenant. 253 is the longest a
     * DNS name can be. Honoured only while PORTAL_CUSTOM_DOMAINS is not 'off'.
     *
     * is_portal_enabled - whether this site has a client portal at all. Default ON, so an
     * install whose portal is open to every tenant needs nothing; an application that sells
     * the portal per customer provisions it off.
     *
     * @return void
     */
    public function up()
    {
        DB::statement("
            ALTER TABLE sites
                ADD COLUMN portal_domain VARCHAR(253) NULL DEFAULT NULL AFTER timezone,
                ADD COLUMN is_portal_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_enabled,
                ADD UNIQUE KEY uk_sites_portal_domain (portal_domain)
        ");
    }
};
