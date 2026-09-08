<?php

namespace App\RSpade\Core\Files;

use App\RSpade\Core\Files\File_Storage_Model_Abstract;

/**
 * File_Storage_Model - Physical file storage with deduplication.
 *
 * THIS FILE IS A SHELL, AND THAT IS ITS ENTIRE JOB. Every member lives on
 * File_Storage_Model_Abstract (`File_Storage_Model_Abstract.php`, beside this file); this class exists
 * so that an application can REPLACE it without holding a copy of the implementation.
 *
 * TO CUSTOMIZE IT, declare your own class of the same name under rsx/models/,
 * extending the base:
 *
 *     namespace Rsx\Models;
 *
 *     class File_Storage_Model extends File_Storage_Model_Abstract
 *     {
 *         // only the members you change
 *     }
 *
 * The manifest archives this file as File_Storage_Model.php.upstream, your class becomes
 * File_Storage_Model for the whole tree, and it keeps inheriting everything the framework
 * adds to the base from then on - so your override drifts by exactly the members you
 * declared and no others. A member the base marks #[Replaceable] is replaced outright;
 * any other override calls parent:: (PHP-PARENT-CHAIN-01). Copying the base's
 * implementation into your class instead is a second implementation of a class the
 * framework keeps developing, and the manifest refuses it by name.
 *
 * See: php artisan rsx:man class_override
 */
class File_Storage_Model extends File_Storage_Model_Abstract
{
}
