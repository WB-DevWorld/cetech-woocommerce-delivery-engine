# Stage 14 shipments — staff training (RC.5)

**Plugin:** CETECH WooCommerce Delivery Engine **1.0.0-rc.5**  
**Audience:** Administrators and authorised shipment staff  
**Everyday home:** WordPress admin → **Delivery Engine → Overview**  
**Shipments home:** **Delivery Engine → Shipments** (only after an Administrator turns shipment records on)

This guide uses everyday staff language. It does **not** teach PHP, databases, or carrier integrations.

Screenshots are not recaptured for RC.5. Use these written steps on the live screens.

---

## What a shipment is

A **shipment** is the Delivery Engine’s operational record for fulfilling a **delivery** group that was saved on a WooCommerce order.

It is **not**:

- a new WooCommerce order
- a replacement for WooCommerce payment, totals, or refunds
- a Store Pickup ticket
- a live carrier tracking feed

WooCommerce remains the owner of money, products, customer details, and payment. The Delivery Engine shipment is the staff work record for moving the goods.

The shipment is built from the **historical delivery details already saved on that order**. Staff must not rebuild it from today’s product settings.

---

## Turning Shipments on (Administrators only)

Shipment records stay **off** after upgrade until an Administrator turns them on.

1. Open **Delivery Engine → Settings**.
2. Turn on **Enable shipment records**. That creates eligible shipments and shows the **Shipments** menu.
3. Optionally turn on **Enable customer tracking links**. Customers then see **Track shipment** only when a shipment has a safe `http` or `https` tracking URL.

Customer timeline is **not** in this release.

Tracking links on their own do **not** create shipments. This does **not** call carriers or refresh tracking automatically.

---

## Shipments menu

When shipment records are on, authorised staff see **Delivery Engine → Shipments**.

Use the list to search and open a shipment. The detail screen shows:

- shipment reference (for example `39737-D1`)
- WooCommerce order
- customer-safe destination summary
- delivery option
- items and quantities
- original estimated delivery
- current estimated delivery, if later updated
- tracking fields, when present
- staff History

Use **Create shipment from order** when a legitimate order needs a shipment and one was not created automatically (typical for Cash on Delivery). Enter the WooCommerce Order ID, review the read-only preview, then confirm. Do not type delivery prices or regroup items.

---

## Needs Attention

**Needs Attention** is the operational inbox. Opening it does **not** clear a task.

Shipment-related rows can include:

- Cash on Delivery order awaiting shipment creation
- delayed shipment
- order cancelled after the shipment already progressed
- refund that needs a physical fulfilment review

Each row should take you to the WooCommerce order and/or the shipment, as permitted.

A task disappears when the underlying work is done (for example the COD shipment is created, the delayed shipment is recovered, or the shipment is cancelled or delivered according to the existing rules). Do not look for a separate “dismiss” button for a correctly completed task.

The **Needs Attention** menu badge counts these unresolved operational items. It is **not** the same as the **Shipments** activity badge.

---

## Status meanings

| Status | Meaning for staff |
|--------|-------------------|
| Awaiting fulfilment | The delivery shipment exists and is waiting to be worked. |
| Processing | Staff are preparing the shipment. |
| Dispatched | The shipment has been sent. |
| In transit | The shipment is on the way. |
| Delayed | The shipment is late or blocked and needs attention. |
| Delivered | The shipment is complete. |
| Cancelled | The shipment will not be fulfilled. |

Saving a tracking number does **not** by itself change status.

---

## Normal status progression

Typical happy path:

**Awaiting fulfilment → Processing → Dispatched → In transit → Delivered**

**Delayed** can appear when the shipment is late; recover it to a progressed status when movement resumes.

Use only the supported status actions on the shipment screen. Do not invent a status.

Marking **Dispatched** does not invent a dispatch date. A shipment may be dispatched with no dispatch date.

**Delivered** and **Cancelled** are finished outcomes for ordinary work.

---

## Delayed workflow

If a shipment is **Delayed**:

1. It appears in **Needs Attention**.
2. Investigate the real fulfilment problem.
3. When it is moving again, change status using the supported recovery action (for example back to **In transit**).
4. The delayed Needs Attention row clears when the shipment leaves Delayed.

Do not leave Delayed as a parking status for finished work.

---

## Correction workflow

Authorised staff may **correct** a status when the recorded status is wrong (for example the parcel was already delivered).

A correction **requires an internal reason**.

Customers see the corrected status. They must **never** see the internal correction reason.

---

## Tracking

Enter, when you have them:

- public carrier display name
- tracking number
- a safe tracking URL (`http` or `https` only)

Customers get **Track shipment** only when:

1. shipment records are on
2. customer tracking links are on
3. the stored URL is a safe http/https link

No URL, or an unsafe URL, means **no** customer Track button.

This release does **not** poll carriers or update tracking automatically.

---

## Original vs current estimated delivery

- **Original estimated delivery** is what the customer was given at checkout. It stays on the record.
- **Current estimated delivery** may be updated later if the operational estimate changes.

Updating the current estimate does **not** overwrite the original. Customers may see that the estimate was updated. Internal reasons stay internal.

---

## Prepaid / online automatic creation

For a prepaid or online-paid Delivery Engine order, confirmed WooCommerce payment creates the delivery shipment automatically.

Confirmation means WooCommerce payment complete, or a persisted paid date. A “paid-looking” order status is **not** enough on its own.

Automatic creation uses the historical order delivery groups. It does **not** reread today’s product configuration.

---

## Cash on Delivery workflow

Cash on Delivery is a real fulfilment job even when payment has not been recorded yet.

