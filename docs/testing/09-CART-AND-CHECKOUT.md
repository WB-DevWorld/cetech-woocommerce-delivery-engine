# 09 — Cart and Checkout

## DE-QA-CART-001 — Cart persistence

Create a fresh cart.

### Steps

1. select location and Delivery Option on the product page;
2. add to cart;
3. open cart;
4. navigate away;
5. return;
6. refresh.

### Expected

Valid delivery context remains attached to the correct cart item.

---

## DE-QA-CART-002 — Quantity revalidation

Change quantity.

### Expected

Delivery is revalidated where needed.

Stale invalid pricing/eligibility must not remain silently active.

---

## DE-QA-CART-003 — Destination change

Change the customer/shipping destination where the flow supports it.

### Expected

Old delivery results are not blindly reused.

---

## DE-QA-CHECKOUT-001 — Genuine WooCommerce shipping

Proceed to Classic checkout.

Compare:

| Source | Amount |
| --- | ---: |
| Delivery Charge in admin | |
| Product page | |
| Cart | |
| Checkout shipping | |

### Expected

The Delivery Engine amount appears as a genuine WooCommerce shipping charge.

It must not be hidden in merchandise price, duplicated, or added as an arbitrary product fee.

---

## DE-QA-CHECKOUT-002 — Missing/invalid delivery

Use a safe unsupported/missing-rate condition.

### Expected

Checkout refuses the invalid delivery choice or clearly requires correction.

Missing configuration must not silently become free delivery.

---

## DE-QA-CHECKOUT-003 — Customer-friendly shipping label

### Expected

Checkout uses the public Delivery Option label rather than a technical/internal method name.

---

## DE-QA-CHECKOUT-004 — Cart/Checkout Blocks

Where Blocks are used:

1. repeat the important cart/checkout flow;
2. confirm shipping amount;
3. confirm validation;
4. complete a test order if authorized.

### Expected

Blocks and Classic follow the same authoritative business rules.

Report any difference in price, eligibility or customer delivery information.
