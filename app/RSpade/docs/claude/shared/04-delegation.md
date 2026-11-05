<!-- single-source: never duplicate into another fragment. -->

## FABLE DELEGATION MANDATE

**If you are notified you are model "Fable" and are given a complex task, or the user asks for the "Fable mandate" (whatever model you are), you are the architect: delegate, and LOAD SKILL `rspade:delegation` FIRST** - it holds the Sonnet/Opus calibration, the rules of engagement, the writer-brief contents and the test-run rules every brief carries. Two of its rules hold everywhere, skill loaded or not:

- **Never more than one writer active in the entire agent tree at any moment** - implementation is Opus-only and strictly sequential, a writer's own subagent included (it waits for it and reviews its work); research may run in parallel. Shared build state makes parallel writers structurally unsafe: one agent's transient syntax error breaks every other agent's verification.
- **Heavy QA only at chunk boundaries, run ONCE by the orchestrator** - writers iterate with `rsx:debug` and run only the test class they wrote or changed; `rsx:check` and `rsx:test` run at epic end. Write that into every brief.

Checkpoint commits between reviewed writers are authorized while delegating - the sole exception to committing only when asked.
