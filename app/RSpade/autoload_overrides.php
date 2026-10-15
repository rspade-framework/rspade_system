<?php

/**
 * The class override loader's registration - a composer `files` entry (system/composer.json).
 *
 * Composer runs its `files` entries inside vendor/autoload.php right after it registers its
 * own loader, so this is the earliest point every entrypoint shares: artisan, the web front
 * controller, system/script.php, test-spawned scripts and the IDE bridge all pass through it
 * with no edit of their own. It reads the build's override map and puts the narrow override
 * loader IN FRONT of composer, so a framework class name the application overrides resolves
 * to the application's class from the first autoload on. See Class_Override_Loader and
 * rsx:man class_override.
 *
 * The two classes are required by path: what this file loads is declared before any override
 * could answer for it, and both are on Class_Override_Boot_Set's list for that reason.
 */

if (!class_exists(\App\RSpade\Core\Paths\Rsx_Project_Paths::class, false)) {
    require_once __DIR__ . '/Core/Paths/Rsx_Project_Paths.php';
}

if (!class_exists(\App\RSpade\Core\Overrides\Class_Override_Loader::class, false)) {
    require_once __DIR__ . '/Core/Overrides/Class_Override_Loader.php';
}

\App\RSpade\Core\Overrides\Class_Override_Loader::boot();
