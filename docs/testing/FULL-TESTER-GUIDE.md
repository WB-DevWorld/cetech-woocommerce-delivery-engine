# Delivery Engine Full Testing Guide v1.0

This is the main, permanent tester-facing guide for the CETECH WooCommerce Delivery Engine.

It is written for staff testers and business users. You do **not** need to understand the plugin's internal code.

## Table of Contents

1. [Purpose](#1-purpose)
2. [Who should use this guide](#2-who-should-use-this-guide)
3. [What can be skipped](#3-what-can-be-skipped)
4. [How the full campaign works](#4-how-the-full-campaign-works)
5. [Result and severity words](#5-result-and-severity-words)
6. [Evidence requirements](#6-evidence-requirements)
7. [Standard test data](#7-standard-test-data)
8. [Complete testing order](#8-complete-testing-order)
9. [Module guides](#9-module-guides)
10. [Final regression](#10-final-regression)
11. [Release sign-off](#11-release-sign-off)

---

# 1. Purpose

The Full Testing Guide answers a broader question than the Rapid Test Pack:

> Is this Delivery Engine build safe, understandable, correct, and usable across the full set of features we intend to rely on?

The full campaign covers staff setup, customer use, cart/checkout, order history, shipments, failure handling, roles/security, compatibility, responsive behavior, and final release evidence.

---

# 2. Who should use this guide

Use this guide when:

- testing before a production pilot;
- qualifying a release candidate;
- retesting after an important fix;
- testing a new WooCommerce/theme/plugin combination;
- preparing commercial release evidence;
- performing a complete regression.

For a short time-boxed session, use the [Rapid Test Guide](./RAPID-TEST-GUIDE-2026-09-24.md).

---

# 3. What can be skipped

Only these may be confirmation-only if already completed by an authorized tester:

1. the original **clean baseline**;
2. the **Setup Guide** completion state.

Everything else must be tested.

Do not assume configuration already exists.

For each functional section:

- create the minimum setup if it does not exist;
- reuse the campaign setup if an earlier tester already created it;
- do not create unnecessary duplicates;
- still perform the test yourself.

---

# 4. How the full campaign works

The campaign should progress in order.

Early sections create the test setup. Later sections depend on it.

Do not jump straight to checkout before proving the Delivery Area, Delivery Option, Delivery Charge, Site-wide Defaults and product configuration used by checkout.

When several testers are involved, one tester may create the shared campaign setup. Other testers may reuse it, but each tester must still run the functional cases assigned to them.

---

# 5. Result and severity words

## Results

- **PASS** — the test was performed and worked correctly.
- **FAIL** — the test was performed and did not work correctly.
- **BLOCKED** — another problem prevented the test.
- **NOT TESTED** — the test was not performed.

For Baseline and Setup Guide only:

- **CONFIRMED — ALREADY COMPLETED**

## Severity

- **P0 / Critical** — fatal error, wrong money charged, data loss/corruption, checkout impossible, silent free delivery caused by missing configuration, serious privacy/security exposure.
- **P1 / Major** — important customer/staff workflow gives the wrong result or cannot be completed.
- **P2 / Moderate** — meaningful defect with a workaround.
- **P3 / Minor** — cosmetic, wording, layout or low-risk usability problem.

---

# 6. Evidence requirements

Every failed case should capture:

- Test ID
- tester name and role
- date/time
- page/URL
- product and SKU/ID
- variation where relevant
- quantity
- Country / Region / Locality / Postcode
- Delivery Option
- configured Delivery Charge
- exact steps
- expected result
- actual result
- screenshot/video
- cart/order/shipment ID where available
- whether it reproduces

Use the [Defect Report Template](./DEFECT-REPORT-TEMPLATE.md).

---

# 7. Standard test data

Use the shared campaign data in [TEST-DATA.md](./TEST-DATA.md).

The goal is to make tester results comparable.

Do not make every tester invent different products, locations and settings unless the case specifically requires it.

---

# 8. Complete testing order

Follow this order:

1. Clean baseline
2. Admin and setup
3. Location Packs
4. Delivery Areas
5. Delivery Options
6. Delivery Charges
7. Site-wide Defaults and inheritance
8. Product and variation exceptions
9. Fulfilment types
10. Pickup
11. Simple-product storefront
12. Variable products
13. Cart persistence/revalidation
14. Multi-item and multi-destination
15. Classic checkout
16. Cart/Checkout Blocks
17. Payment lifecycle
18. Order snapshots
19. Shipments
20. Tracking/customer presentation
21. Needs Attention
22. Bulk Tools
23. Roles/security
24. Compatibility
25. Responsive/browser/accessibility basics
26. Failure/recovery
27. Final regression
28. Logs and sign-off

---

# 9. Module guides

Use these guides in order:

- [01 — Clean Baseline](./01-CLEAN-BASELINE.md)
- [02 — Admin and Setup](./02-ADMIN-AND-SETUP.md)
- [03 — Location Packs](./03-LOCATION-PACKS.md)
- [04 — Delivery Areas](./04-DELIVERY-AREAS.md)
- [05 — Delivery Options](./05-DELIVERY-OPTIONS.md)
- [06 — Delivery Charges](./06-DELIVERY-CHARGES.md)
- [07 — Inheritance and Exceptions](./07-INHERITANCE-AND-EXCEPTIONS.md)
- [08 — Storefront](./08-STOREFRONT.md)
- [09 — Cart and Checkout](./09-CART-AND-CHECKOUT.md)
- [10 — Multi-Destination](./10-MULTI-DESTINATION.md)
- [11 — Orders and Snapshots](./11-ORDERS-AND-SNAPSHOTS.md)
- [12 — Shipments and Tracking](./12-SHIPMENTS-AND-TRACKING.md)
- [13 — Needs Attention](./13-NEEDS-ATTENTION.md)
- [14 — Bulk Tools](./14-BULK-TOOLS.md)
- [15 — Roles and Security](./15-ROLES-AND-SECURITY.md)
- [16 — Compatibility](./16-COMPATIBILITY.md)
- [17 — Failure and Recovery](./17-FAILURE-AND-RECOVERY.md)
- [18 — Final Regression](./18-FINAL-REGRESSION.md)

Use [TEST-CASE-INDEX.md](./TEST-CASE-INDEX.md) to find an individual stable test ID.

---

# 10. Final regression

Do not recommend release based only on individual module tests.

The final regression must prove one complete real journey:

**staff configuration → product → customer location → delivery option → authoritative price/ETA → cart → checkout → payment state → order → shipment → customer presentation**

It must also include at least one deliberate unsupported/missing configuration case to prove fail-closed behavior.

---

# 11. Release sign-off

A release-quality test campaign should end with:

- total PASS / FAIL / BLOCKED / NOT TESTED;
- P0/P1/P2/P3 defect counts;
- unresolved defect list;
- tested WordPress/WooCommerce/theme/plugin versions;
- exact Delivery Engine version;
- test order/shipment IDs;
- log time window;
- tester recommendation.

Use the [Test Run Report Template](./TEST-RUN-REPORT-TEMPLATE.md).

A tester may recommend:

- **PASS**
- **PASS WITH ISSUES**
- **HOLD**
- **STOP**

The tester recommendation is evidence for the owner/release decision. It does not itself change the release or deploy anything.
