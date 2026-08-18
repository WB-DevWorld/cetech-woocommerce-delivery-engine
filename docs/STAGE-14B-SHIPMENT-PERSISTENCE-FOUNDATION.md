# Stage 14B — Shipment Persistence Foundation

**Document status:** Stage 14B completion record  
**Date:** 2026-08-18  
**Plugin version:** `1.0.0-rc.4` (unchanged)  
**Schema target:** `4` (`SchemaVersion::TARGET`; not deployed to FLAIROC)  
**Runtime shipment creation:** NOT implemented  
**FLAIROC:** NOT MODIFIED  

This document records the persistence foundation only. It does **not** create shipments on orders, add a Shipments screen, add tracking UI, add customer shipment cards, or change FLAIROC.

---

## 1. Verdict

**COMPLETE for the authorised Stage 14B scope.**

Schema/domain/persistence are implemented. Runtime shipment creation is **not** implemented.

RC.4 checkout, selector, resolver, grouping, shipping, snapshots, Access/Administrator recovery, and customer presentation were not modified.

---

## 2. Baseline before this stage

| Item | Value |
|------|--------|
| Branch | `master` |
| Docs commits pushed first | `8150f6f` Stage 14A architecture; `a3158e2` governing rules |
| Plugin version | `1.0.0-rc.4` |
| Schema target before 14B | `3` |
| RC.4 tag | `v1.0.0-rc.4` peeled `6b70c29` — untouched |
| Governing rules | Present in checkout (`docs/DELIVERY-ENGINE-GOVERNING-RULES.md`) |

There is no documented development-version policy requiring a plugin-version bump for an internal schema/domain stage. Plugin version remains `1.0.0-rc.4`.

---

## 3. What was implemented

### Schema 4

Idempotent migration `database/migrations/20260818140000_create_shipment_tables.php` (`get_version() = 4`) creates:

| Suffix | Table | Purpose |
|--------|-------|---------|
| `shipments` | `{prefix}delivery_engine_shipments` | One operational shipment per historical order delivery group |
| `shipment_items` | `{prefix}delivery_engine_shipment_items` | Order-line membership snapshotted onto the shipment |
| `shipment_events` | `{prefix}delivery_engine_shipment_events` | Append-only history |

Design is historical order truth, not live product settings. Columns include stable identity/idempotency, order linkage, `delivery_group_id`, status machine code, public Delivery Option snapshot, original/current ETA, customer-paid amount, tracking fields, separated public/private notes, timestamps, and the private operational fields Stage 14 actually needs (`supplier_id`, `origin_id`, `logistics_profile_id`, `internal_cost`, `rate_card_*`). `wc_fulfillment_id` is reserved NULL for a future adapter.

### Indexes

- UNIQUE `order_group (order_id, delivery_group_id)`
- UNIQUE `idempotency_key`
- KEY `order_id`
- KEY `status`
- KEY `status_updated (status, updated_at)`
- UNIQUE `shipment_item (shipment_id, order_item_id)`
- KEY `shipment_items.order_item_id`
- KEY `shipment_time (shipment_id, event_at)`

No foreign keys to WooCommerce tables.

### Domain and repository

- `Shipment`, `ShipmentItem`, `ShipmentEvent`, `ShipmentIdentity`, `ShipmentListResult`
- Machine-code enums: `ShipmentStatus`, `ShipmentEventType`, `ShipmentEventSource`
- `ShipmentRepositoryInterface` → `WpdbShipmentRepository` (canonical V1)
- `WooCommerceFulfillmentsAdapter` reserved, unimplemented, **not bound**, no dual-write

Repository operations: create (idempotent), find by ID, find by order/group, find by idempotency key, list/paginate, update, replace items, find items, append events, find events.

Idempotency invariant `order_id + delivery_group_id` is enforced in `create()` and by the unique indexes.

Status persistence stores machine codes only (`awaiting_fulfilment`, `processing`, `dispatched`, `in_transit`, `delayed`, `delivered`, `cancelled`). Translated labels are presentation-only.

### Lifecycle

- Deactivation does **not** drop shipment tables or data.
- Uninstall drops shipment tables only when `cetech_de_delete_data_on_uninstall` is explicitly enabled (existing policy). `ConfigurationTables::all_suffixes()` and the `uninstall.php` fallback list include the three suffixes.

