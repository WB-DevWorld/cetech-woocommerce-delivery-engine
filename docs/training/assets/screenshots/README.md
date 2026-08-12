# Screenshot capture status (RC.2 training) — Stage 12B partial save

**Policy:** Never fabricate screenshots. Label capture method honestly.  
**Base URL:** `https://flairoc.com/intl/`  
**Auth:** Human-assisted headed Playwright → gitignored `training/playwright/auth/storage-state.json`  
**Runtime:** 1.0.0-rc.2 unchanged  
**Stage 12B status:** **BLOCKED — VISUAL CAPTURE INCOMPLETE** (canonical set not finished; Jane stopped for time)

## Inventory

| Filename | Screen | Audience | Capture method | RC version | Privacy reviewed | Used in | Status |
|----------|--------|----------|----------------|------------|------------------|---------|--------|
| `00-delivery-engine-dashboard.png` | Delivery Engine dashboard | Staff / admin | PLAYWRIGHT-CAPTURED (human-assisted auth) | 1.0.0-rc.2 | YES (admin UI) | Visual Walkthrough (optional) | SAVED |
| `01-delivery-settings-home.png` | Delivery Settings home | All staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | YES | Quick Start / Visual Walkthrough | SAVED |
| `02-default-settings.png` | Default Settings | All staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | YES | Visual Walkthrough / Admin Guide | SAVED |
| `03-product-specific-settings.png` | Product-Specific Settings | All staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | YES | Visual Walkthrough | SAVED |
| `03b-product-39705-editor.png` | Product #39705 editor | Staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | YES | Playbook / Manual | SAVED |
| `04-variation-specific-settings.png` | Variation-Specific Settings | All staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | YES | Visual Walkthrough | SAVED |
| `04b-variation-39718-editor.png` | Variation #39718 editor | Staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | YES | Playbook | SAVED |
| `05-delivery-preview-ready.png` | Delivery Settings Preview | All staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | YES | Visual Walkthrough | SAVED |
| `06-delivery-preview-needs-configuration.png` | Preview Needs configuration | Staff | — | 1.0.0-rc.2 | — | — | **MISSING** (optional; skipped for safety/time) |
| `07-simple-product-customer-delivery.png` | QA #39705 customer delivery | All staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | YES (storefront; admin bar may show if logged-in session) | Visual Walkthrough | SAVED |
| `08-variable-product-customer-delivery.png` | QA #39717 Variation A | All staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | YES | Visual Walkthrough | SAVED |
| `09-variable-product-second-variation.png` | QA #39717 Variation B | All staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | YES | Visual Walkthrough | SAVED |
| `10-cart-delivery-information.png` | Cart delivery labels | All staff | — | 1.0.0-rc.2 | — | — | **MISSING** (capture stopped) |
| `11-multi-product-cart-one-charge.png` | Multi-product one charge | All staff | — | 1.0.0-rc.2 | — | — | **MISSING** (capture stopped) |
| `12-checkout-delivery-charge.png` | Classic Checkout Delivery charge | All staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | **FAIL — billing PII visible** | — | **WITHHELD FROM COMMIT** (local file may remain; do not publish until redacted) |
| `13-order-delivery-information.png` | Order Delivery information | Staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | **FAIL — customer name/email/address/phone/IP** | — | **WITHHELD FROM COMMIT** |
| `14-order-shipping-clean.png` | Order shipping line | Staff | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | **FAIL — same order context / PII risk** | — | **WITHHELD FROM COMMIT** |
| `15-legacy-delivery-rules.png` | Legacy Delivery Rules | Staff (secondary) | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | YES | Visual Walkthrough | SAVED |
| `16-technical-diagnostic-tools.png` | Technical diagnostics | Admin / support only | PLAYWRIGHT-CAPTURED | 1.0.0-rc.2 | YES | Admin / appendix only | SAVED |

## Counts

- Canonical checklist 01–16: **captured usable in repo ~11** (01–05, 07–09, 15–16) + optional `00`/`03b`/`04b`
- **Missing:** 06 (optional), **10**, **11**
- **Withheld (privacy):** 12, 13, 14 — re-capture with redaction required before merge

## Manual fallback

See [MANUAL-CAPTURE-CHECKLIST.md](MANUAL-CAPTURE-CHECKLIST.md) for human Chrome capture of remaining filenames.

## Auth

```bash
cd training/playwright
npm run training:auth
```

Uses FLAIROC `https://flairoc.com/intl/wp-admin/`. Never commit `auth/storage-state.json`.
