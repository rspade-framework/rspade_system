/**
 * Rsx_Task - read a background task run and act on one, from the browser.
 *
 * Every call goes through Rsx_Task_Controller, which asks the application's task gates
 * (Task_Gates: deny by default; a developer passes) - so a run the viewer may not see is
 * "not found", and an action they may not take is refused. The task widgets are built on this
 * class; an application building its own task UI uses it the same way.
 *
 *     const status = await Rsx_Task.get(327);            // {status, progress_percent, can, ...}
 *     const {lines, last_id} = await Rsx_Task.output_after(327, null);
 *     const runs = await Rsx_Task.find({class: 'Import_Service', live: true});
 *     await Rsx_Task.force_stop(327, 30, 'operator asked');
 *
 * REALTIME. A run publishes "something changed" frames and nothing else; refetch on each one:
 *
 *     const watcher = await Rsx_Task.watch(327, () => refresh_status());  // lifecycle, reports, messages
 *     await Rsx_Task.watch_output(327, () => read_more_output());          // output lines (the busy feed)
 *     await Rsx_Task.watch_list(() => reload_my_list());                   // any run started or finished
 *     await Rsx_Task.watch_task('Import_Service', 'run', () => check());   // runs of ONE task, by name
 *     watcher.stop();
 *
 * IS A TASK RUNNING? Watch it by name and ask on every frame (and once at the start - the
 * resync does that for you):
 *
 *     await Rsx_Task.watch_task('Import_Service', 'run', async () => {
 *         const runs = await Rsx_Task.live_runs('Import_Service', 'run');   // pending or running
 *         show_import_busy(runs.length > 0);
 *     });
 *
 * Inside a component prefer this.subscribe('Task_Changed_Topic', {id}, cb) (and
 * 'Task_Output_Topic' / 'Task_List_Changed_Topic', which takes an optional {class, method}
 * filter): the subscription then ends with the component. Every watch fires once on (re)subscribe as a resync, so write each callback as
 * an idempotent refetch, and route it through debounce(refetch, Rsx_Task.LIVE_UPDATE_DELAY)
 * as the task widgets do:
 *
 *     const refresh_soon = debounce(() => this.refresh(), Rsx_Task.LIVE_UPDATE_DELAY);
 *     this.subscribe('Task_Changed_Topic', { id: task_id }, () => refresh_soon());
 */
class Rsx_Task {
    /**
     * The one delay every task view debounces its realtime refetch by: each frame calls a
     * framework debounce(refetch, LIVE_UPDATE_DELAY) - the first frame refetches at once,
     * frames during a refetch or the delay after it coalesce into ONE follow-up, and refetches
     * never overlap. Use it for a task view of your own too.
     */
    static LIVE_UPDATE_DELAY = 250;

    /** The report kinds a run may carry, in the order a report browser offers them. */
    static REPORT_KINDS = ['state_json', 'state_list', 'status_text', 'progress', 'progress_count', 'eta', 'heartbeat', 'messages', 'summary', 'return_code'];

    /** A display label per report kind. */
    static REPORT_LABELS = {
        state_json: 'State',
        state_list: 'Queue',
        status_text: 'Status',
        progress: 'Progress',
        progress_count: 'Progress (count)',
        eta: 'ETA',
        heartbeat: 'Heartbeat',
        messages: 'Messages',
        summary: 'Summary',
        return_code: 'Return code',
    };

    /** One run's status (with `can`: the lifecycle actions the viewer may take). */
    static async get(task_id) {
        return Rsx_Task_Controller.get({ task_id: int(task_id) });
    }

    /** One report's value: {kind, value, status}. */
    static async report(task_id, kind) {
        return Rsx_Task_Controller.report({ task_id: int(task_id), kind: kind });
    }

    /**
     * Output lines after a cursor: {lines: [{id, stream, line, at}], last_id, more, is_live}.
     *
     * @param {number} task_id
     * @param {number|null} after_id null reads from the start
     * @param {string[]} [streams] any of stdout, stderr, operator (default: all)
     */
    static async output_after(task_id, after_id, streams = null) {
        const params = { task_id: int(task_id), after_id: after_id };
        if (streams) {
            params.streams = streams;
        }

        return Rsx_Task_Controller.output(params);
    }

    /** Messages after a cursor: {messages: [{id, body, at}], last_id, more}. */
    static async messages_after(task_id, after_id) {
        return Rsx_Task_Controller.messages({ task_id: int(task_id), after_id: after_id });
    }

