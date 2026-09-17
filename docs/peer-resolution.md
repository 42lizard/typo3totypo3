# Peer resolution

The extension resolves public TYPO3 page URLs into stable references through a
read-only authenticated API. The [link-field workflow](link-fields.md) uses this
API to convert editor links on save.

## Try the paired development instances

The existing local instances have already been paired. From `dev/typo3-v13`:

```bash
ddev typo3 exchange:resolve v14 https://t3exchange-v14.ddev.site/
```

From `dev/typo3-v14`:

```bash
ddev typo3 exchange:resolve v13 https://t3exchange-v13.ddev.site/
```

Each result includes `status`, a reference containing the logical instance UUID,
page UUID and selected language ID, the current readable URL, and the site identifier.
Commands return a nonzero exit code for unresolved URLs and connection failures.

For a fresh checkout, run `bash dev/setup.sh`, then explicitly pair the local
instances with `python3 dev/pair.py`. Pairing generates separate random tokens per
direction, stores only token hashes for incoming grants, and writes secrets to
Git-ignored files with owner-only permissions. It refuses to overwrite existing
configuration. Restart both DDEV projects after changing their environment
configuration; changes to the JSON peer configuration take effect on the next request.

## Server configuration

Set `TYPO3_EXCHANGE_CONFIG` to an absolute path to a protected JSON file outside
the document root. Without an enabled file, resolution is disabled. The DDEV
projects use `/var/www/html/.peer-config.json` inside each container. Production,
staging and development must have separate credentials and endpoint mappings.
Do not distribute these files through content database exports or source control.

Configuration structure (placeholders must be replaced):

```json
{
  "enabled": true,
  "instance": "11111111-1111-4111-8111-111111111111",
  "incoming": {
    "22222222-2222-4222-8222-222222222222": {
      "enabled": true,
      "tokenHash": "SHA-256 digest of the incoming 64-character hex token",
      "sites": ["main"],
      "requestsPerMinute": 120
    }
  },
  "outgoing": {
    "partner": {
      "enabled": true,
      "instance": "22222222-2222-4222-8222-222222222222",
      "endpoint": "https://partner.example/typo3-exchange/v1/resolve",
      "origins": ["https://partner.example", "http://partner.example"],
      "token": "64-character cryptographically random hex token"
    }
  }
}
```

Use random UUIDv4 values for logical instances and 32 random bytes encoded as hex
for each token. Hash the token's hex string with SHA-256 for the receiving grant.
The receiving `incoming` key must match the caller's logical instance UUID.
Tokens authorize only the named sites' public page metadata. Disabling an
incoming grant yields 403; deleting the grant or changing its token hash yields
401. Rotate a connection's token at both ends. Preserve the instance UUID and
page identity table when moving the same logical instance to a new domain.

`origins` is an exact scheme/hostname/port allowlist, not a wildcard or URL prefix.
Readable HTTP URLs can resolve against equivalent HTTPS site bases; API traffic
always uses verified HTTPS. Configure absolute site/language bases in TYPO3.
Current URL validation supports ASCII/punycode hostnames and IPv4 addresses.
IPv6 literals, shortcut/mount pages, and specialized route
integrations are not covered by this milestone.

When an API/public domain changes, update endpoint/origin configuration and the
TYPO3 site base explicitly. Nothing follows redirects to discover a new peer.
Fixed administrator-configured endpoints may use private addresses for DDEV;
production network/DNS egress controls remain deployment responsibilities.

## API contract

`POST /typo3-exchange/v1/resolve` accepts JSON with either `urls` or `references`.
Send `Authorization: Bearer <token>` and `X-TYPO3-Peer: <caller-instance-uuid>`.
Requests require HTTPS, contain 1–50 items, and have a 64 KiB body limit.
Responses are JSON with `Cache-Control: no-store, private`.

Initial resolution:

```json
{"urls":["https://partner.example/info-page?campaign=email#contact"]}
```

Refresh after a page rename or move:

```json
{"references":[{"instance":"22222222-2222-4222-8222-222222222222","page":"33333333-3333-4333-8333-333333333333","language":0}]}
```

Successful requests return `{ "protocol": 1, "instance": "...", "results": [...] }`.
Results preserve input order:

| Status | Meaning |
| --- | --- |
| `resolved` | Verified public page; includes `reference`, `url`, and `site` |
| `unavailable` | No accessible destination in the selected site/language; no page metadata disclosed |
| `unsupported` | Malformed input or a URL outside the supported page-only form |

Identity refresh returns the canonical page URL. Query strings and fragments
belong to the link, not its destination identity; consumers must retain them
separately for future rendering. Initial URL resolution preserves ordinary
query strings and fragments. Page-selection, preview, authentication and known
plugin parameters, nested query parameters, and enhanced detail routes are rejected.

| HTTP status | Meaning |
| --- | --- |
| 400 | Invalid request or non-HTTPS API call |
| 401 / 403 | Invalid credentials / disabled or empty site grant |
| 405 / 415 | Unsupported method / content type |
| 413 | Request or resulting response exceeds size limit; reduce batch size |
| 429 | Authenticated peer exceeded its request allowance; `Retry-After: 60` |
| 503 | Resolver disabled, misconfigured, or temporarily unavailable |

A proxy 404, timeout, invalid response, or error status is never interpreted as
authoritative page deletion. The client validates protocol, instance identity,
result count, UUIDs, language IDs and returned URL origins. It disables redirects,
cookies and automatic decompression, verifies TLS, caps response transfer size,
and uses a one-second connect timeout within a three-second request timeout.
The link-field save workflow shares a three-second deadline across all its
records, peers and batches.

Rate limiting uses TYPO3's persistent rate-limit storage with a per-peer lock.
For a multi-web-node deployment, the lock/storage arrangement must be shared or
the gateway must enforce the aggregate limit. Apply unauthenticated traffic
limits at the web server/gateway as well. Inbound API handlers never fetch the
submitted URL: TYPO3 matches its site, language and page locally.

## Identity and publication

`tx_typo3totypo3_identity` associates a page's default-language UID with a random
UUID, assigned lazily after successful authorization and public visibility checks.
Concurrent initial lookups converge on the same identity. Copies get different
page UIDs and therefore identities; a soft-deleted original keeps its identity
for restoration. Keep this table with the corresponding content database when
copying an environment. Unrelated imports or reusing record IDs are outside the
supported identity lifecycle.

Resolution uses an anonymous live context, not the caller's frontend or backend
session. It checks visibility, access schedules, group restrictions, inherited
restrictions, workspace state and the exact selected translation. Missing or
hidden translations never fall back to another language. Unsupported page types
do not receive a managed reference. Public-page lookup uses TYPO3's internal
`SiteMatcher` in one class; both supported core versions are integration-tested.

## Verification

Run this from each instance directory after pairing:

```bash
ddev exec php /opt/typo3-to-typo3/dev/test-resolver.php
```

The check requires the named Development-context DDEV projects. It creates
temporary pages and grants, temporarily adds a test language, flushes local
caches, and restores/removes its changes afterward. Do not run concurrently with
manual configuration edits. It exercises real HTTP requests and cross-version
communication, plus controlled invalid responses through the real HTTP client.

Coverage includes stable identities after rename/move/restore, distinct identities
for new pages, publication/access/language restrictions, authentication, scoped
grants, rate limits, request limits, no-cache responses, and client validation.
Link-field conversion and persistent destination rendering have separate
[integration checks](link-fields.md#verification). [Background retries](delayed-resolution.md)
and [destination refresh with explicit old-domain aliases](destination-refresh.md)
are implemented. Deployment load targets remain a subsequent validation milestone.
