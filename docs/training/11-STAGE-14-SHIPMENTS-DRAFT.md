# Stage 14 shipments — DRAFT staff notes (not final)

**Status:** Draft for `1.0.0-rc.5-qa.5` owner retest. Do **not** treat this as the published RC.4 training set. Screenshots and final wording wait for owner QA PASS.

**Everyday home remains:** WordPress admin → **Delivery Engine → Overview**

---

## What Shipments is

When an Administrator turns on **Enable shipment records** in Settings, eligible **paid Delivery Engine delivery orders** get shipment records. Staff work them in **Delivery Engine → Shipments**. Store Pickup is not turned into a fake delivery shipment.

These settings stay **off** after upgrade until an Administrator turns them on.

---

## Turning it on (Administrators only)

1. Delivery Engine → **Settings**
2. **Enable shipment records** — shipment creation + staff Shipments workspace
3. **Enable customer tracking links** — customer **Track shipment** when a shipment has a safe http/https URL

Customer timeline is **not** in this release.

Tracking links on their own do **not** create shipments or customer cards. This does **not** call carriers or refresh tracking automatically.

---

## Shipment statuses

Awaiting fulfilment → Processing → Dispatched → In transit → Delayed → Delivered, or Cancelled when that is the correct operational outcome.

Saving a tracking number does **not** by itself change status.

---

## Searching and opening shipments

Use the Shipments list filters/search. Open one shipment to see order, customer, items, Delivery Option, ETA, tracking, and history.

---

## Adding tracking

Enter carrier display name, tracking number, and a safe tracking URL if you have one. Customers only get **Track shipment** when records **and** tracking links are on **and** the URL is safe.

---

## Public vs private notes

Public notes can appear to the customer. Private notes, correction reasons, and internal costs stay internal.

---

## Changing status / Delayed / Correct status / current ETA

- Use the supported status actions.
- **Delayed** can raise Needs Attention until recovered.
- **Correct status** needs a reason. Customers see the corrected status, not the internal reason.
- Updating **current ETA** keeps the original estimate on the record.

---

## Needs Attention

Operational issues (for example a refund/cancel after the shipment has already progressed) appear in Needs Attention. Completing or cancelling a shipment should not leave a stale “needs review” row for a finished outcome.

---

## Cancellations and refunds

Before dispatch, cancellation can cancel the shipment. After physical progress, do not pretend the parcel was never sent — expect Needs Attention instead of a silent fake cancel.

---

## Permissions

Administrators keep full access. Other roles are set in Settings → Access (`manage_shipments`, `update_shipment_status`, and related caps). A user without update permission should not change status.

---

## Customer View Order / tracking

Customers may see shipment cards (status, current ETA, tracking number, Track shipment). They must **not** see supplier, origin, Logistics Profile, internal cost, Rate Card, private notes/reasons, or technical ids.

---

## Information that must remain private

Supplier, origin, Logistics Profile, internal cost, Rate Card identity, private notes, correction reasons, delivery group ids, and other technical identifiers.
