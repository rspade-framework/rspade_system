# Issues encountered: proxority

## php-fpm `pm = ondemand` may run fewer workers than `pm.max_children` for a burst

**What happens**: in ondemand mode php-fpm forks at most one child per readiness event on its
listening socket, and that event is edge-triggered. When several connections arrive together it
can have fewer children started than requests, and the excess waits in php-fpm's listen backlog
until a running child finishes or another connection arrives. Reproduced in the test image
(web pool of 8): eight requests dispatched by Proxority within a second found six `pool www`
children, with two connections in the backlog (`ss -xl` on `/run/php/php-fpm.sock`: Recv-Q 2).

**What it means for Proxority**: its capacity model counts N = `pm.max_children` workers. A request
it has dispatched may briefly wait in php-fpm's backlog instead of running. With ordinary requests
the wait ends as soon as any child frees (milliseconds); it lasts as long as the requests ahead of
it take. The pool stays `pm = ondemand` (owner ruling, 2026-11-03): an idle ondemand pool frees its
memory.

**What it means for the tests**: `Proxority_Live_Stack_Test` never holds a php-fpm worker until the
test releases it. A worker held that way would never free, so a request waiting in the backlog
behind it would wait for the proxy's 360 s response bound and be answered 504 - which is how an
earlier form of the flood test failed inside docker test workers. Every probe request the tests
send answers by itself (a `hold` is a fixed two seconds), every request completes, and the
scheduling assertions read the order of the proxy's event log rather than a momentary busy count.
