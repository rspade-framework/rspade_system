---
name: money
description: "Storing, computing and showing amounts of money with Rsx_Money (PHP and JS) and the ['money' => true] column kind - the ways in (from_decimal, from_cents, from_text, from_submitted, Model::money_from_submitted(), from_aggregate), add / subtract / multiply / percent / multiply_fraction / allocate / divide_rounded with a named ROUND_HALF_UP / ROUND_HALF_EVEN rounding, sum_money(), Money_Input, Money_Display, <%= record.budget %>, the {__MONEY} envelope, rsx.money.display. Use when a column or value holds a price, total, budget, fee, tax or balance, totalling or splitting amounts, an endpoint reads an amount, or on 'This is not an amount of money.', 'This number carries floating-point residue; pass it as a decimal string.', 'An amount of money has at most two decimal places.', 'This amount is outside the range the system can hold.', 'This amount is larger than this field can hold.', 'Rsx_Money cannot be converted to a string', 'a float is never assigned to it', MODEL-DECIMAL-01, MODEL-MONEY-01 or MODEL-MONEY-02."
---

# Money: `Rsx_Money` and the money column kind

The contract - every signature, every message verbatim - is `rsx:man money`. The template's
worked example is the project `budget` (`rsx/models/project_model.php`,
`rsx/app/frontend/projects/`).

## The use case

A float cannot hold most amounts: `0.1 + 0.2` is `0.30000000000000004`, and the error
accumulates silently through a line-item loop until an invoice is a cent off. A DECIMAL column
read through Eloquent is a string, which invites `(float)` and then exactly that loop.

`Rsx_Money` is an exact amount: an immutable count of cents in the signed 64-bit range, always
two decimal places, identical in PHP and JavaScript (same methods, same results, same refusals).
**No float ever takes part in a calculation, every rounding is named by the caller, and every
step is checked against the range.** Declare a column money and it reads as one.

One currency per application: the display (symbol, separators) is configuration, not part of
the value. There is no currency code on an amount and no conversion.

## Declaring a column

```php
// Migration: DECIMAL(p,2), p at most 18 (MODEL-MONEY-01)
DB::statement("ALTER TABLE projects ADD COLUMN budget DECIMAL(15,2) NULL");

// Model
public static $columns = [
    'budget'        => ['money' => true],
    'hour_estimate' => [],              // a DECIMAL that is NOT money: say so
];
```

- `money` is a flag (literal `true`), exclusive with `enum` / `text_type` / `type_ref`; it
  combines with `derived`, `never_export` and `revision_exclude` (`MODEL-COLUMNS-01`, build FATAL).
- **No `$casts` entry** for a money column - the declaration IS its cast; a cast beside it is
  `MODEL-COLUMNS-01`.
- **Every DECIMAL, FLOAT and DOUBLE column of a model's table is declared** money or not money
  (`[]`) - `MODEL-DECIMAL-01` (`rsx:check`). A FLOAT/DOUBLE holding money is converted to
  `DECIMAL(p,2)` by a migration first.
- Why precision 18: `DECIMAL(18,2)`'s largest value fits the range; a wider column could store
  an amount no `Rsx_Money` can hold, and reading it would throw.
- Accessors: `Model::money_columns()`, `Model::is_money_column('col')` (JS:
  `Model.is_money_column('col')`), `Model::money_limit('col')` (JS: `Model.money_limit('col')`,
  an `Rsx_Money` from the page's field-length script - a limit is a schema fact, never in the
  stub; it throws for a column that is not money or when the script did not load). The generated header says
  `@property \App\RSpade\Core\Database\Money\Rsx_Money $budget money: a value object, two decimal places, never a float`.

## What the value is - and what it refuses

```php
$project->budget;                       // Rsx_Money, or null (no budget)
$project->budget->to_decimal();         // "1234.50" - the exact canonical string
$project->budget->format();             // "$1,234.50" - display
```

**It is never a number and never a string.** Every operation is a method:

| You want | Write | Never |
|---|---|---|
| a total | `$a->add($b)`, `$a->subtract($b)` | `$a + $b` |
| equality / order | `$a->equals($b)`, `compare()`, `less_than()`, `greater_than_or_equal()` ... | `==`, `<`, `===` |
| "is there an amount?" | `$m !== null` | `if ($m)`, `empty($m)`, `$m ?: x` |
| "is it zero / negative?" | `is_zero()`, `is_negative()`, `is_positive()` | `$m == 0`, `(bool) $m` |
| a string | `to_decimal()` (data), `format()` (display) | `(string) $m`, `"$m"`, `'x' . $m` |
| a number | there is none | `(int)`, `(float)`, `round()`, `number_format()` |

