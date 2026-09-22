# POST-RC.12 — Shipment workspace Woo order-read recovery (Issue #31)

**Current candidate identity:** `1.0.0-dev.shipment-order-read.1`  
**Schema:** `6` (unchanged; no schema 7)  
**Branch:** `fix/shipment-workspace-order-read`  
**Base:** protected `master` `baa273c2af00672d22268eb6968012ac7a51cfdd`  
**Requires PHP:** `8.3`  
**Not RC.13. Not deployed. Do not merge until owner/ChatGPT technical review.**

Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/31

Training remains `1.0.0-dev.address-ux.3`. Do not install shipment-order-read.1 until owner/ChatGPT technical review. Frozen address-ux / pdp-precision / geo-country ZIPs must not be overwritten.

## Defect

Staff Shipment Workspace can show **Customer unavailable** for a shipment whose WooCommerce order still exists.

Confirmed source: `ShipmentWorkspaceQuery::load_orders()` ran one `wc_get_orders( include, limit, type=shop_order, return=objects )` bulk lookup, mapped returned `WC_Order` objects by `get_id()`, and returned immediately. If a requested ID was absent from that bulk result, the list/detail caller received `null` even when `wc_get_order( $id )` could load the same order.

## Training investigation (read-only, PII-free)

Inspected on `https://training.cetechbpa.com` before changing production logic. Customer name/address/email/phone are not recorded.

| Fact | Value |
|------|-------|
| Installed plugin | `1.0.0-dev.address-ux.3` |
| Schema | `6` |
| PHP | `8.4.24` |
| WooCommerce | `11.1.0` |
| HPOS (`woocommerce_custom_orders_table_enabled`) | yes |
| HPOS sync | no |

Historical physical case: Woo order `49392`, shipment reference `49392-D1`.

| Probe | Result |
|-------|--------|
| `wc_get_order(49392)` | exists=`true`; class `Automattic\WooCommerce\Admin\Overrides\Order`; id `49392`; status `completed`; type `shop_order` |
| `wc_get_orders( include=[49392], limit=1, type=shop_order, return=objects )` | array; count `1`; returned IDs `[49698]` (not `49392`) |
| Shipment `49392-D1` | exists; shipment id `26`; `order_id` `49392`; status `awaiting_fulfilment` |

**ORIGINAL BULK-MISS REPRODUCED.** Direct load succeeds; the bulk `include` result does not contain order `49392`. No cause is claimed for why WooCommerce returned a different ID. HPOS is an environment fact, not proven as the bulk-omission root cause.

The workspace defect still holds regardless of that Woo bulk behavior: partial/wrong bulk results were not recovered through `wc_get_order`.

## Implementation contract

Keep the bulk query as the fast path. `load_orders()`:

1. Normalize and dedupe positive requested IDs.
2. If none: return `[]`.
3. If `wc_get_orders` exists: exactly one bulk query (`include`, `limit=count(ids)`, `type=shop_order`, `return=objects`).
4. Map valid `WC_Order` objects by `$order->get_id()`.
5. Compute requested IDs minus successfully loaded IDs.
6. Only for missing requested IDs, if `wc_get_order` exists: call `wc_get_order( $missing_id )`.
7. If the direct result is a `WC_Order`, add it.
8. If false/null/wrong type, leave it missing.
9. Return the map keyed by order ID.

Non-array bulk results are treated as no bulk hits; every requested ID may then use the per-ID Woo CRUD fallback. No new abstraction.

## Performance

- Normal case (all requested IDs present in the bulk map): **one** `wc_get_orders` call, **zero** `wc_get_order` calls.
- Fallback cost is proportional only to requested IDs missing from the bulk result.
- Valid bulk objects are never replaced.
- Duplicate requested IDs fallback at most once (`load_orders()` dedupes independently of `order_ids()`).

## Failure behavior

If bulk and direct both fail: no fatal; customer label remains **Customer unavailable**; order display falls back to the numeric shipment `order_id`; no fabricated customer/order data.

## What was not changed

- `customer_label()` precedence (formatted billing full name → first + last → company → Guest; Customer unavailable only when there is no `WC_Order`).
- `order_number()` / `order_edit_url()` capability checks.
- Historical shipment rows remain truth. Workspace still does not consult `EffectiveConfigurationResolver`, Product Delivery Rules, Rate Cards, or current supplier/origin names.
- No customer-facing PDP/cart/checkout/My Account/email/tracking changes.
- No shipment/order schema, mutation, tracking, ETA, or snapshot writes.
- No direct SQL on `wp_wc_orders`, `wp_wc_order_addresses`, `wp_posts`, or `wp_postmeta`. WooCommerce CRUD remains the authority.

## Package (shipment-order-read.1, after CI)

Recorded after GitHub CI SUCCESS on the runtime/package-source SHA. Do not overwrite frozen address-ux / pdp-precision / geo-country ZIPs. Do not treat this ZIP as RC.13 or as a replacement for RC.12. Training remains `1.0.0-dev.address-ux.3`. Do not deploy shipment-order-read.1.

- Source SHA: pending CI-green runtime commit
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.shipment-order-read.1.zip`
- Bytes: pending package
- SHA-256: pending package

## Explicitly not done

- Issue #32
- Ashanti Region rename
- Notice-overwrite P3
- Modify address-ux.3 runtime on training
- PDP/cart/checkout changes
- Shipment or order schema
- Direct HPOS table queries
- Geography / coverage / rates / Location Packs
- Reconciliation
- Schema 7 / RC.13 / RC.12 mutation
- Pilot / FLAIROC / production / POS
