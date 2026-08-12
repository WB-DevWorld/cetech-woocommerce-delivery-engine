# VIDEO TITLE: Variation inheritance and overrides

**Canonical file:** `05-variation-inheritance-overrides.webm` (optional `05-variation-inheritance-overrides.mp4`)  
**Audience:** Staff and trainers  
**Learning objective:** Explain Default → Product → Variation and the modes Use inherited setting, Set a different value here, and Turn off, with real UI examples including offer collections where applicable.  
**Estimated duration:** 6–9 minutes  
**Capture method (fill after recording):** PLAYWRIGHT / HUMAN / MIXED — _pending_  
**Related written guide:** 01-QUICK-START.md inheritance table, 05 Module 3  
**Practice exercise:** On #39705 and #39718, identify each mode label without saving unwanted changes.  
**QA fixtures:** products `#39705`, `#39717` / `#39718` / `#39719`; orders `#39721` / `#39724` (read-only preferred)

---

## Scene 1 — The chain

**What is visible:** Diagram-like walk: Default tab → Product → Variation.

**Narration:** Inheritance always runs Default, then Product, then Variation. The most specific intentional value wins.

**Action being performed:** Click through the three tabs in order.

**Key teaching point:** Variation wins when it sets a value.

## Scene 2 — Use inherited setting

**What is visible:** Mode control set to Use inherited setting.

**Narration:** Use inherited setting means keep the value from the level above. This is the safest everyday choice.

**Action being performed:** Highlight the label on a QA field.

**Key teaching point:** Inherit by default.

## Scene 3 — Set a different value here

**What is visible:** Mode Set a different value here with an editor.

**Narration:** Set a different value here replaces the inherited scalar for this product or variation only.

**Action being performed:** Show the mode without leaving a bad permanent override.

**Key teaching point:** Override is explicit.

## Scene 4 — Turn off

**What is visible:** Turn off mode where supported.

**Narration:** Turn off means intentionally none for this item where the field supports it. It is not the same as forgetting to configure.

**Action being performed:** Show Turn off if present; explain verbally if not on screen.

**Key teaching point:** Turn off is intentional absence.

## Scene 5 — Collections

**What is visible:** Delivery offers collection controls (add / remove / replace) if present.

**Narration:** For delivery offers, you may add, remove, or replace inherited options depending on the controls your screen shows. Empty lists are not always inherit — read the labels carefully.

**Action being performed:** Hover collection controls on QA product.

**Key teaching point:** Collections have explicit semantics — do not guess.


---

## Full transcript

[Scene 1] Inheritance always runs Default, then Product, then Variation. The most specific intentional value wins.

[Scene 2] Use inherited setting means keep the value from the level above. This is the safest everyday choice.

[Scene 3] Set a different value here replaces the inherited scalar for this product or variation only.

[Scene 4] Turn off means intentionally none for this item where the field supports it. It is not the same as forgetting to configure.

[Scene 5] For delivery offers, you may add, remove, or replace inherited options depending on the controls your screen shows. Empty lists are not always inherit — read the labels carefully.

---

## Chapter timestamps

Refine after final recording:

- 00:00 Title / start
- (planned) Scene 1: The chain
- (planned) Scene 2: Use inherited setting
- (planned) Scene 3: Set a different value here
- (planned) Scene 4: Turn off
- (planned) Scene 5: Collections
- (planned total) 6–9 minutes

---

## Privacy notes for this video

- Use QA data only; blur customer PII on order screens.
- Never show passwords, cookies, nonces, payment details, or auth state.
- Prefer read-only walks; restore any temporary QA config changes.