In PHP arithmetic and string conversion throw (`Rsx_Money cannot be converted to a string: use
to_decimal() for the exact value or format() for display.`), but **`==` / `<` compare the objects'
one property - the decimal string, as numbers through a float, so two amounts past 15 significant
digits can compare equal - `===` compares identity, and every object is TRUE** - silently, with no
error. In
JavaScript every coercion to a primitive throws (`+m`, `m < n`, `` `${m}` ``, `m == 5`), but
**`===` is identity and every object is truthy**, also silently. `MODEL-MONEY-02` (`rsx:check`)
reports the PHP shapes where it can prove the operand is money; nothing lints JavaScript.

## The ways in - which refuse and which round

| Door | Takes | Extra precision |
|---|---|---|
| `from_decimal($x)` | a plain decimal string `"1234.5"`, an int of WHOLE units (`5` is 5.00), a float without residue | REFUSED (`scale`) |
| `from_cents($x)` | an int count of cents, or a string of one (`"-5"`; `"-0"` is 0.00) | n/a |
| `from_text($s)` | what a person typed, read through the display config: `"$1,234.56"`, `"(500.00)"`, `"-$99.99"` | REFUSED |
| `from_submitted($x)` | a form/API field: blank or null is **null** (never zero), a string is typed text, an int whole units, a float the float door, an envelope decoded | REFUSED |
| `Model::money_from_submitted('col', $x)` | `from_submitted()`, then refused beyond the column's own limit | REFUSED |
| `from_aggregate($x)` | an SQL total: null (no rows) is 0.00, a decimal string or float **rounded half-up** | ROUNDED - the one door that rounds |
| `zero()` | - | - |

- **Floats**: a float is accepted only when it is exactly what was written. A whole-valued float
  within +/-(2^53 - 1) is an integer in both languages (`1000000000000001.0` is whole units, as the
  JS Number is); any other float only when its shortest form has at most 15 significant digits
  (`19.99` is fine). `0.1 + 0.2` and `9007199254740992.0` are refused: `This number carries
  floating-point residue; pass it as a decimal string.` Prefer strings; a float is never assigned
  to a money column at all. Negative zero is zero at every door (`-0.0`, `"-0.00"`,
  `from_cents("-0")`).
- Trailing zeros are not precision: `"1.500"` is 1.50; `"1.505"` is refused (`An amount of money
  has at most two decimal places.`).
- `from_text()` grouping is strict: with `,` grouping, `"1,234.5"` reads but `"12,34"` and
  `"1234,5"` are `This is not an amount of money.` A sign and parentheses together are refused.
- `from_aggregate()` is for a figure SQL computed (`SUM`, `AVG`) - never for a figure a person
  entered.
