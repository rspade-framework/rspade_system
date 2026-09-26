/**
 * _Sys_User_Sign_In_As_Form - the "Sign in as this user" dialog. See the .jqhtml.
 */
class _Sys_User_Sign_In_As_Form extends Component {
    /**
     * Ask which site, begin the impersonation, and leave the panel with a full page load
     * (the application's bundle replaces the panel's, so this is never an SPA dispatch).
     *
     * @param {object} user The detail payload's user: {id, email, status_label}
     * @param {object[]} memberships The detail payload's memberships (is_active is read)
     * @returns {Promise<boolean>} false when cancelled; true once the page is leaving
     */
    static async open(user, memberships) {
        const sites = memberships.filter((row) => row.is_active).map((row) => ({
            value: row.site_id,
            label: (row.site_name || 'Site') + ' (site #' + row.site_id + ')',
        }));

        const result = await _Sys_Modal.form({
            title: 'Sign in as ' + user.email + '?',
            component: '_Sys_User_Sign_In_As_Form',
            component_args: {
                login_user_id: user.id,
                email: user.email,
                status_label: user.status_label,
                sites: sites,
            },
            submit_label: 'Sign in as this user',
        });

        if (!result) {
            return false;
        }

        window.location.href = result.destination;

        return true;
    }
}
