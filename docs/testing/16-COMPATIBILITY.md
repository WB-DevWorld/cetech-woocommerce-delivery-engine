# 16 — Compatibility

A PASS here applies only to the exact versions/environment tested.

Do not turn "no problem expected" into "certified compatible."

Record versions in [TEST-ENVIRONMENT.md](./TEST-ENVIRONMENT.md).

## DE-QA-COMPAT-001 — Storefront / generic WooCommerce theme

Use Storefront or the designated generic WooCommerce theme.

Run the core product → cart → checkout flow.

### Expected

Core Delivery Engine behavior does not depend on WoodMart.

---

## DE-QA-COMPAT-002 — WoodMart

On the supported WoodMart test site/version, run:

- simple product;
- variable product;
- location selection;
- delivery cards;
- cart;
- checkout;
- mobile view.

### Expected

WoodMart does not hide/break core Delivery Engine behavior.

The Delivery Engine must not require editing the WoodMart parent theme.

---

## DE-QA-COMPAT-003 — B2BKing

Where B2BKing Free/Pro is installed and part of the certification campaign:

Test at least:

- normal retail customer;
- B2B/wholesale customer;
- relevant payment/shipping visibility;
- product price remains owned by WooCommerce/B2BKing;
- Delivery Engine shipping remains correct.

### Expected

The Delivery Engine does not take over B2BKing merchandise pricing.

Record exact B2BKing version and customer group.

---

## DE-QA-COMPAT-004 — FOX / WOOCS

Where FOX/WOOCS Free/Professional is installed:

1. test the base currency;
2. switch to another configured currency;
3. obtain a Delivery Engine quote;
4. continue through checkout.

### Expected

Currency conversion happens once.

No double conversion.

Order currency/totals remain consistent.

Record the currencies and exact plugin version.

---

## DE-QA-COMPAT-005 — WoodMart Dynamic Discounts

Use a product/quantity where the product merchandise price changes through the pricing plugin.

### Expected

The merchandise price change does not make the Delivery Engine become the product-pricing authority.

Delivery logistics may react to quantity where configured, but the plugin must not recreate the merchandise discount.

---

## DE-QA-COMPAT-006 — WCFM isolation

Where WCFM is installed:

### Expected

An ordinary vendor does not gain Delivery Engine administration merely because WCFM is active.

Record only the currently claimed isolation scope. Do not call this full vendor-fulfilment certification unless separately implemented/tested.

---

## DE-QA-COMPAT-007 — WPML/WCML

Run only if this build/release campaign claims WPML/WCML support.

Record exact licensed versions.

### Expected

Translated customer copy and currency behavior follow the advertised support scope.

If not part of the release certification, record **NOT CERTIFIED / NOT TESTED**, not PASS.

---

## DE-QA-COMPAT-008 — Paystack

Paystack is relevant only as a Delivery Engine compatibility/payment-lifecycle test.

Use sandbox/test mode.

### Expected

A Paystack-paid test order produces the correct Delivery Engine post-payment behavior without duplicate shipments/orders.

Gateway availability alone is not enough to call this PASS.

---

## DE-QA-COMPAT-009 — Cache/session isolation

Where WP Rocket, Redis or another cache/session system is part of the campaign:

Use two customer sessions with different destinations.

### Expected

One customer's delivery location/quote does not leak into another customer's session.
