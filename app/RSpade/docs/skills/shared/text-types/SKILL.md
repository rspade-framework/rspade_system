---
name: text-types
description: "Declaring what kind of string a TEXT column holds and writing a type for it - public static $text_types on a model, Rsx_Text_Abstract with its two required methods sanitize_encoded() and encode_plain_text(), what assigning a bare string does (plain text, escaped into the encoding) versus Type::from_untrusted_encoded() for encoded content, writing a declared column through the external API (plain string or a JSON-encoded {__TEXT, raw} envelope in a string param), the optional to_plain_text()/to_html() conventions and when NOT to define them, is_empty() and the wrapping-encoding override, the PRINTER and EDITOR registrations on the JavaScript class of the same name and the generated Model.editor_component_for(col) / Model.text_type_or_null(col) lookups, ACCEPTS on an input component, what an Ajax endpoint receives (a typeless Rsx_Text_Request_Value that answers only is_empty()), and Type::from_request() for reading the content before storing it. Use when a TEXT column should hold rich text or a custom notation such as {{Client_Model:42}}, when adding or adapting a type under rsx/lib/text_types/, when an endpoint must read a submitted value (tagged entities in a comment), when wiring an editor or printer, when migrating a column from plain text, when an importer or API client writes a declared column, or on hitting \"cannot be used as a string\", \"does not define to_plain_text()\", \"does not define to_html()\", \"A submitted text value cannot be used as a string\", \"Text types do not convert into one another\", \"was handed a value the client submitted as\", \"edits Rich_Text values, but was given string\", or a column comparison against '' that is unexpectedly false, when composing markup for a declared column (a helper returning `\"<p>...\"`), when stored text shows literal &lt;p&gt; tags, or on a TEXT-TYPE-01 build failure."
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

PHP cannot notate a variable as markup rather than a string; a declared column supplies the
type the language lacks. Declare the type once on the model and the value carries it. Every
sink asks the value. **Declare any TEXT column whose content is not plain prose** - anything
a sink must escape, render or interpret differently from the characters it holds.

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

## What a type is — two requirements, several conventions

| Method | Status | Define it when |
|---|---|---|
| `sanitize_encoded()` | **Required** | Always. The trust boundary, even as a written-down passthrough |
| `encode_plain_text()` | **Required** | Always. Plain text -> this encoding; every bare string passes through it before `sanitize_encoded()`. A passthrough when the encoding IS plain text |
| `is_empty()` | Convention, default = raw check | The encoding *wraps* content (an emptied WYSIWYG stores `<p><br></p>`) |
| `to_plain_text()` | Convention, **throws** by default | Something needs a plain rendition — a CSV cell, an index. Rarely |
| `to_html()` | Convention, **throws** by default | Markup must be produced *on the server* — an email, an export. **Rarely.** A page renders through the PRINTER component |
| your own | — | Anything the application must *read* from the encoding |

**Rule of thumb for `to_plain_text()` and `to_html()`: rarely necessary.** They exist so that
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
    public static function sanitize_encoded(string $raw): string { return sanitize_rich_text_html($raw); }

    // REQUIRED. A bare string is plain text: escape it, keep its line breaks.
    public static function encode_plain_text(string $plain): string
    {
        return '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_HTML5)) . '</p>';
    }

    // OVERRIDDEN: the encoding wraps content. <p><br></p> is empty.
    public function is_empty(): bool { return trim($this->to_plain_text()) === ''; }

    // DEFINED: the reference app exports projects to CSV.
    public function to_plain_text(): string { /* strip markup, keep block breaks as newlines */ }

    // DEFINED: an email may embed it. Storage was filtered on write, so the
    // stored form IS the safe rendition.
    public function to_html(): string { return $this->raw; }
}
```

```javascript
class Rich_Text extends Rsx_Text_Abstract {
    static PRINTER = 'Rich_Text_Display';    // injects stored HTML through DOMPurify
    static EDITOR  = 'Wysiwyg_Input';
    static sanitize_encoded(raw) { return sanitize_rich_text_html(raw); }
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
    public static function sanitize_encoded(string $raw): string
    {
        $models = Manifest::php_get_extending('Rsx_Model_Abstract');

        return preg_replace_callback(self::TAG, function ($m) use ($models) {
            return isset($models[$m[1]]) ? $m[0] : '';
        }, $raw);
    }

