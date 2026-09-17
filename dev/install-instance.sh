#!/usr/bin/env bash
set -euo pipefail

# Run inside a development instance's web container, from /var/www/html.
if [[ ! -f config/system/settings.php ]]; then
    if [[ ! -f .local-credentials ]]; then
        (umask 077; php -r 'echo "Dev!" . bin2hex(random_bytes(18)) . PHP_EOL;' > .local-credentials)
    fi
    export TYPO3_SETUP_ADMIN_PASSWORD
    TYPO3_SETUP_ADMIN_PASSWORD="$(cat .local-credentials)"
    vendor/bin/typo3 setup --no-interaction \
        --driver=mysqli --host=db --port=3306 --dbname=db \
        --username=db --password=db --server-type=other \
        --admin-username=admin --admin-email=admin@example.test \
        --project-name="$DDEV_SITENAME" --create-site="$DDEV_PRIMARY_URL/"
    unset TYPO3_SETUP_ADMIN_PASSWORD
fi

vendor/bin/typo3 extension:setup --no-interaction