### Runtime freeze (intentional)

`enable_shipment_records`, `enable_tracking_links`, and `enable_customer_timeline` remain default **OFF** and Settings-unavailable. No payment/order hooks create shipments. No staff or customer shipment UI was added.

---

## 4. Scope intentionally excluded

- Automatic shipment creation (Stage 14C)
- Shipment planner / snapshot consumption workflows
- Staff Shipments workspace
- Tracking entry UI and customer Track controls
- Customer shipment cards / timeline
- Native WooCommerce Fulfillments dual-write
- Plugin version bump, packaging, tagging, FLAIROC deploy
- Selector, ECR, grouping, shipping, checkout, snapshot, Access/recovery, or customer-presentation changes

---

## 5. Schema / migration impact

| Item | Value |
|------|--------|
| Previous target | `3` |
| New target | `4` |
| Migration | `20260818140000_create_shipment_tables` |
| Option | `cetech_de_db_version` |
| Fresh install path | 1 → 2 → 3 → 4. Schema v3 still forbids shipment tables during its own verify; v4 creates them afterwards. |
| RC.4 tagged package | Remains schema `3`. Master code target is `4` and is **not** deployed. |
| Order snapshots | Untouched. Migration does not read or write `_cetech_de_delivery_snapshot` / quote snapshot meta. |

---

## 6. Runtime behaviour

None added for customers or staff. Binding `ShipmentRepositoryInterface` is infrastructure only. Plugin boot still runs pending migrations, so activating/updating this tree on a WordPress site will create the three tables when schema 3 is already present. That is table creation only — no shipment rows are written.

---

## 7. Security / privacy

Private columns (`supplier_id`, `origin_id`, `logistics_profile_id`, `internal_cost`, `private_note`, `rate_card_*`) exist in persistence and are not exposed by any new customer renderer. Public and private notes are separate columns. No browser-submitted delivery prices. `$wpdb` + prefix + prepared SQL. No FKs onto WooCommerce tables.

---

## 8. Shipping-integrity review

No shipping method, package builder, grouping, rate, or checkout validator changes. Missing rates still cannot become free shipping via this stage because this stage does not calculate rates.

---

## 9. Compatibility

HPOS-compatible: shipment rows store `order_id` without joining posts tables. WooCommerce Fulfillments adapter is unused. WoodMart is unused. Core does not depend on optional integrations.

---

## 10. Performance

Three new tables, queried only if later stages call the repository. Unique `(order_id, delivery_group_id)` keeps creation retries O(1). List queries use `status + updated_at`. No storefront queries were added.

---

## 11. Tests

Focused PHPUnit coverage:

- migration 3 → 4 identity and idempotent `dbDelta` (no `DROP TABLE`)
- table/index markers
- repository CRUD, items, events
- unique idempotency at application and database levels
- stable machine status codes; translated labels rejected as state
- pagination / status / order filters
- schema upgrade does not touch RC.4 snapshot meta keys
- flags remain off; no creation hooks; Fulfillments adapter unwired

Proportional gates: Composer validate, PHP lint of changed PHP, full PHPUnit suite. No JS changes, so no JS tests.

---

## 12. Documentation updated

- This file
- `docs/AI-HANDOFF.md` current-status block
- `docs/DELIVERY-ENGINE-GOVERNING-RULES.md` baseline schema line (master target 4; plugin version still RC.4)
- `docs/PROJECT-GOVERNANCE.md` current baseline bullets

---

## 13. Staging checks required

Not for FLAIROC in this stage. When a later authorised deploy includes schema 4:

1. Confirm `cetech_de_db_version` becomes `4`.
2. Confirm the three tables exist with the unique `order_group` index.
3. Confirm no shipment rows appear until Stage 14C is implemented and flags are deliberately enabled.
4. Confirm RC.4 checkout still quotes, groups, and snapshots as before.

---

## 14. Known limitations

- Tables can exist while no shipment is ever created.
- Repository does not implement business workflows (planner, payment hook, status machine transitions, refunds).
- `WooCommerceFulfillmentsAdapter` throws if called.
- Unit tests use a Fake `$wpdb`; they do not execute `dbDelta` against MySQL.

---

## 15. Recommended next phase

**Stage 14C** — planner + idempotent creation from historical order snapshots. Do not start 14C unless explicitly instructed.
