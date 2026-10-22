/**
 * _Sys_System_Info_Action - the System Info screen. See the .jqhtml.
 *
 * Three-state over ONE call (_Sys_System_Info_Controller.report); Refresh reloads it.
 * Every value arrives raw from the server; the static helpers below only format it,
 * answering null for a value the server could not read so _Sys_Detail_Item draws its dash.
 */
@route('/_sys/system-info')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('System Info')
class _Sys_System_Info_Action extends Spa_Action {
    /** supervisorctl state -> _Sys_Status_Badge tone. */
    static SERVICE_TONES = {
        RUNNING: 'ok',
        STARTING: 'info',
        BACKOFF: 'warn',
        STOPPING: 'warn',
        STOPPED: 'muted',
        EXITED: 'fail',
        FATAL: 'fail',
        UNKNOWN: 'muted',
    };

    /** A byte count in words, or null. */
    static bytes(value) {
        return value === null || value === undefined ? null : bytes_to_human(value);
    }

    /** "<part> of <whole>" in bytes, or null when either is missing. */
    static bytes_of(part, whole) {
        if (part === null || part === undefined || whole === null || whole === undefined) {
            return null;
        }
        return bytes_to_human(part) + ' of ' + bytes_to_human(whole);
    }

    /** A boolean as Yes / No, or null. */
    static yes_no(value) {
        return value === null || value === undefined ? null : (value ? 'Yes' : 'No');
    }

    /** A number with thousands separators, or null. */
    static count(value) {
        return value === null || value === undefined ? null : Number(value).toLocaleString();
    }

    on_create() {
        this.data.report = null;
        this.data.error_data = null;
    }

    async on_load() {
        try {
            this.data.report = await _Sys_System_Info_Controller.report();
        } catch (e) {
            console.error(e);
            this.data.error_data = e.message || String(e);
        }
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_info').on('click._sys_info', '[data-action="refresh"]', function () {
            that.reload();
        });
    }
}
