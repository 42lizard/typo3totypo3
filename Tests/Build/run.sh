#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
for version in 13 14; do
    (
        cd "dev/typo3-v$version"
        ddev exec bash /opt/typo3-to-typo3/Tests/Build/check-site-preservation.sh
        ddev exec env TYPO3_PATH_ROOT=/var/www/html/public vendor/bin/phpunit -c /opt/typo3-to-typo3/Tests/Functional/phpunit.xml "$@"
        ddev exec env TYPO3_CONTEXT=Testing TYPO3_PATH_APP=/var/www/html/var/exchange-testing TYPO3_PATH_ROOT=/var/www/html/public \
            TYPO3_EXCHANGE_CONFIG=/var/www/html/var/exchange-testing/.peer-config.json \
            EXCHANGE_TEST_ORIGIN="https://t3exchange-v$version-testing.ddev.site" \
            vendor/bin/phpunit -c /opt/typo3-to-typo3/Tests/Integration/phpunit.xml "$@"
    )
done
