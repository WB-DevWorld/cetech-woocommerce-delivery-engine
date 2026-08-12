# Playwright documentation & training harness

**Purpose:** Capture teaching screenshots and smoke-check that staff training docs still match CETECH Delivery Engine **1.0.0-rc.2** UI.

**Not for:** Replacing PHPUnit, changing live business data, or storing credentials.

## Safety rules

1. Do **not** commit passwords, Application Passwords, cookies, or `auth/storage-state.json`.
2. Prefer dedicated QA products: simple `#39705`, variable `#39717` / A `#39718` / B `#39719`.
3. Do **not** create customer accounts, enable COD, or create repeated QA orders for screenshots.
4. Prefer **read-only** walks. If a configuration change is required, use QA records and restore state.
5. Never claim a screenshot was live-captured when it was not.

## Setup

```bash
cd training/playwright
npm install
npx playwright install chromium
```

Copy secrets into the **repository root** gitignored `.env.local` (names only shown here):

```text
FLAIROC_BASE_URL=https://flairoc.com/intl/
FLAIROC_WP_ADMIN_URL=https://flairoc.com/intl/wp-admin/
FLAIROC_WP_USERNAME=...
FLAIROC_WP_APP_PASSWORD=...
# Optional password login when Application Password HTML login is blocked:
# FLAIROC_WP_PASSWORD=...
```

## Authenticated admin captures

Cloudflare may block automated `wp-login.php`. Preferred flow:

1. Log in manually in a normal browser session when needed.
2. Or run `npm run auth:save` when password/app-password login is reachable.
3. Confirm `auth/storage-state.json` exists locally and remains gitignored.

```bash
npm run auth:save
```

## Commands

| Command | Use |
|---------|-----|
| `npm run test:smoke` | Label presence / navigation smoke (`@smoke`) |
| `npm run test:capture` | Write screenshots under `docs/training/assets/screenshots/` (`@capture`) |
| `npm run test:validate` | Replay primary tutorial selectors (`@validate`) |
| `npm test` | All documentation scenarios |

## Screenshot output

Files are written to:

`docs/training/assets/screenshots/`

Filenames follow the training library numbering (for example `01-delivery-settings-home.png`).

## QA fixtures (read-only preferred)

| ID | Role |
|----|------|
| `#39705` | Simple QA product |
| `#39717` | Variable parent |
| `#39718` | Variation A |
| `#39719` | Variation B |

Storefront example (simple): `/buy/flairoc-delivery-engine-qa-product/`

## Projects

- `public` — storefront / cart / checkout (no admin storage state)
- `admin` — wp-admin pages (requires storage state)
- `setup` — one-time auth save
