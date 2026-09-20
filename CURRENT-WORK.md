# Current Work — Issue #33 Ghana Location Pack liveness

Status: RC.12 IMMUTABLE — POST-RC.12 DEV CANDIDATE `1.0.0-dev.geo-live.2` — AWAITING DIFFERENTIAL REVIEW / NOT RC.13 / NOT DEPLOYED / NOT MERGED

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Current protected `master`: `83effbf081e54b47ef088673c8ac18217ab9dfda`.
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
Issue `#33` — `[P1] Ghana Location Pack can remain importing with no continuation scheduled`

- Owner: `@wbdevworld` (explicit ChatGPT authorization; Issue #33 watchdog correction only).
- Branch: `fix/geography-pack-liveness` from protected `master` `83effbf081e54b47ef088673c8ac18217ab9dfda`.
- Development identity: `1.0.0-dev.geo-live.2` (schema remains `6`).
- Frozen historical geo-live.1 ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-live.1.zip` (`1,783,293` bytes, SHA-256 `6281f1b3f7d080ac9bb528fc1a117be909ac1ae05c052d6fe704cc053192ccbf`) — do not overwrite.
- PR: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/34
- Not RC.13. Not a release promotion. Do not merge or deploy until owner/ChatGPT differential review.

## Central leases
- plugin bootstrap / version identity on this branch: `1.0.0-dev.geo-live.2`; published `1.0.0-rc.12` remains the tagged identity;
- schema / migrations: frozen at `6`;
- published release identity/tags: `v1.0.0-rc.11` and `v1.0.0-rc.12` immutable;
- canonical `master`: no force-push/rewrite;
- product-control-plane Requirement IDs: frozen.

## Environment authorization
- Training site `https://training.cetechbpa.com`: read-only forensics for Issue #33. Plugin currently `1.0.0-dev.checkout-mdest.1`. Do not click Continue / retry. Do not run safe reconciliation. Do not edit geography DB rows. Do not deploy geo-live.2 until owner/ChatGPT review.
- Ghana Location Pack remains the live importing dataset. Cursor/source/checksum/generation must not be reset.
- CETECH Pilot: **NOT STARTED**.
- FLAIROC: **NOT DEPLOYED**.
- Production: **NOT DEPLOYED**.
- POS repository: outside scope / must not be touched.

## Explicit non-actions
Do not create RC.13. Do not deploy this fix yet. Do not merge PR #34 yet. Do not move `v1.0.0-rc.12` or overwrite its ZIP. Do not overwrite the geo-live.1 ZIP. Do not start CETECH Pilot. Do not deploy FLAIROC or production. Do not touch POS. Do not start Stage 15. Do not implement Issue #31 or Issue #32. Do not click Continue / retry on training. Do not run safe reconciliation. Do not reset the current GH dataset or cursor.
