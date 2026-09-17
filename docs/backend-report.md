# Backend link report

Open **Web → Cross-instance links** in TYPO3 13 or **Content status →
Cross-instance links** in TYPO3 14. Grant the `exchange_links` backend module to
editor groups. The module reports tracked fields in the user's current workspace;
editors see only records and fields they can edit. This includes table and field
permissions, page permissions, web mounts, language restrictions, edit locks,
auth-mode restrictions and workspace membership. Core form permission events
remain effective. Read-only records are deliberately omitted.

Each entry identifies the source record and field, destination, state and reason,
last check, and next planned check. Record links open the normal TYPO3 editor.
Pending URLs display only their origin; paths, query strings, fragments and
credentials are omitted. Managed references display the page UUID and selected
language. The report does not disclose previously public URLs for unavailable
or denied destinations. Mixed RTE fields show each affected managed destination.

The report reads existing outcomes and current source fields; it does not scan
unrelated content or contact peers. Results are paged in batches of 100 tracked
fields before record-level permission filtering. A batch can therefore be empty
while a later batch contains permitted problems. There is no unfiltered global
record count. Deleted, replaced and differently versioned source fields are
omitted. Existing content without a tracked outcome first appears after a save.

## Retry and repair

**Queue retry** requeues a permitted pending or attention outcome. It checks the
current record, field fingerprint, workspace, job generation and worker lease.
The existing `exchange:retry` job performs conversion with its normal concurrency
checks, history logging and draft protections. A newer edit disables the stale
action. A form token and POST are required, and authorization also runs on direct
action requests. A backend request never performs remote resolution.

Only administrators see connection-wide failures. Credential denial and repeated
failures also show a persistent toolbar indicator on backend reload; they do not
send email or repeatedly enqueue notifications. Initial lookup failures are
included, even if no managed destination exists yet. The indicator disappears
when the persisted problems are resolved. Repeated refresh failure means at
least three failed attempts.

After correcting the protected peer configuration or remote grants, administrators
can select **Recheck after repair** to resume paused destination checks. Destinations
stay denied until verified individually. The report does not edit credentials or
connection configuration. Initial failed conversions are retried through their
record's queue action after the connection is repaired.

Save-time lookup warnings are consolidated into one message. Opening a record
with outstanding problems shows one warning naming its affected fields. Successful
background conversion is quiet and uses TYPO3's normal audit history.

## Localization and tests

All backend UI labels, state explanations, save/edit warnings, toolbar text and
managed-link explanations use `Resources/Private/Language/locallang.xlf` and
`de.locallang.xlf`. English is the default; the backend user's language selects
German. Keys and substitution placeholders are checked in the functional suite.

See [testing](testing.md) for the extension's PHPUnit suites. The backend functional
tests use TYPO3's testing framework and isolated SQLite databases, real backend
users, core permission gates, form tokens and Fluid rendering on TYPO3 13 and 14.
