# 05 — Delivery Options

Do not assume an option already exists.

Create the minimum test option if needed. Later testers should reuse it and repeat the tests.

## DE-QA-OPTION-001 — Create Delivery Option

Create a clear option such as **Standard Delivery** if the campaign does not yet have one.

Record:

- public name;
- enabled state;
- delivery timeframe/ETA settings;
- any other customer-visible settings.

### Expected

Save succeeds and the option can be reopened with the same values.

---

## DE-QA-OPTION-002 — Edit option

Change one safe field, save, confirm, then restore the campaign value if required.

### Expected

Only the intended field changes.

---

## DE-QA-OPTION-003 — Disable option

If safe in the shared campaign:

1. disable the option;
2. check the relevant customer page;
3. re-enable it.

### Expected

A disabled option does not remain available to the customer.

Do not leave the shared option disabled.

---

## DE-QA-OPTION-004 — Public label

Check the option on the product page/cart/checkout where it appears.

### Expected

The customer sees the public Delivery Option name, not an internal technical label.

---

## DE-QA-OPTION-005 — ETA/timeframe presentation

### Expected

The configured promise/timeframe is understandable and not contradicted by another part of the customer journey.
