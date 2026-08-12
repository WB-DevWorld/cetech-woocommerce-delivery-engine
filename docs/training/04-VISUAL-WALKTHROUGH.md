# Visual Walkthrough (screenshot tour)

**Audience:** New staff and trainers  
**Version:** 1.0.0-rc.2  
**Screenshots folder:** `docs/training/assets/screenshots/`

For each screen: (1) what you are looking at (2) what matters (3) what is safe to change (4) what to leave alone (5) what happens after Save.

Capture note: screenshots are produced by the Playwright harness when auth/storefront access allows. Filenames below are the canonical teaching set. **As of the Stage 12 automation pass, live FLAIROC capture was blocked by Cloudflare for both automated admin login and storefront browsing** — see `assets/screenshots/README.md`. Written callouts below still match RC.2 UI labels from the live plugin. If a PNG is missing, re-capture with a human session — do not invent images.

---

## 1. WordPress admin → Delivery Engine Dashboard

**Screenshot:** `00-delivery-engine-dashboard.png`

1. Looking at: Delivery Engine operations overview / readiness.  
2. Matters: links into everyday delivery configuration.  
3. Safe: follow links to Delivery Settings, offers, zones, rate cards.  
4. Leave alone: collapsed Advanced system details unless you are technical support.  
5. After Save: N/A (mostly informational).

---

## 2. Delivery Settings home

**Screenshot:** `01-delivery-settings-home.png`

1. Looking at: **Delivery Settings** with tabs Default / Product-Specific / Variation-Specific.  
2. Matters: this is the everyday editor.  
3. Safe: open the correct tab for your task.  
4. Leave alone: Legacy Delivery Rules link unless authorised.  
5. After Save: values stored for that level; customers use effective settings according to store configuration.

**Callouts:**  
① Page title Delivery Settings  
② Three level tabs  
③ Link to Delivery Settings Preview  

---

## 3. Default Settings

**Screenshot:** `02-default-settings.png`

1. Looking at: store-wide defaults products can inherit.  
2. Matters: Fulfilment availability, Fulfilment choice, Delivery offers, logistics/supplier/origin/priority as used by your store.  
3. Safe: set clear defaults your catalogue should follow.  
4. Leave alone: experimental changes without admin approval; private supplier/origin values if you are not authorised.  
5. After Save: products/variations on “Use inherited setting” pick up the new defaults (future behaviour — past orders unchanged).

---

## 4. Product-Specific Settings

**Screenshots:** `03-product-specific-settings.png`, `03b-product-39705-editor.png`

1. Looking at: overrides for one product (practice ID **39705**).  
2. Matters: mode per field — Use inherited setting / Set a different value here / Turn off.  
3. Safe: override only fields that must differ.  
4. Leave alone: unrelated products; mass overrides that should be Defaults instead.  
5. After Save: that product’s effective values update; verify in Preview.

---

## 5. Variation-Specific Settings

**Screenshots:** `04-variation-specific-settings.png`, `04b-variation-39718-editor.png`

1. Looking at: overrides for one variation (practice parent **39717**, variation **39718**/**39719**).  
2. Matters: parent product ID + variation ID must both be correct.  
3. Safe: variation-only differences.  
4. Leave alone: changing parent when only one variation should differ (or the reverse).  
5. After Save: only that variation’s effective path changes.

---

## 6. Delivery Settings Preview

**Screenshot:** `05-delivery-preview-ready.png`

1. Looking at: read-only view of what will apply.  
2. Matters: Ready vs Needs configuration; Currently using which level.  
3. Safe: everything (read-only).  
4. Leave alone: N/A.  
5. After Save: N/A — return to Delivery Settings to edit.

---

## 7. Customer product page (simple)

**Screenshot:** `06-simple-product-customer-view.png`

1. Looking at: shop product with **Delivery options**.  
2. Matters: customer must see understandable choices.  
3. Safe: staff change config in admin, not on this page.  
4. Leave alone: customer personal data if visible in browser chrome.  
5. After Save (in admin): refresh product page to verify.

---

## 8. Customer product page (variable)

**Screenshot:** `06b-variable-product-customer-view.png`

1. Looking at: variable product delivery selector / “select options” guidance.  
2. Matters: choices can change when Variation A → B.  
3. Safe: test with QA product only.  
4. Leave alone: non-QA catalogue.  
5. After admin Save: re-select variation to confirm refresh.

---

## 9. Cart

**Screenshot:** `07-cart-delivery-information.png`

1. Looking at: cart line delivery summary (Fulfilment, Delivery method/option, estimate as applicable).  
2. Matters: selection persisted.  
3. Safe: empty training carts when done.  
4. Leave alone: payment settings.  
5. After Save: N/A for cart; admin config changes require re-adding/re-selecting as needed.

---

## 10. Checkout

**Screenshot:** `08-checkout-delivery-charge.png`

1. Looking at: Classic Checkout shipping line **Delivery** with charge.  
2. Matters: server-authoritative fee; not silent $0.  
3. Safe: stop before payment for training.  
4. Leave alone: enabling COD or new gateways for screenshots.  
5. After placing a real order: Delivery information appears on the order (training should prefer existing QA orders).

---

## 11. WooCommerce order — Delivery information

**Screenshot:** `09-order-delivery-information.png`

1. Looking at: staff **Delivery information** panel.  
2. Matters: fulfilment, method/option, estimate, charge, products covered.  
3. Safe: read and fulfil against it.  
4. Leave alone: rewriting historical delivery facts to match new Defaults.  
5. After Save of future Defaults: this past order stays as purchased.

**Privacy:** blur customer names/emails/addresses in any shared screenshot.

---

## 12. Legacy Delivery Rules (warning stop)

**Screenshot:** `10-legacy-delivery-rules.png`

1. Looking at: older rules UI.  
2. Matters: not the everyday system.  
3. Safe: open read-only to recognise it; follow links back to Delivery Settings.  
4. Leave alone: edits unless explicitly authorised.  
5. After Save: can affect compatibility paths — escalate before changing.
