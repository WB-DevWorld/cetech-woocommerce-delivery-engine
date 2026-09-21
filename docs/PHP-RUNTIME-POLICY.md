# PHP runtime policy

**Status:** Owner-directed CETECH production/runtime policy, 21 September 2026  
**Does not:** change plugin business logic, raise the commercial PHP floor, move a release tag, or create RC.13

This file is the canonical distinction between the CETECH production PHP target and the commercial supported minimum. CI job names, package qualification, and Pilot checks must use these labels.

## Production recommended / certified PHP

- **CETECH production target:** PHP **8.5.x**
- Use the **latest stable PHP 8.5 patch** available at deployment time.
- As of 21 September 2026 the current stable patch is **PHP 8.5.10**. CI should track the 8.5 line (`8.5`), not a frozen patch, so later 8.5 security releases are picked up.
- **PHP 8.6 is still a pre-release/beta line and must not be used as the production target.**

Local development, integration testing, release qualification, package verification, migration testing, WordPress/WooCommerce integration testing, and CETECH Pilot testing must include PHP 8.5.

Passing PHP 8.1 or PHP 8.2 **does not** prove PHP 8.5 compatibility and must not be used as the sole qualification for a CETECH production or Pilot deploy.

## Minimum supported PHP

- **Commercial supported minimum:** PHP **8.1**
- `composer.json` (`php: >=8.1`), the plugin header `Requires PHP: 8.1`, and the activation guard remain the declared floor until a later **explicit commercial-support decision** changes them.
- Do not remove PHP 8.1 support merely because CETECH production uses PHP 8.5.
- A minimum-version CI job is a **compatibility test**, not the production target.
- PHP 8.1 is past the PHP project's security end-of-life (31 December 2025). Keeping it as a commercial floor is a conscious support decision, not an accident of old CI configuration.

## CI matrix

| Lane | PHP | Role |
|------|-----|------|
| Minimum compatibility | 8.1 | Commercial floor lint + PHPUnit. Not CETECH production. |
| WooCommerce recommended-floor | 8.3 | Compatibility, matching WooCommerce's representative test PHP. |
| Mature WooCommerce compatibility | 8.4 | Compatibility. |
| **CETECH production target** | **8.5** | **Blocking merge/release gate.** |

PHP 8.2 is no longer the principal PHPUnit job. A historical GitHub required-check alias named `PHP / PHPUnit 8.2` must succeed only when the PHP 8.5 production-target job has succeeded, until the repository ruleset is renamed.

PHP 8.5 CI must run at least:

- Composer validation
- production PHP lint
- full PHPUnit suite
- database/migration proofs against real MariaDB where those tests exist
- WordPress bootstrap + WooCommerce activation, HPOS enablement, Classic cart/checkout page presence, Store API cart, Action Scheduler presence
- package/autoload verification on a Composer `--no-dev` tree
- extracted production-tree PHP lint
- upgrade from current production package identity `1.0.0-rc.12`
- control-plane verification

Public GitHub CI cannot install proprietary CETECH production dependencies (WoodMart, B2BKing, FOX/WOOCS, WP Rocket, payment plugins). Those remain Pilot/staging evidence.

## Local / isolated QA

Use `docker/php85-qa/docker-compose.yml`:

- PHP 8.5 WordPress image
- MariaDB 11.4 (current isolated-qualification database until production names a different version)
- current supported WordPress from the official image
- current supported WooCommerce installed in the lab, not baked into this repository

Do not qualify a release only on PHP 8.1/8.2 and then deploy it to PHP 8.5.

## CETECH Pilot / production

Before the first CETECH Pilot production deployment:

1. Read the exact PHP version on the target server (`php -v`).
2. Use the latest stable PHP 8.5 patch available on that host.
3. Run the accepted plugin package on an equivalent PHP 8.5 staging/qualification environment.
4. Perform clean-install and upgrade tests.
5. Verify WooCommerce, HPOS, WoodMart, and other installed production dependencies on PHP 8.5.
6. Check PHP error and deprecation logs.
7. Establish rollback before deployment.

If WordPress, WooCommerce, a theme, or a plugin dependency is incompatible with PHP 8.5, report that incompatibility explicitly. Do not silently lower CETECH's production PHP version.

## WordPress / WooCommerce

The goal is not merely that plugin files parse on PHP 8.5. The supported stack that must be proven together is:

PHP 8.5 → WordPress (6.9+ / 7.x, which document PHP 8.5 support) → WooCommerce → CETECH Delivery Engine → HPOS → Classic / Blocks → WoodMart → applicable B2BKing / FOX-WOOCS / cache / payment / other production dependencies.

WooCommerce currently tests **8.5 as its latest supported PHP**. That is evidence for CETECH's production target; it is not a substitute for CETECH Pilot evidence on the real site.

## Historical CI (do not misread)

RC.11 / RC.12 GitHub required jobs were:

- Runtime PHP 8.1
- PHP / PHPUnit 8.2
- JavaScript / Vitest
- Control Plane

Those jobs were **compatibility and then-current CI configuration**. They were not a declaration that CETECH production runs PHP 8.1 or 8.2. FLAIROC Stage 0B already recorded PHP **8.5.5** on 2026-08-10.
