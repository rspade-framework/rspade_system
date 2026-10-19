---
id: money_never_in_a_float
title: No amount is computed in a float
severity: high
when: launch, change
scope: rsx/**/*.php, rsx/**/*.js, rsx/**/*.jqhtml
related: rsx:man standard_library, skill rspade:rsx-stdlib
---

## The contract

No money amount - price, total, budget, rate, fee, tax, balance, subtotal, discount, salary, cost - is ever added, multiplied, divided, summed, split or rounded as a float, in PHP or in JavaScript. Amounts are computed with `brick/money` (guaranteed present): `Money::of($string_or_int, 'USD')`, `->plus()` / `->minus()` / `->multipliedBy()` / `->dividedBy()`, an explicit `RoundingMode` wherever a result can carry more scale than the currency, `->formatTo($locale)` or `->getAmount()` for output. A float cannot hold most decimal amounts; the error accumulates silently through a line-item loop and surfaces as an invoice a cent off, after launch.

Read `php artisan rsx:man standard_library` (brick/money) first.

Facts to judge by: there is no money column type or cast - a DECIMAL column hydrates as the driver's STRING, which is exactly what `Money::of()` wants, while a value just ASSIGNED from a float stays a float until re-read. `Rsx_Settings::TYPE_DECIMAL` returns a string; `TYPE_FLOAT` returns a double. `Currency_Input` / `rsx_numeric` give `.val()` as a number string. The template's `Formatters::currency()` formats through brick/money and accepts a string; a float handed to it is already imprecise. No rule enforces any of this.

## Find every instance

    grep -rn --include='*.php' -e "(float)" -e "floatval(" rsx | grep -v '^rsx/tests/'
    grep -rn --include='*.php' "number_format" rsx | grep -v '^rsx/tests/'
    grep -rnE --include='*.php' '\$[a-z_]+->(price|total|amount|budget|rate|fee|tax|balance|subtotal|cost|salary|discount)[a-z_]*[^;]*[-+*/]' rsx | grep -v '^rsx/tests/'
    grep -rnE --include='*.php' -e '->(price|total|amount|budget|rate|fee|tax|balance|subtotal|cost|salary|discount)[a-z_]* = ' -e 'TYPE_FLOAT' rsx | grep -v '^rsx/tests/'
    grep -rnE "(parseFloat|Number|toFixed)\(|[-+*/] *[a-z_.]*(price|total|amount|budget|fee|tax|balance|cost|salary|discount)" rsx --include='*.js' --include='*.jqhtml'
    grep -rniE "DECIMAL\(|FLOAT|DOUBLE" rsx/resource/migrations

Read every hit that touches an amount, then follow each money column the migrations declare to every place it is computed with.

## What does not conform

- An amount cast to float (`(float)`, `floatval()`, `+ 0`, `parseFloat()`, `Number()`) and then computed with.
- Arithmetic, `array_sum`, `round()` or `number_format()` on amounts held as floats or numeric strings outside brick/money. `number_format()` on an amount for display is a finding too (`->formatTo()`, or `Formatters::currency()` given a string).
- A money column declared FLOAT or DOUBLE (should be DECIMAL), or a money setting stored as `TYPE_FLOAT`.
- An amount computed in the browser (a cart total, a tax line) - compute on the server, or with integer minor units at the very least, and report it.
- Rounding left implicit, or a mixed-currency operation forced through by converting to floats.
- bcmath used for money (brick/money carries the currency and needs no extension).

Not findings: an amount that only TRAVELS - assigned to a column, put in a payload, compared for emptiness, passed as a string; a float that is not money (hours, percentages, byte counts, relevance scores, coordinates).

## Report

`PASS - <one line: N amount hits read, none computed in a float>`, or one finding per line:

    file:line - what is wrong - the conforming fix

No other prose.
