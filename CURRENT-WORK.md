# Current Work — PHP 8.5 CI / runtime realignment

Status: RC.12 IMMUTABLE — WS3 CI/RUNTIME REALIGNMENT — NOT RC.13 / NOT A RELEASE / NOT DEPLOYED

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Current protected `master` (this task base): `5abfab0b5078e67b158f282088022b2ac2566f22`.
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
PHP 8.5 CI / runtime realignment (owner instruction 21 September 2026).

- Owner: WS3 / `@wbdevworld`.
- Branch: `ws3/php-85-runtime-realignment` from protected `master` `5abfab0b5078e67b158f282088022b2ac2566f22`.
- Plugin version identity on this branch: unchanged from protected master (`1.0.0-dev.geo-live.2`). Schema remains `6`.
- Policy: `docs/PHP-RUNTIME-POLICY.md`. Evidence: `docs/PHP-85-CI-REALIGNMENT.md`.
- CETECH production PHP target: **8.5.x** (latest stable patch at deploy; 8.5.10 as of 21 September 2026).
- Commercial minimum PHP: **8.1** (retained).
- PHP 8.6 must not be used as a production target.
- Not RC.13. Not a release promotion. Do not deploy.

## Separate open work (do not edit from this branch)
- Issue `#35` / PR `#36` (`fix/geonames-country-identity`) remains a separate candidate. This task must not take that lease or mix country-identity runtime changes.

## Central leases
- CI workflows / release-package PHP image;
- PHP runtime policy and isolated PHP 8.5 QA Compose;
- compatibility-matrix documentation that currently treated 8.1/8.2 as the target environment.
- plugin bootstrap / version identity: **not taken** (remains master `1.0.0-dev.geo-live.2`);
- schema / migrations: frozen at `6`;
- published release identity/tags: `v1.0.0-rc.11` and `v1.0.0-rc.12` immutable;
- canonical `master`: no force-push/rewrite;
- product-control-plane Requirement IDs: frozen (no new IDs).

## Environment authorization
- GitHub Actions ephemeral runners and optional local `docker/php85-qa` only.
- Training site `https://training.cetechbpa.com`: **do not mutate**.
- CETECH Pilot: **NOT STARTED**.
- FLAIROC: **NOT DEPLOYED**.
- Production: **NOT DEPLOYED**.
- POS repository: outside scope / must not be touched.

## Explicit non-actions
Do not create RC.13. Do not change `Requires PHP` / Composer floor from 8.1 unless a later commercial-support decision says so. Do not change business logic for this task. Do not move `v1.0.0-rc.12` or overwrite its ZIP. Do not start CETECH Pilot. Do not deploy FLAIROC or production. Do not touch POS. Do not start Stage 15. Do not implement Issue #31, #32, or #35 from this branch.
