# V1 Release Candidate — Packaging Guide

**Target:** `v1.0.0-rc.1`  
**Plugin slug:** `cetech-woocommerce-delivery-engine`  
**Package name:** `cetech-woocommerce-delivery-engine-v1.0.0-rc.1.zip`

This guide describes how to build a **private RC install ZIP** for staging. It does **not** create a Git tag or GitHub release.

---

## Purpose

Produce a WordPress-installable plugin folder/ZIP from a **clean, verified** `master` commit so staging can run the [V1 RC smoke test checklist](V1-RC-SMOKE-TEST-CHECKLIST.md) before any public RC tag.

---

## Required clean Git state

Before packaging:

```powershell
git status          # must be clean
git branch --show-current   # master
git log -1 --oneline        # includes V1 RC cleanup + Phase 2H4
```

Do **not** package from uncommitted changes.

---

## Required local tools

| Tool | Purpose |
|------|---------|
| **PHP** 8.1+ | Lint + optional Composer platform |
| **Composer** | Generate `vendor/autoload.php` (runtime required) |
| **PowerShell** 5.1+ | Run `scripts/build-v1-rc-package.ps1` |
| **Zip** | Provided via `Compress-Archive` in PowerShell |

---

## Pre-package checks

Run from repository root:

```powershell
git status
composer dump-autoload -o
Get-ChildItem -Path src,database -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }
php -l cetech-woocommerce-delivery-engine.php
php -l uninstall.php
php -l src/Bootstrap/FeatureFlags.php
```

Complete staging tests from [V1-RC-SMOKE-TEST-CHECKLIST.md](V1-RC-SMOKE-TEST-CHECKLIST.md) **after** installing the ZIP on a staging site.

Reference flag enablement order: [V1-RC-FLAG-MATRIX.md](V1-RC-FLAG-MATRIX.md).

---

## Build command

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.1
```

Outputs (not committed):

- `dist/cetech-woocommerce-delivery-engine-v1.0.0-rc.1.zip`
- `dist/cetech-woocommerce-delivery-engine-v1.0.0-rc.1.zip.sha256`

---

## Package contents

The ZIP must contain a **single top-level folder**:

```text
cetech-woocommerce-delivery-engine/
  cetech-woocommerce-delivery-engine.php   # bootstrap
  uninstall.php
  composer.json
  readme.txt
  src/
  database/
  vendor/                                  # required at runtime
  docs/                                      # optional for private RC distribution
```

### Required runtime files

- Root plugin file: `cetech-woocommerce-delivery-engine.php`
- PSR-4 source: `src/`
- Migrations: `database/`
- **Composer autoload:** `vendor/autoload.php` (plugin exits with admin notice if missing)

### Optional for private RC

- `docs/` (RC flag matrix, smoke checklist, release notes, packaging guide)
- `scripts/` (build script only — not required on production sites)

---

## Exclusions

Do **not** include in the ZIP:

- `.git/`, `.github/`
- `dist/`, `build/`
- `node_modules/`, `tests/`, `coverage/`
- `.env`, secrets, credentials
- `*.zip`, `*.log`
- IDE folders (`.vscode/`, `.idea/`)
- `.DS_Store`, `Thumbs.db`

---

## SHA256 checksum

The build script writes:

```text
dist/cetech-woocommerce-delivery-engine-v1.0.0-rc.1.zip.sha256
```

Verify manually:

```powershell
Get-FileHash dist/cetech-woocommerce-delivery-engine-v1.0.0-rc.1.zip -Algorithm SHA256
```

Compare to the `.sha256` file contents.

---

## Post-build ZIP inspection

1. Extract ZIP to a temp folder.
2. Confirm structure:

```text
cetech-woocommerce-delivery-engine/cetech-woocommerce-delivery-engine.php
cetech-woocommerce-delivery-engine/src/
cetech-woocommerce-delivery-engine/database/
cetech-woocommerce-delivery-engine/vendor/autoload.php
```

3. Confirm **no** `.git` inside the archive.
4. The build script runs `scripts/verify-production-package-autoload.php` against the staging copy after `composer install --no-dev`. That gate confirms:
   - `ConfigurationHealthChecker` autoloads from `Application\Diagnostics`
   - `Plugin.php` imports that class (prevents Bootstrap namespace collision)
   - boot factory short-class references have imports
   - schema target `3` and ECR cutover flag default OFF
   - PHPUnit is **not** present in production `vendor/`

Staging always rebuilds production `vendor/` (repo `vendor/` is not copied).

---

## WordPress staging install test

1. Upload ZIP via **Plugins → Add New → Upload Plugin** (or copy folder to `wp-content/plugins/`).
2. Activate only when WooCommerce is active.
3. Confirm **Delivery Engine** admin menu and **System Status** load.
4. Confirm all runtime flags default **off** (see flag matrix).

---

## HPOS smoke test

On an HPOS-enabled staging store:

1. Place a test order with full flag chain enabled (see flag matrix).
2. Open order in admin — snapshot meta box renders.
3. Confirm no PHP errors in logs.

---

## Rollback

1. Deactivate plugin in WordPress.
2. Delete plugin folder from `wp-content/plugins/cetech-woocommerce-delivery-engine/`.
3. Configuration data remains in DB unless uninstall delete-data opt-in was used.
4. Restore previous plugin ZIP/folder if needed.

---

## Final rule

**Do not tag `v1.0.0-rc.1` in Git until staging smoke tests pass.**

After smoke tests pass:

1. Optionally align plugin header / `CETECH_DE_VERSION` / `readme.txt` Stable tag with RC version.
2. Commit any version doc updates (not the ZIP).
3. Create annotated tag and private GitHub release (operator action).
