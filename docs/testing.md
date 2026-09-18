# PHPUnit tests

All extension tests live in `Tests/`. Development dependencies provide PHPUnit
11 and TYPO3 testing framework 9.7 for TYPO3 13 and 14. Tests never run against
the development content databases.

## GitHub Actions

The `PHPUnit` workflow runs on pushes, pull requests, and manual dispatches.
It runs the existing isolated functional suite against both committed TYPO3
13/14 lockfiles on PHP 8.2 and 8.4. These are PHPUnit tests using TYPO3's testing
framework, rather than a separate unit-test suite. SQLite fixtures are created
on the disposable runner; no DDEV, credentials, or live peers are needed.
The paired-instance HTTPS integration suite remains a local DDEV check.

## Local execution

Prepare the Testing contexts in the existing DDEV projects:

```bash
bash Tests/Build/setup.sh
```

Each existing project (`dev/typo3-v13` and `dev/typo3-v14`) serves two nginx
vhosts: the normal development hostname and `t3exchange-v13-testing.ddev.site`
or `t3exchange-v14-testing.ddev.site`. The second vhost sets `TYPO3_CONTEXT=Testing`.
Project-owned `config/system/additional.php` checks the context and includes
`additionalTesting.php`, which selects `db_testing` instead of `db`.
Site YAML uses a `baseVariants` condition (`applicationContext == "Testing"`)
to select the Testing hostname.

The ignored `var/exchange-testing` directory isolates Testing site configuration,
caches, settings and peer credentials while sharing the installed code/vendor.
Its `additional.php` loads the project-owned file. Setup copies the committed
site configuration, including its context variant, and seeds only a test root page.
No development database content or credentials are copied. There are no extra
DDEV projects or containers. Setup is repeatable and does not reset data.

Run both suites on both supported versions:

```bash
bash Tests/Build/run.sh
```

Keep both existing DDEV projects running for the HTTPS integration suite.
For individual runs, change into `dev/typo3-v13` or `dev/typo3-v14`.

## Isolated functional tests

```bash
ddev exec env TYPO3_PATH_ROOT=/var/www/html/public vendor/bin/phpunit -c /opt/typo3-to-typo3/Tests/Functional/phpunit.xml
```

These classes extend `TYPO3\TestingFramework\Core\Functional\FunctionalTestCase`.
The framework creates a further isolated TYPO3 installation and SQLite database,
loads the extension and CSV fixtures, and resets data between tests. The tests
use their own peer configuration and prohibit network calls.

Coverage includes module/record/field/language/workspace authorization, direct
retry actions, generation and lease checks, CSRF, connection repair, consolidated
warnings, administrator notifications, English/German XLF completeness and core
Fluid rendering. Use `--filter LinkReportTest` or a method name for a focused run.

Outside DDEV, install the development dependencies in a TYPO3 Composer project,
set `TYPO3_PATH_ROOT` to its public directory, and run its `vendor/bin/phpunit`
with the extension-contained configuration. SQLite is the default test backend;
the framework also supports database environment overrides for other engines.

## Paired-instance integration tests

```bash
ddev exec env TYPO3_CONTEXT=Testing TYPO3_PATH_APP=/var/www/html/var/exchange-testing TYPO3_PATH_ROOT=/var/www/html/public TYPO3_EXCHANGE_CONFIG=/var/www/html/var/exchange-testing/.peer-config.json EXCHANGE_TEST_ORIGIN=https://t3exchange-v13-testing.ddev.site vendor/bin/phpunit -c /opt/typo3-to-typo3/Tests/Integration/phpunit.xml
```

Use the v14 Testing origin when running in that project. These PHPUnit cases
exercise actual HTTPS communication between the two Testing vhosts. They require
`Testing` context and the isolated application path; a normal development-context
invocation fails before TYPO3 bootstraps or fixtures are written. Run one major
version's suite at a time.

