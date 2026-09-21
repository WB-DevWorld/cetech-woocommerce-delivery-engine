# PHP 8.3–8.5 CI / runtime realignment

**Status:** implementation record for the 21 September 2026 owner PHP realignment, **corrected the same day** so PHP 8.3 is the minimum supported version  
**Branch:** `ws3/php-85-runtime-realignment` from protected `master` `5abfab0b5078e67b158f282088022b2ac2566f22`  
**PR:** #37 (amended; do not merge the first 8.1-floor draft)  
**Not RC.13.** No tag/package identity change. No FLAIROC / training / production / Pilot mutation.

## Why

The repository used PHP 8.1 as the runtime lint gate and PHP 8.2 as the principal PHPUnit CI job. That configuration was being misread as both the CETECH production environment and the commercial support floor.

Owner policy uses the oldest PHP version currently **recommended** by WordPress and WooCommerce, through the latest stable PHP suitable for production. As of 21 September 2026 that range is **PHP 8.3 through PHP 8.5.x**. PHP 8.6 remains pre-release and is not a production target.

The first PR #37 draft incorrectly retained PHP 8.1 as a commercial floor. Decision 8 supersedes that draft.

## Policy

| Item | Decision |
|------|----------|
| Minimum supported PHP (`Requires PHP`, `composer.json` `>=8.3`, activation guard) | **8.3** |
| Supported / certified range | **PHP 8.3, 8.4, 8.5.x** |
| CETECH production / currently qualified latest stable | **PHP 8.5.x** (latest stable patch at deploy) |
| PHP 8.1 / 8.2 | **Not supported.** Do not advertise. No CI support lane. |
| Principal blocking CI PHPUnit job | **PHP 8.5** (plus blocking PHP 8.3 minimum-support gate) |
| Future PHP (8.6+) | Add only after WordPress, WooCommerce, Delivery Engine, DB/migration, and CETECH stack qualification |
| Plugin business logic / schema / version identity | **Unchanged** |
| Immutable tags `v1.0.0-rc.12` and earlier | **Unchanged** |

Canonical policy: `docs/PHP-RUNTIME-POLICY.md`.

## CI matrix and durable required-check names

| Job name | PHP | Role |
|----------|-----|------|
| `PHP 8.3 Minimum Supported` | 8.3 | Blocking Composer, runtime/application lint, PHPUnit, control-plane PHP |
| `PHP 8.4 Compatibility` | 8.4 | Composer, lint, PHPUnit |
| `PHP 8.5 CETECH Production Target` | 8.5 | Blocking Composer, full production lint, complete PHPUnit, production `--no-dev` tree |
| `PHP 8.5 MariaDB Geography/Migrations` | 8.5 | Real MariaDB geography/migration proofs |
| `PHP 8.5 WordPress/WooCommerce` | 8.5 | WP/Woo boot, activation, HPOS, Store API, clean install, RC.12 upgrade |
| `CI Required Gates` | aggregator | Fails unless 8.3, 8.4, all 8.5 jobs, JavaScript, and Control Plane succeeded |
| `JavaScript / Vitest` | n/a | Unchanged |
| `Control Plane` | 8.5 | Unchanged |

Removed: `Minimum compatibility PHP 8.1`, `Runtime PHP 8.1`, `PHP / PHPUnit 8.2`.

GitHub ruleset `master-2` (id `23441703`) must be transitioned to the truthful names above **before** aliases are relied on, and must not leave `master` mergeable without 8.3 + 8.5 protections.

## What public CI can and cannot prove

Automated PHP 8.5 GitHub jobs prove plugin parse, Composer, PHPUnit (stubbed WordPress/Woo harness), MariaDB geography/migration proofs, a Composer `--no-dev` production tree, and an isolated WordPress + WooCommerce activation/HPOS/Store API/RC.12 upgrade smoke.

They do **not** prove WoodMart, B2BKing, FOX/WOOCS, WP Rocket, Redis/object cache, payment plugins, or CETECH Pilot production. Those remain explicit staging/Pilot gaps. If a production dependency is incompatible with PHP 8.5, report that incompatibility. Do not silently lower CETECH's PHP version.

## PHP 8.5 findings known before this change

Historical local PHPUnit on PHP 8.5.0 reported `ReflectionMethod::setAccessible()` deprecations in **tests only**. No production `src/` call sites were found. This task does not mix test-harness cleanup into the CI realignment.

## Local evidence (this branch, after the 8.3-floor correction)

Developer host **PHP 8.5.0** / PHPUnit **10.5.64**:

- Production PHP lint: **484 files / 0 failures**
- Default PHPUnit: **1274 tests, 8008 assertions, OK**, **13 deprecations**, **1 skipped**
- Control plane: **OK**

Docker **PHP 8.3.33**:

- Production PHP lint: **484 files / 0 failures**
- Application/tests/tooling lint: **2433 files / 0 failures**
- Default PHPUnit: **1274 tests, 8011 assertions, OK**, **1 skipped**, **0 deprecations**

Docker **PHP 8.4.25**:

- Production PHP lint: **484 files / 0 failures**
- Application/tests/tooling lint: **2433 files / 0 failures**
- Default PHPUnit: **1274 tests, 8011 assertions, OK**, **2 deprecations**, **1 skipped**

Deprecations were not treated as failures. They are consistent with historical PHP 8.4/8.5 `ReflectionMethod::setAccessible()` use in **tests**, not `src/`. This task does not mix test-harness cleanup into the CI change.

Not run in this correction (gated to GitHub Actions / Pilot):

- real MariaDB geography groups (`phpunit.real-db.xml`)
- WordPress + WooCommerce activation / HPOS / Store API / RC.12 upgrade smoke
- WoodMart / B2BKing / FOX-WOOCS / cache / payment (proprietary; not in public CI)

No plugin `src/` compatibility failure was found on PHP 8.3, 8.4, or 8.5.
