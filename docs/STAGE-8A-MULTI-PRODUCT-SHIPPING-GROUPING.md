# Stage 8A — Release-Critical Multi-Product Shipping Grouping

**Document status:** Stage 8A completion record  
**Plugin version:** `1.0.0-rc.2`  
**Schema target:** `3` (unchanged)  
**Date:** 2026-08-12  
**Carry-forward:** Stage 6C presentation cleanup; Stage 8 live `#39724`; Stage 8C presentation cleanup

---

## 1. Verdict

**STAGE 8 FUNCTIONALLY COMPLETE** (live `#39724`)

**Final RC.2 live verification: PASS.**

| Live proof | Result |
|------------|--------|
| Compatible products share fixed-per-shipment charge | **25.00 once** (not 50.00) |
| Quantity 2 on fixed-per-shipment path | Remained **25.00** |
| Multi-line QA order | **`#39724`** — items **39.98** + shipping **25.00** = total **64.98** |
| Saved delivery information | **PASS** |
| Presentation cleanup (Stage 8C) | **PASS** |
| Delivery Engine PHP fatals in final smoke | **None** |

Stage 8A answers one business question for Classic Checkout:

> Which cart items travel together, and what should the customer be charged?

It does **not** build shipment records, tracking, Blocks checkout, or carrier APIs.

---

## 2. In plain English

### What is a delivery group?

A **delivery group** is a set of cart products that can genuinely be fulfilled the same way — same fulfilment path and same selected delivery option (or the same store-pickup choice).

WooCommerce then creates one shipping package per delivery group.

### When do products travel together?

Products share one delivery group when their selected delivery requirements match:

- same fulfilment availability (for example In Warehouse)
- same fulfilment choice (Delivery or Store Pickup)
- same selected delivery offer (for Delivery)

Quantity of the same cart line does **not** create extra packages by itself.

### When are they separated?

Products are split when they cannot travel together, for example:

- Store Pickup + Home Delivery
- Local delivery + International Air/Sea
- Two different selected delivery offers
- A variation whose effective delivery settings differ from another variation’s selection

### How is shipping charged?

- Compatible products in one group → **one** WooCommerce shipping charge for that group
- Rate cards still control the math:
  - `fixed_per_shipment` → charged once for the group (not once per product)
  - `fixed_per_item` → uses combined quantity
- Store Pickup groups are **not** charged as delivery (explicit $0 shipping line so checkout can complete)
- Missing/invalid rates still **fail closed** (never silent free shipping)

---

## 3. Stage 7 confirmation

**COMPLETE — NO WOODMART ADAPTER REQUIRED**

Evidence: Stage 6 variable QA used WoodMart’s normal variation lifecycle successfully. No WoodMart-specific defect required an adapter.

---

## 4. Behavior found before Stage 8A

Audit of runtime before this stage:

| Scenario | Previous behavior |
|----------|-------------------|
| A. Qty 2 of same product | One WC cart line / one package (no duplicate packages from quantity alone) |
| B. Two compatible products, same offer | **One** WC package; calculator quoted **each line and summed** → risk of double `fixed_per_shipment` charge |
| C. Two different offers | Still one WC package; summed separate line quotes |
| D. Pickup + Delivery | One WC package; pickup skipped in quote (null offer); delivery quoted |
| E. Local + International | One WC package; summed line quotes |
| F/G. Variations | Used cart-line selection; no explicit package split architecture |

**Conclusion:** There was **no** `woocommerce_cart_shipping_packages` grouping architecture. The shipping calculator summed per-line quotes inside WooCommerce’s default single package.

---

## 5. Delivery grouping rule (implementation)

Internal group id (never shown to customers):

```text
{fulfilment_availability}|{fulfilment_choice}|{offer_or_pickup}
```

Examples:

- `in_warehouse|delivery|10`
- `in_store|store_pickup|pickup`
- `international_fulfilment|delivery|30`

Notes:

- Deterministic (no random values)
- Built from the **selected cart intent** (variation-aware)
- Pickup location scheduling deferred (V1 intent has no pickup location id)
- Supplier/origin IDs are **not** exposed; not required for this release’s grouping key

---

## 6. Runtime wiring

| Component | Role |
|-----------|------|
| `DeliveryGroupIdentity` | Stable group key helpers |
| `ShippingPackageBuilder` | `woocommerce_cart_shipping_packages` split/consolidate |
| `SelectedOfferShippingRateCalculator` | One quote per managed delivery group; pickup → $0 |
| `SelectedOfferShippingIntegration` | Exclusive DE rates on managed packages when DE rate present |
| `SelectedOfferShippingMethod` | Per-package labels (`Delivery` / `Delivery N` / `Store pickup`) and unique rate id suffix |
| Order snapshots | Optional `delivery_group_id` on lines; optional `groups[]` on package snapshot (schema version remains `1`, additive) |

