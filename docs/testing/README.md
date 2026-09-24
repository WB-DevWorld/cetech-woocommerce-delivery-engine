# Delivery Engine Testing

## Rapid Test Pack v0.1 — 24 September 2026

**Purpose:** time-boxed physical QA for the CETECH WooCommerce Delivery Engine before broader testing begins.

**Primary release under test:** `1.0.0-rc.12`  
**Schema:** `6`  
**Release source:** `78594ad8962868683726373f58f4a8b1b48e4d0e`  
**Target:** find release-blocking defects quickly, validate the most important staff/customer journeys, and collect reproducible evidence.

This page is the **single tester entry point**. Testers should work from this page rather than inventing their own configuration or test order.

---

# 1. Critical rule: what may be skipped and what must be repeated

## Baseline and Setup Guide only

The **Baseline** and **Setup Guide** sections are the only sections that may be treated as already completed.

If those were already completed by the main tester or another authorized tester:

- do **not** reset the plugin;
- do **not** delete configuration just to reproduce a clean state;
- do **not** re-run a setup wizard merely to make the test look fresh;
- confirm the current state is consistent with the recorded baseline/setup outcome;
- record **CONFIRMED — PREVIOUSLY COMPLETED** or **CONFIRMED — CURRENT STATE**;
- continue immediately to the functional tests.

## Every functional test after Baseline / Setup Guide must be run by every tester

An existing Delivery Option, Delivery Area, Delivery Charge, product exception, pickup location, order, or other configuration is **not a reason to skip a functional test**.

Later testers should use the existing configuration as test data and execute the test again.

Examples:

- If **Greater Accra** already exists, do not skip the Delivery Area tests. Open it, exercise the relevant controls, and test its storefront behavior again.
- If **Standard Delivery** already exists, do not skip Delivery Option tests. Verify it, use it in a quote, cart, and checkout again.
- If a simple or variable product was already configured, use it and repeat the customer journey.
- If a previous tester already placed an order, place another test order when the case requires an order. A previous PASS is evidence, not a substitute for your test.

**Only Baseline and Setup Guide can be confirmation-only. Everything else is repeatable physical QA.**

---

# 2. Do not alter these campaign assumptions

## Ghana Location Pack

The Ghana Location Pack is already installed and is expected to be **Ready**.

**DO NOT DOWNLOAD OR REINSTALL THE GHANA LOCATION PACK.**

If Ghana is unexpectedly missing, Failed, or not Ready:

1. take a screenshot;
2. record the exact status;
3. mark affected tests **BLOCKED**;
4. do not reinstall it unless separately instructed.

## Initial clean-slate campaign baseline

The campaign was originally reset to approximately:

| Item | Initial campaign baseline |
| --- | --- |
| Ghana Location Pack | Ready |
| Delivery Options | 0 |
| Delivery Areas | 0 |
| Delivery Charges | 0 |
| Pickup Locations | 0 |
| Product / Variation Exceptions | 0 |
| Shipments | 0 |
| Bulk history | 0 |
| Site-wide configuration | Empty |
| Needs Attention | May be high because products genuinely have no delivery configuration |

A previously observed clean-state Needs Attention catalog count was around **75**.

These are the **initial campaign baseline facts**, not values that every later tester must restore.

If previous testing has already created configuration, later testers should expect counts to have changed.

---

# 3. Tester information

Fill this before starting.

| Field | Tester fills |
| --- | --- |
| Tester name | |
| Date | 24 September 2026 |
| Start time | |
| Finish time | |
| Site URL | |
| WordPress user / role | |
| Browser | |
| Device | |
| Plugin version shown | |
| Schema shown, if visible | |
| Test run ID | e.g. RAPID-20260924-JD-01 |

---

# 4. Result and severity vocabulary

Use only these test results:

- **PASS**
- **FAIL**
- **BLOCKED**
- **NOT TESTED**
- **CONFIRMED — PREVIOUSLY COMPLETED** — Baseline / Setup Guide only
- **CONFIRMED — CURRENT STATE** — Baseline / Setup Guide only

Severity for failures:

