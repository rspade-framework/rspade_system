/**
 * _Sys_Locks_Action - what rsx-lockd holds right now. See the .jqhtml.
 *
 * Refreshed on demand (the Refresh button): a lock snapshot is a moment, and no topic
 * announces lock traffic. "End holder" confirms, posts to _Sys_Locks_Controller.kill_holder
 * (the identity checks are Lock_Status::kill_holder()'s), reports the outcome - a refusal
 * names its reason - and reloads.
 */
@route('/_sys/locks')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Locks')
class _Sys_Locks_Action extends Spa_Action {
    on_create() {
        this.data.snapshot = null;
        this.data.error_data = null;
    }

    async on_load() {
        try {
            this.data.snapshot = await _Sys_Locks_Controller.snapshot();
        } catch (e) {
            this.data.error_data = e;
        }
    }

    /** "1.5s", "12m 4s", "3h 2m" - a duration in milliseconds, for a table cell. */
    static duration(ms) {
        const seconds = ms / 1000;
        if (seconds < 60) return seconds.toFixed(1) + 's';
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + 'm ' + Math.floor(seconds % 60) + 's';
        return Math.floor(minutes / 60) + 'h ' + (minutes % 60) + 'm';
    }

    /** "c7 web-1:3140" - a connection as the daemon reports it. */
    static who(party) {
        return party.conn_id + ' ' + (party.host || '?') + ':' + (party.pid || '?');
    }

    /** A waiter's bounds: its own timeout and the stall watch it asked for. */
    static bounds(waiter) {
        const parts = [waiter.timeout === null ? 'no timeout' : 'timeout ' + waiter.timeout + 's'];
        if (waiter.stall !== null) parts.push('stall watch ' + waiter.stall + 's');
        return parts.join(', ');
    }

    /** Whether a holder has been silent past the stall window while somebody waits behind it. */
    static is_silent(entry, holder, stall_seconds) {
        return entry.waiters.length > 0 && stall_seconds > 0 && holder.progress_ms >= stall_seconds * 1000;
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_locks');
        this.$.on('click._sys_locks', '[data-action="refresh"]', function () {
            that.reload();
        });
        this.$.on('click._sys_locks', '[data-action="kill_holder"]', async function () {
            const $element = $(this);
            const name = $element.attr('data-name');
            const holders = $element.attr('data-holders');

            const confirmed = await that.alive(_Sys_Modal.confirm(
                'End the holder of ' + name + '?',
                'Sends SIGTERM to ' + holders + '. Its work stops where it is, and the lock is freed when the process exits. '
                    + 'Refused unless every holder is on this host and is proven to be the process that took the lock.',
                'End holder'
            ));
            if (!confirmed) {
                return;
            }

            try {
                const result = await that.alive(_Sys_Locks_Controller.kill_holder({ name: name }));
                Flash_Alert.success(result.message);
            } catch (e) {
                // The refusal's reason IS the answer: say it, then show the state it describes.
                await that.alive(_Sys_Modal.alert('Not ended', e.message || String(e)));
            }

            that.reload();
        });
    }
}
