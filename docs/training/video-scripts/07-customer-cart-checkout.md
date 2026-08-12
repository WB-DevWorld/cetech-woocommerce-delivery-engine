# VIDEO TITLE: Customer product, cart, and checkout

**Canonical file:** `07-customer-cart-checkout.webm` (optional `07-customer-cart-checkout.mp4`)  
**Audience:** Staff who support the customer journey  
**Learning objective:** Walk product → delivery selection → cart → reload → checkout Delivery charge using RC.2 labels, without placing a payment.  
**Estimated duration:** 6–9 minutes  
**Capture method (fill after recording):** PLAYWRIGHT / HUMAN / MIXED — _pending_  
**Related written guide:** 05 Modules 8–9, 04 storefront sections  
**Practice exercise:** On QA #39705: select a delivery option, open cart, refresh, open checkout, confirm Delivery charge line; do not pay.  
**QA fixtures:** products `#39705`, `#39717` / `#39718` / `#39719`; orders `#39721` / `#39724` (read-only preferred)

---

## Scene 1 — Product page

**What is visible:** QA simple product with Delivery options.

**Narration:** The customer starts on the product page. They choose a delivery option before adding to cart when the store requires it.

**Action being performed:** Open #39705 storefront; show Delivery options.

**Key teaching point:** Selection happens on the product.

## Scene 2 — RC.2 labels

**What is visible:** Fulfilment / Delivery method / Delivery option / Estimated delivery where shown.

**Narration:** Release candidate labels use Fulfilment, Delivery method, Delivery option, and Estimated delivery. Teach these words — not internal field names.

**Action being performed:** Pause on each visible label.

**Key teaching point:** Operational language only.

## Scene 3 — Cart and reload

**What is visible:** Cart with retained delivery choice.

**Narration:** After adding to cart, the delivery choice should remain. Reload the cart to show that the selection is remembered for the session.

**Action being performed:** Add to cart; open cart; reload once.

**Key teaching point:** Cart keeps the choice.

## Scene 4 — Checkout Delivery charge

**What is visible:** Checkout with Delivery shipping line.

**Narration:** At checkout, the customer should see a Delivery charge that matches their selection and destination. Stop before payment. No order creation is required for this video.

**Action being performed:** Open checkout; highlight Delivery charge; do not pay.

**Key teaching point:** Server-calculated charge — never invent a fee in the browser.


---

## Full transcript

[Scene 1] The customer starts on the product page. They choose a delivery option before adding to cart when the store requires it.

[Scene 2] Release candidate labels use Fulfilment, Delivery method, Delivery option, and Estimated delivery. Teach these words — not internal field names.

[Scene 3] After adding to cart, the delivery choice should remain. Reload the cart to show that the selection is remembered for the session.

[Scene 4] At checkout, the customer should see a Delivery charge that matches their selection and destination. Stop before payment. No order creation is required for this video.

---

## Chapter timestamps

Refine after final recording:

- 00:00 Title / start
- (planned) Scene 1: Product page
- (planned) Scene 2: RC.2 labels
- (planned) Scene 3: Cart and reload
- (planned) Scene 4: Checkout Delivery charge
- (planned total) 6–9 minutes

---

## Privacy notes for this video

- Use QA data only; blur customer PII on order screens.
- Never show passwords, cookies, nonces, payment details, or auth state.
- Prefer read-only walks; restore any temporary QA config changes.
