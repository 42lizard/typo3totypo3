# Cross-instance page links

Status: approved by the user on 2026-09-17. All five interview rounds are
incorporated, including asynchronous resolution after slow or failed lookups.
Development instances and the [authenticated peer resolver](peer-resolution.md)
are implemented, together with link-field/RTE conversion, local rendering and
[delayed initial resolution](delayed-resolution.md) and
[scheduled destination refresh](destination-refresh.md). The localized
[backend report](backend-report.md) is implemented. Final deployment validation
remains a subsequent milestone.

## Purpose and first release

An editor pastes a readable URL from one TYPO3 instance into a link field or
rich-text editor in another. During save, the receiving instance asks the
destination instance to resolve it to a stable reference. Frontend rendering
produces a readable destination URL from that reference.

- Support TYPO3 13 and 14, with separate DDEV installations, mixed-version
  integration testing, and a three-instance scenario to verify peer isolation.
- Require this extension on every participating instance.
- Support public pages across multiple websites and languages.
- Links survive page renames, page-tree moves, and destination domain changes.
- Deleting and recreating a page creates a different destination.
- Records, files, and restricted pages are outside the first release.
- Ship page links first, sharing peer configuration and authentication without
  building a general synchronization framework for unspecified future features.

## Destination identity

- Destinations have persistent UUIDs scoped to a logical instance.
- Renames and moves retain identity; copies of pages receive new identities.
- Restoring the original page retains its identity. Environment copies retain
  identities and use explicit environment mappings.
- Moving a page between websites in the same instance preserves links only if
  the peer still has permission and the selected language remains available.
- Moving a destination to another instance is outside the first release.

## Trust and administration

Backend-managed connections amend the original file-managed design; see
[ADR 0003](adr/0003-manage-connections-in-the-backend.md) and the
[pairing guide](connections.md).

- Connections are explicitly configured trusted peers; trust is not transitive.
- Only administrators configure connections and grant access to selected
  websites' public pages.
- Credentials are revocable and separate per peer and direction, exchanged
  manually for the first release.
- Use high-entropy scoped bearer API tokens. The API permits page resolution
  only and grants no content-writing access.
- Communication uses verified HTTPS, including trusted HTTPS in DDEV.
- Connection settings are stored encrypted in the database, using TYPO3's server-side
  encryption key and application context. Credentials never appear in rendered links.
- Pasted URLs can trigger communication only with configured peers.
- Database copies must not inherit active production connections. Staging and
  development require explicit environment mappings and credentials before
  outbound communication is enabled.
- Administrators update public domains and fixed API addresses while retaining
  logical instance identity. Old public domains may remain configured aliases.
- Do not discover API endpoints through pasted URLs or HTTP redirects.
- Explicit credential revocation or access denial disables clickability of
  affected managed links and alerts administrators; retain stored references.
  This differs from transient network or server failures.

### Security implementation requirements

- Send credentials in the Authorization header, never URLs. Keep plaintext
  credentials out of logs, repository files and database exports. The administrator
  sees a newly generated outgoing token once for manual pairing; saved tokens are
  never displayed. Full server/configuration clones still require explicit
  environment isolation before startup.
- Match configured URL origins strictly, including scheme, hostname and port.
  Send the candidate URL as data to the peer's fixed endpoint; neither resolver
  fetches arbitrary submitted URLs. Disable redirects for authenticated requests.
- Enforce approved-site and public-page authorization for every requested
  destination, including refreshes by identity. Return only resolver metadata.
- Validate response schemas, identities and returned URL origins against the
  configured peer. A generic proxy error or HTML error page is not authoritative
  evidence of destination deletion.
- Bound request/response sizes, batch sizes and execution times; apply rate
  limiting. Egress/DNS controls must fit the deployment; explicitly configured
  DDEV/private endpoints are permitted without disabling TLS verification.
- These credentials authorize only this public-page resolver. Future private
  content or write APIs require a new security review.

## Editor and visitor behavior

- Failed initial resolution preserves the original URL, saves the content, and
  produces a visible warning. It never creates an unverified stable reference.
- Existing stable references survive temporary peer outages.
- Preserve the selected destination language; do not silently switch to another
  language when a translation becomes unavailable.
- Preserve fragments and ordinary query parameters, but only the page itself
  receives the maintenance guarantee. Preview, authentication, and unsupported
  detail-page URLs remain ordinary URLs with a warning.
