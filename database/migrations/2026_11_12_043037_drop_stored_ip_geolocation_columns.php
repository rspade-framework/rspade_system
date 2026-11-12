<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Where an IP address is located is answered at display time by Rsx_Geo_Ip and is never
 * stored (rsx:man geo_ip). The geocode columns on _ip_addresses and the location columns on
 * _login_history held nothing and are removed.
 */
return new class extends Migration
{
    public function up()
    {
        DB::statement('
            ALTER TABLE _ip_addresses
                DROP COLUMN city,
                DROP COLUMN state,
                DROP COLUMN country,
                DROP COLUMN latitude,
                DROP COLUMN longitude
        ');

        DB::statement('
            ALTER TABLE _login_history
                DROP COLUMN location_city,
                DROP COLUMN location_region,
                DROP COLUMN location_country
        ');
    }
};
