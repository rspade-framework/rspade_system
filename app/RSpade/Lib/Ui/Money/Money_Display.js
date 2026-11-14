/**
 * Money_Display - an amount of money, nothing stored, or redacted. See Money_Display.jqhtml.
 */
class Money_Display extends Component {
    on_create() {
        const value = this.args.value;

        if (this.args.redacted !== true && value !== null && value !== undefined && !(value instanceof Rsx_Money)) {
            shouldnt_happen(
                `Money_Display $value must be an Rsx_Money or null, but was given ${typeof value}. ` +
                `An amount reaches the browser as an Rsx_Money (a money column, or Rsx_Money.from_decimal()).`
            );
        }
    }
}
