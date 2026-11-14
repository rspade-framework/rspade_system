---
id: money_never_in_a_float
title: No amount is held or computed outside Rsx_Money
severity: high
when: launch, change
scope: rsx/**/*.php, rsx/**/*.js, rsx/**/*.jqhtml
related: rsx:man money, skill rspade:money
---

## The contract

Every amount of money - price, total, budget, fee, tax, balance, subtotal, discount, salary, cost - is held as an `Rsx_Money` and computed only with its methods, in PHP and in JavaScript: stored in a column declared `['money' => true]`, read from input through `Model::money_from_submitted()` / `Rsx_Money::from_submitted()`, totalled in SQL with `sum_money()`, every rounding named. A float cannot hold most amounts, and an amount carried as a number or a numeric string invites exactly the float arithmetic that leaves an invoice a cent off, silently, after launch.

Read `php artisan rsx:man money` first.

Already enforced - do not re-check: every DECIMAL, FLOAT and DOUBLE column of a model's own table is declared money or not money (MODEL-DECIMAL-01); a money column is DECIMAL(p,2), p <= 18 (MODEL-MONEY-01); no `$casts` entry shadows one (MODEL-COLUMNS-01); in PHP, a money value the linter can PROVE is money - a declared column through an evident model, a typed or assigned variable, an `Rsx_Money` call - is never compared, tested for truth, cast, used in arithmetic or interpolated (MODEL-MONEY-02); at run time a float assigned to a money column, a string conversion and PHP arithmetic on the object all throw. This audit covers what none of those see.

## Find every instance

    php artisan db:query "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('decimal','float','double','int','bigint') AND COLUMN_NAME REGEXP 'price|total|amount|budget|fee|tax|balance|subtotal|cost|salary|discount|rate' LIMIT 100"
    grep -rn "=> \[\]" rsx/models
    grep -rnE --include='*.php' -e "\(float\)" -e "floatval\(" -e "number_format\(" -e "bc(add|sub|mul|div)\(" rsx | grep -v '^rsx/tests/'
    grep -rnE --include='*.php' -e "->to_decimal\(\)" -e "from_aggregate\(" -e "->sum\(" -e "TYPE_FLOAT" -e "TYPE_DECIMAL" rsx | grep -v '^rsx/tests/'
    grep -rnE "(parseFloat|Number|toFixed|rsx_numeric)\(|[-+*/<>] *[a-z_.]*(price|total|amount|budget|fee|tax|balance|cost|salary|discount)" rsx --include='*.js' --include='*.jqhtml'
    grep -rnE "(price|total|amount|budget|fee|tax|balance|cost|salary|discount)[a-z_]* *(===|!==|==|\|\||&&|\?)" rsx --include='*.js' --include='*.jqhtml'

For each column hit, open its model: a money figure must be declared `['money' => true]`. For each `=> []` entry, confirm the DECIMAL really is not money. For each code hit, follow the value to where it was read and where it is used. Noise: framework `_`-prefixed tables and counters (`total_sent`, `progress_total`); the test tree; numeric fields that are not money (hours, quantities, percentages); `!== null` checks on a money value (correct).

## What does not conform

- An amount held in a carrier other than a money column: an INT of cents, a FLOAT/DOUBLE, a DECIMAL declared `[]`, a JSON field, a `Rsx_Settings` value of `TYPE_FLOAT` / `TYPE_DECIMAL`, a VARCHAR.
- Arithmetic, comparison or rounding done on `to_decimal()` / `to_cents_string()` strings or on numbers derived from them (`(float)`, `bcadd()`, `number_format()`, `array_sum`, `round()`), instead of on `Rsx_Money` methods - including values the linter cannot type: array elements, collection items, untyped returns, `$row['budget']` in a DataGrid's `transform_records()`.
- `from_aggregate()` given a figure a person entered or the code computed - it is for SQL totals only, because it rounds silently.
- `->sum('col')` / `->avg('col')` on a money column (Laravel answers a number) instead of `sum_money()` / `from_aggregate()`.
- A typed amount assigned to a column or used without `money_from_submitted()` / `from_submitted()`, or its refusal caught and swallowed instead of reported as the field's error; catching `rounding` / `division` / `argument` as if it were input.
- An amount in JavaScript tested with truthiness (`if (budget)`, `budget || x`) or compared with `===` / `==` / `<` - only `equals()`, `compare()` and the questions work, and only `!== null` tests presence; nothing lints JS.
- An amount typed into a plain or `rsx_numeric()` input, or computed in the browser from `parseFloat()` / `Number()`, instead of `<Money_Input>` and `Rsx_Money` methods; a stored or charged figure computed only in the browser.
- A multiplication, division or percentage whose rounding mode is chosen without thought for the figure (a split done with `divide_rounded()` and multiplied back instead of `allocate()`; a pro-rated or interest figure computed in two roundings instead of `multiply_fraction()`).

Not findings: an amount that only TRAVELS as an `Rsx_Money` or its envelope (assigned, returned, put in a payload, printed by `<%= %>` or `Money_Display`); `to_decimal()` written to a CSV cell, a log or an external system's field; a float or DECIMAL that is not money (hours, percentages, rates, byte counts, coordinates, scores) declared `[]`; `brick/money` used for a figure that carries its own currency code.

## Report

`PASS - <one line: N amount hits read, none held or computed outside Rsx_Money>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
