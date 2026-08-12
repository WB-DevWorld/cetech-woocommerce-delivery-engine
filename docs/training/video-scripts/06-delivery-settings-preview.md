# VIDEO TITLE: Delivery Settings Preview

**Canonical file:** `06-delivery-settings-preview.webm` (optional `06-delivery-settings-preview.mp4`)  
**Audience:** Everyday staff  
**Learning objective:** Use Preview to read Ready, Needs configuration, and Currently using — and know what to do when configuration is incomplete without breaking live production settings for the camera.  
**Estimated duration:** 4–6 minutes  
**Capture method (fill after recording):** PLAYWRIGHT / HUMAN / MIXED — _pending_  
**Related written guide:** 04-VISUAL-WALKTHROUGH.md Preview section, 07-TROUBLESHOOTING-FAQ.md  
**Practice exercise:** Preview QA #39705 and one variation; write down Currently using.  
**QA fixtures:** products `#39705`, `#39717` / `#39718` / `#39719`; orders `#39721` / `#39724` (read-only preferred)

---

## Scene 1 — Open Preview

**What is visible:** Delivery Settings Preview.

**Narration:** Preview is read-only. Use it after changes, and whenever a customer or colleague reports a delivery problem.

**Action being performed:** Open Preview.

**Key teaching point:** Preview before escalating.

## Scene 2 — Ready

**What is visible:** Ready status on a complete QA example.

**Narration:** Ready means required delivery values are present for this view. Staff can expect the customer journey to have a fair chance of showing options.

**Action being performed:** Show Ready on QA fixture.

**Key teaching point:** Ready is the goal state.

## Scene 3 — Needs configuration

**What is visible:** Needs configuration language if a safe QA example exists; otherwise explain with documentation wording on screen.

**Narration:** Needs configuration means something required is missing or incomplete. Do not invent free shipping. Fix Default or the correct product or variation layer, then Preview again. Do not break live production configuration just to film this state.

**Action being performed:** Use existing QA-safe incomplete example or on-screen FAQ text — never sabotage production.

**Key teaching point:** Incomplete ≠ free.

## Scene 4 — Currently using

**What is visible:** Currently using Default / Product / Variation.

**Narration:** Currently using tells you which level is supplying the effective values. If inheritance looks wrong, fix the level Preview points to.

**Action being performed:** Highlight Currently using.

**Key teaching point:** Fix the level that is actually in force.


---

## Full transcript

[Scene 1] Preview is read-only. Use it after changes, and whenever a customer or colleague reports a delivery problem.

[Scene 2] Ready means required delivery values are present for this view. Staff can expect the customer journey to have a fair chance of showing options.

[Scene 3] Needs configuration means something required is missing or incomplete. Do not invent free shipping. Fix Default or the correct product or variation layer, then Preview again. Do not break live production configuration just to film this state.

[Scene 4] Currently using tells you which level is supplying the effective values. If inheritance looks wrong, fix the level Preview points to.

---

## Chapter timestamps

Refine after final recording:

- 00:00 Title / start
- (planned) Scene 1: Open Preview
- (planned) Scene 2: Ready
- (planned) Scene 3: Needs configuration
- (planned) Scene 4: Currently using
- (planned total) 4–6 minutes

---

## Privacy notes for this video

- Use QA data only; blur customer PII on order screens.
- Never show passwords, cookies, nonces, payment details, or auth state.
- Prefer read-only walks; restore any temporary QA config changes.
