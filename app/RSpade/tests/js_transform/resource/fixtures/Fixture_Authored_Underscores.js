// A non-class file whose author deliberately named top-level bindings with a leading
// underscore. Provenance, not name shape: neither may be renamed by the prefix plugin.
const _CONST = 42;

function _helper(value) {
    return value + _CONST;
}

const authored_result = _helper(1);
