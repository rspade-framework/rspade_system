---
name: text-types
description: "Declaring what kind of string a TEXT column holds and writing a type for it - public static $text_types on a model, Rsx_Text_Abstract with its one required method filter_set(), the optional to_text()/to_html() conventions and when NOT to define them, is_empty() and the wrapping-encoding override, the PRINTER and EDITOR registrations on the JavaScript class of the same name, ACCEPTS on an input component, what an Ajax endpoint receives (a typeless Rsx_Text_Request_Value that answers only is_empty()), and Type::from_request() for reading the content before storing it. Use when a TEXT column should hold rich text or a custom notation such as {{Client_Model:42}}, when adding or adapting a type under rsx/lib/text_types/, when an endpoint must read a submitted value (tagged entities in a comment), when wiring an editor or printer, when migrating a column from plain text, or on hitting \"cannot be used as a string\", \"does not define to_text()\", \"does not define to_html()\", \"A submitted text value cannot be used as a string\", \"Text types do not convert into one another\", \"was handed a value the client submitted as\", \"edits Rich_Text values, but was given string\", or a column comparison against '' that is unexpectedly false."
---

# Declared TEXT column types

> **Living feature.** The framework ships `Rsx_Text_Abstract` and **no concrete types**.
> Which types exist and what each permits is the application's decision; the reference
> app's are at `rsx/lib/text_types/`, described by its own `CLAUDE.md`. When this feature
> changes, update that file in the same pass. The contract is `rsx:man text_types`.

## The use case

A TEXT column may hold plain text, sanitized HTML from a WYSIWYG, or an application's own
notation — `{{Client_Model:42}}` for a tagged record, say. That is a property of the
**column**, but the value stops carrying it the moment it is read, so every place that
prints, edits, exports or indexes the column independently remembers which kind it is and
reaches for the matching escape, widget or filter. Misremembering doesn't crash: it renders
markup as literal text, or user input unescaped.

Declare the type once on the model and the value carries it. Every sink asks the value.

```php
public static $text_types = [
    'description' => Rich_Text::class,
    'notes'       => Raw_Text::class,
];
```

**The content is a black box to your code.** A user may enter anything. To the application
the value is as nebulous as an image: it can be empty, stored, displayed. It is *not* a
string you search, trim, concatenate or format — a typed value used as a string throws, in
PHP and in JavaScript. One function touches the content: the server sanitizer. Anything
else you need to *read* out of it is a dedicated method on the type.

**An undeclared TEXT column is an ordinary string.** Opt-in; a tree that declares nothing is
unaffected.

## What a type is — one requirement, several conventions

| Method | Status | Define it when |
|---|---|---|
| `filter_set()` | **Required** | Always. The trust boundary, even as a written-down passthrough |
| `is_empty()` | Convention, default = raw check | The encoding *wraps* content (an emptied WYSIWYG stores `<p><br></p>`) |
| `to_text()` | Convention, **throws** by default | Something needs a plain rendition — a CSV cell, an index. Rarely |
| `to_html()` | Convention, **throws** by default | Markup must be produced *on the server* — an email, an export. **Rarely.** A page renders through the PRINTER component |
| `from_string()` | Convention, default = `from_untrusted` | A column is migrating to this type from plain text |
| your own | — | Anything the application must *read* from the encoding |

**Rule of thumb for `to_text()` and `to_html()`: rarely necessary.** They exist so that
application code needing a rendition of *any* text type has a predictable name to call —
not so every type must carry one. A type whose plain form means resolving references
against the database may reasonably never do that on the server. Throwing by default keeps
the convention honest: an undefined one fails loudly instead of guessing.

## Two types, worked

The shipped `Rich_Text`, and `Entity_Tag_Text` — **theoretical, not shipped** — plain text
in which `{{Client_Model:42}}` tags a record. The second is the shape every notation type
takes, and it shows where the conventions are *not* needed.

### `Rich_Text` — shipped, `rsx/lib/text_types/rich_text/`

```php
class Rich_Text extends Rsx_Text_Abstract
{
    public static function filter_set(string $raw): string { return safe_html($raw); }

    // OVERRIDDEN: the encoding wraps content. <p><br></p> is empty.
    public function is_empty(): bool { return trim($this->to_text()) === ''; }

    // DEFINED: the reference app exports projects to CSV.
    public function to_text(): string { /* strip markup, keep block breaks as newlines */ }

    // DEFINED: an email may embed it. Storage was filtered on write, so the
    // stored form IS the safe rendition.
    public function to_html(): string { return $this->raw; }
}
```

