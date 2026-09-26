/**
 * _Sys_Log_Batch - one read's entries. See _Sys_Log_Batch.jqhtml.
 *
 * Owns the laravel level -> tone map. A level the map does not know is drawn muted
 * rather than refused: a log line's level is whatever the writer chose.
 */
class _Sys_Log_Batch extends Component {
    static LEVEL_TONES = {
        DEBUG: 'muted',
        INFO: 'info',
        NOTICE: 'info',
        WARNING: 'warn',
        ERROR: 'fail',
        CRITICAL: 'fail',
        ALERT: 'fail',
        EMERGENCY: 'fail',
    };

    /**
     * @param {string} level A laravel level word (ERROR, WARNING, ...)
     * @returns {string} ok | warn | fail | info | muted
     */
    static tone_for(level) {
        return _Sys_Log_Batch.LEVEL_TONES[str(level).toUpperCase()] || 'muted';
    }
}
