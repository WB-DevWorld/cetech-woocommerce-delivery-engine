# Delivery Engine Testing Portal

This is the single starting point for Delivery Engine testers.

You do **not** need to understand the plugin's internal code. Choose the testing path that matches the time and purpose of your test.

## Which guide should I use?

| If you are... | Use |
| --- | --- |
| Testing today with limited time | [Rapid Test Guide — 24 September 2026](./RAPID-TEST-GUIDE-2026-09-24.md) |
| Performing the complete Delivery Engine test campaign | [Full Testing Guide v1.0](./FULL-TESTER-GUIDE.md) |
| Reporting a problem | [Defect Report Template](./DEFECT-REPORT-TEMPLATE.md) |
| Submitting the result of a test session | [Test Run Report Template](./TEST-RUN-REPORT-TEMPLATE.md) |
| Looking for test products/locations/accounts | [Test Data](./TEST-DATA.md) |
| Checking the test environment | [Test Environment](./TEST-ENVIRONMENT.md) |
| Looking for a specific test area | [Test Case Index](./TEST-CASE-INDEX.md) |

---

## Current release under test

- **Version:** `1.0.0-rc.12`
- **Schema:** `6`
- **Release source:** `78594ad8962868683726373f58f4a8b1b48e4d0e`

The release candidate is not Stable 1.0.

---

## Important rule about the starting state

Only the **Baseline** and **Setup Guide** may be confirmation-only if an authorized tester has already completed them.

For every functional test after that:

- do **not** assume the required Delivery Area, Delivery Option, Delivery Charge, Pickup Location, Site-wide Default, product exception or variation exception already exists;
- create the minimum test setup if it does not exist;
- if an earlier tester already created the same test setup, reuse it instead of creating duplicates;
- still perform the functional test yourself.

A previous tester's PASS is evidence, but it is not your PASS.

---

## Ghana Location Pack

The Ghana Location Pack is already installed and should be **Ready**.

**Do not download or reinstall Ghana during this campaign unless specifically instructed.**

---

## Testing packs

### Rapid Test Pack

Use the Rapid Test Guide when time is limited and the main goal is to find release-blocking defects quickly.

It focuses on:

- clean-state/setup confirmation;
- Ghana geography;
- Delivery Areas;
- Delivery Options;
- Delivery Charges;
- Site-wide Defaults;
- simple and variable products;
- product page;
- cart;
- checkout;
- fresh test order;
- pickup;
- fail-closed behavior;
- shipments;
- Needs Attention;
- mobile/WoodMart sanity;
- logs.

### Full Testing Guide v1.0

Use the Full Testing Guide for the complete release-quality campaign.

It expands testing to:

1. Baseline and clean-state truth
2. Admin fundamentals
3. Location Packs and canonical geography
4. Delivery Areas
5. Delivery Options
6. Delivery Charges
7. Site-wide Defaults
8. Product and variation exceptions
9. Fulfilment types
10. Pickup
11. Simple-product storefront
12. Variable products
13. Cart persistence and revalidation
14. Multi-item and multi-destination
15. Classic checkout
16. Cart/Checkout Blocks
17. Payment lifecycle
18. Order snapshots
19. Shipments
20. Tracking and customer presentation
21. Needs Attention
22. Bulk Tools
23. Roles and security
24. Compatibility
25. Responsive/browser/accessibility basics
26. Failure and recovery
27. Final regression
28. Logs and sign-off

---

## Stable test IDs

All test cases use stable IDs such as:

- `DE-QA-BASE-001`
- `DE-QA-GEO-001`
- `DE-QA-AREA-001`
- `DE-QA-OPTION-001`
- `DE-QA-RATE-001`
- `DE-QA-PDP-001`
- `DE-QA-BLOCKS-001`
- `DE-QA-SHIP-001`

Use these IDs in defect reports, screenshots, GitHub issues and retests.

---

## Evidence

When a test fails, capture:

- test ID;
- page/URL;
- tester user/role;
- product and variation;
- selected location;
- Delivery Option;
- quantity;
- expected result;
- actual result;
- screenshot/video;
- time;
- cart/order/shipment ID where available;
- whether it reproduces.

Do not report only: **"delivery is not working."**

---

## Result words

Use:

- **PASS**
- **FAIL**
- **BLOCKED**
- **NOT TESTED**

Baseline/Setup Guide only may also use:

- **CONFIRMED — ALREADY COMPLETED**

---

## Severity

- **P0 / Critical:** fatal, wrong money charged, data corruption, privacy/security exposure, silent free delivery from missing configuration, checkout impossible.
- **P1 / Major:** important customer/staff workflow gives the wrong result or cannot be completed.
- **P2 / Moderate:** meaningful defect with a workaround.
- **P3 / Minor:** cosmetic, wording, spacing or low-risk usability issue.

