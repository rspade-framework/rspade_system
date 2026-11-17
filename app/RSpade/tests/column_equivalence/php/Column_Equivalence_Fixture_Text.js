/**
 * Column_Equivalence_Fixture_Text - the browser half of the column-equivalence fixture text
 * type. A text type is two classes of one name (TEXT-TYPE-01); this one is never rendered (the
 * browser vector runner declares its own copy at run time, since the test tree is not served),
 * so the components it names are those of the shipped plain-text type's shape.
 */
class Column_Equivalence_Fixture_Text extends Rsx_Text_Abstract {
    static PRINTER = 'Column_Equivalence_Fixture_Text_Display';

    static EDITOR = 'Column_Equivalence_Fixture_Text_Input';

    static sanitize_encoded(raw) {
        return raw.replace(/\s+/g, ' ').trim();
    }
}
