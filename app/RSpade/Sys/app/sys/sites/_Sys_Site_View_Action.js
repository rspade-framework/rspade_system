/**
 * _Sys_Site_View_Action - one tenant site. See the .jqhtml for the layout.
 *
 * Rename opens _Sys_Site_Rename_Form.open(site) and reloads the record. Disable and
 * Enable confirm first, stating the consequence in members, then call
 * _Sys_Sites_Controller.set_enabled and reload. The server decides what is allowed
 * (site 0 and soft-deleted sites are not_found; the acting session's own site cannot
 * be disabled) and refuses the rest.
 */
@route('/_sys/sites/:id')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Site')
class _Sys_Site_View_Action extends Spa_Action {
    on_create() {
        this.data.site = null;
        this.data.load_error = null;
        this.data.loading = true;
    }

    async on_load() {
        try {
            const response = await _Sys_Sites_Controller.detail({ id: this.args.id });
            this.data.site = response.site;
        } catch (e) {
            // Plain data: this.data keeps no Error object, so the code and the message
            // are copied out. A not_found code renders the missing-record state.
            if (e.code !== Ajax.ERROR_NOT_FOUND) {
                console.error(e);
            }
            this.data.load_error = { code: e.code || null, message: e.message || String(e) };
        }
        this.data.loading = false;
    }

    page_title() {
        // The id, not the name: a rename reloads the record but does not repaint the
        // layout's title.
        return 'Site #' + this.args.id;
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_site_view');

        this.$.on('click._sys_site_view', '[data-action="rename"]', async function () {
            if (await _Sys_Site_Rename_Form.open(that.data.site)) {
                that.reload();
            }
        });

        this.$.on('click._sys_site_view', '[data-action="disable"], [data-action="enable"]', async function () {
            const enable = $(this).attr('data-action') === 'enable';
            const site = that.data.site;
            const n = site.enabled_member_count;
            const members = n === 1 ? '1 member' : n + ' members';

            const confirmed = enable
                ? await _Sys_Modal.confirm(
                    'Enable ' + site.name + '?',
                    members + ' with an enabled membership can sign in to this site again.',
                    'Enable'
                )
                : await _Sys_Modal.confirm(
                    'Disable ' + site.name + '?',
                    members + ' will be signed out on their next request, and nobody can sign in to this site until it is enabled again. ' +
                    'API keys held by its members stop working too. Nothing is deleted.',
                    'Disable'
                );

            if (!confirmed) {
                return;
            }

            await _Sys_Sites_Controller.set_enabled({ id: site.id, enabled: enable });
            Flash_Alert.success(site.name + (enable ? ' is enabled.' : ' is disabled.'));
            that.reload();
        });
    }
}
