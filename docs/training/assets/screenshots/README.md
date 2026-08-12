# Screenshot capture status (RC.2 training)

**Policy:** Never fabricate screenshots or claim live capture when capture failed.

## Live-capture attempts (automated Playwright from this environment)

| Surface | Result |
|---------|--------|
| Admin wp-login / Delivery Settings | **Blocked** — Cloudflare challenge on automated login. Admin scenarios skip until local `auth/storage-state.json` is provided (gitignored). |
| Storefront QA product pages | **Blocked** — Cloudflare “Sorry, you have been blocked”. Public capture scenarios skip cleanly. |
| Cart / checkout delivery fee | **Not captured** — depends on storefront access + prepared cart; no paid orders created for docs. |
| Order Delivery information | **Not captured** — requires admin auth storage state. |

## Canonical filenames (for human re-capture)

Place genuine RC.2 screenshots here when a human browser session can access FLAIROC:

- `00-delivery-engine-dashboard.png`
- `01-delivery-settings-home.png`
- `02-default-settings.png`
- `03-product-specific-settings.png`
- `03b-product-39705-editor.png`
- `04-variation-specific-settings.png`
- `04b-variation-39718-editor.png`
- `05-delivery-preview-ready.png`
- `06-simple-product-customer-view.png`
- `06b-variable-product-customer-view.png`
- `07-cart-delivery-information.png`
- `08-checkout-delivery-charge.png`
- `09-order-delivery-information.png`
- `10-legacy-delivery-rules.png`

## How to capture later

See `training/playwright/README.md`: save gitignored auth state manually if needed, then:

```bash
cd training/playwright
npm install
npx playwright install chromium
npm run test:capture
```

Use QA products `#39705` / `#39717`–`#39719` only. Blur customer PII.
