#!/usr/bin/env bash
set -euo pipefail
case "$TYPO3_CONTEXT:$TYPO3_PATH_APP" in
    Testing:/var/www/html/var/exchange-testing) testing_database=db_testing ;;
    Testing/PeerC:/var/www/html/var/exchange-testing-c) testing_database=db_testing_c ;;
    *) exit 1 ;;
esac
cd "$TYPO3_PATH_APP"
if [[ ! -f config/system/settings.php ]]; then
    if [[ ! -f .local-credentials ]]; then
        (umask 077; php -r 'echo bin2hex(random_bytes(24)) . PHP_EOL;' > .local-credentials)
    fi
    export TYPO3_SETUP_ADMIN_PASSWORD
    TYPO3_SETUP_ADMIN_PASSWORD="Test!$(cat .local-credentials)"
    bash /opt/typo3-to-typo3/dev/setup-with-site-config.sh --no-interaction \
        --driver=mysqli --host=db --port=3306 --dbname="$testing_database" \
        --username=db --password=db --server-type=other \
        --admin-username=testing-admin --admin-email=testing@example.test \
        --project-name="Testing: $DDEV_SITENAME" --create-site="$EXCHANGE_TEST_ORIGIN/"
    unset TYPO3_SETUP_ADMIN_PASSWORD
fi
vendor/bin/typo3 extension:setup --no-interaction

php /opt/typo3-to-typo3/Tests/Build/seed-context.php
vendor/bin/typo3 cache:flush
