# PHP runtime policy

**Status:** Owner-directed CETECH PHP support policy, 21 September 2026 (corrected)  
**Does not:** change plugin business logic, move a release tag, or create RC.13

This file is the canonical PHP support policy. CI job names, package qualification, commercial language, and Pilot checks must use these labels.

Do not confuse the oldest PHP version WordPress or WooCommerce can still boot with the oldest PHP version they currently **recommend**. This product uses the recommended floor.

## Supported / certified range today

- **PHP 8.3**
- **PHP 8.4**
- **PHP 8.5.x**

PHP 8.1 and PHP 8.2 are **not** supported and must not be advertised.

## Minimum supported PHP

- **Minimum supported PHP: 8.3**
- This is the WordPress and WooCommerce recommended floor as of 21 September 2026.
- `composer.json` (`php: >=8.3`), the plugin header `Requires PHP: 8.3`, and the activation guard must match this floor.
- PHP 8.3 is a **blocking** CI gate.

## Recommended / CETECH production PHP

- **Recommended production PHP:** latest qualified stable release.
- **Currently qualified latest stable:** PHP **8.5.x**
- Use the **latest stable PHP 8.5 patch** available at deployment time (8.5.10 as of 21 September 2026).
- CI tracks the 8.5 line (`8.5`), not a frozen patch.
- PHP 8.5 is a **blocking** CETECH production CI gate.

Local development, integration testing, release qualification, package verification, migration testing, WordPress/WooCommerce integration testing, and CETECH Pilot testing must include PHP 8.5.

Passing PHP 8.3 or PHP 8.4 does **not** prove PHP 8.5 compatibility and must not be used as the sole qualification for a CETECH production or Pilot deploy.

## Future PHP releases (including 8.6)

PHP 8.5 is **not** a permanent architectural maximum.

When a newer PHP line (for example 8.6) becomes stable, it may be added to the certified window only after:

- upstream WordPress compatibility;
- upstream WooCommerce compatibility;
- Delivery Engine CI qualification;
- database/migration tests;
- WordPress/WooCommerce stack tests;
- third-party CETECH stack qualification where relevant.

Do **not** run production on a pre-release PHP merely because its version number exceeds 8.5. PHP 8.6 is currently beta/pre-release and is **not** a production target.

## CI matrix

| Lane | PHP | Role |
|------|-----|------|
| Minimum supported | 8.3 | Blocking Composer, lint, PHPUnit, control-plane PHP. |
| Supported compatibility | 8.4 | Composer, lint, PHPUnit. |
| Latest stable / CETECH production | 8.5 | Blocking Composer, full lint, PHPUnit, MariaDB, WP/Woo/HPOS/Store API, production tree, RC.12 upgrade. |

Durable GitHub required checks:

- `PHP 8.3 Minimum Supported`
- `PHP 8.4 Compatibility`
- `PHP 8.5 CETECH Production Target`
- `PHP 8.5 MariaDB Geography/Migrations`
- `PHP 8.5 WordPress/WooCommerce`
- `CI Required Gates`
- `JavaScript / Vitest`
- `Control Plane`

Do not keep `Runtime PHP 8.1` or `PHP / PHPUnit 8.2` as durable required-check names.

## Local / isolated QA

Use `docker/php85-qa/docker-compose.yml` for the CETECH production PHP target.

Do not qualify a CETECH release only on PHP 8.3/8.4 and then deploy it to PHP 8.5.

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

The supported stack that must be proven together is:

PHP 8.5 → WordPress (recommended PHP 8.3+) → WooCommerce (recommended PHP 8.3+; latest supported PHP 8.5) → CETECH Delivery Engine → HPOS → Classic / Blocks → WoodMart → applicable B2BKing / FOX-WOOCS / cache / payment / other production dependencies.

Public GitHub CI cannot install proprietary CETECH production dependencies. Those remain Pilot/staging evidence.

## Commercial language

Use:

- Minimum supported PHP: **8.3**
- Recommended production PHP: **latest qualified stable release**
- Currently qualified latest stable: **PHP 8.5.x**

Do not advertise PHP 8.1 or PHP 8.2 as supported unless a later explicit owner decision changes this policy.

## Historical CI (do not misread)

RC.11 / RC.12 GitHub required jobs were `Runtime PHP 8.1` and `PHP / PHPUnit 8.2`. Those jobs are **historical CI configuration**, not current commercial support. FLAIROC Stage 0B already recorded PHP **8.5.5** on 2026-08-10.
