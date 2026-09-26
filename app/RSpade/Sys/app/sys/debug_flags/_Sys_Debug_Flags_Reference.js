/**
 * _Sys_Debug_Flags_Reference - see _Sys_Debug_Flags_Reference.jqhtml.
 */
class _Sys_Debug_Flags_Reference extends Component {
    /**
     * A configuration value as one line of text: booleans and null by name, a list
     * comma-separated (or "none"), anything else as JSON.
     *
     * @param {*} value
     * @returns {string}
     */
    static format_value(value) {
        if (value === null || value === undefined) {
            return 'null';
        }

        if (value === true || value === false) {
            return value ? 'true' : 'false';
        }

        if (Array.isArray(value)) {
            return value.length ? value.join(', ') : 'none';
        }

        if (is_object(value)) {
            return JSON.stringify(value);
        }

        return str(value);
    }
}
