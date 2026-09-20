# CETECH Delivery Engine — Current Status

Last reconciled: 2026-09-20 (RC.12 release-promotion preparation; Issue #23 CLOSED/COMPLETED; PR #24 MERGED; geo.16 immutable; Pilot not authorized).

## Canonical repository
- Organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`
- Default/canonical development branch: protected `master`
- Current protected `master`: `3d786ba6440a5f6f850d736bda0da5a5f5236c1f`
- This master commit is the merge of owner-accepted PR #24 (Issue #23 / `1.0.0-dev.geo.16`).
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

## RC.12 promotion
GitHub issue #26 — `[RC12] Promote owner-accepted geography baseline to RC.12`

- Release branch: `release/rc12` from exact protected master `3d786ba6440a5f6f850d736bda0da5a5f5236c1f`
- Target version identity: `1.0.0-rc.12`
- Schema: `6`
- Scope: release identity/bookkeeping/qualification only; no new runtime feature work
- Final tag/package: not yet published
- `v1.0.0-rc.12` does not exist

Required before merge:
1. All four required CI jobs green on the exact RC.12 PR head.
2. Version identity `1.0.0-rc.12`, schema 6, no runtime/schema drift beyond promotion.
3. Owner/ChatGPT merge-safety authorization.

Required after merge before tag:
1. Protected-master CI green.
2. Exact final RC.12 ZIP built from the final protected-master release commit.
3. Production-package verifier PASS.
4. Packaged PHP lint PASS.
5. Clean-install smoke PASS.
6. RC.11 → RC.12 upgrade/data-retention smoke PASS.
7. Optionally geo.16 → RC.12 identity-upgrade smoke if the existing harness supports it cheaply.
8. Final ZIP bytes/SHA-256 recorded.
9. Annotated `v1.0.0-rc.12` tag peels to the exact protected-master release commit.

## Closed Issue #23 / merged PR #24
Issue #23 — `[POST-RC11] Canonical geography, coverage groups, cascading location UX, and delivery-card redesign`

- Sole owner: `@wbdevworld`
- Issue #23: CLOSED / COMPLETED
- PR #24: MERGED at `3d786ba6440a5f6f850d736bda0da5a5f5236c1f`
- Protected-master post-merge CI run `35519854001`: SUCCESS
- Owner-accepted geo.16 remains immutable qualification provenance:
  - Identity: `1.0.0-dev.geo.16`
  - Package-source SHA: `7aeb4c573d04d12d8101c0e05bc8858ff332d63f`
  - ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.16.zip`
  - Bytes: `1,764,171`
  - SHA-256: `500b09878ba08b879e5bdfdb0ea6e58726a71fe90a6994a83bb96b1e314a3925`
  - Schema: `6`

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
- WPML overlay remains separate on `feat/post-rc9-wpml`; not part of RC.10, RC.11 or RC.12 core certification.
- Superseded RC.10 docs-only PR #17 is closed and must not be merged into current master.

## Certification boundaries
- WPML/WCML certification: separate / not included.
- WP Rocket certification: separate / not certified.
- WoodMart physically qualified on 8.4.1 for issue #18 PDP-price acceptance.
- Issue #18 owner QA used WordPress 7.1 / WooCommerce 11.0.1 in its isolated lab.
- RC.11 package clean-install/upgrade smoke used isolated GitHub Actions WordPress/WooCommerce containers; WooCommerce 11.1.0 was installed during the successful run.
- External PSP certification: not claimed.
- FLAIROC/training/production deployment or certification: not claimed by RC.11 or this RC.12 promotion.
- CETECH Pilot: not authorized by this RC.12 promotion.
- POS repository / VitePOS: outside scope.

## Development baseline
- Immutable RC.11 release anchor: `v1.0.0-rc.11` / `384f564f64a2db766ae6907392e95fb366fb8533`.
- Current protected-master runtime baseline: `3d786ba6440a5f6f850d736bda0da5a5f5236c1f` (schema `6`).
- New work should branch from the latest protected `master` after the RC.12 promotion closes, unless a later owner instruction names a different surface.
- Release source and current development head are deliberately allowed to differ after release closeout; never move a release tag to follow later master commits.

## Owner-accepted product truth
- `PRODUCT-TRUTH-BASELINE-1` was accepted by the owner on 2026-09-19 with all six decisions resolved in `docs/product/DECISION-CONFLICT-REGISTER.md`.
- The approved 372-Requirement registry and companion artifacts live under `docs/product/`; `docs/AUTHORITY.md` defines which artifact governs each class of truth.
- Stable 1.0 scope is frozen as `STABLE-1.0-SCOPE-1`. This product baseline is not a claim that missing capabilities are implemented or that Stable 1.0 has shipped.
- Product-control-plane publication PR #25 is merged to protected master `6ee4cef088f0bda2633d4b8e37abf3e37634426b` and is now an ancestor of current master `3d786ba6440a5f6f850d736bda0da5a5f5236c1f`.
- Frozen Requirement IDs must not be renumbered. RC.12 promotion does not change product-registry classifications.

## Completed forensic / release-control work
- 2026-09-15 local Git/source recovery (issue #2 / PR #9).
- 2026-09-16 Desktop artifact reconciliation (PR #14): all relevant desktop artifacts accounted for.
- 2026-09-16 owner QA of pre-RC.10 qualification baseline: PASS with documented boundaries.
- 2026-09-16 RC.10 promotion completed and tagged.
- 2026-09-16 issue #18 owner acceptance, integration and post-merge CI completed.
- 2026-09-16 RC.11 promotion, final package qualification, annotated tag and closeout completed.
- 2026-09-19 product-control-plane publication PR #25 merged to protected master.
- 2026-09-20 Issue #23 / PR #24 merged as owner-accepted `1.0.0-dev.geo.16`; protected-master CI `35519854001` SUCCESS.

## Geography provenance (immutable; not rebuilt)
- Frozen physically qualified geography/runtime candidate: `1.0.0-dev.geo.15` / `eb9f4e4893b32ec8e59d47fb90e13adfed85e78e`; schema `6`. ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.15.zip`, `1,763,366` bytes, SHA-256 `95340eed81a3e7d7b5a7bddb3635a825870a6916d5716d27e6ea12f1fcd68690`.
- Frozen rejected physical-QA candidate: `1.0.0-dev.geo.14` / `ea53d0486593199898269479be624de262127692`; schema `6`. ZIP SHA-256 `cc3f320e8f9856c08d0e5c50ee05f41363db8a8ba79135bde23befdeaa212cc7`.
- Frozen rejected technical-review candidates geo.1–geo.13 remain provenance only. Do not rebuild or reuse those package identities.
- Architecture: `docs/POST-RC11-CANONICAL-GEOGRAPHY-COVERAGE.md`.
- Historical qualification PR `#12` / `1.0.0-dev.qual.1` is provenance only.

## Recorded P3 / later cleanup (not in RC.12)
- Blocks script dependency warning.
- Variable ETA copy/prefix.
- Lamp default selection UX.
- Optional Blocks totals evidence polish.

## Remaining separate work
1. ChatGPT merge-safety review of the RC.12 promotion PR, then protected merge only after that authorization.
2. After merge: protected-master CI, exact final RC.12 ZIP, verifier, packaged PHP lint, clean-install and RC.11 → RC.12 upgrade smokes, annotated tag. Not part of this preparation PR.
3. Create the controlled CETECH production Pilot only after a separate owner authorization; deployment remains a separate action.
4. Certify the Stable 1.0 launch floor: WooCommerce/HPOS, Classic, Blocks, Storefront, current supported WoodMart, B2BKing, and FOX/WOOCS.
5. Certify WPML/WCML before Stable 1.0 only if it will be advertised as supported at launch; keep other optional targets explicitly uncertified until evidence exists.
6. Do not start Stage 15.

## Deferred / not started
- Stage 15 is NOT STARTED.
- Advanced carrier APIs.
- Richer customer shipment timeline/emails where outside current V1 scope.
- Later POD/OTP/QR/GPS/photo/driver workflows unless scope is explicitly reopened.
