# Current Work — Issue #35 GeoNames country identity (realigned onto PHP 8.3–8.5 master)

Status: RC.12 IMMUTABLE — POST-RC.12 DEV CANDIDATE `1.0.0-dev.geo-country.5` — AWAITING FINAL DEPLOYMENT REVIEW / NOT RC.13 / NOT DEPLOYED / NOT MERGED

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Current protected `master`: `d5ca30d1d92bb69b8a8ffbca2606d30906b8d4a1` (PR #37 PHP 8.3–8.5.x support-range correction).
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
Issue `#35` — `[P1] GeoNames PCL* records can overwrite canonical country identity`

Realign PR `#36` onto protected `master` `d5ca30d1d92bb69b8a8ffbca2606d30906b8d4a1` so Issue #35 keeps its accepted runtime and PR #37 PHP policy remains canonical.

- Owner: `@wbdevworld` (explicit ChatGPT authorization; Issue #35 only).
- Branch: `fix/geonames-country-identity`.
- Development identity: `1.0.0-dev.geo-country.5` (schema remains `6`).
- Requires PHP: `8.3`. Composer: `>=8.3`. Activation guard: `8.3`.
- Runtime / package-source SHA: pending committed runtime SHA after master integration.
- Frozen geo-country.4 ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.4.zip` (`1,798,250` bytes, SHA-256 `2fc4996c0e403c41bce200dec5be680a05f56f9e666b5a3e4a13a400dba24354`). Runtime SHA `ce1bcff053385a042993e50c3b216d8649d7b43d`. Do not overwrite.
- Frozen geo-country.3 ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.3.zip` (`1,797,361` bytes, SHA-256 `a86a940d8ebac2c9e293dad8690ed8f121b5afa66cb3f1e0537f3f4152b26c66`). Runtime SHA `bae75f79d0094c78706912c9a530f09ea21b9bed`. Do not overwrite.
- Frozen geo-country.2 ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.2.zip` (`1,798,020` bytes, SHA-256 `6e7f76e2d218a01e2af401cc646471dc2ce27afc8a52c6ad15e671df0b88122c`). Runtime SHA `23237eedece174b2f9c4334693b137310ce5c54a`. Do not overwrite.
- Frozen geo-country.1 ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.1.zip` (`1,794,741` bytes, SHA-256 `216e3a28d37e6af5f6cf97a69ef362dc946a94b1d26d8508c3d747d582198a6c`). Do not overwrite.
- PR: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/36
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.5.zip` (build after runtime CI SUCCESS; do not overwrite frozen .1–.4).
- Not RC.13. Not a release promotion. Do not merge or deploy until owner/ChatGPT final deployment review.

PHP policy retained from PR #37 (`docs/PHP-RUNTIME-POLICY.md`, `docs/PHP-85-CI-REALIGNMENT.md`):

- Minimum supported PHP: **8.3**.
- Supported / certified range: PHP **8.3, 8.4, and 8.5.x**.
- CETECH production / currently qualified latest stable: PHP **8.5.x**.
- PHP 8.1 and 8.2 are not supported and must not be advertised.

## Central leases
- plugin bootstrap / version identity on this branch: `1.0.0-dev.geo-country.5`; published `1.0.0-rc.12` remains the tagged identity;
- CI workflows / required-check names / PHP runtime policy: retained from protected master (PR #37);
- schema / migrations: frozen at `6`;
- published release identity/tags: `v1.0.0-rc.11` and `v1.0.0-rc.12` immutable;
- canonical `master`: no force-push/rewrite;
- product-control-plane Requirement IDs: frozen (no new IDs).

## Environment authorization
- GitHub Actions ephemeral runners and optional local `docker/php85-qa`.
- Training site `https://training.cetechbpa.com`: read-only forensics for Issue #35. Plugin currently `1.0.0-dev.geo-live.2`. Training PHP remains 8.4.24. Do not deploy geo-country.1–.5 until owner/ChatGPT review. Do not confirm migrated Accra/Kumasi coverage. Do not click I reviewed this migrated coverage / Confirm replacement. Do not rerun safe reconciliation. Do not reset the GH pack.
- CETECH Pilot: **NOT STARTED**.
- FLAIROC: **NOT DEPLOYED**.
- Production: **NOT DEPLOYED**.
- POS repository: outside scope / must not be touched.

## Explicit non-actions
Do not create RC.13. Do not deploy this fix yet. Do not merge until final deployment review. Do not move `v1.0.0-rc.12` or overwrite its ZIP. Do not overwrite the frozen geo-country.1, geo-country.2, geo-country.3, or geo-country.4 ZIPs. Do not start CETECH Pilot. Do not deploy FLAIROC or production. Do not touch POS. Do not start Stage 15. Do not implement Issue #31 or Issue #32. Do not confirm Accra/Kumasi coverage. Do not fix the admin-notice overwrite (out of #35). Do not advertise PHP 8.1 or 8.2 as supported.
