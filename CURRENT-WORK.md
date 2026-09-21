# Current Work — Issue #38 PDP location precision / exact delivery quote

Status: RC.12 IMMUTABLE — POST-RC.12 DEV CANDIDATE `1.0.0-dev.pdp-precision.1` — AWAITING TECHNICAL REVIEW / NOT MERGED / NOT DEPLOYED / NOT RC.13

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Current protected `master`: `514ddfc1b4d10b2171d3d3bae020381198c59d2b` (merged/green post-Issue-#35 baseline; PR #36 MERGED; Issue #35 CLOSED).
- Immutable RC.12 release source: `78594ad8962868683726373f58f4a8b1b48e4d0e`.
- Tag `v1.0.0-rc.12` must not be moved. Do not rebuild or overwrite the RC.12 ZIP.
- Do not create RC.13.

## RC.12 published identity (immutable)
- Version: `1.0.0-rc.12`
- Schema: `6`
- Tag: `v1.0.0-rc.12` (unsigned annotated `tag`)
- Annotated tag object: `89f34883a017b8bb66f98db345fbbae0d8dd72b0`
- Peels to: `78594ad8962868683726373f58f4a8b1b48e4d0e`

## Active task
Issue `#38` — `[P1] PDP saved-region hydration can hide City/Town and show broader-area delivery fee`

- Owner: `@wbdevworld` (explicit owner/ChatGPT authorization; Issue #38 only).
- Branch: `fix/pdp-location-precision`.
- Base: protected `master` `514ddfc1b4d10b2171d3d3bae020381198c59d2b`.
- Runtime / package-source SHA: `dd078712bd97024d49ccd208a0b9d7e82d25f857`.
- PR: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/40 (open; do not merge).
- Development identity: `1.0.0-dev.pdp-precision.1` (schema remains `6`).
- Requires PHP: `8.3`. Composer: `>=8.3`. Activation guard: `8.3`.
- Not RC.13. Do not reuse geo-country identities.
- Do not merge. Do not deploy. Do not close Issue #38.

PHP policy retained from PR #37 (`docs/PHP-RUNTIME-POLICY.md`, `docs/PHP-85-CI-REALIGNMENT.md`):

- Minimum supported PHP: **8.3**.
- Supported / certified range: PHP **8.3, 8.4, and 8.5.x**.
- CETECH production / currently qualified latest stable: PHP **8.5.x**.
- PHP 8.1 and 8.2 are not supported and must not be advertised.

## Central leases
- plugin bootstrap / version identity on this branch: `1.0.0-dev.pdp-precision.1`; published `1.0.0-rc.12` remains the tagged identity;
- CI workflows / required-check names / PHP runtime policy: retained from protected master;
- schema / migrations: frozen at `6`;
- published release identity/tags: `v1.0.0-rc.11` and `v1.0.0-rc.12` immutable;
- canonical `master`: no force-push/rewrite;
- product-control-plane Requirement IDs: frozen (no new IDs);
- GH Location Pack / Accra / Kumasi / Greater Accra charges / sentinel Test AREAAA: not mutated;
- Zone 2 display name remains `Ashanti Region`.

## Environment authorization
- GitHub Actions ephemeral runners and optional local `docker/php85-qa`.
- Training site `https://training.cetechbpa.com`: **NOT AUTHORIZED** for this task. Do not deploy.
- CETECH Pilot: **NOT STARTED**.
- FLAIROC: **NOT DEPLOYED**.
- Production: **NOT DEPLOYED**.
- POS repository: outside scope / must not be touched.

## Explicit non-actions
Do not create RC.13. Do not merge this PR. Do not close Issue #38. Do not implement #39/#31/#32. Do not move `v1.0.0-rc.12` or overwrite its ZIP. Do not overwrite frozen geo-country ZIPs. Do not start CETECH Pilot. Do not deploy FLAIROC, training, or production. Do not touch POS. Do not start Stage 15. Do not rename Ashanti Region. Do not mutate Accra/Kumasi coverage, Greater Accra charges, or the GH Location Pack. Do not rerun safe legacy reconciliation.
