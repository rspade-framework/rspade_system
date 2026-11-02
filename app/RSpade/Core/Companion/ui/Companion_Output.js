/**
 * Companion_Output - a companion application's output, live, in the task console's terminal.
 *
 *     <Companion_Output $companion="hello" />
 *
 * $companion  (required) the companion's name
 * $streams    'both' (default) | 'stdout' | 'stderr' - which lines to show at first
 * $filter     true (default) shows the stdout / stderr / both selector; false hides it
 *
 * The <Task_Output> twin: the same template, terminal, toolbar and look (it extends that class
 * and overrides only its source hooks), reading the companion's capped Redis stream through
 * Rsx_Companion.output_after() with an OPAQUE string cursor. stdout is plain white, stderr bold
 * amber, and the daemon's own lines about the companion (an instance starting or ending) dim
 * cyan; "stderr" shows the daemon's lines too. Instances follow one another in one feed.
 *
 * THE STREAM IS CAPPED (rsx.companions.output_stream_max_lines). When lines this console never
 * showed were dropped from it - an old companion opened from the start, or a reader that fell
 * behind - a marker line says so, rather than presenting the gap as continuous; the log file
 * under storage/logs/companions/<name>/ has every line.
 *
 * Live updates ride Companion_Output_Topic {name}, published by rsx-companiond, and need realtime
 * (REALTIME_ENABLED); without it the console shows the output as it was when it opened, and
 * Refresh reads what is new. Reading needs the companion's view gate (Companion_Gates).
 */
class Companion_Output extends Task_Output {
    /** stdout: plain white. stderr: bold amber. The daemon's lines: dim cyan. */
    static STREAM_STYLE = {
        stdout: '\x1b[0;37m',
        stderr: '\x1b[1;38;5;214m',
        daemon: '\x1b[2;36m',
    };

    /** The marker written where the stream dropped lines this console never showed. */
    static TRIMMED_NOTICE = '[earlier output was dropped from the live stream; the companion\'s log file has every line]';

    _check_args() {
        if (!this.args.companion) {
            throw new Error('Companion_Output requires $companion');
        }
    }

    _stream_list(streams) {
        return streams === 'both' ? ['stdout', 'stderr', 'daemon'] : (streams === 'stderr' ? ['stderr', 'daemon'] : ['stdout']);
    }

    _read_page(cursor, streams) {
        return Rsx_Companion.output_after(this.args.companion, cursor, streams);
    }

    _subscribe_output(callback) {
        this.subscribe('Companion_Output_Topic', { name: str(this.args.companion) }, callback);
    }

    _write_notices(response) {
        if (response.trimmed) {
            this._write_line('daemon', Companion_Output.TRIMMED_NOTICE, null);
        }
    }
}
