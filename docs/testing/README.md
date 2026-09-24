# Delivery Engine Rapid Test Guide

**For testing on 24 September 2026**

This guide is for staff testers. You do **not** need to understand how the Delivery Engine is built internally.

Your job is simple:

> Use the plugin like a normal staff member and customer, check that it behaves correctly, and report anything that does not work.

**Plugin version being tested:** `1.0.0-rc.12`  
**Database/schema version:** `6`

---

# Table of Contents

1. [Read this first](#1-read-this-first)
2. [What can be skipped and what must be tested again](#2-what-can-be-skipped-and-what-must-be-tested-again)
3. [Important Ghana Location Pack instruction](#3-important-ghana-location-pack-instruction)
4. [What the original clean starting point was](#4-what-the-original-clean-starting-point-was)
5. [Before you begin](#5-before-you-begin)
6. [How to record results](#6-how-to-record-results)
7. [What to do when something fails](#7-what-to-do-when-something-fails)
8. [Suggested order if you have limited time](#8-suggested-order-if-you-have-limited-time)
9. [Baseline checks](#9-baseline-checks)
10. [Setup Guide check](#10-setup-guide-check)
11. [Ghana locations](#11-ghana-locations)
12. [Delivery Areas](#12-delivery-areas)
13. [Delivery Options](#13-delivery-options)
14. [Delivery Charges](#14-delivery-charges)
15. [Site-wide Defaults](#15-site-wide-defaults)
16. [Simple product test](#16-simple-product-test)
17. [Variable product test](#17-variable-product-test)
18. [Product page customer test](#18-product-page-customer-test)
19. [Cart test](#19-cart-test)
20. [Checkout test](#20-checkout-test)
21. [Place a fresh test order](#21-place-a-fresh-test-order)
22. [Check the completed order](#22-check-the-completed-order)
23. [Pickup test](#23-pickup-test)
24. [Unsupported location test](#24-unsupported-location-test)
25. [Missing delivery price test](#25-missing-delivery-price-test)
26. [Shipment check](#26-shipment-check)
27. [Needs Attention check](#27-needs-attention-check)
28. [Mobile and WoodMart check](#28-mobile-and-woodmart-check)
29. [Error and log check](#29-error-and-log-check)
30. [Optional tests if there is time](#30-optional-tests-if-there-is-time)
31. [What not to spend time on today](#31-what-not-to-spend-time-on-today)
32. [Final tester sign-off](#32-final-tester-sign-off)
33. [Defect and test report templates](#33-defect-and-test-report-templates)

---

# 1. Read this first

This is a **rapid real-world test**.

It is not meant to test every feature in the Delivery Engine.

We mainly want to know:

- Can staff understand and use the plugin?
- Can staff create and manage delivery settings?
- Do Ghana locations work correctly?
- Can a customer select a delivery option?
- Does the customer see the correct delivery price and delivery time?
- Does the delivery choice stay correct in the cart and checkout?
- Does WooCommerce charge the correct delivery amount?
- Does the completed order keep the correct delivery information?
- Does pickup work?
- Does the plugin refuse invalid or unsupported delivery instead of accidentally giving free delivery?
- Does anything crash, show an error, or expose private internal information?

---

# 2. What can be skipped and what must be tested again

This is very important.

## Only these two parts may be treated as already completed

1. **Baseline checks**
2. **Setup Guide**

If another authorized tester has already completed those two parts:

- do not reset the plugin;
- do not delete their setup;
- do not restart the Setup Guide;
- simply confirm that the current state looks correct;
- write **CONFIRMED — ALREADY COMPLETED**;
- continue to the next test.

## Every other test must be done again by every tester

Even if another tester already created:

- a Delivery Area;
- a Delivery Option;
- a Delivery Charge;
- Site-wide Defaults;
- a Pickup Location;
- product settings;
- variation settings;
- test orders;

you must still perform the actual test yourself.

You may **reuse the existing setup**, but you must repeat the test.

For example:

- If Greater Accra is already set up, use it and test it again.
- If Standard Delivery already exists, use it and test it again.
- If a product is already configured, use that product and test it again.
- If another tester already placed an order, create your own fresh order when the order test requires one.

A previous tester's PASS does not count as your PASS.

---

# 3. Important Ghana Location Pack instruction

## DO NOT DOWNLOAD GHANA AGAIN

The Ghana Location Pack is already installed.

It should show:

**Ready**

If it is already Ready, leave it alone.

If Ghana is missing, Failed, or does not show Ready:

1. take a screenshot;
2. record what you see;
3. mark the test **BLOCKED**;
4. tell the test coordinator.

Do not download or reinstall Ghana unless you are specifically instructed to do so.

---

# 4. What the original clean starting point was

Before this test campaign started, the Delivery Engine was reset to approximately:

| Item | Original clean state |
| --- | --- |
| Ghana Location Pack | Ready |
| Delivery Options | 0 |
| Delivery Areas | 0 |
| Delivery Charges | 0 |
| Pickup Locations | 0 |
| Product / Variation Exceptions | 0 |
| Shipments | 0 |
| Bulk history | 0 |
| Site-wide delivery settings | Empty |
| Needs Attention | High because many products had no delivery setup |

At one point the Needs Attention count was about **75**.

These numbers describe the **original starting point**.

They are **not** numbers that every tester should try to restore.

After testing starts, there may already be:

- Delivery Areas;
- Delivery Options;
- Delivery Charges;
- configured products;
- test orders;
- shipments.

That is normal.

---

# 5. Before you begin

Fill this in:

| Information | Tester fills |
| --- | --- |
| Tester name | |
| Test date | 24 September 2026 |
| Start time | |
| Finish time | |
| Website being tested | |
| WordPress user / role | |
| Browser | |
| Device | |
| Plugin version shown | |
| Test Run ID | Example: RAPID-JANE-01 |

Use a fresh browser session where practical.

For customer tests, use a normal customer/guest view where the test requires it.

---

# 6. How to record results

For every test, use one of these:

- **PASS** — it worked correctly.
- **FAIL** — it did not work correctly.
- **BLOCKED** — you could not complete the test because something else prevented it.
- **NOT TESTED** — you ran out of time or did not perform it.

For the **Baseline** and **Setup Guide only**, you may also use:

- **CONFIRMED — ALREADY COMPLETED**

Do not use PASS for something you did not actually test.

## How serious is the problem?

Use:

- **Critical** — checkout cannot work, wrong money is charged, the site crashes, free delivery appears by mistake, data is lost, or private information is exposed.
- **Major** — an important delivery feature gives the wrong result or cannot be used.
- **Moderate** — a real problem exists, but there is a reasonable workaround.
- **Minor** — wording, spacing, layout, small usability issue, or other low-risk problem.

---

# 7. What to do when something fails

Before changing anything, record:

1. the page you were on;
2. the product;
3. the variation if there was one;
4. the location you selected;
5. the Delivery Option;
6. the quantity;
7. what you clicked;
8. what you expected;
9. what actually happened;
10. a screenshot or short video;
11. the time;
12. the order or shipment number if one exists.

Then try the same steps once more if it is safe to do so.

Do not report only:

> Delivery is not working.

Use the [Defect Report Template](./DEFECT-REPORT-TEMPLATE.md).

---

# 8. Suggested order if you have limited time

If time is short, test in this order:

| Order | Test |
| ---: | --- |
| 1 | Baseline and Setup Guide confirmation |
| 2 | Ghana locations |
| 3 | Delivery Area |
| 4 | Delivery Option |
| 5 | Delivery Charge |
| 6 | Site-wide Defaults |
| 7 | Simple product |
| 8 | Variable product |
| 9 | Product page customer journey |
| 10 | Cart |
| 11 | Checkout |
| 12 | Fresh test order |
| 13 | Order information |
| 14 | Pickup |
| 15 | Unsupported location |
| 16 | Missing delivery price |
| 17 | Shipment |
| 18 | Needs Attention |
| 19 | Mobile / WoodMart |
| 20 | Errors and logs |

If you run out of time, mark the remaining tests **NOT TESTED**.

---

# 9. Baseline checks

## DE-RAPID-BASE-001 — Check the plugin version

Open the Delivery Engine and confirm the installed version.

### Expected

- Plugin version: **1.0.0-rc.12**
- Schema: **6**, if the screen shows it

If you see another version, record exactly what you see.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-BASE-002 — Check the current starting state

If another tester already confirmed the original clean baseline, do not reset anything.

Simply check that the current setup makes sense based on testing that has already happened.

### Important

Do not fail this test just because Delivery Options, Areas, Charges, or products are no longer zero.

That is expected once testing has begun.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-BASE-003 — Check Ghana Location Pack

Open the Location Packs area.

Find Ghana.

### Expected

Ghana shows:

**Ready**

Do not download it again.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 10. Setup Guide check

## DE-RAPID-SETUP-001 — Setup Guide

If another authorized tester has already completed the Setup Guide:

- do not restart it;
- do not reset anything;
- confirm that it appears completed or no longer requires action;
- record **CONFIRMED — ALREADY COMPLETED**.

If it has not been completed, follow it normally.

### Report a problem if

- the instructions are confusing;
- the guide sends you to the wrong place;
- a required button does not work;
- the guide asks you to repeat something that is already complete;
- the guide cannot be completed.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 11. Ghana locations

## DE-RAPID-GEO-001 — Check important Ghana locations

Open the location controls.

Find:

- Ghana
- Greater Accra
- Accra
- Tema

If time permits, also find:

- Ashanti
- Kumasi

### Expected

- Accra appears under the correct Ghana geography.
- Tema appears under the correct Ghana geography.
- Kumasi appears under Ashanti.
- You can find the locations without knowing internal database numbers.
- There should not be confusing duplicate locations that make normal selection impossible.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 12. Delivery Areas

## DE-RAPID-AREA-001 — Check Greater Accra Delivery Area

Use the Greater Accra Delivery Area that already exists.

Do not skip this test because it was created by somebody else.

Open it and check:

1. the area name;
2. the selected coverage;
3. Accra;
4. Tema;
5. one location that should not belong to this delivery area.

If it is safe, save the form without changing the intended setup.

### Expected

- The correct places are covered.
- A place that should not be covered does not accidentally match.
- The area saves correctly.
- The screen is understandable to normal staff.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-AREA-002 — Check included and excluded places

Use an existing Delivery Area that includes or excludes selected locations.

Test one place that should be included and one that should be excluded.

### Expected

The plugin follows the rule exactly.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 13. Delivery Options

## DE-RAPID-OPTION-001 — Check a Delivery Option

Open the Delivery Option being used for this test, for example:

**Standard Delivery**

Check:

- its public name;
- whether it is enabled;
- its delivery time/ETA settings;
- how it appears to the customer.

### Expected

The name and delivery time are understandable to customers.

Do not leave the option disabled if you temporarily test disabling it.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 14. Delivery Charges

## DE-RAPID-CHARGE-001 — Check the delivery price

Open the Delivery Charge being used for this test.

Write down:

- Delivery Area;
- Delivery Option;
- currency;
- amount.

You will compare this amount with the product page, cart, and checkout later.

### Expected

The saved amount is correct.

A real zero amount must mean zero/free where intentionally configured.

A missing amount must not be treated as free delivery.

**Expected delivery charge:**  

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 15. Site-wide Defaults

## DE-RAPID-DEFAULT-001 — Check the main/default settings

Open Site-wide Defaults.

Use the current shared test setup.

Do not change the shared settings merely to make your test different.

### Check

- the settings are understandable;
- a normal product can use the default settings;
- a product only needs special settings when it is genuinely different.

### Expected

The normal rule should behave like:

**Site-wide settings → product changes → variation changes**

A product or variation should only replace the specific setting that was changed.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 16. Simple product test

## DE-RAPID-PRODUCT-001 — Check one simple product

Use the nominated simple test product.

Record:

**Product name:**  
**Product ID/SKU:**

Open its Delivery Engine settings.

Check whether it uses the Site-wide Defaults or has its own changes.

Then open the same product on the customer-facing website.

### Expected

The product receives the correct delivery setup.

If the product changes only one delivery setting, unrelated settings should still come from the Site-wide Defaults.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 17. Variable product test

## DE-RAPID-VARIATION-001 — Check two variations

Use the nominated variable product.

Test at least two variations.

### Steps

1. Choose variation A.
2. Check its delivery option, price, and ETA.
3. Change to variation B.
4. Check the delivery information again.
5. Change back to variation A.

### Expected

The delivery result follows the currently selected variation.

Information from the previous variation must not remain by mistake.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 18. Product page customer test

## DE-RAPID-PDP-001 — Test delivery on a product page

Open the nominated simple product as a customer.

### Steps

1. Select **Ghana**.
2. Select **Greater Accra**.
3. Select **Accra**.
4. Select the test Delivery Option.
5. Write down the delivery price.
6. Write down the estimated delivery time.
7. Change the location to **Tema**.
8. Confirm the delivery information updates.
9. Return to the intended location.
10. Add the product to cart.

### Expected

The customer sees:

- the correct Delivery Option;
- the correct delivery price;
- a clear delivery time/ETA;
- a working Add to Cart button.

### The customer must NOT see internal information such as

- supplier name;
- internal warehouse/origin details;
- internal logistics IDs;
- internal rate-card information;
- priority numbers;
- technical database IDs.

### Result

**Result:**  
**Delivery price shown:**  
**ETA shown:**  
**Evidence:**  
**Notes:**

---

# 19. Cart test

## DE-RAPID-CART-001 — Check that delivery information stays correct

Use a fresh cart created by you.

### Steps

1. Add the test product.
2. Open the cart.
3. Go to another page.
4. Return to the cart.
5. Refresh the page.
6. Change the quantity if safe.
7. Continue to checkout.

### Expected

The customer's delivery choice should remain correct.

If something changed that makes the old delivery choice invalid, the plugin should check again and ask for a valid choice instead of silently using old information.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 20. Checkout test

## DE-RAPID-CHECKOUT-001 — Check the WooCommerce shipping charge

Proceed to checkout.

Use a complete test shipping address that matches the selected destination.

Compare these four amounts:

| Place | Amount |
| --- | ---: |
| Delivery Charge in admin | |
| Product page | |
| Cart | |
| Checkout | |

### Expected

The amounts should match unless there is a clear, legitimate rule that explains the difference.

The delivery fee must appear as a normal **WooCommerce shipping charge**.

It must not:

- be hidden inside the product price;
- appear twice;
- appear as a random product fee;
- become zero without a valid reason.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 21. Place a fresh test order

## DE-RAPID-ORDER-001 — Complete checkout

Every tester should create a fresh test order for this test.

Use an approved safe payment method.

Cash on Delivery is acceptable for the rapid test.

If Paystack test mode is being specifically tested for the Delivery Engine, it may be used. Do not spend this test session changing payment-gateway settings.

### Expected

- Checkout completes.
- The correct delivery fee is charged.
- No Delivery Engine error appears.
- A new WooCommerce order is created.

**New order number:**  

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 22. Check the completed order

## DE-RAPID-ORDER-002 — Check the delivery information saved with the order

Open the new order you just created.

Check:

- selected Delivery Option;
- delivery amount;
- customer destination;
- delivery time/ETA where shown;
- shipment information where appropriate.

Also check the customer Thank You page or My Account order page if available.

### Expected

The order should show the same delivery choice and price that the customer agreed to at checkout.

The customer must not see private supplier/origin/internal logistics information.

There should not be duplicate delivery charges or duplicate shipping summaries.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 23. Pickup test

## DE-RAPID-PICKUP-001 — Test Store Pickup

Use the nominated pickup-enabled product.

### Steps

1. Open the product.
2. Choose Store Pickup.
3. Select the pickup location if asked.
4. Add the product to cart.
5. Continue far enough through checkout to confirm pickup remains correct.

### Expected

- Pickup is available only when allowed.
- Free pickup should clearly show Free or GH₵0 where intended.
- A real zero price must work correctly.
- Pickup must not create a fake delivery-to-address charge.
- Pickup-only orders should not create a false delivery shipment.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 24. Unsupported location test

## DE-RAPID-FAIL-001 — Try a location that should not be supported

Use the nominated unsupported location.

Do not damage or delete the working Delivery Area to create this test.

### Expected

The Delivery Engine should clearly say that the delivery is unavailable or should refuse to continue with that invalid delivery choice.

### Critical failure

If the plugin cannot find a valid delivery price but quietly gives the customer **free delivery**, report this immediately as **Critical**.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 25. Missing delivery price test

## DE-RAPID-FAIL-002 — Test a product with no valid delivery price

Use the nominated intentionally unconfigured product or another approved test case that has no valid delivery price.

Do not delete a working Delivery Charge to create the test.

### Expected

No valid delivery price should mean:

- the delivery option is unavailable; or
- the customer is clearly told that delivery cannot currently be quoted.

It must **not** silently turn into free delivery.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 26. Shipment check

## DE-RAPID-SHIP-001 — Check the shipment for your new order

Use the fresh order you created.

Open the Delivery Engine shipment area.

### Check

- whether a shipment should exist for this order;
- whether exactly the correct number of shipments exists;
- whether the products are grouped correctly;
- whether customer-visible shipment information is safe and understandable.

### Expected

- No duplicate shipment.
- Delivery orders create the expected shipment when the order/payment state requires it.
- Pickup-only orders do not create a fake delivery shipment.

If the order is in a state where a shipment should not yet exist, that may be correct. Record what happened.

**Shipment number(s):**

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 27. Needs Attention check

## DE-RAPID-ATTENTION-001 — Check Needs Attention

Open **Needs Attention**.

Do not expect the original count of about 75 to still be there.

Testing and configuration may already have changed it.

### Check

- genuinely unconfigured products are still reported;
- products that were correctly configured are updated appropriately;
- the count changes sensibly;
- the page opens without errors.

### Result

**Count shown:**  
**Result:**  
**Evidence:**  
**Notes:**

---

# 28. Mobile and WoodMart check

## DE-RAPID-UX-001 — Quick mobile/customer display check

Use a real phone if possible. Otherwise use your browser's mobile view.

### Test

1. Open the test product.
2. Select a location.
3. Select a Delivery Option.
4. Check the delivery price and ETA.
5. Add to cart.
6. Open cart.
7. Open checkout.

### Expected

- nothing important overlaps;
- text is readable;
- buttons can be pressed;
- location fields work;
- delivery cards work;
- delivery price is easy to see;
- ETA is easy to understand;
- the WoodMart theme does not hide or break the Delivery Engine.

This is only a quick check today. It is not full mobile/accessibility certification.

### Result

**Result:**  
**Evidence:**  
**Notes:**

---

# 29. Error and log check

## DE-RAPID-LOG-001 — Check for new errors

At the end of your test:

1. write down the finish time;
2. check available WordPress, PHP, or WooCommerce logs if you have access;
3. look for errors that happened during your test.

### Fail this test if your test caused

- a new Delivery Engine fatal error;
- an uncaught exception;
- repeated Delivery Engine warnings that affected the test;
- a WooCommerce critical error caused by the Delivery Engine.

### Result

**Result:**  
**Log time:**  
**Evidence:**  
**Notes:**

---

# 30. Optional tests if there is time

Only do these after the main rapid tests:

- Cart/Checkout Blocks;
- Ashanti / Kumasi;
- another Greater Accra locality;
- International product using Air or Sea;
- cart with several products;
- different destinations for different products;
- Paystack test-mode order specifically for Delivery Engine compatibility;
- second browser.

---

# 31. What not to spend time on today

Do not spend today's rapid-test window trying to complete:

- every Ghana locality;
- every product variation;
- full Bulk Tools testing;
- every WordPress role;
- every plugin combination;
- full WPML/WCML testing;
- full B2BKing testing;
- full FOX/WOOCS testing;
- WP Rocket testing;
- full accessibility certification;
- security penetration testing;
- load/performance certification;
- carrier API testing;
- driver app testing;
- OTP/QR/GPS/photo Proof of Delivery;
- unrelated POS work.

Those belong in the full testing programme.

---

# 32. Final tester sign-off

Complete this at the end.

| Question | Answer |
| --- | --- |
| Could you understand and use the Delivery Engine without developer help? | YES / NO / PARTLY |
| Did Ghana → Greater Accra → Accra/Tema work correctly? | YES / NO / PARTLY |
| Could you get a valid delivery option, price and ETA on the product page? | YES / NO |
| Did the delivery price remain correct in cart and checkout? | YES / NO |
| Was the delivery price shown as a real WooCommerce shipping charge? | YES / NO |
| Did the new order keep the correct delivery information? | YES / NO |
| Was private internal delivery information hidden from the customer? | YES / NO |
| Did unsupported/missing delivery fail safely instead of becoming free delivery? | YES / NO |
| Did Store Pickup work correctly? | YES / NO / NOT TESTED |
| Was shipment behavior correct? | YES / NO / NOT APPLICABLE |
| Did you see any new fatal/critical Delivery Engine error? | YES / NO |

## Final recommendation

Choose one:

### PASS
No release-blocking problem found.

### PASS WITH ISSUES
The main flow works, but I found non-blocking problems that should be fixed.

### HOLD
A Major problem should be fixed and tested again before proceeding.

### STOP
A Critical problem was found.

**Most important problem found:**  

**Tester name:**  

**Finish time:**  

---

# 33. Defect and test report templates

Use:

- [Defect Report Template](./DEFECT-REPORT-TEMPLATE.md)
- [Rapid Test Run Report](./TEST-RUN-REPORT-TEMPLATE.md)

This Rapid Test Guide is only the short testing pack for the current campaign.

A larger **Delivery Engine Testing Guide v1.0** will later cover the complete test programme, including compatibility, roles/security, Bulk Tools, full failure/recovery testing, accessibility, full regression, and release sign-off.
