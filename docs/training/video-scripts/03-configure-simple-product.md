# VIDEO TITLE: Configure a simple product

**Canonical file:** `03-configure-simple-product.webm` (optional `03-configure-simple-product.mp4`)  
**Audience:** Everyday staff  
**Learning objective:** Configure simple QA product #39705 using inheritance or a deliberate product override, then verify Preview and the customer Delivery options.  
**Estimated duration:** 5–8 minutes  
**Capture method (fill after recording):** PLAYWRIGHT / HUMAN / MIXED — _pending_  
**Related written guide:** 03-USE-CASE-PLAYBOOK.md cases 1–2, 05 Module 4  
**Practice exercise:** On #39705 only: open Product-Specific Settings, prefer Use inherited setting, Preview, then check storefront Delivery options.  
**QA fixtures:** products `#39705`, `#39717` / `#39718` / `#39719`; orders `#39721` / `#39724` (read-only preferred)

---

## Scene 1 — Find product settings

**What is visible:** Product-Specific Settings; product ID 39705.

**Narration:** We will practise on the dedicated simple QA product, number 39705. Never practise by changing a real customer catalogue product.

**Action being performed:** Open Product-Specific Settings for #39705.

**Key teaching point:** QA products only for training.

## Scene 2 — Inherit defaults

**What is visible:** Fields set to Use inherited setting.

**Narration:** For most fields, leave Use inherited setting. That means this simple product follows Default Settings.

**Action being performed:** Scroll fields; highlight inherited modes.

**Key teaching point:** Inheritance is the default good practice.

## Scene 3 — Optional product-specific value

**What is visible:** One field with Set a different value here (demo only if authorised).

**Narration:** Only when this product truly needs something different, choose Set a different value here. Otherwise keep inheritance.

**Action being performed:** Show the mode control; avoid permanent production changes.

**Key teaching point:** Overrides must be intentional.

## Scene 4 — Save and Preview

**What is visible:** Save, then Preview Ready.

**Narration:** Save when you have made an authorised change. Then open Preview and confirm Ready, or understand any Needs configuration message.

**Action being performed:** Save only if trainer-authorised; open Preview.

**Key teaching point:** Preview after Save.

## Scene 5 — Customer-facing result

**What is visible:** Storefront product page Delivery options for #39705.

**Narration:** On the shop product page, customers see Delivery options in plain language. Staff configure in admin; customers choose on the product.

**Action being performed:** Open QA simple product storefront; focus Delivery options; no payment.

**Key teaching point:** Customer journey starts on the product page.


---

## Full transcript

[Scene 1] We will practise on the dedicated simple QA product, number 39705. Never practise by changing a real customer catalogue product.

[Scene 2] For most fields, leave Use inherited setting. That means this simple product follows Default Settings.

[Scene 3] Only when this product truly needs something different, choose Set a different value here. Otherwise keep inheritance.

[Scene 4] Save when you have made an authorised change. Then open Preview and confirm Ready, or understand any Needs configuration message.

[Scene 5] On the shop product page, customers see Delivery options in plain language. Staff configure in admin; customers choose on the product.

---

## Chapter timestamps

Refine after final recording:

- 00:00 Title / start
- (planned) Scene 1: Find product settings
- (planned) Scene 2: Inherit defaults
- (planned) Scene 3: Optional product-specific value
- (planned) Scene 4: Save and Preview
- (planned) Scene 5: Customer-facing result
- (planned total) 5–8 minutes

---

## Privacy notes for this video

- Use QA data only; blur customer PII on order screens.
- Never show passwords, cookies, nonces, payment details, or auth state.
- Prefer read-only walks; restore any temporary QA config changes.
