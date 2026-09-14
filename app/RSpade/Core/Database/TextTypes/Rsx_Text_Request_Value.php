<?php

namespace App\RSpade\Core\Database\TextTypes;

/**
 * A declared-text-column value as it arrives from a request: TYPELESS, INERT, and
 * resolved only when it reaches a column or a type that names itself.
 *
 * At the Ajax boundary the server holds a bag of keys and has no idea which column each
 * one is bound for. That is only known at assignment - `$project->description = $x` - so
 * the boundary cannot know the RIGHT type and must not guess it from the client's claim.
 * Instead it produces this: a wrapper that carries the raw string and the client's
 * claimed type name as an OPAQUE string, and can be asked exactly two things.
 *
 *   - is_empty()          the one validation an endpoint needs
 *   - assignment          `$model->column = $value` - the cast types it from the column
 *
 * Everything else THROWS. String coercion, interpolation, strlen(), a log line - each one
 * is the value being used as something it is not, and the exception is the signal. That
 * is how an endpoint stays unaware that a field is special: it does what it does for any
 * field, and the value refuses everything it should not be used for.
 *
 * ── Why the class name is never resolved ────────────────────────────────────────────
 *
 * `__TEXT` is a client-supplied string naming a class. Resolving it - even against an
 * allowlist - is a load of attacker-chosen code, and it is UNNECESSARY, because the
 * server already knows the type from the column declaration. So the name is carried
 * through untouched and discarded at the cast. The filter runs exactly once, with
 * exactly the column's type, at exactly the cast. A client that claims the wrong type is
 * not an error; it is ignored.
 *
 * The name survives for one purpose: if an endpoint echoes the value straight back
 * (never having assigned it), jsonSerialize() sends it with the client's own claim, and
 * the browser rehydrates it. The worst a bad claim achieves is breaking the render in
 * the sender's own session, and the browser refuses a name that is not a text type anyway.
 *
 * ── Why is_empty() trusts the client ────────────────────────────────────────────────
 *
 * Emptiness depends on the encoding (`<p><br></p>` is an empty document and a non-empty
 * string), and only the type knows the encoding. The browser computed this with the real
 * type at hand; the server, typeless here, takes its word - after checking '' and null
 * itself, which need no trust. The lie is useful in one direction only: a blank document
 * slipped past a required-field check, into the client's own record. The server recomputes
 * from the stored, typed value on every read, so the lie survives one request's validation
 * and reaches nobody else.
 *
 * @see Rsx_Text_Abstract::hydrate_request_value() - where these are made
 * @see Rsx_Text_Abstract::from_request()          - resolving one to a type, explicitly
 * @see Rsx_Text_Cast::set()                        - resolving one to a column, implicitly
 */
#[Instantiatable]
final class Rsx_Text_Request_Value implements \JsonSerializable, \Stringable
{
    private string $raw;

    private string $claimed_type;

    private bool $client_says_empty;

    /**
     * @param string $raw The value as submitted. Unfiltered - the column's type filters it.
     * @param string $claimed_type The client's `__TEXT`. Opaque; never resolved.
     * @param bool $client_says_empty The client's `empty` flag.
     */
    public function __construct(string $raw, string $claimed_type, bool $client_says_empty)
    {
        $this->raw = $raw;
        $this->claimed_type = $claimed_type;
        $this->client_says_empty = $client_says_empty;
    }

    /**
     * Whether the submitted value carries no content.
     *
     * '' and null are decided here without trust. Anything else is the client's answer,
     * computed in the browser by the real type - see the class docblock for why that is
     * acceptable and what a lie can and cannot achieve.
     *
     * @return bool
     */
    public function is_empty(): bool
    {
        return $this->raw === '' || $this->client_says_empty;
    }

    /**
     * The type the client claimed. For from_request()'s consistency check and for the
     * echo-back envelope. NOT a fact about the value - the column decides that.
     *
     * @return string
     */
    public function claimed_type(): string
    {
        return $this->claimed_type;
    }

    /**
     * The submitted string, UNFILTERED.
     *
     * For the cast and for from_request(), which are the two places a type is applied to
     * it. An endpoint has no business here: a raw request string used anywhere else has
     * skipped the trust boundary, which is the one thing this class exists to make hard.
     *
     * @return string
     */
    public function _raw(): string
    {
        return $this->raw;
    }

    /**
     * Refused. The value cannot be a string until a type has filtered it, and the only
     * ways to get there are assignment to a declared column or Type::from_request().
     */
    public function __toString(): string
    {
        throw new \LogicException(
            'A submitted text value cannot be used as a string. It is typeless until it '
            . 'reaches a declared column ($model->column = $value) or a type that names '
            . 'itself (Rich_Text::from_request($value)). To test for content, call '
            . '->is_empty().'
        );
    }

    /**
     * The echo-back envelope, carrying the client's own claim back to it.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            '__TEXT' => $this->claimed_type,
            'raw' => $this->raw,
            'empty' => $this->is_empty(),
        ];
    }
}
