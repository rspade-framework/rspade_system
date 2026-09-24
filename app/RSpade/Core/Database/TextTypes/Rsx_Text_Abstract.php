<?php

namespace App\RSpade\Core\Database\TextTypes;

use App\RSpade\Core\Database\TextTypes\Rsx_Text_Request_Value;

/**
 * The base of every declared TEXT column type.
 *
 * A text value is a block of user-authored content whose ENCODING is a property of the
 * column rather than of any one call site. Without this, every place that prints, edits,
 * exports or indexes a column has to independently remember what kind of string it holds
 * and reach for the matching escape, widget or filter - and the failure mode of forgetting
 * is either a broken render or an XSS, neither of which announces itself.
 *
 * Declaring the column's type on the model moves that knowledge to one place and lets the
 * value carry it:
 *
 *     public static $text_types = [
 *         'description' => Rich_Text::class,
 *         'notes'       => Raw_Text::class,
 *     ];
 *
 * A value is IMMUTABLE and carries its own storage form. It enters through exactly two
 * doors - from_storage() trusts the database, everything else routes through
 * from_untrusted() and is filtered - and there is no third way to construct one. A BARE
 * STRING is plain text: from_string() escapes it into the encoding first (escape_string())
 * and then takes the same filtered door.
 *
 * A column with NO declaration keeps today's behaviour exactly: a naked PHP string, no
 * object, no filtering, nothing to migrate.
 *
 * ── What a type must supply, and what it merely may ─────────────────────────────────
 *
 * TWO methods are required: filter_set(), the trust boundary, and escape_string(), the
 * plain-text conversion every bare string goes through. Everything else is a
 * CONVENTION - a predictable name application code can rely on when a type chooses to
 * offer the capability - and throws by default so that asking a type for a rendition it
 * never defined fails loudly instead of guessing.
 *
 * to_html() is a STATIC rendition for a server-generated document: an email, a PDF, an
 * export. Nothing in the framework calls it. A live page renders through the type's
 * PRINTER component in the browser, and that is the only rendering most types ever need;
 * define to_html() only for the specific case where markup must be produced server-side.
 *
 * to_text() is the value as readable text: a CSV cell, an index, an excerpt. Also never
 * called by the framework. A type whose plain form means resolving references against the
 * database may reasonably never do that on the server, and is not made to carry the
 * method to exist. When it does exist it lives HERE and has no JavaScript counterpart:
 * the server can resolve synchronously, the browser cannot, and rather than be right in
 * one language and approximate in the other the browser throws on string coercion. So
 * does the server: to_text(), to_html() and is_empty() are the named ways to ask.
 *
 * is_empty() is the one question every type answers, because assign-then-validate in
 * every endpoint depends on it. Its default is the raw form; a wrapping encoding overrides.
 *
 * @see rsx/lib/text_types/ for the shipped implementations.
 */
#[Instantiatable]
abstract class Rsx_Text_Abstract implements \JsonSerializable, \Stringable
{
    /**
     * The value exactly as it is stored in the column.
     *
     * @var string
     */
    protected string $raw;

    /**
     * Private to the construction helpers below. A value is never built by hand: every
     * path has to declare whether its input is trusted, and the constructor is where that
     * distinction would be lost.
     *
     * @param string $raw
     */
    final protected function __construct(string $raw)
    {
        $this->raw = $raw;
    }

    // =========================================================================
    // CONSTRUCTION - never overridden. The trust boundary lives here.
    // =========================================================================

    /**
     * Hydrate from the database - the TRUSTED door.
     *
     * Public, because a caller holding content that came out of the column (a stored
     * snapshot reduced to text, say) legitimately needs it, and UNFILTERED: whatever is
     * handed in is stored and rendered byte for byte. Anything that did not come out of
     * the column - a request, an import, an API caller - goes through from_untrusted();
     * handing it here instead is a stored-XSS path.
     *
     * The stored content is TRUSTED: it was filtered by from_untrusted() on the way in,
     * so a read performs no filtering at all. That matters - re-purifying on every read
     * would put an HTMLPurifier pass on every row of every list, and it would mean the
     * database is knowingly holding unsafe content that only looks safe because every
     * reader remembered to clean it.
     *
     * @param string|null $raw
     * @return static|null
     */
    final public static function from_storage(?string $raw): ?static
    {
        return $raw === null ? null : new static($raw);
    }

    /**
     * The ONLY door for content from a request, an import or an API caller.
     *
     * @param string $raw
     * @return static
     */
    final public static function from_untrusted(string $raw): static
    {
        return new static(static::filter_set($raw));
    }

