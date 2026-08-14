# Stage 13D-R1 — Final administrator access / recovery hardening

**Document status:** Local implementation complete. QA.5 package built for owner FLAIROC retest.  
**Baseline:** Owner-verified `1.0.0-rc.3-qa.4` on FLAIROC  
**QA package:** `1.0.0-rc.3-qa.5` — see `docs/STAGE-13D-R1-QA5-OWNER-RETEST-PACKAGE.md`  
**Schema target:** `3` (unchanged; no migration)  
**Branch:** `feat/site-wide-delivery-defaults`  
**Date:** 2026-08-14  
**FLAIROC:** not modified  
**Git:** no RC.3 commit / tag

---

## 1. Verdict

**QA.5 PACKAGE READY FOR OWNER PHYSICAL FLAIROC RETEST.**

QA.4 owner-verified work is preserved. This stage closes the only remaining release blocker: Administrator lockout / unreachable repair.

---

## 2. Problem

QA.4 Access/recovery had a logical weakness:

1. “Repair administrator Delivery Engine access” lived inside Technical Diagnostics.
2. Diagnostics requires `view_delivery_diagnostics`.
3. If Administrator lost that custom capability, the repair control could become unreachable through the permissions system it was meant to repair.
4. Settings → Access still showed Administrator as an editable matrix row (even with some disabled cells), communicating that Administrator was a configurable Delivery Engine role.

---

## 3. Final product rule

Delivery Engine may control permissions for subordinate WordPress roles, but must never allow a normal WordPress Administrator to lock itself out of Delivery Engine administration or its recovery mechanism.

Independent recovery authority is native WordPress:

`current_user_can( 'manage_options' )`

---

## 4. Administrator full-access model

- Administrator is **not** an editable Access matrix row.
- Settings → Access shows a protected presentation:

  - Administrator 🔒
  - Full Delivery Engine access
  - Administrators always retain full Delivery Engine access.

- Editable matrix starts with real subordinate roles (Shop Manager, Editor, etc.).
- No invented “Staff” role.
- Access save (`RoleAccessService::apply()`) always calls `protect_administrator()` and ignores any submitted Administrator revoke attempt.
- Protected set = full `Capabilities::ALL` / `Capabilities::ADMINISTRATOR_RECOVERY`.

---

## 5. Independent recovery path

New class: `AdministratorAccessRecovery`

| Property | Behaviour |
|----------|-----------|
| Authority | `manage_options` only |
| Nonce | `check_admin_referer( ACTION, 'cetech_de_nonce' )` |
| Action | Restore full Administrator Delivery Engine capability set |
| Idempotent | Yes |
| Subordinate roles | Untouched |
| Diagnostics dependency | None |

UX:

- When a `manage_options` user is in wp-admin and Administrator is missing required Delivery Engine capabilities, an admin notice appears:

  “Delivery Engine administrator access needs repair.”

- Button: **Restore Administrator Access**
- Success notice after safe redirect.

Technical Diagnostics may still expose the convenience “Repair administrator Delivery Engine access” form when the user has `view_delivery_diagnostics`. That is no longer the only route.

Ordinary Diagnostics access still requires `view_delivery_diagnostics`. `manage_options` alone does not open Diagnostics.

---

## 6. Capability self-heal

`Capabilities::ensure_current()`:

- Fresh installs / version `< 2`: historical `register()` matrix.
- Version `2` → `3`: additive subordinate upgrades only.
- Version already `3`: still runs `ensure_administrator_recovery()` every boot.
- Self-heal repairs missing Administrator Delivery Engine capabilities without resetting Shop Manager or other subordinate Access choices.
- Capability VERSION remains **3** (no subordinate reset migration).

---

## 7. Access page authority

- Editing the matrix still requires `manage_options` (`RoleAccessService::can_edit()`).
- Ordinary Delivery Engine pages/actions continue to use `current_user_can()` / `AdminPageAccess::require_capability()`.
- No role-name-only authorization shortcut.

---

## 8. Runtime non-regression

Not changed:

- EffectiveConfigurationResolver / GLOBAL → PRODUCT → VARIATION
- Stage 6 / Stage 8 runtime
- Checkout validation, snapshots, immutability
- Delivery Option compatibility / International Air-Sea
- Preview variation endpoint
- OperationalReadinessAssessor
- Site-wide Defaults / Product Exceptions / Areas / Charges / Pickup
- Legacy retirement / normal menu cleanup from QA.4
- RateCard optional `effective_from` / `effective_to` warning repair

---

## 9. Tests

| Check | Result |
|-------|--------|
| `composer validate --no-check-publish` | Pass |
| PHP lint (changed PHP) | Pass |
| Focused Stage 13D-R1 | **13 tests, 162 assertions, OK** |
| Stage 13D regression | **13 tests, 91 assertions, OK** |
| Full PHPUnit | **310 tests, 1640 assertions, OK** (1 pre-existing deprecation notice) |
| `npm run test:js` | **10 tests passed** |
| Playwright | Not claimed / not installed at repo root |

Focused coverage file: `tests/Unit/Presentation/Admin/Stage13DR1AdminAccessRecoveryTest.php`

Proves A–L from the Stage 13D-R1 brief:

- A Administrator cannot lose DE caps through Access save
- B Administrator not rendered as editable controls
- C Subordinate roles remain editable
- D Shop Manager not reset by self-heal
- E Recovery succeeds with `manage_options` and zero DE caps
- F Recovery fails without `manage_options`
- G Recovery requires nonce
- H Recovery restores all required Administrator DE caps
- I Recovery is idempotent
- J Diagnostics still requires `view_delivery_diagnostics`
- K Boot self-heals Administrator without resetting subordinates
- L Page/action auth uses `current_user_can()`, not role-name-only checks

---

## 10. Files changed

- `src/Core/Capabilities/Capabilities.php`
- `src/Core/Capabilities/RoleAccessService.php`
- `src/Presentation/Admin/AdministratorAccessRecovery.php` *(new)*
- `src/Presentation/Admin/DeliverySettingsPage.php`
- `src/Presentation/Admin/SystemStatusPage.php` *(copy only)*
- `src/Bootstrap/Plugin.php`
- `assets/admin/delivery-engine-admin.css`
- `scripts/verify-production-package-autoload.php`
- `tests/Unit/Presentation/Admin/Stage13DR1AdminAccessRecoveryTest.php` *(new)*
- `tests/Unit/Presentation/Admin/Stage13DAdminSimplificationTest.php`
- `docs/STAGE-13D-R1-ADMIN-ACCESS-RECOVERY-HARDENING.md`
- `docs/AI-HANDOFF.md`

Schema / migration impact: **none**.

---

## 11. Intentionally excluded

- FLAIROC deploy / SSH
- RC.3 commit / tag
- Broader UI redesign
- Shipments / tracking / Blocks
- Any reopen of QA.4-passed behaviour

---

## 12. Recommended next step

Owner folder-replaces QA.4 with QA.5 on FLAIROC and completes the short retest in `docs/STAGE-13D-R1-QA5-OWNER-RETEST-PACKAGE.md`.

After that passes: finalize and build public RC.3.
