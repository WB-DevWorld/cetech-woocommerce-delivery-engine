# CETECH Delivery Engine — Current Status

Last reconciled: 2026-09-21 (PR #36 MERGED onto protected `master` `514ddfc1b4d10b2171d3d3bae020381198c59d2b` — Issue #35 CLOSED; supported/certified PHP 8.3–8.5.x, minimum 8.3, CETECH production 8.5.x; RC.12 tagged from `78594ad`; Issue #26 CLOSED; Issue #29 MERGED; Issue #33 MERGED to `5abfab0`; Issue #38 post-RC.12 PDP location-precision candidate `1.0.0-dev.pdp-precision.2` is **not** RC.13 and is **not deployed**; frozen `1.0.0-dev.pdp-precision.1` ZIP must not be overwritten; Pilot not authorized).

## Canonical repository
- Organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`
- Default/canonical development branch: protected `master`
- Current protected `master`: `514ddfc1b4d10b2171d3d3bae020381198c59d2b`
- RC.12 publication merge on `master`: `78594ad8962868683726373f58f4a8b1b48e4d0e` (PR #27 / `release/rc12` onto `3d786ba6440a5f6f850d736bda0da5a5f5236c1f`).
- Later documentation commits on `master` are **not** the RC.12 tag source.
- Repository visibility: public during GitHub Free branch/ruleset protection use.
- Composer license declaration remains `proprietary`; public visibility is not an open-source license grant.

## PHP runtime policy
- Canonical policy: `docs/PHP-RUNTIME-POLICY.md`. Realignment evidence: `docs/PHP-85-CI-REALIGNMENT.md`.
- **Supported / certified range today:** PHP 8.3, 8.4, and 8.5.x.
- **Minimum supported PHP:** 8.3. Plugin header, Composer, and activation guard are `>=8.3`. This is the WordPress + WooCommerce recommended floor, not the oldest version those products can still boot.
- **Recommended production PHP:** latest qualified stable release. **Currently qualified latest stable:** PHP 8.5.x (latest stable patch at deployment; 8.5.10 as of 21 September 2026).
- **CI:** PHP 8.3 Minimum Supported (blocking); PHP 8.4 Compatibility; **PHP 8.5 CETECH Production Target (blocking)** plus MariaDB and WordPress/WooCommerce jobs. PHP 8.1 and 8.2 must not have support lanes.
- PHP 8.6 pre-release must not be used as a production target. A later stable PHP line is added only after WordPress, WooCommerce, Delivery Engine, and CETECH stack qualification.
- PHP 8.1 and PHP 8.2 are **not** supported and must not be advertised.
- Historical RC.12 required checks (`Runtime PHP 8.1`, `PHP / PHPUnit 8.2`) are provenance of that tag. They must not be read as current commercial support. FLAIROC Stage 0B already recorded PHP 8.5.5.
- Isolated PHP 8.5 QA Compose: `docker/php85-qa/`.


## Current tagged release candidate — RC.12
- Tag: `v1.0.0-rc.12`
- Type: unsigned annotated `tag`
- Annotated tag object: `89f34883a017b8bb66f98db345fbbae0d8dd72b0`
- Peels to release source: `78594ad8962868683726373f58f4a8b1b48e4d0e`
- Merge parents: `3d786ba6440a5f6f850d736bda0da5a5f5236c1f` + `3eaf0d77ec277e4bb3b633da1ac08e08c0790ea7`
- Merge time: `2026-09-20T16:15:10Z`
- Version identity: `1.0.0-rc.12`
- Schema: `6`
- Protected-master CI run `35522128310`: SUCCESS (Runtime PHP 8.1, PHP/PHPUnit 8.2, JavaScript/Vitest, Control Plane)
- GitHub prerelease: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/releases/tag/v1.0.0-rc.12
- Final ZIP: `cetech-woocommerce-delivery-engine-1.0.0-rc.12.zip`
- Final ZIP bytes: `1,767,204`
- Final ZIP SHA-256: `46508c566b505ac470ae94d2829de068e4ff1b53bb4c22e31201e038fb8e03d1`
- Downloaded GitHub asset verification: PASS (same filename, bytes, SHA-256)
- Production-package verifier: PASS
- Packaged PHP lint: `498 files / 0 failures`
- Clean install: `clean version=1.0.0-rc.12 schema=6 tables=all_tables_ok geo=schema6_tables_ok`
- RC.11 → RC.12 upgrade/retention: PASS (`sentinel=1 zone=1 rule=1 option=keep_me coverage_groups=1`; schema-6 conversion `completed`, `converted=1`, countries `GH`)
- Optional geo.16 → RC.12 identity smoke: PASS (`sentinel=1 option=keep_geo16`)
- Issue `#26`: CLOSED / COMPLETED
- Evidence: `docs/RC12-PROMOTION.md`
- Do not move this tag to a later documentation commit. Do not rebuild the ZIP for docs closeout.

## Post-RC.12 candidate — Issue #38 (not a release)
- Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/38
- Branch: `fix/pdp-location-precision`
- Base: protected `master` `514ddfc1b4d10b2171d3d3bae020381198c59d2b`
- Development identity: `1.0.0-dev.pdp-precision.2`
- Schema: `6` (unchanged)
- Requires PHP: `8.3`
- Runtime / package-source SHA: `05b9b393995c884d30f9d761513fcd3beb622587`
- PR: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/40 (open; do not merge)
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.pdp-precision.2.zip`
- Bytes: `1,824,330`
- SHA-256: `1b0c02dcc88370b5f8535c59acb00ad3773a95b2182539b333778046a913557e`
- Frozen pdp-precision.1 ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.pdp-precision.1.zip` (`1,823,157` bytes, SHA-256 `5773b5b2eb9d63bdc5e8b92bbbd156cb78ba4bc4deb74f34db105673542d3fda`)
- Not merged. Not deployed. Not RC.13. Awaiting final deployment review. Do not close Issue #38.
- Evidence: `docs/POST-RC12-PDP-LOCATION-PRECISION.md`

## Post-RC.12 merged — Issue #35 (not a release)
- Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/35
- MERGED via PR #36 onto protected `master` `514ddfc1b4d10b2171d3d3bae020381198c59d2b`
- Development identity: `1.0.0-dev.geo-country.5`
- Schema: `6` (unchanged)
- Requires PHP: `8.3`
- Runtime / package-source SHA: `ea2d412326e369bc7168b9de0dd01875673e71e6`
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.5.zip`
- Bytes: `1,812,883`
- SHA-256: `0d02bb14e2652dd635e858a3c8d4e65c233de81872ffa772225530a525e3d54d`
- Frozen geo-country.4 ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.4.zip` (`1,798,250` bytes, SHA-256 `2fc4996c0e403c41bce200dec5be680a05f56f9e666b5a3e4a13a400dba24354`)
- Frozen geo-country.3 ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.3.zip` (`1,797,361` bytes, SHA-256 `a86a940d8ebac2c9e293dad8690ed8f121b5afa66cb3f1e0537f3f4152b26c66`)
- Frozen geo-country.2 ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.2.zip` (`1,798,020` bytes, SHA-256 `6e7f76e2d218a01e2af401cc646471dc2ce27afc8a52c6ad15e671df0b88122c`)
- Frozen geo-country.1 ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.1.zip` (`1,794,741` bytes, SHA-256 `216e3a28d37e6af5f6cf97a69ef362dc946a94b1d26d8508c3d747d582198a6c`)
- PR: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/36
- CLOSED / COMPLETED. Not RC.13. Training physical repair QA COMPLETE 2026-09-21.
- Evidence: `docs/POST-RC12-GEONAMES-COUNTRY-IDENTITY.md`


## Post-RC.12 merged — Issue #33 (not a release)
- Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/33
- MERGED via PR #34 onto protected `master` `5abfab0b5078e67b158f282088022b2ac2566f22`
- Development identity: `1.0.0-dev.geo-live.2`
- Schema: `6` (unchanged)
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-live.2.zip`
- Bytes: `1,786,463`
- SHA-256: `c5ea75fd407aa32a9e443a3f883e3036785cc776dcf715a040026087fdf8c149`
- Frozen geo-live.1 ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-live.1.zip` (`1,783,293` bytes, SHA-256 `6281f1b3f7d080ac9bb528fc1a117be909ac1ae05c052d6fe704cc053192ccbf`)
- Not RC.13.
- Evidence: `docs/POST-RC12-GEO-LIVENESS.md`

## Post-RC.12 merged — Issue #29 (not a release)
- Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/29
- MERGED via PR #30 onto protected `master` `83effbf081e54b47ef088673c8ac18217ab9dfda`
- Development identity: `1.0.0-dev.checkout-mdest.1`
- Package-source SHA: `b07c3eb1ee3556e5b184ce072c1833ba04459cf9`
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.checkout-mdest.1.zip`
- Bytes: `1,778,523`
- SHA-256: `8ceb27dfa3381db23917fbcae45f31453e8588598241f0c4e9af4fd7855c5df1`
- Schema: `6` (unchanged)
- Not RC.13.
- Evidence: `docs/POST-RC12-CHECKOUT-MULTIDEST.md`

## Prior tagged release candidate — RC.11
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

- Issue `#26`: CLOSED / COMPLETED
- Release branch: `release/rc12`
- PR #27: MERGED
- Version identity: `1.0.0-rc.12`
- Schema: `6`
- Scope: release identity/bookkeeping/qualification only; no new runtime feature work
- Annotated tag `v1.0.0-rc.12` published; GitHub prerelease published
- Closeout comment: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/26#issuecomment-5751247411

## Closed Issue #23 / merged PR #24
Issue #23 — `[POST-RC11] Canonical geography, coverage groups, cascading location UX, and delivery-card redesign`

- Sole owner: `@wbdevworld`
- Issue #23: CLOSED / COMPLETED
- PR `#24`: MERGED at `3d786ba6440a5f6f850d736bda0da5a5f5236c1f`
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
- RC.12 isolated qualification used WordPress `php8.2-apache` + WooCommerce `11.0.1` in Compose project `cetech-rc12-qual`. That isolated lab is historical provenance, not a FLAIROC or production claim, and is **not** the CETECH PHP 8.5 production target. Current isolated QA Compose is `docker/php85-qa/` (`wordpress:php8.5-apache` + MariaDB 11.4).
- Training site `https://training.cetechbpa.com`: RC.12 installed, schema 6, training-site qualification **PASS**. That is **not** Stable-1.0 certification.
- FLAIROC: **NOT DEPLOYED**.
- Production: **NOT DEPLOYED**.
- CETECH Pilot: **NOT STARTED**. This tag/prerelease is not Pilot authorization.
- POS repository / VitePOS: outside scope.

## Development baseline
- Immutable RC.11 release anchor: `v1.0.0-rc.11` / `384f564f64a2db766ae6907392e95fb366fb8533`.
- Current tagged RC.12 release source: `v1.0.0-rc.12` / `78594ad8962868683726373f58f4a8b1b48e4d0e` (schema `6`).
- New work should branch from latest protected `master` unless the owner names a different surface.
- Release source and later documentation commits are deliberately allowed to differ; never move a release tag to follow later master commits.

## Owner-accepted product truth
- `PRODUCT-TRUTH-BASELINE-1` was accepted by the owner on 2026-09-19 with six decisions resolved in `docs/product/DECISION-CONFLICT-REGISTER.md`. Decision 7 (21 September 2026, superseded) incorrectly retained PHP 8.1 as a commercial floor. Decision 8 records the owner correction: supported/certified PHP 8.3–8.5.x, minimum 8.3, CETECH production 8.5.x.
- The approved 372-Requirement registry and companion artifacts live under `docs/product/`; `docs/AUTHORITY.md` defines which artifact governs each class of truth.
- Stable 1.0 scope is frozen as `STABLE-1.0-SCOPE-1`. This product baseline is not a claim that missing capabilities are implemented or that Stable 1.0 has shipped.
- Product-control-plane publication PR #25 is merged to protected master `6ee4cef088f0bda2633d4b8e37abf3e37634426b` and is now an ancestor of current master `78594ad8962868683726373f58f4a8b1b48e4d0e`.
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
- 2026-09-20 PR #27 merged as RC.12 identity promotion; protected-master CI `35522128310` SUCCESS; annotated tag `v1.0.0-rc.12` / `89f34883a017b8bb66f98db345fbbae0d8dd72b0` published; GitHub prerelease published; Issue `#26` CLOSED / COMPLETED.

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

## Current documentation stream
- Branch `docs/rc12-training-realignment` realigns `docs/training/` for RC.12 Location Packs, Coverage Groups, review-required coverage, and the recommended setup order.
- Later documentation commits on `master` are **not** the RC.12 tag source and must not receive the `v1.0.0-rc.12` tag.
- Training site `https://training.cetechbpa.com` currently runs RC.12 / schema 6. Training-site qualification: **PASS**. Do not mutate the live training site from this docs stream. Training-site qualification is **not** Stable-1.0 certification.
- CETECH Pilot: **NOT STARTED**. FLAIROC: **NOT DEPLOYED**. Production: **NOT DEPLOYED**.

## Remaining separate work
1. Create the controlled CETECH production Pilot only after a separate owner authorization; this tag/prerelease is not that authorization.
2. Certify the Stable 1.0 launch floor: WooCommerce/HPOS, Classic, Blocks, Storefront, current supported WoodMart, B2BKing, and FOX/WOOCS.
3. Certify WPML/WCML before Stable 1.0 only if it will be advertised as supported at launch; keep other optional targets explicitly uncertified until evidence exists.
4. Recorded P3 cleanup remains later work.
5. Do not start Stage 15.
6. Do not create RC.13 from this closeout.

## Deferred / not started
- Stage 15 is NOT STARTED.
- Advanced carrier APIs.
- Richer customer shipment timeline/emails where outside current V1 scope.
- Later POD/OTP/QR/GPS/photo/driver workflows unless scope is explicitly reopened.
