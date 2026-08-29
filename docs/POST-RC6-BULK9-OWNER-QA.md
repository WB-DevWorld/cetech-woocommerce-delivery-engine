# POST-RC.6 Bulk Tools — `1.0.0-dev.bulk.9` Owner QA Package

**Document status:** Owner physical QA record — **ACCEPTED**  
**Date:** 2026-08-29  
**Branch:** `integration/post-rc6-bulk-r1`  
**Tested identity:** `1.0.0-dev.bulk.9`  
**Schema:** `5`  
**Training site:** training.cetechbpa.com  
**Protected published baseline at QA time:** tagged `v1.0.0-rc.6` / schema `4` **untouched**  
**This package is not:** RC.6, Stage 15, or a new feature stream  
**FLAIROC:** not modified  
**Cursor did not install this ZIP**

---

## OWNER PHYSICAL QA: ALL SIX ACCEPTANCE CHECKS PASSED

Owner physical QA of **`1.0.0-dev.bulk.9`** on **training.cetechbpa.com** is complete and accepted.

| Item | Value |
|------|--------|
| Site | training.cetechbpa.com |
| Tested identity | `1.0.0-dev.bulk.9` |
| Schema | `5` |
| Date | 2026-08-29 |
| Verdict | **ALL SIX ACCEPTANCE CHECKS PASSED** |

| Check | Result |
|-------|--------|
| Catalog preview → Apply → rollback | **PASS** |
| Multi-batch/background processing | **PASS** |
| Validation Scan | **PASS** |
| Remove-last-valid-option safety gate | **PASS** |
| Delivery Charge GHS 50 → 55 → rollback to 50 | **PASS** |
| R1 admin smoke — blank Reference Code, WooCommerce country picker, single clear Create/Save | **PASS** |

### Known non-blocking presentation issue (not repaired)

A Catalog preview where every target fails proposed-state validation correctly reports **Would fail**, but the page may still display **Ready to apply** and an **Apply these changes** button. The unsafe configuration itself is rejected by the resolver/safety gate. This is a known UI/presentation issue, not an accepted proof that invalid configuration can be applied.

Do **not** repair this as part of RC.7 promotion. The physically accepted Bulk.9 runtime is frozen as `1.0.0-rc.7`.

---

## 1. What this package is

`1.0.0-dev.bulk.9` is the authorised owner-physical-QA package of the consolidated Bulk.8 physical-QA repairs on the combined Bulk Tools + R1 stream.

It includes:

- Bulk.9 repairs recorded in `docs/POST-RC6-BULK8-PHYSICAL-QA-REPAIR.md`
- Bulk.8 background-execution portability
- preserved R1 admin/setup repairs

`1.0.0-dev.bulk.7` and `1.0.0-dev.bulk.8` remain **immutable historical QA artifacts**. Do not overwrite or rebuild them.

---

## 2. Source

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.9.zip` |
| Version | `1.0.0-dev.bulk.9` |
| Schema | `5` |
| Branch | `integration/post-rc6-bulk-r1` |
| Repair commit | `85194cb86ab3d906e6642a3f15b1027767a12da8` |
| Package-source commit | `85194cb86ab3d906e6642a3f15b1027767a12da8` (identity `1.0.0-dev.bulk.9` is in that commit) |
| Bytes | `1256253` |
| SHA-256 | `af331732c1529427c85adc8cfaeb7dbf26ef996e2952b3918f15c25da4f100b2` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.9.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.9.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.bulk.9 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.9.zip` (clean tree; **not** `-AllowDirty`) |

Repair record: `docs/POST-RC6-BULK8-PHYSICAL-QA-REPAIR.md`.

---

## 3. Historical bulk.7 and bulk.8 (unchanged)

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.7.zip` |
| Source | `8fb99c9b43f16cbe96f478347af9601a6581fb80` |
| Bytes | `1226695` |
| SHA-256 | `15ae60d059ef68cebae29329012a5b1e11373e7452c723701d39b5e86e5b50b1` |

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.8.zip` |
| Source | `ed57664e61087aca09eda58b904b9edf0e78aac2` |
| Bytes | `1240853` |
| SHA-256 | `d8db4669e7a85ba178b9d8b7b6ab6a22e015562fe81cdc8e15262df55b263ea1` |

