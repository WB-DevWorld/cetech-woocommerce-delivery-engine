# Stage 13E — RC.3 finalization

**Document status:** Final Stage 13 release record  
**Release version:** `1.0.0-rc.3`  
**Schema target:** `3` (unchanged; no version-bump migration)  
**Branch:** `feat/site-wide-delivery-defaults`  
**RC.2 tag:** `v1.0.0-rc.2` — **untouched**  
**RC.3 tag:** `v1.0.0-rc.3`  
**Date:** 2026-08-14  
**FLAIROC:** not modified in this stage (owner installs after reviewing this package)

---

## 1. Purpose

Finalize the approved Stage 13 / 13B / 13C / 13D / 13D-R1 working tree into the tagged release candidate:

`1.0.0-rc.3`

This replaces the last owner-accepted QA identity `1.0.0-rc.3-qa.5`. It is **not** a new feature stage and does **not** begin Stage 14, shipments, tracking, Blocks, carrier integrations, or bulk import.

---

## 2. Owner acceptance baseline

### QA.4 (full owner pass)

Confirmed on FLAIROC before QA.5:

- clean normal menu
- no Legacy Delivery Rules submenu
- no Technical Diagnostics normal submenu
- Overview upgrade-state wording correct
- no legacy counter/workflow
- real WordPress role controls
- Shop Manager capability enforcement
- Administrator full access
- Delivery Option / Area / Charge / Pickup Location edit PASS
- simple QA product READY; variable parent READY; Variation B READY
- product/variation View/Edit PASS
- Variation B inherits parent Delivery Option
- Preview variations A/B load; Preview/simple switching works
- International = Delivery only with Air/Sea only
- Local Delivery and Store Pickup excluded from International
- RateCard `effective_from` / `effective_to` warnings absent
- final Delivery Engine PHP log PASS

### QA.5 (Stage 13D-R1 access/recovery)

Installed version `1.0.0-rc.3-qa.5` owner physical pass:

| Check | Result |
|-------|--------|
| Administrator protected/full access | PASS |
| Administrator editable checkboxes removed | PASS |
| Subordinate role editable | PASS |
| Permission saves | PASS |
| Permission survives reload | PASS |
| Administrator unaffected | PASS |
| Recovery uses `manage_options` | PASS |
| Recovery independent of `view_delivery_diagnostics` | PASS |
| Final PHP log | PASS |

---

## 3. What RC.3 finalizes

| Area | Outcome |
|------|---------|
| Stage 13 | Site-wide fulfilment defaults + Global → Product → Variation inheritance |
| Stage 13B | WordPress-native admin UX, Overview, Setup Guide, progressive product panel |
| Stage 13C | Owner-review / wizard / International / Preview / readiness repairs |
| Stage 13D | Normal-menu simplification, legacy retirement from everyday UI, real role Access |
| Stage 13D-R1 | Administrator protected full access + independent `manage_options` recovery |
| Version identity | `1.0.0-rc.3` (plugin header, `CETECH_DE_VERSION`, readme Stable tag) |
| Schema | Target remains `3` |

---

## 4. Final normal Delivery Engine menu

- Overview
- Site-wide Defaults
- Delivery Options
- Delivery Areas
- Delivery Charges
- Pickup Locations
- Product Exceptions
- Needs Attention
- Settings

Setup Guide remains visible while incomplete and reopenable from Settings; it is not permanent normal-menu clutter after completion.

---

## 5. Administrator access / recovery

- Administrator is a protected full-access role (not an editable matrix row).
- Subordinate real WordPress roles remain configurable.
- Access saves must not revoke Administrator Delivery Engine capabilities.
- Boot capability self-heal restores missing Administrator DE caps without resetting subordinate choices.
- Independent Restore Administrator Access uses native `manage_options` + nonce.
- Recovery does **not** require `view_delivery_diagnostics`.

---

## 6. Legacy status

- No normal Legacy Delivery Rules menu.
- No new-install legacy educational workflow.
- Historical legacy storage may remain dormant for compatibility.
- RC.3 upgrade does **not** destructively delete historical legacy data.

Technical Diagnostics remains capability-gated / hidden. Logistics Profiles and Suppliers/Origins remain internal/contextual. Contextual Preview remains available.

---

## 7. Runtime non-regression (preserved; not rewritten)

- GLOBAL → PRODUCT → VARIATION field-level inheritance
- Stage 6 simple + variable runtime
- Stage 8 package/grouping, genuine WooCommerce shipping rates, checkout validation, protected order snapshot, HPOS
- Server authority; no silent delivery-option replacement
- Malformed/missing rate never becomes free; explicit configured numeric zero may mean free
- Genuine WooCommerce shipping (not fee-based fake shipping)
- Private supplier/origin/logistics details never exposed to shoppers
- Historical order snapshots immutable
- International = Delivery only with Air and/or Sea
- In Warehouse = local Delivery only; In Store = Delivery and/or Store Pickup
- WoodMart remains optional compatibility only

---

## 8. Deliberately deferred

- Stage 14 / shipment records / tracking / customer timeline
- WooCommerce Blocks checkout architecture
- Carrier API integrations / live quotes
- Bulk import tooling
- Any new feature work beyond the finalized Stage 13 product

---

## 9. FLAIROC status for this stage

**NOT MODIFIED.**

Final FLAIROC RC.3 runtime verification happens **after** the owner installs this package via clean folder replace and runs one focused smoke (admin health, simple + variable product, delivery selection, cart persistence, checkout validation, genuine shipping amount, one order snapshot, final PHP log).

Do **not** claim FLAIROC final RC.3 runtime verification in this document.

---

## 10. Package identity

| Item | Value |
|------|-------|
| ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.3.zip` |
| Root folder | `cetech-woocommerce-delivery-engine/` |
| Schema target | `3` |
| Tag | `v1.0.0-rc.3` |

Exact commit SHA, ZIP byte size, and SHA-256 are recorded in the Stage 13E finalization report produced with the build.

---

## 11. Next step

Owner installs `1.0.0-rc.3` on FLAIROC via clean folder replace, runs the focused final runtime smoke listed above, then **STOP**.

Do not start Stage 14 or any new feature.
