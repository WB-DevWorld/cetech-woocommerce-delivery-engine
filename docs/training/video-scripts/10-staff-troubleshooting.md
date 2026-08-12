# VIDEO TITLE: Staff troubleshooting

**Canonical file:** `10-staff-troubleshooting.webm` (optional `10-staff-troubleshooting.mp4`)  
**Audience:** Everyday staff  
**Learning objective:** Work through common delivery problems safely and know when to escalate — without SSH, SQL, PHP, Redis flush, Nginx, or Code Snippets.  
**Estimated duration:** 7–10 minutes  
**Capture method (fill after recording):** PLAYWRIGHT / HUMAN / MIXED — _pending_  
**Related written guide:** 07-TROUBLESHOOTING-FAQ.md  
**Practice exercise:** Pick one FAQ scenario and walk Preview → Delivery Settings → escalate rule.  
**QA fixtures:** products `#39705`, `#39717` / `#39718` / `#39719`; orders `#39721` / `#39724` (read-only preferred)

---

## Scene 1 — Needs configuration

**What is visible:** Preview or FAQ on screen.

**Narration:** If Preview says Needs configuration, open Delivery Settings for the same product or variation, complete missing values, prefer inheritance when the parent is correct, save, and Preview again.

**Action being performed:** Show Preview and Delivery Settings side path.

**Key teaching point:** Fix config, do not invent fees.

## Scene 2 — No delivery choice / wrong inheritance

**What is visible:** Product page or Preview.

**Narration:** If no delivery choice shows, confirm the correct product or variation, Preview Ready, and that offers are assigned. If the wrong inherited setting appears, change the level Preview says is Currently using.

**Action being performed:** Narrate checklist; optional live QA screens.

**Key teaching point:** Correct level, then Preview.

## Scene 3 — Charges and multi-product surprises

**What is visible:** Cart/checkout or order totals.

**Narration:** No delivery charge usually means missing selection, zone, or rate card — escalate to an administrator if the selection is present but the fee is missing or unexpectedly zero. Unexpected multi-product charges may mean products cannot travel together.

**Action being performed:** Point at Delivery charge area on a safe screen.

**Key teaching point:** Zero fee is a warning, not a shortcut.

## Scene 4 — Missing order Delivery information

**What is visible:** Order screen.

**Narration:** If Delivery information is missing on an order, confirm you are on a Delivery Engine order from the enabled period, then escalate. Do not edit historical delivery details to fix future settings.

**Action being performed:** Show where the panel should appear on a good QA order.

**Key teaching point:** History is immutable for normal staff.

## Scene 5 — When to stop

**What is visible:** End card listing escalate rules.

**Narration:** Stop and ask an administrator or technical support when Preview and Delivery Settings look complete but the shop still fails, or when you are asked to use SSH, SQL, PHP, Redis flush, Nginx, or Code Snippets. Those are not normal staff tools.

**Action being performed:** Hold on escalate message.

**Key teaching point:** Escalate technical work.


---

## Full transcript

[Scene 1] If Preview says Needs configuration, open Delivery Settings for the same product or variation, complete missing values, prefer inheritance when the parent is correct, save, and Preview again.

[Scene 2] If no delivery choice shows, confirm the correct product or variation, Preview Ready, and that offers are assigned. If the wrong inherited setting appears, change the level Preview says is Currently using.

[Scene 3] No delivery charge usually means missing selection, zone, or rate card — escalate to an administrator if the selection is present but the fee is missing or unexpectedly zero. Unexpected multi-product charges may mean products cannot travel together.

[Scene 4] If Delivery information is missing on an order, confirm you are on a Delivery Engine order from the enabled period, then escalate. Do not edit historical delivery details to fix future settings.

[Scene 5] Stop and ask an administrator or technical support when Preview and Delivery Settings look complete but the shop still fails, or when you are asked to use SSH, SQL, PHP, Redis flush, Nginx, or Code Snippets. Those are not normal staff tools.

---

## Chapter timestamps

Refine after final recording:

- 00:00 Title / start
- (planned) Scene 1: Needs configuration
- (planned) Scene 2: No delivery choice / wrong inheritance
- (planned) Scene 3: Charges and multi-product surprises
- (planned) Scene 4: Missing order Delivery information
- (planned) Scene 5: When to stop
- (planned total) 7–10 minutes

---

## Privacy notes for this video

- Use QA data only; blur customer PII on order screens.
- Never show passwords, cookies, nonces, payment details, or auth state.
- Prefer read-only walks; restore any temporary QA config changes.
