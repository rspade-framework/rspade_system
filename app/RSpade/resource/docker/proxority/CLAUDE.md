# Proxority - when to run its tests

**The proxy's own test classes are excluded from the default `rsx:test --framework` run.** They
start real proxies, fake upstreams and live-stack floods, and take minutes. Every one of them
declares `$explicit_group_only`, so a bare suite run passes over them and says so in one line.

**Run them only when a change meaningfully alters the proxy's behaviour**: the scheduler (caps,
priority, round robin, slot release), request classification (media, robots, revalidation,
loopback, whitelist patterns), streaming and buffering, header forwarding, the timeouts, the
whitelist refresh, the status socket and its CLI, or the close/open control (the closure,
draining admitted requests, loopback pass-through, the dead-closer reopen;
`Proxority_Control_Test`). Then run them once, on purpose, and read the
output:

    php artisan rsx:test --framework --group=proxority

**Never run them for an unrelated change**, as a precaution, or "to be safe". A change elsewhere in
the framework cannot alter this directory's behaviour.

Two classes in the group stay in the default run because they test application code that
unrelated changes can break, and they take seconds: `Proxority_Directives_Rule_Test` (the
`PROXORITY-01` manifest rule) and `Proxority_Routes_Command_Test` (`rsx:proxority:routes`).

How the proxy works: `README.md` in this directory. The tests: `system/app/RSpade/tests/proxority/`.
