/**
 * _Sys_Site_Rename_Form - the Rename dialog on the site detail screen. See
 * _Sys_Site_Rename_Form.jqhtml.
 */
class _Sys_Site_Rename_Form extends Component {
    /**
     * Ask for a new name and slug and save them.
     *
     * @param {object} site The detail payload's site
     * @returns {Promise<object|false>} {site} after a rename; false when cancelled
     */
    static async open(site) {
        const result = await _Sys_Modal.form({
            title: 'Rename site #' + site.id,
            component: '_Sys_Site_Rename_Form',
            component_args: { site: site },
            submit_label: 'Save',
        });

        if (result) {
            Flash_Alert.success('Saved as ' + result.site.name + '.');
        }

        return result;
    }
}
