# POST-RC.7 Fulfilment Correctness — `1.0.0-dev.fulfilment.1` Owner QA Package

**Document status:** Packaged for owner physical QA. **Not deployed. Not RC.8.**  
**Date:** 2026-08-31  
**Branch:** `feat/post-rc7-fulfilment-correctness`  
**QA identity:** `1.0.0-dev.fulfilment.1`  
**Schema:** `5`  
**Protected published baseline:** tagged `v1.0.0-rc.7` / `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6` **untouched**  
**FLAIROC:** not modified  
**Cursor did not install this ZIP**

---

## 1. What this package is

`1.0.0-dev.fulfilment.1` is the authorised owner-physical-QA package of the post-RC.7 fulfilment correctness repair:

- In Store Delivery + Store Pickup as concurrent customer choices (Delivery default/preselected)
- International single eligible Air/Sea offer auto-select; multiple remain a choice; constraints not weakened
- DE-managed packages fail closed instead of exposing native WooCommerce methods

It is **not** RC.7, **not** RC.8, **not** Stage 15, and **not** a Bulk / per-item-location / Return-Refund / Checkout Blocks stream.

Implementation record: `docs/POST-RC7-FULFILMENT-CORRECTNESS-IMPLEMENTATION.md`.  
Accepted audit: `docs/POST-RC7-FULFILMENT-CORRECTNESS-AUDIT.md`.

---

## 2. Source and ZIP

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.1.zip` |
| Version | `1.0.0-dev.fulfilment.1` |
| Schema | `5` |
| Branch | `feat/post-rc7-fulfilment-correctness` |
| Implementation/source SHA | `f7dd170c8ecb1009ca95c4c9e456b6c68cd11183` |
| Package-source commit | `f7dd170c8ecb1009ca95c4c9e456b6c68cd11183` |
| Bytes | `1284412` |
| SHA-256 | `1fa195b59a3503c0e1362cc2c24f70f557ec98e8d27bb992a3c432d7ec128886` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.1.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.1.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.fulfilment.1 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.1.zip` (clean tree; **not** `-AllowDirty`) |
| RC.7 tag | `v1.0.0-rc.7` still peels to `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6` |

---

## 3. Automated tests at packaging source

Run against the implementation that became `f7dd170` (runtime identical; docs Git-line only changed before commit):

| Suite | Result |
|-------|--------|
| Focused fulfilment PHPUnit | **39 tests, 189 assertions, OK** |
| Full PHPUnit | **758 tests, 4282 assertions, OK** (5 pre-existing deprecations) |
| Full JS (`npm run test:js`) | **28 / 28 OK** |
| Changed PHP lint | **0 errors** |

Assertions were not weakened.

---

## 4. Extracted-package verification

Extracted to a disposable directory outside the git repo (`%TEMP%\cetech-de-fulfilment1-extract`).

| Check | Result |
|-------|--------|
| One plugin root `cetech-woocommerce-delivery-engine/` | PASS (512 ZIP entries, forward slashes) |
| Identity `CETECH_DE_VERSION` / header `1.0.0-dev.fulfilment.1` | PASS |
| Schema `SchemaVersion::TARGET` = `5` | PASS |
| Production autoload + `scripts/verify-production-package-autoload.php` | PASS (exit 0; Linux-case classmap; no PHPUnit in vendor) |
| In Store `store_pickup` route + dual builder emission + PDP switcher JS | PASS |
| International `defaultDisplayKey` single-offer auto-select present | PASS |
| DE-managed native-rate filter returns only DE rates (fail closed) | PASS |
| No `tests/`, `phpunit.xml`, `node_modules/`, `.git/`, `.env`, nested ZIPs | PASS |
| Packaged PHP lint | **0 errors** |

The verifier success banner still prints the historical line “Schema target 4”; the actual schema gate for this identity requires `TARGET === 5` and passed.

---

## 5. Owner physical QA — three scenarios only

Do **not** expand. If one fails, preserve evidence and investigate that scenario only.

### Scenario 1 — In Store

One clean In Store test product with local Delivery, Store Pickup, and a valid pickup location.

Confirm:

- Delivery is preselected
- local Delivery Offer / price / ETA appears
- Store Pickup is also selectable
- switching to Pickup removes delivery charge / offers / route / doorstep ETA
- pickup location / readiness appears
- switching back restores Delivery correctly

### Scenario 2 — International

One correctly classified International test product with clean Air/Sea configuration.

Confirm:

- Delivery Only
- only eligible Air and/or Sea appear
- no Standard Delivery
- no Store Pickup
- if exactly one eligible Air/Sea offer remains, it auto-selects

Do **not** use the known misconfigured `airshipping` row (`route = local_delivery`) as evidence of resolver behaviour.

### Scenario 3 — In Warehouse

One In Warehouse product still shows:

- Delivery Only
- Standard/local Delivery
- no Store Pickup
- no Air/Sea

---

## 6. Explicitly not done

- FLAIROC not modified; ZIP not installed
- RC.7 not retagged or rebuilt
- RC.8 not created
- Bulk QA not reopened
- Per-item pickup location work not started
- Return/Refund not started
- Checkout Blocks / carrier integrations not started
