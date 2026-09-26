/**
 * _Sys_Email_Preview - see _Sys_Email_Preview.jqhtml.
 *
 * srcdoc is written as a PROPERTY after render rather than interpolated into the
 * attribute: the document is tens of kilobytes of markup, and the property takes it
 * verbatim with no attribute escaping to get wrong.
 */
class _Sys_Email_Preview extends Component {
    on_render() {
        if (!this.args.html) {
            throw new Error('_Sys_Email_Preview requires $html');
        }

        this.$sid('frame').get(0).srcdoc = this.args.html;
    }
}
