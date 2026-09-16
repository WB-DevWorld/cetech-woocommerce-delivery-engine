# Current Work — Issue #18 PDP delivery price display

Status: OWNER-CONTROLLED POST-RC.10 IMPLEMENTATION (NOT ACCEPTED)

## Canonical published truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical published branch: `master`.
- Protected runtime baseline: tagged `v1.0.0-rc.10` → `d1409258caf1a90675b689ab105471460de4c713`. Immutable. Do not move the tag or overwrite the RC.10 ZIP.
- Active development identity: `1.0.0-dev.pdp-price.2` / schema `5`.
- Stage 15: NOT STARTED.

## Active task
GitHub issue **#18** — `[P2] Show authoritative delivery price on product-page delivery options`.

- Sole owner: `@wbdevworld`
- Implementation branch: `fix/pdp-delivery-price-display`
- Cursor/AI is the owner's implementation, debugging, testing and evidence-collection agent only.
- `@Ben-001-sys` and `@Emmanuel-coder-prog` have no assigned role on #18.

## Authorized implementation
Bounded PDP customer-facing delivery prices using the same server quote path as cart/checkout. No FLAIROC/training/production mutation. No RC.10 retag. No Stage 15. No WPML overlay merge. A future RC is created only after the owner explicitly authorizes promotion.

## Central leases
- version identity: `1.0.0-dev.pdp-price.2` on `fix/pdp-delivery-price-display`;
- schema: frozen at 5;
- tagged `v1.0.0-rc.10`: frozen;
- canonical `master`: no force-push/rewrite.

## Environment authorization
- Production/FLAIROC/training: NO autonomous mutation.
- Isolated local QA lab may be used for bounded PDP/cart price comparison. Physical qualification remains owner-decided.
