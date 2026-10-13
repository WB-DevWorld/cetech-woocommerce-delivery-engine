# 11 — Orders and Purchase-Time Snapshots

Every tester performing these cases should create a fresh test order where practical.

## DE-QA-ORDER-001 — Order delivery information

Complete checkout and open the new WooCommerce order.

### Expected

The order records the delivery facts the customer agreed to:

- Delivery Option;
- shipping amount;
- destination;
- ETA/promise where applicable;
- delivery grouping/shipment information where applicable.

---

## DE-QA-ORDER-002 — Customer Thank You / My Account

Check customer-facing order information.

### Expected

It matches checkout and remains understandable.

No private supplier/origin/logistics information is exposed.

---

## DE-QA-ORDER-003 — Email presentation

Where test email delivery is available, inspect the order email.

### Expected

Delivery information is useful and customer-safe.

No duplicate shipping summary or private internal details.

---

## DE-QA-ORDER-004 — Snapshot remains historical

Use a safe dedicated test order.

After purchase, change one relevant current configuration value, such as a test rate or label, then inspect the existing order again.

### Expected

The old order continues to show the purchase-time delivery facts.

Changing today's configuration must not rewrite what the customer previously bought.

Restore the shared campaign configuration after the test where required.

---

## DE-QA-ORDER-005 — Admin/customer consistency

Compare the admin order view and customer-facing order view.

### Expected

They agree on customer-facing facts such as service and charge.

Admin may have additional operational information, but private details must not leak to the customer.
