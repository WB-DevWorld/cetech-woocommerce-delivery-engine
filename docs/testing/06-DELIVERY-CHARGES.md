# 06 — Delivery Charges

Do not assume a charge already exists.

Create the minimum test charge if needed. Later testers reuse it and verify it again.

## DE-QA-RATE-001 — Create Delivery Charge

Create a charge for the campaign Delivery Area + Delivery Option.

Record:

- Delivery Area;
- Delivery Option;
- currency;
- amount;
- dates/conditions if used.

Choose an amount that is easy to recognize later.

### Expected

The charge saves and reopens correctly.

---

## DE-QA-RATE-002 — Authoritative amount

Use the configured charge through the product page, cart and checkout.

### Expected

The same authoritative amount is used unless a documented rule legitimately changes it.

---

## DE-QA-RATE-003 — Explicit zero

Create/use a safe test case where the valid delivery charge is intentionally zero.

### Expected

Zero means a real configured free charge.

It must not be confused with “no rate found.”

---

## DE-QA-RATE-004 — Missing rate

Use a safe destination/product combination with no valid charge.

### Expected

The plugin fails closed.

It must not silently become free delivery.

---

## DE-QA-RATE-005 — Different area/option charge

Create/use a second controlled rate for a different Area or Delivery Option.

### Expected

The correct rate is chosen for the correct combination.

---

## DE-QA-RATE-006 — Effective dates

If date-based charges are configured, test one active and one inactive period.

### Expected

Only the correct currently effective rate is used.

Record the site date/time used for the test.
