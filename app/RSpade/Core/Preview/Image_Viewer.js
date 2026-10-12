/**
 * Image_Viewer - see Image_Viewer.jqhtml for the contract.
 *
 * The template renders the <img> with no source. on_ready binds the load and error handlers and
 * only THEN sets src, so neither event can be missed - a cached image included - and exactly one
 * of them reports.
 */
class Image_Viewer extends Component {
    on_render() {
        this.invalidate('preview_loaded');
    }

    on_ready() {
        const that = this;
        const $img = this.$sid('img');
        const img = $img[0];

        const report = (state) => {
            const w = state === 'loaded' ? (img.naturalWidth || 0) : 0;
            const h = state === 'loaded' ? (img.naturalHeight || 0) : 0;
            that.trigger('preview_loaded', {
                pages: 1,
                width: w,
                height: h,
                ratio: w ? (h / w) : 0,
                state: state,
                attachment_id: that.args.attachment_id ? int(that.args.attachment_id) : null,
            });
        };

        if (!this.args.url) {
            // An <img> with no source fires neither load nor error, so nothing would ever report.
            console_debug('preview', 'Image_Viewer: no url to show');
            report('failed');
            return;
        }

        $img.off('load.image_viewer error.image_viewer');
        $img.on('load.image_viewer', () => report('loaded'));
        $img.on('error.image_viewer', () => {
            console_debug('preview', 'Image_Viewer: image failed to load', that.args.url);
            report('failed');
        });

        $img.attr('src', this.args.url);
    }

    set_page() { /* single page - no-op */ }

    get_page() {
        return 1;
    }

    get_pages() {
        return 1;
    }
}