- **P0 / Critical** — fatal error, checkout impossible, wrong money charged, data corruption, silent free delivery from missing configuration, serious privacy/security exposure.
- **P1 / Major** — core workflow fails, wrong eligibility, wrong delivery option/price/ETA, cart/checkout state lost, shipment/order result materially wrong.
- **P2 / Moderate** — meaningful UX/operational defect with workaround.
- **P3 / Minor** — cosmetic, wording, alignment, low-risk usability defect.

---

# 5. Evidence rule

When something fails, capture evidence **before changing the state again**:

1. exact page / URL;
2. tester user/role;
3. product and product ID/SKU;
4. variation if applicable;
5. selected Country / Region / Locality / Postcode;
6. Delivery Option;
7. quantity;
8. configured Delivery Charge if relevant;
9. exact action performed;
10. expected result;
11. actual result;
12. screenshot or short video;
13. time of failure;
14. cart/order/shipment ID if one exists;
15. whether the failure reproduces.

Do not report only: **"delivery is not working."**

Use the [Defect Report Template](./DEFECT-REPORT-TEMPLATE.md).

---

# 6. Standard campaign test data

The first tester or test coordinator should fill these once. Later testers should reuse them unless a case explicitly requires different data.

| Purpose | Product / ID / SKU |
| --- | --- |
| Simple In-Store product | |
| Simple In-Warehouse product | |
| Variable product | |
| International product | |
| Pickup-enabled product | |
| Product with product override | |
| Variation with variation override | |
| Intentionally unconfigured product | |
| Multi-quantity product | |
| Multi-destination product A | |
| Multi-destination product B | |

Standard geography:

- Ghana → Greater Accra → Accra
- Ghana → Greater Accra → Tema
- Ghana → Greater Accra → one other locality
- Ghana → Ashanti → Kumasi
- one deliberately unsupported destination

Do not create random alternatives when the campaign already has nominated test data.

---

# 7. Time-boxed order for today's rapid pass

If there is limited time, execute in this order:

| Priority | Area |
| --- | --- |
| 1 | Baseline / Setup Guide confirmation |
| 2 | Location Pack and geography |
| 3 | Delivery Area |
| 4 | Delivery Option |
| 5 | Delivery Charge |
| 6 | Site-wide Defaults / inheritance |
| 7 | Simple product storefront |
| 8 | Variable product |
| 9 | Cart persistence / revalidation |
| 10 | Classic checkout / genuine WooCommerce shipping |
| 11 | Order snapshot / customer presentation |
| 12 | Pickup |
| 13 | Unsupported / missing-rate fail-closed |
| 14 | Shipment sanity |
| 15 | Needs Attention |
| 16 | Mobile / WoodMart visual sanity |
| 17 | Logs and sign-off |

If the tester cannot complete all cases before the deadline, mark the remainder **NOT TESTED**. Do not convert untested cases into PASS.

---

# 8. BASELINE — confirmation-only allowed

## DE-RAPID-BASE-001 — Release identity

Confirm the installed test target.

**Expected**

- Version: `1.0.0-rc.12`
- Schema: `6`, where visible/applicable

If a different build is installed, record the exact build and notify the test coordinator before treating version-specific differences as defects.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-BASE-002 — Initial reset history / current campaign state

If the clean reset was already confirmed by an authorized tester, do not reset again.

Confirm only that the current configuration state is plausible given testing already performed.

The original clean campaign state was the table in Section 2.

**PASS / confirmation means:** no unexplained configuration appears and current counts can be explained by prior campaign work.

**Do not fail** merely because Delivery Options / Areas / Charges are no longer zero after testing has begun.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-BASE-003 — Ghana Location Pack

Open Location Packs.

**Expected:** Ghana is **Ready**.

Confirm that Greater Accra can be found.

**Do not download Ghana again.**

**Result:**  
**Evidence:**  
**Notes:**

---

# 9. SETUP GUIDE — confirmation-only allowed

## DE-RAPID-SETUP-001 — Setup Guide state

If the Setup Guide has already been completed:

