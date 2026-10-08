<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Login;

use App\RSpade\Core\Auth\Auth_Gates;
use App\RSpade\Core\Login\Login_Requirements;
use App\RSpade\Core\Manifest\Manifest;

/**
 * Login_Requirements_Health_Checks - the declaration check for rsx:health.
 *
 * A requirement whose screen() is not a route, or whose surfaces() name something that does
 * not exist, does not fail when it is written - it fails when a user signs in and is sent
 * to a page that is not there, or reaches a step that is refused. Both are found here.
 */
class Login_Requirements_Health_Checks
{
    /**
     * Every Login_Requirement_Abstract class: a valid REALM, a screen() that is a route in
     * that realm, and surfaces() that all exist in the surface index. FAIL names each fault.
     *
     * @return array
     */
    #[Health_Check('Login Requirements')]
    public static function login_requirements(): array
    {
        try {
            $staff = Login_Requirements::all(Auth_Gates::REALM_STAFF);
            $portal = Login_Requirements::all(Auth_Gates::REALM_PORTAL);
        } catch (\RuntimeException $e) {
            return ['status' => 'FAIL', 'detail' => $e->getMessage()];
        }

        if ($staff === [] && $portal === []) {
            return ['status' => 'INFO', 'detail' => 'none declared'];
        }

        $surfaces = Auth_Gates::get_surfaces();
        $records = Manifest::php_class_records_extending('Login_Requirement_Abstract');
        $faults = [];

        foreach ([Auth_Gates::REALM_STAFF => $staff, Auth_Gates::REALM_PORTAL => $portal] as $realm => $names) {
            $route_kind = $realm === Auth_Gates::REALM_PORTAL ? 'portal_route' : 'route';

            foreach ($names as $name) {
                $class = $records[$name]['fqcn'];
                $screen = $class::screen();

                if (!in_array($route_kind, $surfaces[$screen]['kinds'] ?? [], true)) {
                    $faults[] = "{$name}::screen() '{$screen}' is not a {$realm} route";
                }

                foreach ($class::surfaces() as $target) {
                    if (!isset($surfaces[$target])) {
                        $faults[] = "{$name}::surfaces() names '{$target}', which is no route or endpoint";
                    }
                }
            }
        }

        if ($faults !== []) {
            return ['status' => 'FAIL', 'detail' => implode('; ', $faults)];
        }

        return [
            'status' => 'OK',
            'detail' => count($staff) . ' staff, ' . count($portal) . ' portal',
        ];
    }
}