```javascript
class Rich_Text extends Rsx_Text_Abstract {
    static PRINTER = 'Rich_Text_Display';    // injects stored HTML through DOMPurify
    static EDITOR  = 'Wysiwyg_Input';
    static filter_set(raw) { return safe_html(raw); }
}
```

### `Entity_Tag_Text` — theoretical

A comment might read: *"Handed off to {{User_Model:33}} — see {{Client_Model:7}}."*

```php
class Entity_Tag_Text extends Rsx_Text_Abstract
{
    private const TAG = '/\{\{([A-Z][A-Za-z0-9_]*):(\d+)\}\}/';

    // REQUIRED. Refuse a malformed tag so the column never holds one: strip
    // any {{...}} whose class is not a model the manifest knows, or whose id
    // is not digits. The text between tags is opaque and untouched.
    public static function filter_set(string $raw): string
    {
        $models = Manifest::php_get_extending('Rsx_Model_Abstract');

        return preg_replace_callback(self::TAG, function ($m) use ($models) {
            return isset($models[$m[1]]) ? $m[0] : '';
        }, $raw);
    }

    // THE DEDICATED FUNCTION - the reason the type exists. A parse, no database.
    // Returns [['User_Model', 33], ['Client_Model', 7]].
    public function tagged_entities(): array
    {
        preg_match_all(self::TAG, $this->raw, $m, PREG_SET_ORDER);

        return array_map(fn ($t) => [$t[1], (int) $t[2]], $m);
    }

    // is_empty(): default is correct - the storage form is text.
    // to_text():  NOT DEFINED. A plain rendition means one get_printed_name()
    //             lookup per tag, per row. If comments are never exported,
    //             the server has no reason to know how. Define it the day an
    //             export needs it.
    // to_html():  NOT DEFINED. Comments are only shown on a page, where the
    //             PRINTER renders each tag as a live chip - fetched through
    //             Model.fetch(), linked, subscribed. Interactive rendering is
    //             what a component is for and what a static string cannot be.
}
```

```javascript
class Entity_Tag_Text extends Rsx_Text_Abstract {
    static PRINTER = 'Entity_Tag_Display';   // resolves each tag to a chip via Model.fetch()
    static EDITOR  = 'Entity_Tag_Input';     // a textarea with @-style autocomplete
}
```

The complete type is `filter_set()` plus `tagged_entities()`. Two methods, both about the
encoding, neither a rendition.

**There is no `to_text()` in JavaScript, on purpose.** Where a type offers one at all, it
lives on the server, which can resolve synchronously; the browser often cannot, and rather
than be right in one language and approximate in the other, it doesn't answer —
`toString()` throws. A different rendition on a page is an argument to the PRINTER.

### The editor names the type it edits

```javascript
class Entity_Tag_Input extends Form_Input_Abstract {
    static ACCEPTS = 'Entity_Tag_Text';   // a NAME: inputs evaluate before rsx/lib/ in a bundle
    _get_value()      { return Entity_Tag_Text.from_editor(this.$sid('input').val()); }
    _set_value(value) { this.$sid('input').val(value === null ? '' : value.to_storage()); }
}
```

`val()` gets and sets a value **object**. Both directions are refused on a mismatch — **a bare
string included, and that is the rule, not a strictness to soften.** An editor that accepted a
string and wrapped it would be deciding what encoding the string has, which is the one decision
no widget may make. When a typed input throws on a string, fix the call site that produced it
(a `''` form default, a hand-built payload, an undeclared column wired to a typed widget); never
add a tolerant branch to the widget. A form need not name the widget —
`<{Comment_Model.editor_for('body')} $name="body" />` asks the column (see the `jqhtml` skill's
`reference/dynamic-tags-and-printers.md`).

## The envelope is the value, everywhere it travels

A typed value leaves the server as `{__TEXT, raw, empty}` on **every** payload — `fetch()`, a
list, a relationship, an `/api/vN` response — and is never flattened to a string for a
consumer's convenience, not for a page and not for an external client. Flattening puts the
guessing back at the consumer, which is the failure this feature ends. The envelope is a black
box only the widgets built for its type understand: a template interpolates it with no
hydration call because the PRINTER is the one thing that can render it. A consumer that needs
a plain rendition gets a deliberate extra key from the endpoint (`to_text()`), never a rewritten
column.

## What happens on an Ajax POST

The browser serialises a typed value as `{__TEXT, raw, empty}`. At the Ajax boundary the
framework turns every such envelope into an **`Rsx_Text_Request_Value`** — typeless, inert,
and the thing your endpoint holds in `$params`.

