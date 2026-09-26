/**
 * _Sys_Email_View_Action - one queued email, any site's. See the .jqhtml for the layout.
 *
 * Resend asks first - and a BLOCKED row asks a different question, because resending
 * it overrides the recipient's opt-out - then calls _Sys_Email_Controller.resend (the
 * rules are Rsx_Mail::resend(), shared with rsx:mail:resend), flashes the outcome and
 * reloads the record.
 */
@route('/_sys/email/:id')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Email')
class _Sys_Email_View_Action extends Spa_Action {
    on_create() {
        this.data.email = null;
        this.data.catcher = null;
        this.data.load_error = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Email_Controller.detail({ id: this.args.id });
            this.data.email = response.email;
            this.data.catcher = response.catcher;
        } catch (e) {
            // Plain data: this.data keeps no Error object, so the code and the message
            // are copied out. A not_found code renders the missing-record state.
            if (e.code !== Ajax.ERROR_NOT_FOUND) {
                console.error(e);
            }
            this.data.load_error = { code: e.code || null, message: e.message || String(e) };
        }
        this.data.loading = false;
    }

    page_title() {
        return 'Email #' + this.args.id;
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_email_view');

        this.$.on('click._sys_email_view', '[data-action="resend"]', async function () {
            const email = that.data.email;
            let confirmed;

            if (email.resend_needs_force) {
                confirmed = await _Sys_Modal.confirm(
                    'Resend a blocked email?',
                    email.to_address + ' has unsubscribed from ' + email.category_id__label + ' email. '
                        + 'That is a consent record, not a delivery failure - resending overrides their opt-out.',
                    'Resend anyway'
                );
            } else {
                confirmed = await _Sys_Modal.confirm(
                    'Resend email #' + email.id + '?',
                    'It goes back on the queue as Pending with its attempts reset, and the drain is kicked. '
                        + 'Its subject, data and attachments are unchanged.',
                    'Resend'
                );
            }

            if (!confirmed) {
                return;
            }

            const result = await _Sys_Email_Controller.resend({ id: email.id, force: email.resend_needs_force });
            Flash_Alert.success('Email #' + result.id + ' is ' + result.status_label + ' again - the queue drain has been kicked.');
            that.reload();
        });
    }

    /**
     * "Name <address>", the address alone, or null when there is none.
     */
    static address(address, name) {
        if (!address) {
            return null;
        }

        return name ? name + ' <' + address + '>' : address;
    }

    /**
     * A CC/BCC list on one line, or null when empty. Entries are {address, name} or strings.
     */
    static address_list(list) {
        if (!Array.isArray(list) || !list.length) {
            return null;
        }

        return list
            .map((entry) => (is_object(entry) ? _Sys_Email_View_Action.address(entry.address, entry.name) : str(entry)))
            .filter((text) => text)
            .join(', ');
    }
}
