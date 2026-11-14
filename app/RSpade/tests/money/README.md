# Concern: money

## Domain overview & applicability

`Rsx_Money` is an amount of money: an immutable count of cents in the signed 64-bit range
with exactly two decimal places, implemented twice - PHP and JavaScript - with the same
algorithms step for step, so both languages give the identical result or the identical
refusal for every call. No float or Number takes part in a calculation: every quantity is an
integer checked by `Rsx_Money_Core`, and a step whose true result leaves the range throws
`Rsx_Money_Exception` (`range`). Parsing (`from_text()`) and printing (`format()`) read one
display configuration, `rsx.money.display`, which the browser receives as
`window.rsxapp.money_display`.

The parity contract is the shared vector file `resource/money_vectors.json` (2156 cases).
Both runners load the same file; `resource/` is never scanned by the manifest. Each case
names a call, its arguments in a small tagged encoding (`{"money": "1.23"}`, `{"int": "..."}`
-> a PHP int / a JS BigInt, `{"float": ...}`, `{"rounding": "ROUND_*"}`,
`{"missing": true}` -> JS `undefined`), an optional display `config`, and either the
encoded result or the refusal (`reason`, plus the exact message unless the reason is
`argument`). Two cases are `only: js`: a missing rounding mode (in PHP an
`ArgumentCountError`, covered by `Money_Php_Language_Test`) and a Number past 2^53 as cents.

## Source files

- `app/RSpade/Core/Database/Money/Rsx_Money.php` - the value, its doors, outputs, questions,
  arithmetic, list and proportion methods, the display configuration
- `app/RSpade/Core/Database/Money/Rsx_Money_Core.php` - the checked-integer primitives
- `app/RSpade/Core/Database/Money/Rsx_Money_Exception.php` - the one exception, six reasons
- `app/RSpade/Core/Js/Rsx_Money.js`, `Rsx_Money_Core.js`, `Rsx_Money_Exception.js` - the
  JavaScript twins; `Rsx_Money` also registers the jqhtml object printer and cache class
- `config/rsx.php` (`money.display`), `Core/Bundle/Rsx_Bundle_Abstract.php`
  (`rsxapp.money_display`)
- The column kind `['money' => true]`: `Core/Database/Money/Rsx_Money_Cast.php`;
  `Core/Database/Models/Rsx_Model_Abstract.php` (`money_columns()`, `is_money_column()`,
  `money_limit()`, `money_from_submitted()`, the schema-derived cast); `Rsx_Money::_wrap_request_envelopes()` (called by `Core/Ajax/Ajax.php`)
  and `_wrap_api_param()` (called by `Core/Api/Api_Param_Validator.php`, the `money` param
  type); `app/Database/RestrictedEloquentBuilder.php` (where-family conversion, raw update,
  `sum_money()`); `Core/Forms/Form_Edit.php` (`FIELD_MONEY`); `Core/Js/Rsx_Js_Model.js`
  (rehydrator, `is_money_column()`); `Core/Forms/Form_Input_Abstract.js` (`_assert_accepts()`);
  `Core/Database/Model_Stub_ManifestSupport.php`; `Commands/Rsx/Constants_Regenerate_Command.php`;
  `Core/Database/Rsx_Schema.php` (precision, scale); rules MODEL-COLUMNS-01 and MODEL-MONEY-01
  (tested in `code_quality/php/Model_Money_Rules_Test.php`)
- The components: `Core/Forms/Money_Input.{jqhtml,js}` (in every bundle with the form engine)
  and `Lib/Ui/Money/Money_Display.{jqhtml,js}` + `money_display.scss` (`Core_Bundle` includes
  the directory)
- The rsx:check rules MODEL-DECIMAL-01 (`CodeQuality/Rules/Models/ModelDecimalColumns_CodeQualityRule.php`,
  tested in `code_quality/php/Model_Decimal_Rule_Test.php`) and MODEL-MONEY-02
  (`ModelMoneyUsage_CodeQualityRule.php` over `CodeQuality/Support/Receiver_Inference_Visitor.php`,
  tested in `code_quality/php/Model_Money_Usage_Rule_Test.php`); the framework's own DECIMAL
  column `_tasks.progress_percent` is declared not money in `Core/Task/Task_Run_Model_Abstract.php`

## Man page(s)

- `rsx:man money` (planned)

## Testable surface

- Every shared behaviour, both languages, over the vector file: bounds, every door, the
  float door and residue rule, strict parsing under eight display configurations, format and
  its round trip, the questions, all arithmetic under all five modes, allocation, the list
  functions, proportions. (php `Money_Vectors_Test`; playwright `playwright/money_vectors.js`)
  - IMPLEMENTED
- PHP only: the string-conversion guard, operator refusals, `ArgumentCountError` for a
  missing argument, a wrong type as `argument` (never `TypeError`), the float door's
  independence from `precision` / `serialize_precision`, the 16-digit whole float, the core's
  overflow edges, display validation per key, `json_encode()`. (php
  `Money_Php_Language_Test`) - IMPLEMENTED
- Browser only: the `Symbol.toPrimitive` / `toString()` guard, `json_encode()` envelopes,
  the jqhtml printer, the cache class round trip through a component's `this.data`,
  `rsxapp.money_display`, the two JavaScript-only refusals, `Rsx_Money_Exception extends
  Error`. (playwright `playwright/money_coercion.js`) - IMPLEMENTED
- The column kind, on the runtime table `money_fixtures` (`php/Money_Fixture_Tables.php`, created
  and dropped by each class; models `Money_Fixture_Model` and `Money_Wide_Fixture_Model`): the
  cast's reads, accepted and refused assignments, dirty-check stability, revisions, `toArray()`,
  never_export and derived (php `Money_Cast_Test`); each column's limit from its DECIMAL
  precision and scale, refused at the door and by the cast (php `Money_Column_Limit_Test`); queries and `sum_money()` (php
  `Money_Query_Test`); the Ajax boundary both ways (php `Money_Ajax_Hydration_Test` with
  `Money_Ajax_Fixture_Controller`); the `money` API param (php `Money_Api_Param_Test`);
  Form_Edit (php `Money_Form_Edit_Test`); the browser rehydrator and input refusals
  (playwright `playwright/money_rehydrate.js`) - IMPLEMENTED
- The input: `val()` is an Rsx_Money, null for a blank box, or the typed string when the text
  does not parse; nothing typed is filtered; blur tidies only text that parses; `val()` takes
  an Rsx_Money or null and refuses a string (playwright `playwright/money_input.js`) -
  IMPLEMENTED
- The display: a value, nothing stored and redacted stay three states; `$negative`,
  `$negative_red`, `$empty`; a bare number or string is refused (playwright
  `playwright/money_display.js`) - IMPLEMENTED

## Running

    php artisan rsx:test --framework Money_Vectors_Test Money_Php_Language_Test Money_Cast_Test Money_Column_Limit_Test Money_Query_Test Money_Ajax_Hydration_Test Money_Api_Param_Test Money_Form_Edit_Test
    php artisan rsx:test --framework Model_Money_Rules_Test Model_Decimal_Rule_Test Model_Money_Usage_Rule_Test
    node app/RSpade/tests/money/playwright/money_vectors.js
    node app/RSpade/tests/money/playwright/money_coercion.js
    node app/RSpade/tests/money/playwright/money_rehydrate.js
    node app/RSpade/tests/money/playwright/money_input.js
    node app/RSpade/tests/money/playwright/money_display.js
