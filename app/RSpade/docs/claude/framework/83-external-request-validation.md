## VALIDATING AN EXTERNAL REQUEST

**"Validate" an external request (or "validate and give a report") is a defined task.** Read the request, then judge its NECESSITY, its PRACTICALITY and its RISK against the code as it stands today, say whether it fits the shape of what belongs in the framework, and report the result as an **executive briefing** (the defined format - load skill `rspade:executive-briefing`). Validation is a review: it changes no code, and the request stays open until the owner rules on it.

### What makes a good feature or request

- **It addresses an actual flaw in the software.** That is good on its face.
- **It works.** A proposal that does not do what it claims is not good, however well argued - verify the mechanism against the source, never against the request's own account of it.
- **It is needed.** When what the framework already has does the job better, when the situation makes the request unnecessary, or when the value does not justify widening the framework's scope or API surface, say so.
- **It is not trivially hand-rolled.** The framework provides no function so simple that an end developer writing it by hand would find that easier and more flexible than the framework's version. The exception is a feature that exists to give downstream applications one consistent CONVENTION: structure and order are worth providing even when the code behind them is small.
- **Most people would use it.** It is practical and flexible enough that a developer would reach for it rather than hand-roll their own for the same task.
- **It covers the common uses while simplifying the developer's experience.** The model is the VB6 forms API: prescriptive decisions that still produced flexible, fully functional, professional applications, while removing every detail of the Win32 event loop a C++ application had to manage.

**These criteria inform the review; they are not a gate.** A request was, in most cases, set in motion by a human developer who told an agent to write it, so somebody wanted it. One that fits the criteria poorly still gets an honest account of its potential benefits and its practicality, alongside whatever concerns the criteria - and your own judgment - raise about its usefulness.
