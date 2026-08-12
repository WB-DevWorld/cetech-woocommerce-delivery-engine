# VIDEO TITLE: Complete staff walkthrough

**Canonical file:** `12-complete-staff-walkthrough.webm` (optional `12-complete-staff-walkthrough.mp4`)  
**Audience:** New staff onboarding  
**Learning objective:** Watch one end-to-end onboarding path from overview through customer journey, orders, troubleshooting boundaries, and Legacy Rules — suitable to watch beginning to end.  
**Estimated duration:** 15–25 minutes  
**Capture method (fill after recording):** PLAYWRIGHT / HUMAN / MIXED — _pending_  
**Related written guide:** 05-STAFF-TRAINING-MANUAL.md (all modules), 10-VIDEO-TRAINING-LIBRARY.md  
**Practice exercise:** After watching, complete Module 1 practical test and one simple-product Preview on #39705.  
**QA fixtures:** products `#39705`, `#39717` / `#39718` / `#39719`; orders `#39721` / `#39724` (read-only preferred)

---

## Scene 1 — Overview

**What is visible:** Delivery Engine dashboard.

**Narration:** This complete walkthrough is your full onboarding video for the CETECH Delivery Engine on RC.2. We will move slowly from admin configuration to the customer journey and back to the order.

**Action being performed:** Title pause on dashboard.

**Key teaching point:** Full path in one sitting.

## Scene 2 — Delivery Settings and Defaults

**What is visible:** Delivery Settings Default tab.

**Narration:** Open Delivery Settings. Defaults are the store-wide baseline. Prefer strong defaults over dozens of one-off product edits.

**Action being performed:** Open Default Settings; pause on key fields.

**Key teaching point:** Defaults first.

## Scene 3 — Simple product

**What is visible:** Product settings #39705.

**Narration:** On simple QA product 39705, prefer Use inherited setting. Override only when needed. Save authorised changes.

**Action being performed:** Show #39705 product settings.

**Key teaching point:** Simple product path.

## Scene 4 — Variable product and override

**What is visible:** #39717 parent; #39718 inherit; #39719 override discussion.

**Narration:** For variable products, configure the parent, then each variation. Variation 39718 can demonstrate inheritance; 39719 can demonstrate an intentional override.

**Action being performed:** Open parent and both variations briefly.

**Key teaching point:** Parent then variation.

## Scene 5 — Preview

**What is visible:** Preview Ready / Currently using.

**Narration:** Always Preview. Confirm Ready and Currently using before you trust the shop page.

**Action being performed:** Open Preview.

**Key teaching point:** Preview is mandatory habit.

## Scene 6 — Customer selection, cart, checkout

**What is visible:** Product → cart → checkout Delivery charge.

**Narration:** Customer selects delivery on the product, cart keeps it, checkout shows the Delivery charge. We stop before payment.

**Action being performed:** Walk storefront path without paying.

**Key teaching point:** No payment required for training video.

## Scene 7 — Multi-product delivery

**What is visible:** Multi-line cart with shared charge when compatible.

**Narration:** Compatible products can share one delivery charge. Incompatible fulfilment paths are separated. Explain simply to customers when more than one charge appears.

**Action being performed:** Show multi-product cart if safe.

**Key teaching point:** Grouping vs separation.

## Scene 8 — Order Delivery information

**What is visible:** QA order #39721 or #39724.

**Narration:** On the order, read Delivery information using operational labels. Do not rewrite historical delivery details to fix future settings.

**Action being performed:** Open QA order; blur PII.

**Key teaching point:** Immutable history.

## Scene 9 — Troubleshooting and Legacy

**What is visible:** FAQ headlines; Legacy Rules warning.

**Narration:** For problems, use Preview and Delivery Settings first. Escalate technical work. Legacy Delivery Rules are not everyday. Never change Settings switches, suppliers and origins, or Legacy Rules without approval.

**Action being performed:** Show escalate boundaries; open Legacy briefly; return to Delivery Settings.

**Key teaching point:** Boundaries protect the store.

## Scene 10 — Close

**What is visible:** Delivery Settings home.

**Narration:** You now have the full staff path. Practise on QA products, watch the shorter topic videos as needed, and ask a trainer to sign off your practical tests.

**Action being performed:** End on Delivery Settings.

**Key teaching point:** Read + watch + practise.


---

## Full transcript

[Scene 1] This complete walkthrough is your full onboarding video for the CETECH Delivery Engine on RC.2. We will move slowly from admin configuration to the customer journey and back to the order.

[Scene 2] Open Delivery Settings. Defaults are the store-wide baseline. Prefer strong defaults over dozens of one-off product edits.

[Scene 3] On simple QA product 39705, prefer Use inherited setting. Override only when needed. Save authorised changes.

[Scene 4] For variable products, configure the parent, then each variation. Variation 39718 can demonstrate inheritance; 39719 can demonstrate an intentional override.

[Scene 5] Always Preview. Confirm Ready and Currently using before you trust the shop page.

[Scene 6] Customer selects delivery on the product, cart keeps it, checkout shows the Delivery charge. We stop before payment.

[Scene 7] Compatible products can share one delivery charge. Incompatible fulfilment paths are separated. Explain simply to customers when more than one charge appears.

[Scene 8] On the order, read Delivery information using operational labels. Do not rewrite historical delivery details to fix future settings.

[Scene 9] For problems, use Preview and Delivery Settings first. Escalate technical work. Legacy Delivery Rules are not everyday. Never change Settings switches, suppliers and origins, or Legacy Rules without approval.

[Scene 10] You now have the full staff path. Practise on QA products, watch the shorter topic videos as needed, and ask a trainer to sign off your practical tests.

---

## Chapter timestamps

Refine after final recording:

- 00:00 Title / start
- (planned) Scene 1: Overview
- (planned) Scene 2: Delivery Settings and Defaults
- (planned) Scene 3: Simple product
- (planned) Scene 4: Variable product and override
- (planned) Scene 5: Preview
- (planned) Scene 6: Customer selection, cart, checkout
- (planned) Scene 7: Multi-product delivery
- (planned) Scene 8: Order Delivery information
- (planned) Scene 9: Troubleshooting and Legacy
- (planned) Scene 10: Close
- (planned total) 15–25 minutes

---

## Privacy notes for this video

- Use QA data only; blur customer PII on order screens.
- Never show passwords, cookies, nonces, payment details, or auth state.
- Prefer read-only walks; restore any temporary QA config changes.
