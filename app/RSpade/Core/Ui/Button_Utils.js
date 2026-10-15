/**
 * Button_Utils - Busy-state helper for buttons and other clickable elements
 *
 * Puts an element into a "submitting" state: locks its current rendered size,
 * swaps its inner content for a CSS-animated loader, and blocks interaction via
 * the `btn--submitting` class (pointer-events: none). Restores the original
 * content and size when cleared.
 *
 * Deliberately does NOT set the `disabled` attribute. Disabling a button
 * changes form-submission semantics and native styling; interaction is blocked
 * purely through pointer-events on the `btn--submitting` class instead.
 *
 * THE LOADER is what is drawn inside the element while it is busy. The
 * framework's is a CSS spinner (.Button_Utils__spinner), styled in
 * Button_Utils.scss and themeable via CSS custom properties
 * (--btn-submitting-loader-color/-size/-thickness/-speed). An application with a
 * busy-state look of its own registers it once, from a static on_app_init():
 *
 *   Button_Utils.set_loader(($el) => '<span class="btn__loader">...</span>');
 *
 * The renderer receives the element entering the busy state and returns the markup
 * placed inside it (a string or a jQuery). It is read at set_submitting() time, so
 * one registration applies to every caller - $.fn.click_async(), Rsx_Form,
 * Modal.form() and direct calls alike. set_loader(null) restores the framework
 * spinner. The renderer decides only what is drawn: the size lock, the
 * btn--submitting class, the pointer-events block, stashing and restoring the
 * original content and idempotence are unchanged.
 *
 * Primary consumer: $.fn.click_async() (see Rsx_Jq_Helpers.js), which wraps a
 * click handler in an automatic set/clear cycle. It can also be driven directly.
 * Rsx_Form drives it too, for the submitting treatment on a form's buttons.
 *
 * IT LIVES IN Core/Ui BECAUSE TWO CORE APIS DEPEND ON IT. Both of those consumers
 * are in every bundle, so their dependency must be as well - a core API whose
 * engine sat in an optional tier is a `Button_Utils is not defined` waiting to
 * happen on any bundle that did not happen to pull that tier in. Core/Ui rather
 * than Core/Js because the class ships styles: the .js and the .scss are one
 * related set and stay colocated, and Core/Js holds no SCSS at all.
 *
 * Usage:
 *   Button_Utils.set_submitting($btn);      // returns $btn, for chaining
 *   Button_Utils.clear_submitting($btn);    // returns $btn, for chaining
 *   if (Button_Utils.is_submitting($btn)) { ... }
 *   Button_Utils.set_loader(renderer);      // or null for the framework spinner
 */
class Button_Utils {
    // State marker class - also carries the pointer-events block (see .scss)
    static SUBMITTING_CLASS = 'btn--submitting';

    // jQuery .data() keys used to stash original state during a busy cycle.
    // Stored in jQuery's internal cache (not written to the DOM), so an object
    // value is safe and there are no serialization concerns.
    static _DATA_HTML = 'button_utils_original_html';
    static _DATA_STYLE = 'button_utils_original_style';

    // The registered loader renderer, or null for the framework spinner.
    static _loader = null;

    /**
     * Register the loader drawn inside every element that subsequently enters the
     * busy state. renderer($el) returns the markup - a string or a jQuery - placed
     * inside the element; null restores the framework spinner. Anything else throws.
     */
    static set_loader(renderer) {
        if (renderer !== null && typeof renderer !== 'function') {
            throw new Error('Button_Utils.set_loader() takes a function ($el) => markup, or null for the framework spinner - got ' + typeof renderer);
        }
        Button_Utils._loader = renderer;
    }

    /**
     * The markup drawn inside $btn for this busy cycle: the registered renderer's,
     * or the framework spinner when none is registered.
     */
    static _render_loader($btn) {
        if (Button_Utils._loader === null) {
            return '<span class="Button_Utils__spinner" aria-hidden="true"></span>';
        }

        const markup = Button_Utils._loader($btn);
        if (typeof markup !== 'string' && !(markup instanceof jQuery)) {
            throw new Error('Button_Utils loader renderer must return a string or a jQuery - got ' + (markup === null ? 'null' : typeof markup));
        }
        return markup;
    }

    /**
     * Returns true if the (first matched) element is currently submitting.
     */
    static is_submitting($el) {
        return $el.first().hasClass(Button_Utils.SUBMITTING_CLASS);
    }

    /**
     * Enter the busy state: lock the rendered size, stash inner HTML, show the
     * loader (the registered renderer's markup, or the framework spinner).
     * Idempotent - a no-op on an element already submitting. Returns $el.
     */
    static set_submitting($el) {
        $el.each((index, el) => {
            const $btn = $(el);
            if ($btn.hasClass(Button_Utils.SUBMITTING_CLASS)) {
                return; // already submitting - idempotent no-op
            }

            // Rendered first, while the element is untouched: a renderer that throws
            // leaves nothing half-applied, and one that reads the element sees it as is.
            const loader = Button_Utils._render_loader($btn);

            // Lock the current rendered box so swapping content cannot reflow it.
            // getBoundingClientRect() reports the border-box size; pinning
            // box-sizing to border-box makes the inline width/height reproduce
            // that box exactly regardless of the element's own box-sizing.
            const rect = el.getBoundingClientRect();

            // Stash the original inline size props so clear can restore them
            // exactly - an element may already carry its own inline width/height.
            $btn.data(Button_Utils._DATA_STYLE, {
                width: el.style.width,
                height: el.style.height,
                box_sizing: el.style.boxSizing,
            });

            // Stash the original content
            $btn.data(Button_Utils._DATA_HTML, $btn.html());

            el.style.width = rect.width + 'px';
            el.style.height = rect.height + 'px';
            el.style.boxSizing = 'border-box';

            $btn.addClass(Button_Utils.SUBMITTING_CLASS);
            $btn.empty().append(loader);
        });
        return $el;
    }

    /**
     * Leave the busy state: restore inner HTML, remove the class, unlock the
     * size. No-op when the element is not submitting. Returns $el.
     */
    static clear_submitting($el) {
        $el.each((index, el) => {
            const $btn = $(el);
            if (!$btn.hasClass(Button_Utils.SUBMITTING_CLASS)) {
                return; // not submitting - nothing to restore
            }

            const original_html = $btn.data(Button_Utils._DATA_HTML);
            const original_style = $btn.data(Button_Utils._DATA_STYLE) || {};

            $btn.removeClass(Button_Utils.SUBMITTING_CLASS);

            if (typeof original_html === 'string') {
                $btn.html(original_html);
            }

            // Restore original inline size props (empty string removes the prop)
            el.style.width = original_style.width || '';
            el.style.height = original_style.height || '';
            el.style.boxSizing = original_style.box_sizing || '';

            $btn.removeData(Button_Utils._DATA_HTML);
            $btn.removeData(Button_Utils._DATA_STYLE);
        });
        return $el;
    }
}