    /**
     * The value as the column stores it.
     *
     * @return string
     */
    final public function to_storage(): string
    {
        return $this->raw;
    }

    // =========================================================================
    // WHAT A CONCRETE TYPE SUPPLIES - filter_set() and escape_string() are required;
    // the rest are CONVENTIONS a type adopts only when it needs the capability.
    // =========================================================================

    /**
     * The value as readable text, with every trace of the encoding removed - a CSV cell,
     * a search index, an excerpt. OPTIONAL; the default throws.
     *
     * A CONVENTION, not a requirement. Nothing in the framework calls this; it exists so
     * application code needing a plain rendition of ANY text type has one predictable name.
     * Implement it when your type needs that. Many never will: resolving tagged entities to
     * their names is database work a server may have no reason to do.
     *
     * When you do implement it: strip a marker, never the content it referred to.
     *
     * @return string
     */
    #[Replaceable]
    public function to_text(): string
    {
        throw new \LogicException(
            class_basename(static::class) . ' does not define to_text(). A plain-text rendition '
            . 'is optional - implement to_text() on the type if an export or an index needs one.'
        );
    }

    /**
     * A STATIC HTML rendition for a server-generated document - an email, a PDF, an
     * export. OPTIONAL; the default throws.
     *
     * A CONVENTION, not a requirement. Nothing in the framework calls this: a live page
     * renders through the type's PRINTER component, and that is the only rendering most
     * types ever need. Rule of thumb: rarely necessary - define it only for the specific
     * situation where markup must be produced on the server. A type only ever shown on a
     * page has no reason to carry it.
     *
     * @return string
     */
    #[Replaceable]
    public function to_html(): string
    {
        throw new \LogicException(
            class_basename(static::class) . ' does not define to_html(). A server-side HTML '
            . 'rendition is optional and rarely needed - implement to_html() on the type only '
            . 'if a document or export must render it on the server. A page renders it through '
            . 'the PRINTER component.'
        );
    }

    /**
     * The write filter: applied to every value that did not come from the database, and
     * REQUIRED of every type, even one whose filter is a passthrough.
     *
     * This is the type's trust boundary and the reason stored content can be trusted on
     * read. A type whose encoding permits markup sanitizes here; a type whose encoding is
     * structured validates here and throws on malformed input. It is abstract rather than
     * defaulted so that a type cannot inherit "no filter" by omission - a passthrough has to
     * be written down, with the reason it is safe beside it.
     *
     * CONTRACT: a pure string transform. No file or network I/O, no deserialization, no
     * dynamic dispatch on the input, nothing that runs a command. This is the one
     * application-authored function that stands directly in front of untrusted input, and
     * the input is chosen by whoever is on the other end of the connection.
     *
     * PUBLIC, not protected: a protected static under app/RSpade would have to be named
     * `__filter_set` (PHP-RSPADE-01), and a `__`-prefixed framework name is reserved - an
     * application type could not name it to implement it.
     *
     * @param string $raw
     * @return string
     */
    abstract public static function filter_set(string $raw): string;

    /**
     * Plain text -> this type's encoding: the ESCAPE, REQUIRED of every type.
     *
     * A bare string carries no encoding, so the one thing it can honestly be taken to be is
     * plain text. This is what makes that true: every bare string assigned to a declared
     * column - an import, a seed, a CLI script, an /api/vN call, a plain form field - is
     * converted by it, and the type's filter_set() then runs on the result. For an HTML
     * type this is `'<p>' . nl2br(htmlspecialchars($plain)) . '</p>'`, so `<`, `&` and line
     * breaks survive as the text they were instead of being read as markup.
     *
     * Abstract for the same reason filter_set() is: a type whose encoding IS plain text
     * writes the passthrough down, with the reason beside it, rather than inheriting one.
     *
     * CONTRACT: a pure string transform, exactly as filter_set().
     *
     * @param string $plain
     * @return string
     */
    abstract public static function escape_string(string $plain): string;

    /**
     * Plain text -> a value of this type: escape_string(), then filter_set().
     *
     * What the cast does with a bare string, and the explicit spelling for code that wants
     * the typed value without a column to assign it to. Final so that no type can build a
     * value from plain text without its filter running.
     *
     * @param string $plain
     * @return static
     */
    final public static function from_string(string $plain): static
    {
        return static::from_untrusted(static::escape_string($plain));
    }

    // =========================================================================
    // DEFAULTS
    // =========================================================================

