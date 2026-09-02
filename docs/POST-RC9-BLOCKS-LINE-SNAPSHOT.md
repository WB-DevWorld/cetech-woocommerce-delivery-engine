# POST-RC.9 Blocks line snapshot

**Document status:** Implementation record. Local QA only. Not deployed.  
**Date:** 2026-09-02  
**Branch:** `feat/post-rc9-blocks-line-snapshot`  
**Starting source:** tagged `1.0.0-rc.9` packaged commit `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`  
**Development identity:** `1.0.0-dev.blocks-snapshot.1`  
**Schema target:** `5` (unchanged; no migration)  
**Protected tag:** `v1.0.0-rc.9` still peels to `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`  
**FLAIROC:** not modified  
**Training:** not modified  
**cartstate.1:** not modified  
**Not Stage 15.** Not WCFM/WPML/per-item.

**Source SHA:** `54c9894f492906a24d30c939f831f4538d6b0255`  
**ZIP:** `cetech-woocommerce-delivery-engine-1.0.0-dev.blocks-snapshot.1.zip`  
**ZIP SHA-256:** `092f3144e2a4b4df474c91513b6a282af08ccc817a5fa007f1f94d5d5229c401`  
**PHPUnit:** 855 tests, 4891 assertions, OK (5 pre-existing deprecations on PHP 8.5)  
**Local browser:** native Blocks Delivery order **#25** and Pickup order **#26** on the disposable QA lab (`http://localhost:8088`). Not deployed.

```text
READ → AUDIT → PLAN → IMPLEMENT → TEST → DOCUMENT → REVIEW → STOP
```

---

## Classification

**B — RC.9 baseline Blocks defect.**

Not expected architecture. Not a cartstate.1 regression. Not an evidence/query mistake.

Local RC.9-only native Blocks Delivery order **#25** (`created_via=store-api`) had:

- `_cetech_de_delivery_quote_snapshot` present (group `in_warehouse|delivery|1`)
- product line `_cetech_de_delivery_snapshot` absent (no DE line meta at all)
- shipping `cetech_de_group_id=in_warehouse|delivery|1`

The same pattern was observed on cartstate.1 Blocks order #26. RC.9 already registers the Store API hooks, so cartstate.1 was not reinstalled for comparison.

Package snapshot alone is **not** equivalent. `HistoricalShipmentPlanner::plan_delivery_group()` skips lines without line snapshots and fails `missing_order_item` when the Delivery group has no snapshot items. Staff preview is `invalid_snapshot`.

---

## Root cause

Classic `WC_Checkout::create_order` copies the posted address onto the order **before** `create_order_line_items` → `woocommerce_checkout_create_order_line_item`. `OrderDeliverySnapshotBuilder::build_line_snapshot()` can resolve the destination zone and persist `_cetech_de_delivery_snapshot`.

WooCommerce 11.0.1 Store API `OrderController::update_order_from_cart()`:

1. `update_line_items_from_cart()` → `wc()->checkout->create_order_line_items()` → the **same** Classic line-item hook fires
2. **then** `update_addresses_from_cart()` copies `WC()->customer` onto the order

At line-item hook time the Blocks Delivery order has no shipping address, so the builder returns `null` for Delivery (offer id > 0 and not Store Pickup). Pickup skips that destination check, which is why Classic pickup #21 already had line snapshots.

Package snapshot is written later on `woocommerce_store_api_checkout_update_order_from_request` / `woocommerce_store_api_checkout_order_processed` because the shipping method is `delivery_engine_selected_offer`.

### Hook answers

1. Classic line snapshot: `woocommerce_checkout_create_order_line_item` → `OrderDeliverySnapshotPersister::handle_create_order_line_item`.
2. That hook **does** execute for Store API Blocks orders in WC 11.0.1, but **too early** (no order address yet).
3. Package quote snapshot: `woocommerce_checkout_order_created` / `woocommerce_store_api_checkout_order_processed` / `woocommerce_store_api_checkout_update_order_from_request` → `handle_order_created`.
4. `WC()->cart` is still available at Store API processed / update-from-request (emptied after payment).
5. Cart item keys are not stored on WooCommerce order items. This stream records a **transient** `_cetech_de_cart_item_key` only when the early hook cannot build a snapshot, then matches that key (falling back to product_id + variation_id + quantity with claimed uniqueness) and deletes the transient key after the immutable line snapshot is written.
6. Yes: RC.9 wrote the package snapshot even when line persistence failed.

---

## Repair

Do **not** patch the RC.9 tag. Classic path is unchanged when the address is already on the order.

After Store API copies the customer address, `handle_order_created` (Store API `update_order_from_request` priority 20, plus `checkout_order_processed`) backfills any missing managed line snapshots from `WC()->cart` using the same `OrderDeliverySnapshotBuilder` contract. Writes are idempotent: existing `_cetech_de_delivery_snapshot` is never replaced. Old orders without a live cart are not rewritten. Unmanaged lines still receive no snapshot.

Schema remains 5. No migration. Pickup remains zero / no Delivery shipment.

---

## Files changed

### Identity (schema still 5)

- `cetech-woocommerce-delivery-engine.php` — `1.0.0-dev.blocks-snapshot.1`
- `readme.txt`

### Runtime

- `src/Application/Order/OrderDeliverySnapshotPersister.php` — Store API backfill + transient cart-item mapping
- `src/Application/Order/OrderDeliverySnapshot.php` — `META_CART_ITEM_KEY`
- `src/Application/Order/OrderDeliverySnapshotBuilder.php` — not `final` so unit tests can stub quote-engine-free builders
- `src/Presentation/Admin/OrderDeliverySnapshotAdminDisplay.php` — hide the transient mapping key

### Tests / stubs

- `tests/Unit/Order/OrderDeliverySnapshotPersisterStoreApiTest.php`
- `tests/Unit/Shipment/HistoricalShipmentPlannerTest.php`
- `tests/Unit/Shipment/SchemaV4InspectionTest.php`
- `tests/Unit/Presentation/DeliveryPresentationCleanupTest.php`
- `tests/stubs/woocommerce-order-stub.php`
- `tests/bootstrap.php` — `WC()` test double

---

## Scope intentionally excluded

- Patching or retagging RC.9
- cartstate.1, WCFM, WPML, per-item locations
- Schema 6 / Stage 15
- FLAIROC / training
- Rewriting historical orders
- Changing Classic snapshot JSON
- Automatic paid-order shipment creation on COD fixtures

---

## Security / shipping integrity

- Server remains authoritative. Browser-submitted prices are not trusted.
- Historical line snapshots remain immutable after first write.
- Transient `_cetech_de_cart_item_key` is hidden and removed after snapshot persistence.
- Missing configuration still does not become free shipping.
- Pickup still creates no Delivery shipment.

---

## Compatibility / performance

- WooCommerce CRUD / HPOS unchanged.
- Extra work is one cart walk at Store API checkout after address sync.
- Classic checkout still writes on the original line-item hook; backfill is a no-op there.

---

## Known limitations

- Historical RC.9 Blocks Delivery orders created before this package still lack line snapshots and cannot be shipment-planned without a new checkout on this identity.
- Automatic shipment creation was not exercised on COD (no `date_paid`); staff preview / historical planner is the supported COD path.
