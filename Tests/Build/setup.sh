#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
python3 Tests/Build/prepare-instances.py
for version in 13 14; do
    (
        cd "dev/typo3-v$version"
        ddev restart -y
        ddev composer install --no-interaction
        ddev mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS db_testing; GRANT ALL PRIVILEGES ON db_testing.* TO 'db'@'%';"
        ddev exec env TYPO3_CONTEXT=Testing TYPO3_PATH_APP=/var/www/html/var/exchange-testing TYPO3_PATH_ROOT=/var/www/html/public \
            TYPO3_EXCHANGE_CONFIG=/var/www/html/var/exchange-testing/.peer-config.json \
            EXCHANGE_TEST_ORIGIN="https://t3exchange-v$version-testing.ddev.site" \
            bash /opt/typo3-to-typo3/Tests/Build/install-context.sh
    )
done