- Assignment to a money column takes an `Rsx_Money`, a strict decimal string (`'29.99'`,
  `'250000'`), an int of whole units or an envelope; a float throws (`... is a money column; a
  float is never assigned to it. Assign Rsx_Money::from_decimal('1.00') or the decimal string.`),
  typed text (`'$1,234'`) throws, and an amount beyond the column's limit throws `This amount is
  larger than this field can hold.` An unchanged amount is never dirty.

## Arithmetic, rounding and the range

```php
$total   = $subtotal->add($shipping)->subtract($discount);
$tax     = $subtotal->percent('7.25', Rsx_Money::ROUND_HALF_UP);       // 19.99 -> 1.45
$line    = $unit_price->multiply(3, Rsx_Money::ROUND_EXACT);           // whole factor: exact
$half    = $amount->multiply('0.5', Rsx_Money::ROUND_HALF_EVEN);
$monthly = $annual->divide_rounded(12, Rsx_Money::ROUND_HALF_UP);
```

**Every method that can leave a fraction of a cent takes the rounding mode as a REQUIRED
argument** - there is no default:

| Mode | Behaviour | Use for |
|---|---|---|
| `ROUND_HALF_UP` | nearest; a half goes away from zero | most customer-facing figures (tax, price x rate) |
| `ROUND_HALF_EVEN` | nearest; a half goes to the even cent | many repeated roundings (interest accrual, statistics) - no drift |
| `ROUND_UP` | any remainder away from zero | "never under-charge" figures |
| `ROUND_DOWN` | any remainder dropped | "never over-pay" figures |
| `ROUND_EXACT` | a remainder THROWS (`rounding`) | a calculation that must come out whole |

Factors, rates and divisors are an int, a decimal string (`'1.5'`, `'7.25'`) or a residue-free
float. **Each step is checked**: a step whose true result leaves the range throws `This amount is
outside the range the system can hold.`, even when the final answer would fit - so `MAX x '0.5'`
throws (MAX x 5 is computed first), and a `sum()` can depend on the order of its elements near the
range. The range is +/-92233720368547758.07, far beyond any business amount; a column's own limit
is far smaller (below).

### Spreadsheet-style functions

```php
Rsx_Money::sum($amounts);                         // empty list = 0.00
Rsx_Money::min($amounts); Rsx_Money::max($amounts);   // empty list throws
Rsx_Money::average($amounts, Rsx_Money::ROUND_HALF_UP);
Rsx_Money::running_totals($amounts);              // the total after each element
```

A list is a PHP list / JS Array of `Rsx_Money`; **a null element is refused** - filter first
(an absent figure summed as zero hides the absence).

```php
$a->ratio_to($b, 2, Rsx_Money::ROUND_HALF_UP);              // 50.00 to 200.00 -> "0.25"
$a->percent_change_to($b, 2, Rsx_Money::ROUND_HALF_UP);     // 100.00 to 107.25 -> "7.25"
$a->round_to(Rsx_Money::from_decimal('100'), Rsx_Money::ROUND_HALF_UP);   // 1234.56 -> 1200.00
$a->clamp($low, $high);  $a->is_between($low, $high);       // inclusive
$a->negate();  $a->abs();
```

`ratio_to()` and `percent_change_to()` answer DECIMAL STRINGS, never money and never floats.

### A split that loses no cent

```php
Rsx_Money::from_decimal('100')->allocate([1, 1, 1]);   // 33.34, 33.33, 33.33 - sums to 100.00
$fee->allocate([70, 30]);                              // by integer ratios; a 0 ratio gets nothing

['quotient' => $each, 'remainder' => $left] = Rsx_Money::from_decimal('100')->divide(3);
// $each 33.33, $left 0.01: quotient x 3 + remainder is the amount; the remainder has its sign
```

`allocate()` hands the leftover cents to the largest remainders, ties to the earlier element. Never
`divide_rounded()` an amount N ways and multiply back - the parts will not sum.

### Pro-rating and interest: one calculation, one rounding

```php
// 17 days of a 30-day month
$charge = $monthly->multiply_fraction(17, 30, Rsx_Money::ROUND_HALF_UP);

// one day's simple interest at 5% a year: balance x 5 / (100 x 365)
$interest = $balance->multiply_fraction(5, 36500, Rsx_Money::ROUND_HALF_EVEN);   // 10000.00 -> 1.37
```

`multiply_fraction()` takes integers and rounds once. Computing `5 / 365` first, as a decimal,
rounds twice and drifts.

## In an endpoint

The template's project save (`rsx/app/frontend/projects/projects_controller.php`):

```php
$budget = null;
try {
    $budget = Project_Model::money_from_submitted('budget', $params['budget'] ?? null);
} catch (Rsx_Money_Exception $e) {
    if (!$e->is_input_problem()) {
        throw $e;
    }
    $errors['budget'] = $e->getMessage();
}
if ($budget !== null && $budget->is_negative()) {
    $errors['budget'] = 'Budget must be zero or more.';
}
...
$edit->set('budget', $budget);              // inside Form_Edit::run()
```

- `money_from_submitted()` is the door: it reads `"$1,234.50"`, `"1234.5"`, `"(5.00)"`, a blank
  (null - no amount, never zero) or the envelope `Money_Input` sends, and refuses beyond the
  column's limit. Its refusals are input problems (`format` / `scale` / `range`) whose message is
  written for the person - hand it to the field error unchanged.
- **Catch only `is_input_problem()`**. `rounding`, `division` and `argument` are the calculation or
  the CODE - rethrow them.
- The business rule (not negative, not over a cap) is yours, written with the methods.
- `Form_Edit` compares amounts exactly (`1234.5` and `1234.50` are the same, so no false
  conflict), and a conflict question shows them formatted (field kind `money`).

## In a form

```html
<Form_Field $label="Budget">
    <Money_Input $name="budget" $placeholder="0.00" />
