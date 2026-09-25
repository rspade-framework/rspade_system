<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Dispatch\Php;

use RuntimeException;
use App\RSpade\Core\Dispatch\Route_ManifestSupport;
use App\RSpade\Core\Portal\Portal_Route_ManifestSupport;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * ROUTE-VERB-01: a #[Route] / #[Portal_Route] declares GET and/or POST, and nothing else -
 * a manifest-build FATAL in both realms.
 *
 * The support modules are fed a SYNTHETIC manifest_data array (never the real manifest),
 * so each declaration is judged in isolation. Pure array work, no database.
 *
 * Behavior of record: php artisan rsx:man routing.
 */
class Route_Verb_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE = 'ROUTE-VERB-01';

    public static function test_get_post_and_both_are_accepted_in_both_realms()
    {
        foreach ([null, ['GET'], ['POST'], ['GET', 'POST'], ['get', 'post']] as $methods) {
            $args = $methods === null ? ['/_test/verb/probe'] : ['/_test/verb/probe', $methods];

            $manifest = static::__manifest('Route', $args);
            Route_ManifestSupport::process($manifest, array_keys($manifest['data']['files']), []);
            static::__assert_true(isset($manifest['data']['routes']['/_test/verb/probe']), json_encode($methods) . ' is recorded');

            $manifest = static::__manifest('Portal_Route', $args);
            Portal_Route_ManifestSupport::process($manifest, array_keys($manifest['data']['files']), []);
            static::__assert_true(isset($manifest['data']['portal_routes']['/_test/verb/probe']), json_encode($methods) . ' is recorded (portal)');
        }
    }

    public static function test_any_other_verb_is_refused_in_both_realms()
    {
        foreach ([['PUT'], ['GET', 'DELETE'], ['PATCH'], ['HEAD'], ['OPTIONS'], []] as $methods) {
            static::__assert_throws(
                RuntimeException::class,
                function () use ($methods) {
                    $manifest = static::__manifest('Route', ['/_test/verb/probe', $methods]);
                    Route_ManifestSupport::process($manifest, array_keys($manifest['data']['files']), []);
                },
                self::RULE
            );

            static::__assert_throws(
                RuntimeException::class,
                function () use ($methods) {
                    $manifest = static::__manifest('Portal_Route', ['/_test/verb/probe', $methods]);
                    Portal_Route_ManifestSupport::process($manifest, array_keys($manifest['data']['files']), []);
                },
                self::RULE
            );
        }
    }

    /**
     * A synthetic manifest_data with one controller method carrying one route attribute.
     */
    private static function __manifest(string $attribute, array $args): array
    {
        return [
            'data' => [
                'files' => [
                    'fake/synthetic_verb_controller.php' => [
                        'fqcn' => 'App\RSpade\Tests\Dispatch\Php\Synthetic_Verb_Controller',
                        'class' => 'Synthetic_Verb_Controller',
                        'attributes' => null,
                        'public_static_methods' => [
                            'probe' => ['attributes' => [$attribute => [$args], 'Auth' => [['public']]]],
                        ],
                    ],
                ],
                'routes' => [],
                'portal_routes' => [],
            ],
        ];
    }
}
