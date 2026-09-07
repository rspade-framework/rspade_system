# Test catalog: enums

Status legend: `implemented` | `deferred` (reason) | `blocked` | `planned`.
Type: php (all, no DB). Last updated: 2026-06-16.

## Enum_Static_Methods_Test (php, no DB)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| enum-sm-01 | field__enum returns all definitions | model field | full map | implemented |
| enum-sm-02 | each entry has full metadata | - | label/constant/custom keys | implemented |
| enum-sm-03 | includes selectable:false entries | - | present | implemented |
| enum-sm-04 | works on a second field of same model | - | correct map | implemented |
| enum-sm-05 | sorted by `order` property | mixed order | ascending by order | implemented |
| enum-sm-06 | field__enum_select excludes non-selectable | - | omitted | implemented |
| enum-sm-07 | select returns {value,label} pairs | - | pair shape | implemented |
| enum-sm-08 | select order respected | - | ordered | implemented |
| enum-sm-09 | select includes all when all selectable | - | complete | implemented |
| enum-sm-10 | field__enum_labels id=>label map | - | map | implemented |
| enum-sm-11 | labels include non-selectable | - | present | implemented |
| enum-sm-12 | labels return all four flash types | Flash_Alert | 4 entries | implemented |
| enum-sm-13 | field__enum_ids returns all keys | - | key list | implemented |
| enum-sm-14 | ids include non-selectable | - | present | implemented |
| enum-sm-15 | ids on a second model | - | correct | implemented |
| enum-sm-16 | generated PHP constants match enum keys | - | constant===key | implemented |
| enum-sm-17 | flash alert model constants present | - | constants defined | implemented |

## Enum_Magic_Properties_Test (php, no DB)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| enum-mp-01 | field__label returns label | value set | correct label | implemented |
| enum-mp-02 | label changes with value | change value | label tracks | implemented |
| enum-mp-03 | label for non-selectable entry | non-sel value | resolves | implemented |
| enum-mp-04 | label on second enum field | - | correct | implemented |
| enum-mp-05 | field__constant returns constant name | value | constant string | implemented |
| enum-mp-06 | constant for suspended value | suspended | constant | implemented |
| enum-mp-07 | custom `order` prop via BEM naming | - | value | implemented |
| enum-mp-08 | `selectable:false` readable on instance | - | false | implemented |
| enum-mp-09 | flash alert label + constant | - | correct | implemented |
| enum-mp-10 | flash alert error type | - | correct | implemented |
| enum-mp-11 | __isset true for known enum prop | - | true | implemented |
| enum-mp-12 | __isset true for custom enum prop | - | true | implemented |

## Enum_To_Array_Export_Test (php, no DB)

| ID | Purpose | Input | Expected | Status |
|----|---------|-------|----------|--------|
| enum-ta-01 | toArray includes __label | - | present | implemented |
| enum-ta-02 | toArray includes __constant | - | present | implemented |
| enum-ta-03 | toArray preserves raw field integer | - | int preserved | implemented |
| enum-ta-04 | toArray includes model identifier | - | __MODEL present | implemented |
| enum-ta-05 | toArray includes custom enum props | - | present | implemented |
| enum-ta-06 | toArray reflects current field value | change value | output tracks | implemented |

## Deferred / planned

| ID | Purpose | Type | Reason | Status |
|----|---------|------|--------|--------|
| enum-d-01 | field__enum_select($current) includes non-selectable if it is the current value | php | man page calls this JS-only; needs a doc decision before asserting PHP behavior | deferred |
| enum-d-02 | field__enum(id) single-entry lookup | php | documented JS-only | deferred |
| enum-d-03 | toArray with orphaned enum value (no matching def) | php | silent path, low value | deferred |
| enum-d-04 | cross-model enum isolation (get_called_class guard) | php | low risk; guard makes collision unlikely | deferred |
| enum-d-05 | tie-break ordering with mixed explicit/default order | php | no suitable existing model found | deferred |
| enum-d-06 | JS-side enum stubs (field__enum_select etc. in browser) | playwright | JS surface, needs browser harness | planned |

## Man-page note

`man/enums.txt`: the ANTI-ALIASING "WRONG" example referenced `$contact` before
it was declared; corrected to declare `$contact = static::find($id)` first. No
behavioral divergence; no issues_encountered.md.
