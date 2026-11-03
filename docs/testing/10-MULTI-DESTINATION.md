# 10 — Multi-Item and Multi-Destination

## DE-QA-MULTI-001 — Two products, same destination

Add two test products to the cart for the same destination.

### Expected

The plugin groups/charges them according to the configured fulfilment rules.

It must not duplicate shipping accidentally.

---

## DE-QA-MULTI-002 — Different delivery needs

Use two products with different legitimate delivery requirements.

### Expected

The customer sees the correct delivery choices/groups for each requirement.

Private grouping logic remains hidden.

---

## DE-QA-MULTI-003 — Two destinations

Where the current customer flow supports per-item destinations:

1. send Product A to Destination A;
2. send Product B to Destination B;
3. continue through cart/checkout.

### Expected

Each line keeps the correct destination and quote.

One line must not overwrite the other.

---

## DE-QA-MULTI-004 — Edit one destination

Change only one item's destination.

### Expected

Only the affected delivery group/quote changes.

Other valid items remain correct.

---

## DE-QA-MULTI-005 — Mixed pickup and delivery

Where supported, combine:

- one pickup item;
- one delivered item.

### Expected

Pickup does not create a false delivery charge/shipment.

The delivery item still receives the correct shipping charge.

---

## DE-QA-MULTI-006 — Multi-quantity

Use quantity greater than one for a nominated product.

### Expected

The plugin applies the configured logistics/grouping behavior correctly and does not duplicate/lose line context.
