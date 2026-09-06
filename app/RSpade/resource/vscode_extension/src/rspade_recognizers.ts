/**
 * RSpade recognizers - pure text analysis, NO vscode dependency.
 *
 * ONE recognizer per construct. Both sides of every feature use the functions in
 * this file: the semantic-token providers (colouring) and the definition/hover
 * providers (navigation) ask the same function for the same ranges, so a token
 * that is coloured is exactly a token that can be followed, and there is never a
 * second copy of a pattern to keep in step.
 *
 * Everything here is deterministic and side-effect free, which is what makes it
 * testable with plain node (out/test/run_recognizer_tests.js).
 *
 * Offsets are always [start, end) character offsets WITHIN THE LINE named by
 * `line` (0-based), which is the shape both vscode.Range and the definition
 * providers want.
 */

export type Recognizer_Kind = 'auth_check' | 'css_class' | 'man_topic' | 'skill';

export interface Recognized_Token {
    line: number;
    start: number;
    end: number;
    kind: Recognizer_Kind;
    value: string;
}

/** Comment syntax family used when restricting doc references to comments. */
export type Comment_Flavor = 'c_like' | 'jqhtml' | 'blade' | 'plain';

// =========================================================================
// A. AUTH CHECK NAMES
// =========================================================================

/**
 * Every quoted check name inside a PHP `#[Auth(...)]` or a JS `@auth(...)`.
 *
 * Both attributes are variadic, so each quoted argument is its own token and its
 * own navigation target. The negative lookahead after `Auth` keeps
 * `#[Auth_Realm('portal')]` and `#[Auth_Check]` out of this recognizer.
 */
export function recognize_auth_checks(line_text: string, line: number): Recognized_Token[] {
    const tokens: Recognized_Token[] = [];
    const call_pattern = /(?:#\[\s*\\?Auth(?![A-Za-z0-9_])|@auth(?![A-Za-z0-9_]))\s*\(([^)]*)\)/g;

    let call_match: RegExpExecArray | null;
    while ((call_match = call_pattern.exec(line_text)) !== null) {
        const args_text = call_match[1];
        const args_offset = call_match.index + call_match[0].lastIndexOf(args_text);

        const string_pattern = /'([^']*)'|"([^"]*)"/g;
        let string_match: RegExpExecArray | null;
        while ((string_match = string_pattern.exec(args_text)) !== null) {
            const value = string_match[1] !== undefined ? string_match[1] : string_match[2];
            if (value === '') {
                continue;
            }
            // +1 skips the opening quote so the range covers the name only.
            const start = args_offset + string_match.index + 1;
            tokens.push({ line, start, end: start + value.length, kind: 'auth_check', value });
        }
    }

    return tokens;
}

/** The three realms an #[Auth] gate can be resolved in. */
export type Auth_Realm = 'staff' | 'portal' | 'any';

/**
 * Manifest-relative roots that put a file in the portal realm.
 *
 * Mirrors Auth_ManifestSupport::PORTAL_ROOTS, respelled workspace-relative: the
 * manifest addresses framework files from system/, the editor from the project
 * root.
 */
const PORTAL_ROOTS = [
    'rsx/portal/',
    'system/app/RSpade/Core/Portal/',
    'app/RSpade/Core/Portal/',
];

/**
 * The realm a file's #[Auth] names resolve in, by the same rule the manifest
 * applies: an explicit class-level #[Auth_Realm('...')], else the portal realm
 * for a file under a portal root, else staff.
 *
 * @param relative_path Workspace-relative path, forward slashes, no leading slash
 * @param document_text The whole file
 */
export function infer_auth_realm(relative_path: string, document_text: string): Auth_Realm {
    const declared = /#\[\s*\\?Auth_Realm\s*\(\s*['"](staff|portal|any)['"]\s*\)/.exec(document_text);
    if (declared) {
        return declared[1] as Auth_Realm;
    }

    const normalized = relative_path.replace(/\\/g, '/').replace(/^\/+/, '');
    for (const root of PORTAL_ROOTS) {
        if (normalized.startsWith(root)) {
            return 'portal';
        }
    }

    return 'staff';
}

// =========================================================================
// C. .Class_Name SELECTORS
// =========================================================================

/**
 * The whole of the qualifying-token rule: PascalCase segments joined by
 * underscores, at least two segments. `.btn-primary`, `.card`, `.foo_bar` and
 * `.Foo` are all excluded, so an ordinary CSS class is never touched.
 */
