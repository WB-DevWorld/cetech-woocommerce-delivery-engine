# POST-RC.8 Blocks — `1.0.0-dev.blocks.3` Owner QA Package

**Document status:** Packaged for owner physical QA of matched Delivery Area pricing fallback. Not RC.9. Not deployed.  
**Date:** 2026-08-31  
**Branch:** `feat/post-rc8-integrations`  
**QA identity:** `1.0.0-dev.blocks.3`  
**Schema:** `5`  
**Protected published baseline:** tagged `v1.0.0-rc.8` / `6d166227998d4b0f5047fea91944ff024b389810` **untouched**  
**FLAIROC:** not modified  
**Repair record:** `docs/POST-RC8-BLOCKS-3-MATCHED-AREA-PRICING.md`  
**Historical Blocks.2 ZIP:** immutable; keep it as the mixed-cart repair artifact

---

## Source and ZIP

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.3.zip` |
| Version | `1.0.0-dev.blocks.3` |
| Schema | `5` |
| Branch | `feat/post-rc8-integrations` |
| Implementation/source SHA | `4d5f4fe0c9a4437e6a7f9eac1e3771351ecb44b5` |
| Package-source commit | `4d5f4fe0c9a4437e6a7f9eac1e3771351ecb44b5` |
| Bytes | `1369764` |
| SHA-256 | `db94e205dada64f6be687892ac02e91424cd4e36f54c2473a260a7da35cba93a` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.3.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.3.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.blocks.3 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.3.zip` (clean tree; **not** `-AllowDirty`) |
| RC.8 tag | `v1.0.0-rc.8` still peels to `6d166227998d4b0f5047fea91944ff024b389810` |

Cursor did **not** install this ZIP. Do not deploy to FLAIROC.

---

## Automated tests at packaging source

| Suite | Result |
|-------|--------|
| Focused matcher / quote / overlap / Blocks / Classic / warehouse / international | **99 tests, 589 assertions, OK** |
| Full PHPUnit | **828 tests, 4760 assertions, OK** (5 pre-existing deprecations) |
| Full JS | **32 / 32 OK** |
| Production PHP lint (source tree) | **399 OK / 0 FAIL** |
| Composer | `composer.json` valid |

---

## Extracted package verification

| Check | Result |
|-------|--------|
| One plugin root | PASS |
| Identity `1.0.0-dev.blocks.3` | PASS |
| Not `blocks.2` / not `rc.8` / not `rc.9` | PASS |
| Schema `5` | PASS |
| Production Composer autoload | PASS |
| Linux-case classmap | PASS |
| `match_all` + ordered pricing fallback present | PASS |
| Test an address primary/also-matches copy present | PASS |
| Blocks JS present | PASS |
| `cart_checkout_blocks` declaration | PASS |
| No `tests/` / `phpunit.xml` / PHPUnit vendor / `node_modules` / `.git` / `.env` / nested ZIPs | PASS |
| Packaged plugin PHP lint (excluding vendor) | **401 OK / 0 FAIL** |
| `verify-production-package-autoload.php` on extracted root | **exit 0** |

The verifier still prints a stale “Schema target 4…” success string. Actual `SchemaVersion::TARGET` in the ZIP is `'5'`.

---

## Owner physical QA

Site: **training.cetechbpa.com**  
Replace Blocks.2 with this ZIP in place. Do **not** uninstall/delete Delivery Engine data. Keep temporary native Blocks pages assigned. Keep WoodMart layouts 1482, 1496 and 1500 in draft.

Confirm International Air for GH / Greater Accra / Accra quotes the Greater Accra Air rate without duplicating that rate onto Accra. Do not substitute a different Delivery Option. Native WooCommerce fallback remains fail-closed.

Mixed In Store Delivery + Pickup Blocks should remain clean (no false “Delivery pricing is not available…” warning).

Do **not** begin RC.9.
