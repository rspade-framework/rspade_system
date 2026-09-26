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
}
