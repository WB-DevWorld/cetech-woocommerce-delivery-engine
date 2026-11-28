# Phase 1 base merge: `fix/m2-2026-10` (`1.0.0-m2.6`) into the M3–M8 batch

Date: 2026-11-28. Editor: Claude Code, the lead and integration editor (WS3) for Frank's M3–M8 authorization of 28 November 2026.

## What is merged

`fix/m2-2026-10` at `ef02a2208e628640c7c0d8a885163916bd74de0d` (`1.0.0-m2.6`, schema 6, built by `release-package.yml` run 45809038681, installed on staging 26 November 2026) is merged into a branch cut from `master` `08422f06fc9db81f43c5370412978bad04dd9096`. The merge base is `8497109f` (the m2.5 records, already forward-ported to `master` by #195).

The M2 line brings these commits onto the M3–M8 line:

| Commit | Change | M2 PR |
|---|---|---|
| `8ed5809` | Catalog CSV export batched and streamed, so it finishes on a large catalog | #196 |
| `221164d` | Admin help text says where the customer's delivery estimate really comes from (`DeliveryEstimateHelpText`) | #197 |
| `89c693f` | The customer place label names the region or country in words when no town is chosen | #200 |
| `360a97d` … `3495a19` | CI: qualified PHP 8.5.11 archive re-pin and PCRE2 alignment. The script is byte-identical to `master`'s #199, so it merged cleanly | #198 |
| `11ee5be` | Version `1.0.0-m2.6`. Its identity lines are not taken (see below) | #201 |

`fix/m2-6-records` (PR #202, documentation of the m2.6 staging install) is not merged into `fix/m2-2026-10`, so it is not part of this merge.

## Conflicts and resolution

Five files conflicted. All are identity or records, and each keeps `master`'s side:

| File | Resolution |
|---|---|
| `cetech-woocommerce-delivery-engine.php` | `master` identity `1.0.0-m3.0-dev.1` and its comment. The M2 schema-6 marker comment is not taken, so the package verifier does not class this tree as schema 6 |
| `readme.txt` | `Stable tag: 1.0.0-m3.0-dev.1` |
| `tests/Unit/Persistence/SchemaV6InspectionTest.php` | `master`'s version pin |
| `tests/Unit/Shipment/SchemaV4InspectionTest.php` | `master`'s version pin |
| `CURRENT-WORK.md` | `master`'s document unchanged. The M2 branch's own entries stay on `fix/m2-2026-10`; this file records the merge instead |

The rest merged without conflict. That includes `src/Bootstrap/Plugin.php`, where the export service now gets its own batch-scoped resolver (`CatalogScanScopedConfigurationRepository`). `master`'s `EffectiveConfigurationResolver` has the same four-argument constructor, and all four imports already exist on `master`.

## Local checks on the merged tree (PHP 8.5.0, Windows)

- `bash scripts/ci-lint-php.sh cetech-woocommerce-delivery-engine.php uninstall.php src database`: 924 files, 0 failures.
- `php scripts/verify-control-plane.php`: OK.
- PHPUnit and Vitest: recorded in the pull request, compared against `master` `08422f06` run the same way on the same laptop.

The schema target stays 12. Nothing is installed by this merge.
