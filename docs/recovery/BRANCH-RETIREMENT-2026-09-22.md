# Branch Retirement Audit — 2026-09-22

## Purpose

This is the systematic branch-retirement pass after Issue #32 / PR #43. The goal is to distinguish merged-equivalent work from unique evidence or still-valid optional work before deleting any branch.

Canonical comparison point: protected `master` `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`.

## Classification rules

- **MERGED-EQUIVALENT — SAFE TO RETIRE:** the branch's intended change landed through its merged PR and current master contains the surviving result.
- **SUPERSEDED — SAFE TO RETIRE:** the branch was never merged, but its purpose is obsolete and later canonical documentation/runtime truth supersedes it; the closed PR preserves historical review context.
- **HISTORICAL EVIDENCE — RETAIN:** unique recovered/unmerged evidence exists and should remain addressable until a separate content-salvage decision.
- **ACTIVE SURVIVING WORK — RETAIN:** unmerged optional/future work still has a legitimate product role.
- **CANONICAL — RETAIN:** protected master.

## Branch matrix

| Branch | Head | Evidence | Classification | Disposition |
| --- | --- | --- | --- | --- |
| `master` | `88c9ec09...` | protected canonical branch | CANONICAL | RETAIN |
| `docs/rc12-training-realignment` | `3d171f84...` | PR #28 merged | MERGED-EQUIVALENT | SAFE TO RETIRE |
| `fix/cart-checkout-address-ux` | `000c5b33...` | PR #41 merged | MERGED-EQUIVALENT | SAFE TO RETIRE |
| `fix/checkout-multi-destination-preservation` | `41c80300...` | PR #30 merged | MERGED-EQUIVALENT | SAFE TO RETIRE |
| `fix/geography-pack-liveness` | `be2187f7...` | PR #34 merged | MERGED-EQUIVALENT | SAFE TO RETIRE |
| `fix/geonames-country-identity` | `5b1749c7...` | PR #36 merged | MERGED-EQUIVALENT | SAFE TO RETIRE |
| `fix/needs-attention-count-contract` | `658316f7...` | PR #43 merged | MERGED-EQUIVALENT | SAFE TO RETIRE |
| `fix/pdp-location-precision` | `d1ef4302...` | PR #40 merged | MERGED-EQUIVALENT | SAFE TO RETIRE |
| `fix/shipment-workspace-order-read` | `1da738ee...` | PR #42 merged | MERGED-EQUIVALENT | SAFE TO RETIRE |
| `ws3/php-85-runtime-realignment` | `bab46d0a...` | PR #37 merged | MERGED-EQUIVALENT | SAFE TO RETIRE |
| `ws3/sync-current-status-2026-09-16` | `dbf90ca4...` | PR #15 closed unmerged; RC.9-era status is obsolete | SUPERSEDED | SAFE TO RETIRE |
| `ws3/rc10-closeout-truth` | `3a85d52d...` | PR #17 closed unmerged; only old CURRENT-WORK/RC10/STATUS docs | SUPERSEDED | SAFE TO RETIRE |
| `docs/staff-training-rc2` | `27ebf93a...` | no PR; unique divergent training-doc history | HISTORICAL EVIDENCE | RETAIN |
| `feat/post-rc9-customer-ux` | `d534d6a2...` | recovered unique post-RC.9 customer-UX provenance | HISTORICAL EVIDENCE | RETAIN |
| `feat/post-rc9-wpml` | `3b5b60d0...` | unique WPML overlay, intentionally outside current core | ACTIVE SURVIVING WORK | RETAIN |

## PR #15 closeout

PR #15 `docs: synchronize current delivery-engine status` was closed without merge on 2026-09-22. Its base was RC.9-era `b7c9f2b...`; it described PR #12 / `1.0.0-dev.qual.1` as the active qualification surface and would regress current project truth.

Classification: **SUPERSEDED HISTORICAL DOCUMENTATION**.

## Deletion gate

Only branches marked **SAFE TO RETIRE** may be deleted. Deletion must not occur until this matrix is committed and the replacement truth-sync PR is merged or otherwise preserved on canonical history.

The three retained non-master branches must not be deleted by bulk cleanup:
- `docs/staff-training-rc2`
- `feat/post-rc9-customer-ux`
- `feat/post-rc9-wpml`

Branch deletion is repository housekeeping only; it must never rewrite protected `master`, tags, release assets, or historical PR/issue records.
