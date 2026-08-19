# Stage 14C — Shipment Creation From Paid Order Snapshots

**Document status:** Stage 14C completion record  
**Date:** 2026-08-18  
**Plugin version:** `1.0.0-rc.4` (unchanged)  
**Schema target:** `4` (unchanged from Stage 14B)  
**Runtime shipment creation:** implemented, **feature-gated OFF** (`enable_shipment_records`)  
**FLAIROC:** NOT MODIFIED  

This document records paid-order shipment planning and idempotent aggregate creation. It does **not** add a Shipments workspace, tracking UI, customer shipment cards, emails, or FLAIROC changes.

---

## 1. Verdict

**COMPLETE for the authorised Stage 14C scope.**

Paid WooCommerce orders can be planned from historical Delivery Engine snapshots and persisted as shipment aggregates when `enable_shipment_records` is enabled. The flag remains **OFF** by default and unavailable in Settings.

RC.4 checkout, selector, resolver, grouping, shipping, snapshots, Access/Administrator recovery, and customer presentation were not modified.

---

## 2. Historical snapshot contract consumed

Stage 14C reads the **implemented RC.4 contract**, not a redesigned snapshot. The snapshot format was not changed.

| Location | Key | Contents used |
|----------|-----|----------------|
| Line item meta | `_cetech_de_delivery_snapshot` / `_cetech_de_delivery_snapshot_version` | `delivery_group_id`, `fulfilment_availability`, `fulfilment_choice`, `delivery_offer_id`, `delivery_offer_public_label`, `estimate_text`, `destination_zone_id`, `quantity`, `currency_code`, `quoted_amount` (per-line; **not summed** for the group charge), `rate_card_id`, `rate_card_code`, `product_id`, `variation_id` |
| Order meta | `_cetech_de_delivery_quote_snapshot` / `_cetech_de_order_delivery_snapshot_version` | package `groups[]` (`group_id`, `package_total_delivery_amount`, `fulfilment_choice`, `is_pickup`, `display_index`, shipping method id/label), `currency_code`, `destination_zone_id` |
| Shipping line meta | `cetech_de_group_id` | matching WooCommerce shipping line total for the historical group |

Reader: `OrderDeliverySnapshotReader` (WooCommerce CRUD only).

Compared with Stage 14A: implementation matches the documented group identity and public option/ETA/amount fields. Per-line `quoted_amount` remains a line fact and is **not** used as the shipment charge when a group/shipping-line amount exists.

---

## 3. Fields deliberately not reconstructed

RC.4 did not snapshot these. Stage 14C stores them as null / not-recorded and does **not** look them up from current configuration:

- supplier
- origin
- Logistics Profile
- route (separate field)
- service level (separate field)
- public carrier
- split processing/transit/final-mile ETA
- configuration fingerprint
- internal cost

Absence of those fields is not treated as snapshot corruption.

---

## 4. Planner architecture

```
Historical order (WooCommerce CRUD)
→ HistoricalOrderShipmentContextFactory
→ HistoricalOrderShipmentContext
→ HistoricalShipmentPlanner
→ ShipmentPlan[]
→ ShipmentService
→ ShipmentRepositoryInterface::ensureCompleteAggregate()
```

The planner does not persist. It has no EffectiveConfigurationResolver, Rate Card, product-exception, Site-wide Defaults, or Stage 8 grouping-engine dependencies.

One valid **delivery** group → one `ShipmentPlan`. Output is deterministic for the same historical context.

---

## 5. Grouping contract

Authoritative historical grouping remains:

`{fulfilment_availability}|{fulfilment_choice}|{offer_id|pickup}`

Stage 14C does not re-run the Stage 8 grouping engine. It consumes saved `delivery_group_id` values. No supplier/origin/Logistics Profile splitting is introduced.

---

## 6. Store Pickup

Pickup groups (`…|store_pickup|pickup`) are skipped.

Pickup-only Delivery Engine paid orders: **success with zero delivery shipments**. Not an error. Not Needs Attention.

---

## 7. Paid shipping amount

Uses historical group `package_total_delivery_amount` and/or the matching WooCommerce shipping line (`cetech_de_group_id`, method `delivery_engine_selected_offer`).

If both exist and disagree after 4-decimal normalisation: **fail closed** (`shipping_amount_mismatch`).

Does not requote, recalculate Rate Cards, sum per-line quotes, reconvert currency, or change WooCommerce totals.

---

## 8. ETA and public Delivery Option

- Original ETA = historical `estimate_text`. Current ETA starts equal to original.
- Public label = snapshotted `delivery_offer_public_label`.
- Stable offer id stored separately when present.
- Combined historical ETA is not split into invented components.

---

## 9. Identity / idempotency

Database unique `(order_id, delivery_group_id)` from Stage 14B remains authoritative.

Application path: `ensureCompleteAggregate()`. Repeated payment, status fallback, plugin reload, webhook, or admin retry must not create a second shipment, duplicate items, or a second initial event.

---

## 10. Aggregate atomicity / recovery

Strategy:

1. `START TRANSACTION`
2. Insert shipment row if missing (duplicate key → load existing)
3. Insert **missing** items only (no delete/replace of valid items)
4. Append initial `created` event only if none exists (`to_status` = `awaiting_fulfilment`)
5. Verify completeness (all expected items + created event)
6. `COMMIT`, or `ROLLBACK` on any failure

A retry that finds a shipment row **without** complete items/events repairs it (`completed_existing_incomplete`). Incomplete aggregates are never treated as success.

