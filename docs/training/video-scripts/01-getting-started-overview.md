# VIDEO TITLE: Getting started overview

**Canonical file:** `01-getting-started-overview.webm` (optional `01-getting-started-overview.mp4`)  
**Audience:** New staff, trainers  
**Learning objective:** Find Delivery Engine in WordPress, know Delivery Settings as the everyday home, and name Default / Product / Variation, Preview, supporting config pages, and order Delivery information — without technical jargon.  
**Estimated duration:** 5–8 minutes  
**Capture method (fill after recording):** PLAYWRIGHT / HUMAN / MIXED — _pending_  
**Related written guide:** 00-START-HERE.md, 01-QUICK-START.md, 05-STAFF-TRAINING-MANUAL.md Module 1–2  
**Practice exercise:** Open Delivery Engine → Delivery Settings and Preview; do not edit Legacy Rules.  
**QA fixtures:** products `#39705`, `#39717` / `#39718` / `#39719`; orders `#39721` / `#39724` (read-only preferred)

---

## Scene 1 — Title / purpose

**What is visible:** WordPress admin desktop; Delivery Engine menu visible in the left sidebar.

**Narration:** Welcome to the CETECH Delivery Engine staff walkthrough for release candidate 1.0.0-rc.2. In this short overview you will learn where everyday delivery work happens, and what customers and staff each see.

**Action being performed:** Pause on wp-admin home or Delivery Engine dashboard for several seconds.

**Key teaching point:** This is operational training, not developer architecture.

## Scene 2 — Open Delivery Engine

**What is visible:** Left menu: Delivery Engine.

**Narration:** In WordPress admin, open Delivery Engine. This is the home for delivery configuration for your store.

**Action being performed:** Click Delivery Engine; show the dashboard briefly.

**Key teaching point:** Staff start from Delivery Engine, not random WooCommerce screens.

## Scene 3 — Delivery Settings is everyday

**What is visible:** Delivery Settings with tabs Default / Product-Specific / Variation-Specific.

**Narration:** Your everyday starting point is Delivery Settings. Most day-to-day work happens here — not in Legacy Delivery Rules.

**Action being performed:** Open Delivery Settings; hover or pause on the three tabs.

**Key teaching point:** Delivery Settings = normal current workflow.

## Scene 4 — Three levels

**What is visible:** Default Settings tab content.

**Narration:** Think of three layers. Default Settings apply store-wide. Product-Specific Settings can change one product. Variation-Specific Settings can change one variation. Prefer inheritance unless something truly needs to differ.

**Action being performed:** Click through Default, then Product, then Variation tabs without saving changes.

**Key teaching point:** Default → Product → Variation.

## Scene 5 — Preview

**What is visible:** Delivery Settings Preview page.

**Narration:** Delivery Settings Preview is read-only. It shows what will actually apply for a product or variation, including Ready or Needs configuration.

**Action being performed:** Open Preview; pause on Ready / Needs configuration language if visible.

**Key teaching point:** Always Preview after important changes.

## Scene 6 — Offers, zones, rate cards

**What is visible:** Delivery Offers, Destination Zones, and Rate Cards list screens.

**Narration:** At a high level: Delivery Offers are the choices customers see. Destination Zones describe where a choice applies. Rate Cards connect an offer and zone to a delivery charge. You configure products in Delivery Settings; these pages support that setup.

**Action being performed:** Open each page briefly, read-only.

**Key teaching point:** Supporting config pages work together with Delivery Settings.

## Scene 7 — Order Delivery information

**What is visible:** Existing QA order with Delivery information panel (blur personal fields).

**Narration:** After a customer pays, staff open the order and find Delivery information — fulfilment, delivery method, delivery option, estimated delivery, and charge. You do not need technical metadata for normal work.

**Action being performed:** Open QA order #39721 or #39724; focus Delivery information; redact PII.

**Key teaching point:** Orders keep what the customer paid for; do not rewrite history casually.

## Scene 8 — Close

**What is visible:** Back on Delivery Settings home.

**Narration:** Remember: Delivery Settings for everyday work, Preview to confirm, and Delivery information on orders after purchase. Next videos go deeper into each step.

**Action being performed:** Return to Delivery Settings; end card / pause.

**Key teaching point:** Path for new staff is clear and repeatable.


---

## Full transcript

[Scene 1] Welcome to the CETECH Delivery Engine staff walkthrough for release candidate 1.0.0-rc.2. In this short overview you will learn where everyday delivery work happens, and what customers and staff each see.

[Scene 2] In WordPress admin, open Delivery Engine. This is the home for delivery configuration for your store.

[Scene 3] Your everyday starting point is Delivery Settings. Most day-to-day work happens here — not in Legacy Delivery Rules.

[Scene 4] Think of three layers. Default Settings apply store-wide. Product-Specific Settings can change one product. Variation-Specific Settings can change one variation. Prefer inheritance unless something truly needs to differ.

[Scene 5] Delivery Settings Preview is read-only. It shows what will actually apply for a product or variation, including Ready or Needs configuration.

[Scene 6] At a high level: Delivery Offers are the choices customers see. Destination Zones describe where a choice applies. Rate Cards connect an offer and zone to a delivery charge. You configure products in Delivery Settings; these pages support that setup.

[Scene 7] After a customer pays, staff open the order and find Delivery information — fulfilment, delivery method, delivery option, estimated delivery, and charge. You do not need technical metadata for normal work.

[Scene 8] Remember: Delivery Settings for everyday work, Preview to confirm, and Delivery information on orders after purchase. Next videos go deeper into each step.

---

## Chapter timestamps

Refine after final recording:

- 00:00 Title / start
- (planned) Scene 1: Title / purpose
- (planned) Scene 2: Open Delivery Engine
- (planned) Scene 3: Delivery Settings is everyday
- (planned) Scene 4: Three levels
- (planned) Scene 5: Preview
- (planned) Scene 6: Offers, zones, rate cards
- (planned) Scene 7: Order Delivery information
- (planned) Scene 8: Close
- (planned total) 5–8 minutes

---

## Privacy notes for this video

- Use QA data only; blur customer PII on order screens.
- Never show passwords, cookies, nonces, payment details, or auth state.
- Prefer read-only walks; restore any temporary QA config changes.
