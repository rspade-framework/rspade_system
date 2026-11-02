/**
 * _Sys_Companion_Activity - see the .jqhtml.
 */
class _Sys_Companion_Activity extends Component {
    /** How long an instance ran (or has run), in words; '' when it never started. */
    static ran(record) {
        if (!record.started_at) {
            return '';
        }

        const end = record.ended_at ? Rsx_Time.to_ms(record.ended_at) : Date.now();
        const seconds = Math.max(0, Math.floor((end - Rsx_Time.to_ms(record.started_at)) / 1000));

        return Rsx_Time.duration_to_human(seconds, true);
    }

    on_create() {
        this.data.detail = null;
        this.data.error_data = null;

        const refresh_soon = debounce(() => {
            if (this.is_stopped()) return;
            return this.refresh();
        }, Rsx_Companion.LIVE_UPDATE_DELAY);
        this.subscribe('Companion_Changed_Topic', { name: str(this.args.name) }, () => refresh_soon());
    }

    async on_load() {
        try {
            this.data.detail = await _Sys_Companions_Controller.detail({ name: str(this.args.name) });
        } catch (e) {
            this.data.error_data = e;
        }
    }
}
