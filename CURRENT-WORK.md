# Current Work — Issue #29 Checkout Multi-Destination Stabilization

Status: RC.12 IMMUTABLE — POST-RC.12 DEV CANDIDATE `1.0.0-dev.checkout-mdest.1` — AWAITING REVIEW / NOT RC.13 / NOT DEPLOYED

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Current protected `master` (docs after RC.12): `c4cee3c37360789d47f3238207aaa4e74ba86109`.
- Immutable RC.12 release source: `78594ad8962868683726373f58f4a8b1b48e4d0e`.
- Tag `v1.0.0-rc.12` must not be moved. Do not rebuild or overwrite the RC.12 ZIP.

## RC.12 published identity (immutable)
- Version: `1.0.0-rc.12`
- Schema: `6`
- Tag: `v1.0.0-rc.12` (unsigned annotated `tag`)
- Annotated tag object: `89f34883a017b8bb66f98db345fbbae0d8dd72b0`
- Peels to: `78594ad8962868683726373f58f4a8b1b48e4d0e`
- GitHub prerelease: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/releases/tag/v1.0.0-rc.12
- Final ZIP: `cetech-woocommerce-delivery-engine-1.0.0-rc.12.zip`
- ZIP bytes: `1,767,204`
- ZIP SHA-256: `46508c566b505ac470ae94d2829de068e4ff1b53bb4c22e31201e038fb8e03d1`

## Active task
Issue `#29` — `[P1] Checkout Use my checkout address flattens multi-destination carts`

- Owner: `@wbdevworld` (explicit owner instruction; checkout runtime + customer copy).
- Branch: `fix/checkout-multi-destination-preservation` from protected `master` `c4cee3c`.
- Development identity: `1.0.0-dev.checkout-mdest.1` (schema remains `6`).
- Not RC.13. Not a release promotion.

Owner-reproduced on `training.cetechbpa.com` (RC.12 runtime):
- Line A destination GH / Standard Delivery / GH₵30
- Line B destination Accra / Standard Delivery / GH₵50
- Cart: subtotal GH₵60 + GH₵30 + GH₵50 = **GH₵140**
- After **Use my checkout address** (checkout = Accra): both items Accra, only GH₵50 delivery, total **GH₵110**

## Central leases
- plugin bootstrap / version identity on this branch: `1.0.0-dev.checkout-mdest.1`; published `1.0.0-rc.12` remains the tagged identity;
- schema / migrations: frozen at `6`;
- published release identity/tags: `v1.0.0-rc.11` and `v1.0.0-rc.12` immutable;
- canonical `master`: no force-push/rewrite;
- product-control-plane Requirement IDs: frozen;
- PR #12 / `1.0.0-dev.qual.1` remains historical qualification provenance.

## Environment authorization
- Training site `https://training.cetechbpa.com`: RC.12 / schema 6. Authorized only for bounded reproduction/testing. Do not deploy this candidate until local tests, PR/CI, and owner/ChatGPT review.
- Ghana Location Pack is currently importing. Do not interfere. Do not run safe reconciliation until it is ready.
- CETECH Pilot: **NOT STARTED**.
- FLAIROC: **NOT DEPLOYED**.
- Production: **NOT DEPLOYED**.
- POS repository: outside scope / must not be touched.

## Explicit non-actions
Do not create RC.13. Do not deploy this fix yet. Do not move `v1.0.0-rc.12` or overwrite its ZIP. Do not start CETECH Pilot. Do not deploy FLAIROC or production. Do not touch POS. Do not start Stage 15. Do not merge WPML/WCML. Do not expand WP Rocket certification. Do not interfere with the Ghana Location Pack import.
