# Report usage and notify consumers

A serving instance can record which configured consumer environments report using
its pages. When a destination changes, it sends a notice. The consumer queues an
asynchronous resolver refresh; the notice never supplies an authoritative URL or
availability status.

This is opt-in. Existing resolver connections and periodic destination refresh
continue to work without enabling usage reporting or notifications.

## Configure both directions

For a consumer **A** linking to a serving instance **B**:

1. Update the extension database schema on both installations. Keep the existing
   resolver connection from A to B and its incoming grant on B.
2. In **Instance connections → Usage and notifications**, initialize each
   exchange environment. This creates a deployment identity outside the database,
   in `config/system/exchange-environment.php`. The PHP process needs write access
   to that configuration directory for initialization. Alternatively, provide a
   distinct UUID through `TYPO3_EXCHANGE_ENVIRONMENT` before initialization.
3. Set B's environment UUID on A's existing outgoing resolver connection. Resolver
   responses must match that environment when notifications are enabled.
4. On A, add an outgoing **Usage reporting** capability pointing at
   `https://B.example/typo3-exchange/v2`. Enter B's instance and environment UUIDs.
   Save, copy the one-time token and pairing generation, and explicitly enable it.
5. On B, add the matching incoming **Usage reporting** grant. Enter A's instance
   and environment UUIDs, the same generation and token, and the permitted site
   identifiers. Enable the grant. An empty site list grants no page registration.
6. On B, create an outgoing **Change notifications** capability pointing at A's
   exchange endpoint. Copy its newly generated token and generation into a matching
   incoming notification grant on A. Use the respective remote identities in each
   form. Notification permission does not grant usage or resolver permission.
7. Schedule the commands below on both installations. Capability discovery runs
   through the authenticated endpoint before new delivery starts. The connection
   screen shows supported, unsupported, unconfirmed, disabled or denied state.

Use descriptive local connection names, including the environment, such as
`customer-portal-production`. This name appears in the serving instance's usage
view. Stored credentials are encrypted with the existing TYPO3 connection store;
incoming credentials are stored as hashes. Saved credentials are never displayed.

## Schedule background work

Run once per minute, allowing at most one invocation of each command per instance:

```sh
vendor/bin/typo3 exchange:sync
vendor/bin/typo3 exchange:refresh
vendor/bin/typo3 exchange:retry
```

`exchange:sync` indexes source records, publishes usage, detects destination
changes and delivers notices. It starts work for up to 45 seconds per invocation.
Source reconciliation resumes across invocations and repeats daily. The existing
refresh command remains the fallback when a notification cannot be delivered.
Visitor rendering performs no peer requests.

After changing site, domain, language or routing configuration, request a recheck:

```sh
vendor/bin/typo3 exchange:sync --recheck-destinations
```

Other recovery options are `--reconcile` for a fresh source scan and
`--recheck-capabilities` after a peer upgrade. The connection screen also queues
these operations and delivery retries. A capability reported as unsupported is
not retried indefinitely; explicitly recheck it after upgrading the peer.

## What counts as usage

Supported link fields and rich-text anchors containing stable managed references
count, including hidden content and drafts. Multiple local occurrences become one
presence declaration per destination and language. Deleted records and obsolete
versions do not count. Ordinary URLs waiting for their first successful resolution
do not count as stable references.

Source record identities, titles, URLs, occurrence counts and workspace information
are not exported. Removing the final local occurrence queues an explicit removal.
An unavailable destination does not, by itself, remove its usage.

The receiver cannot accept a first registration of a page that is already
unavailable under its site grant. That report remains incomplete and retries.
Previously authorized registrations can remain tracked while their page is hidden
or deleted. Revoking the site grant prevents destination-specific notifications.

Daily reconciliation uses staged snapshots. Only a complete, verified snapshot can
infer that a previously reported reference is absent. Per-pair revisions prevent
late requests from resurrecting removed usage or overwriting newer reports.

## Editorial view

Select a page in **Cross-instance links**, then open **Used by other instances**.
Optionally include permitted descendants. Page, module and language permissions
are applied before output. The view lists the local consumer connection name,
language, last report in UTC, and current/stale status. Reports older than 48 hours
are stale, not evidence of removal.

The view describes live destinations. Draft edits affect consumers after
publication. Editing a page with reported usage displays a non-blocking warning,
including permitted descendants. The page-tree hide and delete actions also warn
before proceeding. “No reported usage for this selection” does not
prove that no external links exist.

