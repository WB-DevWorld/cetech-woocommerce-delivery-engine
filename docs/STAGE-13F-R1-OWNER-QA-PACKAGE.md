# Stage 13F-R1 — Owner QA package (`1.0.0-rc.4-qa.1`)

**Document status:** Packaging record for owner FLAIROC physical retest  
**QA version:** `1.0.0-rc.4-qa.1`  
**Schema target:** `3` (unchanged; no migration)  
**Branch:** `feat/site-wide-delivery-defaults`  
**HEAD:** `f91da5dad677410ca05fbcb86ef8fd83596b3871`  
**Working tree:** **dirty** — Stage 13F presentation polish + version identity `1.0.0-rc.4-qa.1` (packaged with `-AllowDirty`)  
**RC.3 tag:** `v1.0.0-rc.3` → `f91da5dad677410ca05fbcb86ef8fd83596b3871` — **untouched**  
**Date:** 2026-08-14  
**FLAIROC:** **NOT MODIFIED**  
**RC.4 commit/tag:** **none**

---

## 1. Verdict

**READY FOR OWNER PHYSICAL FLAIROC RETEST of QA.1 (Stage 13F customer UX).**

This is an **owner-review QA package only**. It is **not** final RC.4.

Built with:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.4-qa.1 -AllowDirty -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-rc.4-qa.1.zip
```

| Artifact | Path |
|----------|------|
| Dist ZIP | `dist/cetech-woocommerce-delivery-engine-1.0.0-rc.4-qa.1.zip` |
| Desktop ZIP | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-rc.4-qa.1.zip` |
| Bytes | `860387` |
| SHA-256 | `636f58790e1eed825b8e7200dc8ccc8bad7dd5f7f9df10a1057c86190c835241` |

ZIP root folder: `cetech-woocommerce-delivery-engine/`

---

## 2. What this QA package contains

Stage 13F customer-facing presentation polish on top of tagged RC.3:

- Compact product selector hierarchy (public label + Estimated delivery)
- Public description omitted from compact product selector
- Compact thank-you / My Account / customer email Delivery details
- No fulfilment / method / duplicate charge / Shipping summary on customer surfaces
- WooCommerce shipping rate label prefers selected public Delivery Option label
- Pickup extras only when present in public payload

No inheritance, Stage 6/8 architecture, rate-calculation, snapshot, Access, or schema changes.

---

## 3. Pre-package gates

| Gate | Result |
|------|--------|
| `composer validate --no-check-publish` | PASS |
| PHP lint (identity + Stage 13F files) | PASS |
| Full PHPUnit | **322** tests / **1697** assertions / PASS |
| `npm run test:js` | **11** tests / PASS |

Playwright: not claimed (no supported root setup for this package gate).

---

## 4. Extracted-package verification

| # | Check | Result |
|---|--------|--------|
| 1 | One plugin root `cetech-woocommerce-delivery-engine/` | PASS |
| 2 | Version identity `1.0.0-rc.4-qa.1` | PASS |
| 3 | `SchemaVersion::TARGET` = `3` | PASS |
| 4 | `vendor/autoload.php` works | PASS |
| 5 | Production package autoload verifier | PASS |
| 6 | Linux filename/class casing | PASS |
| 7 | Packaged PHP lint | PASS (0 fails) |
| 8 | No PHPUnit in production vendor | PASS |
| 9 | Stage 13F public presentation code present | PASS |
| 10 | Stage 13F customer-facing CSS present | PASS |
| 11 | Product selector hierarchy present | PASS |
| 12 | Public Delivery Option shipping-rate label behavior | PASS |
| 13 | Stage 6 variable selector assets present | PASS |
| 14 | Stage 8 shipping/grouping/snapshot present | PASS |
| 15 | AdministratorAccessRecovery + Stage 13D access protections | PASS |
| 16 | No Legacy normal menu restoration | PASS (Legacy remains retired from everyday submenu; string may appear only in retirement/filter lists) |
| 17 | No Code Snippets dependency | PASS |
| 18 | No helper/disposable plugin | PASS |
| 19 | Excludes tests / node_modules / Playwright / .git / .env / review harness / training | PASS |

---

## 5. Short owner physical retest checklist

Install by **clean folder replace** on FLAIROC. Create **at most one** new QA order unless a failure requires retest.

### A. Delivery Charge value

Open **Delivery Engine → Delivery Charges**. Confirm the active charge for **FLAIROC QA Standard Delivery**. Record whether it is **25.00** or **250.00**. Do not silently change it during install. If the intended QA rate is 25.00 and live shows 250.00, correct configuration manually before the transactional smoke.

### B. Product page

Expect:

```text
FLAIROC QA Standard Delivery
Estimated delivery: 3–6 business days
```

Must **not** show QA-only Stage 0B description, Fulfilment: In Warehouse, logistics/supplier/origin/priority/rate-card IDs.

### C. Cart / checkout

- Selection persists
- Checkout validation passes
- Genuine WooCommerce shipping
- Amount equals currently configured Delivery Charge
- Shipping line uses public option label, e.g. `$25.00 via FLAIROC QA Standard Delivery` (or `$250.00 via …` if configured 250)

### D. Thank-you / customer order view

```text
Delivery details
Delivery option: FLAIROC QA Standard Delivery
Estimated delivery: 3–6 business days
```

Must **not** show Fulfilment / Delivery method / duplicate Delivery charge / Shipping summary / technical fields. WooCommerce’s normal Shipping totals row may remain.

### E. Email

If customer delivery email summary is enabled: same compact contract; no private fields; no duplicate shipping price.

### F. PHP log

Final Delivery Engine / fatal log check must **PASS**.

---

## 6. Release hygiene

- **FLAIROC NOT MODIFIED**
- **NO RC.4 COMMIT**
- **NO RC.4 TAG**
- **`v1.0.0-rc.3` untouched**
- Schema remains **3** / no migration

---

## STOP
