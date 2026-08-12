# Auth directory (gitignored storage)

This folder may contain local Playwright `storage-state.json` after a successful login.

## Human-assisted auth (preferred)

From `training/playwright`:

```bash
npm run training:auth
```

1. Headed Chrome opens the FLAIROC wp-admin URL.
2. Complete Cloudflare and WordPress login yourself.
3. When the admin bar is visible, the script saves `storage-state.json` (or press Enter to finish early).
4. Never commit that file.

**Never commit:**

- `storage-state.json`
- cookies / session tokens / nonces
- Application Passwords
- screenshots that show credentials

Committed files here: `README.md` and `.gitkeep` only.