    // REQUIRED. The encoding is plain text with markers: a passthrough.
    // sanitize_encoded() still drops a malformed tag.
    public static function encode_plain_text(string $plain): string { return $plain; }

    // THE DEDICATED FUNCTION - the reason the type exists. A parse, no database.
    // Returns [['User_Model', 33], ['Client_Model', 7]].
    public function tagged_entities(): array
    {
        preg_match_all(self::TAG, $this->raw, $m, PREG_SET_ORDER);

        return array_map(fn ($t) => [$t[1], (int) $t[2]], $m);
    }

    // is_empty(): default is correct - the storage form is text.
    // to_plain_text():  NOT DEFINED. A plain rendition means one get_printed_name()
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

The complete type is `sanitize_encoded()`, `encode_plain_text()` and `tagged_entities()`. Three
methods, all about the encoding, none a rendition.

**There is no `to_plain_text()` in JavaScript, on purpose.** Where a type offers one at all, it
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
`<{Comment_Model.editor_component_for('body')} $name="body" />` asks the column (see the `jqhtml` skill's
`reference/dynamic-tags-and-printers.md`).

## The envelope is the value, everywhere it travels

A typed value leaves the server as `{__TEXT, raw, empty}` on **every** payload — `fetch()`, a
list, a relationship, an `/api/vN` response — and is never flattened to a string for a
consumer's convenience, not for a page and not for an external client. Flattening puts the
guessing back at the consumer, which is the failure this feature ends. The envelope is a black
box only the widgets built for its type understand: a template interpolates it with no
hydration call because the PRINTER is the one thing that can render it. A consumer that needs
a plain rendition gets a deliberate extra key from the endpoint (`to_plain_text()`), never a rewritten
column.

## Assigning a value: a bare string is plain text

| Assigned | Meaning | Path |
|---|---|---|
| a typed value (`Rich_Text`) | already encoded + filtered | stored as is (another type throws) |
| a request envelope (Ajax or `/api/vN`) | encoded content | `sanitize_encoded()` |
| **a bare string** | **plain text** | `encode_plain_text()` then `sanitize_encoded()` |
| an int, float or bool | stringified plain text | as a bare string (`false` -> `''` -> `is_empty()`) |
| `null` | null | — |
| an array or other object | — | throws |

**Only the first two rows are enforced.** A wrong type throws; a malformed envelope throws. A
bare string cannot be checked - every string is legal plain text - so **markup assigned as a
bare string is stored as visible tags (`<p>&lt;strong&gt;...`) with no error, no log line and
no failing round-trip test.** Misremembering doesn't crash here either: it is the read-side
failure this feature ends, reappearing on the write side, and it is the one choice nothing
checks for you.

**Ask what is in your hand:**
- **You BUILT the string and it contains tags?** It is markup: `Type::from_untrusted_encoded($html)`.
- **It ARRIVED as prose** - a spreadsheet cell, a typed sentence, an API scalar? Assign it
  bare. **Do not hand-wrap it in `<p>`** - the column does that, and your wrapper becomes
  visible tags.

**A function that composes content for a declared column returns the TYPE, never a string:**

```php
private static function task_created_body(User_Model $actor): Rich_Text
{
    return Rich_Text::from_untrusted_encoded('<p><strong>' . e($actor->get_printed_name()) . '</strong> created a task.</p>');
}
```

`from_untrusted_encoded()` then lives once, where the markup is born, and every signature downstream
carries the fact. A composer typed `string` loses it at the first hop.

An importer, a seed, a script or a plain API param stores what was typed: on a `Rich_Text`
column `"Contact <john@acme.com>\nline two"` becomes escaped text with a `<br>`, never a
purified-away "tag". **Never hand-roll `htmlspecialchars`/`nl2br` before assigning** — that is
`encode_plain_text()`'s job and doing it too double-escapes.

**Encoded content from your own code is said out loud**: `$record->body =
Rich_Text::from_untrusted_encoded($html);` (filter only). `Rich_Text::from_plain_text($plain)` is the
explicit form of what assignment does. A column-aware importer holding HTML asks the model:
`$type = Model::text_type_or_null($col); $record->$col = $type ? $type::from_untrusted_encoded($html) : $html;`
(`text_type_for()` is the cast's lookup and THROWS on an undeclared column.)

**`from_storage()` is the one constructor of the four (`from_storage`, `from_untrusted_encoded`, `from_plain_text`, `from_request`) that skips the sanitizer: public, trusted and UNFILTERED.** It is how the cast
reads the column, and it is for content that came OUT of the column. Handing it a request, an
import or an API value stores it byte for byte - a stored-XSS path. Everything else is
`from_untrusted_encoded()`.

## Writing through the external API

A declared column is written through an ordinary `'string'` `#[Api_Param]`; the endpoint just
assigns it. The client chooses the form:

- **plain text** — any ordinary string; escaped into the encoding on assignment.
- **encoded** — the envelope **JSON-encoded into the string**:
  `"{\"__TEXT\":\"Rich_Text\",\"raw\":\"<p>Hi</p>\"}"`. It becomes the same
  `Rsx_Text_Request_Value` the Ajax path produces, and the column's `sanitize_encoded()` runs.

A string is an envelope only when it **begins with `{`, parses as a JSON object, and has a
`__TEXT` key**; anything else is plain text. An identified envelope that fails the shape check
(`__TEXT` non-empty string, `raw` string|null, optional boolean `empty`, no other keys) is a
**422 on that field**, never stored as its own JSON. A client round-tripping a GET JSON-encodes
the envelope it read; sending `raw` alone stores the markup as literal text.

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
    // type's sanitize_encoded() on the way in, exactly as assignment did.
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
surface, not a security control), an already-typed value of the same type, a bare string
(plain text, via `from_plain_text()`), or `null`. A typed value of a *different* type throws; types don't convert.

**Equivalent when the record is at hand:** `$comment->body` after assignment *is* an
`Entity_Tag_Text`, so `$comment->body->tagged_entities()` works with no explicit
resolution. `from_request()` is for when you need the typed value without a record.

## Gotchas, in the order they bite

**Markup in a bare string is stored as visible tags, silently** - and prose pre-wrapped in
`<p>` is the mirror image. See "Assigning a value" above; a composer returns the type.

**`=== ''` and `empty()` are both silently wrong.** A value object is never identical to a
string, and PHP's `empty()` is always false on an object. Use `is_empty()`.

**`|| ''` in form-data defaults.** `''` is a string; a typed input refuses one. `null` is the
right empty.

**A typed value throws when used as a string — deliberately.** The case that settles it is a
write: `$copy->notes = $orig->notes . 'more'` would strip the markup and store something
plausible. Say what you mean: `to_plain_text()`, `to_html()`, `is_empty()` — and only where the
type defines them.

**A wrapping encoding must override `is_empty()`.** The default is the raw form.

**"Just give the API client the string" is the same mistake as `=== ''`.** The external API
emits the envelope. See the section above.

**Changing a column's type is two acts, in order**: a raw-SQL migration that re-encodes the
rows into the storage form `Type::from_plain_text()` produces (a migration never calls an
application class - MIGRATION-MODEL-01), then the declaration change.

**A third-party editor may not round-trip HTML it didn't author.** Load a stored value and
save it back; the result must be byte-identical.

**TEXT-TYPE-01 (manifest-build FATAL)** refuses a declaration the cast cannot honour: an entry
naming no `Rsx_Text_Abstract` class, a type with no JS twin, and a `$casts` / `casts()` entry on
a declared column - which would SHADOW the text cast and store request markup unfiltered.
Escape: `@TEXT-TYPE-01-EXCEPTION` in the file.

**`->pluck()`, raw SQL and `DB::table()` bypass the cast** — the same boundary that already
applies to dates and type refs. Mass assignment is refused by `Rsx_Model_Abstract` and
would route through the cast even if it weren't.

## Reference

`rsx:man text_types` — the full contract, TWO TYPES WORKED, and HOW IT WORKS INSIDE
`rsx:man jqhtml` — ADVANCED: VALUE PRINTERS, ADVANCED: DYNAMIC COMPONENT TAGS
skill `rspade:form-input-contract` — the input value contract these editors implement
`resource/reference_app/lib/text_types/` — the shipped types and their `CLAUDE.md`
`resource/reference_app/tests/Text_Type_Assignment_Test.php` — the column behaviour, pinned
