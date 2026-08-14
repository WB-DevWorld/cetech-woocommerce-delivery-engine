# Stage 13D — Final admin simplification, legacy retirement, real role permissions

**Document status:** Local implementation + QA.4 packaging record. Owner physical FLAIROC retest is the remaining gate.  
**QA version:** `1.0.0-rc.3-qa.4`  
**Schema target:** `3` (unchanged; no migration)  
**Branch:** `feat/site-wide-delivery-defaults`  
**HEAD:** `5e6508bbdc7180393aaf0e53e2bf9d70f75090fc`  
**Working tree:** dirty / uncommitted Stage 13 / 13B / 13C / 13D tree (packaged with `-AllowDirty`)  
**RC.2 tag:** `v1.0.0-rc.2` — **untouched**  
**Date:** 2026-08-14  
**FLAIROC:** not modified  
**Final RC.3 commit/tag:** none

---

## 1. Verdict

**READY FOR OWNER PHYSICAL FLAIROC RETEST of QA.4.**

Stage 13D is the final normal-admin simplification pass before public RC.3. It does not invent a new architecture and does not rewrite the proven runtime.

QA.3 owner-tested R3 repairs remain in place and were not reopened.

---

## 2. Menu changes

Normal Delivery Engine submenu (when setup is complete):

- Overview
- Site-wide Defaults
- Delivery Options
- Delivery Areas
- Delivery Charges
- Pickup Locations
- Product Exceptions
- Needs Attention
- Settings

Setup Guide appears in the normal menu **only while setup is incomplete**. Completed stores reopen it from **Settings → Run Setup Guide Again**. The page remains registered under `options.php` so the Settings link still works.

Removed from the normal submenu:

- Legacy Delivery Rules
- Technical Diagnostic Tools
- Delivery Settings Preview (contextual only: Overview, product/variation panel, troubleshooting)
- Logistics Profiles
- Suppliers & Origins
- Rate Cards / Destination Zones / Delivery Offers as internal names (normal titles remain Delivery Charges / Areas / Options)
- old Product Rules

Hidden support destinations (`options.php`, not everyday menu items):

- Technical Diagnostics (`view_delivery_diagnostics`)
- Delivery Settings Preview
- Product Delivery Settings (customize)
- Logistics Profiles
- Suppliers & Origins

---

## 3. Legacy retirement behavior

Legacy Delivery Rules is retired from the **normal product**.

- No normal submenu.
- No create/edit workflow (`ProductDeliveryRulesPage::handle_actions()` is a no-op).
- New installations never see a Legacy management page.
- Staff cannot create new legacy rules through admin.
- Legacy is not a second configuration workflow beside Site-wide Defaults / Product Exceptions.

Database tables and historical rows are **not dropped**. FLAIROC currently has `0` products relying on Legacy Delivery Rules. Those dormant rows must not create UI clutter.

Internal compatibility readers and category-compat runtime remain for upgrade safety only. Unresolved genuine customer problems still surface through **Needs Attention** in plain business wording. No Legacy management page is restored merely because historical rows exist.

Normal Overview / Settings / Wizard copy no longer teaches “Legacy Delivery Rules”, “New Delivery Settings System”, or shoppers using a second old system.

---

## 4. Hidden upgrade-compatibility decision

| Decision | Result |
|----------|--------|
| Drop legacy tables | **No** — historical data kept |
| Normal Legacy submenu / create UI | **Removed** |
| Hidden Legacy management page | **Not registered** (not even under `options.php`) |
| Internal product-rule repository / resolver | **Preserved** for older installs that still use that runtime path |
| Overview legacy counts / compatibility card | **Removed** |
| Transitional banner while setup is incomplete | Plain wording: “Existing delivery configuration is still serving customers while you finish Delivery Engine setup.” Removed once current Delivery Engine setup is operational. |

---

## 5. Real role permissions manager

Settings → **Access** is a real WordPress role permissions matrix.

- Roles are read live from `wp_roles()`. Only roles that exist are shown.
- No fictional “Staff” row.
- WordPress capabilities remain the authority (`current_user_can()` at page/action level).
- Saving Access grants/revokes only Delivery Engine capabilities. Unrelated WordPress capabilities are not changed.
- Only users with `manage_options` can edit the matrix.

### Capability mapping

| Business permission | WordPress capability |
|---------------------|----------------------|
| View Delivery Engine | `view_delivery_engine` |
| Manage Site-wide Defaults | `manage_site_wide_defaults` |
| Manage Delivery Options | `manage_delivery_offers` |
| Manage Delivery Areas | `manage_delivery_zones` |
| Manage Delivery Charges | `manage_delivery_rate_cards` |
| Manage Pickup Locations | `manage_pickup_locations` |
| Manage Product Exceptions | `manage_product_delivery_rules` |
| Manage Delivery Settings | `manage_delivery_settings` |
| View Technical Diagnostics | `view_delivery_diagnostics` |

Raw capability strings are not shown in the normal table. They may appear under Technical details.

### Dependencies

Any Manage-* resource permission implies View Delivery Engine (server-side on save; UI also keeps View checked). Diagnostics stays independently controlled.

### Self-lockout protection

Administrator **View Delivery Engine** and **Manage Delivery Settings** are locked/read-only in the matrix. `Capabilities::ensure_administrator_recovery()` still self-heals those two caps on boot. Shop Manager and other roles are not reset on QA.4 / capability version 3 upgrade.

### Upgrade preservation

Capability version is **3**.