- confirm its completed/appropriate state;
- do not restart it;
- do not wipe configuration;
- record **CONFIRMED — PREVIOUSLY COMPLETED**;
- move on.

If it has not been completed, follow the visible Setup Guide normally and record anything confusing, contradictory, inaccessible, or broken.

**Result:**  
**Evidence:**  
**Notes:**

---

# 10. FUNCTIONAL TESTS — every tester must repeat these

From this point onward, **no test may be skipped merely because another tester already passed it or because the configuration already exists.**

Existing objects should normally be reused as controlled fixtures.

---

## DE-RAPID-GEO-001 — Geography hierarchy

Open the relevant geography/location controls.

Confirm the tester can navigate/search:

1. Ghana
2. Greater Accra
3. Accra
4. Tema

Also confirm Ashanti → Kumasi if time permits.

**Expected**

- hierarchy is sensible;
- locations are discoverable;
- no obvious duplicate Greater Accra hierarchy;
- no confusing internal identifiers are required from ordinary staff.

**FAIL if**

- valid locality cannot be found;
- locality is under the wrong region;
- duplicate/ambiguous canonical records prevent normal use;
- internal IDs are exposed as normal customer/staff labels.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-AREA-001 — Delivery Area administration

Use the existing Greater Accra Delivery Area if already created.

Do **not** mark PASS because it exists.

Exercise the area:

1. open it;
2. confirm its coverage mode and selected geography;
3. save a harmless/no-op confirmation if the UI supports it safely;
4. confirm Accra coverage;
5. confirm Tema coverage;
6. confirm one locality that should not match does not accidentally match.

If the campaign specifically requires a create test and no safe temporary QA area exists, create a clearly named temporary QA area and remove it after evidence is captured.

**Expected**

- area remains valid after save;
- intended descendants are available;
- include/exclude semantics are understandable;
- unsupported geography does not silently match.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-AREA-002 — Include / exclude behavior

Using the campaign Delivery Area, exercise one configured include/exclude or Entire Area / Selected Locations / Entire Area Except behavior.

**Expected:** effective coverage matches what staff configured.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-OPTION-001 — Delivery Option

Open the campaign Delivery Option, such as Standard Delivery.

Every tester must verify it again.

Check:

- public name;
- enabled state;
- ETA/timeframe configuration;
- public presentation;
- disable/enable behavior only if doing so will not disrupt another tester.

Do not leave the option disabled.

**Expected:** the option is usable, clearly named, and customer-safe.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-RATE-001 — Delivery Charge

Open the campaign Delivery Charge / Rate Card.

Record:

- area;
- Delivery Option;
- currency;
- configured amount.

Then use that same configuration in storefront/cart/checkout later.

**Expected:** configured value saves and is not confused with missing configuration.

**Configured expected amount:**  
**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-DEFAULT-001 — Site-wide Defaults

Open Site-wide Defaults.

Every tester should inspect the active global configuration and confirm that a normal inheriting product receives the intended values.

Do not overwrite the shared campaign defaults simply to create a unique tester state.

**Expected:** effective configuration follows the current inheritance model and is understandable to staff.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-INHERIT-001 — GLOBAL → PRODUCT

Use the nominated simple product.

1. open the product delivery settings;
2. identify inherited values;
3. identify any product override;
4. save only if necessary;
5. open the storefront product page;
6. verify the effective behavior.

**Expected:** product inheritance is correct and no unrelated inherited field disappears because one field is overridden.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-INHERIT-002 — PRODUCT → VARIATION

Use the nominated variable product and variation.

1. select the parent product;
2. inspect the variation;
3. identify the variation override;
4. view that variation on the storefront;
5. switch to another variation;
6. switch back.

**Expected**

- variation-specific delivery state is correct;
- no stale price/ETA/location/eligibility leaks from the previous variation;
- parent values continue to inherit where not overridden.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-PDP-001 — Simple product customer journey

Open the nominated simple product as a customer.

1. select Ghana;
2. select Greater Accra;
3. select Accra;
4. select the configured Delivery Option;
5. note the delivery fee;
6. note the ETA;
7. change to Tema and observe recalculation;
8. return to the intended destination;
9. add to cart.