    /**
     * String coercion is REFUSED.
     *
     * A typed value used as a string is a typed value used wrongly, and the exception is
     * the signal. The case that settles it is not a read but a write:
     *
     *     $copy->notes = $original->notes . "\n\nappended";
     *
     * Silent coercion would strip the markup, concatenate, and hand a plain string to the
     * cast, which would store it looking entirely plausible - the exact failure this whole
     * system exists to prevent. Every legitimate need has a named method: to_text() for a
     * CSV cell, a search index or a length check; to_html() for a document; is_empty() for
     * validation. Say which one you mean.
     *
     * @return string
     */
    final public function __toString(): string
    {
        throw new \LogicException(
            class_basename(static::class) . ' cannot be used as a string. Call ->to_text() '
            . 'for its readable text, ->to_html() for a document rendition, ->is_empty() to '
            . 'test for content, or assign it to its column.'
        );
    }

    /**
     * Whether the value carries no content. The blessed spelling for an emptiness test:
     * `$value === ''` is FALSE for an object no matter what it holds, silently.
     *
     * @return bool
     */
    #[Replaceable]
    public function is_empty(): bool
    {
        // The RAW form, deliberately not to_text(): to_text() is optional, and emptiness
        // is the one question every type must answer, because assign-then-validate in
        // every endpoint depends on it. A type whose encoding WRAPS its content overrides
        // this - an emptied WYSIWYG stores <p><br></p>, a non-empty string holding an
        // empty document (see Rich_Text).
        return trim($this->raw) === '';
    }

    /**
     * Turn every {__TEXT, raw} envelope in a decoded request body into a typeless
     * Rsx_Text_Request_Value, recursively, leaving everything else untouched.
     *
     * Called once at the Ajax boundary (the external API's string params go through
     * hydrate_request_string() instead, held to the same shape check). NOTHING IS RESOLVED HERE: the boundary holds a bag
     * of keys and cannot know which column each is bound for, so it does not guess a type
     * from the client's claim - the claim is carried as an opaque string and discarded when
     * the value reaches a column (the cast) or a type that names itself (from_request()).
     * That is what makes a `__TEXT` naming an arbitrary class harmless: no name from the
     * wire is ever loaded. See Rsx_Text_Request_Value for the full reasoning.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function hydrate_request_value(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_key_exists('__TEXT', $value)) {
            return static::__wrap_envelope($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = static::hydrate_request_value($item);
        }

        return $value;
    }

    /**
     * One string-valued request field, checked for an ENCODED text value.
     *
     * The external API's params are scalars, so an encoded value arrives as the envelope
     * JSON-encoded into a string. A string is an envelope when, and only when, it begins
     * with `{`, parses as a JSON object, and that object has a `__TEXT` key. Anything else
     * - "{hello}", a JSON object with no `__TEXT` - is an ordinary string, which a declared
     * column reads as plain text.
     *
     * A string that IS identified as an envelope faces exactly the scrutiny an Ajax
     * envelope faces (__wrap_envelope(), shared), and a malformed one is refused rather
     * than quietly demoted to plain text: the caller said "this is encoded", and storing
     * its JSON as literal text would be a silent reinterpretation.
     *
     * @param string $value
     * @return string|Rsx_Text_Request_Value|null the string untouched, the wrapper, or null
     *                                            for an envelope whose raw form is null
     * @throws \InvalidArgumentException on an identified but malformed envelope
     */
    public static function hydrate_request_string(string $value): string|Rsx_Text_Request_Value|null
    {
        if (!str_starts_with($value, '{')) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || !array_key_exists('__TEXT', $decoded)) {
            return $value;
        }

