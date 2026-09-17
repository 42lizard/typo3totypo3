# Deploy and operate cross-instance links

## Pair the instances

Use the JSON schema and identity rules in [peer configuration](peer-resolution.md#server-configuration).
Create one outgoing connection for each remote instance UUID and a corresponding
incoming grant on that instance. Use independent random tokens for each direction;
store the incoming token's SHA-256 hash on the receiver. Limit each grant to the
site identifiers it needs. API endpoints must use HTTPS with valid certificates.

Keep `TYPO3_EXCHANGE_CONFIG` outside the document root and source control, readable
only by the application account. Provide the same environment to PHP-FPM and CLI
jobs. Give Testing, Development and production separate credentials and endpoint
mappings. In TYPO3 site YAML, select environment-specific bases through
`baseVariants`; do not infer peer trust from pasted URLs or redirects.

After deployment, run from the TYPO3 project directory:

```bash
vendor/bin/typo3 extension:setup
vendor/bin/typo3 cache:flush
vendor/bin/typo3 exchange:resolve partner https://partner.example/
```

Replace `partner` and its URL with the configured outgoing connection. Repeat in
the other direction if both instances author links. A successful response should
contain the expected remote instance UUID. Never put bearer tokens in command
arguments, screenshots, tickets or logs.

## Schedule both jobs

Run these as the application's operating-system account with its normal PHP and
`TYPO3_EXCHANGE_CONFIG` environment. Replace the project path:

```cron
* * * * * cd /path/to/typo3 && vendor/bin/typo3 exchange:retry --limit=10
* * * * * cd /path/to/typo3 && vendor/bin/typo3 exchange:refresh --limit=5000
```

Alternatively create recurring console-command tasks for both commands in TYPO3
Scheduler and arrange for the Scheduler runner to execute every minute. Choose
one scheduling mechanism; extension installation does not create schedules.
Retain command output in the deployment's normal job monitoring and alert on
nonzero exits. Leases protect overlapping runs. Tune limits against measured
workload; the configured limits are not throughput guarantees.

Retry converts previously unresolved, unchanged source fields. Refresh updates
already-known destinations and invalidates dependent page caches without editing
source content. See [retry](delayed-resolution.md) and [refresh](destination-refresh.md)
for deadlines, backoff, leases and external-cache requirements.

## Diagnose and recover

Grant editors the `exchange_links` module. Its **All tracked links** view includes
healthy links; use **Problems only** or a specific status to investigate. The
report is limited to records and fields the current user can edit in the current
workspace. Links become tracked through the save pipeline; the report does not
scan old content automatically.

| State | Action |
| --- | --- |
| Pending | Restore connectivity and let the retry job run; the original readable link remains saved. |
| Stale | Restore connectivity and let refresh verify the destination; the last verified URL remains available. |
| Unavailable | Check the destination's publication, language and access. Established destinations are checked again automatically. |
| Denied | Repair credentials or site grants. An administrator can use **Recheck after repair**, or `exchange:refresh --resume=partner`. |
| Initial lookup needing attention | Repair the cause, then use **Queue retry** on the source field. |

Resuming denied destinations does not immediately make them clickable: each must
pass verification. Automatic recovery follows the current backoff plus scheduler
latency. Background success is quiet; persisted failures remain visible in the
report. Never change production credentials just to simulate an outage.

## Validation evidence and limits

Run `bash Tests/Build/setup.sh`, then `bash Tests/Build/run.sh` from the extension
repository. All fixtures stay in isolated Testing databases. Functional tests use
the TYPO3 testing framework; integration tests exercise real HTTPS between the
Testing vhosts in the existing DDEV projects.

Coverage includes editor/admin permissions, direct action authorization, CSRF,
workspace ownership, pending/unavailable/denied states, transient failures,
backoff and recovery, credential repair, save deadlines, concurrent edits,
lease recovery, page-cache invalidation, and English/German rendering. Failure
and timing cases use controlled transport responses; these are reproducible
behavior tests, not measurements of a production network outage.

The ten-peer/10,000-reference throughput target and an actual production scheduler
and external-cache deployment still require deployment-specific validation. The
local test result does not establish those performance or operational guarantees.
