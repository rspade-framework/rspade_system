<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 */

namespace App\RSpade\Tests\SiteModel\Php;

use Illuminate\Support\Facades\DB;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The two per-site client portal columns: sites.portal_domain, stored in one spelling (a
 * bare lowercase hostname, or NULL) and unique; sites.is_portal_enabled, on by default.
 *
 * normalize_portal_domain() is asserted pure; the saving hook and the column defaults are
 * asserted through real saves inside the test's transaction.
 */
class Site_Model_Portal_Columns_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = true;

    private static function __new_site(): Site_Model
    {
        $site = new Site_Model();
        $site->slug = 'portal-columns-' . uniqid();
        $site->name = 'Portal Columns Test';

        return $site;
    }

    public static function test_a_typed_domain_is_normalised_to_a_bare_lowercase_host()
    {
        $cases = [
            'portal.example.com' => 'portal.example.com',
            '  Portal.Example.COM  ' => 'portal.example.com',
            'https://portal.example.com' => 'portal.example.com',
            'http://portal.example.com:8443/login?x=1#y' => 'portal.example.com',
            'portal.example.com.' => 'portal.example.com',
            'portal.example.com:443' => 'portal.example.com',
            'jobs.my-agency.co.uk/' => 'jobs.my-agency.co.uk',
            'xn--bcher-kva.example' => 'xn--bcher-kva.example',
        ];

        foreach ($cases as $typed => $stored) {
            static::__assert_equals($stored, Site_Model::normalize_portal_domain($typed), "'{$typed}'");
        }
    }

    public static function test_an_empty_domain_is_null()
    {
        foreach ([null, '', '   ', 'https://', '.'] as $typed) {
            static::__assert_null(Site_Model::normalize_portal_domain($typed), var_export($typed, true));
        }
    }

    public static function test_a_value_that_is_not_a_hostname_is_refused()
    {
        $refused = ['localhost', 'portal', '10.0.0.1', 'user@portal.example.com', 'por tal.example.com',
            '-portal.example.com', 'portal-.example.com', 'portal..example.com', 'bücher.example',
            'portal_x.example.com', str_repeat('a', 64) . '.example.com', str_repeat('abcdefghi.', 26) . 'com'];

        foreach ($refused as $typed) {
            $refusal = null;
            try {
                Site_Model::normalize_portal_domain($typed);
            } catch (\InvalidArgumentException $e) {
                $refusal = $e->getMessage();
            }

            static::__assert_not_null($refusal, "'{$typed}' was accepted as a portal domain");
            static::__assert_contains('must be a hostname', (string) $refusal);
        }
    }

    public static function test_a_save_stores_the_normalised_domain_and_null_for_blank()
    {
        $site = static::__new_site();
        $site->portal_domain = 'HTTPS://Jobs.Example-Agency.test:443/';
        $site->save();

        static::__assert_equals('jobs.example-agency.test', DB::table('sites')->where('id', $site->id)->value('portal_domain'));

        $site->portal_domain = '  ';
        $site->save();

        static::__assert_null(DB::table('sites')->where('id', $site->id)->value('portal_domain'));
    }

    public static function test_a_save_refuses_a_domain_that_is_not_a_hostname()
    {
        $site = static::__new_site();
        $site->portal_domain = 'not a host';

        static::__assert_throws(\InvalidArgumentException::class, fn () => $site->save(), 'must be a hostname');
    }

    public static function test_a_domain_addresses_one_site()
    {
        $first = static::__new_site();
        $first->portal_domain = 'unique-portal.example.test';
        $first->save();

        $second = static::__new_site();
        $second->portal_domain = 'Unique-Portal.Example.test';

        static::__assert_throws(\Illuminate\Database\QueryException::class, fn () => $second->save(), 'uk_sites_portal_domain');
    }

    public static function test_the_portal_is_enabled_by_default_and_cast_to_a_boolean()
    {
        $site = static::__new_site();
        $site->save();

        $fresh = Site_Model::find($site->id);
        static::__assert_true($fresh->is_portal_enabled === true, 'is_portal_enabled is not on, as a boolean, by default');

        $fresh->is_portal_enabled = false;
        $fresh->save();

        static::__assert_true(Site_Model::find($site->id)->is_portal_enabled === false);
    }
}
