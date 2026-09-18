#!/usr/bin/env bash
set -euo pipefail

# Run from an installed Composer project; all writes stay in a disposable app.
source_project="$PWD"
extension_root="$(cd "$(dirname "$0")/../.." && pwd)"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
mkdir -p "$fixture/public" "$fixture/config/sites/main"
ln -s "$source_project/vendor" "$fixture/vendor"
ln -s "$source_project/composer.json" "$fixture/composer.json"
ln -s "$source_project/composer.lock" "$fixture/composer.lock"
cat > "$fixture/config/sites/main/config.yaml" <<'YAML'
# Keep this comment and custom configuration intact.
rootPageId: 1
base: 'https://development.example/'
baseVariants:
  - base: 'https://testing.example/'
    condition: 'applicationContext == "Testing"'
languages:
  - title: English
    enabled: true
    languageId: 0
    base: /
    locale: en_US.UTF-8
    navigationTitle: English
    flag: us
customSetting: preserve-me
YAML
printf 'page.999 = TEXT\npage.999.value = Custom rendering\n' > "$fixture/config/sites/main/setup.typoscript"
cp -a "$fixture/config/sites" "$fixture/expected-sites"
cd "$fixture"
export TYPO3_PATH_APP="$fixture" TYPO3_PATH_ROOT="$fixture/public" TYPO3_CONTEXT=Testing
unset TYPO3_EXCHANGE_CONFIG
export TYPO3_SETUP_ADMIN_PASSWORD
TYPO3_SETUP_ADMIN_PASSWORD="Test!$(php -r 'echo bin2hex(random_bytes(24));')"
# Before the fix, pass vendor/bin/typo3 setup to reproduce the overwrite.
if [[ $# -eq 0 ]]; then
    set -- bash "$extension_root/dev/setup-with-site-config.sh"
fi
"$@" --no-interaction --driver=sqlite --server-type=other \
    --admin-username=testing-admin --admin-email=testing@example.test \
    --project-name='Site preservation regression' --create-site=https://development.example/
diff -ru "$fixture/expected-sites" "$fixture/config/sites"
bash "$extension_root/dev/install-instance.sh"
diff -ru "$fixture/expected-sites" "$fixture/config/sites"
for context in Development Testing; do
    TYPO3_CONTEXT="$context" php <<'PHP'
<?php
$loader = require 'vendor/autoload.php';
\TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run(1, \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = \TYPO3\CMS\Core\Core\Bootstrap::init($loader);
$site = $container->get(\TYPO3\CMS\Core\Site\SiteFinder::class)->getSiteByIdentifier('main');
$expected = getenv('TYPO3_CONTEXT') === 'Testing' ? 'https://testing.example/' : 'https://development.example/';
if ((string)$site->getBase() !== $expected) {
    throw new \RuntimeException('Wrong site base for ' . getenv('TYPO3_CONTEXT'));
}
PHP
done
printf 'Site files preserved after setup and repeat installation; Development/Testing bases verified.\n'