Administrators can explicitly forget stale tracking entries after confirmation.
This action is recorded locally and does not alter source content. A later
newer authorized report can restore the entry.

## Delivery and recovery

Messages use fixed HTTPS endpoints with certificate verification and no redirects.
Requests and responses are limited to 64 KiB and batches to 50 destinations. Each
incoming capability/pair is limited to 120 requests per minute.

A successful notification acknowledgment means durable acceptance, not completed
refresh. The consumer's refresh worker verifies the new URL or availability and
invalidates the corresponding TYPO3 page caches. Replayed or older notifications
have no effect; a newer notice survives an in-flight refresh. Unknown destinations
are not created merely because a notice names them.

Transport failures back off for 1, 2, 4, 8, 16, 32 and then 60 minutes, continuing
hourly. Permission denial pauses delivery. Correct the grant or credential before
requesting a retry. Failures lasting seven days require administrator attention.
Incomplete page registrations retry separately so other reports can progress.

Disabling or removing a connection stops communication without telling the former
peer that its links were removed. Local tracking remains. After configuration is
changed and the capability verified again, the sender prepares fresh reconciliation
instead of replaying the previous queued backlog.

## Restore, clone and rollback

Keep deployment activation outside database exports. A database copy must not
activate exchange under an unrelated deployment identity. A complete server clone
that includes the same deployment identity cannot automatically detect that it is
a clone: isolate its outbound traffic and disable scheduled exchange jobs before
starting it.

For restoration of the same environment, retain its deployment identity and agree
new pairing-generation UUIDs with the peers before resuming. Old generations must
not be reused after revision state has been rolled back. For a separate clone,
use **Initialize as a separate environment** in the connection screen, then create
new explicit grants. This removes inherited capability configuration and pending
deliveries and disables all connections. Review resolver connections and explicitly
re-enable communication only after the clone is correctly paired. When activation comes from an environment variable, assign the clone a
distinct UUID there first; do not send removal
reports on behalf of the original environment.

The supported pre-feature rollback baseline is commit
`65bed0f361a1e7ac6c468c858783204b5ce5e7d8`. Stop the new sync job and
disable the new capabilities before rolling back. On upgraded consumers, disable
incoming notifications from the rolled-back server and clear that server’s
optional environment UUID in the outgoing resolver connection. The older resolver
does not return an environment UUID; an active binding intentionally rejects it.
Restore the binding before enabling notifications again. Keep the additive tables and
configuration for a later upgrade; do not delete revision watermarks while their
pairing generations remain usable. Retain the existing resolver and periodic
refresh jobs. The paired PHPUnit test exports that exact commit and boots it in
an isolated CLI process on TYPO3 v13 and v14, using the upgraded test database.
It checks configuration preservation, resolver refresh and readable rendering;
the shared Development installation remains on the current code.

## Acceptance evidence

Results recorded on 18 September 2026 use an Apple M1 Pro (10 host cores,
32 GiB RAM), Docker Desktop allocated five CPUs and approximately 12 GiB RAM,
DDEV 1.25.4 and PHP 8.4.24. Functional/load databases use SQLite 3.46.1;
the real HTTPS paired tests use MariaDB 10.11. This is a reproducible development
environment measurement, not a production sizing guarantee.

The full source-reconciliation test on TYPO3 13.4.35 passed with 100,000 content
records, one million link occurrences and 10,000 distinct references. Half the
content records are hidden workspace drafts; every third record is a translation.
References repeat across records. The serving fixture adds 10,001 pages to the
same database, which the source scanner also visits. The database reached about
709 MiB. With one sync worker and 100 ms simulated request latency, all scan,
transmission and verified snapshot-completion work took **53 invocations,
565.46 seconds processing, 3,125.34 seconds (52m 5s) on a once-per-minute schedule**.
Notification load and functional tests were running concurrently on the same VM.

The complete v13/v14 suite and exact-baseline rollback checks pass. Full ten-peer
normal/burst/outage and missed-event timings are still being verified; they are
not yet claimed proven. The remaining targets are defined in
[the capacity decision](https://github.com/42lizard/typo3totypo3/issues/15).
Real three-instance trust validation is tracked separately in
[issue #7](https://github.com/42lizard/typo3totypo3/issues/7). See
[testing](testing.md) for the extension's isolated PHPUnit suites.
