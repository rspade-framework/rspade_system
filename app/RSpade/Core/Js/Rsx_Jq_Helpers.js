// @JS-THIS-01-EXCEPTION
/**
 * jQuery helper extensions for the RSX framework
 * These extensions add utility methods to jQuery's prototype
 * Note: 'this' references in jQuery extensions refer to jQuery objects by design
 */
class Rsx_Jq_Helpers {
    /**
     * Initialize jQuery extensions when the framework core is defined
     * This method is called during framework initialization
     */
    static _on_framework_core_define() {
        // Returns true if jquery selector matched an element
        $.fn.exists = function () {
            return this.length > 0;
        };

        // Returns true if jquery element is visible
        $.fn.is_visible = function () {
            return this.is(':visible');
        };

        // Scrolls to the target element, only scrolls up.  Todo: Create a version
        // of this that also scrolls only down, or both
        $.fn.scroll_up_to = function (speed = 0) {
            if (!this.exists()) {
                // console.warn("Could not find target element to scroll to");
                return;
            }

            if (!this.is_in_dom()) {
                // console.warn("Target element for scroll is not on dom");
                return;
            }

            let e_top = Math.round(this.offset().top);
            let s_top = $('body').scrollTop();
            if (e_top < 0) {
                let target = s_top + e_top;
                $('html, body').animate(
                    {
                        scrollTop: target,
                    },
                    speed
                );
            }
        };

        // $().is(":focus") - check if element has focus
        $.expr[':'].focus = function (elem) {
            return elem === document.activeElement && (elem.type || elem.href);
        };

        // Save native click behavior before override
        $.fn._click_native = $.fn.click;

        // Override .click() to call preventDefault by default
        // This prevents accidental page navigation/form submission - the correct behavior 95% of the time
        $.fn.click = function (handler) {
            // If no handler provided, trigger click event (jQuery .click() with no args)
            if (typeof handler === 'undefined') {
                return this._click_native();
            }

            // Attach click handler with automatic preventDefault
            return this.on('click', function (e) {
                // Save original preventDefault
                const original_preventDefault = e.preventDefault.bind(e);

                // Override preventDefault to show warning when called explicitly
                e.preventDefault = function() {
                    console.warn('event.preventDefault() is called automatically by RSpade .click() handlers and can be removed.');
                    return original_preventDefault();
                };

                // Call preventDefault before handler
                original_preventDefault();

                return handler.call(this, e);
            });
        };

        // Escape hatch: click handler without preventDefault for the 5% case
        $.fn.click_allow_default = function (handler) {
            if (typeof handler === 'undefined') {
                return this._click_native();
            }
            return this._click_native(handler);
        };

        // Async click handler with automatic button busy-state.
        //
        // Wraps the handler in a Button_Utils submitting cycle: on click the
        // element enters the busy state (rendered size locked, content swapped
        // for a loader, interaction blocked via pointer-events), the handler
        // runs, and the busy state clears when the returned promise settles.
        //
        // - Throws immediately if handler is not a function (fail loud).
        // - Auto preventDefault, consistent with .click().
        // - Re-entrancy guard: a click while already submitting is ignored. This
        //   covers programmatic .trigger('click') and double-taps that slip past
        //   pointer-events, which does not block synthetic click events.
        // - On settle, clears the busy state ONLY if the element is still in the
        //   DOM. A handler that navigates the SPA away detaches the button before
        //   its promise resolves; touching a detached node then is pointless, so
        //   the is_in_dom() guard skips it (no error).
        // - The handler's rejection is intentionally NOT caught. .finally()
        //   propagates it unchanged, so an error escapes as an unhandled
        //   rejection and reaches the global handler (Rsx._handle_unhandled_exception
        //   -> console + flash), exactly like any other uncaught async error.
        //   No swallow, no rethrow theater.
        $.fn.click_async = function (handler) {
            if (typeof handler !== 'function') {
                throw new Error('$.fn.click_async() requires a function handler');
            }

            return this.on('click', function (e) {
                e.preventDefault();

                const $el = $(this);

                // Re-entrancy guard - ignore clicks while a run is in flight
                if (Button_Utils.is_submitting($el)) {
                    return;
                }

                Button_Utils.set_submitting($el);

                // Run the handler and clear the busy state once it settles. The
                // chain is left unhandled on purpose so a rejection surfaces
                // globally rather than being swallowed here.
                Promise.resolve(handler.call(this, e)).finally(function () {
                    if ($el.is_in_dom()) {
                        Button_Utils.clear_submitting($el);
                    }
                });
            });
        };

        // Returns true if the jquery element exists in and is attached to the DOM
        $.fn.is_in_dom = function () {
            let $element = this;
            let _ancestor = function (HTMLobj) {
                while (HTMLobj.parentElement) {
                    HTMLobj = HTMLobj.parentElement;
                }
                return HTMLobj;
            };
            return _ancestor($element[0]) === document.documentElement;
        };

        // Returns true if the element is visible in the viewport
        $.fn.is_in_viewport = function () {
            let scrolltop = $(window).scrollTop() > 0 ? $(window).scrollTop() : $('body').scrollTop();

            let $element = this;

            const top_of_element = $element.offset().top;
            const bottom_of_element = $element.offset().top + $element.outerHeight();
            const bottom_of_screen = scrolltop + $(window).innerHeight();
            const top_of_screen = scrolltop;

            if (bottom_of_screen > top_of_element && top_of_screen < bottom_of_element) {
                return true;
            } else {
                return false;
            }
        };

        // Gets the tagname of a jquery element
        $.fn.tagname = function () {
            return this.prop('tagName').toLowerCase();
        };

        // Returns true if a href is not same domain
        $.fn.is_external = function () {
            const host = window.location.host;
            const link = $('<a>', {
                href: this.attr('href'),
            })[0].hostname;
            return link !== host;
        };

        // HTML5 form validation wrappers
        $.fn.checkValidity = function () {
            if (this.length === 0) return false;
            return this[0].checkValidity();
        };

        $.fn.reportValidity = function () {
            if (this.length === 0) return false;
            return this[0].reportValidity();
        };

        $.fn.requestSubmit = function () {
            if (this.length === 0) return this;
            this[0].requestSubmit();
            return this;
        };

        // Find related components by searching up the ancestor tree
        // Like .closest() but searches within ancestors instead of matching them
        $.fn.closest_sibling = function (selector) {
            let $current = this;
            let $parent = $current.parent();

            // Keep going up the tree until we hit body
            while ($parent.length > 0 && !$parent.is('body')) {
                // Search within this parent for the selector
                let $found = $parent.find(selector);
                if ($found.length > 0) {
                    return $found;
                }

                // Move up one level
                $parent = $parent.parent();
            }

            // If we reached body, search within body as well
            if ($parent.is('body')) {
                let $found = $parent.find(selector);
                if ($found.length > 0) {
                    return $found;
                }
            }

            // Return empty jQuery object if nothing found
            return $();
        };

        // The numeric field filter. $.fn.rsx_numeric() and the pure helpers
        // Rsx_Jq_Helpers._numeric_read() / ._numeric_value() / ._numeric_format() /
        // ._numeric_time_to_decimal() are its whole implementation, plus the valHook
        // installed below it.
        /**
         * Turn an <input type="text"> into a numeric field.
         *
         *     $input.rsx_numeric({decimals: 2, commas: true, prefix: '$'});
         *     $input.rsx_numeric(false);   // remove the filter
         *
         * | Option     | Default | Meaning                                                      |
         * |------------|---------|--------------------------------------------------------------|
         * | `decimals` | `0`     | Maximum decimal places. `0` accepts integers only - `.` is    |
         * |            |         | rejected like any other non-digit; a longer fraction is       |
         * |            |         | truncated, never rounded.                                     |
         * | `commas`   | `false` | Show thousands separators.                                    |
         * | `prefix`   | `''`    | A display-only prefix such as `'$'`.                          |
         * | `time`     | `false` | Hours, with minutes accepted after a colon. Forces `decimals` |
         * |            |         | to 2; `commas` and `prefix` apply as usual.                   |
         *
         * TIME ENTRY. With `time: true` the field holds HOURS, and a colon is accepted so
         * that a duration may be typed the way it is read off a clock. The accepted
         * characters are digits plus at most one `.` OR at most one `:` - the two are
         * mutually exclusive in one value, and whichever is typed first wins, so `1.5:2`
         * becomes `1.52` and `1:3.` stays `1:3`. At most two digits follow either one.
         *
         * A colon value is hours:minutes and is converted to decimal hours:
         *
         *     1:30  -> 1.5        :30 and 0:30 -> 0.5      2:15 -> 2.25
         *     1:20  -> 1.33       1:   -> 1                1:5  -> 1.08
         *     :90   -> 1.5        1:63 -> 2.05
         *
         * A single digit after the colon is minutes as written, so `1:5` is 1:05 and not
         * 1:50. Minutes at or past 60 roll into hours. The result is rounded to two
         * decimals, half up, and trailing zeros are not padded - `1.5`, never `1.50`, and
         * a whole number of hours shows as `2`.
         *
         * The conversion happens at the three moments the value leaves the user's hands:
         * `.val()` answers decimal hours and never a colon, `.val('2:15')` displays `2.25`,
         * and blur rewrites the box from `1:30` to `1.5`. While the field is FOCUSED a
         * colon value is left exactly as typed, so it can still be edited. A plain decimal
         * typed in time mode is already decimal hours and is left alone.
         *
         * WHAT .val() SEES IS THE RAW NUMBER, BOTH WAYS. The getter answers digits with
         * at most one `.` - no commas, no prefix, `''` for an empty box - and the setter
         * takes a number or a string, sanitises it and displays it formatted. That is a
         * `text` entry in `$.valHooks`, applied only to elements carrying this plugin's
         * data and deferring to whatever hook was there before for every other element.
         * So `Form_Input_Abstract` subclasses reading `this.$sid('input').val()`, and any
         * plain `$(el).val()`, get the number without knowing the filter exists.
         *
         * FORMATTING IS INLINE, as the user types. With the caret at the end the box is
         * rewritten formatted on every keystroke; editing mid-string writes the cleaned
         * unformatted number and keeps the caret where it was, because re-applying
         * separators under the caret would move it; blur reformats the whole value; focus
         * selects everything, so the next keystroke replaces it. Backspace at the end over
         * a separator deletes the digit before it rather than a character that is only
         * there to be read. The box looks the same focused and blurred.
         *
         * Filtering is all it does - it never validates (that is the server's), never
         * calls `_notify_input()`, and never fires an `input` event of its own: the
         * browser already fired one for the keystroke that reached it.
         *
         * Calling it again reconfigures: the previous handlers come off first, so the last
         * options win and there is only ever one set. `rsx_numeric(false)` takes the
         * handlers off and leaves the raw number in the box.
         *
         * `Currency_Input` is this filter with `commas` and a `prefix`, and
         * `Time_Entry_Input` is it with `time`; it is what every numeric input invokes, so
         * no component filters digits by hand.
         *
         * @param {Object|false} options
         * @returns {jQuery}
         */
        $.fn.rsx_numeric = function (options) {
            if (options === false) {
                return this.each(function () {
                    const $input = $(this);
                    const state = $.data(this, 'rsx_numeric');
                    if (!state) {
                        return;
                    }

                    // Read the number out while the state still describes the display.
                    const numeric = Rsx_Jq_Helpers._numeric_value(this.value, state);
                    $input.off('.rsx_numeric');
                    $.removeData(this, 'rsx_numeric');
                    this.value = numeric;
                });
            }

            const settings = options || {};

            return this.each(function () {
                const $input = $(this);
                const element = this;

                const time = settings.time === true;

                const state = {
                    time: time,

                    // Time entry is two decimal places by definition - a minute is a
                    // hundredth of an hour to the nearest hundredth - so the option is
                    // not the caller's to set there.
                    decimals: time ? 2 : (settings.decimals === undefined ? 0 : int(settings.decimals)),
                    commas: settings.commas === true,
                    prefix: settings.prefix === undefined ? '' : str(settings.prefix),

                    // Raised by focus and consumed by the mouseup that completes the
                    // focusing click - see the focus handlers below.
                    select_on_mouseup: false,
                };

                // Reconfiguration: handlers from an earlier call are unbound first, so two
                // calls leave one set of handlers rather than two.
                $input.off('.rsx_numeric');
                $.data(element, 'rsx_numeric', state);

                // Whatever the box already holds is now displayed under these options.
                element.value = Rsx_Jq_Helpers._numeric_format(
                    Rsx_Jq_Helpers._numeric_value(element.value, state),
                    state
                );

                $input.on('keydown.rsx_numeric', function (e) {
                    if (e.key !== 'Backspace') {
                        return;
                    }

                    const caret = element.selectionStart;
                    if (caret !== element.selectionEnd || caret !== element.value.length || caret === 0) {
                        return;
                    }
                    if (/[0-9]/.test(element.value.charAt(caret - 1))) {
                        return;
                    }

                    // The last character is one the filter wrote, not one the user typed.
                    // Deleting it would only see it written again by the reformat, so the
                    // digit in front of it goes instead.
                    e.preventDefault();

                    const numeric = Rsx_Jq_Helpers._numeric_read(element.value, state);
                    if (!numeric.length) {
                        return;
                    }

                    element.value = Rsx_Jq_Helpers._numeric_format(numeric.slice(0, -1), state);
                    const end = element.value.length;
                    element.setSelectionRange(end, end);
                });

                $input.on('input.rsx_numeric', function () {
                    const raw = element.value;
                    const caret = element.selectionStart;
                    const numeric = Rsx_Jq_Helpers._numeric_read(raw, state);

                    if (caret === raw.length) {
                        const display = Rsx_Jq_Helpers._numeric_format(numeric, state);
                        if (display !== raw) {
                            element.value = display;
                            const end = display.length;
                            element.setSelectionRange(end, end);
                        }
                        return;
                    }

                    // The caret is inside the value: only the rejected characters come
                    // out, and the caret keeps its place relative to what is left.
                    // Separators are not re-applied here because inserting one ahead of
                    // the caret moves the caret off the digit the user is editing; the
                    // next keystroke at the end, or the blur, formats the whole value.
                    const cleaned = state.prefix + numeric;
                    if (cleaned !== raw) {
                        element.value = cleaned;
                        const position = Math.min(caret, cleaned.length);
                        element.setSelectionRange(position, position);
                    }
                });

                // Blur is where a time value stops being something the user is typing and
                // becomes the number the field holds, so the box is rewritten in decimal
                // hours here rather than while the caret is still in it.
                $input.on('blur.rsx_numeric', function () {
                    state.select_on_mouseup = false;
                    element.value = Rsx_Jq_Helpers._numeric_format(
                        Rsx_Jq_Helpers._numeric_value(element.value, state),
                        state
                    );
                });

                // Select-all on focus, in two halves. A keyboard focus is done here; a
                // mouse focus is not, because the click that raised it has not finished -
                // its default action still has a caret to place, and it lands after this
                // handler returns and collapses the selection. The mouseup that ends the
                // click is where that is undone, and only when the user did not drag a
                // selection of their own.
                $input.on('focus.rsx_numeric', function () {
                    state.select_on_mouseup = true;
                    element.select();
                });

                $input.on('mouseup.rsx_numeric', function () {
                    if (!state.select_on_mouseup) {
                        return;
                    }
                    state.select_on_mouseup = false;

                    if (element.selectionStart === element.selectionEnd) {
                        element.select();
                    }
                });
            });
        };

        // The raw-value contract. jQuery consults $.valHooks[elem.type] before reading or
        // writing a value, and 'text' is the type of every input this plugin is applied
        // to. Returning undefined hands the element back to jQuery, so an input without
        // the plugin's data behaves exactly as it did. jQuery ships no hook of its own
        // for 'text', and the framework is the one owner of this slot: a hook already
        // present here is a collision, not something to chain behind.
        if ($.valHooks.text !== undefined) {
            throw new Error('Rsx_Jq_Helpers: $.valHooks.text is already defined; rsx_numeric() owns the text value hook.');
        }
        $.valHooks.text = {
            get: function (elem) {
                const state = $.data(elem, 'rsx_numeric');
                if (state) {
                    return Rsx_Jq_Helpers._numeric_value(elem.value, state);
                }
                return undefined;
            },
            set: function (elem, value) {
                const state = $.data(elem, 'rsx_numeric');
                if (state) {
                    elem.value = Rsx_Jq_Helpers._numeric_format(
                        Rsx_Jq_Helpers._numeric_value(value, state),
                        state
                    );
                    return elem.value;
                }
                return undefined;
            },
        };

        // Attach the CSRF token header to a settings object bound for the local
        // server, without clobbering any caller-supplied header. window.rsxapp.csrf
        // is populated (session-gated) at page render; when absent (anonymous
        // page) nothing is attached. Applied only to framework-local transports
        // (never to external cross-domain requests, which must not leak the token).
        const attach_csrf_header = function (settings) {
            if (typeof window !== 'undefined' && window.rsxapp && window.rsxapp.csrf) {
                settings.headers = settings.headers || {};
                if (!('X-CSRF-Token' in settings.headers)) {
                    settings.headers['X-CSRF-Token'] = window.rsxapp.csrf;
                }
            }
            return settings;
        };

        // Override $.ajax to prevent direct AJAX calls to local server
        // Developers must use the Ajax endpoint pattern: await Controller.method(params)
        const native_ajax = $.ajax;
        $.ajax = function (url, options) {
            // Handle both $.ajax(url, options) and $.ajax(options) signatures
            let settings;
            if (typeof url === 'string') {
                settings = options || {};
                settings.url = url;
            } else {
                settings = url || {};
            }

            // Check if this is a local request (relative URL or same domain)
            const request_url = settings.url || '';
            const is_relative = !request_url.match(/^https?:\/\//);
            const is_same_domain = request_url.startsWith(window.location.origin);
            const is_local_request = is_relative || is_same_domain;

            // Allow framework Ajax.call() to function (direct + batch transports).
            // Attach the CSRF token header here - the single chokepoint that covers
            // every Ajax.js call at once.
            if (settings.__local_integration === true) {
                return native_ajax.call(this, attach_csrf_header(settings));
            }

            // Allow file upload endpoint - requires native $.ajax for FormData support.
            // Same header attach covers every /_upload multipart caller.
            const is_file_upload = request_url === '/_upload' || request_url.endsWith('/_upload');
            if (is_file_upload) {
                return native_ajax.call(this, attach_csrf_header(settings));
            }

            // Block local AJAX requests that don't use the Ajax endpoint pattern
            if (is_local_request) {
                // Try to parse controller and action from URL
                let controller_name = null;
                let action_name = null;
                const url_match = request_url.match(/\/_rsx_api\/([^\/]+)\/([^\/\?]+)/);
                if (url_match) {
                    controller_name = url_match[1];
                    action_name = url_match[2];
                }

                let error_message = 'AJAX requests to localhost via $.ajax() are prohibited.\n\n';

                if (controller_name && action_name) {
                    error_message += `Instead of:\n`;
                    error_message += `  $.ajax({url: '${request_url}', ...})\n\n`;
                    error_message += `Use:\n`;
                    error_message += `  await ${controller_name}.${action_name}(parameters)\n\n`;
                } else {
                    error_message += `Use the Ajax endpoint pattern:\n`;
                    error_message += `  await Controller_Name.action_name(parameters)\n\n`;
                }

                error_message += `The controller method must have the #[Ajax_Endpoint] attribute.`;

                shouldnt_happen(error_message);
            }

            // Allow external requests (different domain)
            return native_ajax.call(this, settings);
        };
    }

    /**
     * The raw number inside a displayed value: digits, at most one '.', and at most
     * state.decimals digits after it (truncated, never rounded). Everything else -
     * separators, the prefix, letters, a second decimal point - is dropped. With
     * state.decimals at 0 the '.' is dropped too.
     *
     * In time mode a ':' is accepted in the '.' position instead: digits plus at most one
     * of the two, whichever appears first, with at most two digits after it. This is what
     * the box holds WHILE IT IS BEING TYPED - the colon is still there. _numeric_value()
     * is what turns it into a number.
     *
     * @param {*} display
     * @param {Object} state - the options stored in $.data(el, 'rsx_numeric')
     * @returns {string}
     */
    static _numeric_read(display, state) {
        const text = str(display);

        if (state.time) {
            const accepted = text.replace(/[^0-9.:]/g, '');
            const separator = accepted.search(/[.:]/);
            if (separator < 0) {
                return accepted;
            }

            // The first separator is the one the value has; every later one of either
            // kind is dropped, which is what makes '.' and ':' mutually exclusive.
            const minutes = accepted.slice(separator + 1).replace(/[.:]/g, '').slice(0, 2);
            return accepted.slice(0, separator + 1) + minutes;
        }

        if (state.decimals <= 0) {
            return text.replace(/[^0-9]/g, '');
        }

        const digits = text.replace(/[^0-9.]/g, '');
        const point = digits.indexOf('.');
        if (point < 0) {
            return digits;
        }

        const fraction = digits.slice(point + 1).replace(/\./g, '').slice(0, state.decimals);
        return digits.slice(0, point + 1) + fraction;
    }

    /**
     * The NUMBER inside a displayed value - _numeric_read() plus, in time mode, the
     * hours:minutes conversion. This is what .val() answers, what .val(x) is sanitised
     * through, and what blur rewrites the box from; the typing handlers use
     * _numeric_read() directly, which is why a colon survives while the caret is in it.
     *
     * @param {*} display
     * @param {Object} state - the options stored in $.data(el, 'rsx_numeric')
     * @returns {string}
     */
    static _numeric_value(display, state) {
        const numeric = Rsx_Jq_Helpers._numeric_read(display, state);
        return state.time ? Rsx_Jq_Helpers._numeric_time_to_decimal(numeric) : numeric;
    }

    /**
     * Hours:minutes as decimal hours. Text with no ':' is already decimal hours and comes
     * back unchanged, so '1.5' and '' both pass straight through.
     *
     *     1:30 -> 1.5     :30 -> 0.5     2:15 -> 2.25     1:20 -> 1.33
     *     1:   -> 1       :90 -> 1.5     1:63 -> 2.05     1:5  -> 1.08
     *
     * A single digit after the colon is minutes as written, so '1:5' is 1:05. Minutes at
     * or past 60 roll into hours. The arithmetic is done in whole minutes and rounded to
     * hundredths half up, so no float fraction is ever carried; trailing zeros are not
     * padded and a whole number of hours has no fraction at all.
     *
     * @param {string} text - a value already through _numeric_read() in time mode
     * @returns {string}
     */
    static _numeric_time_to_decimal(text) {
        const colon = text.indexOf(':');
        if (colon < 0) {
            return text;
        }

        const hours = text.slice(0, colon);
        const minutes = text.slice(colon + 1);
        if (hours === '' && minutes === '') {
            return '';
        }

        const total_minutes = int(hours || '0') * 60 + int(minutes || '0');
        const hundredths = Math.round((total_minutes * 100) / 60);
        const whole = Math.floor(hundredths / 100);
        const fraction = str(hundredths % 100).padStart(2, '0').replace(/0+$/, '');

        return fraction === '' ? str(whole) : whole + '.' + fraction;
    }

    /**
     * The displayed form of a raw number: the prefix, thousands separators if the
     * options ask for them, and everything from the separator on exactly as typed - a
     * trailing '.' or ':' is kept, because the user is still typing what follows it. In
     * time mode the separator may be either one, and only the part before it is grouped.
     *
     * @param {string} numeric - a value already through _numeric_read()
     * @param {Object} state - the options stored in $.data(el, 'rsx_numeric')
     * @returns {string}
     */
    static _numeric_format(numeric, state) {
        if (numeric === '') {
            return '';
        }

        const separator = numeric.search(/[.:]/);
        let integer = separator < 0 ? numeric : numeric.slice(0, separator);

        if (state.commas) {
            integer = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        const display = state.prefix + integer;
        return separator < 0 ? display : display + numeric.slice(separator);
    }
}
