<?php
/**
 * CODING CONVENTION:
 * snake_case for variable_names and function_names.
 *
 * PRE-BOOT MINIMUM IMAGE VERSION
 *
 * The RSpade container image and system/ are versioned apart. The checkout states the
 * oldest image it runs on as `min_image_version=` in
 * app/RSpade/resource/docker/image_contract.conf (that file's header has the rule for
 * moving it); an image records what it is at /opt/rspade/image.conf as `image_version=`
 * beside `built_from=`. Inside the container an image older than the checkout needs is
 * FATAL: the checkout may depend on a service or tool only a newer image provides, and
 * running on regardless fails somewhere far from the cause.
 *
 * THE COMPARISON. Versions are dated, YYYY.MM.DD with an optional .N, compared
 * numerically field by field (a missing .N is 0). `image_version=local` - an image
 * build.sh made from a checkout's own sources - always passes. No record, no
 * image_version, or one that is not a dated version FAILS.
 *
 * SKIPPED outside the RSpade container (no /.rspade_container) and on the framework
 * authors' own box (IS_FRAMEWORK_DEVELOPER true), whose host-built image has no record.
 *
 * THE GATE, rsx_image_contract_gate(), is required by both entrypoints before the
 * autoloader: bootstrap/rsx_preboot.php (artisan and script.php; the boot-free repair
 * commands and rsx:health are exempt) and public/index.php (every request, after the
 * maintenance and migration gates). The healthy path is one stat and two small reads.
 *
 * Its twins print the same sentence: system/bin/lib/rsx_image_contract.sh (the container
 * entrypoint, maintenance-mode.sh, the updater's pre-check) and
 * App\RSpade\Lib\Operations\Health\Rsx_Image_Contract (rsx:health "Container Image",
 * the post-pull report), which calls the functions below.
 *
 * Plain filesystem calls only: no autoloader, no framework, no configuration.
 * REQUIRED WITH require_once - every reader may be first.
 */

require_once __DIR__ . '/rsx_paths.php';

/** A key=value file's values (`#` comments; the last occurrence wins); null when unreadable. */
function rsx_image_contract_read(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }

    $raw = @file_get_contents($file);
    if (!is_string($raw)) {
        return null;
    }

    $values = [];
    foreach (preg_split('/\R/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $equals = strpos($line, '=');
        if ($equals !== false) {
            $values[trim(substr($line, 0, $equals))] = trim(substr($line, $equals + 1));
        }
    }

    return $values;
}

/** A dated version (YYYY.MM.DD or YYYY.MM.DD.N) as four integers; null for anything else. */
function rsx_image_contract_version_fields(string $version): ?array
{
    if (!preg_match('/^(\d{4})\.(\d{2})\.(\d{2})(?:\.(\d+))?$/', $version, $match)) {
        return null;
    }

    return [(int) $match[1], (int) $match[2], (int) $match[3], (int) ($match[4] ?? 0)];
}

/** Whether dated version $a is older than dated version $b. */
function rsx_image_contract_version_older(string $a, string $b): bool
{
    return rsx_image_contract_version_fields($a) < rsx_image_contract_version_fields($b);
}

/**
 * The minimum image version the checkout file $requirement_file states. Throws a
 * RuntimeException naming the file when it is missing or the value is not a dated version.
 */
function rsx_image_contract_required(string $requirement_file): string
{
    $values = rsx_image_contract_read($requirement_file);
    if ($values === null) {
        throw new RuntimeException("{$requirement_file} is unreadable: the file is missing");
    }

    $value = $values['min_image_version'] ?? '';
    if (rsx_image_contract_version_fields($value) === null) {
        throw new RuntimeException("{$requirement_file} is unreadable: min_image_version={$value}");
    }

    return $value;
}

/** Whether IS_FRAMEWORK_DEVELOPER is true (1/true/yes/on): the environment, else .env. */
function rsx_image_contract_framework_developer(): bool
{
    $value = strtolower(rsx_paths_env_value('IS_FRAMEWORK_DEVELOPER'));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/**
 * The sentence naming the remedy when the image recorded in $record_file is older than
 * $requirement_file needs; null when it is not. Assumes the container (the caller has
 * checked $marker_file exists; it supplies the image target). Throws when the requirement
 * file is malformed.
 */
function rsx_image_contract_sentence(string $requirement_file, string $record_file, string $marker_file): ?string
{
    $required = rsx_image_contract_required($requirement_file);
    $provided = (rsx_image_contract_read($record_file) ?? [])['image_version'] ?? '';

    if ($provided === 'local') {
        return null;
    }

    if ($provided === '') {
        $origin = 'no recorded version';
    } elseif (rsx_image_contract_version_fields($provided) === null) {
        $origin = "version {$provided}, not a dated version";
    } elseif (rsx_image_contract_version_older($provided, $required)) {
        $origin = "version {$provided}";
    } else {
        return null;
    }

    $target = (rsx_image_contract_read($marker_file) ?? [])['target'] ?? '';
    if ($target === '') {
        $target = '<dev|prod>';
    }

    return "This container's image ({$origin}) is older than this checkout needs ({$required} or newer):"
        . " docker pull ghcr.io/rspade-framework/rspade-server-{$target}:latest, then recreate the container from it.";
}

/**
 * Whether the artisan command $command is exempt from the gate: the boot-free repair
 * commands rsx_preboot() intercepts (a pull is how a checkout and its image get back in
 * step; maintenance mode and rsx:git must work on any tree) and rsx:health, which reports
 * the same problem as its "Container Image" row. Null - an external script - is not.
 */
function rsx_image_contract_exempt(?string $command): bool
{
    return in_array($command, [
        'rsx:framework:pull',
        'rsx:maintenance:enable',
        'rsx:maintenance:disable',
        'rsx:git',
        'rsx:health',
    ], true);
}

/**
 * Refuse this process when the container's image is older than this checkout needs.
 *
 * Command line: the sentence on stderr, exit 1. Web: a 500 page carrying the sentence.
 * Returns when the image is current, outside the container, or on the framework authors'
 * box. A malformed requirement file refuses too, naming the file.
 *
 * The paths default to the real ones; a test passes scratch files.
 */
function rsx_image_contract_gate(
    string $marker = '/.rspade_container',
    string $record = '/opt/rspade/image.conf',
    ?string $requirement = null
): void {
    if (!is_file($marker)) {
        return;
    }

    try {
        $problem = rsx_image_contract_sentence(
            $requirement ?? rsx_paths_system_dir() . '/app/RSpade/resource/docker/image_contract.conf',
            $record,
            $marker
        );
    } catch (RuntimeException $e) {
        $problem = $e->getMessage();
    }

    // Asked only when there is something to skip, so the healthy path never reads .env.
    if ($problem === null || rsx_image_contract_framework_developer()) {
        return;
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "[ERROR] {$problem}\n");
        exit(1);
    }

    require_once __DIR__ . '/rsx_preboot_page.php';
    rsx_preboot_page_render(500, 'Container image out of date', [$problem]);

    exit;
}
