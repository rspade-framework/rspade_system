/**
 * _Sys_Companion_Actions - see the .jqhtml.
 */
class _Sys_Companion_Actions extends Component {
    /** Each verb's button and its confirmation. */
    static VERBS = {
        pause: {
            label: 'Pause',
            tone: 'secondary',
            question: 'The companion is asked to pause its work through the SDK. The process keeps running, and Resume continues it.',
        },
        resume: {
            label: 'Resume',
            tone: 'primary',
            question: 'The companion is asked to continue its work.',
        },
        stop: {
            label: 'Graceful stop',
            tone: 'warning',
            question: 'The companion is asked to finish its unit of work and exit, and is killed if it outlasts its grace. It stays stopped until Start or Restart; its desired state on the command line is unchanged.',
        },
        start: {
            label: 'Start',
            tone: 'primary',
            question: 'The hold from a graceful stop is released, and the companion starts when it is ready.',
        },
        restart: {
            label: 'Restart',
            tone: 'secondary',
            question: 'The companion is stopped gracefully and started again at once.',
        },
    };

    /**
     * The verbs that apply to a companion now, in display order, filtered by what the viewer
     * may do (status.can).
     *
     * @param {object} c A status from Rsx_Companion
     * @returns {string[]}
     */
    static applicable(c) {
        const verbs = [];
        if (c.status === 'running' && c.is_live) verbs.push('pause');
        if (c.status === 'paused') verbs.push('resume');
        if (c.desired === 'running' && !c.held && !c.maintenance && ['running', 'paused', 'starting', 'waiting', 'crashed'].includes(c.status)) verbs.push('stop');
        if (c.held && !c.maintenance) verbs.push('start');
        if (c.desired === 'running' && !c.maintenance && c.status !== 'unknown' && c.status !== 'stopping') verbs.push('restart');

        return verbs.filter((verb) => c.can && c.can[verb]);
    }

    /**
     * Call $callback after a verb taken from any _Sys_Companion_Actions inside $container. Call
     * it again after each repaint: a repaint builds new components.
     */
    static on_acted_within($container, callback) {
        $container.find('._Sys_Companion_Actions').each(function () {
            const $element = $(this);
            $element.component().on('acted', callback);
        });
    }

    /**
     * The whole flow of one verb: the confirmation, the endpoint, a refusal shown in a dialog.
     * Independent of any component's lifetime on purpose - a live list repaints its rows on
     * every realtime frame, and a verb the operator confirmed must not be lost because the row
     * holding its button was rebuilt meanwhile. Answers true when the daemon took the verb.
     *
     * @param {string} name
     * @param {string} action
     * @returns {Promise<boolean>}
     */
    static async perform(name, action) {
        const verb = _Sys_Companion_Actions.VERBS[action];

        if (!(await _Sys_Modal.confirm(verb.label + ' ' + name + '?', verb.question, verb.label))) {
            return false;
        }

        try {
            await _Sys_Companions_Controller.act({ name: name, companion_action: action });
        } catch (error) {
            if ([Ajax.ERROR_GENERIC, Ajax.ERROR_VALIDATION, Ajax.ERROR_NOT_FOUND].includes(error.code)) {
                await _Sys_Modal.alert('Not done', error.message);
                return false;
            }
            throw error;
        }

        return true;
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_companion_actions').on('click._sys_companion_actions', '[data-companion-action]', async function (e) {
            const $element = $(this);
            // A list row opens the detail on click; a verb button is not that click.
            e.stopPropagation();

            const action = str($element.attr('data-companion-action'));
            const done = await _Sys_Companion_Actions.perform(that.args.companion.name, action);

            if (done && !that.is_stopped()) {
                that.trigger('acted', { action: action });
            }
        });
    }
}
