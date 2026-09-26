/**
 * _Sys_User_View_Action - one login identity. See the .jqhtml for the layout.
 *
 * Handles the identity-wide actions (the header's Sign in as this user, Change status and
 * End all sessions)
 * and a membership's Enable/Disable (the memberships are this action's data); the
 * Sessions and Security regions handle their own rows. Every action goes through
 * _Sys_User_Actions: a server-published refusal is explained instead of confirmed, and a
 * refusal the server answers is shown in a dialog.
 */
@route('/_sys/users/:id')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('User')
class _Sys_User_View_Action extends Spa_Action {
    on_create() {
        this.data.user = null;
        this.data.memberships = [];
        this.data.load_error = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Users_Controller.detail({ id: this.args.id });
            this.data.user = response.user;
            this.data.memberships = response.memberships;
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
        return 'User #' + this.args.id;
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_user_view');

        this.$.on('click._sys_user_view', '[data-action="sign-in-as"]', function () {
            that._sign_in_as();
        });

        this.$.on('click._sys_user_view', '[data-action="status"]', function () {
            that._change_status();
        });

        this.$.on('click._sys_user_view', '[data-action="terminate-all"]', function () {
            that._terminate_all();
        });

        this.$.on('click._sys_user_view', '[data-action="membership-enable"], [data-action="membership-disable"]', function () {
            const $button = $(this);
            const membership = that.data.memberships.find((row) => row.id === Number($button.attr('data-membership-id')));
            that._set_membership_enabled(membership, $button.attr('data-action') === 'membership-enable');
        });
    }

    /**
     * Impersonation: a published refusal is explained; otherwise one dialog picks the
     * membership site and confirms, then the page leaves the panel.
     */
    async _sign_in_as() {
        if (await _Sys_User_Actions.refused(this.data.user.impersonate_refusal)) {
            return;
        }

        await _Sys_User_Sign_In_As_Form.open(this.data.user, this.data.memberships);
    }

    /**
     * One dialog both chooses and confirms: a button per status the identity is not in.
     */
    async _change_status() {
        const user = this.data.user;

        if (await _Sys_User_Actions.refused(user.status_refusal)) {
            return;
        }

        // Cancel is the default: Enter must never apply a status the developer did not pick.
        const buttons = [{ label: 'Cancel', value: false, default: true }];
        for (const option of user.status_options) {
            if (option.value !== user.status) {
                buttons.push({ label: 'Set ' + option.label, value: option.value, class: 'btn-primary' });
            }
        }

        const status = await _Sys_Modal.show({
            title: 'Change the status of ' + user.email + '?',
            body: 'It is ' + user.status_label + ' now.\n\n' +
                'Status is this application\'s own vocabulary: the framework does not act on it. ' +
                'Sign-in and live sessions are decided by memberships, so a new status neither signs ' +
                user.email + ' out nor refuses a sign-in unless this application\'s own login code reads it. ' +
                'To stop access, disable the memberships or end the sessions.',
            buttons: buttons,
        });

        if (!status) {
            return;
        }

        const result = await _Sys_User_Actions.call(() => _Sys_Users_Controller.set_status({ id: user.id, status: status }));
        if (result === null) {
            return;
        }

        Flash_Alert.success(user.email + ' is ' + result.status_label + '.');
        this.reload();
    }

    async _terminate_all() {
        const user = this.data.user;
        const body = user.is_self
            ? 'Every other browser signed in as you is signed out now. This browser stays signed in.'
            : 'Every browser signed in as ' + user.email + ' is signed out now and reloads to the sign-in page. ' +
              'Their memberships are untouched, so they can sign in again.';

        if (!await _Sys_Modal.confirm('End all sessions of ' + user.email + '?', body, 'End all sessions')) {
            return;
        }

        const result = await _Sys_User_Actions.call(() => _Sys_Users_Controller.terminate_all_sessions({ id: user.id }));
        if (result === null) {
            return;
        }

        Flash_Alert.success(result.count === 1 ? '1 session ended.' : result.count + ' sessions ended.');
        this.sid('sessions').reload();
    }

    async _set_membership_enabled(membership, enable) {
        const user = this.data.user;
        const site = membership.site_name ? membership.site_name + ' (site #' + membership.site_id + ')' : 'site #' + membership.site_id;

        if (!enable && await _Sys_User_Actions.refused(membership.disable_refusal)) {
            return;
        }

        let confirmed;
        if (enable) {
            confirmed = await _Sys_Modal.confirm(
                'Enable the membership on ' + site + '?',
                user.email + ' can sign in to this site again' +
                    (membership.site_is_enabled ? '.' : ' once the site itself is enabled - it is disabled now.'),
                'Enable'
            );
        } else {
            const others = this.data.memberships.filter((row) => row.id !== membership.id && row.is_active).length;
            confirmed = await _Sys_Modal.confirm(
                'Disable the membership on ' + site + '?',
                user.email + ' can no longer sign in to this site, and a session on it ends on its next request. ' +
                    (others === 0
                        ? 'This is its only active membership, so it cannot sign in at all until one is enabled. '
                        : 'Its ' + (others === 1 ? 'other active membership is' : others + ' other active memberships are') + ' untouched. ') +
                    'Nothing is deleted.',
                'Disable'
            );
        }

        if (!confirmed) {
            return;
        }

        const result = await _Sys_User_Actions.call(() => _Sys_Users_Controller.set_membership_enabled({ id: membership.id, enabled: enable }));
        if (result === null) {
            return;
        }

        Flash_Alert.success('The membership on ' + site + (enable ? ' is enabled.' : ' is disabled.'));
        this.reload();
    }
}
