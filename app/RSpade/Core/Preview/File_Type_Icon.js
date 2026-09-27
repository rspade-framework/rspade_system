/**
 * File_Type_Icon
 *
 * See File_Type_Icon.jqhtml for the argument contract. This class holds the baked icon data and
 * the lookup; the component itself has no state, no load and no lifecycle work.
 *
 * THE DATA IS THE SERVER'S. BundleCompiler calls _define() at the bundle tail with
 * File_Attachment_Icons::get_outline_icon_payload() - the one extension map, the generic icon
 * name and each distinct SVG once - so there is no client-side extension list to drift from the
 * PHP one. The SVG strings are framework-owned artwork (resource/icons/outline/), which is why the
 * template may emit them unescaped.
 */
class File_Type_Icon extends Component {
    /** {extensions: {ext: name}, generic: name, icons: {name: svg}}, baked by BundleCompiler. */
    static _payload = {extensions: {}, generic: null, icons: {}};

    /**
     * Receive the baked payload. Called once per bundle, at the bundle tail.
     *
     * @param {Object} payload
     */
    static _define(payload) {
        File_Type_Icon._payload = payload;
    }

    /**
     * The outline SVG markup for an extension; the generic mark when it is unrecognised or empty.
     *
     * @param {string|null} extension without the dot, any case
     * @returns {string}
     */
    static svg_for_extension(extension) {
        const payload = File_Type_Icon._payload;
        const key = str(extension || '').toLowerCase();
        const name = payload.extensions[key] || payload.generic;
        const svg = payload.icons[name];

        if (!svg) {
            shouldnt_happen(`File_Type_Icon: no baked outline icon named '${name}'`);
        }

        return svg;
    }

    /**
     * The extension of a file name: whatever follows the last dot, or '' when there is none.
     *
     * @param {string|null} file_name
     * @returns {string}
     */
    static extension_of(file_name) {
        const name = str(file_name || '');
        const dot = name.lastIndexOf('.');

        return dot > 0 ? name.substring(dot + 1) : '';
    }

    /**
     * Resolve the component's arguments to SVG markup ($extension, then $attachment, then
     * $file_name). Throws when none of the three was given - a missing argument is a caller bug,
     * never "a generic file".
     *
     * @param {Object} args the component's this.args
     * @returns {string}
     */
    static svg_for_args(args) {
        if (args.extension !== undefined) {
            return File_Type_Icon.svg_for_extension(args.extension);
        }
        if (args.attachment !== undefined) {
            return File_Type_Icon.svg_for_extension(args.attachment ? args.attachment.file_extension : '');
        }
        if (args.file_name !== undefined) {
            return File_Type_Icon.svg_for_extension(File_Type_Icon.extension_of(args.file_name));
        }

        throw new Error('File_Type_Icon requires $extension, $attachment or $file_name');
    }
}
