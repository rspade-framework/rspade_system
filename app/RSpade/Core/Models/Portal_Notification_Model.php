<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Models\Portal_Notification_Model_Abstract;

/**
 * Portal_Notification_Model - the framework-core portal activity / notification record.
 *
 * THIS FILE IS A SHELL, AND THAT IS ITS ENTIRE JOB. Every member lives on
 * Portal_Notification_Model_Abstract (`Portal_Notification_Model_Abstract.php`, beside this file); this class exists
 * so that an application can REPLACE it without holding a copy of the implementation.
 *
 * TO CUSTOMIZE IT, declare your own class of the same name under rsx/models/,
 * extending the base:
 *
 *     namespace Rsx\Models;
 *
 *     class Portal_Notification_Model extends Portal_Notification_Model_Abstract
 *     {
 *         // only the members you change
 *     }
 *
 * The manifest archives this file as Portal_Notification_Model.php.upstream, your class becomes
 * Portal_Notification_Model for the whole tree, and it keeps inheriting everything the framework
 * adds to the base from then on - so your override drifts by exactly the members you
 * declared and no others. A member the base marks #[Replaceable] is replaced outright;
 * any other override calls parent:: (PHP-PARENT-CHAIN-01). Copying the base's
 * implementation into your class instead is a second implementation of a class the
 * framework keeps developing, and the manifest refuses it by name.
 *
 * See: php artisan rsx:man class_override
 */
class Portal_Notification_Model extends Portal_Notification_Model_Abstract
{
}
