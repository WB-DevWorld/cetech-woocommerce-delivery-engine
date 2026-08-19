# Stage 14H-FINAL — RC.5 finalization

**Document status:** Final Stage 14 / RC.5 release record  
**Release version:** `1.0.0-rc.5`  
**Schema target:** `4` (unchanged; no migration)  
**Branch:** `master`  
**Accepted functional baseline:** owner-physical PASS of `1.0.0-rc.5-qa.6` on FLAIROC  
**RC.4 tag:** `v1.0.0-rc.4` — **untouched**  
**QA.1–QA.6 packages:** **immutable**  
**Date:** 2026-08-19  
**FLAIROC:** **NOT MODIFIED** in this stage (owner installs the final ZIP after reviewing this record)

---

## 1. Verdict

**STAGE 14H-FINAL COMPLETE — `1.0.0-rc.5` PREPARED FOR OWNER CLEAN-FOLDER INSTALL**

Owner physical QA of **`1.0.0-rc.5-qa.6`** on FLAIROC is **PASS**. This stage promotes that accepted runtime to the final RC.5 identity, documentation, training, package, and tag.

This is **not** Stage 15. FLAIROC was not modified from Cursor.

---

## 2. Purpose

Finalize the owner-accepted Stage 14 shipment operations V1 as the tagged release candidate:

`1.0.0-rc.5`

No new functionality. No redesign. No Checkout Blocks, carrier APIs, tracking automation, driver workflows, bulk shipping, POD, or other future scope.

---

## 3. Owner acceptance baseline (authoritative)

Live version during owner QA: **`1.0.0-rc.5-qa.6`**  
Schema: **4**

### Final FLAIROC readiness verification

| Check | Result |
|-------|--------|
| Public storefront | HTTP 200 |
| REST | HTTP 200 |
| Administrator `manage_shipments` | YES |
| Administrator `update_shipment_status` | YES |
| Administrator `manage_options` | YES |
| Shipment tables physically InnoDB | YES |
| Shipments / items / events at verification | **19 / 21 / 46** |

Final owner check found **no** site PHP log at `$PWD/logs/error.log` or `$PWD/wp-content/debug.log`. Storefront and REST remained HTTP 200 and no physical fatal was observed during the accepted QA workflow. **Do not claim “final PHP log clean.”**

### Inheritance

- No artificial Product/Variation configuration scopes for untouched **4156**, **39420**, **39422**, **39424**, **39426**
- Site-wide setup complete: YES
- Primary profile: `in_warehouse`
- Active profiles: `in_warehouse`, `in_store`, `international_fulfilment`
- Untouched simple/variation storefront inheritance confirmed under QA.4 / QA.5
- Explicit product **39705** remains an exception and works

### Shipment evidence

| Shipment | Owner result |
|----------|----------------|
| **39737-D1** | Delivered; complete status/tracking/ETA happy path |
| **39742-D1** | COD manually created from historical order; later real `payment_complete()` remained exactly one shipment |
| **39744-D1** | International Air |
| **39744-D2** | International Sea |
| **39746-D1** | Mixed Pickup + Delivery produced only the genuine delivery shipment; pickup produced no fake shipment |
| **39747-D1** | Cancellation before dispatch auto-cancelled shipment |
| **39748-D1** | Cancellation after dispatch preserved Dispatched and raised Needs Attention |
| **39753-D1** | Amount-only refund with quantity 0 preserved shipment and raised refund review |
| **39755-D1** | Explicit full item-quantity refund before dispatch auto-cancelled |
| **39757-D1** | Refund after dispatch preserved Dispatched and raised refund review |

Manual shipment creation events physically verified with `actor_user_id = 3`, source staff.

### UI / polish accepted

Staff History unique identity (`Jane Love (Staff · User #3)`); customer Track shipment button; customer shipment cards separated from WooCommerce Order Again; Needs Attention badge; per-user Shipments activity badge; COD awaiting-creation Needs Attention with View Order / Create Shipment; manual COD creation from historical data; duplicate manual creation prevented.

### Refund repair accepted

Amount-only / unproven physical refund → review, no auto-cancel. Explicit full quantity refund before dispatch → auto-cancel. Refund after physical progress → preserve shipment + review.

---

## 4. Source audit (pre-finalization)

