// Framework-application name, UNDECORATED: a single leading underscore is the reserved
// framework prefix (App\RSpade\Core\Naming\Rsx_Identifier). The prefix plugin decides
// generated-ness by PROVENANCE, so this authored top-level class must survive the
// transform with its name intact rather than being renamed to `_<fileHash>_Fixture_...`.
class _Fixture_Sys_Sidebar extends Component {

    static WIDTH = 240;

    get_width() {
        return _Fixture_Sys_Sidebar.WIDTH;
    }
}
