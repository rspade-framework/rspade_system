// Case (f): decorated class DECLARATION whose only decorator payload is metadata the
// framework reads back off the class - @title stores the string as `_spa_title`, which
// Spa_Action.page_title()/get_static_title() read. If the transform were to drop or
// reorder the class-decorator application, the title system would silently fall back to
// "(title not set)" with no build signal.
@route("/fixture/title")
@layout("Fixture_Spa_Layout")
@spa("Fixture_Spa_Controller::index")
@title("Fixture Title")
class Fixture_Title_Action extends Spa_Action {

    static TABS = ["overview"];
}
