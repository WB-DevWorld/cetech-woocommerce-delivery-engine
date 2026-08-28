# POST-RC.6 Consolidated Development Stream

**Document status:** Integration record for one combined post-RC.6 training-site development stream  
**Date:** 2026-08-28  
**Canonical development branch:** `integration/post-rc6-bulk-r1`  
**Plugin identity:** `1.0.0-dev.bulk.8` (historical packaged identity `1.0.0-dev.bulk.7` remains immutable)  
**Schema target:** `5` (Bulk Tools migration; R1 added no migration)  
**Protected published baseline:** tagged `1.0.0-rc.6` / schema `4` / `v1.0.0-rc.6` **untouched**  
**This is not:** RC.6, RC.6 R1 QA.3, RC.7, or Stage 15  
**FLAIROC:** not modified  
**History:** not rewritten. Historical Bulk and R1 branches, commits, QA ZIPs, and the RC.6 tag remain preserved.

---

## 1. Why the streams were consolidated

Owner decision: stop maintaining Bulk Tools and R1 as separate physical-QA plugin installations.

Training-site testing must use **one** plugin:

1. protected RC.6 runtime foundation
2. current Bulk Tools implementation (schema 5)
3. all approved R1 admin/setup runtime repairs
4. R1 QA.1 failure repairs
5. small duplicate Create/Save UX cleanup

Do **not** keep switching:

```text
R1 ZIP  ↔  Bulk ZIP  ↔  R1 ZIP
```

Once training migrates to schema 5 with this combined build, do not roll back to a separate schema-4 R1 package except for an explicit rollback investigation.

---

## 2. Source identities

| Stream | Branch | HEAD / commits | Version | Schema |
|--------|--------|----------------|---------|--------|
| Bulk Tools (base) | `feat/post-rc6-bulk-tools` | `720b513` (`docs: record 1.0.0-dev.bulk.6 owner-QA package checksum`); runtime package source `fb16554` | `1.0.0-dev.bulk.6` | `5` |
| R1 runtime | `fix/post-rc6-admin-setup-defects` | `79b751abdebf588295d470462ba29721bc4a7562` (setup defects); `fc76c4c911c5ea5832b61b6cd80e6cfcecd455ce` (QA.1 request-path repairs) | n/a (layered) | `4` on that branch |
| R1 QA identity **not carried** | same | `a7f9946d47e484362ab063018ec72af721ff15a8` (`1.0.0-rc.6-r1-qa.2`) | **excluded** | `4` |
| Common RC.6 ancestor | tag `v1.0.0-rc.6` | peeled `8f37fe826e23406c9035312e279699b65c1e72e4` | `1.0.0-rc.6` | `4` |
| Integration | `integration/post-rc6-bulk-r1` | created from Bulk `720b513` | `1.0.0-dev.bulk.7` | `5` |

Bulk working tree at audit: clean of tracked source; untracked `docs/POST-RC6-TESTER-OBSERVATIONS-AUDIT.md` was the known tester-audit leftover (also committed on R1). **Not** unexplained Bulk source.  
R1 working tree at audit: clean.

`bulk.7` was not previously reserved.

---

## 3. How R1 was merged

New worktree/branch based on **current Bulk Tools**, not on the R1 QA branch.

Cherry-picked **runtime only**:

1. `79b751a` — Reference Code generate-before-validate; form recovery; responsive cards; WooCommerce country picker
2. `fc76c4c` — entity-form ownership (`form=`), ISO country POST, draft TypeError repair, request-path tests

Did **not** cherry-pick `a7f9946` (version/package identity `1.0.0-rc.6-r1-qa.2`).

Historical R1 docs copied onto this branch without rewriting:

- `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1.md`
- `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA1.md`
- `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA1-FAILURE-REPAIR.md`
- `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA2.md`
- `docs/POST-RC6-TESTER-OBSERVATIONS-AUDIT.md`

Historical Bulk docs (`docs/POST-RC6-BULK-TOOLS-*.md`) were not rewritten.

---

## 4. Conflicts and exact resolutions

Cherry-pick of `79b751a`:

| File | Result |
|------|--------|
| `assets/admin/delivery-engine-admin.css` | Auto-merged. R1 resilient `minmax(min(100%, …))` plus Bulk Tools CSS retained. |
| `assets/admin/scoped-configuration.css` | Auto-merged. |
| `src/Presentation/Admin/AdminPageLayout.php` | Auto-merged. Kept Bulk `open_page( string $extra_class = '' )` and Bulk content-panel styles; added R1 header submit type. |
| `docs/AI-HANDOFF.md` | **Conflict.** Kept Bulk current-status identity (`1.0.0-dev.bulk.6` / schema `5` at that moment). R1 status lines were not substituted. |

