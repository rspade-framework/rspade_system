<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Tests\Proxority\Php;

use RuntimeException;
use App\RSpade\Core\Auth\Auth_ManifestSupport;
use App\RSpade\Core\Proxority\Proxority_Directives;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;

/**
 * PROXORITY-01: #[Unthrottled] / #[Priority] / #[Media] sit only on the surfaces that honour
 * them, at most one per method - a manifest-build FATAL otherwise.
 *
 * The rule is driven two ways: Proxority_Directives::resolve() over the kind matrix, and
 * Auth_ManifestSupport::build_index() over a SYNTHETIC file map (never the real manifest),
 * which is where the build calls it - proving the directive lands on the surface entry and
 * that a class, an instance method or a non-surface static carrying one fails the build.
 *
 * Behavior of record: Proxority_Directives (docblock); rsx:man proxority (phase 4).
 */
class Proxority_Directives_Rule_Test extends Rsx_Test_Abstract
{
    protected static $use_database_transactions = false;

    private const RULE = 'PROXORITY-01';

    public static function test_each_directive_is_accepted_on_every_kind_that_honours_it()
    {
        $matrix = [
            'Unthrottled' => ['route', 'spa', 'portal_route', 'api'],
            'Priority' => ['route', 'spa', 'portal_route', 'api', 'ajax'],
            'Media' => ['route', 'portal_route', 'api'],
        ];

        foreach ($matrix as $directive => $kinds) {
            foreach ($kinds as $kind) {
                static::__assert_equals(
                    strtolower($directive),
                    Proxority_Directives::resolve([$directive => [[]]], [$kind], 'probe'),
                    "#[{$directive}] on {$kind}"
                );
            }
        }

        // Two kinds on one member, both honouring it.
        static::__assert_equals('media', Proxority_Directives::resolve(['Media' => [[]]], ['route', 'portal_route'], 'probe'));
        static::__assert_null(Proxority_Directives::resolve(['Auth' => [['public']]], ['route'], 'probe'), 'no directive, no value');
    }

    public static function test_a_directive_on_a_kind_that_does_not_honour_it_is_refused()
    {
        $refused = [
            'Unthrottled' => ['ajax', 'companion', 'model_fetch'],
            'Priority' => ['companion', 'model_fetch'],
            'Media' => ['spa', 'ajax', 'companion', 'model_fetch'],
        ];

        foreach ($refused as $directive => $kinds) {
            foreach ($kinds as $kind) {
                static::__assert_throws(
                    RuntimeException::class,
                    fn () => Proxority_Directives::resolve([$directive => [[]]], [$kind], 'probe'),
                    self::RULE
                );
            }
        }

        // One honouring kind does not excuse another that does not.
        static::__assert_throws(
            RuntimeException::class,
            fn () => Proxority_Directives::resolve(['Media' => [[]]], ['route', 'ajax'], 'probe'),
            self::RULE
        );
    }

    public static function test_two_directives_on_one_method_are_refused()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => Proxority_Directives::resolve(['Priority' => [[]], 'Media' => [[]]], ['route'], 'probe'),
            self::RULE
        );
    }

    public static function test_a_directive_on_no_surface_is_refused()
    {
        static::__assert_throws(
            RuntimeException::class,
            fn () => Proxority_Directives::resolve(['Unthrottled' => [[]]], [], 'probe'),
            self::RULE
        );
    }

    public static function test_the_build_records_the_directive_on_the_surface()
    {
        $index = Auth_ManifestSupport::build_index(static::__files([
            'download' => ['Route' => [['/_test/proxority/download/:key']], 'Media' => [[]], 'Auth' => [['public']]],
            'status' => ['Ajax_Endpoint' => [[]], 'Priority' => [[]], 'Auth' => [['public']]],
            'plain' => ['Route' => [['/_test/proxority/plain']], 'Auth' => [['public']]],
        ]));

        $surfaces = $index['surfaces'];
        static::__assert_equals('media', $surfaces['Synthetic_Proxority_Controller::download']['throttle'] ?? null);
        static::__assert_equals('priority', $surfaces['Synthetic_Proxority_Controller::status']['throttle'] ?? null);
        static::__assert_false(isset($surfaces['Synthetic_Proxority_Controller::plain']['throttle']));
    }

    public static function test_the_build_fails_on_a_misplaced_directive()
    {
        $cases = [
            'on an Ajax endpoint' => [static::__files(['call' => ['Ajax_Endpoint' => [[]], 'Media' => [[]], 'Auth' => [['public']]]])],
            'on a non-surface static' => [static::__files(['helper' => ['Unthrottled' => [[]]]])],
            'two on one route' => [static::__files(['page' => ['Route' => [['/_test/proxority/page']], 'Priority' => [[]], 'Unthrottled' => [[]], 'Auth' => [['public']]]])],
            'on a class' => [static::__files([], ['Media' => [[]]])],
            'on an instance method' => [static::__files([], null, ['render' => ['Priority' => [[]]]])],
        ];

        foreach ($cases as [$files]) {
            static::__assert_throws(
                RuntimeException::class,
                fn () => Auth_ManifestSupport::build_index($files),
                self::RULE
            );
        }
    }

    /**
     * A synthetic file map: one controller with the given static methods' attributes.
     */
    private static function __files(array $static_methods, ?array $class_attributes = null, array $instance_methods = []): array
    {
        $methods = [];
        foreach ($static_methods as $name => $attributes) {
            $methods[$name] = ['attributes' => $attributes, 'static' => true];
        }

        $instance = [];
        foreach ($instance_methods as $name => $attributes) {
            $instance[$name] = ['attributes' => $attributes];
        }

        return [
            'fake/synthetic_proxority_controller.php' => [
                'fqcn' => 'App\RSpade\Tests\Proxority\Php\Synthetic_Proxority_Controller',
                'class' => 'Synthetic_Proxority_Controller',
                'extends' => 'Rsx_Controller_Abstract',
                'attributes' => $class_attributes,
                'public_static_methods' => $methods,
                'public_instance_methods' => $instance,
            ],
        ];
    }
}
