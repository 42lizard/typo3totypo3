#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
for version in 13 14; do
    (
        cd "typo3-v$version"
        ddev typo3 --version
        ddev composer validate --strict --no-check-publish
        ddev exec php /opt/typo3-to-typo3/dev/check-instance.php
        for peer in 13 14; do
            for path in / /typo3/; do
                url="https://t3exchange-v$peer.ddev.site$path"
                status="$(ddev exec curl --fail --silent --show-error \
                    --max-time 15 --output /dev/null --write-out '%{http_code}' "$url")"
                [[ "$status" == 200 ]] || { echo "Unexpected HTTP $status: $url" >&2; exit 1; }
                echo "TYPO3 $version → $url: HTTPS 200"
            done
        done
    )
done
