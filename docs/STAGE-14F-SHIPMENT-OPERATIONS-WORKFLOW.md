# Stage 14F — Shipment Operations Workflow

**Document status:** Stage 14F completion record  
**Date:** 2026-08-18  
**Plugin version:** `1.0.0-rc.4` (unchanged)  
**Schema target:** `4` (unchanged)  
**Feature flags:** `enable_shipment_records` default **OFF**; `enable_tracking_links` default **OFF**; `enable_customer_timeline` unused / reserved **OFF**  
**FLAIROC:** NOT MODIFIED  

This document records staff shipment status workflow, current-ETA updates, conservative WooCommerce cancellation/refund behaviour, operational Needs Attention, Access matrix shipment capabilities, and audit/event hardening. It does **not** add carrier APIs, customer emails, customer timeline, guest tracking, shipping labels, WooCommerce Fulfillments dual-write, automatic order completion, packaging, tags, or FLAIROC changes.

---

## 1. Verdict

**COMPLETE for the authorised Stage 14F scope.**

One authoritative `ShipmentStatusService` owns status validation, persistence, events, actor/source, and reason rules. Current ETA updates go through `ShipmentEtaService` and never overwrite `eta_original`. WooCommerce order cancellation and refunds use the same status service with conservative, idempotent behaviour. Settings → Access now exposes `manage_shipments` and `update_shipment_status`. Administrator recovery remains `manage_options`.

---

## 2. Status transition matrix

Machine codes are unchanged:

`awaiting_fulfilment` · `processing` · `dispatched` · `in_transit` · `delayed` · `delivered` · `cancelled`

### Normal workflow

| From | Allowed ordinary targets |
|------|--------------------------|
| `awaiting_fulfilment` | `processing`, `cancelled` |
| `processing` | `dispatched`, `delayed`, `cancelled` |
| `dispatched` | `in_transit`, `delayed` |
| `in_transit` | `delivered`, `delayed` |
| `delayed` | `processing`, `dispatched`, `in_transit`, `delivered`, `cancelled` |
| `delivered` | none |
| `cancelled` | none |

Ordinary skip-ahead (for example awaiting → dispatched) is rejected.

A reason is required for:

- entering `delayed`
- entering `cancelled`
- leaving `delayed` (recovery)
- every corrective change
- automatic WooCommerce cancel (machine reason code)

Forward moves such as Processing / Dispatched / In transit / Delivered do not require a reason.

---

## 3. Delayed / issue recovery

`delayed` is not terminal. Recovery uses the normal matrix above. History remains append-only:

previous state → delayed → resumed state

Needs Attention lists delayed shipments from the indexed status query. The active delayed issue disappears when the shipment leaves `delayed`. Event rows are never deleted.

---

## 4. Corrective status changes

Separate from ordinary actions. Staff see **Correct status**, not an ordinary workflow dropdown.

Requirements:

- capability `update_shipment_status`
- nonce
- recognised target status different from current
- mandatory internal reason

The event is `status_changed` with `public_note = correction` (machine marker, not a customer string). `internal_note` stores the reason. Customers never see the reason, actor, or event history.

---

## 5. ETA original / current

Persisted type remains `varchar(255)` human-readable estimate text.

| Field | Rule |
|-------|------|
| `eta_original` | Immutable checkout snapshot. Not editable in UI or service. |
| `eta_current` | Starts equal to original. Staff may replace the text. Reason required when the value actually changes. |

Identical saves create no event. Current ETA is **not** recomputed from Rate Cards, Product Exceptions, or EffectiveConfigurationResolver.

Internal ETA/status reasons are **not** the public shipment note. Staff still use the existing public note field to tell the customer something.

Customer View Order continues to use **Estimated delivery to your address**. When current differs from original, the card shows the current text with restrained “updated estimate” wording.

---

## 6. Dispatch date / timezone

Stage 14E stored `dispatch_at` as UTC datetime from a date-only staff input. Stage 14F keeps that contract and repairs conversion so a staff calendar date round-trips in non-UTC site timezones.

**Storage:** site-timezone midnight of the entered `Y-m-d`, converted to UTC `Y-m-d H:i:s`.

**Display / staff date field:** convert that UTC datetime back through the site timezone and show the same calendar date.

**Status vs date:** Mark as Dispatched does **not** invent `dispatch_at`. A shipment may be `dispatched` with no dispatch date. Saving a dispatch date still does not change status.

Ghana/UTC passing is not the proof; tests cover a negative offset (`America/New_York`), a positive named zone (`Pacific/Auckland`), and a numeric `gmt_offset` of `+5.5`.

---

## 7. WooCommerce cancellation

Hook: `woocommerce_order_status_cancelled`.

