# Stage 14D — Staff Shipments List + Detail Workspace

**Document status:** Stage 14D completion record  
**Date:** 2026-08-18  
**Plugin version:** `1.0.0-rc.4` (unchanged)  
**Schema target:** `4` (unchanged)  
**Feature flag:** `enable_shipment_records` remains **OFF** by default  
**FLAIROC:** NOT MODIFIED  

This document records the WordPress-native staff Shipments list and read-only detail workspace. It does **not** add tracking editing, customer tracking, status workflow, shipment emails, or FLAIROC changes.

---

## 1. Verdict

**COMPLETE for the authorised Stage 14D scope.**

Authorized staff can inspect persisted shipment records when `enable_shipment_records` is enabled. The workspace is read/inspect only. Status, tracking, ETA, and customer presentation remain uneditable here.

---

## 2. Menu placement

When the flag is ON and the user has `manage_shipments`, normal Delivery Engine navigation is:

Overview → Site-wide Defaults → Delivery Options → Delivery Areas → Delivery Charges → Pickup Locations → Product Exceptions → **Shipments** → Needs Attention → Settings

Setup Guide remains conditional. Legacy Delivery Rules and Technical Diagnostics are **not** restored to the normal menu.

When the flag is OFF, the normal Shipments submenu is not registered. Menu hiding is not security: `ShipmentsPage::render()` still requires `manage_shipments` and denies access when the flag is off.

---

## 3. Access requirements

| Path | Requirement |
|------|-------------|
| Shipments submenu | `enable_shipment_records` ON **and** `manage_shipments` |
| Direct list/detail URL | same; otherwise WordPress `wp_die` permission denial |
| WooCommerce order edit link | `edit_shop_orders` or `edit_shop_order` |
| Private note / internal cost | `view_private_delivery_costs` (note also accepts private-origin/source caps) |
| Stored supplier/origin IDs | `view_private_origins` or `manage_private_sources` |

The Access matrix and AdministratorAccessRecovery were **not** redesigned.

---

## 4. List columns

Shipment · Order · Customer · Delivery Option · Items · Status · Estimated delivery · Tracking · Updated

Staff-facing shipment identity is the stored `shipment_number` (for example `1001-D1`). If empty, presentation falls back to `SHP-000123`. That display value is **not** the idempotency key.

Items column is a localized count (`1 item` / `%d items`), not product names.

---

## 5. Search / filters / pagination

**Search (server-side prefix, not unindexed fuzzy):**

- `shipment_number`
- `tracking_number`
- numeric term also matches `id` or `order_id`

Private notes are not searched.

**Filters:** query parameter `status` uses machine codes (`awaiting_fulfilment`, `processing`, `dispatched`, `in_transit`, `delayed`, `delivered`, `cancelled`). The delayed option label is “Delayed / issue”. Translated labels are never used in query logic.

No Delivery Option filter (no efficient indexed lookup of historical labels).

**Pagination:** repository `list()` uses SQL `LIMIT`/`OFFSET` (default 20, max 100). The admin page does not load all shipments into PHP.

---

## 6. Query strategy

List page (bounded):

1. `ShipmentRepositoryInterface::list()` — `COUNT(*)` + page `SELECT` with `LIMIT`
2. `countItemsByShipmentIds()` — one `GROUP BY` query
3. `wc_get_orders( [ 'include' => order_ids ] )` — one batched WooCommerce lookup

Not loaded on the list: shipment events, per-row `findItems()`, current product/config/resolver.

Detail page: one shipment aggregate (`findById` + `findItems` + `findEvents`) plus that order.

Schema 4 indexes were **not** changed. Prefix `LIKE 'term%'` uses existing columns.

---

## 7. Shipment detail sections

- **Shipment summary** — reference, status (read-only), WooCommerce order, customer, created, updated
- **Delivery** — historical public Delivery Option, original/current ETA when they differ otherwise a single Estimated delivery, customer delivery charge, currency, public note; private note only with private caps
- **Items** — snapshot product name, variation id when present, SKU from the WooCommerce order item when available, quantity
- **Tracking** — Not added, or existing carrier / number / URL / dispatch date (read-only)
- **Operations** — omitted unless a stored private id/cost exists **and** the user may see it. Stored IDs are not resolved to today’s supplier/origin names
- **History** — events newest-first; `created` presents as Awaiting fulfilment; unknown event codes present as “Shipment update”

---

## 8. Historical-data rules

Delivery Option, ETA, and customer-paid amount come from the shipment row, which Stage 14C copied from historical order snapshots.

Do **not** look up today’s Delivery Option name, recalculate ETA, or reconvert currency.

RC.4 did not snapshot supplier, origin, Logistics Profile, route, service level, public carrier, split ETA legs, configuration fingerprint, or internal cost. Stage 14D does not reconstruct them from current configuration. If absent, those rows are omitted.

---

## 9. Privacy / language / accessibility

- No public REST or AJAX endpoint
- No shipment data in customer product/cart/checkout/thank-you/My Account/email output
- Strings are gettext-ready on `cetech-woocommerce-delivery-engine`
- Status badges include a text label; colour is not the only signal
- Search and status controls have visible or screen-reader labels
- Table wrap remains horizontally scrollable on narrow admin screens (`cetech-de-admin-table-wrap`)

---

## 10. Needs Attention relationship

Needs Attention remains the problem queue (including Stage 14C creation failures). When the Shipments flag is ON, a “View shipments” link searches the list by order id (`?s={order_id}`). That does not N+1 shipment lookups. Shipments is the operational record workspace, not a duplicate problem queue.

---

## 11. Tests

Focused coverage includes feature flag menu visibility, unauthorized direct URL denial, menu position, paginated SQL, status machine-code filters, prefix search, empty state, historical public label, detail order/item/ETA/amount/history, unknown event fallback, private-note gating, no current-resolver lookup, and no customer-facing workspace leakage.

---

## 12. Remaining limitations

- Tracking is display-only; Stage 14E owns editing and customer presentation
- No status mutation, dispatch/delivered/delayed/cancel, or ETA-edit workflow
- Real MySQL/MariaDB schema-4 migration remains unverified locally; FLAIROC was not used to prove it
- Unknown persisted event machine codes still fail repository hydrate; the presentation renderer degrades for unknown codes if they reach it
- Feature remains OFF by default and unavailable in Settings

---

## 13. Intentionally not implemented

Manual status changes, tracking edit/save UI, dispatch/delivered/delayed/cancel actions, ETA-edit workflow, customer shipment cards, customer tracking, shipment emails, carrier APIs, WooCommerce Fulfillments dual-write, historical backfill, Checkout Blocks, bulk import, POD/GPS/OTP/QR, Stage 14E+, FLAIROC, package/release, version bump, new tag.
