<?php

namespace App\RSpade\Core\Controller;

use App\RSpade\Core\Bundle\BundleIntegration_Abstract;

/**
 * Controller integration for RSX framework
 *
 * The JS stub generation this class used to own is a MANIFEST concern and lives in
 * Controller_Stub_ManifestSupport, an ordinary entry in config('rsx.manifest_support').
 */
class Controller_BundleIntegration extends BundleIntegration_Abstract
{
    /**
     * Get the integration's unique identifier
     *
     * @return string Integration identifier
     */
    public static function get_name(): string
    {
        return 'controller';
    }

    /**
     * Get file extensions handled by this integration
     *
     * Controllers are PHP files, but we don't need to register
     * extensions as the PHP files are already handled by the core.
     *
     * @return array Empty array as no special extensions needed
     */
    public static function get_file_extensions(): array
    {
        return [];
    }
}
