<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Models\Ip_Address_Model_Abstract;

/**
 * Ip_Address_Model - tracking and geocoding for one IP address (a system model, not exported to the JavaScript ORM).
 *
 * THIS FILE IS A SHELL, AND THAT IS ITS ENTIRE JOB. Every member lives on
 * Ip_Address_Model_Abstract (`Ip_Address_Model_Abstract.php`, beside this file); this class exists
 * so that an application can REPLACE it without holding a copy of the implementation.
 *
 * TO CUSTOMIZE IT, declare your own class of the same name under rsx/models/,
 * extending the base:
 *
 *     namespace Rsx\Models;
 *
 *     class Ip_Address_Model extends Ip_Address_Model_Abstract
 *     {
 *         // only the members you change
 *     }
 *
 * The manifest archives this file as Ip_Address_Model.php.upstream, your class becomes
 * Ip_Address_Model for the whole tree, and it keeps inheriting everything the framework
 * adds to the base from then on - so your override drifts by exactly the members you
 * declared and no others. A member the base marks #[Replaceable] is replaced outright;
 * any other override calls parent:: (PHP-PARENT-CHAIN-01). Copying the base's
 * implementation into your class instead is a second implementation of a class the
 * framework keeps developing, and the manifest refuses it by name.
 *
 * See: php artisan rsx:man class_override
 */
class Ip_Address_Model extends Ip_Address_Model_Abstract
{
}
