# POST-RC.9 Cart-State Reconciliation

**Document status:** Implementation record for development candidate `1.0.0-dev.cartstate.1`  
**Date:** 2026-09-01  
**Branch:** `feat/post-rc9-cart-state`  
**Protected baseline:** tagged `1.0.0-rc.9` / schema `5` / `v1.0.0-rc.9` — **immutable; do not retag**  
**Development identity:** `1.0.0-dev.cartstate.1`  
**Schema target:** `5` (unchanged; schema 6 was not required)  
**FLAIROC:** not modified  
**Package:** see `docs/POST-RC9-CART-STATE-QA.md`. Not RC.9. Not RC.10. Not deployed.

```text
READ → AUDIT → PLAN → IMPLEMENT → TEST → DOCUMENT → REVIEW → STOP
```

## Root cause

RC.9 `CartDeliverySelectionRevalidator` compared stored cart intent with live product configuration and detected stale/unavailable/invalid state, but **did not mutate the cart**. It only warned:

> A delivery option in your cart is no longer available. Please remove and re-add the product.

`CartDeliverySelectionCapture` stores `cetech_de_delivery_selection`, `cetech_de_delivery_selection_summary`, and `cetech_de_delivery_selection_hash` in WooCommerce cart item data. WooCommerce `generate_cart_id()` hashes **all** cart item data, including admin-derived fingerprints, labels, ETA, and hashes.

Therefore, after an administrator changed Delivery Engine configuration while a product remained in cart:

1. the original line kept the old intent/summary/hash;
2. a newly added copy received the new intent/summary/hash;
3. WooCommerce treated them as different cart IDs;
4. shipping grouping followed the stale vs live offer identities, producing ghost duplicate groups.

Cart was treated as a historical snapshot. The product rule is the opposite: **cart is live customer state; orders are historical immutable snapshots.**

## Precise reconciliation policy

| State | Owner | Behaviour |
|-------|--------|-----------|
| Per-item location / address (when present) | Customer | Preserved. Never overwritten because config changed, the product was added again, or browsing location changed. Different locations never merge. |
| Deliberate fulfilment choice while still valid | Customer | Preserved. Delivery ↔ Store Pickup is never switched silently. |
| Delivery Option identity (`delivery_offer_id` / pickup) | Customer semantic choice | Kept when still valid or when a deterministic equivalent exists (same choice + same offer ID, or exact display key, or the single remaining pickup option). |
| Effective config fingerprint, labels, ETA, pickup public copy, rule id, hashes, grouping inputs derived from current config | Admin / live config | Refreshed in place when the semantic choice remains valid. |
| Choice no longer valid | — | Do **not** retain stale public delivery details. Mark the line `cetech_de_needs_reselection`. Fail closed for shipping/checkout until the customer reselects. Do not require deleting the product. |
| Another remaining Delivery Option | — | Not substituted merely to obtain a price. |
| WooCommerce variation identity | WooCommerce | Unchanged. Different variations never merge. |
| Paid order / shipment snapshots | Historical | Immutable. Cart reconciliation never writes order meta. |

### Cart-line identity

`woocommerce_cart_id` now uses `CartLineCustomerIdentity`:

- product ID + variation ID + variation attributes (WooCommerce);
- customer-owned DE context: fulfilment choice + offer/pickup + per-item location token;
- other plugins’ non-`cetech_de_*` cart item data.

Admin fingerprints, summaries, hashes, `issued_at`, `rule_id`, and `configuration_fingerprint` are excluded from the cart ID. After reconciliation, identical current customer contexts consolidate quantities. Needs-reselection lines get a distinct shipping group suffix (`|reselect`) so they cannot share a quoted package with a valid same-offer line.

### When it runs

- `woocommerce_cart_loaded_from_session`
- `woocommerce_before_calculate_totals`
- Classic Cart / Checkout notices
- Classic Cart inline reselection form
- Blocks Store API cart-item payload + `extensionCartUpdate` callback

Classic Checkout and Blocks share `CartDeliverySelectionReconciler`, `CheckoutDeliverySelectionValidator`, and `CartDeliveryReselectionService`. Reselection reuses `ProductDeliveryOptionsBuilder` / `ProductDeliverySelectionValidator`.

## Schema

No schema change. Target remains `5`. No new tables or options.

## Intentionally excluded

- Per-item location architecture (fixture support only: location tokens are preserved and never merged)
- Stage 15
- FLAIROC / training deploy
- Retagging or rebuilding RC.9
- Silent Delivery ↔ Pickup switches
- Rewriting historical orders
