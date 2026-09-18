#!/usr/bin/env bash
set -euo pipefail

# TYPO3 --create-site replaces existing site YAML and (on v14) TypoScript.
# Keep user/repository configuration while still creating the initial page data.
backup="$(mktemp -d)"
restore_site_config() {
    status=$?
    if [[ -d "$backup/sites" ]]; then
        if ! cp -a "$backup/sites/." config/sites/; then
            echo "Could not restore site configuration; backup retained at $backup" >&2
            exit 1
        fi
    fi
    rm -rf "$backup"
    exit "$status"
}
if [[ -d config/sites ]]; then
    cp -a config/sites "$backup/sites"
fi
trap restore_site_config EXIT
vendor/bin/typo3 setup "$@"
