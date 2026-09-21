# Current Work — Issue #39 compact cart/checkout delivery-address UX

Status: RC.12 IMMUTABLE — POST-RC.12 DEV CANDIDATE `1.0.0-dev.address-ux.2` — AWAITING FINAL DEPLOYMENT REVIEW / NOT MERGED / NOT DEPLOYED / NOT RC.13

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Current protected `master`: `ed3753d73e262d8c7467fa936e3e36060960e58c` (PR #40 MERGED; Issue #38 CLOSED / COMPLETED; protected-master CI SUCCESS).
- Immutable RC.12 release source: `78594ad8962868683726373f58f4a8b1b48e4d0e`.
- Tag `v1.0.0-rc.12` must not be moved. Do not rebuild or overwrite the RC.12 ZIP.
- Frozen `1.0.0-dev.pdp-precision.1` / `1.0.0-dev.pdp-precision.2` ZIPs must not be overwritten.
- Do not create RC.13.

## RC.12 published identity (immutable)
- Version: `1.0.0-rc.12`
- Schema: `6`
- Tag: `v1.0.0-rc.12` (unsigned annotated `tag`)
- Annotated tag object: `89f34883a017b8bb66f98db345fbbae0d8dd72b0`
- Peels to: `78594ad8962868683726373f58f4a8b1b48e4d0e`

## Active task
Issue `#39` — `[P2] Cart/checkout delivery-address actions and per-item editor need clearer hierarchy and compact UX`

- Owner: `@wbdevworld` (explicit owner/ChatGPT authorization; Issue #39 only).
- Branch: `fix/cart-checkout-address-ux`.
- Base: protected `master` `ed3753d73e262d8c7467fa936e3e36060960e58c`.
- Runtime / package-source SHA: `b2acea7ba75f31cd6a7851fcbf594bf798bfd79d`.
- PR: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/41 (open; do not merge).
- Development identity: `1.0.0-dev.address-ux.2` (schema remains `6`).
- Requires PHP: `8.3`. Composer: `>=8.3`. Activation guard: `8.3`.
- Frozen `1.0.0-dev.address-ux.1` ZIP must not be overwritten (`1,832,223` bytes, SHA-256 `2a28798140fafaa7d7b4e20bc4e3878fd1949f7014af854b002960a3ae1120fe`).
- Not RC.13. Do not reuse pdp-precision or geo-country identities as the current plugin version.
- Do not merge. Do not deploy.

PHP policy retained from PR #37 (`docs/PHP-RUNTIME-POLICY.md`, `docs/PHP-85-CI-REALIGNMENT.md`):

- Minimum supported PHP: **8.3**.
- Supported / certified range: PHP **8.3, 8.4, and 8.5.x**.
- CETECH production / currently qualified latest stable: PHP **8.5.x**.
- PHP 8.1 and 8.2 are not supported and must not be advertised.

## Central leases
- plugin bootstrap / version identity on this branch: `1.0.0-dev.address-ux.2`; published `1.0.0-rc.12` remains the tagged identity; frozen address-ux.1 / pdp-precision / geo-country ZIPs must not be overwritten;
- CI workflows / required-check names / PHP runtime policy: retained from protected master;
- schema / migrations: frozen at `6`;
- published release identity/tags: `v1.0.0-rc.11` and `v1.0.0-rc.12` immutable;
- canonical `master`: no force-push/rewrite;
- product-control-plane Requirement IDs: frozen (no new IDs);
- GH Location Pack / Accra / Kumasi / Greater Accra charges / sentinel Test AREAAA: not mutated;
- Zone 2 display name remains `Ashanti Region`.

## Environment authorization
- GitHub Actions ephemeral runners and optional local `docker/php85-qa`.
- Training site `https://training.cetechbpa.com`: **NOT AUTHORIZED**. Leave installed `1.0.0-dev.pdp-precision.2`. Do not deploy `1.0.0-dev.address-ux.1` or `1.0.0-dev.address-ux.2`.
- CETECH Pilot: **NOT STARTED**.
- FLAIROC: **NOT DEPLOYED**.
- Production: **NOT DEPLOYED**.
- POS repository: outside scope / must not be touched.

## Explicit non-actions
Do not create RC.13. Do not merge this PR. Do not implement #31/#32. Do not move `v1.0.0-rc.12` or overwrite its ZIP. Do not overwrite frozen geo-country or pdp-precision ZIPs. Do not start CETECH Pilot. Do not deploy FLAIROC, training, or production. Do not touch POS. Do not start Stage 15. Do not rename Ashanti Region. Do not mutate Accra/Kumasi coverage, Greater Accra charges, or the GH Location Pack. Do not rerun safe legacy reconciliation.
