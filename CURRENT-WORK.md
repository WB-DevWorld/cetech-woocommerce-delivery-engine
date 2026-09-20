# Current Work — RC.12 Staff Training Realignment

Status: RC.12 TAGGED AND PUBLISHED — DOCS-ONLY TRAINING REALIGNMENT / NOT DEPLOYED

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- RC.12 release source / current tagged candidate: `78594ad8962868683726373f58f4a8b1b48e4d0e`.
- Later `master` may advance for documentation only. That later commit is **not** the RC.12 release source and must not receive the `v1.0.0-rc.12` tag.
- Do not rebuild the RC.12 ZIP merely because training docs are committed later.

## RC.12 published identity
- Version: `1.0.0-rc.12`
- Schema: `6`
- Tag: `v1.0.0-rc.12` (unsigned annotated `tag`)
- Annotated tag object: `89f34883a017b8bb66f98db345fbbae0d8dd72b0`
- Peels to: `78594ad8962868683726373f58f4a8b1b48e4d0e`
- GitHub prerelease: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/releases/tag/v1.0.0-rc.12
- Final ZIP: `cetech-woocommerce-delivery-engine-1.0.0-rc.12.zip`
- ZIP bytes: `1,767,204`
- ZIP SHA-256: `46508c566b505ac470ae94d2829de068e4ff1b53bb4c22e31201e038fb8e03d1`
- Protected-master CI: `35522128310` SUCCESS
- Issue `#26`: CLOSED / COMPLETED
- Training site: `https://training.cetechbpa.com` currently runs RC.12 / schema 6. Qualification: PASS.

## Immutable prior release — RC.11
- Tag: `v1.0.0-rc.11`
- Annotated tag object: `acaae9bfc9758cdee1b3f2ec47e94848e83f87da`
- Peels to: `384f564f64a2db766ae6907392e95fb366fb8533`
- Version: `1.0.0-rc.11`; schema: `5`
- ZIP SHA-256: `97423a95273f6148ee855d1fb8a66c6c66e47aa20cb2c5868ecf2f84b2a9a521`
- Not mutated.

## Active task
Docs-only staff training realignment for RC.12 Location Packs, Coverage Groups, review-required coverage, and setup order.

- Branch: `docs/rc12-training-realignment` from `78594ad`.
- Scope: `docs/training/**` plus truthful RC.12 closeout status/docs. **No runtime, schema, or package changes.**
- Historical `docs/staff-training-rc2` is not merged. Salvage is documented separately.
- After merge, `.github/workflows/sync-training-docs.yml` publishes `docs/training/**` to `wbdevworld/cetech-woocommerce-delivery-engine-training`.

## Central leases
- plugin bootstrap / version identity: published `1.0.0-rc.12`; do not change without a new authorized promotion;
- schema / migrations: frozen at `6` for this candidate;
- published release identity/tags: `v1.0.0-rc.11` and `v1.0.0-rc.12` immutable;
- canonical `master`: no force-push/rewrite;
- product-control-plane Requirement IDs: frozen;
- PR #12 / `1.0.0-dev.qual.1` remains historical qualification provenance.

## Environment authorization
- CETECH Pilot: NOT AUTHORIZED.
- FLAIROC: NO autonomous mutation.
- Training site: NO autonomous mutation (read-only inspection only).
- Production: NO autonomous mutation.
- POS repository: outside scope / must not be touched.

## Explicit non-actions
Do not deploy CETECH Pilot. Do not deploy FLAIROC/training/production. Do not touch POS. Do not start Stage 15. Do not merge WPML/WCML. Do not expand WP Rocket certification. Do not create RC.13. Do not move `v1.0.0-rc.12` or overwrite its ZIP. Do not merge `docs/staff-training-rc2`. Do not change runtime PHP, JS, migrations, or schema.
