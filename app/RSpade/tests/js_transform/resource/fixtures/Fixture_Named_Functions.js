// Function NAMES through minification. A top-level function declaration, and a named
// function nested inside an IIFE - the nested one is in mangle scope, so a minifier
// without keep_fnames renames it to a single letter. The minified-names harness mode reads
// `.name` off both and expects the authored spelling.
function fixture_top_level_function() {
    return 42;
}

const Fixture_Named_Functions = (function () {
    function fixture_inner_helper(value) {
        return value + 1;
    }

    return {
        top: fixture_top_level_function,
        inner: fixture_inner_helper,
    };
})();
