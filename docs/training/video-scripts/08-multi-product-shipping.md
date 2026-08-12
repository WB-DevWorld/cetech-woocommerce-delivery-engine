# VIDEO TITLE: Multi-product shipping

**Canonical file:** `08-multi-product-shipping.webm` (optional `08-multi-product-shipping.mp4`)  
**Audience:** Staff handling multi-line carts  
**Learning objective:** Show two compatible QA products sharing one delivery charge (expected 25.00 fixed-per-shipment in the verified QA story) and explain that incompatible fulfilment paths are separated.  
**Estimated duration:** 5–8 minutes  
**Capture method (fill after recording):** PLAYWRIGHT / HUMAN / MIXED — _pending_  
**Related written guide:** 05 Module on multi-product / Stage 8 notes in FAQ, order #39724  
**Practice exercise:** Build a cart with two compatible QA products; confirm one shared delivery charge; do not place an order.  
**QA fixtures:** products `#39705`, `#39717` / `#39718` / `#39719`; orders `#39721` / `#39724` (read-only preferred)

---

## Scene 1 — Two compatible products

**What is visible:** Cart with two QA lines that can travel together.

**Narration:** When two products can travel together, they can share one delivery charge. We use safe QA products only.

**Action being performed:** Add two compatible QA products with delivery selected.

**Key teaching point:** Compatible products can group.

## Scene 2 — One shared charge

**What is visible:** Cart or checkout showing a single 25.00 delivery charge when that is the QA expectation.

**Narration:** These products can travel together, so they share one delivery charge. In the verified QA story that shared charge is twenty-five point zero zero.

**Action being performed:** Highlight the single Delivery charge.

**Key teaching point:** Shared shipment → one charge in this QA case.

## Scene 3 — Incompatible paths

**What is visible:** Optional second illustration or verbal explanation if a safe incompatible pair is not on screen.

**Narration:** If fulfilment paths are incompatible, the store separates them. Customers may see more than one delivery charge. That is expected when items cannot travel together.

**Action being performed:** Explain clearly; do not create unnecessary orders.

**Key teaching point:** Separation protects correct charging.


---

## Full transcript

[Scene 1] When two products can travel together, they can share one delivery charge. We use safe QA products only.

[Scene 2] These products can travel together, so they share one delivery charge. In the verified QA story that shared charge is twenty-five point zero zero.

[Scene 3] If fulfilment paths are incompatible, the store separates them. Customers may see more than one delivery charge. That is expected when items cannot travel together.

---

## Chapter timestamps

Refine after final recording:

- 00:00 Title / start
- (planned) Scene 1: Two compatible products
- (planned) Scene 2: One shared charge
- (planned) Scene 3: Incompatible paths
- (planned total) 5–8 minutes

---

## Privacy notes for this video

- Use QA data only; blur customer PII on order screens.
- Never show passwords, cookies, nonces, payment details, or auth state.
- Prefer read-only walks; restore any temporary QA config changes.
