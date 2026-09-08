<?php

namespace App\RSpade\Core\Models;

use App\RSpade\Core\Models\User_Model_Abstract;

/**
 * User_Model - the staff user of a site.
 *
 * THIS FILE IS A SHELL, AND THAT IS ITS ENTIRE JOB. Every member lives on
 * User_Model_Abstract (`user_model_abstract.php`, beside this file); this class exists so
 * that an application can REPLACE it without holding a copy of the implementation.
 *
 * TO CUSTOMIZE IT, declare your own class of the same name under rsx/models/, extending
 * the base:
 *
 *     namespace Rsx\Models;
 *
 *     class User_Model extends User_Model_Abstract
 *     {
 *         public function get_view_profile_url(): ?string
 *         {
 *             // your screens
 *         }
 *     }
 *
 * The manifest archives this file as User_Model.php.upstream, your class becomes
 * User_Model for the whole tree, and it keeps inheriting everything the framework adds to
 * the base from then on - so your override drifts by exactly the members you declared and
 * no others. A member the base marks #[Replaceable] is replaced outright; any other
 * override calls parent:: (PHP-PARENT-CHAIN-01). Copying the base's implementation into
 * your class instead is a second implementation of a class the framework keeps developing,
 * and the manifest refuses it by name.
 *
 * The reference app ships NO override of this class: the one answer the base cannot give
 * (where a staff user's profile screen is) is asked through the resolve hook
 * `user.view_profile_url`, which the app answers from a handler - a seam beats a clone
 * whenever the addition is policy. Override this class only for what a hook cannot express.
 *
 * See: php artisan rsx:man class_override
 */
class User_Model extends User_Model_Abstract
{
}
