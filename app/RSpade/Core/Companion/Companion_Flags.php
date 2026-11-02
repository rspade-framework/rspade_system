<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Core\Companion;

use App\RSpade\Core\Paths\Rsx_Project_Paths;

/**
 * Companion_Flags - the desired state of companion applications, as files.
 *
 * A companion should run while its flag file exists; absent is stopped, so nothing runs until
 * an administrator starts it. The maintenance overlay is a second file that holds every
 * companion down without touching the first. Files, because they must be readable when the
 * database is down and the tree is broken, and because rsx-companiond reads them with no PHP.
 *
 * They are PER BOX: storage/state is this machine's. On several web hosts, set them on each.
 * Only the rsx:companion_apps commands write them.
 */
class Companion_Flags
{
    public static function is_desired(string $name): bool
    {
        return is_file(Rsx_Project_Paths::companion_desired_flag_file($name));
    }

    public static function set_desired(string $name, bool $running): void
    {
        $file = Rsx_Project_Paths::companion_desired_flag_file($name);

        if ($running) {
            static::__write($file);
        } elseif (is_file($file)) {
            unlink($file);
        }
    }

    public static function is_maintenance_on(): bool
    {
        return is_file(Rsx_Project_Paths::companion_maintenance_flag_file());
    }

    public static function set_maintenance(bool $on): void
    {
        $file = Rsx_Project_Paths::companion_maintenance_flag_file();

        if ($on) {
            static::__write($file);
        } elseif (is_file($file)) {
            unlink($file);
        }
    }

    private static function __write(string $file): void
    {
        ensure_directory(dirname($file));

        // The content is for a person reading the directory: when, never what.
        if (file_put_contents($file, gmdate('c') . "\n") === false) {
            throw new \RuntimeException("Could not write {$file}");
        }
    }
}
