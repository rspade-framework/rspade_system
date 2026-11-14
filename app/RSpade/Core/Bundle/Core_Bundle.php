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
                'app/RSpade/Lib/Geo/ReferenceData',
                'app/RSpade/Core/Database',
                'app/RSpade/Core/Time',  // Timezone preference endpoints (Rsx_Timezone_Controller proxy)
                'app/RSpade/Core/Session/Rsx_Session_Controller.php',  // The page-view report endpoint (Rsx_Session.js); the rest of Core/Session is server-only
                'app/RSpade/Lib/Ui/DarkMode',  // Theme preference endpoints (Rsx_Dark_Mode_Controller proxy)
                'app/RSpade/Core/Models',  // Framework models (User_Model, Site_Model, etc.)
                // The feature models that live in Lib/ beside their feature, named file by file:
                // each directory around them is server-only.
                'app/RSpade/Lib/Geo/Places',  // Country_Model, Region_Model
                'app/RSpade/Lib/Settings/Has_Preference_Variables.php',
                'app/RSpade/Lib/Portal/Portal_User_Model_Abstract.php',
                'app/RSpade/Lib/Portal/Portal_User_Model.php',
                'app/RSpade/Lib/Portal/Portal_Notification_Model_Abstract.php',
                'app/RSpade/Lib/Portal/Portal_Notification_Model.php',
                'app/RSpade/Lib/Messaging/Mail/Email_Attachment_Model_Abstract.php',
                'app/RSpade/Lib/Messaging/Mail/Email_Attachment_Model.php',
                'app/RSpade/Lib/Messaging/Mail/Email_Blocked_Address_Model_Abstract.php',
                'app/RSpade/Lib/Messaging/Mail/Email_Blocked_Address_Model.php',
                'app/RSpade/Lib/Messaging/Mail/Email_Queue_Model_Abstract.php',
                'app/RSpade/Lib/Messaging/Mail/Email_Queue_Model.php',
                'app/RSpade/Lib/Messaging/Mail/Email_Recipient_Model_Abstract.php',
                'app/RSpade/Lib/Messaging/Mail/Email_Recipient_Model.php',
                'app/RSpade/Lib/Messaging/Sms/Sms_Queue_Model_Abstract.php',
                'app/RSpade/Lib/Messaging/Sms/Sms_Queue_Model.php',
                'app/RSpade/Lib/Messaging/Sms/Sms_Recipient_Model_Abstract.php',
                'app/RSpade/Lib/Messaging/Sms/Sms_Recipient_Model.php',
                'app/RSpade/Core/Forms',  // The form contract: Rsx_Form + Form_Input_Abstract (one engine, every bundle)
                'app/RSpade/Core/Ui',  // Framework UI primitives (Spinner + the default spinner, Button_Utils - the busy-state engine behind click_async() and Rsx_Form)
                'app/RSpade/Core/Files',  // File upload/preview controllers + File_Attachment_Model and its JS class (thumbnail_url), used by the Core <Attachment_Thumbnail> component. The generated Base_ stub does NOT depend on this include: a JS model class carries its stub into every bundle it reaches (BundleCompiler::_get_model_stubs_for_js_classes), including when the model is class-overridden into rsx/models.
                'app/RSpade/Core/SPA',
                'app/RSpade/Lib/Developer/Debug',  // Debug components (JS_Tree_Debug_*)
                'app/RSpade/Core/Preview',  // Document preview components (Document_Preview + viewers)
                'app/RSpade/Lib/Ui/RecordAuthor',  // Authorship display components (Record_Author)
                'app/RSpade/Lib/Ui/Money',  // Money display (Money_Display); Money_Input is in Core/Forms
                'app/RSpade/Lib/Geo/Ip/ui',  // The DB-IP attribution beside a shown location (Geo_Ip_Credit); the rest of Lib/Geo/Ip is server-only
                'app/RSpade/Lib/SignIn/Turnstile',  // Cloudflare Turnstile widget (Turnstile_Input)
                'app/RSpade/Lib/SignIn/TwoFactor',  // Second-factor components (Totp_Enrollment, Passkey_Register, Two_Factor_Challenge, Passkey_Sign_In) + Rsx_Two_Factor.js
                'app/RSpade/Lib/SignIn/Sso',  // Federated sign-in buttons (Sso_Buttons) + Rsx_Sso.js - the login page is its own bundle
                'app/RSpade/Lib/SignIn/Confirmation',  // "Confirm it's you" before a sensitive action: <Password_Confirmation_Form> + Rsx_Password_Confirmation.js + its controller
                'app/RSpade/Core/Task/ui',  // Task widgets (Task_Status_Badge, Task_Output, Task_Report, Task_Report_Browser) + Rsx_Task.js
                'app/RSpade/Core/Task/ui/vendor/xterm.scss',  // xterm.js's stylesheet for Task_Output; vendor/ is never scanned by a directory include
                'app/RSpade/Core/Companion/ui',  // Companion_Output (the Task_Output twin over a companion's stream) + Rsx_Companion.js
                'app/RSpade/Core/Time/vendor/rsx_timezone_abbreviations.js',  // generated zone-name table (rsx:time:abbreviations) - the vendor bundle, by path like every vendor/ file
                'app/RSpade/Core/Time/vendor/rsx_timestamp_format.js',  // Rsx_Time's formatting engine, the JS twin of Rsx_Timestamp_Format.php
                'app/RSpade/Lib/Ui/Flash',  // Flash alerts (Flash_Alert + Server_Side_Flash)
            ],
            // The outline file-type icons BundleCompiler bakes into every bundle for
            // <File_Type_Icon> live under a resource/ directory, which no include scans, so
            // editing or adding one must invalidate the compiled output explicitly.
            'watch' => [
                'app/RSpade/Core/Files/resource/icons/outline',
            ],
            'npm' => [
                'DOMPurify' => "import DOMPurify from 'dompurify'",
                'sha1' => "import sha1 from 'js-sha1'",
            ],
        ];
    }
}
