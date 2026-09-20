# RC.12 Promotion

Status: COMPLETE / TAGGED / PUBLISHED PRERELEASE / TRAINING SITE QUALIFIED / CETECH PILOT NOT STARTED / FLAIROC NOT DEPLOYED / PRODUCTION NOT DEPLOYED

## Purpose
Promote the owner-accepted Issue #23 geography baseline already integrated into protected `master` to `1.0.0-rc.12` without adding new runtime behavior.

## Source lineage
- Prior immutable release: `v1.0.0-rc.11` → annotated tag object `acaae9bfc9758cdee1b3f2ec47e94848e83f87da` → `384f564f64a2db766ae6907392e95fb366fb8533`
- Owner-accepted geo.16 package-source SHA: `7aeb4c573d04d12d8101c0e05bc8858ff332d63f`
- Geo.16 qualified ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.16.zip` (`1,764,171` bytes, SHA-256 `500b09878ba08b879e5bdfdb0ea6e58726a71fe90a6994a83bb96b1e314a3925`)
- Protected master integration / PR #24 merge commit: `3d786ba6440a5f6f850d736bda0da5a5f5236c1f`
- Protected-master post-PR-#24 CI run `35519854001`: SUCCESS
- Issue #23: CLOSED / COMPLETED
- RC.12 release branch: `release/rc12`
- RC.12 PR head (unchanged at merge): `3eaf0d77ec277e4bb3b633da1ac08e08c0790ea7`
- GitHub issue: `#26` CLOSED / COMPLETED
- GitHub PR: `#27` MERGED

## Target identity
- Version: `1.0.0-rc.12`
- `CETECH_DE_VERSION`: `1.0.0-rc.12`
- Schema: `6` (`SchemaVersion::TARGET`)
- No schema 7
- No new runtime feature work beyond the owner-accepted protected-master baseline

## Included post-RC.11 correction
Issue #23 adds canonical geography, Delivery Area coverage groups, cascading location UX, and the geo.16 Location Packs admin-form contract. RC.12 is an identity-only promotion of that already merged runtime. Recorded P3 items remain later cleanup.

## PR #27 merge
ChatGPT merge-safety review: PASS on exact head `3eaf0d77ec277e4bb3b633da1ac08e08c0790ea7` / base `3d786ba6440a5f6f850d736bda0da5a5f5236c1f`.

Merge method: merge commit (not squash, not rebase). One-time repository-admin bypass was used only for this PR at that exact HEAD because GitHub required an approving review the sole owner/author could not supply. Repository rulesets were not changed.

- Merge commit / resulting `origin/master`: `78594ad8962868683726373f58f4a8b1b48e4d0e`
- Parents: `3d786ba6440a5f6f850d736bda0da5a5f5236c1f` `3eaf0d77ec277e4bb3b633da1ac08e08c0790ea7`
- Merge time: `2026-09-20T16:15:10Z`
- Subject: `Merge pull request #27 from WB-DevWorld/release/rc12`

This merge commit is the prospective RC.12 release source.

## Protected-master CI
Run `35522128310` (`Delivery Engine CI`, event `push`, head `78594ad8962868683726373f58f4a8b1b48e4d0e`): SUCCESS.

Required jobs, all SUCCESS:
- Runtime PHP 8.1
- PHP / PHPUnit 8.2
- JavaScript / Vitest
- Control Plane

URL: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/actions/runs/35522128310

## Release-source verification
Compared `3d786ba6440a5f6f850d736bda0da5a5f5236c1f` → `78594ad8962868683726373f58f4a8b1b48e4d0e`.

- Plugin Version: `1.0.0-rc.12`
- `CETECH_DE_VERSION`: `1.0.0-rc.12`
- `SchemaVersion::TARGET`: `6`
- `src/` runtime files: none changed
- Schema: no drift (remains 6)
- P3 cleanup: not included
- Runtime change is identity/bookkeeping only (`cetech-woocommerce-delivery-engine.php` version/comment) plus tests/verifier/docs already reviewed on PR #27

## Final canonical package
Built from exact protected-master commit `78594ad8962868683726373f58f4a8b1b48e4d0e` in an isolated worktree on local branch `rc12-package-source` (not pushed).

Command:

`powershell -NoProfile -ExecutionPolicy Bypass -File scripts/build-v1-rc-package.ps1 -Version 1.0.0-rc.12 -ZipFileName cetech-woocommerce-delivery-engine-1.0.0-rc.12.zip`

- Filename: `cetech-woocommerce-delivery-engine-1.0.0-rc.12.zip`
- Exact source commit: `78594ad8962868683726373f58f4a8b1b48e4d0e`
- ZIP bytes: `1,767,204`
- SHA-256: `46508c566b505ac470ae94d2829de068e4ff1b53bb4c22e31201e038fb8e03d1`
- Canonical path: `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-rc12-release-source\dist\cetech-woocommerce-delivery-engine-1.0.0-rc.12.zip`