| Item | Value |
|------|--------|
| Branch | `master` tracking `origin/master` |
| Pre-finalization HEAD | `732947b` (QA.6 docs SHA commit; already pushed) |
| Working tree | Clean |
| Schema target | `4` |
| Existing `v1.0.0-rc.5` tag | **none** |
| `v1.0.0-rc.4` | `69a167432a2b895c9bae97aecb98d8d0abe1b16e` (annotated); peeled `6b70c29` — **untouched** |
| `v1.0.0-rc.2` | present locally and on origin — **untouched** |
| `v1.0.0-rc.3` | present **locally** (`e395a47…`); **not listed** by `git ls-remote --tags origin` in this audit. Not pushed or rewritten by this stage. |
| QA.1–QA.6 ZIPs | retained; not overwritten |

No unexpected dirty tree. Finalization proceeds.

---

## 5. What RC.5 finalizes

| Area | Outcome |
|------|---------|
| Stage 14 V1 | Shipment records, staff workspace, tracking, customer cards, operations, COD action-required queue, manual historical creation, quantity-aware refund review |
| Version identity | `1.0.0-rc.5` (plugin header, `CETECH_DE_VERSION`, readme Stable tag) |
| Schema | Target remains **4** |
| Flags | Stage 14 flags still default **OFF** |
| Training | `docs/training/11-STAGE-14-SHIPMENTS.md` finalized |

Inherited unchanged from QA.6 runtime: QA.3 optional/defaultable ECR semantics, genuine WooCommerce shipping, immutable snapshots, idempotency, Air/Sea split, pickup suppression, privacy boundaries, badges, staff capabilities, Administrator `manage_options` recovery.

---

## 6. Source gates (final)

Filled after the qualification run in this stage.

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | valid |
| Production PHP lint | **323 files, 0 failures** |
| Stage 14 shipment suite | **215 tests, 1244 assertions, OK** |
| PHPUnit | **555 tests, 3050 assertions** |
| Deprecations | **3** (`ReflectionMethod::setAccessible()` PHP 8.5) — same type as QA.6; no new type |
| `npm run test:js` | **11 passed** |
| Playwright | **not run** |

QA.6 baseline: 555 tests / 3050 assertions; 323 PHP files / 0 lint failures; 11 JS passed; 3 PHP 8.5 `ReflectionMethod::setAccessible()` deprecations. Final source matches that baseline. No regression.

---

## 7. Package identity

| Item | Value |
|------|--------|
| ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.5.zip` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.5.zip` |
| Desktop path | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.5.zip` |
| Root folder | `cetech-woocommerce-delivery-engine/` |
| Schema target | `4` |
| Tag | `v1.0.0-rc.5` |
| Built with | `scripts/build-v1-rc-package.ps1` (**no** `-AllowDirty`) |

Exact commit SHA, ZIP byte size, and SHA-256 are filled after the clean tagged build.

---

## 8. Extracted package verification

Filled after independent extraction.

---

## 9. Deliberately not in RC.5

- Checkout Blocks
- carrier APIs / live quotes / automatic tracking synchronisation
- shipment emails
- customer timeline
- driver accounts, GPS, OTP, QR, POD, signatures
- bulk shipping / bulk import
- Stage 15 or later roadmap work
- FLAIROC deploy from Cursor

---

## 10. Owner install (after this package exists)

**NOT PERFORMED FROM CURSOR.**

Clean-folder replacement on FLAIROC of **only** `cetech-woocommerce-delivery-engine-1.0.0-rc.5.zip`:

1. Record active version (`1.0.0-rc.5-qa.6`), schema **4**, and that evidence shipments still exist.
2. Deactivate CETECH WooCommerce Delivery Engine.
3. **Delete** the entire `wp-content/plugins/cetech-woocommerce-delivery-engine/` folder (do not overlay).
4. Install the final RC.5 ZIP.
5. Activate.
6. Confirm plugin version **`1.0.0-rc.5`**, schema still **4**, and existing shipment evidence still present (including the table above).
7. Do **not** drop shipment tables. Do **not** place a new QA order unless something is wrong.

Keep the QA.6 ZIP available for rollback of identity only; schema 4 and shipment rows remain.

---

## 11. Next step

After owner confirmation that FLAIROC is running **`1.0.0-rc.5`**: **STOP**.

Do not begin Stage 15, Checkout Blocks, carrier integrations, automated tracking, drivers, or POD.
