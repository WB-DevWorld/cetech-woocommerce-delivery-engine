# POST-RC.6 Bulk Tools — `1.0.0-dev.bulk.8` Owner QA Package

**Document status:** Packaging / owner-physical-QA record  
**Date:** 2026-08-28  
**Branch:** `integration/post-rc6-bulk-r1`  
**Plugin identity:** `1.0.0-dev.bulk.8`  
**Schema:** `5`  
**Protected published baseline:** tagged `v1.0.0-rc.6` / schema `4` **untouched**  
**This is not:** RC.6, RC.7, Stage 15, or a physical PASS  
**FLAIROC:** not modified  
**Cursor must not install this ZIP** on training.cetechbpa.com, FLAIROC, or any other owner site.

---

## 1. What this package is

`1.0.0-dev.bulk.8` is the authorised owner-physical-QA package of the Bulk Tools **background-execution portability** repair on the combined Bulk Tools + R1 stream.

It exists so Bulk Tools can be retested on training.cetechbpa.com **without requiring a 1-minute host cron**. The owner will restore the training site’s WordPress cron timer to its original **5-minute** cadence before testing.

`1.0.0-dev.bulk.7` remains an **immutable historical QA artifact**. It does **not** include this repair. Do not overwrite or rebuild it.

---

## 2. Source

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.8.zip` |
| Version | `1.0.0-dev.bulk.8` |
| Schema | `5` |
| Branch | `integration/post-rc6-bulk-r1` |
| Portability-repair commit | `ed57664e61087aca09eda58b904b9edf0e78aac2` |
| Package-source commit | `ed57664e61087aca09eda58b904b9edf0e78aac2` (identity `1.0.0-dev.bulk.8` is in that commit) |
| Bytes | `1240853` |
| SHA-256 | `d8db4669e7a85ba178b9d8b7b6ab6a22e015562fe81cdc8e15262df55b263ea1` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.8.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.8.zip` |
| Build | `scripts/build-v1-rc-package.ps1 -Version 1.0.0-dev.bulk.8 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.8.zip` (clean tree; **not** `-AllowDirty`) |

Repair record: `docs/POST-RC6-BULK-BACKGROUND-PORTABILITY.md`. Matrix items **1–16** remain mapped there.

---

## 3. Historical bulk.7 (unchanged)

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.7.zip` |
| Source | `8fb99c9b43f16cbe96f478347af9601a6581fb80` |
| Bytes | `1226695` |
| SHA-256 | `15ae60d059ef68cebae29329012a5b1e11373e7452c723701d39b5e86e5b50b1` |

Verified after the bulk.8 build: same bytes and SHA-256.

---

## 4. Automated results (exact pre-package source)

Run on the committed tree `ed57664` immediately before packaging:

| Gate | Result |
|------|--------|
| PHPUnit (Unit + Integration) | **735 tests, 4157 assertions, OK** (5 pre-existing deprecations) |
| Focused Bulk + Plugin-boot integration | **108 tests, 510 assertions, OK** (2 deprecations in that subset) |
| R1 `PostRc6AdminSetupRepairR1*` | **17 tests, 361 assertions, OK** |
| JS `npm run test:js` | **22 passed / 22** |
| PHP lint (29 changed PHP files) | **0 errors** |
| `composer validate --no-check-publish` | valid |
| Security suite | **not run** (still isolated on `wip/rc6-adversarial-security-audit`) |
| Playwright / live wp-admin / training / FLAIROC | **not run** |

Plugin-boot integration covers Action Scheduler missing (fail-safe) and a process-isolated live-gateway async enqueue that does not walk the catalog in the creating request. Preview / apply / rollback / cancel, ECR, and shipment tests are inside the 735.

---

## 5. Extracted-package verification

Extracted outside the repository to `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-bulk8-verify-extract\`.

| Check | Result |
|-------|--------|
| One plugin root `cetech-woocommerce-delivery-engine/` | PASS |
| Version `1.0.0-dev.bulk.8` | PASS |
| `SchemaVersion::TARGET = 5` | PASS |
| Production `vendor/autoload.php` + `scripts/verify-production-package-autoload.php` | PASS (Linux-case classmap included) |
| Portability sources (`WpActionSchedulerGateway`, `BulkJobRunnerState`, `BulkQueueHealth`, `advance=1` JS, pending-args diagnostic) | PASS |
| Bulk Tools + R1 (`WooCommerceCountryCatalog`, header `form=` Create/Save) | PASS |
| No `tests/`, no `phpunit.xml`, no `node_modules`, no `.git`, no `.env`, no `vendor/phpunit`, no nested ZIPs | PASS |
| Packaged PHP lint excluding vendor | **388 files / 0 failures** |
| Identity is not `1.0.0-dev.bulk.7` or `1.0.0-rc.7` | PASS |

**No physical PASS.** Owner must install and test on training.cetechbpa.com.

---

## 6. Owner physical QA purpose

Prove Bulk Tools works acceptably **without a 1-minute host cron**. Restore the training site’s WordPress cron timer to **5 minutes** before testing.

### A. Preview

- One product
- Job begins/advances without waiting ~4 minutes
- Correct preview produced
- No mutation during preview

### B. Apply

- One product
- Bounded background progression
- Correct mutation
- Correct counters

### C. Rollback

- Restore original inheritance/state
- Correct Current / Will restore presentation
- Correct 1 / 1 bookkeeping
- No duplicate rollback

### D. Browser fallback

- Leave Bulk Tools page open
- If the background runner does not advance promptly, bounded browser continuation can safely progress the job

### E. Close/reopen

- Closing the browser does not cancel/corrupt the durable job
- Reopening shows accurate job state

### F. Waiting/stale UX

- Delayed worker gets understandable waiting state
- Genuinely stale job becomes actionable
- No Action Scheduler jargon in ordinary UI

### G. Cancellation

- Cancel while waiting
- Cancel during processing where safely testable

### H. No duplicates

- No duplicate product mutations
- No duplicate rollback
- Counters remain correct

Also continue unfinished bulk.7 R1 remainder checks on this same installation if still needed. Do **not** use this package to judge In Store Delivery + optional Store Pickup, or International training-product observations.

---

## 7. Confirmations

- Historical `1.0.0-dev.bulk.7` ZIP **unchanged**
- Tag `v1.0.0-rc.6` **unchanged** (peeled `8f37fe826e23406c9035312e279699b65c1e72e4`)
- FLAIROC **untouched**
- Schema still **5** (no schema 6)
- **No RC.7**
- Cursor did **not** install the ZIP

---

## STOP

Owner should install **only** `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.8.zip` for training-site physical QA of this portability repair. Cursor must not deploy it. Do not begin Stage 15.
