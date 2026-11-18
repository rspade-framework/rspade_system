/**
 * @Replaceable - the JS-PARENT-CHAIN-01 opt-out, the JavaScript twin of PHP's #[Replaceable].
 *
 * A method that overrides a method declared by an ancestor class in the manifest must call
 * super.<same name>(...) in its body (JS-PARENT-CHAIN-01, a manifest-build fatal). Placed on
 * the PARENT's method, @Replaceable declares that method a hook overriders replace outright:
 * an override of it owes no super call.
 *
 *   class Spa_Layout extends Component {
 *       @Replaceable
 *       async on_action(url, action_name, args) {}
 *   }
 *
 * The anchor is the NEAREST ancestor declaring the method: a class that re-declares it
 * without @Replaceable re-establishes the obligation for its own descendants.
 *
 * At run time it is a marker and nothing more - the method is returned unchanged. The
 * manifest build reads it off the class's parse. Applies to instance and static methods.
 *
 * @decorator
 */
function Replaceable(value, context) {
    if (context.kind !== 'method') {
        throw new Error(`@Replaceable can only be applied to methods, not ${context.kind}`);
    }

    return value;
}
