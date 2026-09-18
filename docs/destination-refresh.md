# Scheduled destination refresh

Run `vendor/bin/typo3 exchange:refresh` every minute through cron or TYPO3
Scheduler's console-command task. Schedule `exchange:retry` separately for
initial lookups; refresh never writes source content or publishes workspace
records. Apply the schema with `extension:setup` and flush caches when deploying.
No recurring task is installed automatically.

```cron
* * * * * cd /path/to/project && vendor/bin/typo3 exchange:refresh
```

In DDEV, run `ddev typo3 exchange:refresh` from either instance directory for a
manual pass. `--limit=5000` is the default; allowed limits are 1–10000. Each run
starts work for at most 45 seconds, with batches of at most 50 references and
HTTP timeouts of at most three seconds. Peers receive batches in round-robin
order; a failed peer receives no more requests during that run. Database leases
expire after two minutes and prevent overlapping workers processing the same
destination. Generation checks prevent late responses overwriting newer saves.
Configure one outgoing connection per remote instance UUID.

Resolved and explicitly unavailable destinations are checked every two
minutes. With the every-minute schedule, healthy peers and enough capacity to
process all due records, changes reach TYPO3 output within five minutes.
Monitor command failures and the destination table's `next_refresh`,
`refresh_error`, `attempts`, and `checked_at` fields. `checked_at` is the latest
attempt, including failures. The [10-peer/10,000-destination acceptance workload](acceptance.md#five-minute-polling-freshness)
measured worst-phase completion below five minutes on both supported versions.
Repeat those measurements on deployment hardware; the local result is not a
production throughput guarantee.

## Failure and recovery

Timeouts, rate limits, server errors, malformed responses, redirects and
unsupported reference responses keep the last verified URL. Resolved records
become `stale`; already unavailable or denied records stay non-clickable.
Transient failures back off from one minute to one hour. Recovery after an
outage can therefore take up to the current backoff interval plus scheduler
latency. Every resolved response must match the requested instance, page UUID
and language exactly and return an approved canonical URL without suffixes.

HTTP 401/403 pauses the entire connection and marks all its destinations
`denied`, including records outside the current batch. Correct credentials or
site grants before resuming. Changes to outgoing peer configuration automatically
requeue paused destinations. When correcting only the remote grant, run:

```bash
vendor/bin/typo3 exchange:refresh --resume=peer-name
```

Resuming does not restore clickability until each individual reference has been
verified. Unavailable pages continue to be checked automatically for restoration.
Disabling an outgoing peer denies its destinations on the next refresh run.
After removing or changing peer configuration, clear frontend caches as part of
the configuration deployment, even if no command has run yet.

## Page caches and external caches

Rendering reads persistent local destination data and makes no HTTP requests.
Every managed reference, including non-clickable ones, adds destination and
peer cache tags with a maximum lifetime of 300 seconds. URL or state changes
invalidate dependent TYPO3 page caches; connection denial invalidates the peer
tag. A cache clear does not delete destination data. Query strings and fragments
remain in the source reference and are appended only during rendering.

TYPO3 cache invalidation cannot purge an independent CDN or reverse proxy.
Configure its purge integration or an expiry appropriate to the required total
freshness bound. A five-minute edge TTL added to a five-minute origin update
window can yield ten-minute-old output. Account for both layers, and disable
stale serving where confirmed removal must propagate within a strict deadline.

## Domain and endpoint changes

Update the outgoing `endpoint` explicitly to the new fixed HTTPS resolver
endpoint and approve the new public origin in `origins`. Retain the remote
instance UUID. Existing identities are refreshed through that endpoint; there
is no redirect following or endpoint discovery.

For newly pasted URLs using an old domain, retain both old and new origins in
the caller's outgoing `origins`. On the receiving resolver, add a top-level
mapping to its protected peer JSON configuration:

```json
{
  "publicAliases": {
    "https://old.example.org": "https://new.example.org"
  }
}
```

Merge this property into the existing configuration. Mappings accept origins
only (scheme, host and optional port), use exact origin matching and perform
one local replacement before TYPO3 route matching. They preserve path, query
and fragment. The old host is never contacted, aliases are not chained, and
the destination must still belong to an allowed site and be publicly accessible.
This handles a domain change; it cannot discover an old slug that no longer
routes to a page before the first managed reference was established.

## Verification

Run from the repository root after [test setup](testing.md):

```bash
bash Tests/Build/run.sh --filter RefreshTest
```

The check temporarily changes peer/site configuration and creates pages and
content, restoring/removing them afterward. Real HTTPS calls cover renames,
moves, inherited access restrictions, selected translations, restoration and
old-domain aliases. Controlled responses cover outages, malformed identities,
canonical URL validation, batch bounds, leases, newer-save races, connection
revocation, administrator recovery and explicit API endpoint changes. It also
checks cache invalidation, persistent rendering without HTTP and unchanged
source content.
