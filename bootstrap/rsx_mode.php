<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * PRE-BOOT MODE READER
 *
 * THE application mode - development, debug or production - as it can be known before
 * Composer's autoloader exists and before Laravel reads a single configuration file.
 *
 * It is THE mode normalizer: Rsx::get_mode() calls it (and adds the validation), and so
 * do config/app.php and config/jqhtml.php (Laravel's app.env and app.debug derive from
 * it), bootstrap/app.php, rsx_paths.php, rsx_first_run.php, the container gate and the
 * rsx:test refusal. One reader, so no two of them can disagree about what mode this box
 * is in - `RSX_MODE=prod` is production everywhere, Laravel's debug switch included.
 *
 *   - ABSENT MEANS DEVELOPMENT: an environment file with no RSX_MODE line IS a
 *     development install.
 *   - PRECEDENCE FOLLOWS PHPDOTENV: a non-empty real environment variable wins over the
 *     file, because phpdotenv does not overwrite what the process already has. Load-bearing
 *     wherever a parent hands a mode to a child through the environment.
 *   - `dev` and `prod` are the aliases, resolved here. An UNRECOGNIZED value is returned
 *     as it was written and never rejected here: Rsx::get_mode() rejects it with a better
 *     error than a pre-boot caller could.
 *
 * REQUIRED WITH require_once, ALWAYS - any caller may be first.
 */

require_once __DIR__ . '/rsx_paths.php';

/**
 * The application mode, normalized, pre-boot.
 *
 * @return string 'development', 'debug', 'production', or whatever unrecognized value the
 *                environment carries (left for get_mode() to reject).
 */
function rsx_preboot_mode(): string
{
    $mode = strtolower(trim(rsx_paths_env_value('RSX_MODE')));

    if ($mode === '' || $mode === 'dev') {
        return 'development';
    }

    if ($mode === 'prod') {
        return 'production';
    }

    return $mode;
}

/**
 * Is this box in one of the two SEALED modes - the ones built once by an explicit command
 * and then treated as immutable?
 *
 * @return bool
 */
function rsx_preboot_mode_is_production_like(): bool
{
    $mode = rsx_preboot_mode();

    return $mode === 'debug' || $mode === 'production';
}
