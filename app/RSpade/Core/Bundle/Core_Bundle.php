<?php

namespace App\RSpade\Core\Bundle;

use App\RSpade\Core\Bundle\Rsx_Bundle_Abstract;

/**
 * Core Framework Bundle
 *
 * Provides all core JavaScript framework files that are required for RSX to function.
 * This bundle is automatically included in every project.
 */
class Core_Bundle extends Rsx_Bundle_Abstract
{
    /**
     * Define the bundle configuration
     *
     * @return array Bundle configuration
     */
    public static function define(): array
    {
        return [
            'include' => [
                __DIR__,
                'app/RSpade/Core/Js',
                'app/RSpade/Core/Data',
                'app/RSpade/Core/Database',
                'app/RSpade/Core/Time',  // Timezone preference endpoints (Rsx_Timezone_Controller proxy)
                'app/RSpade/Core/Theme',  // Theme preference endpoints (Rsx_Dark_Mode_Controller proxy)
                'app/RSpade/Core/Models',  // Framework models (User_Model, Site_Model, etc.)
                'app/RSpade/Core/Forms',  // The form contract: Rsx_Form + Form_Input_Abstract (one engine, every bundle)
                'app/RSpade/Core/Ui',  // Framework UI primitives (Spinner + the default spinner, Button_Utils - the busy-state engine behind click_async() and Rsx_Form)
                'app/RSpade/Core/Files',  // File_Attachment_Model + its JS class (thumbnail_url) - <Attachment_Thumbnail> is a Core component, so its model must reach every bundle
                'app/RSpade/Core/SPA',
                'app/RSpade/Core/Debug',  // Debug components (JS_Tree_Debug_*)
                'app/RSpade/Core/Preview',  // Document preview components (Document_Preview + viewers)
                'app/RSpade/Core/Authorship',  // Authorship display components (Record_Author)
                'app/RSpade/Core/Turnstile',  // Cloudflare Turnstile widget (Turnstile_Input)
                'app/RSpade/Core/TwoFactor',  // Second-factor components (Totp_Enrollment, Passkey_Register, Two_Factor_Challenge) + Rsx_Two_Factor.js
                'app/RSpade/Breadcrumbs',  // Progressive breadcrumb resolution
                'app/RSpade/Lib',
            ],
            'npm' => [
                'DOMPurify' => "import DOMPurify from 'dompurify'",
                'sha1' => "import sha1 from 'js-sha1'",
            ],
        ];
    }
}
