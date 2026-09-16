# CETECH Delivery Engine — Current Status

Last reconciled: 2026-09-16 (RC.11 tagged release-candidate closeout).

## Canonical repository
- Organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`
- Default/canonical development branch: protected `master`
- Repository visibility: public during GitHub Free branch/ruleset protection use.
- Composer license declaration remains `proprietary`; public visibility is not an open-source license grant.

## Current tagged release candidate — RC.11
- Tag: `v1.0.0-rc.11`
- Annotated tag object: `acaae9bfc9758cdee1b3f2ec47e94848e83f87da`
- Peels to release source: `384f564f64a2db766ae6907392e95fb366fb8533`
- Version identity: `1.0.0-rc.11`
- Schema: `5`
- Final qualified ZIP: `cetech-woocommerce-delivery-engine-1.0.0-rc.11.zip`
- Final qualified ZIP bytes: `1,545,789`
- Final qualified ZIP SHA-256: `97423a95273f6148ee855d1fb8a66c6c66e47aa20cb2c5868ecf2f84b2a9a521`
- Tag is annotated but unsigned. Do not describe it as signed.
- RC.11 is immutable. Do not move the tag or reuse/overwrite the qualified ZIP identity.

## RC.11 promotion evidence
- Issue #20 — `[RC11] Promote owner-accepted issue #18 baseline to RC.11`: CLOSED / COMPLETED.
- PR #21 release-promotion head: `b397489afc29d65a2b62a1afa57ba8ca6db3ef67`.
- PR #21 merged to protected master release source: `384f564f64a2db766ae6907392e95fb366fb8533`.
- Release-branch CI run `35144591865`: SUCCESS on Runtime PHP 8.1, PHP/PHPUnit 8.2, JavaScript/Vitest, Control Plane.
- Protected-master post-merge CI run `35144871814`: SUCCESS on all four required jobs.
- Final package + isolated WordPress qualification run: `35145775218` SUCCESS.
- Production package verifier: PASS.
- Packaged PHP lint: `448 files / 0 failures`.
- Clean install evidence: `clean version=1.0.0-rc.11 schema=5 tables=all_tables_ok`.
- RC.10 → RC.11 upgrade/data-retention evidence: `upgrade version=1.0.0-rc.11 schema=5 tables=all_tables_ok sentinel=1 option=keep_me`.

## Included post-RC.10 correction — issue #18
Issue #18 — `[P2] Show authoritative delivery price on product-page delivery options`

- Sole owner: `@wbdevworld`
- Final accepted candidate source: `ecb0a69f8375712de0fb7ba53ee8abe2f9b438e5`
- Accepted development identity: `1.0.0-dev.pdp-price.3`
- Integrated via PR #19 into protected master commit `35ff33d6788279a3ab75627f9eb9756b988ccb04`
- Issue #18: CLOSED / COMPLETED

Accepted behavior includes authoritative customer-facing PDP delivery prices, quantity-aware pricing, price + configured ETA display, fail-closed unquoted delivery, Delivery/Pickup capability before location, no implicit store-base-country PDP quote, correct ETA singular/plural, public-safe price payloads, Storefront/WoodMart/mobile qualification, and qualified PDP/cart/checkout amount parity.

## Prior immutable release — RC.10
- Tag: `v1.0.0-rc.10`
- Annotated tag object: `506c2067f963ae7c1753fa0f32e2dc0e30b7d1c9`
- Peels to: `d1409258caf1a90675b689ab105471460de4c713`
- Version identity: `1.0.0-rc.10`
- Schema: `5`
- Original final ZIP SHA-256: `6f451d7d898773ee257ab511f41c7db7199b1017c02de638a7b27c2c584d39ab`
- RC.10 remains immutable.

## Historical baselines preserved
- `v1.0.0-rc.9` peels to `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`.
- Earlier published tags remain immutable.
- Historical qualification branch/PR #12 remains provenance only; do not use it as current team baseline.
- Historical recovered `feat/post-rc9-customer-ux` remains provenance only.
- WPML overlay remains separate on `feat/post-rc9-wpml`; not part of RC.10 or RC.11 core certification.
- Superseded RC.10 docs-only PR #17 is closed and must not be merged into current master.

## Certification boundaries
- WPML/WCML certification: separate / not included.
- WP Rocket certification: separate / not certified.
- WoodMart physically qualified on 8.4.1 for issue #18 PDP-price acceptance.
- Issue #18 owner QA used WordPress 7.1 / WooCommerce 11.0.1 in its isolated lab.
- RC.11 package clean-install/upgrade smoke used isolated GitHub Actions WordPress/WooCommerce containers; WooCommerce 11.1.0 was installed during the successful run.
- External PSP certification: not claimed.
- FLAIROC/training/production deployment or certification: not claimed by RC.11 promotion.
- POS repository / VitePOS: outside scope.

## Development baseline
- Immutable RC.11 release anchor: `v1.0.0-rc.11` / `384f564f64a2db766ae6907392e95fb366fb8533`.
- New work should branch from the latest protected `master`, which may advance beyond the release source through later docs-only or owner-authorized work.
- Release source and current development head are deliberately allowed to differ after release closeout; never move the release tag to follow later master commits.

## Completed forensic / release-control work
- 2026-09-15 local Git/source recovery (issue #2 / PR #9).
- 2026-09-16 Desktop artifact reconciliation (PR #14): all relevant desktop artifacts accounted for.
- 2026-09-16 owner QA of pre-RC.10 qualification baseline: PASS with documented boundaries.
- 2026-09-16 RC.10 promotion completed and tagged.
- 2026-09-16 issue #18 owner acceptance, integration and post-merge CI completed.
- 2026-09-16 RC.11 promotion, final package qualification, annotated tag and closeout completed.

## Remaining separate work
1. Production rollout/pilot only if separately and explicitly owner-authorized.
2. WPML/WCML overlay decision and licensed-dependency certification if included later.
3. WP Rocket certification when a legitimate package is available.
4. Any further product/runtime work only under a new explicitly authorized task.

## Deferred / not started
- Stage 15 is NOT STARTED.
- Advanced carrier APIs.
- Richer customer shipment timeline/emails where outside current V1 scope.
- Later POD/OTP/QR/GPS/photo/driver workflows unless scope is explicitly reopened.
