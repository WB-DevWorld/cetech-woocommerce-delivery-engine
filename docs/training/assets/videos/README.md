# Staff walkthrough videos (RC.2)

**Purpose:** Training-quality walkthrough recordings for CETECH Delivery Engine **1.0.0-rc.2**.  
**Not for:** Raw Playwright debug traces, failure retries, or unattended smoke dumps.

## Canonical filenames

| # | File |
|---|------|
| 01 | `01-getting-started-overview.webm` |
| 02 | `02-default-delivery-settings.webm` |
| 03 | `03-configure-simple-product.webm` |
| 04 | `04-configure-variable-product.webm` |
| 05 | `05-variation-inheritance-overrides.webm` |
| 06 | `06-delivery-settings-preview.webm` |
| 07 | `07-customer-cart-checkout.webm` |
| 08 | `08-multi-product-shipping.webm` |
| 09 | `09-order-delivery-information.webm` |
| 10 | `10-staff-troubleshooting.webm` |
| 11 | `11-legacy-rules-explained.webm` |
| 12 | `12-complete-staff-walkthrough.webm` |

Optional MP4 copies may use the same basename (for staff players that prefer MP4).

## Storage policy (do not bloat Git)

Narration scripts, the video index, and transcripts **belong in Git**.

Large `.webm` / `.mp4` binaries usually **do not**. Before committing any binary:

1. Check file size (prefer keeping individual files under a few MB; avoid multi‑hundred‑MB packs).
2. If large, store binaries outside Git and link from `docs/training/10-VIDEO-TRAINING-LIBRARY.md`:
   - GitHub **Release** assets on a private docs/training release, or
   - Private Drive / internal training storage shared with staff.
3. Keep this folder’s README + `transcripts/` in the repo even when media files live elsewhere.

Recommended local layout when binaries are external:

```text
docs/training/assets/videos/
  README.md                 ← in Git
  transcripts/*.vtt         ← in Git when available
  *.webm / *.mp4            ← local or Release/Drive (often gitignored)
```

Capture working copies may also land under `training/playwright-videos/output/` (gitignored). Copy finished teaching files here (or to Release/Drive) only after privacy review.

## Capture methods

Label each final video honestly in the index:

| Label | Meaning |
|-------|---------|
| **PLAYWRIGHT** | Deliberate paced recording via `training/playwright-videos` (`video: on`) |
| **HUMAN** | Trainer screen recording following the narration shot list |
| **MIXED** | Human auth / Cloudflare, then Playwright paced capture (or human polish over Playwright) |

Cloudflare must not be bypassed. Prefer a clean human recording over fighting the challenge.

## Privacy checklist (every final video)

Must **not** show:

- Passwords, cookies, nonces, auth/storage state
- Real customer PII, private emails, payment details
- Browser password manager chrome
- SSH, server secrets, Application Passwords

Use QA products `#39705`, `#39717` / `#39718` / `#39719` and existing QA orders `#39721` / `#39724`. Prefer read-only walks. Do not create unnecessary paid orders solely for video.

## Related docs

- Index: [`../../10-VIDEO-TRAINING-LIBRARY.md`](../../10-VIDEO-TRAINING-LIBRARY.md)
- Narration scripts: [`../../video-scripts/`](../../video-scripts/)
- Recording harness: `training/playwright-videos/`
- Screenshots (Stage 12B): [`../screenshots/`](../screenshots/)
