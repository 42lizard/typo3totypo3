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

The five workflows cover authenticated resolution, link fields, RTE markup and
versions, delayed conversion, and destination refresh. They retain all 233
assertions from the former standalone checks. They use the dedicated test
MariaDB databases so HTTP requests and the test process observe the same
fixtures, including transaction and competing-connection checks. Cleanup restores
configuration and snapshots in `finally`/shutdown handlers; each case runs in a
separate PHP process to isolate TYPO3 globals.

The old `dev/test-*.php` entry points have been replaced. `dev/check.sh` remains
a development-environment smoke check, not an extension test.