</Form_Field>
```

- The value has THREE states: an `Rsx_Money`; `null` for a blank box; or **the typed STRING when
  it does not parse** - so the server refuses it with its own message (a typo submitted as null
  would silently clear the field). Code reading `val()` tests `instanceof Rsx_Money` first.
- Typing is never filtered; on blur a parsed entry is tidied to the plain form (`1234.50`).
- **Seed it with the `Rsx_Money` or `null`, never `''`** (`budget: project.budget`, no `|| ''`):
  `val(v)` takes an `Rsx_Money` or null and throws on a string. No `$max_length`.
- A plain input given an `Rsx_Money` throws (`... edits plain strings, but was given an
  Rsx_Money. That column is a money column - use <Money_Input>.`).
  `Model.editor_component_for('budget')` answers `'Money_Input'` for a dynamic tag.

## Printing

```html
<%= project.budget %>                         <%-- $1,234.50 - the value prints itself --%>
<% if (project.budget !== null) { %><%= project.budget %><% } else { %><Empty_Value /><% } %>
<Money_Display $value=unallocated $negative_red=true />
<span title="<%= project.budget.format() %>">  <%-- attribute position: call format() --%>
```

- `<%= m %>` prints `format()` through the registered object printer. **Null prints nothing** -
  guard it, or use `Money_Display`.
- **In attribute position printers are not consulted**, and the value refuses to become a string:
  write `m.format()` (or `to_decimal()`).
- `<Money_Display $value=... />` keeps three states apart: a value (`$0.00` is a value), nothing
  stored (`$empty`, an em-dash by default) and `$redacted=true` ("Hidden", no figure printed).
  Options `$negative="parentheses"`, `$negative_red`. A number or string `$value` throws.
- `format($options)`: `['negative' => 'parentheses']` gives `($1,234.50)`, `['symbol' => false]`,
  `['grouped' => false]`; `to_input_text()` is the edit-box form (`-1234.50`).
- Server-generated output (an email, a PDF) calls `->format()` itself; a CSV cell writes
  `$record->budget?->to_decimal()` (machine-readable).

## In JavaScript

The same class and methods (`Rsx_Money.from_decimal('19.99').multiply(3, Rsx_Money.ROUND_EXACT)`).
A money column arrives from `fetch()`, a relationship or an Ajax response as an `Rsx_Money`
(`null` stays null); a value returned from an endpoint (`'subprojects_budget' =>
...->sum_money('budget')`) arrives as one too, and an `Rsx_Money` passed to an Ajax call or
submitted by a form arrives in PHP as one. In JS a whole number is a BigInt or a safe-integer
Number; `from_cents()` refuses a Number beyond the safe range. A cached component's `this.data`
restores its amounts as `Rsx_Money`.

**Compute amounts on the server** where a figure is stored or charged; JS money arithmetic is for
display (a running total in a form, the template's "Unallocated" figure).

## Queries

```php
Project_Model::where('budget', '>=', Rsx_Money::from_decimal('10000'))->get();   // binds "10000.00"
Project_Model::whereBetween('budget', [$low, $high]);
$total = Project_Model::where('parent_project_id', $id)->sum_money('budget');   // Rsx_Money, 0.00 if none
```

- Every where-family value (`where`, `orWhere`, `whereNot`, `whereIn`, `whereBetween` and their
  `or`/`Not` forms) and a raw `update()` value bind an `Rsx_Money` as its decimal string.
- `sum_money('col')` totals a declared money column exactly (a NULL amount is skipped by SQL); on
  another column it throws `... is not a money column: sum_money() totals a column declared
  ['money' => true] in $columns.` Never `->sum('budget')` - Laravel answers a float or int.
- A grouped total: `selectRaw('client_id, SUM(budget) AS total')->groupBy('client_id')`, then
  `Rsx_Money::from_aggregate($row->total)` per row.

## The external API

**The API carries an amount as a plain decimal string, in and out - the `{__MONEY}` envelope is
browser Ajax only and never appears on `/api/vN`.**

- A read returns a money column, and any `Rsx_Money` an endpoint puts in its answer (a computed
  figure, a `sum_money()` total), as `"1234.56"` - exactly two places. Return the model or the
  `Rsx_Money` itself; the dispatcher's response pass (`Api_Dispatcher::plain_body()`) writes the
  string. Never hand-convert with `to_decimal()` for the API, and never hand-build an envelope.
- A write declares `#[Api_Param('price', type: 'money')]`: it accepts a plain decimal string
  (`"1234.50"`, `"1234.5"`), a JSON int of whole units or a residue-free JSON float; blank or null
  is null. The endpoint receives an `Rsx_Money` (or null); anything else - the envelope included,
  as a JSON object or encoded into a string - is that field's error, "This is not an amount of
  money.". A default is a literal (`default: '0.00'`). Plain strings are strict decimals -
  `"$1,234"` is refused. OpenAPI documents the param as a string with the decimal pattern.
