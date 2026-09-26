/**
 * _Sys_Status_Badge
 *
 * See _Sys_Status_Badge.jqhtml. The class owns the one tone map, so a screen that
 * needs the tone without the pill (a row accent, a tile) reads the same answer.
 */
class _Sys_Status_Badge extends Component {
    /**
     * Status word -> tone. The five tones map to themselves.
     */
    static TONES = {
        ok: 'ok',
        warn: 'warn',
        fail: 'fail',
        info: 'info',
        muted: 'muted',

        // Tasks (_tasks.status) and the mail/SMS queues share 'pending' and 'failed'.
        pending: 'info',
        running: 'info',
        completed: 'ok',
        failed: 'fail',
        killed: 'warn',

        sending: 'info',
        sent: 'ok',
        blocked: 'warn',
        suppressed: 'muted',

        // Login identities (login_users.status_id, as the lowercased enum label).
        active: 'ok',
        inactive: 'muted',
        suspended: 'fail',
    };

    /**
     * The tone for a status word. Throws for a word the map does not know.
     *
     * @param {string} status
     * @returns {string} ok | warn | fail | info | muted
     */
    static tone_for(status) {
        const key = str(status).toLowerCase();
        const tone = _Sys_Status_Badge.TONES[key];

        if (!tone) {
            throw new Error(`_Sys_Status_Badge: unknown status '${status}'. Add it to _Sys_Status_Badge.TONES.`);
        }

        return tone;
    }
}
