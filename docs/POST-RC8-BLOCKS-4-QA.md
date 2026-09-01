# POST-RC.8 Blocks — `1.0.0-dev.blocks.4` Owner QA Package

**Document status:** Packaged for owner physical QA of constrained Delivery Area fallback. Not RC.9. Not deployed.  
**Date:** 2026-09-01  
**Branch:** `feat/post-rc8-integrations`  
**QA identity:** `1.0.0-dev.blocks.4`  
**Schema:** `5`  
**Protected published baseline:** tagged `v1.0.0-rc.8` / `6d166227998d4b0f5047fea91944ff024b389810` **untouched**  
**FLAIROC:** not modified  
**Repair record:** `docs/POST-RC8-BLOCKS-4-CONSTRAINED-FALLBACK.md`  
**Historical Blocks.3 ZIP:** immutable; keep it as the matched-area pricing artifact

---

## Source and ZIP

| Item | Value |
|------|-------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.4.zip` |
| Version | `1.0.0-dev.blocks.4` |
| Schema | `5` |
| Branch | `feat/post-rc8-integrations` |
| Runtime-fix commit | `47d326ab3ca95f9487882bcd260704f84b3621af` |
| Package-source commit | `796b9a52add1053520b2e440d60aa88814d6dd0c` |
| Bytes | `1375134` |
| SHA-256 | `494c20a88d88c359f549402de4832828081380c047111a90afdff066fe32ef65` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.4.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.4.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.blocks.4 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.4.zip` (clean tree; **not** `-AllowDirty`) |
| RC.8 tag | `v1.0.0-rc.8` still peels to `6d166227998d4b0f5047fea91944ff024b389810` |

Cursor did **not** install this ZIP. Do not deploy to FLAIROC.

---

## Automated tests at packaging source

| Suite | Result |
|-------|--------|
| `ConstrainedFallbackGeographyTest` + `ConstrainedFallbackAdminUsabilityTest` | **7 tests, 26 assertions, OK** |
| `MatchedAreaPricingFallbackTest` | **14 tests, 31 assertions, OK** |
| Destination zone matcher / ordering / region equivalence | **14 tests, 36 assertions, OK** |
| `OverlappingDeliveryAreaCoverageTest` | **4 tests, 9 assertions, OK** |
| `BlocksCheckoutAdapterTest` | **17 tests, 65 assertions, OK** |
| Full PHPUnit | **840 tests, 4798 assertions, OK** (5 pre-existing deprecations) |
| Full JS | **32 / 32 OK** |
| Production PHP lint (source tree) | **399 OK / 0 FAIL** |
| Composer | `composer.json` valid |

Previous blocks.3 baseline was 828 tests / 4760 assertions.

---

## Extracted package verification

| Check | Result |
|-------|--------|
| One plugin root | PASS |
| Identity `1.0.0-dev.blocks.4` | PASS |
| Not `blocks.3` / not `rc.8` / not `rc.9` | PASS |
| Schema `5` | PASS |
| Production Composer autoload | PASS |
| Linux-case classmap | PASS |
| `is_unrestricted_fallback` present | PASS |
| Test an address country selector + constrained/global fallback copy present | PASS |
| Blocks JS present (`assets/frontend/blocks-checkout.js`) | PASS |
| `cart_checkout_blocks` declaration | PASS |
| No `tests/` / `phpunit.xml` / PHPUnit vendor / `node_modules` / `.git` / `.env` / nested ZIPs | PASS |
| Packaged plugin PHP lint (excluding vendor) | **401 OK / 0 FAIL** |
| `verify-production-package-autoload.php` on extracted root | **exit 0** |

The verifier still prints a stale “Schema target 4…” success string. Actual `SchemaVersion::TARGET` in the ZIP is `'5'`.

---

## Owner physical QA

Site: **training.cetechbpa.com**  
Replace Blocks.3 with this ZIP in place. Do **not** uninstall/delete Delivery Engine data. Keep temporary native Blocks pages assigned. Keep WoodMart layouts 1482, 1496 and 1500 in draft.

Confirm:

1. **Test an address** Country is a WooCommerce country list (Ghana, United States), not a typed ISO field.
2. Greater Accra marked Fallback with Ghana + Greater Accra rules does **not** match United States / New York.
3. A ruleless Fallback can still catch an unmatched address.
4. No matching area and no global fallback stays unmatched / fail closed. Native WooCommerce shipping is not substituted.
5. Accra + Greater Accra overlap still works; Air can still inherit Greater Accra pricing.
6. Pickup remains free. International remains Air/Sea only. In Warehouse remains local delivery only.

Do **not** begin RC.9.