    /** The newest visible runs matching a filter (at most 1000): {tasks: [...]}. */
    static async find(filter = {}) {
        return Rsx_Task_Controller.find({ filter: filter });
    }

    /** One page of visible runs matching a filter, newest first: {tasks, next_cursor}. */
    static async page(filter = {}, cursor = null, page_size = null) {
        const params = { filter: filter, cursor: cursor };
        if (page_size) {
            params.page_size = page_size;
        }

        return Rsx_Task_Controller.page(params);
    }

    /** Graceful stop: the task stops at its next check; never killed. */
    static async stop(task_id, explanation = null) {
        return Rsx_Task_Controller.stop({ task_id: int(task_id), explanation: explanation });
    }

    /** Force stop: ask, then kill after grace_seconds (null: the configured default). */
    static async force_stop(task_id, grace_seconds = null, explanation = null) {
        return Rsx_Task_Controller.force_stop({ task_id: int(task_id), grace_seconds: grace_seconds, explanation: explanation });
    }

    /** Force kill: kill the run's worker now. */
    static async force_kill(task_id, explanation = null) {
        return Rsx_Task_Controller.force_kill({ task_id: int(task_id), explanation: explanation });
    }

    /** Cancel a pending run. */
    static async cancel(task_id, explanation = null) {
        return Rsx_Task_Controller.cancel({ task_id: int(task_id), explanation: explanation });
    }

    /** Run a finished run again; resolves {task_id} of the new run. */
    static async rerun(task_id) {
        return Rsx_Task_Controller.rerun({ task_id: int(task_id) });
    }

    /** Watch one run's lifecycle and reports. Resolves {stop(), established}. */
    static watch(task_id, callback) {
        return Rsx_Realtime.watch('Task_Changed_Topic', { id: int(task_id) }, callback);
    }

    /** Watch one run's output. Resolves {stop(), established}. */
    static watch_output(task_id, callback) {
        return Rsx_Realtime.watch('Task_Output_Topic', { id: int(task_id) }, callback);
    }

    /** Watch for any run entering, leaving or changing lifecycle. Resolves {stop(), established}. */
    static watch_list(callback) {
        return Rsx_Realtime.watch('Task_List_Changed_Topic', {}, callback);
    }

    /**
     * Watch the runs of ONE task by name: the callback fires when a run of it is queued,
     * starts, settles, is cancelled or killed. Resolves {stop(), established}.
     *
     * @param {string} class_name the service's simple name ('Import_Service')
     * @param {string|null} method the task method; null watches every task of the service
     * @param {Function} callback
     */
    static watch_task(class_name, method, callback) {
        const filter = { class: class_name };
        if (method) {
            filter.method = method;
        }

        return Rsx_Realtime.watch('Task_List_Changed_Topic', filter, callback);
    }

    /**
     * The visible LIVE runs (pending or running) of one task, newest first - "is it running?".
     * Read through the view scope like find().
     *
     * @param {string} class_name the service's simple name
     * @param {string|null} method null: every task of the service
     * @returns {Promise<Array>} status objects, as find() answers them
     */
    static async live_runs(class_name, method = null) {
        const filter = { class: class_name, live: true };
        if (method) {
            filter.method = method;
        }

        return (await Rsx_Task.find(filter)).tasks;
    }

    /**
     * The display tone of a status: pending, running, completed, failed, stopped, killed,
     * cancelled -> neutral | active | success | danger | warning.
     */
    static tone(status_id) {
        return {
            1: 'neutral',
            2: 'active',
            3: 'success',
            4: 'danger',
            5: 'warning',
            6: 'danger',
            7: 'neutral',
        }[int(status_id)] || 'neutral';
    }

    /**
     * "45.5%", "3 of 257", or both - the progress a status reports, as text; '' when none.
     */
    static progress_text(status) {
        const parts = [];
        if (status.progress_percent !== null && status.progress_percent !== undefined) {
            parts.push(Rsx_Task.format_percent(status.progress_percent));
        }
        if (status.progress_count) {
            parts.push(status.progress_count.done + ' of ' + status.progress_count.total);
        }

        return parts.join(' - ');
    }

    /** 45.5 -> "45.5%", 45 -> "45%", 45.25 -> "45.25%". */
    static format_percent(value) {
        return (Math.round(float(value) * 100) / 100) + '%';
    }
}
