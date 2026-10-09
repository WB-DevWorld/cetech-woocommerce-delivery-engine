# 18 — Final Regression and Sign-Off

Run this only after the relevant module tests are complete.

## DE-QA-REG-001 — Complete end-to-end delivery sale

Use a fresh browser/customer session and a fresh test order.

### Staff preparation

Confirm the test uses:

- valid Ghana geography;
- a Delivery Area;
- a Delivery Option;
- a Delivery Charge;
- effective Site-wide/Product/Variation configuration as appropriate.

### Customer journey

1. open the product;
2. select Country → Region → Locality;
3. choose the intended Delivery Option;
4. record the delivery price and ETA;
5. add to cart;
6. confirm the delivery state;
7. proceed to checkout;
8. enter the final shipping address;
9. verify the genuine WooCommerce shipping charge;
10. complete a safe test order.

### Order/operations

11. record the order ID;
12. inspect the saved purchase-time delivery information;
13. inspect shipment creation/state where applicable;
14. inspect customer Thank You / My Account;
15. inspect test email where available;
16. confirm no private logistics information is exposed;
17. check logs for the test time window.

### Expected

The same customer delivery promise remains coherent from product page through the completed order.

---

## DE-QA-REG-002 — Complete pickup journey

Run a fresh Store Pickup order.

### Expected

- pickup choice remains correct;
- valid free/zero charge is preserved;
- checkout/order presentation is correct;
- no false delivery shipment is created.

---

## DE-QA-REG-003 — Fail-closed control

Immediately after the happy-path tests, run one unsupported/missing-rate case.

### Expected

The system refuses invalid delivery safely.

This proves the release does not only work when everything is perfectly configured.

---

## DE-QA-REG-004 — Final logs

Check the exact test window.

### Expected

No new Delivery Engine fatal/critical error caused by the regression run.

---

# Final campaign summary

Record:

| Item | Count |
| --- | ---: |
| PASS | |
| FAIL | |
| BLOCKED | |
| NOT TESTED | |
| P0 / Critical | |
| P1 / Major | |
| P2 / Moderate | |
| P3 / Minor | |

## Release recommendation

Choose one:

- **PASS** — no release-blocking defect found.
- **PASS WITH ISSUES** — no blocker, but non-blocking defects remain.
- **HOLD** — at least one Major/P1 issue should be repaired and retested.
- **STOP** — Critical/P0 issue found.

Record:

- tester(s);
- exact Delivery Engine version;
- environment;
- new order IDs;
- shipment IDs;
- evidence links;
- log time window;
- unresolved defect IDs.

Use [TEST-RUN-REPORT-TEMPLATE.md](./TEST-RUN-REPORT-TEMPLATE.md) for the final report.
