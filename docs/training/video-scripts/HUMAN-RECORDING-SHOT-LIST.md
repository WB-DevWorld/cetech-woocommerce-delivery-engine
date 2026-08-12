# Human recording shot list (Stage 12C)

Use when Playwright video capture is blocked (for example by Cloudflare). Follow the same scene lists as `01`–`12` narration scripts.

## Setup

1. Clean desktop; hide personal notifications.
2. Browser zoom consistent; target readable WordPress UI (≈1080p capture where feasible).
3. Log in manually; do not record password entry if avoidable (cut that segment).
4. Prefer QA products `#39705`, `#39717`–`#39719` and orders `#39721` / `#39724`.
5. Narrate live or record voice later from the matching `video-scripts/*.md` transcript.

## Per-video checklist

For each canonical filename in `docs/training/assets/videos/README.md`:

- [ ] Follow scenes in order from the matching script
- [ ] Deliberate pauses before important clicks
- [ ] No private data on screen
- [ ] Label capture method **HUMAN** (or **MIXED**) in `10-VIDEO-TRAINING-LIBRARY.md`
- [ ] Export `.webm` or `.mp4` to local/Release/Drive per storage policy — do not auto-bloat Git

## Privacy gate before distribution

Review the full cut once. Reject any take that shows credentials, PII, payment data, or secrets.
