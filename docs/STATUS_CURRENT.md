# CETECH Delivery Engine — Current Status

Last reconciled: 2026-09-16 (RC.11 promotion preparation).

## Canonical repository
- Organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`
- Default/canonical published branch: `master`
- Protected `master` at RC.11 promotion start: `35ff33d6788279a3ab75627f9eb9756b988ccb04`
- This master commit integrates the exact owner-accepted issue #18 candidate `ecb0a69f8375712de0fb7ba53ee8abe2f9b438e5`.
- Repository visibility: public during GitHub Free branch/ruleset protection use.
- Composer license declaration remains `proprietary`; public visibility is not an open-source license grant.

## Current tagged release baseline
- Tag: `v1.0.0-rc.10`
- Peels to: `d1409258caf1a90675b689ab105471460de4c713`
- Version identity: `1.0.0-rc.10`
- Schema: `5`
- RC.10 is immutable. Do not move the tag or overwrite its ZIP.

## Accepted post-RC.10 integration
Issue #18 — `[P2] Show authoritative delivery price on product-page delivery options`

- Owner: `@wbdevworld`
- Accepted candidate source: `ecb0a69f8375712de0fb7ba53ee8abe2f9b438e5`
- Accepted candidate identity: `1.0.0-dev.pdp-price.3`
- Integrated via PR #19 into protected `master` merge commit `35ff33d6788279a3ab75627f9eb9756b988ccb04`
- Post-merge CI run `35143791024`: SUCCESS on Runtime PHP 8.1, PHP/PHPUnit 8.2, JavaScript/Vitest, Control Plane
- Issue #18: CLOSED / COMPLETED

Accepted behavior includes authoritative customer-facing PDP delivery prices, quantity-aware pricing, price/ETA rendering, fail-closed unquoted delivery, Delivery/Pickup capability before location, PDP no longer auto-selecting the store base country, correct ETA singular/plural, Storefront/WoodMart qualification, and PDP/cart/checkout parity for the qualified single-group cases.

## RC.11 promotion
GitHub issue #20 — `[RC11] Promote owner-accepted issue #18 baseline to RC.11`

- Release branch: `release/rc11`
- Promotion source baseline: protected `master` `35ff33d6788279a3ab75627f9eb9756b988ccb04`
- Target version identity: `1.0.0-rc.11`
- Schema: `5`
- Scope: release identity/bookkeeping/qualification only; no new runtime feature work
- Final tag/package: not yet published at this stage of this document

Required before final publication:
1. Exact RC.11 source green on all required CI jobs.
2. Production ZIP from the exact final release source.
3. Package verifier PASS on extracted production root.
4. Packaged PHP lint PASS.
5. Clean-install smoke PASS.
6. RC.10 → RC.11 upgrade smoke PASS.
7. Exact ZIP bytes/SHA-256 recorded.
8. Annotated `v1.0.0-rc.11` tag peels to exact protected-master release commit.

## Historical baselines preserved
- `v1.0.0-rc.9` peels to `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`.
- Earlier published tags remain immutable.
- Historical qualification branch/PR #12 remains provenance only; do not use it as current team baseline.
- Historical recovered `feat/post-rc9-customer-ux` remains provenance only.
- WPML overlay remains separate on `feat/post-rc9-wpml`; not part of RC.10 or RC.11 core certification.

## Certification boundaries
- WPML/WCML certification: separate / not included.
- WP Rocket certification: separate / not certified.
- WoodMart physically qualified on 8.4.1 for the issue #18 PDP-price acceptance work.
- WordPress isolated QA used 7.1 for issue #18.
- External PSP certification: not claimed.
- FLAIROC/training/production certification: not claimed by RC.11 promotion.
- VitePOS/POS repository: outside scope.

## Completed forensic / release-control work
- 2026-09-15 local Git/source recovery (issue #2 / PR #9).
- 2026-09-16 Desktop artifact reconciliation (PR #14): all relevant desktop artifacts accounted for.
- 2026-09-16 owner QA of pre-RC.10 qualification baseline: PASS with documented boundaries.
- 2026-09-16 RC.10 promotion completed and tagged.
- 2026-09-16 issue #18 owner acceptance, protected-master integration, and post-merge CI completed.

## Remaining separate work
1. RC.11 release qualification/finalization under issue #20.
2. WPML/WCML overlay decision and licensed-dependency certification if included later.
3. WP Rocket certification when a legitimate package is available.
4. Production rollout/pilot remains a separate human-authorized activity.

## Deferred
- Stage 15 is not started.
- Advanced carrier APIs.
- Richer customer shipment timeline/emails where outside current V1 scope.
- Later POD/OTP/QR/GPS/photo/driver workflows unless scope is explicitly reopened.