- After confirmed deletion or loss of public access, preserve link text but
  remove clickability and report the unavailable destination to editors.
- Retain the stored reference so restoring the original destination can restore
  the link.
- Installing the extension does not bulk-convert existing content. Eligible
  links are converted when their records are saved.
- Provide save-time warnings and a backend report with record, field,
  destination, and failure reason. Editors see only records they may access;
  administrators also see connection and refresh failures.
- Batch and deduplicate save-time lookups with a three-second total network
  budget per save. Unresolved links remain ordinary URLs with a warning.
- Successful delayed conversions update the report and leave an audit entry
  without interrupting editors. Show outstanding problems when the affected
  record is edited; administrator notices are for persistent connection failures.

## Delayed resolution

- Peers that are slow or unavailable produce persistent delayed-resolution jobs.
  This replaces the initial next-save/manual-only retry proposal.
- A successful job may replace the original URL with a verified stable reference
  only if the saved field and its editorial version remain unchanged. Otherwise,
  discard the stale result and reconsider the current content.
- Retry temporary failures with increasing delays, capped at one hour between
  attempts. After seven days, mark the job as needing attention; allow manual
  retry. Invalid credentials and denied access require administrator attention
  instead of continual retries.
- Before initial resolution succeeds, no stable identity guarantee exists. Accept
  the destination identified by the URL when resolution succeeds and expose
  pending status to editors.
- The five-minute freshness target for already-resolved destinations is separate
  from the delayed-resolution retry schedule.
- Use a TYPO3 command run every minute through cron or Scheduler. Process
  persistent jobs in bounded batches; no separate queue service is required.
- Apply version/field checks atomically with the content update so an editor
  cannot race the final write. Process through TYPO3's supported persistence and
  permission mechanisms, with cache invalidation and audit history.
- Jobs must tolerate duplicate execution, worker interruption and overlapping
  scheduled runs without duplicate transformations or recursive job creation.

## Editing and publication boundaries

- Cover configured TCA link fields and RTE fields saved through DataHandler and
  rendered through standard TYPO3 link rendering.
- FlexForms and writes that bypass DataHandler are outside the first release.
- Delayed conversion of unpublished content stays within its original draft or
  workspace version and must never publish it.
- Publishing, replacing, or deleting an editorial version invalidates its pending
  jobs. Check the resulting current version separately.
- Destination resolution exposes only publicly available pages.

## Freshness

- Destination changes should reach rendered output within five minutes while
  peers and recurring background tasks are healthy.
- Production installations run recurring tasks with batched refreshes and
  invalidation of affected rendered-page caches.
- Visitor requests do not require live API calls to peers.
- During outages, use the last known URL and expose stale status to
  administrators. An outage alone is not evidence of deletion.
- Store verified destination data persistently so a cache clear does not remove
  the last known URL. Rendering reads local data, never a live peer API.
- External caches such as CDNs or reverse proxies must use compatible expiry or
  invalidation. The extension's five-minute guarantee covers TYPO3-managed output
  under the agreed load with healthy peers and background execution.

## Resolution states and rendering

| State | Stored content and rendering | Next action |
| --- | --- | --- |
| Pending initial resolution | Original ordinary URL remains clickable; no identity guarantee yet | Background lookup with retry policy |
| Verified destination | Stable reference renders the locally stored readable URL | Scheduled identity-based refresh |
| Temporary peer failure after verification | Stable reference renders last known URL, marked stale in the report | Retry refresh; do not infer deletion |
| Authoritative destination unavailable | Retain reference; render text without link | Continue availability checks so restoration can recover |
| Explicit credential/access denial | Retain affected references; render text without links | Administrator repairs credentials or grants, then retry |
| Initial resolution needs attention | Original URL remains; report explains unresolved state | Manual retry after correction or retry-window expiry |

Absent translations and loss of public access count as destination unavailability.
Only validated resolver outcomes establish this state. Failed initial resolution
does not grant control over an ordinary external link: it remains ordinary until
verified conversion. Users may still navigate the original URL; the destination
instance remains responsible for enforcing access to its content.

## Acceptance scenarios

These are validation requirements for implementation, not completed test results.

