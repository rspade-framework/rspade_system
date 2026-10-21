/**
 * _Sys_User_Actions - the two steps every Users-screen action shares.
 *
 *     if (await _Sys_User_Actions.refused(row.disable_refusal)) return;
 *     if (!await _Sys_Modal.confirm(...)) return;
 *     const result = await _Sys_User_Actions.call(() => _Sys_Users_Controller.x({...}));
 *     if (result === null) return;   // refused or gone - already explained
 *
 * refused(reason) shows the server-published reason a row cannot take an action (a
 * *_refusal field on the payload) and answers true; null answers false and shows nothing.
 *
 * call(fn) runs one action endpoint. A refusal (ERROR_VALIDATION) or a vanished record
 * (ERROR_NOT_FOUND) is shown in a dialog and answers null - the server is the authority,
 * so a row published as allowed can still be refused when the state moved underneath it.
 * Anything else is not handled here and propagates.
 *
 * end_session(login_user_id, email, session) is the whole End flow for one user session
 * (a _Sys_Users_Controller.sessions entry), shared by the Sessions tab and the session
 * screen: the published refusal, the confirm, terminate_session, the flash. It answers
 * true when the caller should repaint (ended, or the server said it is gone), false when
 * nothing happened (refused or cancelled).
 */
class _Sys_User_Actions {
    static async refused(reason) {
        if (!reason) {
            return false;
        }

        await _Sys_Modal.alert('Not available', reason);

        return true;
    }

    static async call(fn) {
        try {
            return await fn();
        } catch (e) {
            if (e.code === Ajax.ERROR_VALIDATION || e.code === Ajax.ERROR_NOT_FOUND) {
                await _Sys_Modal.alert(e.code === Ajax.ERROR_NOT_FOUND ? 'Not found' : 'Refused', e.message);

                return null;
            }
            throw e;
        }
    }

    static async end_session(login_user_id, email, session) {
        if (await _Sys_User_Actions.refused(session.terminate_refusal)) {
            return false;
        }

        const confirmed = await _Sys_Modal.confirm(
            'End this session of ' + email + '?',
            session.device_summary + ' at ' + session.ip_address + ' is signed out now and reloads to the sign-in page. ' +
                (session.impersonator_login_user_id
                    ? 'This also ends the impersonation by ' + (session.impersonator_email || '#' + session.impersonator_login_user_id) + '. '
                    : '') +
                'The identity can sign in again.',
            'End session'
        );

        if (!confirmed) {
            return false;
        }

        const result = await _Sys_User_Actions.call(() => _Sys_Users_Controller.terminate_session({ id: login_user_id, user_session_id: session.id }));
        if (result !== null) {
            Flash_Alert.success('The session is ended.');
        }

        return true;
    }
}
