---
name: executive-briefing
description: "Writing an executive briefing on an issue, feature or incident for a stakeholder who owns the decision but is not holding the code in their head. Use when the user asks for 'an executive briefing', 'the executive summary', to 'brief me', to 'explain this to me like a manager' or 'like a project manager', or says they need to 'get my bearings' / 'back up a step' on something agents have been working on - especially when they mention running several agents or threads at once, or that they only had a passing brief on the issue."
---

# Executive briefing

"An executive briefing" is a DEFINED FORMAT, not a synonym for a summary. It is a message in the conversation - never a file, never an artifact - that lets the owner of a decision re-acquire a thread well enough to rule on it.

It governs this one named request. Ordinary technical reporting to the same person is unchanged: they are usually a programmer who wants file paths every other day of the week.

---

## Who you are writing for

A stakeholder who is **smart, professional, and owns the decision** - and who is not holding the codebase in their head. Typically they are running several agents in parallel, got a one-line brief that something was a problem, and now need to re-acquire the thread well enough to rule on it.

Assume they are **capable of any level of detail and short of the context to place it.** The failure is never "too simple". The failure is a paragraph they cannot act on because it assumes they remember what some method three layers down does.

---

## The four parts, in this order

**1. The problem, in one paragraph, before anything else.** What was actually wrong, in the language of the business, not the code. State the user-visible symptom and the thing underneath it. Name the consequence. If one sentence makes the whole issue click, it goes here and nowhere else.

**2. The ruleset as it stands now.** Not what changed - what is TRUE today. This is the part they need in order to reason about anything else, and the part an agent most often skips, because to the agent it is the boring, already-settled bit. Bullets. Bold the rule, explain it in plain language underneath. Where a rule looks arbitrary, give the one-line reason: an unexplained rule is one they cannot apply themselves later.

**3. What it cost and changed - the consequential effects.** Program logic and DATA, separately if both moved. This is where numbers belong: how many records, how much money, how much slower, how many places were wrong. **Lead with the effects that are already live**, then the latent ones. If something was visibly broken in production, say so plainly. If a cost was taken knowingly, say it was knowing and say what it buys.

**4. What you recommend next.** Separate **the decision only they can make** from **the work that follows**. The decision goes first, alone, flagged as theirs. Then the rest in priority order, each with its reason. If something should deliberately NOT be done yet, say so and why - a recommendation to wait is a recommendation.

Four parts, each as long as its content warrants. A medium issue is about the size of the example below; an incident involving money reasonably runs longer.

---

## Tone rules

- **No file paths, no function names, no class names, no code blocks.** None. Refer to a thing by what it does: "the invoice list", "the nightly import", "the rate lookup".
- **Numbers beat adjectives.** "4,180 of 4,180 records" and "0.7 ms to 23 ms" land; "most records" and "somewhat slower" do not.
- **Bold the claim, not the jargon.** Someone reading only the bold text should come away with the correct picture.
- **Short paragraphs under bullets, not bullets of fragments.** The reader is not skimming a slide; they are rebuilding a mental model.
- **Say what is NOT known.** "This has never met real data" is one of the most valuable lines a briefing can carry. It is not weakness; it is what stops a bad decision.
- **Own the misses plainly.** If the work took two passes because the first missed something, say that, say what was missed and why it was missable. A briefing that reads as self-congratulation is not trusted twice.
- **End with where the full write-up lives**, in one line, so they can hand it onward or go deeper without asking. This is the one place a document may be named.

---

## Anti-patterns - what an agent does by default and must not do here

- **Leading with the fix.** The instinct is to report what was changed. The reader cannot evaluate a fix before they understand the rule.
- **A chronological narrative.** "First we found X, then we tried Y." They do not need the journey; they need the state.
- **Burying the decision.** If something needs their yes, it sits at the top of the recommendations, labelled as theirs.
- **Hedging a live defect.** "There may be an edge case where..." when the truth is "this was wrong on screen for every imported record until yesterday."
- **Reflexive length.** A briefing as long as the implementation document is not a briefing.
- **Writing it to a file.** The reader asked precisely because they do not want to go and open something.
- **Restating the format.** Never mention the format, this skill, or that you are following one.

---

## Worked example

An illustrative briefing on an invented defect - prices in an order system. Note what it does NOT contain: no paths, no symbols, and no account of how the work was done.

> **The problem**
>
> Orders were being priced from the product's CURRENT price every time anybody looked at them, instead of the price the customer agreed to. So when a price was raised, every unpaid order for that product quietly got more expensive, and the invoice we later sent did not match the confirmation the customer already had. Nobody noticed for three weeks because each screen was internally consistent - it was only wrong compared with an email we had already sent.
>
> **The ruleset as it stands now**
>
> - **An order line carries its own price, fixed at the moment the order is placed.** Changing a product's price afterwards changes future orders and nothing else. This is the rule everything below follows from.
> - **A fixed price can be changed only by an explicit, recorded adjustment** - who, when, and why. There is no way to change it as a side effect of editing something else. Deliberate: a price that can drift silently is the defect we just had.
> - **Totals are added up from the line prices, never stored separately.** One source for the number means the list, the order page and the invoice cannot disagree.
> - **Discount codes are applied once, at ordering, and their effect is stored on the line.** A code that expires later does not reach back into orders that already used it.
>
> **What it cost and changed**
>
> - **312 open orders were showing a price different from the one the customer was quoted.** All 312 have been reset to the confirmed price. **41 of them had already been invoiced at the wrong amount** - 38 too high, 3 too low - for a net overcharge of $2,946.10.
> - **Nothing was lost.** Every corrected line kept a record of the value it had before.
> - **The fix took two passes, and the second is the part worth knowing.** The first corrected the order page and the invoice. The monthly revenue report reads orders by a separate path, and nobody checked it - it over-reported September by the same $2,946.10 until the second pass. **That figure went into one report that has already been circulated.**
> - **One cost taken knowingly:** placing an order now does one more lookup, about 4 milliseconds. It buys a price that cannot change after the fact.
> - **The honest caveat:** the explicit-adjustment path has been tested and **has never been used on a real order.**
>
> **What I recommend next**
>
> - **One decision only you can make: the 38 customers who were overcharged.** I can issue the credits automatically, or produce the list for someone to contact them first. Refunding without a word is faster; it is also the version where a customer learns we got it wrong from their bank statement. **Nothing else is blocked on this, but the credits are.**
> - **Send a corrected September revenue figure** to whoever received the report. Highest-value next step after the decision, and it takes five minutes.
> - **Make one real price adjustment on a live order and look at it** on the order page, the invoice and the report. That converts "tested" into "proven".
> - **Leave the 3 undercharged orders alone** unless you want them pursued - $61.40 in total, and collecting it costs more goodwill than it returns.
> - **One thing to plan for, not fix:** currencies. Every price is fixed in dollars today. The day we sell in a second currency, "the price the customer agreed to" has to include which currency - a design question, not a bug.
>
> The full write-up, with every affected order listed, is in the incident notes under "order price drift".
