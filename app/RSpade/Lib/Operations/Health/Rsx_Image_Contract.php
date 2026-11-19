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
 * The container image and system/ are versioned apart. The checkout states the oldest image
 * it runs on as `min_image_version=` in resource/docker/image_contract.conf (whose header
 * has the rule for moving it); an image records what it is at RECORD_PATH as
 * `image_version=` beside `built_from=`. Dated versions (YYYY.MM.DD[.N]) compare field by
 * field; `image_version=local` (an image build.sh made from a checkout) always passes; no
 * record, or no dated image_version, fails.
 *
 * Skipped outside the RSpade container (MARKER_PATH) and on the framework authors' box
 * (IS_FRAMEWORK_DEVELOPER true). A requirement file that is missing or malformed THROWS
 * naming the file - never read as "fine".
 *
 * The comparison and the sentence are bootstrap/rsx_image_contract.php's, the pre-boot gate
 * that makes an older image fatal for commands and web requests; this class adds the test
 * seams and the health row. The bash twin is system/bin/lib/rsx_image_contract.sh, and all
 * three print the same sentence.
 */
class Rsx_Image_Contract
{
    /** What the image records, written into its last layer (Dockerfile). */
    public const RECORD_PATH = '/opt/rspade/image.conf';

    /** The RSpade container marker; its `target=` names the image to pull. */
    public const MARKER_PATH = '/.rspade_container';

    /** The checkout's requirement, relative to base_path(). */
    public const REQUIREMENT_FILE = 'app/RSpade/resource/docker/image_contract.conf';

    /**
     * Test seams: read these paths, or this answer, instead of the real ones. Nothing outside
     * a test run may set them, and a test that sets them must clear them.
     */
    public static ?string $requirement_path_for_tests = null;
    public static ?string $record_path_for_tests = null;
    public static ?string $marker_path_for_tests = null;
    public static ?bool $framework_developer_for_tests = null;

    /** Whether this process runs in the RSpade container. */
    public static function applies(): bool
    {
        return is_file(static::__marker_path());
    }

    /** Whether the check is skipped on this box: the framework authors' own. */
    public static function skipped(): bool
    {
        if (static::$framework_developer_for_tests !== null) {
            return static::$framework_developer_for_tests;
        }

        static::__require_bootstrap();

        return rsx_image_contract_framework_developer();
    }

    /** The minimum image version this checkout needs. Throws when its file is malformed. */
    public static function required(): string
    {
        static::__require_bootstrap();

        return rsx_image_contract_required(static::__requirement_path());
    }

    /**
     * What the image records: ['image_version' => ?string, 'built_from' => ?string]. Null
     * when the image carries no record.
     */
    public static function record(): ?array
    {
        static::__require_bootstrap();

        $values = rsx_image_contract_read(static::$record_path_for_tests ?? self::RECORD_PATH);
        if ($values === null) {
            return null;
        }

        $version = $values['image_version'] ?? '';
        $built_from = $values['built_from'] ?? '';

        return [
            'image_version' => $version === '' ? null : $version,
            'built_from' => ($built_from === '' || $built_from === 'unknown') ? null : $built_from,
        ];
    }

    /**
     * The sentence naming the remedy when this container's image is older than this checkout
     * needs; null when it is not, outside the RSpade container, or on the framework authors'
     * box. Throws on a malformed requirement file.
     */
    public static function problem(): ?string
    {
        if (!static::applies() || static::skipped()) {
            return null;
        }

        static::__require_bootstrap();

        return rsx_image_contract_sentence(
            static::__requirement_path(),
            static::$record_path_for_tests ?? self::RECORD_PATH,
            static::__marker_path()
        );
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

        if (static::skipped()) {
            return [
                'status' => 'INFO',
                'detail' => 'The framework authors\' box (IS_FRAMEWORK_DEVELOPER): the image version is not checked.',
            ];
        }

        try {
            $problem = static::problem();
            $required = static::required();
        } catch (RuntimeException $e) {
            return [
                'status' => 'FAIL',
                'detail' => $e->getMessage(),
                'remediation' => 'Restore the file from the framework checkout: php artisan rsx:framework:pull.',
            ];
        }

        if ($problem !== null) {
            $target = (rsx_image_contract_read(static::__marker_path()) ?? [])['target'] ?? '';
            $target = $target !== '' ? $target : '<dev|prod>';

            return [
                'status' => 'FAIL',
                'detail' => $problem,
                'remediation' => "docker pull ghcr.io/rspade-framework/rspade-server-{$target}:latest, then recreate"
                    . ' the container from it (docker compose up --detach --force-recreate).',
            ];
        }

        $record = static::record();
        $built_from = $record['built_from'] ?? 'an unrecorded revision';

        return [
            'status' => 'OK',
            'detail' => "image {$record['image_version']} (built from {$built_from}); this checkout needs {$required} or newer.",
        ];
    }

    private static function __marker_path(): string
    {
        return static::$marker_path_for_tests ?? self::MARKER_PATH;
    }

    private static function __requirement_path(): string
    {
        return static::$requirement_path_for_tests ?? base_path(self::REQUIREMENT_FILE);
    }

    private static function __require_bootstrap(): void
    {
        require_once base_path('bootstrap/rsx_image_contract.php');
    }
}
