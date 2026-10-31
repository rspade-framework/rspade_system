/**
 * _Sys_Performance_Action - the control panel's Performance screen.
 *
 * What requests and task runs have actually used, against what the machine was sized
 * for: php-fpm's live pool status, a summary per kind of the last 100 (page, Ajax, API,
 * task), two charts of the last hour - memory held and processes at work, stacked by
 * kind, with the budget's ceilings across them - and the last 10 of each kind.
 *
 * Loads _Sys_Performance_Controller.get_snapshot and .get_fpm_status together, then
 * refreshes every REFRESH_INTERVAL_MS while the tab is visible (paused while
 * document.hidden, resumed by a namespaced visibilitychange handler, stopped in
 * on_stop()); each refresh repaints only when the answer changed. The kind toggles
 * redraw the two charts alone and are kept in the URL hash (`kinds`).
 */
@route('/_sys/performance')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Performance')
class _Sys_Performance_Action extends Spa_Action {
    /** How often the screen asks again: a CADENCE, the pace of a chart of minutes. */
    static REFRESH_INTERVAL_MS = 15000;

    static KINDS = [
        { key: 'page', label: 'Page' },
        { key: 'ajax', label: 'Ajax' },
        { key: 'api', label: 'API' },
        { key: 'task', label: 'Task' },
    ];

    static _instance_count = 0;

    on_create() {
        this.data.snapshot = null;
        this.data.fpm = null;
        this.data.error_data = null;
        this.data.loading = true;

        const from_hash = str(Rsx.url_hash_get('kinds') || '');
        const all = _Sys_Performance_Action.KINDS.map((k) => k.key);
        this.state.visible = from_hash ? all.filter((key) => from_hash.split(',').includes(key)) : all;

        this._poll_timer = null;
        this._polling = false;
        _Sys_Performance_Action._instance_count++;
        this._visibility_ns = 'visibilitychange._sys_performance_' + _Sys_Performance_Action._instance_count;
    }

    async on_load() {
        try {
            const [snapshot, fpm] = await Promise.all([
                _Sys_Performance_Controller.get_snapshot(),
                _Sys_Performance_Controller.get_fpm_status().catch((e) => {
                    console.error(e);
                    return null;
                }),
            ]);
            this.data.snapshot = snapshot;
            this.data.fpm = fpm;
        } catch (e) {
            console.error(e);
            this.data.error_data = e.message || String(e);
        }
        this.data.loading = false;
    }

    on_render() {
        const that = this;

        this.$.off('change._sys_performance').on('change._sys_performance', '[data-kind-toggle]', function () {
            const $box = $(this);
            const key = str($box.attr('data-kind-toggle'));
            const visible = new Set(that.state.visible);

            if ($box.is(':checked')) visible.add(key); else visible.delete(key);

            that.state.visible = _Sys_Performance_Action.KINDS.map((k) => k.key).filter((k) => visible.has(k));
            Rsx.url_hash_set_single('kinds', that.state.visible.join(','));

            for (const sid of ['memory_chart', 'workers_chart']) {
                const chart = that.sid(sid);
                if (chart) chart.set_visible(that.state.visible);
            }
        });
    }

    on_ready() {
        const that = this;

        $(document).off(this._visibility_ns).on(this._visibility_ns, function () {
            if (!document.hidden) that._schedule();
        });

        this._schedule();
    }

    on_stop() {
        if (this._poll_timer !== null) {
            clearTimeout(this._poll_timer);
            this._poll_timer = null;
        }
        $(document).off(this._visibility_ns);
    }

    /** Arm the next refresh, unless one is armed or running, or the tab is hidden. */
    _schedule() {
        const that = this;

        if (this.is_stopped() || document.hidden || this._poll_timer !== null || this._polling) {
            return;
        }

        this._poll_timer = setTimeout(function () {
            that._poll();
        }, _Sys_Performance_Action.REFRESH_INTERVAL_MS);
    }

    async _poll() {
        this._poll_timer = null;

        if (this.is_stopped() || document.hidden) {
            return;
        }

        this._polling = true;
        try {
            await this.alive(this.refresh());
        } catch (e) {
            console.error(e);
        } finally {
            this._polling = false;
        }

        this._schedule();
    }

    /** The two charts' series and ceilings, from the snapshot. */
    chart_inputs() {
        const s = this.data.snapshot;
        const b = s.budget;
        const series = (field) => _Sys_Performance_Action.KINDS.map((k) => ({
            key: k.key,
            label: k.label,
            values: s.minutes.map((m) => m.kinds[k.key][field]),
        }));

        return {
            memory: {
                series: series('avg_mem_mb'),
                ceilings: b ? [
                    { label: 'RAM for PHP', value: b.php_ram_mb },
                    { label: 'Planned typical', value: b.planned_typical_mb },
                ] : [],
            },
            workers: {
                series: series('avg_workers'),
                ceilings: b ? [
                    { label: 'php-fpm + task workers', value: b.worker_ceiling },
                ] : [],
            },
        };
    }
}
