# Complete failed initial resolution in background jobs

A slow or unavailable peer must not prevent content saves, but requiring another
editor save would leave eligible links permanently unmanaged. Persist delayed
resolution jobs that convert an ordinary URL only after successful verification
and an atomic check that its saved field and editorial version are unchanged.
This deliberately permits audited background content changes within the same
editorial version, without publishing drafts; the operational and retry contract
is recorded in [the design](../cross-instance-links.md#delayed-resolution).
