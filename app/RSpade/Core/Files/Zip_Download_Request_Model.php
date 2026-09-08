<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\Zip_Download_Request_Model_Abstract;

/**
 * Zip_Download_Request_Model - one row per minted multi-file ZIP download (_zip_download_requests).
 *
 * THIS FILE IS A SHELL, AND THAT IS ITS ENTIRE JOB. Every member lives on
 * Zip_Download_Request_Model_Abstract (`Zip_Download_Request_Model_Abstract.php`, beside this file); this class exists
 * so that an application can REPLACE it without holding a copy of the implementation.
 *
 * TO CUSTOMIZE IT, declare your own class of the same name under rsx/models/,
 * extending the base:
 *
 *     namespace Rsx\Models;
 *
 *     class Zip_Download_Request_Model extends Zip_Download_Request_Model_Abstract
 *     {
 *         // only the members you change
 *     }
 *
 * The manifest archives this file as Zip_Download_Request_Model.php.upstream, your class becomes
 * Zip_Download_Request_Model for the whole tree, and it keeps inheriting everything the framework
 * adds to the base from then on - so your override drifts by exactly the members you
 * declared and no others. A member the base marks #[Replaceable] is replaced outright;
 * any other override calls parent:: (PHP-PARENT-CHAIN-01). Copying the base's
 * implementation into your class instead is a second implementation of a class the
 * framework keeps developing, and the manifest refuses it by name.
 *
 * See: php artisan rsx:man class_override
 */
class Zip_Download_Request_Model extends Zip_Download_Request_Model_Abstract
{
}
