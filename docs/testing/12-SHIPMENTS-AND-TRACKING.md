# 12 — Shipments and Tracking

## DE-QA-SHIP-001 — Shipment creation

Create a fresh eligible order.

### Expected

A delivery order creates the expected shipment when its payment/order state requires it.

A pickup-only order must not create a false delivery shipment.

---

## DE-QA-SHIP-002 — No duplicate shipment

Repeat/reload/revisit the relevant order/payment processing path without intentionally creating another order.

### Expected

The same event does not create duplicate shipments.

---

## DE-QA-SHIP-003 — Shipment grouping

For a multi-item/multi-destination order, inspect shipment grouping.

### Expected

Items are placed into the correct operational shipments.

---

## DE-QA-SHIP-004 — Status progression

On a safe test shipment, move through allowed statuses.

### Expected

- valid status changes save;
- invalid/impossible changes are refused or clearly handled;
- order/customer presentation updates appropriately.

Do not alter real customer shipments.

---

## DE-QA-SHIP-005 — Manual tracking

Add safe test tracking information where supported.

### Expected

Tracking saves and appears in the intended staff/customer places.

---

## DE-QA-SHIP-006 — Customer-safe shipment card

Inspect the customer view.

### Expected

Show useful public information only.

Do not expose supplier, private origin, internal grouping keys, private notes, cost/margin or technical IDs.

---

## DE-QA-SHIP-007 — Cancel/refund interaction

Use a safe test order.

Perform the supported cancellation/refund scenario.

### Expected

Shipment/order state remains consistent and no duplicate/ghost shipment is created.

---

## DE-QA-SHIP-008 — COD behavior

Place a COD test order.

### Expected

Shipment behavior follows the documented COD/payment lifecycle rather than pretending the order was paid when it was not.
