# Install with Composer

Install into an existing Composer-based TYPO3 13.4 or 14.3 project, with PHP 8.2
or newer and the sodium extension enabled.

From the TYPO3 project's root directory:

```bash
composer config repositories.typo3-to-typo3 vcs https://github.com/42lizard/typo3totypo3
composer require 42lizard/typo3-to-typo3:dev-main --prefer-dist
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
```

There are currently no release tags. `dev-main` explicitly allows the development
branch for this package without lowering the project's global minimum stability.
Commit the resulting `composer.json` and `composer.lock` to keep deployments on
the resolved commit. The Composer package name is `42lizard/typo3-to-typo3`; the
TYPO3 extension key is `typo3_to_typo3`.

Next, [connect the instances](connections.md) and
[schedule the retry and refresh jobs](operations.md#schedule-both-jobs).

## Package contents

GitHub distribution archives exclude development installations, tests, documentation
sources/screenshots, CI workflows, agent instructions, and documentation tooling
through `.gitattributes` export rules. Runtime PHP, TYPO3 configuration, templates,
translations, schema, Composer metadata, README and license remain included.

Composer does not install a dependency's `require-dev` packages. The extension's
PHPUnit and TYPO3 testing framework dependencies therefore are not installed in
the consuming project.

An explicit `--prefer-source` installation uses a Git checkout and includes the
tracked development files. Export exclusions apply to archives, not Git clones;
they are a packaging convenience, not a security boundary. For contribution and
testing, use the full repository and its [development setup](https://github.com/42lizard/typo3totypo3#local-development).
