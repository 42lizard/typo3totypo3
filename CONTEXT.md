# Cross-instance linking

Links between TYPO3 instances retain their destination when a page is renamed,
moved, or served under a different domain.

## Language

**Instance**:
A logical participating TYPO3 installation, which may have separate production,
staging, and development environments. An instance may contain multiple websites
and languages.
_Avoid_: Instant

**Peer**:
An instance explicitly configured as a trusted communication partner of another
instance. Trust in one peer does not imply trust in that peer's partners.

**Destination page**:
The public page, in the selected website and language, that a cross-instance
link points to. Deleting and recreating a page produces a different destination.

**Cross-instance link**:
A link from content in one instance to a destination page in a peer instance.
_Avoid_: External link when specifically referring to a managed cross-instance link

**Stable reference**:
The identity of a destination that remains the same across page renames,
page-tree moves, and destination domain changes.

**Readable URL**:
The human-readable address an editor pastes to identify a destination page.
Its spelling may change while the destination's stable reference stays the same.

**Broken destination**:
A previously resolved destination page that no longer exists. Temporary inability
to contact its instance does not by itself establish that the destination is broken.

**Pending resolution**:
A readable URL awaiting a verified destination identity after an unsuccessful
initial lookup. It does not yet carry the maintenance guarantee of a stable
reference.

**Unavailable destination**:
A previously verified destination that the peer confirms cannot currently be
linked, including a missing page, unavailable translation, or loss of public access.

**Stale destination data**:
Previously verified destination information whose freshness could not be confirmed
because of a temporary communication failure. Staleness does not establish that
the destination is unavailable.
