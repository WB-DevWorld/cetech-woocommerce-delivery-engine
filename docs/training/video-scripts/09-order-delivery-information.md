# VIDEO TITLE: Order Delivery information

**Canonical file:** `09-order-delivery-information.webm` (optional `09-order-delivery-information.mp4`)  
**Audience:** Everyday staff  
**Learning objective:** Read Delivery information on existing QA orders #39721 or #39724 and know which fields matter for normal work.  
**Estimated duration:** 4–6 minutes  
**Capture method (fill after recording):** PLAYWRIGHT / HUMAN / MIXED — _pending_  
**Related written guide:** 04 order section, 05 Module 10  
**Practice exercise:** Open #39721 or #39724 read-only; list Fulfilment, Delivery method, Delivery option, Estimated delivery, charge, status.  
**QA fixtures:** products `#39705`, `#39717` / `#39718` / `#39719`; orders `#39721` / `#39724` (read-only preferred)

---

## Scene 1 — Open existing QA order

**What is visible:** WooCommerce order edit for #39721 or #39724; personal fields blurred.

**Narration:** Use an existing QA order. Do not create a new paid order only for filming. Open Delivery information on the order screen.

**Action being performed:** Open order; redact PII; focus Delivery information.

**Key teaching point:** Read-only QA orders preferred.

## Scene 2 — Operational fields

**What is visible:** Product, Fulfilment, Delivery method, Delivery option, Estimated delivery, Delivery charge, Status.

**Narration:** Normal staff need Product, Fulfilment, Delivery method, Delivery option, Estimated delivery, Delivery charge, and Status. These describe what the customer bought and how delivery was recorded.

**Action being performed:** Pause on each field.

**Key teaching point:** Operational fields only.

## Scene 3 — No technical overload

**What is visible:** Order screen without emphasising technical meta.

**Narration:** Normal staff do not need technical metadata. If you only see confusing technical details, stop and ask an administrator or technical support.

**Action being performed:** Avoid expanding advanced diagnostics.

**Key teaching point:** Escalate technical detail — do not guess.


---

## Full transcript

[Scene 1] Use an existing QA order. Do not create a new paid order only for filming. Open Delivery information on the order screen.

[Scene 2] Normal staff need Product, Fulfilment, Delivery method, Delivery option, Estimated delivery, Delivery charge, and Status. These describe what the customer bought and how delivery was recorded.

[Scene 3] Normal staff do not need technical metadata. If you only see confusing technical details, stop and ask an administrator or technical support.

---

## Chapter timestamps

Refine after final recording:

- 00:00 Title / start
- (planned) Scene 1: Open existing QA order
- (planned) Scene 2: Operational fields
- (planned) Scene 3: No technical overload
- (planned total) 4–6 minutes

---

## Privacy notes for this video

- Use QA data only; blur customer PII on order screens.
- Never show passwords, cookies, nonces, payment details, or auth state.
- Prefer read-only walks; restore any temporary QA config changes.
