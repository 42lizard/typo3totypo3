# TYPO3 to TYPO3

An extension for data exchange between TYPO3 instances. The first feature is
maintainable cross-instance page links; see the [approved design](docs/cross-instance-links.md).

**[Read the documentation](https://42lizard.github.io/typo3totypo3/)** — setup,
backend screenshots, operation, and development guides.

## Beta status

Ready for beta testing on TYPO3 13.4 and 14.3, with PHP 8.2 or newer.
Start with disposable installations or staging sites. Production scheduler,
external-cache integration, and the ten-peer/10,000-reference throughput target
still need deployment-specific validation; see the [operations guide](docs/operations.md).

Report reproducible bugs in [GitHub Issues](https://github.com/42lizard/typo3totypo3/issues),
including TYPO3/PHP versions, reproduction steps, and expected versus actual
behavior. Remove tokens, encryption keys, credentials, and private content from
reports and screenshots.

Progress is tracked in [GitHub issue #1](https://github.com/42lizard/typo3totypo3/issues/1).

The repository provides two working development installations and an authenticated
public-page resolver with stable identities. [Link fields](docs/link-fields.md)
and RTE anchors convert on save and render from persistent local destination
data. [Background retries](docs/delayed-resolution.md) complete failed initial
lookups without overwriting newer edits. [Scheduled destination refresh](docs/destination-refresh.md)
updates URLs and availability and invalidates dependent page caches. The
[backend report](docs/backend-report.md) provides permission-aware status and retry
actions in English and German. See [testing](docs/testing.md) for the extension’s
PHPUnit and TYPO3 testing-framework suites. Final deployment validation remains.

## Local development

For an existing TYPO3 project, follow the
[Composer installation guide](https://42lizard.github.io/typo3totypo3/installation/).
The setup below is for developing and testing the extension itself.

Requirements: Docker and DDEV 1.24.10 or newer with trusted local HTTPS configured.
The setup was verified with DDEV 1.25.4. Both projects use PHP 8.4 and MariaDB 10.11.

From the repository root:

```bash
bash dev/setup.sh
bash dev/check.sh
```

| Instance | Frontend | Backend |
| --- | --- | --- |
| TYPO3 13 | <https://t3exchange-v13.ddev.site/> | <https://t3exchange-v13.ddev.site/typo3/> |
| TYPO3 14 | <https://t3exchange-v14.ddev.site/> | <https://t3exchange-v14.ddev.site/typo3/> |

Both logins use **admin**. Each instance gets a separate random password on first
setup, stored locally in `dev/typo3-v13/.local-credentials` or
`dev/typo3-v14/.local-credentials`. These files and TYPO3's generated system
configuration are ignored by Git. The credentials files contain the initial
passwords; changing a password in TYPO3 does not update these files.

Setup installs the committed Composer lockfiles and initializes each instance
only when its system settings do not exist. Rerunning it preserves existing
content and passwords. Each instance has its own database and a basic root page.

The repository root is the extension source. Both containers mount it read-only
at `/opt/typo3-to-typo3`; Composer symlinks the extension from there. Edit files in
the repository to change both installations. After changing package metadata,
run `ddev composer update 42lizard/typo3-to-typo3` in each instance directory;
after adding extension configuration or schema, run `ddev typo3 extension:setup`.

Run DDEV commands from the relevant instance directory, for example:

```bash
cd dev/typo3-v13
ddev typo3 --version
ddev typo3 cache:flush
ddev stop
```

Use `ddev start` there to resume it. Stop or start the other instance from its own
directory. These commands leave unrelated DDEV projects alone.

`dev/check.sh` verifies Composer configuration, the shared extension mount,
Development context, and both frontend and backend HTTP 200 responses over
verified HTTPS from each container. It never disables certificate verification.
DDEV provides [inter-project HTTPS communication](https://docs.ddev.com/en/stable/users/usage/managing-projects/#inter-project-communication).
For secure peer setup, the resolver command, and integration checks, see
[peer resolution](docs/peer-resolution.md).

Deployment and recovery: [operations guide](docs/operations.md).

Manage peers under **System → Instance connections**; see [pairing and migration](docs/connections.md).

## License

GPL-2.0-only; see [LICENSE](LICENSE).
