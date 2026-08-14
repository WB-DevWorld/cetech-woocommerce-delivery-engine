# Stage 13D-R1 — QA.5 owner retest package

**Document status:** Packaging record for owner FLAIROC retest  
**QA version:** `1.0.0-rc.3-qa.5`  
**Schema target:** `3` (unchanged; no migration)  
**Branch:** `feat/site-wide-delivery-defaults`  
**HEAD:** `5e6508bbdc7180393aaf0e53e2bf9d70f75090fc`  
**Working tree:** dirty / uncommitted Stage 13 / 13B / 13C / 13D / 13D-R1 tree (packaged with `-AllowDirty`)  
**RC.2 tag:** `v1.0.0-rc.2` — **untouched**  
**Date:** 2026-08-14  
**FLAIROC:** not modified  
**Final RC.3 commit/tag:** none

---

## 1. Why QA.5 exists

QA.4 is the current owner-verified FLAIROC baseline. Stage 13D-R1 closed the remaining release blocker locally:

Administrator must remain a protected full-access role, and recovery must remain reachable via native `manage_options` even when every Delivery Engine custom capability is missing.

QA.5 is an **owner-retest QA build only**. It is **not** tagged `v1.0.0-rc.3`.

---

## 2. Verdict

**READY FOR OWNER PHYSICAL FLAIROC RETEST of QA.5.**

Built with:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.3-qa.5 -AllowDirty -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-rc.3-qa.5.zip
```

| Artifact | Path |
|----------|------|
| Dist ZIP | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.3-qa.5.zip` |
| Desktop ZIP | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.3-qa.5.zip` |
| Bytes | `845547` |
| SHA-256 | `789da669c6854089a26460e11a0d3abe614fa2b8f5091adfbda144a1462ec796` |

ZIP root folder: `cetech-woocommerce-delivery-engine/`  
This is **not** `v1.0.0-rc.3`.

---

## 3. What QA.5 contains beyond QA.4

| # | Change | Packaged |
|---|--------|----------|
| 1 | Administrator protected Access presentation (not editable matrix row) | Yes |
| 2 | Access save ignores Administrator revoke attempts; restores full DE capability set | Yes |
| 3 | Independent Restore Administrator Access path (`manage_options` + nonce) | Yes — `AdministratorAccessRecovery` |
| 4 | Boot self-heal for missing Administrator DE caps without resetting subordinate roles | Yes |
| 5 | Diagnostics remains capability-gated; recovery does not depend on it | Yes |

QA.4-passed behaviour is preserved (menu cleanup, real role Access for subordinates, RateCard optional dates, International/Preview/readiness repairs).

---

## 4. Short owner retest list

Folder-replace QA.4 with QA.5 on FLAIROC. Do **not** reactivate unless something fails oddly — capability self-heal runs on boot.

1. **Administrator protected row** — Settings → Access shows Administrator 🔒 / Full Delivery Engine access; no editable Administrator checkboxes.
2. **Subordinate role remains editable** — Shop Manager (or another real role) still has working Access checkboxes; save/reload preserves choices.
3. **Independent recovery authority** — With `manage_options`, recovery notice/button works even if Delivery Engine custom caps are missing; does not require Technical Diagnostics.
4. **PHP log** — no new Delivery Engine PHP notices/warnings/fatals after the short retest.

Optional confidence checks (already PASS on QA.4 — do not reopen unless broken):

- Normal Delivery Engine menu still clean (no Legacy / Technical Diagnostics submenu)
- Shop Manager View / Manage Areas / Charge restriction still work
- Administrator still opens Delivery Engine pages after recovery

---

## 5. Package verification (extracted)

Extract used: `build/qa5-verify-extract/cetech-woocommerce-delivery-engine/`

| Check | Result |
|-------|--------|
| Production autoload verification | Pass |
| Version identity `1.0.0-rc.3-qa.5` | Pass |
| `AdministratorAccessRecovery.php` present | Pass |
| `editable_roles()` / `protect_administrator()` present | Pass |
| Access protected-admin UI copy present | Pass |
| `administrator_missing_required_capabilities()` present | Pass |
| Schema target `3` | Pass |
| Stage 6 / 8 / 13D presence | Pass |

---

## 6. Install notes for FLAIROC

1. Back up the current plugin folder if desired.
2. Folder-replace with the QA.5 ZIP contents (`cetech-woocommerce-delivery-engine/`).
3. Do not wipe options, schema, rate cards, exceptions, or order snapshots.
4. Load any wp-admin screen as an Administrator; if repair is needed, use **Restore Administrator Access**.
5. Complete the four-item retest list above.
6. Capture PHP log before/after markers as usual.

---

## 7. Deferred / not done

- FLAIROC install / SSH
- Final RC.3 commit
- `v1.0.0-rc.3` tag
- Public RC.3 package
- Shipments / tracking / Blocks

After QA.5 owner PASS → finalize and build RC.3.