It answers **one** question and accepts **one** operation:

```php
$params['body']->is_empty();        // the one validation
$comment->body = $params['body'];   // assignment - the column types and filters it
```

Everything else throws. That is what lets an endpoint stay unaware the field is special:
it does what it does for any field, and the value refuses everything it shouldn't be used
for.

**Why typeless:** at the boundary the server has a bag of keys and no idea which column
each is bound for — that's only known at assignment. So it doesn't guess the type from the
client's `__TEXT` (a client-supplied class name, never resolved) and doesn't need to: the
column declaration already knows. The filter runs at the cast, with the column's type,
exactly once. A client claiming the wrong type is ignored, not errored.

**The pattern: assign, then validate.** Nothing is written until `save()`.

```php
$project->description = $params['description'];

if ($project->description->is_empty()) {          // read back: typed by the COLUMN
    return response_form_error('Description is required.');
}

$project->save();
```

`is_empty()` on the read-back is correct because the column's type is answering.

## Reading the content before storing it — `from_request()`

The common case: a comment is posted, and the entities tagged in it must be notified.
Reading the tags is `Entity_Tag_Text::tagged_entities()` — a dedicated function on the type
— and the endpoint needs a typed value to call it on. It asks for one **explicitly, by
naming the type**, which is the declaration the boundary couldn't make:

```php
#[Ajax_Endpoint]
#[Auth('is_logged_in')]
public static function post_comment(Request $request, array $params = [])
{
    $comment = new Comment_Model();
    $comment->thread_id = (int) $params['thread_id'];
    $comment->body      = $params['body'];         // typeless in, Entity_Tag_Text stored

    if ($comment->body->is_empty()) {
        return response_form_error('Say something.');
    }

    $comment->save();

    // Now READ it. The endpoint names the type; the value is filtered by that
    // type's filter_set() on the way in, exactly as assignment did.
    $body = Entity_Tag_Text::from_request($params['body']);

    foreach ($body->tagged_entities() as [$class, $id]) {
        if ($class === 'User_Model') {
            Notification::send($id, 'mentioned', $comment);
        }
    }

    return ['id' => $comment->id];
}
```

`from_request()` takes the request value (its client-claimed type must match — a mismatch
means the form and the endpoint disagree about what the field *is*, which is a bug to
surface, not a security control), an already-typed value of the same type, a bare string,
or `null`. A typed value of a *different* type throws; types don't convert.

**Equivalent when the record is at hand:** `$comment->body` after assignment *is* an
`Entity_Tag_Text`, so `$comment->body->tagged_entities()` works with no explicit
resolution. `from_request()` is for when you need the typed value without a record.

## Gotchas, in the order they bite

**`=== ''` and `empty()` are both silently wrong.** A value object is never identical to a
string, and PHP's `empty()` is always false on an object. Use `is_empty()`.

**`|| ''` in form-data defaults.** `''` is a string; a typed input refuses one. `null` is the
right empty.

**A typed value throws when used as a string — deliberately.** The case that settles it is a
write: `$copy->notes = $orig->notes . 'more'` would strip the markup and store something
plausible. Say what you mean: `to_text()`, `to_html()`, `is_empty()` — and only where the
type defines them.

**A wrapping encoding must override `is_empty()`.** The default is the raw form.

**"Just give the API client the string" is the same mistake as `=== ''`.** The external API
emits the envelope. See the section above.

**Changing a column's type is two acts, in order**: a raw-SQL migration that re-encodes the
rows into the storage form `Type::from_string()` produces (a migration never calls an
application class - MIGRATION-MODEL-01), then the declaration change.

**A third-party editor may not round-trip HTML it didn't author.** Load a stored value and
save it back; the result must be byte-identical.

**`->pluck()`, raw SQL and `DB::table()` bypass the cast** — the same boundary that already
applies to dates and type refs. Mass assignment is refused by `Rsx_Model_Abstract` and
would route through the cast even if it weren't.

## Reference

`rsx:man text_types` — the full contract, TWO TYPES WORKED, and HOW IT WORKS INSIDE
`rsx:man jqhtml` — ADVANCED: VALUE PRINTERS, ADVANCED: DYNAMIC COMPONENT TAGS
skill `rspade:form-input-contract` — the input value contract these editors implement
`resource/reference_app/lib/text_types/` — the shipped types and their `CLAUDE.md`
`resource/reference_app/tests/Text_Type_Assignment_Test.php` — the column behaviour, pinned
