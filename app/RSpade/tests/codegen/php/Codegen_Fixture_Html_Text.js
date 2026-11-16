/**
 * Codegen_Fixture_Html_Text - the browser half of the codegen test fixture HTML text type. A
 * text type is two classes of one name (TEXT-TYPE-01); this one is never rendered.
 */
class Codegen_Fixture_Html_Text extends Rsx_Text_Abstract {
    static PRINTER = 'Codegen_Fixture_Html_Text_Display';

    static EDITOR = 'Codegen_Fixture_Html_Text_Input';
}