- Stored version `< 2`: historical `register()` default matrix (administrator + shop_manager).
- Stored version `2`: **additive** v3 caps only (`view_delivery_engine` if the role already has any Delivery Engine cap; `manage_pickup_locations` if it has zones; `manage_site_wide_defaults` if it has settings) plus administrator recovery.
- Stored version `3`: no-op.

Folder-replacing QA.3 with QA.4 does not reset Shop Manager assignments.

---

## 6. RateCard warning repair

`RateCardValidator` treated missing `effective_from` / `effective_to` as required array keys and emitted PHP warnings on FLAIROC during QA.3.

Those fields are optional. Missing or blank keys are now treated as not supplied (`??` + `is_supplied_date()`). Invalid supplied dates still fail validation. Dates are not reinterpreted as pricing.

---

## 7. Settings layout

Primary sections: General, Customer Experience, Orders, Access, Setup Guide, Advanced.

One primary **Save Changes** for editable settings. Low-level runtime switches sit under collapsed Advanced. Unsupported future controls (shipments, tracking, timeline, bulk import) remain unavailable/read-only.

---

## 8. Runtime non-regression

Not rewritten in this pass:

- GLOBAL → PRODUCT → VARIATION through EffectiveConfigurationResolver
- Stage 6 simple + variable runtime
- Stage 8 grouping / WooCommerce shipping rates
- explicit numeric zero safety; unresolved ≠ free shipping
- checkout validation, cart persistence, HPOS snapshots, snapshot immutability
- customer / supplier / origin privacy
- no silent offer replacement; server authority
- QA.3 Preview variation loading
- QA.3 International: Customer fulfilment = Delivery only; shipping options = Air / Sea / Air+Sea
- Site-wide Defaults compatibility filtering
- OperationalReadinessAssessor shared by Preview / Product Exceptions / Needs Attention

---

## 9. Tests

| Check | Result |
|-------|--------|
| `composer validate --no-check-publish` | Pass — `./composer.json is valid` |
| PHP lint (`src/`, `database/`, `scripts/`, bootstrap) | Pass — 261 files |
| PHPUnit | **297 tests, 1461 assertions, OK** (baseline before this stage: 277 / 1361) |
| `npm run test:js` | **10 tests passed** |
| Root Playwright | **Not run** — `@playwright/test` is not installed in the repository root |

Focused Stage 13D coverage:

- `tests/Unit/Presentation/Admin/RateCardValidatorOptionalDatesTest.php`
- `tests/Unit/Presentation/Admin/Stage13DAdminSimplificationTest.php`

---

## 10. Files changed (Stage 13D)

Principal implementation:

- `src/Presentation/Admin/Validation/RateCardValidator.php`
- `src/Core/Capabilities/Capabilities.php`
- `src/Core/Capabilities/RoleAccessService.php` *(new)*
- `src/Presentation/Admin/AdminMenu.php`
- `src/Presentation/Admin/DeliverySettingsPage.php`
- `src/Presentation/Admin/OverviewPage.php`
- `src/Presentation/Admin/PickupLocationsPage.php`
- `src/Presentation/Admin/ProductDeliveryRulesPage.php`
- `src/Presentation/Admin/SystemStatusPage.php`
- `src/Presentation/Admin/SetupWizardPage.php`
- `src/Presentation/Admin/FeatureFlagLabels.php`
- `src/Application/Configuration/Admin/ScopedConfigurationAuthorization.php`
- `src/Application/Configuration/OperationalStateService.php`
- `src/Bootstrap/Plugin.php`
- `assets/admin/delivery-engine-admin.js`
- `assets/admin/delivery-engine-admin.css`
- `cetech-woocommerce-delivery-engine.php` / `readme.txt` (QA.4 identity only)
- `scripts/verify-production-package-autoload.php`
- `tests/Unit/Presentation/Admin/RateCardValidatorOptionalDatesTest.php`
- `tests/Unit/Presentation/Admin/Stage13DAdminSimplificationTest.php`
- `docs/STAGE-13D-FINAL-ADMIN-SIMPLIFICATION.md`
- `docs/AI-HANDOFF.md`
- `docs/ADMIN-UI-LANGUAGE-GUIDE.md` (flag labels only)

Schema / migration impact: **none**. Target remains `3`.

Visual fixture pack: **not regenerated**.

---

## 11. QA.4 package

Built with:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.3-qa.4 -AllowDirty -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-rc.3-qa.4.zip
```

| Artifact | Path |
|----------|------|
| Dist ZIP | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.3-qa.4.zip` |
| Desktop ZIP | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.3-qa.4.zip` |
| Bytes | `839133` |
| SHA-256 | `0cc67f132acf4c5d36cf74caeb99541c729ebf06528ffb152af95a336040d07e` |

ZIP root folder: `cetech-woocommerce-delivery-engine/`  
This is **not** `v1.0.0-rc.3`.

Extracted verification used `build/qa4-verify-extract/cetech-woocommerce-delivery-engine/`, not only the source tree. Production autoload verification, packaged PHP lint, schema target `3`, QA.4 version identity, Stage 6/8/13D presence, Legacy/diagnostics normal-menu absence, and RateCard optional-date repair all passed on the extract.

---

## 12. Deferred items

- Shipments, tracking, customer timeline, Blocks checkout, carrier integrations
- Dropping dormant legacy tables (explicitly out of scope)
- Public RC.3 commit/tag until owner accepts QA.4 on FLAIROC
- Full 31-image review fixture regeneration

---

## 13. Git / FLAIROC / RC.2

- FLAIROC was **not** modified. No SSH. No install of QA.4.
- No final RC.3 commit.
- No `v1.0.0-rc.3` tag.
- RC.2 rollback tag/package untouched.