- **A param stored in a money column names it**: `#[Api_Param('budget', type: 'money', column:
  'Project_Model.budget')]` (a literal `'Model_Name.column'`, checked at manifest build: a
  concrete model, a declared money column, a money param). The validator then refuses an amount
  beyond `Model::money_limit('col')` with "This amount is larger than this field can hold." on
  that field, so the endpoint assigns what it receives. Without `column:`, an over-large amount
  reaches the cast and throws. Worked example: the template's projects API,
  `system/app/RSpade/resource/reference_app/app/api/v1/projects_api_controller.php`.

## Grids, exports, revisions

- `DataGrid_Abstract::transform_records()` sees `toArray()` rows, so a money column there is the
  envelope ARRAY `['__MONEY' => '1234.56']` - leave it (the browser rehydrates it; an API answer
  carrying it is made plain on the way out) or rebuild it
  with `Rsx_Money::from_envelope($row['budget'])`; `$row['budget']->format()` is a fatal error.
- A revision records the canonical strings (`"1234.50"` -> `"1300.00"`); a history screen that
  formats them asks `Model::is_money_column($column)` and prints
  `Rsx_Money::from_storage($raw)->format()`.

## Display configuration

`rsx.money.display` (set it in `rsx/resource/config/rsx.php`), shipped to the browser as
`window.rsxapp.money_display`, drives both `format()` and `from_text()` in both languages:

```php
'money' => ['display' => [
    'symbol' => '$', 'symbol_position' => 'before', 'symbol_spacing' => false,
    'decimal_separator' => '.', 'group_separator' => ',',
]],
```

`symbol_position` `'before'|'after'`; `symbol_spacing` puts a U+00A0 between symbol and number;
`decimal_separator` `'.'|','`; `group_separator` one of `,` `.` `'` space U+00A0 U+202F or `''`
(none), different from the decimal separator. An invalid value throws when first read, naming the
key (`rsx.money.display.<key> must be ...`).

## The rules

| Rule | When | Finds |
|---|---|---|
| `MODEL-MONEY-01` | `rsx:check`, high | a column declared money that is not `DECIMAL(p,2)` with p <= 18 |
| `MODEL-DECIMAL-01` | `rsx:check`, high | a DECIMAL / FLOAT / DOUBLE column the model has not declared money or not money |
| `MODEL-MONEY-02` | `rsx:check`, high | a money value compared, tested for truth, cast, in arithmetic or interpolated (PHP) |
| `MODEL-COLUMNS-01` | manifest build, FATAL | a malformed `money` entry, `money` beside another kind, a `$casts` entry on a money column |

`MODEL-MONEY-02` reports only what it can prove is money (a declared column through an evident
model, a typed variable, a value from an `Rsx_Money` method) and leaves `=== null`, `isset()`,
`??` and `?->` alone. Values through arrays, collections or untyped returns, and all JavaScript,
are unlinted - the conformance audit `money_never_in_a_float` covers what no rule sees.

## Gotchas, in the order they bite

1. **`if ($project->budget)` is always true** for any amount, `0.00` included. "Has a budget" is
   `!== null`; "is zero" is `is_zero()`. Same in JS.
2. **`==` / `<` / `===` compare objects, not amounts** - no error in PHP's `==` or JS's `===`.
   Use `equals()` / `compare()` / the questions.
3. **A float handed in**: `from_decimal(0.1 + 0.2)` is refused and a float assigned to a column
   throws. Keep amounts as strings or `Rsx_Money` from the first read.
4. **`$m->multiply('1.5')` without a mode** is an error - name the rounding every time.
5. **`''` as a form seed** throws in `Money_Input`; seed `null`.
6. **Printing in attribute position** throws - call `format()`.
7. **`->sum('col')`** returns a float; use `sum_money('col')`.
8. **`to_decimal()` strings are not for arithmetic** - convert back with `from_decimal()`; never
   `bcadd()` / `(float)` them.
9. **A typed entry assigned directly** (`$p->budget = $params['budget']`) fails on `"$1,234"`:
   read it through `money_from_submitted()` first.
10. **An `Rsx_Money` as an argument to a component with `on_load()`** makes its args unkeyable
    (not a plain object), so it is not cached - pass the record id, or define `cache_id()`.
