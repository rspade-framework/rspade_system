/**
 * File_Attachment_Model (JavaScript) - the concrete ORM class over the generated
 * Base_File_Attachment_Model stub.
 *
 * thumbnail_url(), inline_url() and download_url() below are THE place a file URL is spelled in
 * JavaScript. Nothing else in the framework or in app code builds one - not a template, not a
 * page script. Each carries ?v=<content_version(record)>: the file routes always serve the
 * attachment's current bytes, give their long-lived cache headers only to a request whose v is
 * current, and answer one without it no-store - so a URL concatenated from a key is correct and
 * never cached, and a URL from here is cached for a year and stops being requested the moment
 * the bytes behind the attachment change.
 *
 * The record these take is a File_Attachment_Model.fetch() payload (it embeds file_storage).
 * <Attachment_Thumbnail> is the component that keeps one current; prefer it to a URL.
 */
class File_Attachment_Model extends Base_File_Attachment_Model {
    /**
     * Build the thumbnail URL for an attachment record.
     *
     * The route shape is framework property (File_Attachment_Controller owns it, and there is no
     * Rsx.Route() form for it because the variant segments are positional), which is why this
     * hardcoded path is sanctioned here and nowhere else.
     *
     * The mirror of File_Attachment_Model::get_thumbnail_url() / ::get_thumbnail_url_preset() in
     * PHP.
     *
     * @param {Object} record   A File_Attachment_Model record (.key, and .file_storage_id +
     *                          .file_storage for the version).
     * @param {Object} options  {type, width, height} for a dynamic variant, or {preset} for a named
     *                          preset. The two forms are mutually exclusive.
     * @returns {string}
     */
    static thumbnail_url(record, options = {}) {
        if (!record || !record.key) {
            throw new Error('File_Attachment_Model.thumbnail_url() requires an attachment record with a key');
        }

        const v = File_Attachment_Model.content_version(record);

        // The route exists in both realms; a portal page asks its own (Rsx_Portal.internal_url).
        if (options.preset) {
            return Rsx_Portal.internal_url('/_thumbnail/preset/' + record.key + '/' + options.preset + '?v=' + v);
        }

        const type = options.type || 'fit';
        const width = int(options.width || 400);

        if (options.height === undefined || options.height === null || options.height === '') {
            return Rsx_Portal.internal_url('/_thumbnail/dynamic/' + record.key + '/' + type + '/' + width + '?v=' + v);
        }

        return Rsx_Portal.internal_url('/_thumbnail/dynamic/' + record.key + '/' + type + '/' + width + '/' + int(options.height) + '?v=' + v);
    }

    /**
     * The URL that serves an attachment's ORIGINAL bytes for display in the page (the browser
     * renders it; nothing is downloaded to disk). What <Attachment_Thumbnail $allow_animated>
     * shows for an animated image, whose thumbnail is a still.
     *
     * The mirror of File_Attachment_Model::get_url() in PHP.
     *
     * @param {Object} record  A File_Attachment_Model record.
     * @returns {string}
     */
    static inline_url(record) {
        if (!record || !record.key) {
            throw new Error('File_Attachment_Model.inline_url() requires an attachment record with a key');
        }

        return Rsx_Portal.internal_url('/_inline/' + record.key + '?v=' + File_Attachment_Model.content_version(record));
    }

    /**
     * The URL that downloads an attachment's bytes to disk. The mirror of
     * File_Attachment_Model::get_download_url() in PHP.
     *
     * @param {Object} record  A File_Attachment_Model record.
     * @returns {string}
     */
    static download_url(record) {
        if (!record || !record.key) {
            throw new Error('File_Attachment_Model.download_url() requires an attachment record with a key');
        }

        return Rsx_Portal.internal_url('/_download/' + record.key + '?v=' + File_Attachment_Model.content_version(record));
    }

    /**
     * A record's CONTENT VERSION, the ?v= value of every file URL: '<file_storage_id>-<the
     * blob's rendered_at in unix seconds, or 0>', and '0-0' for a record with no resident blob.
     * The same string File_Attachment_Model::content_version() produces in PHP for the same
     * record - the storage id moves when the attachment is pointed at different bytes, and
     * rendered_at when the picture of the SAME bytes changes (a document finishing its render).
     *
     * A record that carries no file_storage_id at all (an object holding only a key) answers
     * '0-0' as well, which no attachment with bytes ever has - so its URLs work and are served
     * uncacheable.
     *
     * @param {Object} record
     * @returns {string}
     */
    static content_version(record) {
        const storage_id = record.file_storage_id ? int(record.file_storage_id) : 0;

        if (!storage_id) {
            return '0-0';
        }

        // file_storage is DATA that may be null: the embedded blob of a fetch() payload.
        const storage = record.file_storage;

        if (!storage || !storage.rendered_at) {
            return storage_id + '-0';
        }

        const ms = Rsx_Time.to_ms(storage.rendered_at);

        return storage_id + '-' + (ms === null ? 0 : Math.floor(ms / 1000));
    }
}
