# POST-RC.7 Fulfilment Correctness — `1.0.0-dev.fulfilment.3` Owner QA Package

**Document status:** Packaged for owner physical QA. **Not deployed. Not RC.8.**  
**Date:** 2026-08-31  
**Branch:** `feat/post-rc7-fulfilment-correctness`  
**QA identity:** `1.0.0-dev.fulfilment.3`  
**Schema:** `5`  
**Protected published baseline:** tagged `v1.0.0-rc.7` / `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6` **untouched**  
**FLAIROC:** not modified  
**Cursor did not install this ZIP**

---

## 1. What this package is

`1.0.0-dev.fulfilment.3` is the replacement owner-physical-QA package after Scenario 1 **cart presentation** blockers in `1.0.0-dev.fulfilment.2`.

Fulfilment.2 architecture remains accepted and is not redesigned:

- Delivery + Store Pickup can both be enabled
- Delivery is default
- Pickup Location resolves through ECR
- Standard Delivery and Store Pickup both appear on PDP
- selections persist separately in cart
- Pickup readiness/location/instructions persist
- Store Pickup adds zero delivery charge

This package repairs only:

- Pickup address rendered as human-readable copy, never serialized JSON
- Store Pickup groups labelled as pickup at the store, not “Shipping to [customer shipping address]”

It is **not** RC.7, **not** RC.8, **not** Stage 15, and **not** a Bulk / per-item-location / Return-Refund / Checkout Blocks stream.

Repair note: `docs/POST-RC7-FULFILMENT-CORRECTNESS-CART-PRESENTATION.md`.  
Admin repair (historical): `docs/POST-RC7-FULFILMENT-CORRECTNESS-ADMIN-REPAIR.md`.  
Runtime record: `docs/POST-RC7-FULFILMENT-CORRECTNESS-IMPLEMENTATION.md`.  
Accepted audit: `docs/POST-RC7-FULFILMENT-CORRECTNESS-AUDIT.md`.

---

## 2. Source and ZIP

Filled after packaging.

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.3.zip` |
| Version | `1.0.0-dev.fulfilment.3` |
| Schema | `5` |
| Branch | `feat/post-rc7-fulfilment-correctness` |
| Implementation/source SHA | `6a2091c28b7d32bae5e857dfb4ef4032dae5922b` |
| Package-source commit | `6a2091c28b7d32bae5e857dfb4ef4032dae5922b` |
| Bytes | `1303042` |
| SHA-256 | `6b9337bd9a8f5ffb57dde8fa4ea8bb5e7406280df52609fdb49c4845d0f30285` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.3.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.3.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.fulfilment.3 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.3.zip` (clean tree; **not** `-AllowDirty`) |
| RC.7 tag | `v1.0.0-rc.7` still peels to `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6` |

Previous packages `1.0.0-dev.fulfilment.1.zip` and `1.0.0-dev.fulfilment.2.zip` remain on disk as historical artifacts. Do not install them for this QA pass.

---

## 3. Automated tests at packaging source

| Suite | Result |
|-------|--------|
| Focused cart-presentation + fulfilment PHPUnit | **112 tests, 645 assertions, OK** (3 deprecations in that subset) |
| Full PHPUnit | **780 tests, 4507 assertions, OK** (5 pre-existing deprecations) |
| Full JS (`npm run test:js`) | **28 / 28 OK** |
| Changed PHP lint | **0 errors** |

Assertions were not weakened. The 6 new cart-presentation tests are included in the full suite (774 → 780).

---

## 4. Owner physical QA — Scenario 1 cart recheck only

Do **not** redo Pickup Location admin or the full PDP configuration. Do **not** expand to International or In Warehouse until Scenario 1 cart presentation passes.

Confirm only:

### Pickup-only cart

- Pickup address is human-readable (street, locality, Accra, Ghana — not JSON)
- Group heading is pickup at the store (e.g. `Pickup at CETECH Accra Store`), not a delivery shipment
- Copy does **not** say the pickup group is shipping to the customer shipping address
- Pickup location / readiness / instructions remain visible
- Cart total has **no** delivery charge for pickup

### Mixed Delivery + Pickup cart

- Two groups: Delivery vs Store Pickup
- Delivery group still shows normal `Shipping to …`
- Pickup group shows pickup at the store + pickup address, not the customer shipping destination
- Pickup remains zero charge; Delivery still quotes as before

If Scenario 1 cart presentation fails, preserve evidence and investigate that presentation only.

### Scenario 2 — International (later)

Wait until Scenario 1 passes.

### Scenario 3 — In Warehouse (later)

Wait until Scenario 1 passes.

---

## 5. Hard limits

- No RC.7 mutation
- No RC.8
- No schema 6
- No FLAIROC
- No Bulk work
- No per-item location architecture
- No Return/Refund work
- No Checkout Blocks
- No carriers
