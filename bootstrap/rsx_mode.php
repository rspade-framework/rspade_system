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
 * It mirrors App\RSpade\Core\Rsx::get_mode() EXACTLY, defaults included, because a
 * pre-boot guard and the booted application disagreeing about what mode this box is in
 * would make every refusal written against it arbitrary.
 *
 *   - ABSENT MEANS DEVELOPMENT. env('RSX_MODE', MODE_DEVELOPMENT) defaults that way once
 *     booted, so an environment file with no RSX_MODE line IS a development install.
 *   - PRECEDENCE FOLLOWS PHPDOTENV: a non-empty real environment variable wins over the
 *     file, because phpdotenv does not overwrite what the process already has. Load-bearing
 *     wherever a parent hands a mode to a child through the environment.
 *   - `dev` and `prod` are the aliases get_mode() normalizes. An UNRECOGNIZED value is
 *     returned as it was written and never rejected here: validation belongs to get_mode(),
 *     which throws a better error than a pre-boot guard could, and no guard in this tier
 *     may become a second opinion on what a valid mode is.
 *
 * Two callers today, and both of them refuse a process on the answer:
 * bootstrap/rsx_container_gate.php (development runs in the container or not at all) and
 * the rsx:test refusal in bootstrap/rsx_preboot.php (the suite does not run in a production
 * mode). One reader, so the two can never drift.
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