Cherry-pick of `fc76c4c`:

| File | Result |
|------|--------|
| Runtime PHP/JS/tests/bootstrap | Auto-merged. Kept Bulk `is_order_received_page` / `is_account_page` stubs **and** R1 `checked` / `submit_button` / `home_url` / `wp_parse_url`. Form ownership (`ENTITY_FORM_ID`, `form=`) applied on top of Bulk layout. |
| `docs/AI-HANDOFF.md` | **Conflict.** Kept Bulk current-status identity again. |
| `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA1.md` | **Modify/delete.** File existed only on R1 (QA.1 packaging). **Took the R1 file** as the immutable FAIL record. |

Not conflicts (clean adds from R1): country catalog, validators, request-path tests, rendered-form ownership tests, JS country-picker tests.

Identity files (`cetech-woocommerce-delivery-engine.php`, `readme.txt`, `SchemaV4InspectionTest` version regex) were **not** taken from R1.

---

## 5. Duplicate Create/Save UX cleanup

Owner physical QA of R1 QA.2 confirmed the repair, but several identical primary Create/Save buttons competed on long forms.

Combined-stream contract:

- **One** visible primary Create/Save in the page header (`<input type="submit" name="cetech_de_save" form="cetech-de-entity-form">`), outside the entity form (QA.1 nested-form failure must not return).
- Header with a form-associated submit is `position: sticky` (`cetech-de-page-header--sticky-actions`; `top: 32px` desktop / `46px` at `max-width: 782px`) so the action stays reachable on long forms.
- Back remains a secondary header link.
- Cancel remains a secondary in-form link in `.cetech-de-form-actions`.
- Removed the visible in-form toolbar submit and the footer primary `submit_button`.
- One **visually hidden** native submit stays inside the form (`cetech-de-entity-form-native-submit`) so Enter-key / form-descendant submit still works. It is not a competing visible primary button.

Rendered-form ownership tests now assert exactly one visible `button-primary` Create/Save.

---

## 6. Schema / migration

```text
RC.6 / schema 4
    → combined build
    → schema 5 migration runs exactly as the current Bulk implementation intends
```

- `SchemaVersion::TARGET` remains `'5'`.
- Migration `database/migrations/20260821160000_create_bulk_job_tables.php` is unchanged vs Bulk `720b513`.
- R1 created **no** migration and **no** schema 6.
- R1 admin repairs use existing schema-4 entities (offers, areas, rules). They do not depend on bulk-job tables.

### Real MariaDB status

Bulk Tools already passed real MariaDB schema 4→5 on the disposable stack `cetech-de-bulk-tools-qual` (`docs/POST-RC6-BULK-TOOLS-QUALIFICATION.md`).

This consolidation **did not rerun** that Docker/MariaDB campaign. Justification: git diff of `database/`, `SchemaVersion.php`, `BulkJobSchema.php`, and `MigrationRunner.php` against Bulk `720b513` is empty. `SchemaV5InspectionTest` PASS on the combined tree.

That is **not** a new real-DB PASS claim. It is “migration source identical to the already-qualified Bulk implementation.”

**Before installing on training:** take a database backup/snapshot. After training is on schema 5, do not switch back to schema-4 R1 packages.

---

## 7. Combined automated results

PHP 8.5.0 / PHPUnit 10.5.64 / Vitest 3.2.7. Run on the combined tree **after** conflict resolution and UX cleanup. A successful merge is not itself a PASS.

| Gate | Result |
|------|--------|
| R1 (`PostRc6AdminSetupRepairR1*` + `WooCommerceCountryCatalogTest`) | **26 tests, 410 assertions, OK** |
| PHPUnit default (Unit + Integration), including Bulk + core regressions | **703 tests, 3986 assertions, OK** (5 deprecations, same class as bulk.6) |
| PHP lint `src/` + `database/` + plugin root + `uninstall.php` | **381 files, 0 errors** |
| `npm run test:js` | **19 passed / 19** (4 files: variable selector, admin preview, bulk catalog, country picker) |
| `composer validate --no-check-publish` | valid |
| Security suite | **not run** (still isolated on `wip/rc6-adversarial-security-audit`) |
| Playwright / live wp-admin / training / FLAIROC | **not run** |

Core families included in the 703: ECR, inheritance, hard fulfilment, Delivery Options/Areas, Rate Cards, shipping registration, shipments, Administrator recovery, feature flags, HPOS-relevant order stubs, Bulk preview/apply/rollback/cancel/CSV/portability/Rate Card bulk/validation/queue/schema 5.

---

## 8. Package identity

