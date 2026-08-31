# POST-RC.8 Blocks — `1.0.0-dev.blocks.2` Owner Recheck Package

**Document status:** Packaged for **one** mixed In Store Blocks recheck. Not RC.9. Not deployed.  
**Date:** 2026-08-31  
**Branch:** `feat/post-rc8-integrations`  
**QA identity:** `1.0.0-dev.blocks.2`  
**Schema:** `5`  
**Protected published baseline:** tagged `v1.0.0-rc.8` / `6d166227998d4b0f5047fea91944ff024b389810` **untouched**  
**FLAIROC:** not modified  
**Repair record:** `docs/POST-RC8-BLOCKS-2-PICKUP-RATE-REPAIR.md`  
**Historical Blocks.1 ZIP:** immutable; do not reinstall it for this recheck

---

## Source and ZIP

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.2.zip` |
| Version | `1.0.0-dev.blocks.2` |
| Schema | `5` |
| Branch | `feat/post-rc8-integrations` |
| Implementation/source SHA | `a2f1658bdbd871dc6accf426d98749751cf96fab` |
| Package-source commit | `a2f1658bdbd871dc6accf426d98749751cf96fab` |
| Bytes | `1359677` |
| SHA-256 | `0af329e03621fe78ba0709e63908b3a2f7345664acb1e835472a47d02a147126` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.2.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.2.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.blocks.2 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.2.zip` (clean tree; **not** `-AllowDirty`) |
| RC.8 tag | `v1.0.0-rc.8` still peels to `6d166227998d4b0f5047fea91944ff024b389810` |

Cursor did **not** install this ZIP. Do not deploy to FLAIROC.

---

## Automated tests at packaging source

| Suite | Result |
|-------|--------|
| Focused Blocks/integrations PHPUnit | **26 tests, 131 assertions, OK** |
| Full PHPUnit | **811 tests, 4717 assertions, OK** (5 pre-existing deprecations) |
| Full JS | **32 / 32 OK** |
| Production PHP lint (source tree) | **398 OK / 0 FAIL** |

---

## Extracted package verification

| Check | Result |
|-------|--------|
| One plugin root | PASS |
| Identity `1.0.0-dev.blocks.2` | PASS |
| Not `blocks.1` / not `rc.8` / not `rc.9` | PASS |
| Schema `5` | PASS |
| Production Composer autoload | PASS |
| Linux-case classmap | PASS |
| Blocks PHP rate-array repair present | PASS |
| Blocks JS present | PASS |
| `cart_checkout_blocks` declaration | PASS |
| No `tests/` / `phpunit.xml` / PHPUnit vendor / `node_modules` / `.git` / `.env` / nested ZIPs | PASS |
| Packaged PHP lint | **398 OK / 0 FAIL** |
| `verify-production-package-autoload.php` on extracted root | **exit 0** |

---

## Owner physical recheck — one check only

Site: **training.cetechbpa.com**  
Replace Blocks.1 with this ZIP in place. Do **not** uninstall/delete Delivery Engine data. Keep temporary native Blocks pages assigned. Keep WoodMart layouts 1482, 1496, 1500 in draft for this recheck.

Open the existing mixed In Store cart (Standard Delivery + Store Pickup).

Cart Block and Checkout Block must both show:

- no false “Delivery pricing is not available…” warning
- Standard Delivery = GHS 50
- Pickup = FREE / GHS 0
- total GHS 453
- pickup location/address/readiness correct
- Place Order available on Checkout

Do **not** place another order unless something unexpected requires it. Order `#49237` already proved Classic persistence.

If both surfaces are clean: **Blocks Check 1 PASS**. Do not restart earlier In Store tests. Do not begin RC.9.