Verified after the bulk.9 build: same bytes and SHA-256.

---

## 4. Automated results (exact pre-package source)

Run on the committed tree `85194cb` immediately before packaging:

| Gate | Result |
|------|--------|
| PHPUnit (full suite) | **745 tests, 4223 assertions, OK** (5 pre-existing deprecations) |
| JS `npm run test:js` | **24 passed / 24** |
| PHP lint (19 changed PHP files) | **0 errors** |
| `composer validate --no-check-publish` | valid |
| Identity | `CETECH_DE_VERSION` = `1.0.0-dev.bulk.9`; `SchemaVersion::TARGET` = `5`; no `1.0.0-rc.7` |
| Security suite | **not run** (still isolated on `wip/rc6-adversarial-security-audit`) |
| Playwright / live wp-admin / training / FLAIROC | **not run** |

---

## 5. Extracted-package verification

Extracted outside the repository to `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-bulk9-verify-extract\`.

| Check | Result |
|-------|--------|
| One plugin root `cetech-woocommerce-delivery-engine/` | PASS |
| Version `1.0.0-dev.bulk.9` | PASS |
| `SchemaVersion::TARGET = 5` | PASS |
| Production `vendor/autoload.php` + `scripts/verify-production-package-autoload.php` | PASS (Linux-case classmap included) |
| Bulk Tools runtime (`BulkJobEngine`, `CatalogScopeMutator::scan()`, `RateCardBulkMutator`, `advance=1` JS, `WpActionSchedulerGateway`) | PASS |
| R1 runtime (`WooCommerceCountryCatalog`, header `form=` Create/Save) | PASS |
| No `tests/`, no `phpunit.xml`, no `node_modules`, no `.git`, no `.env`, no `vendor/phpunit`, no nested ZIPs | PASS |
| Packaged PHP lint excluding vendor | **388 files / 0 failures** |
| Identity is not `1.0.0-dev.bulk.8` or `1.0.0-rc.7` | PASS |

Owner physical QA of this extracted package on training.cetechbpa.com is recorded above: **ALL SIX ACCEPTANCE CHECKS PASSED**.

---

## 6. Owner physical QA — six checks only

Do **not** expand this matrix unless one of these six checks actually fails.

1. Catalog preview → Apply → rollback.
2. One multi-batch/background job on the normal host schedule.
3. Validation Scan with real Valid/Warning/Invalid results and **no Apply button**.
4. Remove-last-valid-option preview blocked with `no_valid_delivery_path`.
5. Delivery Charge GHS 50 → 55 → rollback to 50.
6. R1 smoke: blank Reference Code + country picker + single clear Create/Save action.

Do **not** retry historical Job 19 rollback; Charge #1 was later restored manually after that job.

Do **not** use this package to judge In Store Delivery + optional Store Pickup, or International training-product observations.

---

## 7. Confirmations

- Historical `1.0.0-dev.bulk.7` ZIP **unchanged**
- Historical `1.0.0-dev.bulk.8` ZIP **unchanged**
- Historical Bulk.9 ZIP **unchanged** after this QA record
- Tag `v1.0.0-rc.6` **unchanged** (peeled `8f37fe826e23406c9035312e279699b65c1e72e4`)
- FLAIROC **untouched**
- Schema still **5** (no schema 6)
- Cursor did **not** install the ZIP
- The known Catalog preview Ready-to-apply presentation issue was **not** repaired

---

## STOP

**OWNER PHYSICAL QA: ALL SIX ACCEPTANCE CHECKS PASSED** on training.cetechbpa.com for `1.0.0-dev.bulk.9` / schema `5` on 2026-08-29.

This document records the accepted Bulk.9 package. Promotion of that exact runtime to tagged `1.0.0-rc.7` is a separate identity/finalization step. Do not begin Stage 15. Do not modify FLAIROC. Do not repair the known non-blocking Catalog preview presentation issue as part of that freeze.
