/**
 * Error_Screens - what a terminal SPA outcome RENDERS (client side)
 *
 * The JS twin of App\RSpade\Core\Errors\Error_Screens. Same three outcomes, same
 * names, rendered into the ACTIVE layout's content area instead of as a new page:
 *
 *     Error_Screens.unauthorized()   // an @auth gate denied the target action
 *     Error_Screens.not_found()      // no action matches the URL
 *     Error_Screens.fatal(error)     // the action failed to boot or render
 *
 * No navigation, no server round trip: the layout, nav and session chrome stay
 * alive around the error body, so the user is still inside the application.
 *
 * THE BODIES ARE THE BUNDLE'S OWN COMPONENTS. This class only resolves the target
 * container and mounts whichever component the running bundle REGISTERED for the
 * outcome - once, from a static on_app_modules_define():
 *
 *     class My_Error_Screens {
 *         static on_app_modules_define() {
 *             Error_Screens.set_components({
 *                 unauthorized: 'Unauthorized_Error_Page_Component',
 *                 not_found: 'Not_Found_Error_Page_Component',
 *                 fatal: 'Generic_Error_Page_Component',
 *             });
 *         }
 *     }
 *
 * A registry rather than fixed names because more than one application runs on
 * the SPA - the template app's theme ships one set, the framework's own /_sys
 * panel ships another, and neither bundle can see the other's components. There
 * is no default: a bundle that never registered fails LOUD the first time it
 * needs an error screen, naming this call.
 *
 * Each component receives the outcome's arguments below; it may ignore any of them.
 *
 * See: rsx:man error_pages (SPA ERROR SCREENS)
 */
class Error_Screens {
    /**
     * {unauthorized, not_found, fatal} -> component name, or null until a bundle
     * registers its set.
     * @private
     */
    static _components = null;

    /**
     * Register the running bundle's three error-screen components
     *
     * Called once per bundle, from a static on_app_modules_define(). All three keys
     * are required; a later call replaces the whole set.
     *
     * @param {object} components
     * @param {string} components.unauthorized Component for a denied @auth gate
     * @param {string} components.not_found Component for a URL nothing claims
     * @param {string} components.fatal Component for a boot/render failure
     */
    static set_components(components) {
        const keys = ['unauthorized', 'not_found', 'fatal'];

        for (const key of keys) {
            if (!components || !is_string(components[key]) || components[key] === '') {
                throw new Error(
                    `[Error_Screens] set_components() requires a component name for '${key}' ` +
                    '(keys: unauthorized, not_found, fatal).'
                );
            }
        }

        for (const key of Object.keys(components)) {
            if (!keys.includes(key)) {
                throw new Error(`[Error_Screens] set_components() got unknown key '${key}' (keys: unauthorized, not_found, fatal).`);
            }
        }

        Error_Screens._components = {
            unauthorized: components.unauthorized,
            not_found: components.not_found,
            fatal: components.fatal,
        };
    }

    /**
     * The registered set, or null when this bundle registered none.
     *
     * @returns {object|null}
     */
    static get_components() {
        return Error_Screens._components;
    }

    /**
     * Render the unauthorized body for a denied surface
     *
     * @param {object} options
     * @param {string} [options.message] Sentence shown to the user
     * @param {string} [options.section] What was being accessed (e.g. "Billing")
     * @returns {object} The mounted component instance
     */
    static unauthorized(options = {}) {
        return Error_Screens._render('unauthorized', {
            message: options.message || 'You do not have permission to view this page',
            section: options.section || '',
        });
    }

    /**
     * Render the not-found body for a URL nothing claims
     *
     * @param {object} options
     * @param {string} [options.record_type] What is missing (default "Page")
     * @param {string} [options.back_label] Label for the escape link
     * @param {string} [options.back_url] Target of the escape link
     * @returns {object} The mounted component instance
     */
    static not_found(options = {}) {
        return Error_Screens._render('not_found', {
            record_type: options.record_type || 'Page',
            back_label: options.back_label || 'Return to home',
            back_url: options.back_url || '/',
        });
    }

    /**
     * Render the fatal body for a boot/render failure
     *
     * The error object itself is NOT rendered - it goes to the console, where a
     * developer has the stack, and the page shows the message only. An error page
     * is the one surface a user always sees; it must not become a debug dump.
     *
     * @param {Error|string} error The failure
     * @returns {object} The mounted component instance
     */
    static fatal(error) {
        console.error('[Error_Screens] Fatal:', error);

        const message = is_string(error)
            ? error
            : (error && error.message ? error.message : 'An unexpected error occurred.');

        return Error_Screens._render('fatal', {
            error_data: message,
        });
    }

    /**
     * Mount the registered error component for an outcome in the active content area
     *
     * Tears down the mounted action first: a denied or failed action must not be
     * left running behind the error body. The container itself is preserved (it is
     * the layout's $sid="content" element, found by id on the next navigation) -
     * the body is mounted on a child of it, exactly like ordinary page content.
     *
     * @param {string} outcome 'unauthorized', 'not_found' or 'fatal'
     * @param {object} args Component arguments
     * @returns {object} The mounted component instance
     */
    static _render(outcome, args) {
        if (!Error_Screens._components) {
            throw new Error(
                `[Error_Screens] No error screen components are registered, so the '${outcome}' ` +
                'screen cannot render. Register this bundle\'s set once, from a static ' +
                'on_app_modules_define(): Error_Screens.set_components({unauthorized, not_found, fatal}).'
            );
        }

        const component_name = Error_Screens._components[outcome];

        if (!jqhtml.get_registered_templates().includes(component_name)) {
            throw new Error(
                `[Error_Screens] The registered '${outcome}' component '${component_name}' is not ` +
                'in this bundle. Restore it, or register the component this bundle provides ' +
                'with Error_Screens.set_components().'
            );
        }

        const current_action = Spa.action();
        if (current_action) {
            current_action.stop();
        }
        Spa._action = null;

        const layouts = Spa._collect_all_layouts();
        const deepest_layout = layouts.length ? layouts[layouts.length - 1] : null;

        // #spa-root is the initial-boot case: the bootstrap page has rendered, but
        // no layout has been mounted yet (a first-load denial).
        const $target = deepest_layout ? deepest_layout.$sid('content') : $('#spa-root');
        if (!$target || !$target.length) {
            throw new Error('[Error_Screens] No layout content area and no #spa-root to render into');
        }

        $target.empty();
        Spa._clear_container_attributes($target);

        const $host = $('<div>');
        $target.append($host);
        $host.component(component_name, args);

        console_debug('Spa', `Error_Screens rendered ${component_name}`);

        return $host.component();
    }
}
