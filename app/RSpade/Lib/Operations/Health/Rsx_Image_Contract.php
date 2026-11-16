<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace App\RSpade\Lib\Operations\Health;

use RuntimeException;

/**
 * Rsx_Image_Contract - is the RSpade container's image older than this checkout needs?
 *
 * The container image and system/ are versioned apart. The checkout states the image
 * contract it needs in resource/docker/image_contract.conf (whose header is the contract:
 * what the number means and when it moves); an image carries a copy of the file it was
 * built from at RECORD_PATH, plus a `built_from=<framework revision>` line from build.sh.
 * Both are key=value lines with `#` comments.
 *
 * Applies only inside the RSpade container (MARKER_PATH); anywhere else problem() is null.
 * A newer image under an older checkout is fine. An image with no record counts as
 * contract 1 when it carries LOCKD_SCRIPT (the program contract 1 moved into the image)
 * and contract 0 otherwise. A record or requirement file whose `contract=` is missing or
 * not a whole number THROWS naming the file - never read as "fine".
 *
 * The bash twin is system/bin/lib/rsx_image_contract.sh (maintenance-mode.sh and the
 * container entrypoint), and the two print the same sentence. Plain file reads: works on
 * a box whose configuration has not loaded.
 */
class Rsx_Image_Contract
{
    /** What the image provides, written into its last layer (Dockerfile). */
    public const RECORD_PATH = '/opt/rspade/image.conf';

    /** The RSpade container marker; its `target=` names the image to rebuild. */
    public const MARKER_PATH = '/.rspade_container';

    /** Present in every image contract 1 describes: rsx-lockd moved into the image. */
    public const LOCKD_SCRIPT = '/opt/rspade/rsx-lockd/lockd-run.sh';

    /** The checkout's requirement, relative to base_path(). */
    public const REQUIREMENT_FILE = 'app/RSpade/resource/docker/image_contract.conf';

    /**
     * Test seams: read these paths instead of the real ones. Nothing outside a test run may
     * set them, and a test that sets them must clear them.
     */
    public static ?string $requirement_path_for_tests = null;
    public static ?string $record_path_for_tests = null;
    public static ?string $marker_path_for_tests = null;
    public static ?string $lockd_script_for_tests = null;

    /** Whether this process runs in the RSpade container. */
    public static function applies(): bool
    {
        return is_file(static::$marker_path_for_tests ?? self::MARKER_PATH);
    }

    /** The image contract this checkout needs. Throws when its file is malformed. */
    public static function required(): int
    {
        $file = static::$requirement_path_for_tests ?? base_path(self::REQUIREMENT_FILE);

        return static::__contract_number($file, static::__read($file));
    }

    /**
     * What the image records: ['contract' => int, 'built_from' => ?string]. Null when the
     * image carries no record. Throws when the record is malformed.
     */
    public static function record(): ?array
    {
        $file = static::$record_path_for_tests ?? self::RECORD_PATH;
        if (!is_file($file)) {
            return null;
        }

        $values = static::__read($file);
        $built_from = $values['built_from'] ?? '';

        return [
            'contract' => static::__contract_number($file, $values),
            'built_from' => ($built_from === '' || $built_from === 'unknown') ? null : $built_from,
        ];
    }

    /** The image contract this image provides: its record, or the inference for no record. */
    public static function provided(): int
    {
        $record = static::record();
        if ($record !== null) {
            return $record['contract'];
        }

        return is_file(static::$lockd_script_for_tests ?? self::LOCKD_SCRIPT) ? 1 : 0;
    }

    /**
     * The sentence naming the remedy when this container's image is older than this checkout
     * needs; null when it is not, or outside the RSpade container. Throws on a malformed file.
     */
    public static function problem(): ?string
    {
        if (!static::applies()) {
            return null;
        }

        $required = static::required();
        $record = static::record();
        $provided = static::provided();

        if ($provided >= $required) {
            return null;
        }

        if ($record === null) {
            $origin = 'built before images recorded their contract';
        } elseif ($record['built_from'] === null) {
            $origin = 'built from an unrecorded revision';
        } else {
            $origin = 'built from ' . $record['built_from'];
        }

        $marker = static::__read(static::$marker_path_for_tests ?? self::MARKER_PATH);
        $target = ($marker['target'] ?? '') !== '' ? $marker['target'] : 'dev|prod';

        $requirement_file = static::$requirement_path_for_tests ?? base_path(self::REQUIREMENT_FILE);
        $name = static::__read($requirement_file)['breaking_change'] ?? '';

        return "This container's image ({$origin}, image contract {$provided}) is older than this checkout"
            . " needs (image contract {$required}): rebuild the image from this system/"
            . " (bash system/app/RSpade/resource/docker/build.sh {$target}) and recreate the container;"
            . " see breaking change {$name} (php artisan rsx:framework:breaking_changes:show {$name}).";
    }

    #[Health_Check('Container Image')]
    public static function container_image(): array
    {
        if (!static::applies()) {
            return [
                'status' => 'INFO',
                'detail' => 'Not in the RSpade container: no image to compare with this checkout.',
            ];
        }

        try {
            $problem = static::problem();
            $required = static::required();
            $record = static::record();
            $provided = static::provided();
        } catch (RuntimeException $e) {
            return [
                'status' => 'FAIL',
                'detail' => $e->getMessage(),
                'remediation' => 'Restore the file from the framework checkout, or rebuild the image'
                    . ' (bash system/app/RSpade/resource/docker/build.sh <target>) and recreate the container.',
            ];
        }

        if ($problem !== null) {
            $marker = static::__read(static::$marker_path_for_tests ?? self::MARKER_PATH);
            $target = ($marker['target'] ?? '') !== '' ? $marker['target'] : '<target>';

            return [
                'status' => 'FAIL',
                'detail' => $problem,
                'remediation' => "bash system/app/RSpade/resource/docker/build.sh {$target}, then recreate the container"
                    . ' (docker compose up --detach --force-recreate).',
            ];
        }

        if ($record === null) {
            $origin = 'built before images recorded their contract';
        } else {
            $origin = 'built from ' . ($record['built_from'] ?? 'an unrecorded revision');
        }

        return [
            'status' => 'OK',
            'detail' => "image contract {$provided} ({$origin}); this checkout needs {$required}.",
        ];
    }

    /** A key=value file's values (the last occurrence of a key wins); [] when unreadable. */
    private static function __read(string $file): array
    {
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            return [];
        }

        $values = [];
        foreach (explode("\n", $raw) as $line) {
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

    /** The `contract=` value of $file as an integer; throws naming the file otherwise. */
    private static function __contract_number(string $file, array $values): int
    {
        if (!is_readable($file)) {
            throw new RuntimeException("{$file} is unreadable: the file is missing");
        }

        $value = $values['contract'] ?? '';
        if ($value === '' || !ctype_digit($value)) {
            throw new RuntimeException("{$file} is unreadable: contract={$value}");
        }

        return (int) $value;
    }
}