**Expected**

- correct option;
- correct authoritative delivery fee;
- configured/current ETA;
- Add to Cart succeeds;
- customer does not see supplier/origin/internal logistics IDs, priorities, rate-card internals, or other private data.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-PDP-002 — Variable product recalculation

Use the nominated variable product.

Repeat destination selection and switch between at least two variations.

**Expected:** eligibility, fee and ETA reflect the currently selected variation.

**FAIL if:** stale data from another variation remains authoritative.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-CART-001 — Cart persistence

Using a fresh cart created by this tester:

1. add the configured product;
2. open cart;
3. navigate to another page;
4. return to cart;
5. refresh;
6. change quantity if safe;
7. proceed toward checkout.

**Expected**

- the selected delivery context persists where valid;
- changed quantity/state is revalidated;
- stale invalid configuration is not blindly trusted.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-CHECKOUT-001 — Genuine WooCommerce shipping

Proceed through Classic checkout if available.

Enter a complete test shipping address corresponding to the selected destination.

Compare:

- configured Delivery Charge;
- product-page delivery fee;
- cart shipping;
- checkout shipping.

**Expected:** the amounts agree unless a documented rule legitimately changes the quote.

The Delivery Engine charge must appear as a **genuine WooCommerce shipping charge**, not:

- a merchandise-price mutation;
- a hidden product add-on;
- a duplicate fee;
- an unexplained zero.

Record:

**Configured charge:**  
**PDP charge:**  
**Cart shipping:**  
**Checkout shipping:**

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-CHECKOUT-002 — Complete a test order

Complete a fresh test order for this tester using an authorized safe payment method.

COD is acceptable for rapid Delivery Engine testing.

Paystack test mode may be used only if the test coordinator intends to exercise the Delivery Engine through Paystack; do not spend the rapid window reconfiguring payment gateways.

Record the **new order ID**.

**Expected:** checkout completes without Delivery Engine fatal/error and the shipping amount is correct.

**Order ID:**  
**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-ORDER-001 — Order snapshot / purchase-time facts

Open the order created by this tester.

Verify:

- selected Delivery Option;
- shipping amount;
- customer destination;
- ETA/promise information where applicable;
- delivery group/shipment information where applicable.

Check customer-facing Thank You / My Account presentation where available.

**Expected**

- purchase-time delivery facts match checkout;
- customer-safe information is present;
- private supplier/origin/logistics data is not exposed;
- no duplicate delivery charge/shipping summary.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-PICKUP-001 — Pickup

Use the nominated pickup-enabled product.

Every tester should execute the customer flow again.

1. open product;
2. choose pickup;
3. select the intended pickup location if required;
4. add to cart;
5. proceed far enough to verify checkout/order behavior.

**Expected**

- pickup is selectable only where valid;
- genuine zero/free pickup displays correctly;
- zero does not mean missing configuration;
- pickup-only fulfilment does not create a false delivery-to-address shipment.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-FAIL-001 — Unsupported destination

Use a deliberately unsupported destination without changing the campaign's valid area/rate configuration.

**Expected:** unavailable delivery fails closed with a clear customer outcome.

**P0 / Critical:** missing/unconfigured delivery silently becomes free delivery and checkout can continue as if valid.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-FAIL-002 — Missing-rate safety

Use the nominated intentionally unconfigured product or an authorized test condition that has no valid matching rate.

Do not damage the shared working Rate Card to create the condition.

**Expected:** no valid rate means no silently free shipping.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-SHIP-001 — Shipment sanity

Using the order created by this tester, inspect shipment behavior appropriate to its payment/fulfilment state.

**Expected**

- shipment creation occurs only when appropriate;
- no duplicate shipment is produced;
- grouping is sensible;
- customer-safe information matches the order;
- pickup-only flow does not create a false delivery shipment.

If payment state intentionally prevents shipment creation, verify that this is expected rather than failing the case automatically.

**Shipment ID(s):**  
**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-ATTN-001 — Needs Attention

Open Needs Attention.

Do not compare blindly with the original clean-state count of ~75.

Instead verify that:

