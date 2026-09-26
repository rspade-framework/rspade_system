/**
 * _Sys_User_Security - an identity's Security tab. See _Sys_User_Security.jqhtml.
 *
 * Three states: loading, error, the two sections. Every action goes through
 * _Sys_User_Actions (the payload's refusal explained, confirm, call) and reloads the
 * region.
 */
class _Sys_User_Security extends Component {
    on_create() {
        this.data.refusal = null;
        this.data.two_factor = null;
        this.data.sso = null;
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Users_Controller.security({ id: this.args.login_user_id });
            this.data.refusal = response.refusal;
            this.data.two_factor = response.two_factor;
            this.data.sso = response.sso;
        } catch (e) {
            // The MESSAGE: this.data keeps plain data, and an Error's message would not survive.
            console.error(e);
            this.data.error_data = e.message || String(e);
        }
        this.data.loading = false;
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_user_security');

        this.$.on('click._sys_user_security', '[data-action="remove-factor"]', function () {
            const credential_id = Number($(this).attr('data-credential-id'));
            that._remove_factor(that.data.two_factor.credentials.find((row) => row.id === credential_id));
        });

        this.$.on('click._sys_user_security', '[data-action="remove-all-factors"]', function () {
            that._remove_factor(null);
        });

        this.$.on('click._sys_user_security', '[data-action="unlink"]', function () {
            const link_id = Number($(this).attr('data-link-id'));
            that._unlink(that.data.sso.identities.find((row) => row.id === link_id));
        });

        this.$.on('click._sys_user_security', '[data-action="unlink-all"]', function () {
            that._unlink(null);
        });
    }

    /**
     * Remove one factor, or (credential null) every factor and recovery code.
     */
    async _remove_factor(credential) {
        if (await _Sys_User_Actions.refused(this.data.refusal)) {
            return;
        }

        const email = this.args.email;
        const two_factor = this.data.two_factor;
        const is_last = credential !== null && two_factor.credentials.length === 1;
        const password_alone = 'A password alone then signs ' + email + ' in, and a provider sign-in is no longer challenged. They can enroll again.';

        const confirmed = credential === null
            ? await _Sys_Modal.confirm(
                'Remove every second factor of ' + email + '?',
                'Every factor and every recovery code is removed. ' + password_alone,
                'Remove all'
            )
            : await _Sys_Modal.confirm(
                'Remove ' + credential.type_id__label + (credential.label ? ' "' + credential.label + '"' : '') + '?',
                is_last
                    ? 'It is the last second factor of ' + email + ', so its recovery codes are removed too. ' + password_alone
                    : email + ' keeps its other second factors.',
                'Remove'
            );

        if (!confirmed) {
            return;
        }

        const params = { id: this.args.login_user_id };
        if (credential !== null) {
            params.credential_id = credential.id;
        }

        const result = await _Sys_User_Actions.call(() => _Sys_Users_Controller.remove_two_factor(params));
        if (result !== null) {
            Flash_Alert.success(result.is_enabled ? 'The factor is removed.' : email + ' has no second factor now.');
        }
        this.reload();
    }

    /**
     * Disconnect one provider account, or (identity null) every one.
     */
    async _unlink(identity) {
        if (await _Sys_User_Actions.refused(this.data.refusal)) {
            return;
        }

        const email = this.args.email;
        const no_check = 'Nothing checks that ' + email + ' keeps another way to sign in.';

        const confirmed = identity === null
            ? await _Sys_Modal.confirm(
                'Disconnect every sign-in provider of ' + email + '?',
                'None of its connected accounts signs it in any more. ' + no_check,
                'Disconnect all'
            )
            : await _Sys_Modal.confirm(
                'Disconnect ' + identity.provider_label + (identity.email ? ' (' + identity.email + ')' : '') + '?',
                'That account no longer signs ' + email + ' in. ' + no_check,
                'Disconnect'
            );

        if (!confirmed) {
            return;
        }

        const params = { id: this.args.login_user_id };
        if (identity !== null) {
            params.link_id = identity.id;
        }

        const result = await _Sys_User_Actions.call(() => _Sys_Users_Controller.unlink_sso(params));
        if (result !== null) {
            Flash_Alert.success(identity === null ? 'Every provider is disconnected.' : identity.provider_label + ' is disconnected.');
        }
        this.reload();
    }
}
