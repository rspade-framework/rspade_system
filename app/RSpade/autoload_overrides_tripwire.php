<?php

/**
 * The class override trip-wire. Never included on purpose.
 *
 * Class_Override_Loader points composer's classmap entry for every overridden framework class
 * at this file (in memory - nothing under vendor/ is written). The override loader is
 * registered in front of composer, so composer is only ever asked for such a name when that
 * ordering has been broken: something registered ahead of the override loader, or a process
 * that loaded composer without the override loader's `files` entry. Composer would otherwise
 * load the framework class and the override would be silently bypassed for that name. This
 * file turns that into a loud failure naming the class.
 */

$rsx_override_requested = '(unknown)';

foreach (debug_backtrace() as $rsx_override_frame) {
    if (($rsx_override_frame['function'] ?? '') === 'loadClass'
        && ($rsx_override_frame['class'] ?? '') === 'Composer\\Autoload\\ClassLoader') {
        $rsx_override_requested = (string) ($rsx_override_frame['args'][0] ?? '(unknown)');
        break;
    }
}

$rsx_override_by = \App\RSpade\Core\Overrides\Class_Override_Loader::map()[ltrim($rsx_override_requested, '\\')]['by_fqcn'] ?? '(the application class)';

throw new \LogicException(
    "{$rsx_override_requested} is overridden by {$rsx_override_by}, but composer was asked for it before the\n"
    . "  class override loader: composer would have loaded the framework class and bypassed the override.\n"
    . "  The override loader must be first on the autoload stack (Class_Override_Loader::register()).\n"
    . '  See: php artisan rsx:man class_override'
);