| Shipment status | Behaviour |
|-----------------|-----------|
| `awaiting_fulfilment`, `processing` | Automatic `cancelled` via `ShipmentStatusService`, source `woocommerce`, reason `order_cancelled` |
| `dispatched`, `in_transit`, `delayed`, `delivered` | Status unchanged. Needs Attention `order_cancelled_after_progress` |
| already `cancelled` | No-op. No duplicate event |

Shipment workflow never refunds money or edits order totals.

---

## 8. Refund policy

Hook: `woocommerce_order_refunded`.

Refunded quantity uses WooCommerce `WC_Order::get_qty_refunded_for_item()` (absolute value) compared with each shipment item quantity. This is not inferred from refund amount or order status alone.

| Case | Behaviour |
|------|-----------|
| Full refund of every quantity on **one** not-yet-dispatched shipment | Automatic `cancelled`, reason `shipment_quantities_refunded` |
| Partial refund of a shipment | Keep status. Needs Attention `refund_requires_review` |
| Amount-only refund (no refund line items / refunded qty 0) | Keep status. Needs Attention `refund_requires_review` |
| Any refund after `dispatched` / `in_transit` / `delayed` / `delivered` | Keep status. Needs Attention `refund_requires_review` |
| Sibling shipment whose quantities were not refunded | Unchanged |
| Repeated refund/cancel hooks | Idempotent |

Original paid shipping snapshot, order total, tax, and shipping total are never rewritten by this plugin.

---

## 9. Needs Attention

Operational shipment issues (bounded):

- delayed shipments (status-indexed list, limit 50)
- compact option index `cetech_de_shipment_ops_issues` for cancel-after-progress, refund review, and failed automatic sync

Not flagged:

- ordinary awaiting/processing
- missing tracking before dispatch
- pickup-only zero-shipment orders

Each operational row links to shipment detail. Delayed rows clear when status leaves `delayed`. Cancel/refund review rows clear when the shipment is cancelled or later marked delivered (Stage 14G lifecycle repair). A refund recorded after the shipment is already delivered still needs review until a later acknowledge action exists.

Needs Attention remains visible to `manage_product_delivery_rules`, and also to `manage_shipments` when shipment records are enabled.

---

## 10. Access matrix / capabilities

Existing WordPress capabilities, now shown in Settings → Access:

| Permission key | Capability | Typical use |
|----------------|------------|-------------|
| `shipments` | `manage_shipments` | Menu, list, detail, tracking |
| `shipment_status` | `update_shipment_status` | Status, correction, current ETA |

Tracking remains under `manage_shipments`. A user who can inspect shipments cannot change status unless `update_shipment_status` is granted. Direct URLs and POST actions enforce capabilities; menu hiding is not security.

Administrator is not an editable Access row. Recovery remains independent `manage_options` and still restores the full protected set, including shipment capabilities.

---

## 11. Event / audit model

Recognized event machine codes:

`created` · `status_changed` · `tracking_added` · `tracking_updated` · `note_added` · `eta_updated`

Source machine codes:

`system` · `staff` · `retry` · `woocommerce`

Unknown persisted **event type** codes still hydrate (Stage 14D-R1). Unknown **current shipment status** is still rejected. Unknown event **source** codes remain invalid.

Status/ETA reasons are internal. Correction uses the `correction` public_note marker for staff presentation only.

---

## 12. Permissions / security

Every manual status or ETA mutation requires capability + nonce + valid shipment + domain validation. Target status is never trusted from the request without `ShipmentStatus::tryFromMachineCode()`. PRG is unchanged. Feature flags remain OFF by default and are still unavailable to ordinary Settings users.

---

## 13. Language

New staff/customer strings are gettext-ready on text domain `cetech-woocommerce-delivery-engine`. Status, event, source, and error **codes** stay untranslated. Staff-authored reasons are not machine-translated. WPML/WCML remain optional and untested in this stage.

---

## 14. Tests

Focused Stage 14F coverage includes the status matrix, delayed recovery, corrections, ETA original/current rules, dispatch-date timezone round-trip, WooCommerce cancel/refund matrix, sibling isolation, hook idempotency, Access grant/revoke, Administrator recovery, customer card status/ETA without private reasons, and unknown-event resilience after status changes.

Schema 3→4 remains unverified against real MySQL/MariaDB.

---

## 15. Limitations

- No carrier APIs, polling, webhooks, or tracking sync
- No shipment customer emails
- No customer shipment timeline (`enable_customer_timeline` reserved)
- No guest tracking portal
- No shipping-label purchasing
- No WooCommerce Fulfillments dual-write
- No automatic WooCommerce order completion when a shipment is delivered
- No package, version bump, tag, or FLAIROC deploy
- Cancel/refund review issues clear when the shipment is cancelled or later marked delivered (Stage 14G)
- A refund recorded after delivery still needs review (no acknowledge/dismiss action)
- Schema 3→4 live migration is still unverified against real MySQL/MariaDB
