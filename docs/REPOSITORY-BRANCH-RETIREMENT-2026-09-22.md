# Repository Branch Retirement — 2026-09-22

Issue: #45 — Post-#32 repository truth sync and branch retirement

Protected `master` baseline at audit start: `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`. PR #46 is the documentation merge surface; its merge commit becomes the later protected-master head.

This record classifies every branch visible in the repository after Issue #32 / PR #43. Classification is based on live GitHub PR state and compare evidence. No branch is treated as disposable merely because it is old.

## Classification rules

- **CANONICAL** — protected current integration branch.
- **MERGED-EQUIVALENT / SAFE TO RETIRE** — branch head was merged through an identified PR and its surviving purpose is represented in protected history.
- **ACTIVE SURVIVING WORK** — contains unique unmerged work that may still be intentionally pursued.
- **HISTORICAL EVIDENCE — RETAIN** — contains unique unmerged provenance/evidence and should not be deleted without a separate content-preservation decision.
- **SUPERSEDED HISTORICAL EVIDENCE — RETAIN** — obsolete as current work but still has unique commits; do not merge.

## Branch inventory

| Branch | Head | Classification | Evidence / action |
|---|---|---|---|
| `master` | `88c9ec09f3cfabf73b83780c0c37d397e9acdad6` | **CANONICAL** | Protected master; post-#32 CI `35766468276` SUCCESS. |
| `docs/rc12-training-realignment` | `3d171f841949cd5e4058cb4f4b9d6843119f6a0d` | **MERGED-EQUIVALENT / SAFE TO RETIRE** | PR #28 merged 2026-09-20. |
| `fix/cart-checkout-address-ux` | `000c5b3350de0508ae1cd5009ee53ac114a1ffa9` | **MERGED-EQUIVALENT / SAFE TO RETIRE** | PR #41 merged. |
| `fix/checkout-multi-destination-preservation` | `41c80300e1ec7874d6d817d29df2196b935ceacc` | **MERGED-EQUIVALENT / SAFE TO RETIRE** | PR #30 merged. |
| `fix/geography-pack-liveness` | `be2187f75d5dabdddc79060ebf37766387d5b041` | **MERGED-EQUIVALENT / SAFE TO RETIRE** | PR #34 merged. |
| `fix/geonames-country-identity` | `5b1749c7226a4a6666d419da1d20d53a998b3849` | **MERGED-EQUIVALENT / SAFE TO RETIRE** | PR #36 merged. |
| `fix/needs-attention-count-contract` | `658316f775099b0dfc43205bb07c905681a4af10` | **MERGED-EQUIVALENT / SAFE TO RETIRE** | PR #43 merged; Issue #32 closed. |
| `fix/pdp-location-precision` | `d1ef43027035d958f2a53272ae59691e790563a4` | **MERGED-EQUIVALENT / SAFE TO RETIRE** | PR #40 merged. |
| `fix/shipment-workspace-order-read` | `1da738eeac3d160d78e54dfa84556d4d45c4af9a` | **MERGED-EQUIVALENT / SAFE TO RETIRE** | PR #42 merged. |
| `ws3/php-85-runtime-realignment` | `bab46d0a1317cf6fbf5d632abcf96fe06cdcd538` | **MERGED-EQUIVALENT / SAFE TO RETIRE** | PR #37 merged. |
| `docs/staff-training-rc2` | `27ebf93af811373d7a0ded34be16c1d185ea7ddc` | **HISTORICAL EVIDENCE — RETAIN** | 4 unique unmerged commits / large training-doc + Playwright-video lineage. No PR. Do not delete until explicitly reconciled against current `docs/training/`. |
| `feat/post-rc9-customer-ux` | `d534d6a24390f39206f697308f0c4bc42919be46` | **HISTORICAL EVIDENCE — RETAIN** | 12 unique unmerged commits. Later release lineage incorporated/reimplemented material behavior, but the branch itself is not merge-equivalent. Preserve provenance; do not merge wholesale. |
| `feat/post-rc9-wpml` | `3b5b60d0d92b2edc00496d536774f8ac907cd6d2` | **ACTIVE SURVIVING WORK** | 2 unique unmerged commits with WPML runtime/tests/docs. Optional stream remains outside core and uncertified. Do not merge without a new milestone. |
| `ws3/rc10-closeout-truth` | `3a85d52d898c223519deeb89855878a1193ba859` | **HISTORICAL EVIDENCE — RETAIN** | PR #17 closed unmerged; 3 unique documentation commits. Superseded by later RC.10/RC.11/RC.12 truth but preserved as chronology. |
| `ws3/sync-current-status-2026-09-16` | `dbf90ca4d892ce2c7dc12f0c92343321888b3135` | **SUPERSEDED HISTORICAL EVIDENCE — RETAIN** | PR #15 closed as superseded on 2026-09-22; 1 unique docs commit. Must not be merged. |

## Safe-retirement set

The following branch refs are proven safe to delete once branch-deletion access is available:

- `docs/rc12-training-realignment`
- `fix/cart-checkout-address-ux`
- `fix/checkout-multi-destination-preservation`
- `fix/geography-pack-liveness`
- `fix/geonames-country-identity`
- `fix/needs-attention-count-contract`
- `fix/pdp-location-precision`
- `fix/shipment-workspace-order-read`
- `ws3/php-85-runtime-realignment`

Their commits remain preserved through merged PR/commit history.

## Retained branches

Do not delete without a separate preservation/reconciliation decision:

- `docs/staff-training-rc2`
- `feat/post-rc9-customer-ux`
- `feat/post-rc9-wpml`
- `ws3/rc10-closeout-truth`
- `ws3/sync-current-status-2026-09-16`

The last two are not current work. Retention is only for unique historical evidence.

## Tooling limitation recorded

The connected GitHub action surface used for this audit can create/update refs but does **not** expose branch-ref deletion. Therefore Issue #45 may merge the classification/documentation without pretending the safe-retirement refs were deleted. Deletion of the safe-retirement set remains a repository-hygiene action for a GitHub surface that exposes branch deletion. Do not substitute force-moving refs to `master`; that would destroy provenance without actually deleting the branches.

## Release/deployment invariants

Branch cleanup must not:
- move or rebuild `v1.0.0-rc.12`;
- create RC.13;
- alter schema 6;
- modify training operational data;
- deploy Pilot, FLAIROC, production, or POS;
- change geography, coverage, rates, checkout, shipment, COD, or bulk semantics.
