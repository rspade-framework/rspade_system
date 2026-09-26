/**
 * _Sys_Error_Screens
 *
 * Registers the panel's own error bodies as the SPA's error screens. The panel's
 * bundle carries none of the application's theme, so without this a denied gate,
 * an unclaimed URL or a failed action inside /_sys would have nothing to render
 * (Error_Screens fails loud when a bundle registered no set).
 */
class _Sys_Error_Screens {
    static on_app_modules_define() {
        Error_Screens.set_components({
            unauthorized: '_Sys_Unauthorized',
            not_found: '_Sys_Not_Found',
            fatal: '_Sys_Error',
        });
    }
}
