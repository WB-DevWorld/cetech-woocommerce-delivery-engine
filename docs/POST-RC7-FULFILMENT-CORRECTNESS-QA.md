# POST-RC.7 Fulfilment Correctness — `1.0.0-dev.fulfilment.4` Owner QA Package

**Document status:** Packaged for owner physical QA. **OWNER PHYSICAL QA: ALL THREE FULFILMENT CORRECTNESS SCENARIOS PASSED** on training.cetechbpa.com. Promoted to tagged `1.0.0-rc.8`. **Not deployed by Cursor. Not a live FLAIROC change.**  
**Date:** 2026-08-31  
**Branch:** `feat/post-rc7-fulfilment-correctness`  
**QA identity:** `1.0.0-dev.fulfilment.4`  
**Schema:** `5`  
**Protected published baseline:** tagged `v1.0.0-rc.7` / `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6` **untouched**  
**FLAIROC:** not modified  
**Cursor did not install this ZIP**

---

## 1. What this package is

`1.0.0-dev.fulfilment.4` is the replacement owner-physical-QA package after the mixed-cart destination-copy defect in `1.0.0-dev.fulfilment.3`.

Already accepted and not redesigned:

- Pickup-only cart (human-readable address, `Pickup at CETECH Accra Store`, zero charge, no false customer destination)
- Delivery + Store Pickup availability, Delivery default, ECR Pickup Location, PDP switching, cart-line separation, zero Pickup charge

This package repairs only the mixed-cart Pickup package still rendering WooCommerce `Shipping to [customer delivery address]` and `Change address`.

Pickup package copy becomes:

- `Pickup at CETECH Accra Store`
- `Pickup address: …` (human-readable store address)

Delivery package stays:

- Standard Delivery
- delivery charge
- `Shipping to …`
- `Change address`

It is **not** RC.7, **not** RC.8, **not** Stage 15, and **not** a Bulk / per-item-location / Return-Refund / Checkout Blocks stream.

Repair note: `docs/POST-RC7-FULFILMENT-CORRECTNESS-CART-PRESENTATION.md`.

---

## 2. Source and ZIP

Filled after packaging.

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.4.zip` |
| Version | `1.0.0-dev.fulfilment.4` |
| Schema | `5` |
| Branch | `feat/post-rc7-fulfilment-correctness` |
| Implementation/source SHA | `844544ad4020e2c17616145de31a6d6b67bb5128` |
| Package-source commit | `844544ad4020e2c17616145de31a6d6b67bb5128` |
| Bytes | `1303884` |
| SHA-256 | `f31401636a7c3cadf68636bbdc9a94224782eb3fbf408696880da9d11ac67127` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.4.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.4.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.fulfilment.4 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.4.zip` (clean tree; **not** `-AllowDirty`) |
| RC.7 tag | `v1.0.0-rc.7` still peels to `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6` |

Previous packages `1.0.0-dev.fulfilment.1.zip`–`fulfilment.3.zip` remain historical artifacts. Do not install them for this QA pass.

---

## 3. Automated tests at packaging source

| Suite | Result |
|-------|--------|
| Focused cart-presentation + fulfilment PHPUnit | **47 tests, 274 assertions, OK** |
| Full PHPUnit | **783 tests, 4544 assertions, OK** (5 pre-existing deprecations) |
| Full JS (`npm run test:js`) | **28 / 28 OK** |
| Changed PHP lint | **0 errors** |

Assertions were not weakened. Three new mixed-cart HTML regressions are included (Pickup-only, mixed Pickup, mixed Delivery).

---

## 4. Owner physical QA — one mixed-cart screenshot only

Do **not** redo Pickup-only, PDP, Pickup Location admin, International, or In Warehouse.

Confirm only **one mixed Delivery + Pickup cart**:

- Pickup group heading is `Pickup at CETECH Accra Store`
- Pickup group shows the human-readable pickup address
- Pickup group does **not** show `Shipping to [customer delivery address]`
- Pickup group does **not** show `Change address`
- Delivery group still shows Standard Delivery, the delivery charge, `Shipping to …`, and `Change address`

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
- No International / In Warehouse work in this pass
