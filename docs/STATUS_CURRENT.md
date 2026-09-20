# CETECH Delivery Engine — Current Status

Last reconciled: 2026-09-20 (geo.16 Location Packs admin-form P2 closure prepared for differential review; frozen geo.15 ZIP unchanged; Draft PR #24 unmerged; not RC.12).

## Canonical repository
- Organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`
- Default/canonical development branch: protected `master`
- Current protected `master`: `6ee4cef088f0bda2633d4b8e37abf3e37634426b`
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

## Owner-accepted product truth
- `PRODUCT-TRUTH-BASELINE-1` was accepted by the owner on 2026-09-19 with all six decisions resolved in `docs/product/DECISION-CONFLICT-REGISTER.md`.
- The approved 372-Requirement registry and companion artifacts live under `docs/product/`; `docs/AUTHORITY.md` defines which artifact governs each class of truth.
- Stable 1.0 scope is frozen as `STABLE-1.0-SCOPE-1`. This product baseline is not a claim that missing capabilities are implemented or that Stable 1.0 has shipped.
- Product-control-plane publication PR #25 is merged to protected master `6ee4cef088f0bda2633d4b8e37abf3e37634426b`.
- The realignment plan is approved, but geo.16 on issue #23 / Draft PR #24 is the only currently authorized runtime implementation stream. Frozen geo.15 remains the immutable physically qualified geography/runtime baseline except for the confirmed Location Packs admin-form P2. Frozen geo.14 remains an immutable rejected physical-QA candidate.
- After geo.16 confined differential review and narrow physical QA of the form correction, the next release action is a controlled CETECH production Pilot/release candidate. No RC.12 name, package, tag, or deployment is created or authorized by this documentation publication.

## Completed forensic / release-control work
- 2026-09-15 local Git/source recovery (issue #2 / PR #9).
- 2026-09-16 Desktop artifact reconciliation (PR #14): all relevant desktop artifacts accounted for.
- 2026-09-16 owner QA of pre-RC.10 qualification baseline: PASS with documented boundaries.
- 2026-09-16 RC.10 promotion completed and tagged.
- 2026-09-16 issue #18 owner acceptance, integration and post-merge CI completed.
- 2026-09-16 RC.11 promotion, final package qualification, annotated tag and closeout completed.
- 2026-09-19 product-control-plane publication PR #25 merged to protected master.

## Active post-RC.11 implementation stream
- Issue `#23` / draft PR `#24` / branch `feat/canonical-geography-coverage`.
- Frozen technical-stabilization candidate: `1.0.0-dev.geo.11` / `daef41a85662e1c1dc0aa6f5673ca9749f163a26`; schema `6`.
- Frozen rejected candidate: `1.0.0-dev.geo.12` / `41861d261fe9c6ca4dead778464de19e5c03ac40`; schema `6`. ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.12.zip`, `1,757,860` bytes, SHA-256 `e66ab64a4869dd6262fe2cea16f2a7b35949e487e34fbafc5c029bd3cb1203c2`. Do not reuse this package identity.
- Frozen rejected candidate: `1.0.0-dev.geo.13` / `31147df82a819416f15a2729ed87647c6ef80f9e`; schema `6`. ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.13.zip`, `1,758,541` bytes, SHA-256 `c15c44c31ffee4c1baeb1ea6fdeac4fd1255a3ba86cca223ad793fe5c445797a`. Independent geo.12→geo.13 review accepted the intended worker-fencing and hierarchy-boundedness corrections. Do not physically QA geo.13 or reuse its package identity.
- Frozen rejected candidate: `1.0.0-dev.geo.14` / `ea53d0486593199898269479be624de262127692`; schema `6`. ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.14.zip`, `1,759,583` bytes, SHA-256 `cc3f320e8f9856c08d0e5c50ee05f41363db8a8ba79135bde23befdeaa212cc7`. Isolated physical QA confirmed two P1 production-path defects. Do not rebuild or reuse this package identity.
- Frozen physically qualified geography/runtime candidate: `1.0.0-dev.geo.15` / `eb9f4e4893b32ec8e59d47fb90e13adfed85e78e`; schema `6`. ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.15.zip`, `1,763,366` bytes, SHA-256 `95340eed81a3e7d7b5a7bddb3635a825870a6916d5716d27e6ea12f1fcd68690`. Isolated physical QA passed migration/import/coverage/storefront qualification. Confirmed P2: Location Packs forms omitted the shared admin POST contract. Do not rebuild or overwrite this package.
- Current technical-correction candidate: `1.0.0-dev.geo.16` / `7aeb4c573d04d12d8101c0e05bc8858ff332d63f`; schema `6`. ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.16.zip`, `1,764,171` bytes, SHA-256 `500b09878ba08b879e5bdfdb0ea6e58726a71fe90a6994a83bb96b1e314a3925`. Closed P2 pass: Location Packs install, continue/retry and reconciliation forms emit `cetech_de_action` plus `cetech_de_nonce` via `AdminFormHelper`. `AdminActionHandler` unchanged. Matching unchanged. Draft PR #24 unmerged. Do not run physical QA until ChatGPT confirms the geo.15→geo.16 differential is confined.
- Rejected technical-review candidate: `1.0.0-dev.geo.1` / `606730e2535896cb18e415b14d62bbb57d050578` — do not send to physical QA.
- Rejected technical-review candidate: `1.0.0-dev.geo.2` / `a52e2aafc97274b74ec14f8b451395b73c9541af` — frozen ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.2.zip`, SHA-256 `6bde464b249ee6f17831e00d39110c8b10f89f441db3e1b453622a5d95f6b4a5`. Do not physically QA geo.2.
- Rejected technical-review candidate: `1.0.0-dev.geo.3` / `fdff226cbe6837a3ac508bc6677c8e56771ec229` — frozen ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.3.zip`, SHA-256 `1c1af4dcfbc6767402b6e7dfacde9a87270ac5ccb8a950788e130c9d130b49b8`. Do not physically QA geo.3.
- Rejected technical-review candidate: `1.0.0-dev.geo.4` / `48f6a39436bc2464d4e2cfdb47221c7c59542706` — frozen ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.4.zip`, SHA-256 `775ff1b7f31fdffb3ea246f1642693f86dd3e01fd0cb99c729add4831e535456`. Do not physically QA geo.4.
- Rejected technical-review candidate: `1.0.0-dev.geo.5` / `f5da0b6b528e19ae57eedd9bc9bfee5ce05cc57b` — frozen ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.5.zip`, SHA-256 `720a663800b57205637d02b9669eb9930b28b9dc6d1e2b180de6aea10edfa4ee`. Do not physically QA geo.5.
- Rejected technical-review candidate: `1.0.0-dev.geo.6` / `730bcc2fa73fadd0da121487b7439edff5b3dfdc` — frozen ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.6.zip`, SHA-256 `39437fb8a950e88968474eaf71e013b1b9e8a872b9e4b4710e7108d29d9a92c6`. Do not physically QA geo.6.
- Rejected technical-review candidate: `1.0.0-dev.geo.7` / `389674173fbf6886cfbf615c4baa163e13cc12c8` — frozen ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.7.zip`, SHA-256 `d167a0da7841c20bc141a7b3b294acba13291aa6e3e0f0e46cb0f98f36bf616a`. Do not physically QA geo.7.
- Rejected technical-review candidate: `1.0.0-dev.geo.8` / `92cdeae1d1f9916bf38253772ec30240f3e4798b` — frozen ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.8.zip`, SHA-256 `55e3c61297b4b1ab0ad06a3efa3c899c60fdf80f8d0fbd6bec8be5e5342d798f`. Do not physically QA geo.8.
- Rejected technical-review candidate: `1.0.0-dev.geo.9` / `09df7d2337e271792f76290b062c7acba598d80a` — frozen ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.9.zip`, SHA-256 `8ac4067ff8a9b5f1eb6bb1b8ad8224a168517160665804b0aa6b577a17029a47`. Do not physically QA geo.9.
- Rejected technical-review candidate: `1.0.0-dev.geo.10` / `66be34b684c11c165617359b828ccf852fba2635` — frozen ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.10.zip`, SHA-256 `494546cf021909b30a8f0519c816069af72c41b064cbe28b05a31409355f7f47`. Do not physically QA geo.10.
- Rejected whole-branch closure-review candidate: `1.0.0-dev.geo.11` / `daef41a85662e1c1dc0aa6f5673ca9749f163a26` — frozen ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.11.zip`, SHA-256 `5ba3eb3a6b10eba60e36735085071b628d1dc030e85f4e79f67e5753c9331400`. Do not physically QA geo.11.
- RC.11 remains the immutable tagged release candidate. This stream is not RC.12 and must not be packaged as `1.0.0-rc.11`.
- Architecture: `docs/POST-RC11-CANONICAL-GEOGRAPHY-COVERAGE.md`.
- Historical qualification PR `#12` / `1.0.0-dev.qual.1` is provenance only.

## Remaining separate work
1. ChatGPT differential review of the exact geo.15→geo.16 Location Packs form-contract correction. Do not merge. Do not mark PR #24 ready. Do not run physical QA until that review accepts the differential as confined.
2. After acceptance, narrow physical QA of the exact geo.16 ZIP (natural Location Packs POST, negative security, pack-lifecycle smoke, activation/PDP smoke). Inherit geo.15 evidence for byte-identical production files.
3. Create the controlled CETECH production Pilot/release candidate only after an owner-accepted physically qualified package; deployment remains a separate action.
4. Certify the Stable 1.0 launch floor: WooCommerce/HPOS, Classic, Blocks, Storefront, current supported WoodMart, B2BKing, and FOX/WOOCS.
5. Certify WPML/WCML before Stable 1.0 only if it will be advertised as supported at launch; keep other optional targets explicitly uncertified until evidence exists.
6. Execute later approved realignment waves only after their predecessor gates and without a second concurrent runtime stream.
7. Do not infer RC.12 or Stage 15.

## Deferred / not started
- Stage 15 is NOT STARTED.
- Advanced carrier APIs.
- Richer customer shipment timeline/emails where outside current V1 scope.
- Later POD/OTP/QR/GPS/photo/driver workflows unless scope is explicitly reopened.