export const CSS_CLASS_PATTERN = /^[A-Z][A-Za-z0-9]*(_[A-Z][A-Za-z0-9]*)+$/;

export function is_css_class_candidate(value: string): boolean {
    return CSS_CLASS_PATTERN.test(value);
}

/**
 * `.Class_Name` tokens in a line, plus the bare names inside a `class="..."`
 * attribute when `allow_class_attribute` is set (markup files).
 *
 * The lookbehind on the dotted form is what separates a selector from a property
 * access: `this.Foo_Bar` and `obj.Foo_Bar` are reads, `$(".Foo_Bar")`,
 * `.closest('.Foo_Bar')` and a bare `.Foo_Bar {` in SCSS are selectors.
 */
export function recognize_css_classes(
    line_text: string,
    line: number,
    allow_class_attribute: boolean
): Recognized_Token[] {
    const tokens: Recognized_Token[] = [];

    const dotted = /\.([A-Z][A-Za-z0-9]*(?:_[A-Z][A-Za-z0-9]*)+)/g;
    let match: RegExpExecArray | null;
    while ((match = dotted.exec(line_text)) !== null) {
        const preceding = match.index > 0 ? line_text[match.index - 1] : '';
        if (preceding !== '' && /[A-Za-z0-9_$\])]/.test(preceding)) {
            continue;
        }
        // A trailing identifier character means the match is the head of a longer
        // word (a hyphenated CSS class, say) and not a whole segment.
        const following_index = match.index + match[0].length;
        const following = following_index < line_text.length ? line_text[following_index] : '';
        if (following !== '' && /[A-Za-z0-9_-]/.test(following)) {
            continue;
        }
        const start = match.index + 1;
        tokens.push({ line, start, end: start + match[1].length, kind: 'css_class', value: match[1] });
    }

    if (allow_class_attribute) {
        const attribute = /\bclass\s*=\s*(['"])([^'"]*)\1/g;
        let attr_match: RegExpExecArray | null;
        while ((attr_match = attribute.exec(line_text)) !== null) {
            const value_text = attr_match[2];
            const value_offset = attr_match.index + attr_match[0].lastIndexOf(value_text);
            const word = /[A-Za-z0-9_-]+/g;
            let word_match: RegExpExecArray | null;
            while ((word_match = word.exec(value_text)) !== null) {
                if (!is_css_class_candidate(word_match[0])) {
                    continue;
                }
                const start = value_offset + word_match.index;
                tokens.push({
                    line,
                    start,
                    end: start + word_match[0].length,
                    kind: 'css_class',
                    value: word_match[0],
                });
            }
        }
    }

    return dedupe_overlaps(tokens);
}

// =========================================================================
// D. MAN PAGE AND SKILL REFERENCES
// =========================================================================

/**
 * Which comment syntax a file uses. `plain` means the whole file is prose - man
 * pages - so no comment restriction applies at all.
 */
export function comment_flavor_for(file_name: string, language_id: string): Comment_Flavor {
    const lower = file_name.toLowerCase();
    if (lower.endsWith('.txt')) {
        return 'plain';
    }
    if (lower.endsWith('.jqhtml')) {
        return 'jqhtml';
    }
    if (lower.endsWith('.blade.php')) {
        return 'blade';
    }
    if (language_id === 'plaintext' || language_id === 'markdown') {
        return 'plain';
    }
    return 'c_like';
}

interface Region {
    start: number;
    end: number;
}

/**
 * Character ranges of the comments in a document.
 *
 * A single forward scan; string literals are deliberately NOT tracked. The cost
 * of that simplification is that a `//` inside a string can open a phantom
 * comment to end of line, and the only consequence is that a doc reference in
 * that stretch becomes clickable. Nothing is ever coloured that does not also
 * resolve to a real file.
 */
export function comment_ranges(text: string, flavor: Comment_Flavor): Region[] {
    if (flavor === 'plain') {
        return [{ start: 0, end: text.length }];
    }

    const regions: Region[] = [];
    let i = 0;
    const length = text.length;

    while (i < length) {
        const two = text.substr(i, 2);

        if (flavor === 'jqhtml' && text.substr(i, 4) === '<%--') {
            const close = text.indexOf('--%>', i + 4);
            const end = close === -1 ? length : close + 4;
            regions.push({ start: i, end });
            i = end;
            continue;
        }

        if (flavor === 'blade' && text.substr(i, 4) === '{{--') {
            const close = text.indexOf('--}}', i + 4);
            const end = close === -1 ? length : close + 4;
            regions.push({ start: i, end });
            i = end;
            continue;
        }

        if ((flavor === 'blade' || flavor === 'jqhtml') && text.substr(i, 4) === '<!--') {
            const close = text.indexOf('-->', i + 4);
            const end = close === -1 ? length : close + 3;
            regions.push({ start: i, end });
            i = end;
            continue;
        }

        if (two === '/*') {
            const close = text.indexOf('*/', i + 2);
            const end = close === -1 ? length : close + 2;
            regions.push({ start: i, end });
            i = end;
            continue;
        }

        if (two === '//') {
            const close = text.indexOf('\n', i);
            const end = close === -1 ? length : close;
            regions.push({ start: i, end });
            i = end;
            continue;
        }

        // PHP's hash comment. `#[` opens an attribute, never a comment.
        if (text[i] === '#' && text[i + 1] !== '[') {
            const close = text.indexOf('\n', i);
            const end = close === -1 ? length : close;
            regions.push({ start: i, end });
            i = end;
            continue;
        }

        i++;
    }

    return regions;
}

function in_any_region(regions: Region[], offset: number): boolean {
    for (const region of regions) {
        if (offset >= region.start && offset < region.end) {
            return true;
        }
    }
    return false;
}

/**
 * Every man-page topic and skill name referenced in a document.
 *
 * Accepted spellings for a topic (case-insensitive on the keyword, the topic
 * itself always `[a-z][a-z0-9_]*`):
 *
 *     rsx:man topic          php artisan rsx:man topic      man topic
 *     see also topic, topic  see topic                      topic(7)
 *     topic.txt
 *
 * plus, inside a man page's own SEE ALSO section, the compact row
 * `topic - description` and the column row `topic<spaces>description`. Several
 * `rsx:man x` tokens on one line each become their own reference, which is what
 * makes a multi-column SEE ALSO grid work.
 *
 * A skill is `rspade:name`, which covers `skill rspade:name`, a backticked
 * `rspade:name` and `skill (rspade:name)` with one pattern.
 *
 * The caller decides what resolves; this function only says where the candidates
 * are.
 */
export function recognize_doc_references(document_text: string, flavor: Comment_Flavor): Recognized_Token[] {
    const regions = comment_ranges(document_text, flavor);
    const lines = document_text.split('\n');
    const tokens: Recognized_Token[] = [];

    let line_offset = 0;
    let in_see_also = false;

    for (let line = 0; line < lines.length; line++) {
        const line_text = lines[line];
        const candidates: Recognized_Token[] = [];

        if (/^\s*SEE ALSO\s*$/.test(line_text)) {
            in_see_also = true;
        } else if (in_see_also && /^[A-Z][A-Z0-9 _]*$/.test(line_text)) {
            in_see_also = false;
        }

        push_matches(candidates, line, line_text, /(?:php\s+artisan\s+)?\brsx:man\s+([a-z][a-z0-9_]*)/gi, 1, 'man_topic');
        push_matches(candidates, line, line_text, /\bman\s+([a-z][a-z0-9_]*)/gi, 1, 'man_topic');
        // The two lookarounds keep `see rsx:man tasks` and `see man tasks` out of
        // this pattern: the topic of a `see` reference is a bare topic, never the
        // head of a `rsx:man` / `rspade:` reference and never the keyword `man`.
        push_list_matches(candidates, line, line_text, /\bsee(?:\s+also)?\s+(?!man\b)([a-z][a-z0-9_]*(?:\s*,\s*[a-z][a-z0-9_]*)*)(?![a-z0-9_]*:)/gi);
        push_matches(candidates, line, line_text, /\b([a-z][a-z0-9_]*)\(\d\)/g, 1, 'man_topic');
        push_matches(candidates, line, line_text, /\b([a-z][a-z0-9_]*)\.txt\b/g, 1, 'man_topic');
        push_matches(candidates, line, line_text, /\brspade:([a-z][a-z0-9-]*)/g, 1, 'skill');

        if (in_see_also) {
            // An indented row whose first word is followed by more text: covers both
            // `topic - description` and the column form `topic<spaces>description`,
            // whose gap narrows to a single space when the topic is long. A wrapped
            // continuation line ends after its last word, so it never matches.
            push_matches(candidates, line, line_text, /^\s+([a-z][a-z0-9_]*)\s+\S/g, 1, 'man_topic');
        }

        for (const token of dedupe_overlaps(candidates)) {
            if (flavor === 'plain' || in_any_region(regions, line_offset + token.start)) {
                tokens.push(token);
            }
        }

        line_offset += line_text.length + 1;
    }

    return tokens;
}

function push_matches(
    into: Recognized_Token[],
    line: number,
    line_text: string,
    pattern: RegExp,
    group: number,
    kind: Recognizer_Kind
): void {
    let match: RegExpExecArray | null;
    while ((match = pattern.exec(line_text)) !== null) {
        const value = match[group];
        const start = match.index + match[0].lastIndexOf(value);
        into.push({ line, start, end: start + value.length, kind, value });
        if (match.index === pattern.lastIndex) {
            pattern.lastIndex++;
        }
    }
}

/** `see also a, b, c` - each comma-separated topic becomes its own token. */
function push_list_matches(into: Recognized_Token[], line: number, line_text: string, pattern: RegExp): void {
    let match: RegExpExecArray | null;
    while ((match = pattern.exec(line_text)) !== null) {
        const list_text = match[1];
        const list_offset = match.index + match[0].lastIndexOf(list_text);
        const topic = /[a-z][a-z0-9_]*/g;
        let topic_match: RegExpExecArray | null;
        while ((topic_match = topic.exec(list_text)) !== null) {
            const start = list_offset + topic_match.index;
            into.push({ line, start, end: start + topic_match[0].length, kind: 'man_topic', value: topic_match[0] });
        }
    }
}

/**
 * Keep the earliest token of any overlapping group. Recognizer patterns overlap
 * by design - `rsx:man tasks` is matched by the rsx:man form and by the bare
 * `man` form - and the more specific one always starts first.
 */
export function dedupe_overlaps(tokens: Recognized_Token[]): Recognized_Token[] {
    const sorted = tokens.slice().sort((a, b) => (a.line - b.line) || (a.start - b.start) || (b.end - a.end));
    const kept: Recognized_Token[] = [];

    for (const token of sorted) {
        const previous = kept.length > 0 ? kept[kept.length - 1] : undefined;
        if (previous && previous.line === token.line && token.start < previous.end) {
            continue;
        }
        kept.push(token);
    }

    return kept;
}

/** The token whose range covers `character` on `line`, if any. */
export function token_at(tokens: Recognized_Token[], line: number, character: number): Recognized_Token | undefined {
    for (const token of tokens) {
        if (token.line === line && character >= token.start && character <= token.end) {
            return token;
        }
    }
    return undefined;
}

// =========================================================================
// E. FRAMEWORK-PROPERTY GATE
// =========================================================================

/**
 * One key out of .env text, phpdotenv semantics: first definition wins, a
 * commented line is not a definition, surrounding quotes are stripped.
 * Returns null when the key is absent.
 */
export function parse_env_value(env_text: string, key: string): string | null {
    for (const raw_line of env_text.split(/\r?\n/)) {
        const line = raw_line.trim();
        if (line.startsWith('#') || !line.startsWith(key + '=')) {
            continue;
        }
        let value = line.substring(key.length + 1).trim();
        if ((value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))) {
            value = value.substring(1, value.length - 1);
        }
        return value;
    }
    return null;
}

/**
 * Is system/ framework property in this workspace?
 *
 * ON everywhere except a framework-developer tree, and the ONLY thing that turns
 * it off is IS_FRAMEWORK_DEVELOPER being exactly `true` (case-insensitive,
 * trimmed). A missing .env, an unreadable one, an absent key and every other
 * value all leave the protection on, which is the safe direction: the cost of a
 * false positive is a warning, the cost of a false negative is an edit that the
 * next framework update silently destroys.
 */
export function framework_property_gate_is_on(env_text: string | null): boolean {
    if (env_text === null) {
        return true;
    }
    const value = parse_env_value(env_text, 'IS_FRAMEWORK_DEVELOPER');
    if (value === null) {
        return true;
    }
    return value.trim().toLowerCase() !== 'true';
}
