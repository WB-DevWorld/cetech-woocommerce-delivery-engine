# Training walkthrough VIDEO harness

**Purpose:** Capture deliberate, paced staff training videos for CETECH Delivery Engine **1.0.0-rc.2**.

**Not for:** Everyday smoke tests, screenshot dumps, or raw debug traces.

The screenshot/smoke suite remains in `training/playwright/` with `video: 'off'`. This project alone uses `video: 'on'`.

## Safety

1. Never commit `../playwright/auth/storage-state.json` or credentials.
2. Use QA products `#39705`, `#39717`–`#39719` and existing orders `#39721` / `#39724`.
3. Do not place paid orders solely for video.
4. Do not bypass Cloudflare — run human auth first.
5. Review every `.webm` for privacy before distribution.
6. Prefer storing large binaries outside Git (Release / private Drive). See `docs/training/assets/videos/README.md`.

## Setup

```bash
cd training/playwright-videos
npm install
npx playwright install chrome
```

From repo root (after Stage 12B auth helper exists):

```bash
npm run training:auth
npm run training:video
```

## Commands

| Command | Use |
|---------|-----|
| `npm run auth` | Human-assisted headed login (shared storage state) |
| `npm run record:headed` | Record all paced videos headed |
| `npm run record -- --grep @video01` | Record one video |

Optional slower pacing:

```bash
set CETECH_DE_VIDEO_SLOWMO_MS=500
npm run record:headed
```

## Output

Playwright writes raw videos under `output/test-results/` (gitignored). Successful runs also attempt to copy canonical names into `docs/training/assets/videos/`.

If a test skips with Cloudflare, use:

`docs/training/video-scripts/HUMAN-RECORDING-SHOT-LIST.md`

and label the final video **HUMAN** in `docs/training/10-VIDEO-TRAINING-LIBRARY.md`.