Gates: same shipping runtime gate as Stage 6 (`enable_woocommerce_shipping_rate_calculation` + upstream flags).

---

## 7. Order saving / staff view

- Each line snapshot stores which delivery group it belonged to (`delivery_group_id`)
- Order package snapshot stores aggregate shipping total plus optional per-group entries
- No shipment records created
- Staff “Delivery information” shows operational group titles (`Delivery 1`, products, fulfilment, method, option, estimate, charge) — not hashes/IDs/raw JSON

Stage 6C presentation cleanup remains included.

---

## 8. Automated coverage

Focused matrix in `tests/Unit/Shipping/ShippingPackageGroupingTest.php`:

1. Single product → one managed package  
2. Quantity 2 → no duplicate package  
3. Compatible products → consolidate  
4. Incompatible offers → split  
5. Pickup + Delivery → split; pickup $0; delivery charged  
6. Local + International → split  
7. Compatible variations → one group using selected variation intents  
8. Incompatible variation override → split  
9. Group ids survive session restore  
10. Invalid group fails closed (not free)  
11. Snapshot preserves line→group relationship  
12. Stage 6 `25.00` fixed-per-shipment consolidation (not doubled)

---

## 9. Deferred (explicit)

- Shipment records / tracking / timeline
- Advanced pickup location scheduling
- Supplier/origin-based logistics optimizer
- WooCommerce Blocks checkout
- Carrier API quoting
- Intermediate Stage 6C-only package (package Stage 6C + 8A together)

---

## 10. Files changed (summary)

- `src/Application/Shipping/*` — grouping, package builder, assessor, calculator, integration
- `src/Infrastructure/WooCommerce/Shipping/SelectedOfferShippingMethod.php`
- `src/Application/Order/OrderDeliverySnapshot*.php`
- `src/Presentation/Admin/OrderDeliverySnapshotAdminDisplay.php`
- `src/Bootstrap/Plugin.php`
- `src/Application/Destination/PackageDestinationZoneResolverInterface.php`
- `tests/Unit/Shipping/ShippingPackageGroupingTest.php`
- `docs/STAGE-8A-MULTI-PRODUCT-SHIPPING-GROUPING.md`
- `docs/AI-HANDOFF.md`
- `scripts/verify-production-package-autoload.php` — Stage 8 class checks

---

## 11. Grouping safety (Stage 8B-1 review)

Products are **not** consolidated merely because they share a visible offer label.

`DeliveryGroupIdentity` requires all of:

1. fulfilment availability (covers local vs international / in-store / in-warehouse)
2. fulfilment choice (covers pickup vs delivery)
3. selected delivery offer id (or shared store-pickup sentinel)

Variation-aware: uses the cart line’s selected variation intent.

Destination/rate context: customer destination is applied at package quote time (same cart destination for packages).

Deferred (documented, not invented for this release):

- pickup location id (absent from V1 selection intent)
- supplier/origin logistics optimizer beyond representative-line quote dimensions inside an already-compatible group

Internal group ids / hashes are never shown to customers or in normal staff UI.

---

## 12. Stage 8B-1 package state

**Commit:** `5f04147` — `feat: add multi-product delivery grouping`  
**Base includes Stage 6C:** `331a856`  
**Public version:** `1.0.0-rc.2`  
**Schema:** `3`  
**Stage 7:** COMPLETE — NO WOODMART ADAPTER REQUIRED  
**Shipments/tracking:** not included  
**FLAIROC:** not modified during 8B-1  

**Automated gates (8B-1):**

- PHPUnit: 204 tests / 893 assertions  
- Vitest: 9 tests  
- composer validate: OK  
- production package verifier: OK  

Package artifact (not committed):

- filename: `cetech-woocommerce-delivery-engine-stage8a-qa.zip`  
- path: `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-stage8a-qa.zip`  
- bytes: `701402`  
- SHA-256: `daaa558207847d92594cab0cb7bb8cb25b993c792c10f521ca25668e021f2c52`  
- ZIP root: `cetech-woocommerce-delivery-engine/`  

---

## 13. Deferred (explicit)

- Shipment records / tracking / timeline
- Advanced pickup location scheduling
- Supplier/origin logistics optimizer
- WooCommerce Blocks checkout
- Carrier API quoting
- Intermediate Stage 6C-only package (package Stage 6C + 8A together)

---

## 14. Recommended next step

Human clean-installs the Stage 8 QA package, then focused live test only:

1. two compatible delivery products → expect **25.00 once**
2. quantity 2 → expect **25.00** for fixed-per-shipment
3. incompatible split only if already safe to configure
4. one multi-product order if needed
5. restore flags/COD OFF

Do **not** begin shipment/tracking work yet.
