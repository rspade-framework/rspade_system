/**
 * Spinner_Overlay - hide a component behind the application's spinner, and reveal it
 *
 *     Spinner_Overlay.show(component);   // the component is hidden, the spinner shows
 *     Spinner_Overlay.hide(component);   // the spinner goes, the component appears
 *
 * The one implementation behind Spa_Action.show_spinner() / hide_spinner(), the
 * @spinner_until_ready decorator, and any application chrome (a dialog) that hosts a
 * component it wants to reveal whole.
 *
 * The spinner is the component registered with Rsx.set_default_spinner(), mounted in an
 * element inserted as the component's previous SIBLING. A sibling, because a component's
 * every render replaces what is inside its element - an overlay inside it would be wiped
 * by the next render, while a sibling survives any number of them. The sibling selector in
 * spinner_overlay.scss hides the component with visibility (never display, so the tree
 * still lays out and measures while it loads), so removing the sibling is the whole reveal.
 *
 * Placement: centered horizontally over the component, and vertically in the part of it
 * that is on screen - from the component's top edge to its bottom edge or the viewport's
 * bottom edge, whichever is higher - so a hidden page taller than the window still gets its
 * spinner in view. Measured once, when show() is called; a resize does not move it.
 */
class Spinner_Overlay {
    /** Every component an overlay is currently shown for. */
    static _shown = new Set();

    /**
     * Hide the component behind the spinner. Showing an overlay already shown re-measures
     * its placement and changes nothing else.
     *
     * @param {Component} component
     */
    static show(component) {
        let $overlay = component._spinner_overlay_$;
        if (!$overlay) {
            $overlay = $('<div class="Spinner_Overlay"><div class="Spinner_Overlay__stage"></div></div>');
            $overlay.insertBefore(component.$);
            $overlay.find('.Spinner_Overlay__stage').component(Rsx.get_default_spinner());
            component._spinner_overlay_$ = $overlay;
            Spinner_Overlay._shown.add(component);
        }
        Spinner_Overlay._place($overlay, component.$);
    }

    /**
     * Reveal the component. A component with no overlay shown is left as it is.
     *
     * @param {Component} component
     */
    static hide(component) {
        const $overlay = component._spinner_overlay_$;
        if (!$overlay) return;
        $overlay.remove();
        component._spinner_overlay_$ = null;
        Spinner_Overlay._shown.delete(component);
    }

    /**
     * @param {Component} component
     * @returns {boolean}
     */
    static is_shown(component) {
        return !!component._spinner_overlay_$;
    }

    /**
     * Reveal everything. Called for an unhandled exception from the page's code: what was
     * waiting for ready may now never reach it, and an error is never left behind a
     * spinner nobody can see past.
     */
    static hide_all() {
        for (const component of [...Spinner_Overlay._shown]) {
            Spinner_Overlay.hide(component);
        }
    }

    /**
     * Size the stage to the visible part of the component: its top edge to the lower of
     * its bottom edge and the viewport's, relative to the overlay element, which sits at
     * the component's top.
     */
    static _place($overlay, $target) {
        const target = $target[0].getBoundingClientRect();
        const origin = $overlay[0].getBoundingClientRect();
        const top = Math.max(target.top, 0);
        const bottom = Math.min(target.bottom, window.innerHeight);
        $overlay.find('.Spinner_Overlay__stage').css({
            top: (top - origin.top) + 'px',
            height: Math.max(bottom - top, 0) + 'px',
        });
    }
}
