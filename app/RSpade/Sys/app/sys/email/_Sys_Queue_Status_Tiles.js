/**
 * _Sys_Queue_Status_Tiles - see _Sys_Queue_Status_Tiles.jqhtml.
 */
class _Sys_Queue_Status_Tiles extends Component {
    on_create() {
        if (!Array.isArray(this.args.counts) || !this.args.tab || !this.args.grid_key) {
            throw new Error('_Sys_Queue_Status_Tiles requires $counts, $tab and $grid_key');
        }
    }
}
