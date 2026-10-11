<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Docker\Php;

use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * The container images install the Chromium build of ONE Playwright version, named by
 * `ARG PLAYWRIGHT_VERSION` in the Dockerfile, and a Playwright package launches only the
 * build it was released with. So the pin must equal the version of the `playwright` package
 * the framework ships: updating the package without the pin produces an image whose browser
 * the framework cannot launch.
 */
class Docker_Playwright_Pin_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    /** DOCKER-PW-01 - the Dockerfile's pin is the shipped playwright package's version */
    public static function test_the_dockerfile_pin_is_the_shipped_playwright_version()
    {
        $dockerfile = file_get_contents(base_path('app/RSpade/resource/docker/Dockerfile'));
        static::__assert_true(
            preg_match('/^ARG PLAYWRIGHT_VERSION=(\S+)$/m', $dockerfile, $match) === 1,
            'the Dockerfile declares ARG PLAYWRIGHT_VERSION'
        );

        $package = json_decode(file_get_contents(base_path('node_modules/playwright/package.json')), true);

        static::__assert_equals(
            $package['version'],
            $match[1],
            'ARG PLAYWRIGHT_VERSION in app/RSpade/resource/docker/Dockerfile must equal the installed playwright package version - update the pin and rebuild the images'
        );
    }

    /** DOCKER-PW-02 - the browser is installed in the stage both targets are built from */
    public static function test_chromium_is_installed_in_the_base_stage()
    {
        $dockerfile = file_get_contents(base_path('app/RSpade/resource/docker/Dockerfile'));

        $install = strpos($dockerfile, 'playwright@${PLAYWRIGHT_VERSION} install chromium');
        $dev_stage = strpos($dockerfile, "\nFROM base AS dev");

        static::__assert_true($install !== false, 'the Dockerfile installs chromium with the pinned playwright');
        static::__assert_true($dev_stage !== false && $install < $dev_stage, 'the install is in the base stage, ahead of the dev and prod targets');
    }
}