Fake `$wpdb` unit tests cover item-insert failure, event-insert failure, and repair. Real MySQL/MariaDB `dbDelta` / InnoDB transaction behaviour is **not** proven in this stage.

---

## 11. Initial status / event

Machine status: `awaiting_fulfilment` (never the translated label).

Exactly one initial event: `created`, source `system` or `retry`, `to_status` `awaiting_fulfilment`. UTC via `gmdate( 'Y-m-d H:i:s' )`. Event payloads do not include customer-private or supplier data.

---

## 12. Shipment items

Items link to the WooCommerce order item IDs represented by the historical group. Loaded through WooCommerce CRUD (`get_items()`), not legacy post tables.

Mismatch / missing order item → creation failure (`missing_order_item` / `group_item_mismatch`).

---

## 13. Creation triggers

Primary: `woocommerce_payment_complete`. That WooCommerce event is itself payment confirmation.

Fallback: `woocommerce_order_status_processing` and `woocommerce_order_status_completed` **only** when persisted payment-confirmation evidence exists (`WC_Order::get_date_paid()` is a real paid timestamp).

`WC_Order::is_paid()` is **not** sufficient. WooCommerce treats `processing` / `completed` as paid statuses, so Cash on delivery can be `is_paid() === true` with `date_paid` still NULL. That must not create a shipment.

Not hooked: order created, cart, checkout validation, shipping calculation, thank-you.

---

## 14. Eligibility

Before planning:

- valid WooCommerce order
- `enable_shipment_records` enabled
- payment is confirmed: either the `woocommerce_payment_complete` event, or a persisted paid date on status fallback / retry
- status is not cancelled / refunded / failed / trash / checkout-draft
- Delivery Engine historical snapshot exists (line and/or package)

Non-Delivery-Engine orders: no-op, not an error.  
Unpaid / COD processing with no paid date: no-op.  
Feature off: no-op.

Do not treat pre-payment COD fulfilment as in-scope unless a later explicit policy decision says so.

---

## 15. Feature flag

`enable_shipment_records` remains **OFF** by default and Settings-unavailable.

Schema 4 existing does **not** enable shipment records.

`enable_tracking_links` and `enable_customer_timeline` stay OFF.

---

## 16. Failure state

Order meta (CRUD):

| Key | Purpose |
|-----|---------|
| `_cetech_de_shipment_creation_state` | `failed` / `succeeded` |
| `_cetech_de_shipment_creation_error_code` | machine code |
| `_cetech_de_shipment_creation_attempted_at` | ISO-8601 UTC attempt time |

Compact option index (no full-order scan):

`cetech_de_shipment_creation_failure_order_ids`

Removed on uninstall only when delete-data is explicitly enabled. Not dropped on deactivation.

Payment, totals, selected Delivery Option, and historical snapshots are never changed on failure.

---

## 17. Error codes

`missing_snapshot`, `malformed_group_snapshot`, `missing_order_item`, `group_item_mismatch`, `shipping_amount_mismatch`, `repository_write_failed`, `aggregate_incomplete`.

Human copy is translated at presentation time (`ShipmentCreationErrorMessages`).

---

## 18. Needs Attention

Separate section **Paid order shipment problems**, not mixed into product-readiness rows.

Surfaces: order number/link, safe sentence, attempted time, **Retry shipment creation** when the user has `manage_shipments`.

Not flagged: feature disabled, non-DE order, unpaid, pickup-only zero shipments, complete existing aggregate.

No raw SQL, stack traces, supplier ids, or secrets.

---

## 19. Retry / recovery

Needs Attention POST `cetech_de_retry_shipment_creation`.

Requires capability `manage_shipments`, nonce `cetech_de_nonce`, valid paid order, existing historical snapshot.

Uses the **same** `ShipmentService::create_for_paid_order( …, ShipmentEventSource::Retry )`. Successful retry clears the current failure marker; prior audit rows remain.

---

## 20. Capabilities / Administrator recovery

Reuses reserved `manage_shipments`. Access matrix UX is unchanged (Stage 14F). Administrator recovery remains independent `manage_options`.

Needs Attention page access remains `manage_product_delivery_rules`. The retry button is hidden without `manage_shipments`.

---

## 21. Outcome model

`ShipmentCreationOutcome`: `created`, `already_exists_complete`, `completed_existing_incomplete`, `zero_shipments_pickup_only`, `not_delivery_engine_order`, `feature_disabled`, `not_paid`, `ineligible`, `invalid_snapshot`, `creation_failed`.

---

## 22. Tests

Focused PHPUnit: planner, service eligibility/idempotency, aggregate atomicity/repair, failure index + Needs Attention copy, unauthorized/invalid-nonce retry, RC.4 freeze flags still off.

Full suite is the regression gate. JS was not changed.

---

## 23. Limitations

- Feature remains OFF; no ordinary paid order creates rows until deliberately enabled.
- No historical backfill of existing paid orders.
- No staff Shipments list/detail workspace (14D).
- No customer tracking/timeline (14E).
- Refund/cancellation shipment lifecycle not implemented; ineligible statuses simply do not newly create.
- WooCommerce Fulfillments adapter still unused.
- **REAL DB MIGRATION TEST NOT AVAILABLE** in this environment (Fake `$wpdb` only).

---

## 24. Recommended next phase

**Stage 14D** — staff Shipments list + detail workspace. Do not start unless explicitly instructed.
