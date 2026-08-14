# Stage 13C-R3 — Owner live QA.2 follow-up repair

**Document status:** Local implementation record. Owner live retest is the remaining gate.  
**Plugin version:** unchanged working tree (`1.0.0-rc.3-qa.2` baseline; public RC.3 is **not** tagged)  
**Schema target:** `3` (unchanged)  
**Branch:** `feat/site-wide-delivery-defaults` (uncommitted)  
**RC.2 tag:** `v1.0.0-rc.2` — **untouched**  
**Date:** 2026-08-14  
**FLAIROC:** not modified  
**Package / commit:** none

---

## 1. Verdict

**Pending owner live retest on physical FLAIROC.**

Local Stage 13C-R3 repairs from the `1.0.0-rc.3-qa.2` owner review are implemented. PHPUnit and JS tests are green. Do **not** package. Do **not** commit or tag RC.3 until the owner retests the live WordPress admin.

This record does not declare the live findings closed.

---

## 2. What this repair addressed

| # | Finding | Local repair |
|---|---------|--------------|
| 1 | Wizard Step 3 Save & Continue appeared stuck with no Delivery Option selected | Nested create forms no longer close the save form. All fulfilment substeps require ≥1 Delivery Option. Adjacent WP-native validation notice; values preserved; progression only on success. |
| 2 | International staff UX said “Delivery method: Delivery”; empty Air/Sea path was dead | Customer fulfilment / International shipping options wording. Guided empty state + in-context Air/Sea create with forced compatible route. |
| 3 | Preview Variation dropdown empty for variable products | Initial catalog load improved; AJAX `cetech_de_preview_variations` refreshes choices on product change; stale selection cleared; nonce + capability intact. |
| 4 | Variable parent showed usable Local Delivery in Customize but “No usable delivery option” in Exceptions / Needs Attention | Stage 6 profile-slice ↔ Stage 13 root bridge in resolver; assessor no longer mislabels missing root slice; catalog ready-if-any operational slice; private/technical unresolved fields no longer false-fail Delivery Option readiness. |

---

## 3. Root causes (local)

1. **Wizard progression:** Inline create panels used nested `<form>` tags inside the Step 3 save form. Browsers closed the outer form early, so **Save & Continue** often submitted nothing. Empty Delivery Option selection was also allowed for non-International profiles.
2. **International wording:** Site-wide Defaults reused “Delivery method: Delivery” for Air/Sea profiles.
3. **Preview variations:** Changing Product only toggled row visibility; variations were not loaded dynamically. Catalog variation discovery was thin.
4. **Variable-parent readiness:** Stage 6/6B stored parent business config on `in_warehouse` while Stage 13 Preview/Exceptions targeted `''`. Assessor fail-fast + missing-slice mislabel produced “No usable delivery option” even when an active Local Delivery option existed on the profile slice. Unresolved private fields could also poison overall readiness after offers were fixed.

---

## 4. What did not change

- Plugin version string (still `1.0.0-rc.3-qa.2` working tree).
- Schema target `3`.
- Domain model: International → Delivery only → Air and/or Sea.
- EffectiveConfigurationResolver inheritance semantics (bridge only for sole profile item scope).
- Cart / checkout / shipping / order snapshot runtime.
- FLAIROC.
- RC.2 tag `v1.0.0-rc.2`.
- No ZIP package. No RC.3 commit or tag.
- QA.2 regression protections intentionally preserved (prior-install classification, Step 1→2 routing, capability self-heal, money precision, ETA code suppression, private-field grouping, etc.).

---

## 5. Tests (local)

| Check | Result |
|-------|--------|
| `composer validate --no-check-publish` | Pass |
| PHP lint on changed PHP | Pass |
| PHPUnit | **277 tests, 1361 assertions, OK** |
| `npm run test:js` | **10 tests passed** (includes new Preview variation JS test) |
| Focused repair tests | `tests/Unit/Configuration/Stage13CR3RepairTest.php` |

Review fixture **screenshots were not regenerated** in this pass (owner chose docs/report only). HTML harness may still be stale relative to wizard copy until the next capture.

---

## 6. Staging / owner checks required

1. Setup Guide Step 3 In Warehouse with no option selected → validation notice; does not silently stay without feedback; does not mark complete.
2. With a Local Delivery option selected → continues to In Store (not Preview).
3. International substep: wording + guided empty state; create Air/Sea option in context; local-only create path not offered.
4. Delivery Settings Preview: variable product loads Variations A/B; product change refreshes; simple hides Variation.
5. Variable QA parent: Product Exceptions / Needs Attention / Preview Delivery Option field Ready when Customize shows the active Local Delivery option.

---

## 7. Companion docs

- `docs/STAGE-13C-R1-OWNER-REVIEW-REPAIR.md`
- `docs/STAGE-13C-R2-OWNER-RETEST-PACKAGE.md`
- `docs/STAGE-13B-WORDPRESS-NATIVE-UX.md`
- `docs/AI-HANDOFF.md`
