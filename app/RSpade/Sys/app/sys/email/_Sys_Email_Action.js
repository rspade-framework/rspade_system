/**
 * _Sys_Email_Action - the control panel's Email & SMS screen: the two outbound queues,
 * install-wide, one tab each.
 *
 * A table of contents over self-loading regions (see the .jqhtml). The shell owns the
 * one link between them: a plain click on a status tile (_Sys_Queue_Status_Tiles, which
 * names its grid in data-grid-key) narrows that grid to the status in place; a modified
 * click (new tab) follows the tile's link.
 *
 * Addressable state: #tab=email|sms, plus the grids' mail_* and sms_* hash keys - the
 * Dashboard's pending-mail tile links here as {tab: 'email', mail_f_status: 'pending'}.
 * Email is the default tab, so a link carrying only mail_* keys lands on it too.
 */
@route('/_sys/email')
@layout('_Sys_Layout')
@spa('_Sys_Spa_Controller::index')
@auth('is_sysadmin')
@title('Email & SMS')
class _Sys_Email_Action extends Spa_Action {
    on_ready() {
        const that = this;
        const grids = { mail: 'mail_grid', sms: 'sms_grid' };

        this.$.off('click._sys_email').on('click._sys_email', '[data-grid-key] ._Sys_Stat_Tile__link', function (e) {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) {
                return;
            }

            const $tile = $(this).closest('[data-grid-key]');
            e.preventDefault();
            that.sid(grids[$tile.attr('data-grid-key')]).set_filter('status', $tile.attr('data-status'));
        });
    }
}
