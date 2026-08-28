/**
 * Spinner - renders the application's registered spinner inside itself.
 * See Spinner.jqhtml for the paradigm; sizing belongs to the host.
 */
class Spinner extends Component {
    on_render() {
        this.$sid('target').component(Rsx.get_default_spinner());
    }
}
