# VIDEO TITLE: Configure a variable product

**Canonical file:** `04-configure-variable-product.webm` (optional `04-configure-variable-product.mp4`)  
**Audience:** Everyday staff  
**Learning objective:** Configure variable parent #39717 and variations #39718 / #39719, verify Preview, and confirm the storefront selector after a variation is chosen.  
**Estimated duration:** 7–10 minutes  
**Capture method (fill after recording):** PLAYWRIGHT / HUMAN / MIXED — _pending_  
**Related written guide:** 03-USE-CASE-PLAYBOOK.md variable cases, 05 Module 5  
**Practice exercise:** Open parent #39717 and variations #39718/#39719 read-only; Preview each; open storefront and select a variation.  
**QA fixtures:** products `#39705`, `#39717` / `#39718` / `#39719`; orders `#39721` / `#39724` (read-only preferred)

---

## Scene 1 — Parent settings

**What is visible:** Product-Specific Settings for #39717.

**Narration:** Variable products have a parent and variations. Start with parent product 39717. Parent settings apply when a variation inherits.

**Action being performed:** Open product settings for #39717.

**Key teaching point:** Parent first, then variations.

## Scene 2 — Variation A inherit

**What is visible:** Variation-Specific Settings for #39718.

**Narration:** Open Variation A, number 39718. When fields say Use inherited setting, this variation follows the parent — or Default through the parent.

**Action being performed:** Open #39718; highlight inherited fields.

**Key teaching point:** Inherited variation follows the chain.

## Scene 3 — Variation B override path

**What is visible:** Variation-Specific Settings for #39719.

**Narration:** Variation B, number 39719, is useful to demonstrate an override. Only set a different value when this variation must differ.

**Action being performed:** Open #39719; show override vs inherit modes without breaking live QA unless restoring.

**Key teaching point:** Override only the variation that needs it.

## Scene 4 — Save and Preview

**What is visible:** Preview for parent and a variation.

**Narration:** Save authorised changes, then Preview for the exact variation ID you care about. Confirm Currently using and Ready.

**Action being performed:** Open Preview; select variation IDs carefully.

**Key teaching point:** Wrong ID = wrong Preview.

## Scene 5 — Storefront selector

**What is visible:** Variable product page; variation chosen; Delivery options.

**Narration:** On the storefront, the customer must select a variation. Delivery options then refresh for that variation. Do not complete payment just for this video.

**Action being performed:** Select a variation; show Delivery options; stop before payment.

**Key teaching point:** Variation selection drives delivery choices.


---

## Full transcript

[Scene 1] Variable products have a parent and variations. Start with parent product 39717. Parent settings apply when a variation inherits.

[Scene 2] Open Variation A, number 39718. When fields say Use inherited setting, this variation follows the parent — or Default through the parent.

[Scene 3] Variation B, number 39719, is useful to demonstrate an override. Only set a different value when this variation must differ.

[Scene 4] Save authorised changes, then Preview for the exact variation ID you care about. Confirm Currently using and Ready.

[Scene 5] On the storefront, the customer must select a variation. Delivery options then refresh for that variation. Do not complete payment just for this video.

---

## Chapter timestamps

Refine after final recording:

- 00:00 Title / start
- (planned) Scene 1: Parent settings
- (planned) Scene 2: Variation A inherit
- (planned) Scene 3: Variation B override path
- (planned) Scene 4: Save and Preview
- (planned) Scene 5: Storefront selector
- (planned total) 7–10 minutes

---

## Privacy notes for this video

- Use QA data only; blur customer PII on order screens.
- Never show passwords, cookies, nonces, payment details, or auth state.
- Prefer read-only walks; restore any temporary QA config changes.