Recorded after the identity commit (see §11). ZIP is built from committed clean source. Cursor must **not** install it.

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.7.zip` |
| Version | `1.0.0-dev.bulk.7` |
| Schema | `5` |
| Branch | `integration/post-rc6-bulk-r1` |
| Source commit | `8fb99c9b43f16cbe96f478347af9601a6581fb80` |
| Bytes | `1226695` |
| SHA-256 | `15ae60d059ef68cebae29329012a5b1e11373e7452c723701d39b5e86e5b50b1` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.7.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.7.zip` |

Extracted verification (`C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-bulk7-verify-extract\`): version `1.0.0-dev.bulk.7`, `SchemaVersion::TARGET = 5`, production `vendor/autoload.php`, `BulkJobEngine.php` / `WooCommerceCountryCatalog.php` / `assets/admin/bulk-tools.js` present, header `form=` + sticky Create/Save + hidden native submit, no `cetech-de-entity-form-toolbar`, no `tests/`, no `phpunit.xml`, no `node_modules`, no `.git`, no `.env`, no `vendor/phpunit`, no nested ZIPs, packaged PHP lint **383 files / 0 failures**. Identity is **not** `1.0.0-rc.6-r1-qa.2`.

Do **not** overwrite historical `1.0.0-dev.bulk.6`, R1 QA.1/QA.2, or RC.6 ZIPs.

---

## 9. Physical QA strategy from now on

Test **one** combined build on training.cetechbpa.com.

**Backup the database first.** This package migrates schema 4 → 5.

### Remaining R1 checks (against bulk.7)

Continue unfinished R1 QA.2 physical tests. Adjust Test 4: expect **one** obvious header Create/Save (sticky on long forms), not several identical primaries. Cancel/Back stay secondary.

1. Delivery Option blank Reference Code generates and saves
2. Delivery Area Germany picker stores `DE`; blank code generates
3. Ghana / Nigeria / United Kingdom / United States / Germany / China by name
4. Validation error: values restored; no critical error; one visible Create/Save; resubmit works
5. Advanced details blank optional numbers: no TypeError
6. Responsive fulfilment cards at wide / narrow / tablet / mobile
7. Short admin regression: Options, Areas, Site-wide Defaults, Product Exceptions

### Current Bulk Tools checks (same installation)

Continue from `1.0.0-dev.bulk.6` unfinished physical QA, now on bulk.7:

1. Schema 5 after activate; previous configuration retained
2. Bulk Tools Catalog / Jobs / History still load (Screen Options 20/25/50/100)
3. Preview then Apply a small catalog change
4. Rollback: counters Restored (not `0 / n`); Current vs Will restore; `edited_after_job` still skips
5. Cancel remaining work does not silently reverse completed items
6. Configuration package export/import still uses codes, not source IDs
7. Storefront checkout unchanged while flags remain off

Do **not** use this package to judge In Store Delivery + optional Store Pickup, or the International training-product observation. Those remain later stages.

---

## 10. Still out of scope

- In Store Delivery + optional Store Pickup correction
- International training-product correction
- browsing/current location
- item-level destination
- multi-address checkout
- Return Policy / Refund Policy
- Checkout Blocks
- carrier automation
- Stage 15
- FLAIROC

---

## 11. Confirmations

- Historical R1 QA.1 ZIP (`1081009` bytes, SHA-256 `6eb0935c1046d4bd65ea017965970b7c0e4784a55d2db2758eab2be3841b5029`) **unchanged**
- Historical R1 QA.2 ZIP (`1092406` bytes, SHA-256 `62c1fea94bcdea60540ea422220ea9c6f275b16a0c08d806091a5538598c3610`) **unchanged**
- Historical Bulk `1.0.0-dev.bulk.2`–`1.0.0-dev.bulk.7` ZIPs **unchanged**
- Packaged `1.0.0-dev.bulk.8` from `ed57664e61087aca09eda58b904b9edf0e78aac2` (`docs/POST-RC6-BULK-BACKGROUND-PORTABILITY-QA.md`) — **no physical PASS**
- Tag `v1.0.0-rc.6` **unchanged**
- FLAIROC **untouched**
- Branches `feat/post-rc6-bulk-tools` and `fix/post-rc6-admin-setup-defects` **not rewritten**

---

## STOP

Owner should install **only** `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.8.zip` for training-site physical QA of Bulk Tools background-execution portability (and remaining R1 checks on the same installation). Cursor must not deploy it.

Historical `1.0.0-dev.bulk.7` remains immutable and does **not** include the portability repair. Record: `docs/POST-RC6-BULK-BACKGROUND-PORTABILITY-QA.md`. **No physical PASS yet.** Do not begin Stage 15. Do not modify FLAIROC.
