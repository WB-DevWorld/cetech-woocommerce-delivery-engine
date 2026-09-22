# Current Work — Issue #32 Needs Attention count contract

Status: RC.12 IMMUTABLE — POST-RC.12 DEV CANDIDATE `1.0.0-dev.attention-count.1` — AWAITING TECHNICAL REVIEW / NOT MERGED / NOT DEPLOYED / NOT RC.13

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Current protected `master`: `d66e5e366612468d7e5b4874e1a4290c5d2582cc` (PR #42 MERGED; Issue #31 CLOSED / COMPLETED; post-merge CI run `35756300042` SUCCESS).
- Immutable RC.12 release source: `78594ad8962868683726373f58f4a8b1b48e4d0e`.
- Tag `v1.0.0-rc.12` must not be moved. Do not rebuild or overwrite the RC.12 ZIP.
- Frozen `1.0.0-dev.shipment-order-read.1` ZIP must not be overwritten.
- Frozen `1.0.0-dev.pdp-precision.1` / `1.0.0-dev.pdp-precision.2` ZIPs must not be overwritten.
- Frozen `1.0.0-dev.address-ux.1` / `1.0.0-dev.address-ux.2` / `1.0.0-dev.address-ux.3` ZIPs must not be overwritten.
- Do not create RC.13.

## RC.12 published identity (immutable)
- Version: `1.0.0-rc.12`
- Schema: `6`
- Tag: `v1.0.0-rc.12` (unsigned annotated `tag`)
- Annotated tag object: `89f34883a017b8bb66f98db345fbbae0d8dd72b0`
- Peels to: `78594ad8962868683726373f58f4a8b1b48e4d0e`

## Active task
Issue `#32` — `[P2] Overview Needs Attention count omits actionable shipment/COD work`

- Owner: `@wbdevworld` (explicit owner/ChatGPT authorization; Issue #32 only).
- Branch: `fix/needs-attention-count-contract`.
- Base: protected `master` `d66e5e366612468d7e5b4874e1a4290c5d2582cc`.
- Development identity: `1.0.0-dev.attention-count.1` (schema remains `6`).
- Requires PHP: `8.3`. Composer: `>=8.3`. Activation guard: `8.3`.
- Runtime / package-source SHA: `6e9e2ea71796170afb0508954a18d206cdc7017a`.
- Pull request: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/43 (do not merge).
- Runtime CI: `35759882478` SUCCESS.
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.attention-count.1.zip` (`1,848,319` bytes, SHA-256 `2027e5941916dff0302d7e2e545de5a2fd027f0c8f1615d97e3991dcfc5fdcb3`).
- Not RC.13. Do not merge. Do not deploy.

PHP policy retained from PR #37 (`docs/PHP-RUNTIME-POLICY.md`, `docs/PHP-85-CI-REALIGNMENT.md`):

- Minimum supported PHP: **8.3**.
- Supported / certified range: PHP **8.3, 8.4, and 8.5.x**.
- CETECH production / currently qualified latest stable: PHP **8.5.x**.
- PHP 8.1 and 8.2 are not supported and must not be advertised.

## Central leases
- plugin bootstrap / version identity on this branch: `1.0.0-dev.attention-count.1`; published `1.0.0-rc.12` remains the tagged identity; frozen shipment-order-read / address-ux / pdp-precision / geo-country ZIPs must not be overwritten;
- CI workflows / required-check names / PHP runtime policy: retained from protected master;
- schema / migrations: frozen at `6`;
- published release identity/tags: `v1.0.0-rc.11` and `v1.0.0-rc.12` immutable;
- canonical `master`: no force-push/rewrite;
- product-control-plane Requirement IDs: frozen (no new IDs);
- GH Location Pack / Accra / Kumasi / Greater Accra charges / sentinel Test AREAAA: not mutated;
- Zone 2 display name remains `Ashanti Region`.

## Environment authorization
- GitHub Actions ephemeral runners and optional local `docker/php85-qa`.
- Training site `https://training.cetechbpa.com`: **READ-ONLY investigation authorized**. Installed runtime is `1.0.0-dev.shipment-order-read.1`. **NOT AUTHORIZED to deploy** `1.0.0-dev.attention-count.1`.
- CETECH Pilot: **NOT STARTED**.
- FLAIROC: **NOT DEPLOYED**.
- Production: **NOT DEPLOYED**.
- POS repository: outside scope / must not be touched.

## Explicit non-actions
Do not create RC.13. Do not merge this PR. Do not move `v1.0.0-rc.12` or overwrite its ZIP. Do not overwrite frozen shipment-order-read, address-ux, geo-country, or pdp-precision ZIPs. Do not start CETECH Pilot. Do not deploy FLAIROC, training, or production. Do not touch POS. Do not start Stage 15. Do not rename Ashanti Region. Do not mutate Accra/Kumasi coverage, Greater Accra charges, or the GH Location Pack. Do not rerun safe legacy reconciliation. Do not change shipment lifecycle, COD semantics, or the Bulk stale threshold.
