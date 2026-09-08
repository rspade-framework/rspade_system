<?php

namespace App\RSpade\Core\Database;

use App\RSpade\Core\Bundle\BundleIntegration_Abstract;

/**
 * Database integration for RSX framework
 *
 * The JS model stub generation this class used to own is a MANIFEST concern and lives in
 * Model_Stub_ManifestSupport, an ordinary entry in config('rsx.manifest_support').
 */
class Database_BundleIntegration extends BundleIntegration_Abstract
{
    /**
     * Get the integration's unique identifier
     *
     * @return string Integration identifier
     */
    public static function get_name(): string
    {
        return 'database';
    }

    /**
     * Get file extensions handled by this integration
     *
     * Models are PHP files, but we don't need to register
     * extensions as the PHP files are already handled by the core.
     *
     * @return array Empty array as no special extensions needed
     */
    public static function get_file_extensions(): array
    {
        return [];
    }
}