        return static::__wrap_envelope($decoded);
    }

    /**
     * One envelope from the wire, wrapped. Shape is checked strictly; the type name is not
     * resolved. The ONE check both transports run - an Ajax envelope and an API envelope
     * string are held to the same rules.
     *
     * The shape is exactly what jsonSerialize() emits: `__TEXT` a non-empty string, `raw` a
     * string or null, `empty` optional and boolean, and nothing else. A key named `__TEXT`
     * is a claim to be an envelope, so an object that makes the claim and fails the shape
     * is refused, never passed through as an ordinary array.
     *
     * @param array $envelope
     * @return Rsx_Text_Request_Value|null
     * @throws \InvalidArgumentException
     */
    private static function __wrap_envelope(array $envelope): ?Rsx_Text_Request_Value
    {
        if (!is_string($envelope['__TEXT']) || $envelope['__TEXT'] === '') {
            throw new \InvalidArgumentException('A text value envelope must name its type in a non-empty string __TEXT.');
        }

        $extra = array_diff(array_keys($envelope), ['__TEXT', 'raw', 'empty']);
        if (!empty($extra)) {
            throw new \InvalidArgumentException(
                "Text value '{$envelope['__TEXT']}' carries unexpected keys: " . implode(', ', $extra)
                . '. An envelope is exactly {__TEXT, raw, empty}.'
            );
        }

        if (!array_key_exists('raw', $envelope)) {
            throw new \InvalidArgumentException("Text value '{$envelope['__TEXT']}' has no raw form.");
        }

        if (array_key_exists('empty', $envelope) && !is_bool($envelope['empty'])) {
            throw new \InvalidArgumentException("Text value '{$envelope['__TEXT']}' has a non-boolean empty flag.");
        }

        if ($envelope['raw'] === null) {
            return null;
        }

        if (!is_string($envelope['raw'])) {
            throw new \InvalidArgumentException(
                "Text value '{$envelope['__TEXT']}' arrived with a non-string raw form."
            );
        }

        return new Rsx_Text_Request_Value(
            $envelope['raw'],
            $envelope['__TEXT'],
            ($envelope['empty'] ?? false) === true
        );
    }

    /**
     * Resolve a submitted value to THIS type, explicitly, for an endpoint that must work
     * with the value before (or instead of) assigning it to a column.
     *
     * Assignment resolves a value implicitly - the column knows its type. This is the
     * explicit form: the PROGRAMMER names the type by calling it on that type, which is the
     * declaration the boundary could not make. The archetype is a comment endpoint that
     * must read the users tagged in the text to notify them:
     *
     *     $comment = Entity_Tagged_Text::from_request($params['body']);
     *     foreach ($comment->tagged_user_ids() as $user_id) { ... }
     *
     * Accepts a request wrapper (its client-claimed type must match - a mismatch is a
     * programming error and fatal, not a security control: the value resolves to THIS type
     * regardless), an already-typed value (must be this type), or a bare string (a plain
     * field that never wrapped - PLAIN TEXT, converted by from_string()). Every path that
     * carries untrusted input runs the filter.
     *
     * @param mixed $value
     * @return static|null null in, null out - a nullable column with nothing submitted
     */
    final public static function from_request(mixed $value): ?static
    {
        if ($value === null) {
            return null;
        }

        $expected = class_basename(static::class);

        if ($value instanceof Rsx_Text_Request_Value) {
            if ($value->claimed_type() !== $expected) {
                throw new \LogicException(
                    "{$expected}::from_request() was handed a value the client submitted as "
                    . "'{$value->claimed_type()}'. The endpoint and the form disagree about what "
                    . 'this field is - check the model\'s $text_types declaration and the input '
                    . 'component wired to it.'
                );
            }

            return static::from_untrusted($value->_raw());
        }

        if ($value instanceof static) {
            return $value;
        }

        if ($value instanceof Rsx_Text_Abstract) {
            throw new \LogicException(
                "{$expected}::from_request() was handed a " . class_basename($value)
                . '. Text types do not convert into one another.'
            );
        }

        if (is_string($value)) {
            return static::from_string($value);
        }

        throw new \InvalidArgumentException(
            "{$expected}::from_request() expects a submitted text value, a {$expected}, a "
            . 'string or null; got ' . get_debug_type($value) . '.'
        );
    }

    /**
     * The wire envelope. `__TEXT` names the type by SIMPLE NAME, which is what the
     * JavaScript side resolves through the manifest - the same shape `__MODEL` uses for
     * records.
     *
     * The raw form travels, plus ONE boolean. No plain-text rendition does: the browser
     * renders through the PRINTER component and edits through the raw form, so shipping
     * one would roughly double the payload of every text column to serve nobody.
     *
     * `empty` is the exception, and it earns its place. "Does this value have content"
     * is a question templates genuinely ask - a section header should not render above
     * an empty body - and it is the one question the browser cannot always answer for
     * itself, because a type whose plain rendition needs the database cannot compute it.
     * It is also the question a naive check gets wrong: an emptied WYSIWYG stores
     * `<p><br></p>`, which is a non-empty string and an empty document.
     *
     * @return array<string, string>
     */
    final public function jsonSerialize(): array
    {
        return [
            '__TEXT' => class_basename(static::class),
            'raw' => $this->raw,
            'empty' => $this->is_empty(),
        ];
    }
}
