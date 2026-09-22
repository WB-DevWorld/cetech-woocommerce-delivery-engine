# Current Work — Post-#32 repository truth synchronization

Status: RC.12 IMMUTABLE — ISSUE #32 MERGED/CLOSED — ISSUE #45 REPOSITORY/TRUTH CLEANUP ACTIVE — NO RUNTIME CHANGE — NOT RC.13

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Protected `master` baseline at the start of Issue #45: `88c9ec09f3cfabf73b83780c0c37d397e9acdad6` (PR #43 MERGED; Issue #32 CLOSED / COMPLETED). PR #46 carries this documentation synchronization; once it merges, its merge commit becomes the newer protected-master head. Do not treat `88c9...` as the post-PR-#46 head.
- Post-merge CI run `35766468276`: SUCCESS across PHP 8.3, PHP 8.4, PHP 8.5 production, PHP 8.5 MariaDB, PHP 8.5 WordPress/WooCommerce, JavaScript/Vitest, Control Plane, and CI Required Gates.
- Immutable RC.12 release source: `78594ad8962868683726373f58f4a8b1b48e4d0e`.
- Tag `v1.0.0-rc.12` must not be moved. Do not rebuild or overwrite the RC.12 ZIP.
- Schema remains `6`.
- Do not create RC.13 unless a separate release-promotion task explicitly authorizes it.

## Current training truth
- Training site: `https://training.cetechbpa.com`.
- Installed/active candidate: `1.0.0-dev.attention-count.1`.
- Package-source SHA: `6e9e2ea71796170afb0508954a18d206cdc7017a`.
- Frozen ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.attention-count.1.zip`.
- ZIP bytes: `1,848,319`.
- ZIP SHA-256: `2027e5941916dff0302d7e2e545de5a2fd027f0c8f1615d97e3991dcfc5fdcb3`.
- Training physical QA: PASS.
- Verified post-deploy Needs Attention state for the same authorized administrator and stable data: catalog `0`, stale bulk `0`, shipment creation `2`, operations `3`, COD `3`, canonical aggregate `8`, Overview `8`, menu badge `8`.
- Training deployment is qualification evidence only; it is not RC.13, Pilot, FLAIROC, or production promotion.

## Completed post-RC.12 fix
Issue #32 — `[P2] Overview Needs Attention count omits actionable shipment/COD work`
- PR: #43 — MERGED.
- Issue #32 merge commit / protected-master head before PR #46: `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`.
- Final PR head: `658316f775099b0dfc43205bb07c905681a4af10`.
- Runtime/package-source SHA: `6e9e2ea71796170afb0508954a18d206cdc7017a`.
- Development identity: `1.0.0-dev.attention-count.1`.
- Runtime CI `35759882478`: SUCCESS.
- Final-head PR CI `35760847856`: SUCCESS.
- Post-merge master CI `35766468276`: SUCCESS.
- Technical review: PASS.
- Training physical QA: PASS.
- Issue #32: CLOSED / COMPLETED.
- Evidence: `docs/POST-RC12-NEEDS-ATTENTION-COUNT.md`.

## Repository synchronization checkpoint
Issue #45 — `[REPO] Post-#32 repository truth sync and branch retirement`
- Branch: `docs/post-issue32-repository-truth`.
- Base: protected `master` `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`.
- Scope: documentation/control-plane truth, stale PR retirement, branch classification, and safe branch retirement only. PR #46 is the merge surface for this checkpoint; after merge, Issue #45 is expected to close via `Closes #45`.
- No plugin runtime, schema, package, tag, geography, coverage, rate, checkout, shipment, COD, production, FLAIROC, Pilot, or POS mutation.
- Obsolete PR #15 is CLOSED / SUPERSEDED and must not be merged.
- Branch classification record: `docs/REPOSITORY-BRANCH-RETIREMENT-2026-09-22.md`.

## PHP policy
- Minimum supported PHP: **8.3**.
- Supported/certified range: PHP **8.3, 8.4, and 8.5.x**.
- CETECH production/currently qualified latest stable line: PHP **8.5.x**.
- PHP 8.1 and 8.2 are not supported and must not be advertised.
- Canonical policy: `docs/PHP-RUNTIME-POLICY.md`.

## Central leases
- published release identity/tags: `v1.0.0-rc.11` and `v1.0.0-rc.12` immutable;
- schema/migrations: frozen at `6` for this docs-only task;
- canonical `master`: no force-push/rewrite;
- product-control-plane Requirement IDs: frozen; no renumbering;
- frozen development ZIPs remain immutable;
- GH Location Pack / Accra / Kumasi / Greater Accra charges / sentinel Test AREAAA: not mutated;
- Zone 2 display name remains `Ashanti Region`.

## Environment authorization
- CI and documentation/control-plane verification are authorized for Issue #45.
- Training currently contains the physically qualified `1.0.0-dev.attention-count.1`; no further training mutation is authorized by Issue #45.
- CETECH Pilot: NOT STARTED.
- FLAIROC: NOT DEPLOYED with the post-RC.12 candidate stream.
- Production: NOT DEPLOYED.
- POS repository: outside scope.

## Explicit non-actions
Do not create RC.13. Do not move `v1.0.0-rc.12` or overwrite its ZIP. Do not overwrite frozen development artifacts. Do not start CETECH Pilot. Do not deploy FLAIROC or production. Do not touch POS. Do not start Stage 15. Do not rename Ashanti Region. Do not mutate geography, coverage, rates, shipment lifecycle, COD semantics, or the Bulk stale threshold.
