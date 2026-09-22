# Current Work — Post-#32 repository truth synchronization

Status: ISSUE #32 CLOSED / PR #43 MERGED / MASTER CI GREEN — DOCS & BRANCH-CLEANUP ISSUE #44 ACTIVE — RC.12 IMMUTABLE — NOT RC.13

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Current protected `master`: `88c9ec09f3cfabf73b83780c0c37d397e9acdad6` (PR #43 MERGED; Issue #32 CLOSED / COMPLETED).
- Post-merge master CI run `35766468276`: SUCCESS.
- Immutable RC.12 release source: `78594ad8962868683726373f58f4a8b1b48e4d0e`.
- Tag `v1.0.0-rc.12` remains the published prerelease identity and must not be moved or rebuilt.
- RC.13 does not exist and is not authorized by this work.
- Product-truth registry remains `PRODUCT-TRUTH-BASELINE-1` / `STABLE-1.0-SCOPE-1` with 372 stable Requirement IDs.

## Latest merged post-RC.12 hardening
Issue #32 — `[P2] Overview Needs Attention count omits actionable shipment/COD work`

- Branch: `fix/needs-attention-count-contract`.
- Runtime/package-source SHA: `6e9e2ea71796170afb0508954a18d206cdc7017a`.
- Final PR head: `658316f775099b0dfc43205bb07c905681a4af10`.
- Merge commit / current master: `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`.
- PR #43: MERGED.
- Issue #32: CLOSED / COMPLETED.
- Runtime CI `35759882478`: SUCCESS.
- Final PR-head CI `35760847856`: SUCCESS.
- Post-merge master CI `35766468276`: SUCCESS.
- Development identity: `1.0.0-dev.attention-count.1`; schema remains `6`.
- Frozen ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.attention-count.1.zip`.
- ZIP bytes: `1,848,319`.
- ZIP SHA-256: `2027e5941916dff0302d7e2e545de5a2fd027f0c8f1615d97e3991dcfc5fdcb3`.
- Training physical QA: PASS. For the same authorized administrator and stable source data: catalog `0`, bulk stale `0`, shipment creation `2`, operations `3`, COD `3`, canonical aggregate `8`, Overview `8`, menu badge `8`.
- Training currently runs `1.0.0-dev.attention-count.1`, schema `6`.
- No Pilot, FLAIROC, production, or POS deployment occurred.

## Active task
Issue #44 — `[DOCS] Post-#32 repository truth synchronization and branch retirement`

- Branch: `docs/post-issue32-truth-sync`.
- Base: protected `master` `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`.
- Scope: documentation/control-plane truth only plus branch-retirement evidence.
- No plugin runtime, schema, version, tag, package, rate, geography, checkout, shipment, COD, or operational-data changes.
- Obsolete PR #15 is CLOSED WITHOUT MERGE as superseded historical documentation.
- Branch-retirement evidence: `docs/recovery/BRANCH-RETIREMENT-2026-09-22.md`.

## Release and package leases
- Published release identity: `v1.0.0-rc.12` / `1.0.0-rc.12`, schema `6`, release source `78594ad8962868683726373f58f4a8b1b48e4d0e`.
- Frozen post-RC.12 packages must not be overwritten, including geo-country, pdp-precision, address-ux, shipment-order-read, and attention-count candidates.
- Minimum supported PHP: 8.3.
- Supported/certified PHP range: 8.3, 8.4, 8.5.x.
- CETECH production target: latest qualified stable PHP 8.5.x.
- Product-control-plane Requirement IDs are frozen; this docs synchronization does not renumber or silently reclassify them.

## Environment authorization
- Training: currently `1.0.0-dev.attention-count.1`, schema `6`; Issue #32 physical QA passed.
- CETECH Pilot: NOT STARTED.
- FLAIROC: NOT DEPLOYED with this post-RC.12 stream.
- Production: NOT DEPLOYED.
- POS repository: outside scope / must not be touched.

## Explicit non-actions
Do not create RC.13. Do not move `v1.0.0-rc.12` or overwrite its ZIP. Do not overwrite frozen development artifacts. Do not start CETECH Pilot. Do not deploy FLAIROC or production. Do not touch POS. Do not start Stage 15. Do not rename Ashanti Region. Do not mutate coverage, rates, Location Packs, checkout behavior, shipment lifecycle, COD semantics, or the Bulk stale threshold.
