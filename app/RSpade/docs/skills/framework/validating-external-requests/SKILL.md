---
name: validating-external-requests
description: "Validating an external request - judging NECESSITY, PRACTICALITY and RISK against the code as it stands, verifying the proposed mechanism in the source rather than the request's own account, the criteria for a good feature (an actual flaw, it works, it is needed, not trivially hand-rolled, most would use it, the VB6-forms standard), reported as an executive briefing with no code changed. Use when asked to 'validate' a request in docs.dev/external_requests/ or 'validate and give a report'."
---

# Validating an external request

`docs.dev/external_requests/` holds pre-authorized requests from external environments the owner controls (change requests drafted downstream, usually by an agent a human developer instructed). **"Validate" one - or "validate and give a report" - is a defined task**, and it is a REVIEW:

1. Read the request in full.
2. Judge its **NECESSITY**, its **PRACTICALITY** and its **RISK** against the code as it stands TODAY - open the files it names and the subsystem around them.
3. Say whether it fits the shape of what belongs in the framework (the criteria below).
4. Report the result as an **executive briefing** - the defined format; load skill `rspade:executive-briefing` before writing it.

**Validation changes no code**, and the request stays OPEN until the owner rules on it - the `INDEX.md` row is not moved to a disposition and the file is not archived by a validation (the INDEX/archive workflow: skill `rspade:shipping-a-contract-change`, External requests).

## What makes a good feature or request

- **It addresses an actual flaw in the software.** That is good on its face.
- **It works.** A proposal that does not do what it claims is not good, however well argued - **verify the mechanism against the source, never against the request's own account of it.** A request describes the code as its author understood it; the source is what runs.
- **It is needed.** When what the framework already has does the job better, when the situation makes the request unnecessary, or when the value does not justify widening the framework's scope or API surface, say so.
- **It is not trivially hand-rolled.** The framework provides no function so simple that an end developer writing it by hand would find that easier and more flexible than the framework's version. The exception is a feature that exists to give downstream applications one consistent CONVENTION: structure and order are worth providing even when the code behind them is small.
- **Most people would use it.** It is practical and flexible enough that a developer would reach for it rather than hand-roll their own for the same task.
- **It covers the common uses while simplifying the developer's experience.** The model is the VB6 forms API: prescriptive decisions that still produced flexible, fully functional, professional applications, while removing every detail of the Win32 event loop a C++ application had to manage.

## The criteria inform; they do not gate

A request was, in most cases, set in motion by a human developer who told an agent to write it, so somebody wanted it. One that fits the criteria poorly still gets an honest account of its potential benefits and its practicality, alongside whatever concerns the criteria - and your own judgment - raise about its usefulness. The recommendation is yours to make; the ruling is the owner's.

## Checklist for the briefing

- The problem the request addresses, in plain language, and whether it is real today (reproduced or traced in the source, with the evidence stated).
- Whether the proposed mechanism works as described, and where it does not.
- What it would cost: API surface added, contracts changed, the six-artifact mandate it would trigger (skill `rspade:shipping-a-contract-change`), and whether a `breaking_changes` document would be owed.
- Risk: what could break, for whom, and how loudly.
- A recommendation - accept, accept reshaped, or decline - with the one decision only the owner can make called out.
