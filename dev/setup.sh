#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
for version in 13 14; do
    (
        cd "typo3-v$version"
        ddev start -y
        ddev composer install --no-interaction
        ddev exec bash /opt/typo3-to-typo3/dev/install-instance.sh
    )
done
