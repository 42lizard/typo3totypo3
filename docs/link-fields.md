# Managed link fields and RTE anchors

After [pairing the instances](peer-resolution.md), paste a readable peer page
URL into a TCA `type=link` field, for example a content element's **Header link**,
or use it as a link destination in the rich-text editor.
Saving verifies the destination over the authenticated HTTPS API and stores a
`t3://exchange` reference containing the remote instance UUID, page UUID and
selected language. TYPO3's standard typolink rendering uses the persistent local
destination data. Rendering never contacts a peer, including after cache clears.

Run `ddev typo3 cache:flush` and `ddev typo3 extension:setup` in both development
instance directories after installing this change.

## Save behavior

Conversion runs inside DataHandler after its permission and field checks. Only
changed, authorized link and effective rich-text fields participate. Existing
managed references stay intact. URLs outside enabled peers' configured origins stay ordinary links.
There is no bulk conversion of old content. RTE and ordinary link fields use
the same batches, deduplication and time budget.

Requests batch the eligible fields of each record, respecting the API's count
and body limits. Duplicate URLs are reused across records in the save. All
records, peers and batches share one three-second deadline; each request receives
only the remaining time. A slow host cannot give every field another three seconds.

If verification fails, the original value is kept and TYPO3 displays a warning.
A durable outcome records the source table, record, field, workspace and value
hash for later retries and reporting. It does not store credentials or a second
copy of the pasted URL. **Automatic retries are not implemented yet.** Saving an
otherwise unchanged field does not force another lookup.

Query strings, fragments, selected language and typolink target/class/title are
retained. A reference exceeding the field's configured maximum length is not
written. If a field restricts `allowedTypes`, include `exchange` alongside `url`;
record-type `columnsOverrides` are respected. The default unrestricted fields
need no TCA changes.

This milestone covers TCA link fields and `type=text` fields with effective
`enableRichtext`, including record-type overrides. RTE configuration `allowedTypes`
and `blindLinkOptions` are respected; restricted configurations must permit both
`url` and `exchange`. Only verified anchor href attributes change. HTML5 token
positions preserve surrounding content, attributes and inline markup; malformed
HTML is left untouched by conversion. TYPO3's own RTE transformations still apply.
FlexForms, direct SQL changes and custom rendering are outside this milestone.
Conversion uses the record selected by DataHandler and does not publish workspaces
or drafts.

## Destination states and caches

The destination table is persistent data, separate from TYPO3's disposable
frontend caches. Preserve it alongside content containing managed references.
Query/fragment suffixes belong to each link, not to this shared destination.

`DestinationStore::record()` is the update entry point for future refresh jobs:

- `resolved`: render the current verified URL.
- `stale`: retain the last known URL after a transient failure.
- `unavailable` or `denied`: render the supplied link text without an anchor,
  keeping the reference in the source record for recovery.

Every rendered reference adds a destination-specific cache tag, including those
currently unavailable. Changing its URL or state flushes dependent entries in
TYPO3's `pages` cache group. Unrelated cached pages remain intact. Disabling peer
configuration prevents new rendering of its links; clear frontend caches when
changing peer configuration, just as when changing site configuration.

**Scheduled refresh is not implemented yet.** Remote page moves or visibility
changes do not update local destination states automatically until that milestone
is added. A managed reference with missing local destination data renders plain
text. Database clones need an enabled environment-specific peer configuration
and an approved destination origin to render links.

## Verification

From each paired development instance directory:

```bash
ddev exec php /opt/typo3-to-typo3/dev/test-link-fields.php
ddev exec php /opt/typo3-to-typo3/dev/test-rte.php
ddev exec php /opt/typo3-to-typo3/dev/test-resolver.php
```

The link-field check uses real DataHandler saves and standard TYPO3 typolink
rendering on both supported major versions. It creates temporary content,
removes it afterward and clears local caches. Controlled transport responses
exercise batching, failures and the shared time budget; a real HTTPS lookup
verifies cross-version conversion. Do not run these checks alongside manual
editing in the same development instances.

The RTE checks also cover copied and localized content, draft-only workspace
saves, mixed anchors, escaped HTML, and preservation of unrelated markup.
