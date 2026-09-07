<?php

namespace App\RSpade\Tests\Attachments\Php;

use App\RSpade\Core\Files\File_Attachment_Model;
use App\RSpade\Tests\Attachments\Php\Attachment_Fixture_Handler;

/**
 * Test fixture: a handler that opts into serve-time freshness checking. is_stale() returns the
 * test-controlled static flag, so a test can prove apply_serve_freshness() evicts a stale blob.
 */
class Attachment_Fixture_Fresh_Handler extends Attachment_Fixture_Handler
{
    public const CHECK_FRESHNESS_ON_SERVE = true;

    /** Test-controlled staleness reported by is_stale(). */
    public static bool $stale = false;

    public static function reset(): void
    {
        parent::reset();
        static::$stale = false;
    }

    public static function is_stale(File_Attachment_Model $attachment): bool
    {
        return static::$stale;
    }
}
