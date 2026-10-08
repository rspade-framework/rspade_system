/**
 * Rsx_Shaken_Component - what a debug build puts in place of a component the sealed build
 * removed from the bundle.
 *
 * A sealed build keeps only the components something in the bundle names
 * (Bundle_Component_Shaker). A component reached by a name the build could not see - one
 * assembled at run time - is removed, and would then render as an empty element. In a debug
 * build every removed name is registered as a subclass of this class (Jqhtml_Integration),
 * so asking for one throws here, at the earliest lifecycle step, with the message the build
 * wrote for it. Nothing is registered in development (nothing is removed) or in production
 * (the names are withheld).
 */
class Rsx_Shaken_Component extends Component {
    on_create() {
        throw new Error(Manifest._shaken_component_message.replace('{component}', this.constructor.shaken_name));
    }
}
