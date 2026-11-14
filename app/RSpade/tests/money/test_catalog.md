# Test catalog: money

Status legend: `implemented` | `deferred` | `blocked` | `planned`.
Last updated: 2026-11-13.

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|----|--------------------------|------|-------|-------------------|--------|--------------|
| money-vec-01 | The vector file is schema 1, ids unique, its default display is the framework's, every group has a runner method | php | `resource/money_vectors.json` | all hold | implemented | 2026-11-13 |
| money-vec-02 | Every PHP-applicable vector, one method per group (25 groups), each failing id listed with expected vs actual | php | 2154 cases | all pass | implemented | 2026-11-13 |
| money-vec-03 | Every vector in the browser, `only: js` included, decoded and encoded in one page.evaluate() | playwright | 2156 cases | all pass, no page errors | implemented | 2026-11-13 |
| money-php-01 | `(string) $m`, `'x' . $m`, `"{$m}"`, `strlen($m)` throw `\LogicException` | php | a value | the to_decimal()/format() message | implemented | 2026-11-13 |
| money-php-02 | Arithmetic operators on the object throw `TypeError` | php | `$a + $b`, `$a * 2` | Unsupported operand types | implemented | 2026-11-13 |
| money-php-03 | A missing mode is PHP's `ArgumentCountError` (PHP side of `multiply_272`) | php | `multiply('2')` | ArgumentCountError | implemented | 2026-11-13 |
| money-php-04 | A wrong type is `Rsx_Money_Exception` (`argument`), never `TypeError` | php | `add(null)`, `multiply([], m)` | reason argument | implemented | 2026-11-13 |
| money-php-05 | The float door gives ECMAScript `Number::toString()` under the default ini and under `serialize_precision=17 precision=5`, restoring both | php | 18 floats | identical strings | implemented | 2026-11-13 |
| money-php-06 | A whole float of 16 significant digits is residue in PHP (from_decimal, a factor), accepted by from_aggregate | php | `1000000000000001.0` | RESIDUE / RESIDUE / `1000000000000001.00` | implemented | 2026-11-13 |
| money-php-07 | The core detects overflow on each primitive's edge | php | add(MAX,1), sub(MIN,1), mul(MIN,-1), neg/abs(MIN), quo(MIN,-1), pow10(19), int(2^63) | range; rem(MIN,-1) is 0 | implemented | 2026-11-13 |
| money-php-08 | Display validation refuses each key, an unknown key, equal separators and every forbidden symbol character | php | bad displays | RuntimeException naming `rsx.money.display.<key>` | implemented | 2026-11-13 |
| money-php-09 | `json_encode()` writes the envelope, also nested | php | values | `{"__MONEY":"1.00"}` | implemented | 2026-11-13 |
| money-php-10 | `is_input_problem()` is true exactly for format, scale, range | php | six reasons | 3 true, 3 false | implemented | 2026-11-13 |
| money-js-01 | Every primitive coercion throws: `+m`, `m + 1`, `m - n`, `m < n`, template literal, `str(m)`, `m.toString()`, `Number(m)`, `m == 5` | playwright | a value | the toPrimitive message | implemented | 2026-11-13 |
| money-js-02 | `json_encode(m)` is the envelope, nested in structures; the one own property is `_decimal` | playwright | values | envelopes | implemented | 2026-11-13 |
| money-js-03 | The jqhtml printer prints `format()` | playwright | `jqhtml.print_object()` | `$1,234.50`, `-$0.05` | implemented | 2026-11-13 |
| money-js-04 | A value in a component's `this.data` comes back from the cache serializer as an `Rsx_Money` copy | playwright | on_load() storing values | instanceof, same amount | implemented | 2026-11-13 |
| money-js-05 | The two JavaScript-only refusals | playwright | missing mode; `from_cents(2^53)` | argument | implemented | 2026-11-13 |
| money-js-06 | `rsxapp.money_display` carries the five keys and is the active display; `Rsx_Money_Exception extends Error` | playwright | the page | holds | implemented | 2026-11-13 |
| money-cast-01 | a money column carries Rsx_Money_Cast; `money_columns()` / `is_money_column()`; a DECIMAL declared `[]` has no cast | php | Money_Fixture_Model | as declared | implemented | 2026-11-13 |
| money-cast-02 | a stored value reads as Rsx_Money, NULL as null | php | saved rows | `1234.50`, null | implemented | 2026-11-13 |
| money-cast-03 | each accepted form is stored as the canonical string: Rsx_Money, decimal string, `1.500`, whole-number string, int (whole units), envelope array, null | php | assignments | `1.50`, `5.00`, ... | implemented | 2026-11-13 |
| money-cast-04 | a float (exact message), bool, object, list, typed text (`$5`, grouping, whitespace), `1.505`, a malformed envelope are refused | php | assignments | Rsx_Money_Exception with its reason | implemented | 2026-11-13 |
| money-cast-05 | a save and re-read is byte-identical, in the table and through the model | php | `1234567890123.45` | identical strings | implemented | 2026-11-13 |
| money-cast-06 | re-assigning the same amount in any form is not dirty, and the save records no revision | php | Rsx_Money, `1234.500`, envelope, int | clean; revision count unchanged | implemented | 2026-11-13 |
| money-cast-07 | a revision records the canonical strings | php | 1234.5 -> 1300 | `["1234.50","1300.00"]` | implemented | 2026-11-13 |
| money-cast-08 | `toArray()` carries the envelope; never_export removes the column; null is null | php | saved rows | `{"__MONEY":"99.99"}` | implemented | 2026-11-13 |
| money-cast-09 | a derived money column is assigned through the cast | php | amount 10.25, then 0.01 | doubled 20.50, then 0.02 | implemented | 2026-11-13 |
| money-limit-01 | `money_limit()` is the column's DECIMAL maximum at whole cents, memoized | php | `amount` (15,2), `price` (10,2), `rate` (8,3) | `9999999999999.99`, `99999999.99`, `99999.99` | implemented | 2026-11-13 |
| money-limit-02 | a column wider than the type caps at Rsx_Money's maximum; every Rsx_Money (the most negative included) fits it | php | `wide` DECIMAL(19,2) | PHP_INT_MAX cents; accepted | implemented | 2026-11-13 |
| money-limit-03 | `money_limit()` / `money_from_submitted()` on a column that is not money throws LogicException | php | `hours`, `name` | LogicException | implemented | 2026-11-13 |
| money-limit-04 | `money_from_submitted()` accepts the limit and its negation, refuses a cent past either with `range` + `MESSAGE_COLUMN_RANGE` (an input problem); blank is null; scale still refused | php | `amount` +-9999999999999.99, +-10000000000000.00, PHP_INT_MIN cents | as stated | implemented | 2026-11-13 |
| money-limit-05 | the cast refuses an assignment past the column in every accepted form, either sign, and takes the limit and its negation | php | `price` = `100000000.00` (string, int, Rsx_Money, envelope, negative) | `range` + `MESSAGE_COLUMN_RANGE` | implemented | 2026-11-13 |
| money-query-01 | `where()` (2-arg, 3-arg, array), `orWhere()`, `whereNot()` compare an Rsx_Money by amount | php | three rows | the matching names | implemented | 2026-11-13 |
| money-query-02 | `whereIn()`, `whereNotIn()`, `whereBetween()`, `orWhereBetween()` take Rsx_Money values | php | three rows | the matching names | implemented | 2026-11-13 |
| money-query-05 | `whereNotBetween()`, `orWhereNotBetween()` take Rsx_Money values (bounds excluded inclusively) | php | three rows | the matching names | implemented | 2026-11-13 |
| money-query-03 | `raw_bulk()->update()` binds an Rsx_Money as its decimal string | php | one row | `99.90` | implemented | 2026-11-13 |
| money-query-04 | `sum_money()` totals as Rsx_Money; no rows is `0.00`; past the range throws; a column that is not money throws LogicException | php | rows; two DECIMAL(19,2) rows | `60.75`, zero, range, LogicException | implemented | 2026-11-13 |
| money-ajax-01 | an envelope param reaches the endpoint as Rsx_Money, at any depth; an array without `__MONEY` and a typed string are untouched | php | Ajax::internal() | Rsx_Money / array / string | implemented | 2026-11-13 |
| money-ajax-02 | a malformed envelope (non-canonical, extra key, non-string, null, beside `__TEXT`) fails the call with `argument` | php | Ajax::internal() | Rsx_Money_Exception | implemented | 2026-11-13 |
| money-ajax-03 | a returned value and a returned record serialize as the envelope | php | Ajax::internal() | `{"__MONEY":...}` | implemented | 2026-11-13 |
| money-api-01 | `#[Api_Param(type: 'money')]` accepts a decimal string, the envelope as a JSON string, an int (whole units), a float without residue; blank and null are null | php | Api_Param_Validator::validate() | Rsx_Money / null | implemented | 2026-11-13 |
| money-api-02 | a value that is not an amount is the field error with the refusal's message | php | `12.345`, `abc`, `$12.00`, residue, bool, malformed envelope, an array | MESSAGE_SCALE / MESSAGE_FORMAT / ... | implemented | 2026-11-13 |
| money-api-04 | the envelope as a JSON object in a JSON body is accepted (what a read returned, sent back); a malformed one is the field error; another array or a list is `MESSAGE_FORMAT` | php | `{"amount": {"__MONEY": "1.00"}}` | Rsx_Money / refusal | implemented | 2026-11-13 |
| money-api-03 | a declared default reaches the endpoint as Rsx_Money | php | default `0.00` | Rsx_Money | implemented | 2026-11-13 |
| money-form-01 | the amount another editor saved, submitted as Rsx_Money or typed text, is not asked about | php | Form_Edit::run() | no question | implemented | 2026-11-13 |
| money-form-02 | a different amount asks, kind `money`, every value formatted (loaded, current, yours, revision from/to) | php | Form_Edit::run() | `$1,234.50` ... | implemented | 2026-11-13 |
| money-form-03 | a submission that is not an amount differs and shows as typed | php | `lots` | `yours` = `lots` | implemented | 2026-11-13 |
| money-js-07 | the rehydrator turns `{__MONEY}` into Rsx_Money in record fields and lists; null stays null; an object with another key stays plain | playwright | `Rsx_Js_Model._instantiate_models_recursive()` | instanceof, amounts | implemented | 2026-11-13 |
| money-js-08 | an input with no ACCEPTS refuses an Rsx_Money (naming `<Money_Input>`); an ACCEPTS = 'Rsx_Money' input takes Rsx_Money or null and refuses a string | playwright | probe inputs on /_sys | refusals as stated | implemented | 2026-11-13 |
| money-input-01 | blank and whitespace-only are null; `(500.00)`, `-5`, `$1,234.56`, `1234.5`, `0` are Rsx_Money and stay as typed while typing | playwright | typed through the keyboard on /_sys | the amounts | implemented | 2026-11-13 |
| money-input-02 | `12.345`, `12,34`, `abc` give the typed string unchanged | playwright | typed | the strings | implemented | 2026-11-13 |
| money-input-03 | typing raises the form's `input` notification | playwright | typed | event count rises | implemented | 2026-11-13 |
| money-input-04 | blur tidies a parseable entry to `to_input_text()` (`1234.50`, `-5.00`) and leaves `12.345` as typed | playwright | blur | the box text | implemented | 2026-11-13 |
| money-input-05 | `val(Rsx_Money)` shows the amount and is not a user change; `val(null)` empties; `val('')`, `val('5')` refused | playwright | val() | as stated | implemented | 2026-11-13 |
| money-display-01 | a value prints `format()`; zero is `$0.00`, a value; `$negative` sign / parentheses; `$negative_red` only on a negative | playwright | mounted on /_sys | the text and classes | implemented | 2026-11-13 |
| money-display-02 | null prints the em-dash or `$empty`; redacted prints Hidden and no amount, even when given one | playwright | mounted | the text and classes | implemented | 2026-11-13 |
| money-display-03 | a bare number or string as `$value` is refused naming its type, and nothing is shown | playwright | `5`, `'5.00'` | the console report | implemented | 2026-11-13 |
