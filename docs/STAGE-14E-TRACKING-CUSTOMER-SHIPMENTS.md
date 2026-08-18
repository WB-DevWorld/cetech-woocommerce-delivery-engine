# Stage 14E — Manual Tracking + Customer Shipment Presentation

**Document status:** Stage 14E completion record  
**Date:** 2026-08-18  
**Plugin version:** `1.0.0-rc.4` (unchanged)  
**Schema target:** `4` (unchanged)  
**Feature flags:** `enable_shipment_records` default **OFF**; `enable_tracking_links` default **OFF**; `enable_customer_timeline` unused / reserved **OFF**  
**FLAIROC:** NOT MODIFIED  

This document records staff manual tracking management and customer My Account View Order shipment cards. It does **not** add shipment status workflow, ETA editing, customer emails, customer timeline, carrier APIs, or FLAIROC changes.

---

## 1. Verdict

**COMPLETE for the authorised Stage 14E scope.**

Staff can save carrier, tracking number, tracking URL, dispatch date, and public shipment note on an existing shipment detail screen. Customers can see compact shipment cards on WooCommerce My Account → View Order when shipment records and the existing RC.4 customer order summary flag are both enabled. Tracking does not change shipment status.

---

## 2. Tracking data model

Stage 14B schema 4 already stored the required fields. No schema change was made.

| Staff field | Stored column | Notes |
|-------------|---------------|--------|
| Carrier | `tracking_carrier_display` | Customer-facing text entered by staff. Not an internal provider code. |
| Tracking number | `tracking_number` | Operational identifier. Not shipment identity. Not translated. |
| Tracking URL | `tracking_url` varchar(500) | Manual http/https URL only. |
| Dispatch date | `dispatch_at` datetime | Stored UTC datetime from a date-only staff input. Displayed in locale. |
| Public shipment note | `public_note` | Customer-visible authored text. |
| Private/internal note | `private_note` | Unchanged; not editable in the tracking form; never customer-visible. |

`public_carrier_name` remains the historical offer snapshot field and is **not** treated as a staff-recorded carrier for Stage 14E.

---

## 3. Staff tracking management

Location: Delivery Engine → Shipments → shipment detail, Tracking section.

**Capability:** `manage_shipments`. No new capability. Access matrix UX was not redesigned. Administrator recovery remains `manage_options`.

**Security:** POST + `cetech_de_nonce` + `cetech_de_action=cetech_de_save_shipment_tracking` + server-side shipment id lookup. Unauthorized or invalid nonce requests fail with the existing admin action redirect. Post/Redirect/Get prevents repeat submission.

**Validation:**

- Tracking number: sanitized text, max 191 characters; preserved as entered after sanitization.
- Carrier: sanitized text, max 255.
- URL: http/https only; javascript/data/file and malformed values rejected.
- Dispatch date: `Y-m-d` or empty; invalid dates rejected. Saving a date does **not** set status to `dispatched`.
- Public note: textarea sanitization, max 5000; stored as authored text, not machine-translated.
- Empty fields clear the stored value. Clearing is allowed and recorded in history.

Identical saves do not rewrite history.

---

## 4. Tracking events / audit

Recognized machine codes:

- `tracking_added` — first tracking/dispatch details
- `tracking_updated` — later changes or clearing
- `note_added` — public note change (short audit sentence only)

Event `public_note` is a short staff history sentence such as “Tracking added.” The tracking number, URL, and full customer note body are **not** copied into the event row. Actor and timestamps follow existing event columns. Unknown persisted event codes still hydrate and present as “Shipment update”.

---

## 5. Status non-mutation

Tracking save uses `Shipment::withTrackingDetails()` only. `status`, `eta_*`, and private logistics fields are not written. Stage 14F owns status workflow.

---

## 6. Customer presentation

**Location:** WooCommerce My Account → Orders → View Order only (`is_view_order_page()`).

Not added: My Account → Deliveries, public tracking page, guest tracking portal, thank-you duplication of shipment cards, emails.

**Flags:**

- Cards require `enable_shipment_records` **and** `enable_customer_order_delivery_summary`.
- `enable_tracking_links` controls the Track shipment control only.
- `enable_customer_timeline` is not used.

**Card contract (customer-safe DTO):** reference, public Delivery Option, translated status, current ETA (with “updated estimate” when current differs from original), compact items from shipment snapshots, public carrier, tracking number, validated tracking URL, dispatch date, public note.

Never exposed: supplier, origin, Logistics Profile, internal cost, Rate Card, `delivery_group_id`, idempotency key, private note, event history/payloads, database ids.

**Track shipment** appears only with a stored http/https URL and `enable_tracking_links` ON. A tracking number without a valid URL shows the number only.

**Multiple shipments** render as separate cards. Pickup shipments are not created in Stage 14 V1; a stored pickup fulfilment_choice is not rendered as a customer delivery card. Existing RC.4 pickup compact rows remain for pickup lines. On View Order, delivery lines already represented by a shipment card are omitted from the RC.4 compact block so Delivery Option / ETA are not duplicated. Paid totals remain on WooCommerce shipping; shipment cards do not reprint the delivery charge.

**Ownership:** cards load by `WC_Order::get_id()` after WooCommerce has authorised View Order. No public REST endpoint. No shipment id in a customer URL.

---

## 7. Language / accessibility / mobile

Interface labels use gettext on `cetech-woocommerce-delivery-engine`. Tracking numbers/URLs/public notes are not machine-translated. Status labels are translated only at presentation.

Staff form fields have associated labels. Customer cards use `section`/`article`/headings, text status (not colour-only), and a 44px-minimum Track shipment link with `rel="noopener noreferrer"` and a complete `aria-label`. Tracking values wrap (`overflow-wrap: anywhere`).

---

## 8. Performance / caching

View Order loads `findByOrderId($order_id)` plus that shipment’s items. Event history is not loaded for customers. No product/cart/checkout queries. No polling. No public cache of tracking data. No structured-data output.

---

## 9. Tests

Focused coverage includes URL scheme validation, tracking add/update/clear, status non-mutation, nonce/capability denial, unknown-event regression, customer ownership isolation, multiple cards, historical labels/ETA/items, Track shipment flag/URL rules, privacy exclusions, pickup RC.4 remainder, and no duplicate delivery charge.

---

## 10. Remaining limitations

- Real MySQL/MariaDB schema 3→4 migration remains unverified locally; FLAIROC was not used
- Feature flags remain OFF by default and Settings-unavailable
- No status mutation, ETA editing, refund/cancellation lifecycle, customer emails, customer timeline, or carrier APIs
- Guest public tracking portal was not added
- Thank-you page continues to show RC.4 compact delivery details without shipment cards

## 11. Intentionally not implemented

Processing/Dispatched/In transit/Delivered/Delayed/Cancel actions, automatic status transitions, ETA-edit UI, shipment emails, carrier polling, WooCommerce Fulfillments dual-write, historical backfill, Checkout Blocks, bulk import, GPS/OTP/QR/POD, Stage 14F+, package/release, version bump, new tag, FLAIROC.