The workflows cover authenticated resolution, link fields, RTE markup and
versions, delayed conversion, destination refresh, and the usage/notification lifecycle. They use the dedicated test
MariaDB databases so HTTP requests and the test process observe the same
fixtures, including transaction and competing-connection checks. Cleanup restores
configuration and snapshots in `finally`/shutdown handlers; each case runs in a
separate PHP process to isolate TYPO3 globals.

The old `dev/test-*.php` entry points have been replaced. `dev/check.sh` remains
a development-environment smoke check, not an extension test.

## Cross-version exchange and rollback

Run from the repository root with host PHP and both existing DDEV projects running:

```bash
php dev/typo3-v13/vendor/bin/phpunit --no-configuration Tests/Paired/ExchangePairTest.php
```

This test controls only the v13/v14 Testing contexts. It exercises usage in both
HTTPS directions, renames, unavailability, last-reference removal, and the exact
rollback commit documented in [usage and notifications](usage-notifications.md).
The archived extension is selected only in a CLI process; shared Composer files
and the Development backend are unchanged. The legacy-server response is passed
through the current client to test explicit removal of the environment binding.

Do not run this concurrently with the integration suite: both use `db_testing`.
Fixture backups remain in `var/exchange-testing/paired-fixture-backup.json` if the
process crashes. Recover each affected context before rerunning:

```bash
cd dev/typo3-v13 # repeat for v14
printf '%s' '{"operation":"cleanup"}' | ddev exec env TYPO3_CONTEXT=Testing TYPO3_PATH_APP=/var/www/html/var/exchange-testing TYPO3_PATH_ROOT=/var/www/html/public php /opt/typo3-to-typo3/Tests/Paired/fixture.php
```

## Opt-in capacity tests

These PHPUnit tests use separate testing-framework SQLite databases. They do not
create DDEV projects or place records in Development. They are deliberately
separate from the fast CI suite and can take many minutes at full scale. The
notification harness requires CLI `pcntl`, available in these DDEV containers.

```bash
cd dev/typo3-v14 # or v13
# Small pipeline check, not evidence for the full capacity envelope:
ddev exec env EXCHANGE_CAPACITY_SMOKE=1 TYPO3_PATH_ROOT=/var/www/html/public php -d memory_limit=512M vendor/bin/phpunit -c /opt/typo3-to-typo3/Tests/Performance/phpunit.xml
# Full workloads:
ddev exec env TYPO3_PATH_ROOT=/var/www/html/public php -d memory_limit=512M vendor/bin/phpunit -c /opt/typo3-to-typo3/Tests/Performance/phpunit.xml
```

`SourceCapacityTest` indexes 100,000 content records with one million link
occurrences, repeated references, hidden records, translations and workspace
drafts, then verifies transmission and authoritative snapshot completion for
10,000 distinct references. The simulated serving side uses the real receiver,
registry, site authorization and resolver. Its additional 10,001 pages share the
fixture database and are also visited by the source scan.

`NotificationCapacityTest` uses ten isolated consumer databases, 10,000 references
per consumer, production receipt/refresh workers, and tagged TYPO3 page caches.
It checks normal change, a 100,000-relationship burst, and recovery after a modeled
24-hour outage. The outage advances durable retry timestamps instead of waiting
one day; it asserts that the remaining hourly backoff is respected before recovery.
Transport adds 100 ms per request. This simulates peers; real HTTPS authentication
and cross-version behavior are checked separately above.

The notification harness forks ten independent consumer workers, one per
isolated consumer database, and retains the maximum completion time across them.
The serving worker has one invocation at a time.

Timing calculations place measured worker invocations on a once-per-minute
schedule, retaining overruns. They include refresh and cache invalidation and
report maximum completion, not average latency. The recovery figure starts at the
first eligible successful retry; remaining hourly backoff is reported separately.
See [acceptance evidence](usage-notifications.md#acceptance-evidence) for results
and their environment limits.
