# 08 — Storefront

## DE-QA-PDP-001 — Simple product delivery quote

Use the campaign simple product.

### Steps

1. Open the product as a shopper.
2. Select Ghana.
3. Select Greater Accra.
4. Select Accra.
5. Select the intended Delivery Option.
6. Record the delivery price.
7. Record the ETA/timeframe.
8. Add to cart.

### Expected

- valid Delivery Option appears;
- delivery price matches the configured rule;
- ETA is understandable;
- Add to Cart works;
- no private supplier/origin/internal IDs are shown.

---

## DE-QA-PDP-002 — Change destination

Change Accra to Tema.

### Expected

Eligibility, price and ETA update correctly.

Old Accra information must not remain authoritative.

---

## DE-QA-PDP-003 — Variable product

Use the campaign variable product.

Switch between at least two variations.

### Expected

The displayed delivery result follows the current variation.

No stale price/ETA/eligibility from the previous variation remains.

---

## DE-QA-PDP-004 — Quantity change

Change quantity where the product/customer UI allows it.

### Expected

Delivery state is rechecked where quantity matters.

The browser must not invent its own authoritative delivery amount.

---

## DE-QA-PDP-005 — Free pickup presentation

Use a valid free pickup case.

### Expected

The customer sees a clear Free/GH₵0 pickup result, not an error or missing-price state.

---

## DE-QA-PDP-006 — Customer privacy

Inspect the product delivery area closely.

### Expected

Do not expose:

- supplier identities;
- private origins/warehouse internals;
- internal logistics profiles;
- internal priorities;
- grouping keys;
- database IDs;
- private cost/margin data;
- rate-card internals.

---

## DE-QA-PDP-007 — Customer wording

### Expected

Labels are understandable to an ordinary shopper.

Report developer/debug wording, unclear abbreviations or contradictory delivery messages.
