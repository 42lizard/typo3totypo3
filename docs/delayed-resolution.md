# Delayed resolution

Unresolved eligible link fields and RTE anchors now create persistent work in
`tx_typo3totypo3_link_outcome`. The editor's original URL remains saved and usable.
A stable identity exists only after a lookup succeeds. No separate queue service
is needed, and visitor requests never run these jobs.

## Install and run

After updating the extension, run `vendor/bin/typo3 cache:flush` and
`vendor/bin/typo3 extension:setup` to install the added queue columns.
In the paired DDEV projects these schema updates have already been applied.

From either DDEV instance directory:

```bash
ddev typo3 exchange:retry --limit=10
ddev typo3 exchange:retry --list --limit=100
```

Schedule the production command once per minute, as the TYPO3 application's
operating-system user, with its usual environment and `TYPO3_EXCHANGE_CONFIG`:

```cron
* * * * * cd /path/to/typo3 && vendor/bin/typo3 exchange:retry --limit=10
```

The command is also available to TYPO3 Scheduler's console-command task. A
recurring schedule is a deployment requirement; installing the extension does
not create cron entries or Scheduler records automatically.

Each run starts at most the requested number of fields (1–100), and stops
starting work after 45 seconds. Each field lookup uses the same bounded batches,
validation and three-second network deadline as an editor save. Database waits
and the final in-progress field may extend the run beyond 45 seconds. Leases
expire after two minutes; overlapping runs skip active claims, and crashed
workers' claims can be recovered. Newer claims fence out late results from old
workers.

## Retry and attention states

Temporary failures retry after 1, 2, 4, 8, 16, 32 and then 60 minutes, capped at
one hour. Seven days after enqueueing, unresolved work changes to `attention`.
Denied credentials, unavailable or unsupported initial destinations, field-length
limits, disabled configuration, database limitations and persistence/permission
problems also require attention rather than continual automatic retries.

Use `--list` to inspect source keys, record/field/workspace identifiers, statuses,
attempt counts and next-attempt timestamps. It does not print credentials or
pasted URLs. After correcting a problem, explicitly retry its source key:

```bash
ddev typo3 exchange:retry --retry=SOURCE_KEY
```

This resets the seven-day retry window and processes that job only. It still
checks the current source before writing. Mixed RTE fields retain unresolved
anchors; verified anchors can convert independently. A denied anchor pauses
that field for administrator attention. Unsupported anchors do not create an
endless retry loop.

Previously recorded outcomes from earlier extension versions are adopted in
bounded batches. Only a matching saved value and workspace can become an active
job. This does not scan or bulk-convert unrelated content.

## Write safety and publication

The worker performs HTTP lookups before acquiring content locks. It then locks
the content row and job row, checks the field hash, full record fingerprint,
workspace and job generation/claim, and writes through DataHandler in the same
database transaction. DataHandler supplies permissions, history and normal cache
invalidation. The command uses TYPO3's authenticated CLI service account, scoped
temporarily to the job's workspace, without changing a human user's workspace.

A newer edit wins. A stale lookup is discarded and current content is considered
on a separate attempt. Deleted sources are never revived. Published drafts are
not updated by their old job; their live counterpart receives a separate job.
The worker never issues publication commands, auto-creates another version or
bypasses workspace restrictions. Source-page caches are invalidated again after
commit to cover requests racing the transaction.

Atomic writes require the source, outcome table, `sys_history` and `sys_log` on
the same database connection. MariaDB/MySQL tables must use InnoDB. PostgreSQL
uses DBAL row locks; integration verification currently covers the DDEV MariaDB
installations. Other platforms or split database mappings pause the job with
`database` status. Keep the outcome table with its corresponding content when
copying an environment, and configure explicit environment-specific peers.

Only initial URL conversion is implemented here. Periodic refresh of already
verified destination URLs and the backend report remain separate milestones.

## Verification

Run from the repository root after [test setup](testing.md):

```bash
bash Tests/Build/run.sh --filter RetryTest
```

Checks include real peer recovery, backoff/expiry/manual retry, source edits
arriving during lookups, a competing database connection at the final write,
lease recovery, interrupted transactions, RTE preservation, and actual workspace
creation/publication. Temporary content, destinations and workspaces are cleaned
up afterward. The development projects now include `EXT:workspaces` for these
checks.
