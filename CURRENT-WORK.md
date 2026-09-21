# Current Work — Issue #35 GeoNames country identity

Status: RC.12 IMMUTABLE — POST-RC.12 DEV CANDIDATE `1.0.0-dev.geo-country.1` — AWAITING TECHNICAL REVIEW / NOT RC.13 / NOT DEPLOYED / NOT MERGED

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Current protected `master`: `5abfab0b5078e67b158f282088022b2ac2566f22`.
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

- Owner: `@wbdevworld` (explicit ChatGPT authorization; Issue #35 only).
- Branch: `fix/geonames-country-identity` from protected `master` `5abfab0b5078e67b158f282088022b2ac2566f22`.
- Development identity: `1.0.0-dev.geo-country.1` (schema remains `6`).
- Runtime / package-source SHA: `b61466ffc14c71e1a678eaf2d5b84f22bf6040cb`.
- PR: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/36
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.1.zip` (`1,794,741` bytes, SHA-256 `216e3a28d37e6af5f6cf97a69ef362dc946a94b1d26d8508c3d747d582198a6c`).
- Not RC.13. Not a release promotion. Do not merge or deploy until owner/ChatGPT technical review.

## Central leases
- plugin bootstrap / version identity on this branch: `1.0.0-dev.geo-country.1`; published `1.0.0-rc.12` remains the tagged identity;
- schema / migrations: frozen at `6`;
- published release identity/tags: `v1.0.0-rc.11` and `v1.0.0-rc.12` immutable;
- canonical `master`: no force-push/rewrite;
- product-control-plane Requirement IDs: frozen.

## Environment authorization
- Training site `https://training.cetechbpa.com`: read-only forensics for Issue #35. Plugin currently `1.0.0-dev.geo-live.2`. Do not deploy geo-country.1 until owner/ChatGPT review. Do not confirm migrated Accra/Kumasi coverage. Do not click I reviewed this migrated coverage / Confirm replacement. Do not rerun safe reconciliation. Do not reset the GH pack.
- CETECH Pilot: **NOT STARTED**.
- FLAIROC: **NOT DEPLOYED**.
- Production: **NOT DEPLOYED**.
- POS repository: outside scope / must not be touched.

## Explicit non-actions
Do not create RC.13. Do not deploy this fix yet. Do not merge until technical review. Do not move `v1.0.0-rc.12` or overwrite its ZIP. Do not start CETECH Pilot. Do not deploy FLAIROC or production. Do not touch POS. Do not start Stage 15. Do not implement Issue #31 or Issue #32. Do not confirm Accra/Kumasi coverage. Do not fix the admin-notice overwrite (out of #35).