- currently unresolved products/configurations appear truthfully;
- resolved/configured conditions update appropriately;
- obvious stale or impossible counts are not shown;
- the page loads without error.

**Count observed:**  
**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-UX-001 — Mobile / WoodMart rapid visual pass

On a real phone or responsive browser:

1. open the test product;
2. use the location controls;
3. select delivery;
4. inspect fee/ETA;
5. add to cart;
6. view cart/checkout.

**Expected**

- no broken/overlapping fields;
- fee and ETA remain readable;
- delivery cards remain selectable;
- location controls remain usable;
- mobile layout does not require impossible horizontal scrolling;
- WoodMart does not hide/break core Delivery Engine controls.

This is rapid visual QA, not full accessibility or cross-browser certification.

**Result:**  
**Evidence:**  
**Notes:**

---

## DE-RAPID-LOG-001 — Error/log check

At the end of the tester's run:

1. note the test end time;
2. inspect available PHP/WooCommerce logs if permitted;
3. correlate any errors to the test timestamps.

**FAIL if:** the run produced a new Delivery Engine fatal, uncaught exception, or repeated runtime warning affecting tested behavior.

**Result:**  
**Evidence / log time:**  
**Notes:**

---

# 11. Optional tests if time remains

Only after the core rapid tests:

- Cart/Checkout Blocks parity;
- Ashanti / Kumasi;
- additional Greater Accra locality;
- International Air/Sea product;
- multi-item order;
- multi-destination order;
- a safe Paystack test-mode Delivery Engine checkout;
- a second browser.

These are valuable, but do not sacrifice the core sale path to reach them.

---

# 12. Do not spend today's rapid window on

- exhaustive Ghana locality enumeration;
- every variation combination;
- full Bulk Tools qualification;
- exhaustive role/security matrix;
- all compatibility combinations;
- WPML/WCML certification;
- B2BKing/FOX full certification;
- WP Rocket certification;
- full accessibility certification;
- penetration testing;
- performance/load certification;
- carrier APIs;
- driver app;
- POD/OTP/QR/GPS/photo workflows;
- unrelated POS work.

Those belong in the comprehensive Testing Guide v1.0.

---

# 13. Rapid sign-off

Use the [Test Run Report Template](./TEST-RUN-REPORT-TEMPLATE.md) or record the same information below.

| Question | Result |
| --- | --- |
| Can staff operate the configured Delivery Engine without developer knowledge? | YES / NO / PARTLY |
| Does Ghana → Greater Accra → Accra/Tema behave correctly? | YES / NO / PARTLY |
| Can a shopper obtain a valid delivery option, price and ETA? | YES / NO |
| Does the correct delivery charge survive PDP → cart → checkout? | YES / NO |
| Is it a genuine WooCommerce shipping charge? | YES / NO |
| Does the new order preserve the correct delivery facts? | YES / NO |
| Is customer/private-data separation correct? | YES / NO |
| Does unsupported/missing-rate delivery fail closed? | YES / NO |
| Does pickup behave correctly? | YES / NO / NOT TESTED |
| Are shipments sane/idempotent for the tested order? | YES / NO / NOT APPLICABLE |
| Any new fatal/critical Delivery Engine errors? | YES / NO |

Tester recommendation:

- **PASS — no release-blocking defect found**
- **PASS WITH ISSUES — no blocker, defects recorded**
- **HOLD — P1/Major defect requires repair/retest**
- **STOP — P0/Critical defect found**

---

# 14. Full testing system

This rapid pack is intentionally time-boxed.

The next version-controlled testing system should expand under `docs/testing/` into the broader structure already planned for:

- clean baseline;
- admin/setup;
- location packs;
- Delivery Areas;
- Delivery Options;
- Delivery Charges;
- inheritance/exceptions;
- storefront;
- cart/checkout;
- multi-destination;
- orders/snapshots;
- shipments/tracking;
- Needs Attention;
- Bulk Tools;
- roles/security;
- compatibility;
- failure/recovery;
- final regression;
- defect and test-run evidence.

Do not treat today's Rapid Pack as full Stable-1.0 certification.
