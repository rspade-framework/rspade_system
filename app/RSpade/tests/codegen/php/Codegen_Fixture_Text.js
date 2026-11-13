/**
 * Codegen_Fixture_Text - the browser half of the codegen test fixture text type. A text type
 * is two classes of one name (TEXT-TYPE-01); this one is never rendered, so the components it
 * names are those of the shipped plain-text type's shape.
 */
class Codegen_Fixture_Text extends Rsx_Text_Abstract {
    static PRINTER = 'Codegen_Fixture_Text_Display';

    static EDITOR = 'Codegen_Fixture_Text_Input';
}
