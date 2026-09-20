# Current Work — Post-RC.11 Canonical Geography Stream

Status: ISSUE #23 GEO.16 LOCATION PACKS ADMIN-FORM P2 CLOSURE / READY FOR DIFFERENTIAL REVIEW / DRAFT PR #24 OPEN / NOT MERGED / NOT RC.12 / DO NOT RUN PHYSICAL QA UNTIL THE GEO.15→GEO.16 DIFFERENTIAL IS ACCEPTED AS CONFINED

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Current protected `master`: `6ee4cef088f0bda2633d4b8e37abf3e37634426b` (PR #25 product-control-plane merge).
- Current tagged release candidate: `v1.0.0-rc.11`.
- RC.11 annotated tag object: `acaae9bfc9758cdee1b3f2ec47e94848e83f87da`.
- RC.11 tag peels to immutable release source: `384f564f64a2db766ae6907392e95fb366fb8533`.
- RC.11 version identity: `1.0.0-rc.11`; schema: `5`.
- Final qualified RC.11 ZIP: `cetech-woocommerce-delivery-engine-1.0.0-rc.11.zip`.
- Final qualified ZIP bytes: `1,545,789`.
- Final qualified ZIP SHA-256: `97423a95273f6148ee855d1fb8a66c6c66e47aa20cb2c5868ecf2f84b2a9a521`.
- Prior RC.10 remains immutable: `v1.0.0-rc.10` → `d1409258caf1a90675b689ab105471460de4c713`.
- Stage 15: NOT STARTED.
- RC.12: DOES NOT EXIST and is not implied by this stream.

## Owner-approved product control plane
- On 2026-09-19 the owner accepted `PRODUCT-TRUTH-BASELINE-1`, resolved all six recorded product decisions, and approved the realignment plan.
- The authoritative product-control-plane package is published under `docs/product/` on protected `master` via PR #25 (`6ee4cef088f0bda2633d4b8e37abf3e37634426b`).
- `docs/AUTHORITY.md` assigns governing responsibility by artifact. The 372 Requirement IDs are frozen and must not be renumbered.
- Stable 1.0 scope checkpoint: `STABLE-1.0-SCOPE-1`.
- Product-control-plane artifacts do not by themselves authorize extra runtime work. Issue #23 / Draft PR #24 / geo.16 is the only currently authorized runtime implementation stream. Frozen geo.15 remains the immutable physically qualified geography/runtime baseline except for the confirmed Location Packs admin-form P2. Frozen geo.14 remains an immutable rejected physical-QA candidate.

## Active stream — issue #23
- Issue: `#23 — [POST-RC11] Canonical geography, coverage groups, cascading location UX, and delivery-card redesign`.
- Draft PR: `#24`.
- Implementation branch: `feat/canonical-geography-coverage`.
- Branch created from exact protected master: `72fa354d52b49ffd9cbc32862a6b2e3d7117ea4e`.
- Architecture baseline commit: `657e1f9481e1cfe974d2d70fe52a8da28b4176f0`.
- Architecture document: `docs/POST-RC11-CANONICAL-GEOGRAPHY-COVERAGE.md`.
- Frozen technical-stabilization candidate: `1.0.0-dev.geo.11` / `daef41a85662e1c1dc0aa6f5673ca9749f163a26`.
- Frozen rejected candidate: `1.0.0-dev.geo.12` / `41861d261fe9c6ca4dead778464de19e5c03ac40`. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.12.zip` (SHA-256 `e66ab64a4869dd6262fe2cea16f2a7b35949e487e34fbafc5c029bd3cb1203c2`). Do not physically QA geo.12 or reuse its package identity.
- Frozen rejected candidate: `1.0.0-dev.geo.13` / `31147df82a819416f15a2729ed87647c6ef80f9e`. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.13.zip` (SHA-256 `c15c44c31ffee4c1baeb1ea6fdeac4fd1255a3ba86cca223ad793fe5c445797a`). Independent geo.12→geo.13 review accepted the intended worker-fencing and hierarchy-boundedness corrections. Do not physically QA geo.13 or reuse its package identity.
- Frozen rejected candidate: `1.0.0-dev.geo.14` / `ea53d0486593199898269479be624de262127692`. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.14.zip` (1,759,583 bytes, SHA-256 `cc3f320e8f9856c08d0e5c50ee05f41363db8a8ba79135bde23befdeaa212cc7`). Isolated physical QA confirmed two P1 production-path defects. Do not rebuild, overwrite or reuse the geo.14 package identity.
- Frozen physically qualified geography/runtime candidate: `1.0.0-dev.geo.15` / `eb9f4e4893b32ec8e59d47fb90e13adfed85e78e`. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.15.zip` (1,763,366 bytes, SHA-256 `95340eed81a3e7d7b5a7bddb3635a825870a6916d5716d27e6ea12f1fcd68690`). Isolated physical QA passed migration, worker/lease, Ghana import, coverage, storefront, WoodMart, Classic/Blocks and pricing qualification. One confirmed P2 remains: Location Packs admin forms did not satisfy `AdminActionHandler::verify_post()`. Do not rebuild, overwrite or reuse the geo.15 package identity.
- Authorized current identity: `1.0.0-dev.geo.16` (schema `6`).
- Product-control-plane sync: merged `origin/master` `6ee4cef088f0bda2633d4b8e37abf3e37634426b` into `feat/canonical-geography-coverage` at pre-sync `daef41a85662e1c1dc0aa6f5673ca9749f163a26`; post-sync merge commit `a7b3592d2d862a7b7a279087a66813104d04642c`. Conflicts resolved only in `CURRENT-WORK.md` and `docs/STATUS_CURRENT.md`. `ci.yml` auto-merged and preserves feat/** CI plus product-control-plane validation. No rebase/squash/history rewrite.
- Target schema: `6`.
- `1.0.0-dev.geo.1` (`606730e2535896cb18e415b14d62bbb57d050578`) is a rejected technical-review candidate. Do not send it to physical QA or reuse its package identity.
- `1.0.0-dev.geo.2` (`a52e2aafc97274b74ec14f8b451395b73c9541af`) is a rejected technical-review candidate. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.2.zip` (SHA-256 `6bde464b249ee6f17831e00d39110c8b10f89f441db3e1b453622a5d95f6b4a5`). Do not physically QA geo.2 or reuse its package identity.
- `1.0.0-dev.geo.3` (`fdff226cbe6837a3ac508bc6677c8e56771ec229`) is a rejected technical-review candidate. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.3.zip` (SHA-256 `1c1af4dcfbc6767402b6e7dfacde9a87270ac5ccb8a950788e130c9d130b49b8`). Do not physically QA geo.3 or reuse its package identity.
- `1.0.0-dev.geo.4` (`48f6a39436bc2464d4e2cfdb47221c7c59542706`) is a rejected technical-review candidate. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.4.zip` (SHA-256 `775ff1b7f31fdffb3ea246f1642693f86dd3e01fd0cb99c729add4831e535456`). Do not physically QA geo.4 or reuse its package identity.
- `1.0.0-dev.geo.5` (`f5da0b6b528e19ae57eedd9bc9bfee5ce05cc57b`) is a rejected technical-review candidate. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.5.zip` (SHA-256 `720a663800b57205637d02b9669eb9930b28b9dc6d1e2b180de6aea10edfa4ee`). Do not physically QA geo.5 or reuse its package identity.
- `1.0.0-dev.geo.6` (`730bcc2fa73fadd0da121487b7439edff5b3dfdc`) is a rejected technical-review candidate. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.6.zip` (SHA-256 `39437fb8a950e88968474eaf71e013b1b9e8a872b9e4b4710e7108d29d9a92c6`). Do not physically QA geo.6 or reuse its package identity.
- `1.0.0-dev.geo.7` (`389674173fbf6886cfbf615c4baa163e13cc12c8`) is a rejected technical-review candidate. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.7.zip` (SHA-256 `d167a0da7841c20bc141a7b3b294acba13291aa6e3e0f0e46cb0f98f36bf616a`). Do not physically QA geo.7 or reuse its package identity.
- `1.0.0-dev.geo.8` (`92cdeae1d1f9916bf38253772ec30240f3e4798b`) is a rejected technical-review candidate. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.8.zip` (SHA-256 `55e3c61297b4b1ab0ad06a3efa3c899c60fdf80f8d0fbd6bec8be5e5342d798f`). Do not physically QA geo.8 or reuse its package identity.
- `1.0.0-dev.geo.9` (`09df7d2337e271792f76290b062c7acba598d80a`) is a rejected technical-review candidate. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.9.zip` (SHA-256 `8ac4067ff8a9b5f1eb6bb1b8ad8224a168517160665804b0aa6b577a17029a47`, `1,661,773` bytes). Do not physically QA geo.9 or reuse its package identity.
- `1.0.0-dev.geo.10` (`66be34b684c11c165617359b828ccf852fba2635`) is a rejected technical-review candidate. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.10.zip` (SHA-256 `494546cf021909b30a8f0519c816069af72c41b064cbe28b05a31409355f7f47`). Do not physically QA geo.10 or reuse its package identity.
- `1.0.0-dev.geo.11` (`daef41a85662e1c1dc0aa6f5673ca9749f163a26`) is a rejected whole-branch closure-review candidate. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.11.zip` (SHA-256 `5ba3eb3a6b10eba60e36735085071b628d1dc030e85f4e79f67e5753c9331400`). Do not physically QA geo.11 or reuse its package identity.
- `1.0.0-dev.geo.12` (`41861d261fe9c6ca4dead778464de19e5c03ac40`) is a rejected whole-branch review candidate. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.12.zip` (SHA-256 `e66ab64a4869dd6262fe2cea16f2a7b35949e487e34fbafc5c029bd3cb1203c2`). Do not physically QA geo.12 or reuse its package identity.
- `1.0.0-dev.geo.13` (`31147df82a819416f15a2729ed87647c6ef80f9e`) is a frozen rejected technical-review candidate after independent acceptance of its worker-fencing and hierarchy-boundedness corrections. Frozen package: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.13.zip` (SHA-256 `c15c44c31ffee4c1baeb1ea6fdeac4fd1255a3ba86cca223ad793fe5c445797a`). Do not physically QA geo.13 or reuse its package identity.
- Implementation: geo.16 closed P2 Location Packs admin-form correction only. `LocationPacksPage` install, continue/retry and reconciliation POST forms now emit `AdminFormHelper::nonce_field(ACTION)` plus hidden `cetech_de_action`. Notices use the shared `flash_*` API. `AdminActionHandler` security contract is unchanged. Schema-6 coverage kickoff, Action Scheduler readiness, pack lease fencing and matching precedence are unchanged from frozen geo.15. Not packaged as RC.11; not merged; not self-accepted. Do not run physical QA until ChatGPT confirms the geo.15→geo.16 differential is confined to this UI contract correction.
- Do not package this work as `1.0.0-rc.11`.
- Keep PR #24 Draft. Do not merge. Do not self-accept.

## Ownership
Sole owner / product / architecture / QA / acceptance authority: `@wbdevworld`.

Cursor/AI is the owner's implementation, testing, migration, packaging and evidence-collection agent.

Ben (`@Ben-001-sys`) and Emmanuel (`@Emmanuel-coder-prog`) have **no** implementation, review, approval or ownership requirement for issue #23 unless the human owner later delegates something explicitly in this file.

## Central leases
- plugin bootstrap / version identity: `@wbdevworld` (issue #23 → `1.0.0-dev.geo.16`);
- schema / migrations: `@wbdevworld` (schema `6`);
- destination matching / Effective geography contracts: `@wbdevworld`;
- published release identity/tags: frozen at `v1.0.0-rc.11`;
- canonical `master`: no force-push/rewrite;
- PR #12 / `1.0.0-dev.qual.1` remains historical qualification provenance and is **not** the current implementation surface.

## Environment authorization
- Isolated local QA lab / owner-qa-geo evidence folder: permitted for this stream.
- FLAIROC: NO autonomous mutation.
- Training site: NO autonomous mutation.
- Production: NO autonomous mutation.
- POS repository: outside scope / must not be touched.
- CETECH is the first production Pilot customer, but no Pilot creation or deployment is authorized until geo.16 has confined differential review and narrow physical QA of the form correction.
- WPML/WCML: do not merge. Certification remains separate and is required before Stable 1.0 only if advertised as supported at launch.
- WP Rocket certification: do not expand; remains separate/unqualified.
- Stage 15: not started.

## Geo.13 bounded-technical-correction evidence (frozen / not physically QA'd)
- Identity: `1.0.0-dev.geo.13`; schema `6`.
- Package-source SHA: `31147df82a819416f15a2729ed87647c6ef80f9e`.
- Evidence HEAD: `8ae02b15f33a80fbf6886f821e1062ccefca7947`.
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.13.zip`.
- ZIP bytes: `1,758,541`.
- ZIP SHA-256: `c15c44c31ffee4c1baeb1ea6fdeac4fd1255a3ba86cca223ad793fe5c445797a`.
- Do not reuse or overwrite this package identity.

## Geo.14 CAS-closure evidence (frozen / physically QA'd / rejected)
- Identity: `1.0.0-dev.geo.14`; schema `6`.
- Package-source SHA: `ea53d0486593199898269479be624de262127692`.
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.14.zip`.
- ZIP bytes: `1,759,583`.
- ZIP SHA-256: `cc3f320e8f9856c08d0e5c50ee05f41363db8a8ba79135bde23befdeaa212cc7`.
- Isolated physical QA confirmed two P1 production-path defects: schema-6 coverage conversion started too early (`deferred_woo` / unsafe Action Scheduler enqueue) and geography pack same-second `renew_lease()` false `lock_lost`.
- Local evidence path (outside ZIP): `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-owner-qa-geo14\evidence\`. In-repo pointer `docs/ISSUE-23-GEO14-PHYSICAL-QA.md` may be local/uncommitted; do not manufacture history.
- Do not rebuild, overwrite or reuse this package identity.

## Geo.15 P1-closure candidate (frozen / physically QA'd / P2 form defect)
- Identity: `1.0.0-dev.geo.15`; schema `6`.
- Package-source SHA: `eb9f4e4893b32ec8e59d47fb90e13adfed85e78e`.
- Evidence HEAD: `9cd0d8f28bbba3bce4fce8ebd8859921f8fe0ef4`.
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.15.zip`.
- ZIP bytes: `1,763,366`.
- ZIP SHA-256: `95340eed81a3e7d7b5a7bddb3635a825870a6916d5716d27e6ea12f1fcd68690`.
- Isolated physical QA passed RC.11 → schema-6 HTTP conversion, worker/lease concurrency, Ghana 15,349-locality import, 20-locality parity, selected/entire/exclusion coverage, Storefront, WoodMart, Classic/Blocks and pricing. Confirmed P2: Location Packs forms omitted `cetech_de_action` / `cetech_de_nonce`, so natural admin POST returned without executing the handler.
- Geo.15 physical evidence remains authoritative for production files that are byte-identical in geo.16. Do not rebuild, overwrite or reuse this package identity.

## Geo.16 Location Packs admin-form P2 closure
- Identity: `1.0.0-dev.geo.16`; schema `6`.
- Package-source SHA: `7aeb4c573d04d12d8101c0e05bc8858ff332d63f`.
- Evidence HEAD: recorded after ZIP/CI evidence commit.
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.16.zip`.
- ZIP bytes: `1,764,171`.
- ZIP SHA-256: `500b09878ba08b879e5bdfdb0ea6e58726a71fe90a6994a83bb96b1e314a3925`.
- Production package verifier: PASS (schema target 6).
- Production files changed versus frozen geo.15: `src/Presentation/Admin/LocationPacksPage.php`; plugin header / `CETECH_DE_VERSION`. Forbidden geography/runtime production files remain byte-identical to geo.15 (`Schema6CoverageUpgradeKickoff`, `Schema6CoverageUpgradeService`, `ActionSchedulerReadiness`, `GeographyPackService`, `WpdbGeographyPackRepository`).
- Forms: install (`ACTION_INSTALL`), continue/retry (`ACTION_TICK`) and reconciliation (`ACTION_RECONCILE`) emit `cetech_de_nonce` via `AdminFormHelper::nonce_field()` and hidden `cetech_de_action`. Notices use shared `flash_*`. `AdminActionHandler::verify_post()` is unchanged.
- Security: missing/wrong action, missing/invalid nonce, and missing `manage_delivery_zones` must not mutate. No GET mutation.
- Local gates: Composer validate OK; PHP lint 2807 files / 0 failures; Docker PHP 8.1 runtime lint 484 files OK; default PHPUnit 1239 tests / 7510 assertions; Location Packs admin-form contract 13 tests; geography unit/regression 184 tests / 1507 assertions; Vitest 76 tests; team control plane OK; product control plane OK (10 files, 372 Requirement IDs). Production package verifier: PASS. Packaged PHP lint: `499 files / 0 failures`.
- PR #24 remains Draft / OPEN / unmerged.
- Do not run physical QA until ChatGPT confirms the geo.15→geo.16 differential is confined to the intended UI contract correction.

## Recorded P3 / non-blocking (not in geo.16)
- Blocks script dependency warning.
- Variable ETA copy/prefix.
- Lamp default selection UX.
- Optional Blocks totals evidence polish.
- WoodMart WP-CLI 128M lab memory limit.

## Current next work
1. ChatGPT differential review of the exact geo.15→geo.16 Location Packs form-contract correction. STOP. Do not run physical QA until that review accepts the differential as confined.
2. After acceptance, narrow physical QA of the exact geo.16 ZIP: natural Location Packs install / continue / reconcile POST, negative security, pack-lifecycle smoke and basic activation/PDP smoke. Inherit geo.15 evidence for unchanged production files.
3. Keep PR #24 Draft. Do not mark ready. Do not merge. Do not create RC.12 or a CETECH Pilot.

## Explicit non-actions
Do not move, rebuild or overwrite RC.11. Do not reuse the RC.11 identity. Do not rebuild or overwrite frozen geo.15. Do not create RC.12 merely because this implementation succeeds. Do not request Ben/Emmanuel approval. Do not alter frozen Requirement IDs or change product-control-plane registry classifications as part of this stream.
