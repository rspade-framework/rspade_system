/**
 * _Sys_Companions_Action - the companion applications. See the .jqhtml.
 *
 * Refreshes on every Companion_Changed_Topic frame (an empty filter: every companion), through
 * one debounced refresh (Rsx_Companion.LIVE_UPDATE_DELAY), so a companion starting, stopping or
 * changing its status line moves on the screen without a reload.
 */
@route('/_sys/companions')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Companions')
class _Sys_Companions_Action extends Spa_Action {
    on_create() {
        this.data.overview = null;
        this.data.error_data = null;

        const that = this;
        const refresh_soon = debounce(() => {
            if (that.is_stopped()) return;
            return that.refresh();
        }, Rsx_Companion.LIVE_UPDATE_DELAY);
        this.subscribe('Companion_Changed_Topic', {}, () => refresh_soon());
    }

    async on_load() {
        try {
            this.data.overview = await _Sys_Companions_Controller.overview();
        } catch (e) {
            this.data.error_data = e;
        }
    }

    on_ready() {
        const that = this;

        this.$.off('click._sys_companions');
        this.$.on('click._sys_companions', '[data-action="refresh"]', function () {
            that.reload();
        });
        this.$.on('click._sys_companions', 'tr[data-companion]', function (e) {
            const $element = $(this);
            e.preventDefault();
            _Sys_Companion_Detail.open(str($element.attr('data-companion')));
        });
        _Sys_Companion_Actions.on_acted_within(this.$, () => that.refresh());
    }
}
