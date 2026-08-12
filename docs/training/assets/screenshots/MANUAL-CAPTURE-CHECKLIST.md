# MANUAL SCREENSHOT MODE (Stage 12B fallback)

Use this only if headed Playwright still cannot reach FLAIROC after human Cloudflare/login.

## Steps

1. Open **normal Chrome** (not Playwright).
2. Complete Cloudflare + WordPress admin login yourself.
3. Set window roughly **1440 × 1000**.
4. Capture each screen below with **Snipping Tool** or similar.
5. Save PNGs into `docs/training/assets/screenshots/` using the **exact filenames**.
6. Blur customer names, emails, addresses, phones, payment details before saving.
7. Do **not** include password fields, browser password managers, or full desktop chrome.

## Checklist (canonical filenames)

| # | Filename | Where to go | Notes |
|---|----------|-------------|-------|
| 01 | `01-delivery-settings-home.png` | Delivery Engine → Delivery Settings | Tabs visible |
| 02 | `02-default-settings.png` | Default Settings tab | Inheritance starting point |
| 03 | `03-product-specific-settings.png` | Product-Specific Settings | List/editor entry |
| 04 | `04-variation-specific-settings.png` | Variation-Specific Settings | |
| 05 | `05-delivery-preview-ready.png` | Delivery Settings Preview | Prefer **Ready** on QA #39705 |
| 06 | `06-delivery-preview-needs-configuration.png` | Preview | **Only if safe** on a QA item without damaging settings; otherwise omit |
| 07 | `07-simple-product-customer-delivery.png` | Shop QA #39705 | Delivery options |
| 08 | `08-variable-product-customer-delivery.png` | QA #39717 Variation A | |
| 09 | `09-variable-product-second-variation.png` | QA #39717 Variation B | |
| 10 | `10-cart-delivery-information.png` | Cart with QA selection | |
| 11 | `11-multi-product-cart-one-charge.png` | Cart with two compatible QA items | One ~25.00 charge |
| 12 | `12-checkout-delivery-charge.png` | Classic Checkout | Stop before payment |
| 13 | `13-order-delivery-information.png` | Existing order #39721 or #39724 | Redact PII |
| 14 | `14-order-shipping-clean.png` | Same order shipping line | No raw technical metadata |
| 15 | `15-legacy-delivery-rules.png` | Legacy Delivery Rules | Warning visible |
| 16 | `16-technical-diagnostic-tools.png` | Dashboard / diagnostics | Admin/support only |

Optional extras (useful, not required):

- `00-delivery-engine-dashboard.png`
- `03b-product-39705-editor.png`
- `04b-variation-39718-editor.png`

## After capture

Mark each file as **HUMAN-CAPTURED** in `docs/training/assets/screenshots/README.md`.
