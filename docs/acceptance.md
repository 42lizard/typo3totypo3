# Cross-instance linking acceptance evidence

This records the reproducible checks for [issue #7](https://github.com/42lizard/typo3totypo3/issues/7).
All generated data stays in Testing contexts or testing-framework databases.
It does not certify an unmeasured production server, scheduler, CDN or reverse proxy.

## Reproduce

From the extension repository, with the two existing DDEV projects available:

```bash
bash Tests/Build/setup.sh
bash Tests/Build/run.sh
python3 Tests/Build/run-self-integration.py
php dev/typo3-v13/vendor/bin/phpunit --no-configuration Tests/Paired/ExchangePairTest.php
```

Run these sequentially: the integration and paired suites share the isolated
`db_testing` databases. The same-version runner temporarily gives each Testing
instance a self grant, runs its HTTPS integration suite, and restores the original
peer file byte-for-byte in `finally`. It refuses an existing
`var/exchange-testing/.peer-config.self-test-backup.json`. After a killed process,
restore that backup to `.peer-config.json` in the same Testing directory before
rerunning. Never copy it into Development.

Run the full polling workload separately in each project:

```bash
cd dev/typo3-v14 # repeat with v13
ddev exec env TYPO3_PATH_ROOT=/var/www/html/public vendor/bin/phpunit -c /opt/typo3-to-typo3/Tests/Performance/phpunit.xml --filter RefreshCapacityTest
```

The [testing guide](testing.md) documents fixture recovery, the third peer and
the separate usage/notification capacity workloads. The polling test uses the
TYPO3 testing framework. The save-budget test uses the existing MariaDB Testing
context because the retry worker requires transactional row locking; SQLite
cannot verify that persistence contract.

## Workflow and security matrix

| Requirement | Reproducible evidence |
| --- | --- |
| TYPO3 13 → 14 and 14 → 13 | Normal integration suites exercise authenticated saves, link fields, RTE and delayed recovery against the other Testing instance; the paired suite exercises usage, notification, refresh and rollback in both directions. |
| TYPO3 13 → 13 and 14 → 14 | Same-version runner exercises the complete integration suite over HTTPS using an explicit self grant. These runs use one installation per version; independent-instance trust is checked separately by the A/B/C suite. |
| Link fields, RTE, text, attributes, language and suffixes | `LinkFieldsTest` and `RteTest`: DataHandler saves and standard TYPO3 typolink/parseFunc rendering, including duplicates and unsupported editing surfaces. |
| Rename, tree move, domain/API changes | `RefreshTest`: persistent references, real identity refresh and dependent cache invalidation; controlled responses exercise explicit endpoint/domain corrections and old-origin aliases. |
| Copy, delete, recreate and restore | `PageLifecycleTest`: actual DataHandler commands, distinct identities for copies/recreated pages, and recovery of the original identity on restore. |
| Move into an unauthorized site | `PageLifecycleTest`: actual cross-site move, no metadata under the old grant, successful resolution only with the new site's grant. |
| Missing translation and public-access loss | `ResolverTest` and `RefreshTest`: hidden/missing translations, inherited restrictions, scheduling, deletion and unavailable rendering without language fallback. |
| Offline saves and recovery | `SaveBudgetTest` below; `RetryTest` also checks backoff, seven-day attention state, audit history and permission/persistence guards. |
| Editor races and workspace lifecycle | `RetryTest`: edits during lookup, competing final writes, publication, replacement of a draft during lookup and workspace deletion; workers cannot publish drafts or alter another version. |
| Duplicate work, interruption and overlap | `RetryTest`, `RefreshTest` and notification functional tests: leases, transaction rollback, generation guards and safe recovery. |
| Temporary failures versus deletion | `RefreshTest`: timeout, rate limiting, malformed/schema/identity responses and generic proxy errors retain last-known URLs; confirmed unavailability removes clickability. |
| Revocation and repair | `ConnectionsTest`, `RefreshTest` and A/B/C checks: denied peers lose clickability, pause retries and require verified recovery. |
| Cache clear during outage | `LinkFieldsTest`, `RteTest` and `RefreshTest`: rendering uses persistent data without HTTP. |
| Fixed endpoints, origins and resource limits | `ResolverTest`: exact origins, forbidden URL shapes, approved-site checks, request/response bounds and rate limits; unconfigured pasted URLs cause no outbound requests. |
| Verified TLS and redirect refusal | `SaveBudgetTest`: real cURL requests to a loopback HTTPS fixture using DDEV's trusted certificate; a wrong certificate hostname is rejected with cURL error 60 and a real HTTP 302 is rejected without following it. |
| Secrets, administration and reports | Functional connection/report suites check encrypted storage, no secret redisplay, CSRF, editor/admin permissions, language, workspace and direct-action authorization. |
| Database-only clone | Functional tests isolate changed keys and contexts individually; the A/B/C suite copies encrypted connection rows into C's independent database and verifies configuration, resolution and refresh are blocked with zero HTTP calls. |
| Full server/configuration clone | Same key and context can decrypt credentials. Apply the documented [pre-start isolation and re-pairing procedure](connections.md#encryption-and-environment-isolation); this is an operational boundary. |

## One save with 100 distinct links

`SaveBudgetTest` submits ten records in one DataHandler operation. Each record
has one link field and nine RTE links, for 100 distinct destinations across ten
configured peers. A Python standard-library HTTPS fixture runs on loopback inside
the existing web container. It provides controlled responses and never fetches
submitted URLs. cURL still performs TLS verification and enforces real timeouts.

Two workloads use 550 ms responses and unresponsive peers taking five seconds.
Both must persist the content, leave unverified URLs ordinary, create pending
work, and finish all 100 distinct references after recovery. Retry timestamps are
advanced only for fixture jobs rather than sleeping through minute boundaries.
Record fingerprints can require a second retry round when conversion of one field
changes the same record; the test checks eventual completion without bypassing
those guards.

Measured on 2026-09-18, the slow workload used approximately 2.83–2.88 seconds of
cURL transfer time. The unresponsive workload used approximately 3.00–3.02 seconds.
Total DataHandler saves took approximately 3.26–3.38 seconds including local
processing and database writes. The configured **network** deadline is three
seconds; the test allows 50 ms for cURL timer and host scheduling granularity.
This is not a three-second bound on all local TYPO3 processing.

## Five-minute polling freshness

`RefreshCapacityTest` seeds one consuming instance with 10,000 distinct
destinations distributed evenly across ten configured simulated peers. It uses
the production client, refresh worker, destination store and TYPO3's database
page-cache backend, with 10,000 tagged entries and an unrelated control entry.
It verifies every updated URL and every affected cache invalidation. Notifications
are disabled for this workload, so they cannot conceal a polling failure.

Each simulated peer request adds a measured 100 ms for healthy transport and
remote processing. Requests contain at most 50 references. The default worker
limit is 5,000 destinations, with a 45-second start-work deadline. A run starts
each minute. The calculation includes the full polling interval, a conservative
60-second scheduler phase and measured worker durations. Serialized runs that
overrun a minute wait for the next minute tick. Initial
due times are advanced in the fixture; the test does not sleep for the modeled
waiting intervals. This is a per-instance capacity measurement, not a concurrent
ten-server network benchmark.

The original three-minute interval failed at **338.57 seconds**. Normal polling
now uses two minutes, retaining the existing failure backoff and leases:

| Version | First run | Second run | Worst-phase completion |
| --- | ---: | ---: | ---: |
| TYPO3 13.4.35 | 39.83 s | 41.59 s | **281.59 s** |
| TYPO3 14.3.7 | 38.15 s | 38.70 s | **278.70 s** |

Each run processed 5,000 destinations; each complete test passed 20,614 assertions.
These results used PHP 8.4.24, testing-framework SQLite and TYPO3 database caches
inside DDEV on the Apple M1 Pro host (32 GiB RAM; Docker limited to five CPUs and
approximately 12 GiB RAM). Capacity runs were performed sequentially. The real
A/B/C trust tests and save-budget tests use separate MariaDB Testing databases.

Repeat the workload on deployment hardware with representative peer latency and
cache configuration. Configure and monitor the [recurring commands](operations.md#schedule-both-jobs);
installation does not create a scheduler. External caches must have compatible
purging/expiry, and stale serving must be accounted for. These measurements cover
TYPO3-managed output; they do not include CDN propagation or an unhealthy peer's
retry backoff.