Do **not** reuse the provisional evidence ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.12-premerge.zip` (`1,770,445` bytes, SHA-256 `12dcc24a6918bdc4dc2809384e4a1d19f03e7335c2c828dd81ed88f2d26ec891`).

Once tag/release is authorized, this exact ZIP is immutable.

## Package verification
Production-package verifier (`scripts/verify-production-package-autoload.php` against extracted root `cetech-woocommerce-delivery-engine`): **PASS**.

Observed:
- extracted root is `cetech-woocommerce-delivery-engine`
- Composer autoload works
- schema target 6
- version `1.0.0-rc.12`
- no PHPUnit in production vendor
- no `tests/`, `node_modules/`, phpunit, or vitest trees in the extract

Packaged PHP lint: `498 files / 0 failures`.

## Isolated WordPress qualification
Lab: `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-rc12-qualify` / Compose project `cetech-rc12-qual`.

- Images: `wordpress:php8.2-apache` (PHP 8.2.33), `wordpress:cli-php8.2`, `mariadb:11.4`
- WooCommerce: `11.0.1` from the existing geo.16 harness ZIP
- Network: dedicated `10.123.12.0/24` (did not attach to FLAIROC/training/production/POS)
- Lab torn down with volumes after `ALL SMOKES PASSED`

Did not use FLAIROC, training, production, or POS.

### Clean install
`clean version=1.0.0-rc.12 schema=6 tables=all_tables_ok geo=schema6_tables_ok`

Activation succeeded. No PHP fatal. Schema-6 geography/coverage tables present.

### Mandatory RC.11 → RC.12 upgrade / retention
Exact immutable RC.11 ZIP first (SHA-256 `97423a95273f6148ee855d1fb8a66c6c66e47aa20cb2c5868ecf2f84b2a9a521`).

Before:
```
rc11 version=1.0.0-rc.11 schema=5 tables=all_tables_ok geo=schema6_tables_missing
sentinel=1
zone=1
rule=1
option=keep_me
```

Seeded: offer `RC12_UPGRADE_SENTINEL`, zone `RC12_ZONE`, country rule `GH`, option `cetech_rc12_upgrade_sentinel=keep_me`.

After normal plugin replacement with the final RC.12 ZIP:
```
upgrade version=1.0.0-rc.12 schema=6 tables=all_tables_ok geo=schema6_tables_ok sentinel=1 zone=1 rule=1 option=keep_me coverage_groups=1
```

Schema-6 coverage upgrade state:
```
{"status":"completed","pass_kind":"initial","failed_zone_id":0,"countries":["GH"],"bootstrapped":18,"review_required":0,"converted":1,"warnings":[]}
```

Sentinel row, retained option, zone, and GH business rule survived. Country-rule → coverage-group conversion completed (`converted: 1`, `coverage_groups=1`). No data-destructive shortcut.

### Optional geo.16 → RC.12 identity smoke
```
geo16 version=1.0.0-dev.geo.16 schema=6 tables=all_tables_ok geo=schema6_tables_ok
geo16_upgrade version=1.0.0-rc.12 schema=6 tables=all_tables_ok geo=schema6_tables_ok sentinel=1 option=keep_geo16
```

Offer `GEO16_IDENTITY_SENTINEL` and option `cetech_rc12_geo16_sentinel=keep_geo16` survived. This is identity/lineage evidence only; the mandatory public release lineage remains RC.11 → RC.12.

## Release immutability
- `v1.0.0-rc.11` annotated tag object remains `acaae9bfc9758cdee1b3f2ec47e94848e83f87da`
- Peels to `384f564f64a2db766ae6907392e95fb366fb8533`
- Not mutated
- `v1.0.0-rc.12` exists as the published annotated tag below. Do not recreate or move it.

## Final tag
- Tag: `v1.0.0-rc.12`
- Type: unsigned annotated `tag`
- Annotated tag object: `89f34883a017b8bb66f98db345fbbae0d8dd72b0`
- Peels to: `78594ad8962868683726373f58f4a8b1b48e4d0e`
- GitHub prerelease: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/releases/tag/v1.0.0-rc.12
- Downloaded GitHub asset verified: `1,767,204` bytes, SHA-256 `46508c566b505ac470ae94d2829de068e4ff1b53bb4c22e31201e038fb8e03d1`

RC.12 release source/tag remains `78594ad8962868683726373f58f4a8b1b48e4d0e`. Later `master` may advance for documentation only and is not the RC.12 release source. Do not rebuild the ZIP for docs closeout.

Issue `#26` closeout comment: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/26#issuecomment-5751247411

## Boundaries
- RC.11 tag/package remain immutable.
- Training site `https://training.cetechbpa.com`: RC.12 installed, schema 6, training-site qualification **PASS**. That is **not** Stable-1.0 certification. Isolated Compose qualification (above) did not itself deploy to training.
- CETECH Pilot: **NOT STARTED**. This tag/prerelease is not Pilot authorization.
- FLAIROC: **NOT DEPLOYED**.
- Production: **NOT DEPLOYED**.
- No POS repository changes.
- No WPML/WCML merge.
- No WP Rocket certification expansion.
- No unrelated Stable-1.0 implementation.
- No P3 cleanup in this promotion.
- No schema 7.
- Stage 15 not started.
- Issue `#26` closed after tag + prerelease + asset verification.
- GitHub prerelease published; this is not production or Pilot authorization.

## Ownership
Sole owner / release authority: `@wbdevworld`.
