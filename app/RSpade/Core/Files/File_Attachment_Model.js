/**
 * File_Attachment_Model (JavaScript) - the concrete ORM class over the generated
 * Base_File_Attachment_Model stub.
 *
 * It exists for ONE reason: thumbnail_url() below is THE place a thumbnail URL is spelled in
 * JavaScript. Nothing else in the framework or in app code builds one - not a template, not a
 * controller payload, not a page script. <Attachment_Thumbnail> calls it; everything else calls
 * <Attachment_Thumbnail>.
 */
class File_Attachment_Model extends Base_File_Attachment_Model {
    /**
     * Build the thumbnail URL for an attachment record.
     *
     * THE ONLY PLACE '/_thumbnail/' APPEARS IN JAVASCRIPT. The route shape is framework property
     * (File_Attachment_Controller owns it, and there is no Rsx.Route() form for it because the
     * variant segments are positional), which is why this one hardcoded path is sanctioned and
     * every other one is not.
     *
     * The mirror of File_Attachment_Model::get_thumbnail_url() / ::get_thumbnail_url_preset() in
     * PHP, including the ?v= cache-buster: v is the blob's rendered_at as unix seconds, or 0 when
     * nothing has rendered yet. The server IGNORES v - it is purely a browser cache key. Real
     * renders are served with a one-year max-age, so without it a browser that once saw the
     * extension-icon placeholder would keep showing it for a year after the document rendered.
     *
     * @param {Object} record   A File_Attachment_Model record (needs .key, and .file_storage for v).
     * @param {Object} options  {type, width, height} for a dynamic variant, or {preset} for a named
     *                          preset. The two forms are mutually exclusive.
     * @returns {string}
     */
    static thumbnail_url(record, options = {}) {
        if (!record || !record.key) {
            throw new Error('File_Attachment_Model.thumbnail_url() requires an attachment record with a key');
        }

        const v = File_Attachment_Model.thumbnail_version(record);

        if (options.preset) {
            return '/_thumbnail/preset/' + record.key + '/' + options.preset + '?v=' + v;
        }

        const type = options.type || 'fit';
        const width = int(options.width || 400);

        if (options.height === undefined || options.height === null || options.height === '') {
            return '/_thumbnail/dynamic/' + record.key + '/' + type + '/' + width + '?v=' + v;
        }

        return '/_thumbnail/dynamic/' + record.key + '/' + type + '/' + width + '/' + int(options.height) + '?v=' + v;
    }

    /**
     * The ?v= value for a record: the embedded blob's rendered_at in unix seconds, 0 when the blob
     * has never rendered (or is not embedded in this payload). Mirrors the PHP
     * __thumbnail_version(), including reading rendered_at as an ISO string through Rsx_Time.
     *
     * @param {Object} record
     * @returns {number}
     */
    static thumbnail_version(record) {
        const storage = record ? record.file_storage : null;

        if (!storage || !storage.rendered_at) {
            return 0;
        }

        const ms = Rsx_Time.to_ms(storage.rendered_at);

        return ms === null ? 0 : Math.floor(ms / 1000);
    }
}
