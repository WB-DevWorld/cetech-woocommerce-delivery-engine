# POST-RC.7 Fulfilment Correctness — `1.0.0-dev.fulfilment.2` Owner QA Package

**Document status:** Packaged for owner physical QA. **Not deployed. Not RC.8.**  
**Date:** 2026-08-31  
**Branch:** `feat/post-rc7-fulfilment-correctness`  
**QA identity:** `1.0.0-dev.fulfilment.2`  
**Schema:** `5`  
**Protected published baseline:** tagged `v1.0.0-rc.7` / `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6` **untouched**  
**FLAIROC:** not modified  
**Cursor did not install this ZIP**

---

## 1. What this package is

`1.0.0-dev.fulfilment.2` is the replacement owner-physical-QA package after Scenario 1 admin blockers in `1.0.0-dev.fulfilment.1`.

It keeps the fulfilment.1 runtime (In Store Delivery + Store Pickup as concurrent customer choices; International single-offer auto-select; DE-managed packages fail closed) and repairs:

- In Store **Available fulfilment methods** vs **Default customer choice**
- ECR Pickup Location (inherit/override/disable) so Store Pickup is not a Delivery Option
- Pickup Locations R1 (Reference Code, WooCommerce country selector storing ISO-2, form ownership)
- Pickup readiness wired to the existing `readiness_estimate` column

It is **not** RC.7, **not** RC.8, **not** Stage 15, and **not** a Bulk / per-item-location / Return-Refund / Checkout Blocks stream.

Repair note: `docs/POST-RC7-FULFILMENT-CORRECTNESS-ADMIN-REPAIR.md`.  
Runtime record: `docs/POST-RC7-FULFILMENT-CORRECTNESS-IMPLEMENTATION.md`.  
Accepted audit: `docs/POST-RC7-FULFILMENT-CORRECTNESS-AUDIT.md`.

---

## 2. Source and ZIP

Filled after packaging. See the checksum commit on this branch if this file still says pending at read time.

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.2.zip` |
| Version | `1.0.0-dev.fulfilment.2` |
| Schema | `5` |
| Branch | `feat/post-rc7-fulfilment-correctness` |
| Implementation/source SHA | pending package |
| Package-source commit | pending package |
| Bytes | pending package |
| SHA-256 | pending package |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.2.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.2.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.fulfilment.2 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.2.zip` (clean tree; **not** `-AllowDirty`) |
| RC.7 tag | `v1.0.0-rc.7` still peels to `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6` |

Previous package `1.0.0-dev.fulfilment.1.zip` remains on disk as a historical artifact. Do not install it for this QA pass.

---

## 3. Automated tests at packaging source

| Suite | Result |
|-------|--------|
| Focused fulfilment + pickup-admin PHPUnit | **83 tests, 890 assertions, OK** |
| Full PHPUnit | **774 tests, 4463 assertions, OK** (5 pre-existing deprecations) |
| Full JS (`npm run test:js`) | **28 / 28 OK** |
| Changed PHP lint | **0 errors** |

Assertions were not weakened.

---

## 4. Owner physical QA — Scenario 1 only

Do **not** expand to International or In Warehouse until Scenario 1 passes. If Scenario 1 fails, preserve evidence and investigate that scenario only.

### Scenario 1 — In Store

Configure one clean In Store product:

- Available methods: Delivery **and** Store Pickup
- Default customer choice: Delivery
- Delivery Option: Standard Delivery (local)
- Pickup Location: one valid active location (Ghana stored as `GH`)
- Pickup readiness: `1–2 business days`
- Pickup instructions: e.g. Collect from the CETECH Store

Confirm:

- Delivery is preselected
- Standard Delivery price / ETA is visible
- Store Pickup is also selectable
- switching to Pickup removes delivery details and shows location / readiness
- switching back restores Delivery

Do **not** use the known misconfigured `airshipping` row (`route = local_delivery`) as evidence of resolver behaviour.

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
