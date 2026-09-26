/**
 * _Sys_Debug_Flags_Action - the control panel's Debug Flags screen.
 *
 * This browser's console_debug override (_Sys_Debug_Flags_Form) over the read-only
 * table of what is in force (_Sys_Debug_Flags_Reference). The state and the channel
 * inventory load in parallel; a save or a reset reloads the screen, so the table shows
 * the override's effect on this very request.
 */
@route('/_sys/debug-flags')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Debug Flags')
class _Sys_Debug_Flags_Action extends Spa_Action {
    on_create() {
        this.data.state = null;
        this.data.channels = [];
        this.data.error_data = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const [state, channels] = await Promise.all([
                _Sys_Debug_Flags_Controller.state(),
                _Sys_Debug_Flags_Controller.channels(),
            ]);
            this.data.state = state;
            this.data.channels = channels;
        } catch (e) {
            // The MESSAGE: this.data keeps plain data, and an Error's message would not survive.
            console.error(e);
            this.data.error_data = e.message || String(e);
        }
        this.data.loading = false;
    }

    on_ready() {
        const that = this;
        const form = this.sid('form');

        if (form) {
            form.on('changed', function () {
                that.reload();
            });
        }
    }
}
