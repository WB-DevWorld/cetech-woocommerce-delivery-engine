# PHP 8.5 CI / runtime realignment

**Status:** implementation record for the 21 September 2026 owner PHP realignment  
**Branch:** `ws3/php-85-runtime-realignment` from protected `master` `5abfab0b5078e67b158f282088022b2ac2566f22`  
**Not RC.13.** No tag/package identity change. No FLAIROC / training / production / Pilot mutation.

## Why

The repository used PHP 8.1 as the runtime lint gate and PHP 8.2 as the principal PHPUnit CI job. That configuration was being misread as the CETECH production environment. CETECH production is intended to run the latest stable PHP 8.5 patch (8.5.10 as of 21 September 2026). PHP 8.6 remains pre-release and is not a production target.

## Policy retained vs changed

| Item | Decision |
|------|----------|
| Commercial minimum (`Requires PHP`, `composer.json` `>=8.1`, activation guard) | **Unchanged** at 8.1 |
| CETECH production / primary qualification | **PHP 8.5.x** |
| Principal blocking CI PHPUnit job | **Moved from 8.2 to 8.5** |
| PHP 8.3 / 8.4 | Added as WooCommerce compatibility lanes |
| Plugin business logic / schema / version identity | **Unchanged** |
| Immutable tags `v1.0.0-rc.12` and earlier | **Unchanged** |

Canonical policy: `docs/PHP-RUNTIME-POLICY.md`.

## What public CI can and cannot prove

Automated PHP 8.5 GitHub jobs prove plugin parse, Composer, PHPUnit (stubbed WordPress/Woo harness), MariaDB geography/migration proofs, a Composer `--no-dev` production tree, and an isolated WordPress + WooCommerce activation/HPOS/Store API/RC.12 upgrade smoke.

They do **not** prove WoodMart, B2BKing, FOX/WOOCS, WP Rocket, payment plugins, or CETECH Pilot production. Those remain explicit staging/Pilot gaps.

## PHP 8.5 findings known before this change

Historical local PHPUnit on PHP 8.5.0 reported `ReflectionMethod::setAccessible()` deprecations in **tests only**. No production `src/` call sites were found. This task does not mix test-harness cleanup into the CI realignment.

## Local PHP 8.5 evidence (this branch, before PR)

Local CLI **PHP 8.5.0** / PHPUnit **10.5.64** on `ws3/php-85-runtime-realignment` (base `5abfab0`):

- Production + tests + scripts lint: **670 files / 0 failures**
- Default PHPUnit: **1273 tests, 8005 assertions, OK**, **13 deprecations**, **1 skipped**
- Control plane: **OK** (team + product; 372 Requirement IDs)
- Composer `--no-dev` production tree verifier: **OK**; staged PHP lint **484 files / 0 failures**

Deprecations were not treated as failures. They are consistent with historical PHP 8.5 `ReflectionMethod::setAccessible()` use in **tests**, not `src/`. This task does not mix test-harness cleanup into the CI change.

Not run locally in this session (gated to GitHub Actions / Docker):

- PHP 8.1 / 8.3 / 8.4 matrix jobs
- real MariaDB geography groups (`phpunit.real-db.xml`)
- WordPress + WooCommerce activation / HPOS / Store API / RC.12 upgrade smoke
- WoodMart / B2BKing / FOX-WOOCS / cache / payment (proprietary; not in public CI)

## GitHub ruleset

Humans should add these required checks:

- `PHP 8.5 CETECH production target`
- `PHP 8.5 MariaDB geography/migrations`
- `PHP 8.5 WordPress/WooCommerce activation`
- `CI required gates`

Until that rename, aliases keep the historical names `Runtime PHP 8.1` and `PHP / PHPUnit 8.2`. The 8.2 alias now fails unless the PHP 8.5 production-target job succeeded.
