# 17 — Failure and Recovery

These tests deliberately create safe error conditions.

Do not damage shared production-like configuration when a safer isolated test fixture can prove the same behavior.

## DE-QA-FAIL-001 — Unsupported destination

Use a location outside the configured Delivery Area.

### Expected

Delivery is unavailable or correction is required.

It must not silently become free delivery.

---

## DE-QA-FAIL-002 — Missing Delivery Charge

Use a safe product/destination/option combination with no valid charge.

### Expected

Fail closed.

No accidental GH₵0/free shipping.

---

## DE-QA-FAIL-003 — Malformed/invalid rate

Where a controlled test fixture can safely represent an invalid rate, test it.

### Expected

The invalid value is rejected or ignored safely.

It must not become a valid zero charge.

---

## DE-QA-FAIL-004 — Disabled Delivery Option

Disable a safe test Delivery Option.

### Expected

It is no longer offered.

An existing stale selection must not silently remain valid.

Restore the campaign option afterwards.

---

## DE-QA-FAIL-005 — Stale cart

Create a cart with a valid delivery selection.

Then safely change the underlying test configuration so that selection is no longer valid.

### Expected

Cart/checkout revalidates and asks for correction.

It must not silently replace the service with another Delivery Option.

Restore the shared test configuration.

---

## DE-QA-FAIL-006 — Product becomes unavailable

Use a safe test product and change its relevant delivery availability.

### Expected

Existing stale storefront/cart state is not blindly trusted.

---

## DE-QA-FAIL-007 — Location becomes unsupported

Change a safe test Area/fixture after a cart has been created.

### Expected

The cart/checkout detects that the old destination is no longer valid.

Restore the shared fixture after the test.

---

## DE-QA-FAIL-008 — Duplicate/repeated event

Where the test harness safely allows the same order/payment/shipment event to be processed again:

### Expected

No duplicate shipment/order delivery record is created.

---

## DE-QA-FAIL-009 — Error message quality

For each deliberate failure above, inspect the message shown to staff/customer.

### Expected

It explains what needs to be corrected without exposing PHP/SQL/private internals.

---

## DE-QA-FAIL-010 — Recovery after correction

Correct the invalid setup and retry the customer journey.

### Expected

The system recovers normally without requiring destructive cache/database resets.
