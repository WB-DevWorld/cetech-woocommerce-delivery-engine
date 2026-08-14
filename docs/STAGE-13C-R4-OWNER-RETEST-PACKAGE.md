# Stage 13C-R4 — QA.3 owner retest package

**Document status:** Packaging record for owner FLAIROC retest  
**QA version:** `1.0.0-rc.3-qa.3`  
**Schema target:** `3` (unchanged)  
**Branch:** `feat/site-wide-delivery-defaults`  
**HEAD:** `5e6508bbdc7180393aaf0e53e2bf9d70f75090fc`  
**Working tree:** dirty / uncommitted Stage 13 / 13B / 13C-R1 / 13C-R3 repair tree (packaged with `-AllowDirty`)  
**RC.2 tag:** `v1.0.0-rc.2` (`ae08491978b6f07fff0b038a4ff0f2586c0e1d26`) — **untouched**  
**Date:** 2026-08-14  
**FLAIROC:** not modified  
**Final RC.3 commit/tag:** none

---

## 1. Why QA.3 exists

QA.2 is currently installed on FLAIROC. Stage 13C-R3 repaired four live QA.2 owner findings locally. Those repairs have not been installed on FLAIROC yet.

QA.3 is an **owner-retest QA build only**. It is **not** tagged `v1.0.0-rc.3`. It exists so the owner can folder-replace QA.2 on FLAIROC and physically retest the four remaining release blockers.

---

## 2. Verdict

**READY FOR OWNER PHYSICAL FLAIROC RETEST**

Built with:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.3-qa.3 -AllowDirty -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-rc.3-qa.3.zip
```

| Artifact | Path |
|----------|------|
| Dist ZIP | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.3-qa.3.zip` |
| Desktop ZIP | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.3-qa.3.zip` |
| Bytes | `826478` |
| SHA-256 | `45f0773b0191656aa7c7925b6f16caa0ec96027e7ea41ed47a2c02bc25e81eb3` |

ZIP root folder: `cetech-woocommerce-delivery-engine/`  
This is **not** `v1.0.0-rc.3`.

---

## 3. R3 repairs included

The ZIP was built from the current repaired working tree, including uncommitted files.

| # | Repair | Packaged |
|---|--------|----------|
| 1 | Setup Guide Step 3 nested-form / Save & Continue validation | Yes — `SetupWizardPage` validation, no nested create form, progression stays on Step 3 |
| 2 | International Delivery-only wording and Air/Sea guided creation | Yes — customer fulfilment vs International shipping options; Air/Sea only |
| 3 | Delivery Settings Preview variation loading | Yes — `PreviewVariationsEndpoint` + admin JS refresh |
| 4 | Variable-parent / variation readiness false failures | Yes — Stage 6 ↔ Stage 13 item-scope bridge + repaired assessor |

---

## 4. Tests (local, before packaging)

| Check | Result |
|-------|--------|
| `composer validate --no-check-publish` | Pass — `./composer.json is valid` |
| PHP lint (bootstrap, `src/`, `database/`, `scripts/`) | Pass — 260 files |
| PHPUnit | **277 tests, 1361 assertions, OK** |
| `npm run test:js` | **10 tests passed** |
| Root Playwright | **Not run** — `@playwright/test` is not installed in the repository root |
| Reflection deprecation | Unchanged: `ReflectionMethod::setAccessible() is deprecated since 8.5, as it has no effect` |

---

## 5. Package verification (fresh extract of the QA.3 ZIP)

Verified against `build/qa3-verify-extract/cetech-woocommerce-delivery-engine/`, not only the source tree.

| Gate | Result |
|------|--------|
| Single ZIP root `cetech-woocommerce-delivery-engine/` | Pass |
| Plugin bootstrap present | Pass |
| Header / `CETECH_DE_VERSION` / readme Stable tag = `1.0.0-rc.3-qa.3` | Pass |
| `SchemaVersion::TARGET` = `3` | Pass |
| `vendor/autoload.php` exists and loads `Plugin` | Pass |
| `scripts/verify-production-package-autoload.php` | Pass |
| Linux-case PSR-4 classmap | Pass |
| Packaged PHP lint | Pass — 271 files |
| Production vendor has no PHPUnit class | Pass |
| R3 wizard / International / Preview / readiness code present | Pass |
| Stage 6 variable selector JS/CSS | Pass |
| Stage 8 grouping / shipping / snapshot classes | Pass |
| `delivery-engine-admin.css` / `.js` | Pass |
| Capability self-heal on boot | Pass |
| Prior-install operational-state handling | Pass |
| No tests, `node_modules`, Playwright, `.git`, `.env`, `docs/review`, helper plugin, Code Snippets | Pass |

---

## 6. Upgrade safety (QA.2 → QA.3 folder replace)

Folder-replacing the plugin directory does **not** run activation/deactivation hooks.

| Concern | Confirmation |
|---------|--------------|
| Deactivate/reactivate for capabilities | Not required — `Capabilities::ensure_current()` self-heals on boot |
| Reset flags | No — `FeatureFlags::get()` reads stored options; `ensure_defaults()` is activation-only |
| Reset offers / areas / charges / pickup locations | No — no new migration; existing tables untouched |
| Overwrite Site-wide Defaults | No — boot does not call `apply_site_wide()` |
| Mass-apply defaults | No — apply remains a wizard/user action |
| Overwrite product / variation exceptions | No — scoped configuration rows are not rewritten on boot |
| Rewrite order snapshots | No — snapshots persist only on checkout order creation |
| Modify WooCommerce shipping zones/methods | No — method registration is a filter only |
| Silently enable unsupported features | No — shipments / tracking / Blocks flags remain default **off**; stored values preserved |
| Schema target beyond v3 | No — target remains `3`; only the three existing migrations ship |

---

## 7. Git / FLAIROC / RC.2

- FLAIROC was **not** modified.
- No final RC.3 commit.
- No `v1.0.0-rc.3` tag.
- RC.2 tag `v1.0.0-rc.2` remains `ae08491978b6f07fff0b038a4ff0f2586c0e1d26`.
- Desktop RC.2 ZIP path remains present and was not replaced by this build.

---

## 8. Owner retest gates

The owner will physically test **only** these release blockers first:

1. **Wizard Step 3** — empty Delivery Option selection stays on the page with `Select at least one Delivery Option before continuing.`; a compatible selection continues to the next fulfilment setup and never jumps to Delivery Settings Preview.
2. **International** — Delivery-only customer fulfilment; International shipping options are Air and/or Sea; Local Delivery and Store Pickup must not appear; in-context create produces an Air/Sea route.
3. **Preview variations** — FLAIROC Delivery Engine Variable QA Product loads real WooCommerce variations; product change refreshes/clears the list; no raw variation ID entry.
4. **Variable readiness** — Variable QA parent must not falsely fail solely because of the Stage 6 profile slice; valid Local Delivery is recognized; Variation B priority-only customization still inherits the parent Delivery Option; Preview, Product Exceptions, and Needs Attention agree.

---

## 9. Intentionally excluded

- Final RC.3 commit / tag / GitHub release
- FLAIROC install or API/SSH access
- Shipments, tracking, Blocks, carrier integrations
- Regeneration of the 31-screen review fixture pack
- Cleaning or resetting the dirty working tree
