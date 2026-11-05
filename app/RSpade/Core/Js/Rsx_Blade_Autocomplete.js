/**
 * Rsx_Blade_Autocomplete - the autocomplete="off" default for fields a Blade template wrote
 *
 * jqhtml renders every template-written <input> and <textarea> that declares no
 * autocomplete token as autocomplete="off" (the autocomplete_off option, set in
 * Jqhtml_Integration). This class applies the same policy to a server-rendered Blade
 * page: at boot, every <input> and <textarea> in the document with no autocomplete
 * attribute is given autocomplete="off", so a business form is not filled with
 * suggestions the browser saved from some other field.
 *
 * A field that SHOULD autofill states its token in the Blade markup
 * (autocomplete="username", "current-password", "new-password", "one-time-code",
 * "email", ...). Only that attribute exempts a field - an id or a name does not.
 *
 * Scope:
 * - Blade pages only. An SPA page (window.rsxapp.is_spa) renders its fields through
 *   jqhtml, which applies the policy itself.
 * - Runs once, in _on_framework_core_init: Blade-written fields are already in the
 *   document, and no component or application code has run yet. Markup inserted later
 *   with raw jQuery is the inserting code's responsibility; markup rendered by a jqhtml
 *   component is covered by jqhtml.
 *
 * rsx:man jqhtml, AUTOCOMPLETE AND AUTOFILL.
 */
class Rsx_Blade_Autocomplete {
    static _on_framework_core_init() {
        if (Rsx.is_ssr()) return;
        if (window.rsxapp.is_spa) return;

        $('input:not([autocomplete]), textarea:not([autocomplete])').attr('autocomplete', 'off');
    }
}
