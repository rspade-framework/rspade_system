/**
 * Literal escaping for the generated code samples.
 *
 * A sample is a program the reader will paste into a shell or a file, so every value put into
 * one has to survive being read by THAT language. A key with a quote in it, or a search term
 * with a $ or a backtick, must come out the other side as the same bytes - otherwise the
 * sample is not merely ugly, it is a different request from the one the tester made, and in a
 * shell it can be a command.
 *
 * DOUBLE ESCAPING IS THE SUBTLE PART, and it is why these are separate from the URL encoding.
 * A query value inside a curl command is transformed TWICE, in order: percent-encoded because
 * it is going into a URL, then quoted for the shell because the URL is going into a command
 * line. Do only the first and a space breaks the command; do only the second and the server
 * receives something else. Call sites apply api_url_value() first, then the language escaper.
 */

/**
 * Percent-encode one value for a URL query string or path segment.
 *
 * encodeURIComponent leaves ! ' ( ) * alone - legal in a query, but they are also shell and
 * regex metacharacters, and a sample is safer without them. Encoding more than required is
 * always valid; encoding less is sometimes wrong.
 */
function api_url_value(value) {
    return encodeURIComponent(str(value)).replace(/[!'()*]/g, (c) =>
        '%' + c.charCodeAt(0).toString(16).toUpperCase()
    );
}

/**
 * A single-quoted POSIX shell literal, for bash/sh/curl.
 *
 * Inside single quotes a shell interprets NOTHING - no $, no backtick, no backslash - so the
 * only character that needs handling is the single quote itself, which cannot be escaped
 * inside single quotes at all. The standard trick is to end the quoted run, emit an escaped
 * quote, and start a new one: don't  ->  'don'\''t'.
 *
 * Returns the quotes as well as the content, because a bare escaped string is not safe to
 * drop into a command without them.
 */
function api_bash_literal(value) {
    return "'" + str(value).split("'").join("'\\''") + "'";
}

/**
 * A single-quoted PHP literal.
 *
 * PHP single quotes honour exactly two escapes, \\' and \\\\, and treat everything else
 * literally - no variable interpolation, no \n. So backslash must be doubled FIRST (or the
 * backslash added for a quote would itself be re-escaped), then the quote.
 */
function api_php_literal(value) {
    return "'" + str(value).split('\\').join('\\\\').split("'").join("\\'") + "'";
}

/**
 * A double-quoted Python literal.
 *
 * json.dumps of a string produces exactly a valid Python string literal - Python's string
 * syntax is a superset of JSON's for scalars - so this borrows JSON's escaping rather than
 * reimplementing \\uXXXX handling by hand and getting an edge case wrong.
 */
function api_python_literal(value) {
    return JSON.stringify(str(value));
}

/**
 * A single-quoted PowerShell literal.
 *
 * PowerShell interpolates inside double quotes ($var, subexpressions) but not inside single
 * quotes, where the ONLY special character is the single quote, escaped by doubling it.
 * Single quotes are therefore the safe form for arbitrary values.
 */
function api_powershell_literal(value) {
    return "'" + str(value).split("'").join("''") + "'";
}

/**
 * A JavaScript literal. JSON's string syntax is valid JS, so this is json_encode.
 */
function api_js_literal(value) {
    return JSON.stringify(str(value));
}

/**
 * Mark an unfilled path placeholder so a sample SHOWS what is missing.
 *
 * The placeholder is left in the sample rather than substituted with something plausible: a
 * sample that silently invented an id would look ready to run and would not be. Rendered as
 * raw HTML by the caller, so the value is escaped here.
 */
function api_missing_marker(name) {
    return '<span class="Api_Code_Samples__missing" title="' + html(name) + ' is required">{'
        + html(name) + '}</span>';
}
