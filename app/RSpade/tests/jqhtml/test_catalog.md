# JQHTML - test catalog

| ID | Purpose (what it proves) | Type | Input | Expected (approx) | Status | Last updated |
|---|---|---|---|---|---|---|
| JQ-SM-01 | A Define with an empty body and a 20-line `<%-- --%>` header compiles to a sourcemap that names no more generated lines than the compiled file has | asset | fixture template compiled through `JqhtmlWebpackCompiler` | mapped line count <= code line count | implemented | 2026-08-27 |
| JQ-SM-02 | That compiled template survives bundle concatenation without bare `undefined` identifiers | asset | compiled fixture through the concat RPC service | output contains no line of bare `undefined` | implemented | 2026-08-27 |
| JQ-SM-03 | A sourcemap that overruns its file is refused, naming the file and the overrun | asset | hand-built JS + 40-segment map over a 2-line file | non-zero exit, "Malformed sourcemap" naming the file | implemented | 2026-08-27 |
| JQ-CAS-01 | A component mounted on a node appended during the mounting component's own first render pass is live and paints its template | playwright | runtime-registered probe component mounting `Rsx_Default_Spinner` | instance present, template painted | implemented | 2026-08-27 |
| JQ-CAS-02 | The form loading overlay paints the registered spinner with no deferral - the canonical cascade mount | playwright | `Edit_User_Modal.show(1)`, sampled while loading | spinner circle present at first observation | implemented | 2026-08-27 |
| JQ-CAS-03 | A component name registered nowhere still resolves to the base component class (documented behavior) | playwright | `.component('Rsx_No_Such_Component_Temp')` | an instance exists | implemented | 2026-08-27 |
| JQ-TPL-01 | `extends=` template inheritance emits the parent's instructions with the child's slots | asset | fixture pair | parent markup with child slot content | planned | 2026-08-27 |
| JQ-TPL-02 | Slot data (`content('row', record)`) reaches the slot body | asset | fixture pair | slot body sees the record | planned | 2026-08-27 |