1. The customer places a COD order. Thank You is normal.
2. WooCommerce may mark the order Processing. Delivery Engine does **not** automatically create a shipment while payment is unconfirmed (`date_paid` empty).
3. **Needs Attention** shows an action-required row: Cash on Delivery order awaiting shipment creation.
4. Staff open **View Order** and/or **Create Shipment**.
5. The preview is filled from the **saved order delivery details**. Staff confirm. They do not re-enter delivery prices or options.
6. The shipment is created. The COD Needs Attention task clears.
7. If payment is recorded later, Delivery Engine must **not** create a second shipment.

Do **not** mark the order paid just to force a shipment. Do **not** expect automatic COD creation.

---

## Manual shipment creation from Order ID

On **Delivery Engine → Shipments**:

1. Enter the WooCommerce Order ID.
2. Choose **Find order**.
3. Read the preview (order, customer, payment method, destination summary, historical delivery option, items, groups).
4. Confirm only if the preview is the right historical job.

Rules:

- One shipment per eligible historical **delivery** group.
- Store Pickup groups are skipped. No fake pickup shipment.
- Creating the same order twice does **not** create a duplicate shipment.
- If the shipment already exists, the screen shows it.

Staff need shipment permission. A direct URL or form post cannot bypass that.

---

## Pickup behaviour

Store Pickup is not a delivery shipment.

- Pickup-only orders do not get a delivery shipment task.
- A mixed order (pickup + delivery) creates a shipment only for the genuine delivery group.

Do not invent a pickup shipment so the counts “look even.”

---

## Air and Sea as separate shipments

International Air and International Sea are different delivery groups.

If one order has both, staff should see separate shipments, for example:

- `39744-D1` — Air
- `39744-D2` — Sea

Work each shipment on its own. Do not merge them by hand.

---

## Cancellation handling

WooCommerce owns the order cancel. Delivery Engine follows conservatively:

- If the shipment has **not** been dispatched yet, cancelling the WooCommerce order can automatically cancel that shipment.
- If the shipment is already **Dispatched**, **In transit**, **Delayed**, or **Delivered**, the shipment status stays. **Needs Attention** asks staff to review the physical goods.

Do not pretend a parcel already in transit was never sent.

---

## Refund handling

WooCommerce owns the refund money. Delivery Engine never changes what the customer paid for shipping.

- **Full item-quantity refund** of every item on a not-yet-dispatched shipment → that shipment can be auto-cancelled.
- **Amount-only refund** (money refunded, no refund line quantities) → shipment status stays. **Needs Attention** asks for a physical fulfilment review.
- **Partial quantity**, mixed, or unclear refunds → status stays; review required.
- **Any refund after dispatch / in transit / delivered** → do not auto-cancel. Review required.

Never treat “order status = refunded” or a refund amount alone as proof that the physical goods were returned.

---

## Staff identity and History

Shipment History records who did the work.

Staff events should identify a unique person, for example:

**Jane Love (Staff · User #3)**

If several people share a first name, the WordPress user ID still distinguishes them. System-created events show **System**.

The human name may link to the WordPress user edit screen only when the current user is allowed to edit that account. History does **not** show staff email or login on purpose.

Customers never see staff names or user IDs.

---

## Badges

**Needs Attention badge**  
Counts unresolved operational tasks (including COD awaiting shipment creation, delayed shipments, cancel-after-progress, and refund review). Opening the page does **not** clear the count. Completing the work does.

**Shipments activity badge**  
Shows that there is new shipment activity the **current user** has not reviewed yet. Opening Shipments acknowledges it for that user only. It does not clear another person’s badge. It is not the same as Needs Attention.

Unauthorized users must not see operational counts.

---

## Permissions

Administrators keep full access.

Other roles are granted in **Settings → Access**:

- **Manage shipments** — open Shipments, view detail, save tracking, create a shipment from an order
- **Update shipment status** — change status, corrections, current ETA

A person who can view shipments must not change status unless update permission is granted. Hiding a menu item is not security; the server still checks permission.

---

## What staff must never change

Do **not**:

- edit the amount the customer already paid for shipping
- rewrite historical order delivery details to match today’s product settings
- invent free shipping
- expose supplier, origin, Logistics Profile, Rate Card, internal cost, or private notes to customers
- show internal correction reasons on customer pages
- mark a COD order paid just to create a shipment
- create a fake Store Pickup shipment
- merge Air and Sea into one shipment by hand
- call carriers or paste tracking from an unsafe `javascript:` link

---

## Historical order data is the authority

When a shipment is created — automatically after confirmed payment, or manually from an Order ID — Delivery Engine uses the **saved order snapshot**.

Today’s Site-wide Defaults or Product Exceptions do **not** rewrite that historical job.

Past orders keep what the customer bought. Change future settings on Site-wide Defaults or Product Exceptions, not on old orders.

---

## Customer View Order

Customers may see **Delivery shipments** as one card per shipment, with status, current estimate, items, tracking number, and **Track shipment** when allowed.

They must **not** see:

- staff identity
- supplier / origin / Logistics Profile / Rate Card
- internal cost
- private notes
- correction reasons
- delivery group ids or other technical identifiers

WooCommerce **Order Again** is a separate store action. The Delivery shipments section sits below it and must not be confused with it.

---

## Related training

- Everyday home and golden rules: [00 — Start here](00-START-HERE.md)
- Administrator configuration: [02 — Complete Administrator Guide](02-COMPLETE-ADMIN-GUIDE.md)
- Staff course (catalogue and checkout): [05 — Staff Training Manual](05-STAFF-TRAINING-MANUAL.md)
