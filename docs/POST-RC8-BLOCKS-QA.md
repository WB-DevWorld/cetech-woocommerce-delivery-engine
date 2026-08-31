# POST-RC.8 Blocks — `1.0.0-dev.blocks.1` Owner QA Package

**Document status:** Packaged for owner physical QA. **Physical QA not yet executed by Cursor.** Not RC.9. Not deployed to FLAIROC.  
**Date:** 2026-08-31  
**Branch:** `feat/post-rc8-integrations`  
**QA identity:** `1.0.0-dev.blocks.1`  
**Schema:** `5`  
**Protected published baseline:** tagged `v1.0.0-rc.8` / `6d166227998d4b0f5047fea91944ff024b389810` **untouched**  
**FLAIROC:** not modified  
**Implementation record:** `docs/POST-RC8-BLOCKS-IMPLEMENTATION.md`

---

## 1. What this package is

`1.0.0-dev.blocks.1` is the owner-physical-QA package for post-RC.8 Settings honesty and a real WooCommerce Cart & Checkout Blocks adapter.

It is **not** RC.8, **not** RC.9, **not** Stage 15, and not a WPML / WCML / WCFM / VitePOS / Return-Refund / per-item-location / carrier stream.

---

## 2. Source and ZIP

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.1.zip` |
| Version | `1.0.0-dev.blocks.1` |
| Schema | `5` |
| Branch | `feat/post-rc8-integrations` |
| Implementation/source SHA | `35e586bdf90483ae4b3080cac11d8d77bdb79ab0` |
| Package-source commit | `35e586bdf90483ae4b3080cac11d8d77bdb79ab0` |
| Bytes | `1354568` |
| SHA-256 | `6bf53359e8975b331fdd519a3fce74627635b42b3dfccf09c6dd4c41d6a59d78` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.1.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.1.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.blocks.1 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.1.zip` (clean tree; **not** `-AllowDirty`) |
| RC.8 tag | `v1.0.0-rc.8` still peels to `6d166227998d4b0f5047fea91944ff024b389810` |

Do not install historical Fulfilment / Bulk / RC.8 ZIPs for this Blocks QA pass.

---

## 3. Automated tests at packaging source

| Suite | Result |
|-------|--------|
| Focused Blocks / integrations / Settings honesty / identity | **38 tests, 373 assertions, OK** |
| Full PHPUnit | **806 tests, 4705 assertions, OK** (5 pre-existing deprecations) |
| Full JS (`npm run test:js`) | **32 / 32 OK** |
| Production PHP lint (source tree) | **398 OK / 0 FAIL** |
| `composer validate --no-check-publish` | **valid** |

Assertions were not weakened.

---

## 4. Extracted package verification

Verifier was run against the **extracted ZIP**, not the development source tree.

| Check | Result |
|-------|--------|
| One plugin root `cetech-woocommerce-delivery-engine/` | PASS |
| Identity `1.0.0-dev.blocks.1` | PASS |
| Schema `5` | PASS |
| Production Composer autoload | PASS |
| Linux-case classmap | PASS (`verify-production-package-autoload.php` OK) |
| Blocks PHP runtime present | PASS |
| Blocks JS/CSS assets present | PASS |
| Store API extension present | PASS |
| `cart_checkout_blocks` declaration present | PASS |
| RC.8 Classic runtime preserved (`woocommerce_after_checkout_validation`) | PASS |
| Bulk engine preserved | PASS |
| Fulfilment pickup runtime preserved | PASS |
| No `tests/` | PASS |
| No `phpunit.xml` | PASS |
| No development PHPUnit packages | PASS |
| No `node_modules` | PASS |
| No `.git` | PASS |
| No `.env` | PASS |
| No nested ZIPs | PASS |
| Packaged PHP lint | **398 OK / 0 FAIL** |
| `php scripts/verify-production-package-autoload.php <extracted-root>` | **exit 0** |

---

## 5. Owner physical QA — three checks only

Site: **training.cetechbpa.com**  
Install: in-place replacement of the Delivery Engine plugin. Do **not** uninstall/delete Delivery Engine data.  
Confirm after install: Active; `1.0.0-dev.blocks.1`; schema `5`.

### Safe Blocks page setup

Before changing WooCommerce page assignments, record:

- `woocommerce_cart_page_id`
- `woocommerce_checkout_page_id`

Leave existing Classic pages intact. Create temporary pages (for example **DE Blocks QA Cart** / **DE Blocks QA Checkout**) with the genuine WooCommerce Cart Block and Checkout Block. Assign those pages only for the QA session. Record the temporary page IDs.

### Check 1 — In Store / mixed

One Blocks cart: one Standard Delivery line + one Store Pickup line. Place **one** test order.

Expect: both lines survive; Delivery charged; Pickup zero; Pickup location/readiness; no raw JSON; Pickup not described as shipping to the customer address; mixed total correct; order snapshots for Delivery and Pickup; Pickup creates no delivery shipment.

### Check 2 — International

Already-qualified International Air product. Air only; no Standard Delivery; no Pickup; Air rate correct. Change destination enough to recalculate. Must refresh/revalidate; fail closed if no valid DE rate (no native WooCommerce fallback). Additional order only if needed to prove failure.

### Check 3 — In Warehouse

Standard/local Delivery; no Pickup; no Air/Sea. No order required.

### Restore Classic pages

Immediately restore original Cart and Checkout page IDs. Confirm `/cart/` and Classic checkout still open. Do not rerun the RC.8 fulfilment matrix.

---

## 6. Physical QA results

**Not executed in this packaging session.** Cursor has no stored training wp-admin credentials and must not invent a login. FLAIROC was not used.

Install the Desktop ZIP on training, run the three checks above, then record PASS/FAIL here.

| Check | Result |
|-------|--------|
| Install Active / `1.0.0-dev.blocks.1` / schema `5` | pending |
| Classic page IDs recorded | pending |
| Temporary Blocks pages assigned | pending |
| Check 1 In Store mixed | pending |
| Check 2 International + destination change | pending |
| Check 3 In Warehouse | pending |
| Classic page IDs restored | pending |

Do **not** create RC.9 from a pending physical result.
