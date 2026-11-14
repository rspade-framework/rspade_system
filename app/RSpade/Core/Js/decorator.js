/**
 * Decorator function that marks a function as a decorator implementation.
 *
 * When a function has @decorator in its JSDoc comment, it whitelists that function
 * to be used as a decorator on classes and methods throughout the codebase.
 *
 * The function itself performs no operation - it simply returns its input unchanged.
 * Its purpose is purely as a marker for the manifest validation system.
 *
 * Usage:
 *   // /**
 *   //  * My custom decorator implementation
 *   //  * @decorator
 *   //  *\/
 *   function my_custom_decorator(value, context) {
 *       // value: the decorated method (or class); context: {kind, name, static, ...}
 *       // Return a replacement, or nothing to keep the original.
 *   }
 *
 * This allows my_custom_decorator to be used as @my_custom_decorator on classes and on
 * class methods, static and instance - the manifest build refuses any other decorator
 * (JS-DECORATOR-01). Decorators follow the TC39 2023-11 proposal: rsx:man js_decorators.
 *
 * TODO: This is probably no longer necessary? maybe?
 */
function decorator(value) {
    return value;
}
