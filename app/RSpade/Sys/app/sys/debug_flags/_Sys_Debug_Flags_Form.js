/**
 * _Sys_Debug_Flags_Form - see _Sys_Debug_Flags_Form.jqhtml.
 */
class _Sys_Debug_Flags_Form extends Component {
    on_ready() {
        const that = this;
        const form = this.sid('form');
        const filter = form.input('filter_mode');

        this.__show_channels(filter.val());

        filter.on('val', function (component, value) {
            that.__show_channels(value);
        });

        form.on('submitted', function () {
            Flash_Alert.success('console_debug override saved for this browser.');
            that.trigger('changed');
        });

        this.$.off('click._sys_debug_flags').on('click._sys_debug_flags', '[data-action="reset"]', async function () {
            await _Sys_Debug_Flags_Controller.reset();
            Flash_Alert.success('Override removed; this browser follows the configuration again.');
            that.trigger('changed');
        });
    }

    /** The channel list matters to every filter but "all". */
    __show_channels(mode) {
        this.$sid('channels_field').toggle(mode !== 'all');
    }
}
