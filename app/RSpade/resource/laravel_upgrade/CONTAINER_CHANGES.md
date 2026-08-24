# Container / toolchain changes needed for the Laravel upgrade

Running note. Every entry here has to be reflected in the dev container image
before this upgrade is considered done - the box we are working on and the
shipped image must not drift.

Authoritative Dockerfile: `system/app/RSpade/resource/docker/Dockerfile`
(the running container was built from it; `/docker/Dockerfile` is its copy in
the image).

Shape of the PHP install, for reference:

```
FROM ubuntu:24.04 AS base
ENV PHP_VERSION=8.4
RUN add-apt-repository -y ppa:ondrej/php \
    && apt-get install -y php${PHP_VERSION} php${PHP_VERSION}-fpm ... \
    && update-alternatives --set php /usr/bin/php${PHP_VERSION}
```

One `PHP_VERSION` variable drives the whole install, so a PHP bump is a
one-line change plus a rebuild.

---

## Findings so far

### PHP: NO CHANGE REQUIRED

| | Version |
|---|---|
| Container ships | **8.4** (`PHP_VERSION=8.4`, ondrej PPA) |
| Box actually runs | **8.4.24** |
| Laravel 11 needs | 8.2+ |
| Laravel 12 needs | 8.2+ |
| Laravel 13 needs | **8.3+** |

8.4 clears every hop including the final one. The Dockerfile needs no PHP change
for this upgrade.

`composer.json`'s `php` constraint still gets bumped (`^8.1` -> `^8.2` at the L11
hop, `^8.3` at the L13 hop) so the declared floor matches what Laravel requires -
that is a manifest statement, not a container change.

### Composer: NO CHANGE REQUIRED

Box has **2.10.2** (2026-07-01), current. It is new enough to enforce the
security-advisory policy, which matters below.

### Security-advisory blocking: PER-INVOCATION OVERRIDE ONLY

Composer 2.10 refuses to install packages with known advisories. **Every Laravel
11 release is affected** - `PKSA-mdq4-51ck-6kdq` (CRLF injection in the default
email rule) covers `>=11.0.0,<12.0.0` with no fixed 11.x, because Laravel 11 is
past its security-support window. There is no clean Laravel 11 to land on.

Owner ruling: pass through 11 anyway, since it is an intermediary hop we never
publish. Done with the per-invocation flag:

```
composer update ... --no-security-blocking
```

**Deliberately NOT configured in composer.json.** Putting
`policy.advisories.ignore` in the manifest would silence the check permanently,
for every future install, including the ones where it matters. The flag applies
to one command and leaves no trace.

Advisory-free floors, for the later hops:

- Laravel 12: **>= 12.61.1**
- Laravel 13: **>= 13.12.0**

Both are satisfied by resolving `^12` / `^13` to their latest, which is what we
are doing. The flag should NOT be needed once we are off 11 - if a later hop
still requires it, stop and look at why.

---

## Checklist for the container rebuild - FINAL

The upgrade is complete (Laravel 13.26.1) and **the container needs no changes at
all**. Recorded here so the conclusion is not re-derived later.

- [x] **PHP version - NO CHANGE.** 8.4 clears Laravel 13's 8.3 floor. The
      Dockerfile's `PHP_VERSION=8.4` stands.
- [x] **Composer version - NO CHANGE.** 2.10.2 is current.
- [x] **PHP extensions - NO CHANGE.** Nothing new was required across all three
      hops; the full suite (1778 tests) passes on the existing extension set.
- [x] **Advisory override - NOT NEEDED beyond Laravel 11.** Both the 12 and 13
      updates reported "No security vulnerability advisories found" and ran
      without the flag, exactly as the recorded floors predicted.

**Still worth doing, but not because anything changed:** rebuild the dev image
once from the current Dockerfile and smoke-test it, to confirm a FRESH container
produces the same result as this incrementally-upgraded box. The Dockerfile
itself needs no edit for that.

One note for whoever does it: `composer install` inside a fresh image now
succeeds, which it did NOT before this work - the lockfile used to reference
`./packages/ignition` path repositories that do not exist in the repo. See the
README's section 2.