| Scenario | Required outcome |
| --- | --- |
| Save a peer URL in a supported link field and RTE | Store a verified stable reference and render the readable URL; preserve text and supported link attributes |
| Rename or move the destination | Reference remains unchanged; rendered URLs update within five minutes under healthy conditions |
| Change a public domain/API address | Explicit configuration update retains identity; refresh produces the new URL; configured old aliases can still be recognized |
| Copy, delete, recreate, and restore a page | Copy/recreation is a new destination; deletion breaks the original link; restoration of the original identity recovers it |
| Select a language, then remove its translation | Retain that language selection and remove clickability rather than switching languages |
| Move a page into an unauthorized website | No metadata disclosure; affected verified links become unavailable |
| Save while a peer is slow or offline | Total save-time network wait stays within three seconds; content saves with original URLs, warnings and pending jobs |
| Peer recovers | Pending jobs convert unchanged content; apply retry schedule rather than promising immediate conversion |
| Editor modifies a field during a lookup | Stale job cannot overwrite the edit, including an edit racing the final write |
| Publish, replace, or delete a workspace version | Old jobs are invalidated; no automatic publication or mutation of another version |
| Duplicate job, worker crash, or overlapping runs | Safe retry without duplicate conversion or lost editor changes |
| Timeout, rate limit, malformed response or generic proxy 404 | Do not mark a verified page deleted merely because communication failed |
| Peer credential revoked | Affected managed links lose clickability after denial is detected; administrator can repair and retry |
| Clear TYPO3 caches during a peer outage | Verified links still render from persistent last-known data without network requests |
| Untrusted URL or redirect attempt | No request to an unconfigured endpoint; credentials are never forwarded through redirects |
| Clone production database into DDEV | No production communication without explicitly configured environment mappings and credentials |
| Three instances with different grants | A grant for one peer/site does not authorize another peer/site, directly or transitively |
| TYPO3 13/14 combinations | Saving, rendering, lifecycle operations and refresh work across supported versions |
| 10 peers and 10,000 distinct linked destinations per instance | Batched scheduled refresh meets healthy-state freshness target |
| One save with 100 distinct links | Respect aggregate network budget and finish remaining eligible conversions asynchronously |
| Unsupported URL or editing surface | Preserve content; report unsupported URLs in supported fields; do not imply coverage of custom persistence/rendering |

## Deferred scope

General content/file synchronization, restricted destination pages, record detail
links, FlexForms, direct database imports, custom rendering integrations, bulk
migration, cross-instance page moves, stable section-anchor identities, and an
automatic pairing handshake are deferred. Exact URI parameter names and database
schema are implementation details subject to the above behavior.

## Verified TYPO3 integration facts

- TYPO3 supports custom stored link types, with parsing and serialization
  separate from frontend link building:
  <https://docs.typo3.org/m/typo3/reference-coreapi/13.4/en-us/ApiOverview/LinkHandling/Tutorials/CustomLinkBrowser.html>.
- Frontend link builder interfaces differ between TYPO3 13 and 14:
  <https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/14.0/Breaking-106405-TypolinkBuilderSignatureChanges.html>.
- Updating resolved URL data alone does not refresh cached rendered pages;
  cache invalidation or expiry must cover rendered output too:
  <https://docs.typo3.org/m/typo3/reference-coreapi/13.4/en-us/ApiOverview/RequestLifeCycle/RequestAttributes/FrontendCacheCollector.html>.

- DataHandler integration must respect effective field configuration, including
  record-type overrides and allowed link types:
  <https://docs.typo3.org/m/typo3/reference-tca/13.4/en-us/Types/Index.html> and
  <https://docs.typo3.org/m/typo3/reference-tca/13.4/en-us/ColumnsConfig/Type/Link/Index.html>.

## Security references

The security requirements apply these general recommendations to the agreed
read-only resolver design; they do not imply endorsement of this extension.

- Secret separation, scope, rotation and revocation:
  <https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html>.
- Bearer transport and TLS verification, without requiring an OAuth server:
  <https://www.rfc-editor.org/rfc/rfc6750>.
- Fixed destinations, strict URL validation and redirect restrictions:
  <https://cheatsheetseries.owasp.org/cheatsheets/SSRF_Prevention_Cheat_Sheet.html>.
- Endpoint authorization, validation and resource limits:
  <https://cheatsheetseries.owasp.org/cheatsheets/REST_Security_Cheat_Sheet.html>.
