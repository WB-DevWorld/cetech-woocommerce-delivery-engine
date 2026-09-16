# Pre-RC.10 Qualification Baseline

**Document status:** WS3 INTEG-01 assembly record  
**Identity:** `1.0.0-dev.qual.1`  
**Branch:** `batch/pre-rc10-qualification`  
**Issue:** [#11](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/11)  
**Classification:** NON-RELEASE, UNTAGGED, PRE-RC.10, QUALIFICATION-ONLY  
**RC.10:** DOES NOT EXIST  

This file records the reconciled qualification baseline assembled from protected organization `master` plus the exact recovered 11-commit integrated.2 runtime delta. It is not a release record and does not authorize merging this branch into `master`.

## Baseline origin

```text
protected master at assembly start:
0f9c2f06f9da0e15840fe27c8b28f7d146a5dd8d
```

Published plugin release identity remains `1.0.0-rc.9`. Schema remains `5`. Stage 15 is not started.

## Historical source

```text
RC.9 peeled base:
e6bc7fba16d9d7b96682f2945c518a33a9a16cd5
integrated.2 runtime:
10028a2216619f514dda3ecf7cd1cbb7d50296cc
historical checksum follow-up excluded:
d534d6a24390f39206f697308f0c4bc42919be46
```

Historical immutable integrated.2 ZIP SHA-256:

```text
a16a7840f32c8aa95fde3d4ef25c97c39ec995fe1d4ee6036fbb03b8d1a1a9c9
```

Do not rebuild different bytes under `1.0.0-dev.integrated.2`. The excluded `d534d6a...` commit is bookkeeping for that old immutable package and was not replayed.

## Replay table

Provenance-preserving cherry-picks (`git cherry-pick -x`) in exact historical order:

| # | Original SHA | Replayed SHA | Conflict | Notes |
| - | ------------ | ------------ | -------- | ----- |
| 1 | `f6b5c125b71e6fb9d5ce586cc089707c0efb75bb` | `ed6b68a1ff27ae6a4875a9b2b9cad4321aea65c3` | yes | cart live-config reconciliation; `docs/AI-HANDOFF.md` only |
| 2 | `729f4e8796dba83c044f49992cfac33a7f1dff72` | `b31ecc2948469228d590f89def1761b16ce7ec24` | yes | Blocks line snapshots; `docs/AI-HANDOFF.md` only |
| 3 | `78d0808ad5d93064eef056f14553ac516553d3da` | `d1b597c0a0ab579f174e65a30e25f3fdda7ddbca` | yes | per-item customer-context foundation; `docs/AI-HANDOFF.md` only |
| 4 | `72b979cc8b1fcc716d8ecfd4a4e0b97a80acb6a3` | `4b3770b17df67cc166ac6dd17a196629a7800a6f` | yes | Classic per-item destination UX; `docs/AI-HANDOFF.md` only |
| 5 | `2ae5a957f254d903c4270c54ac92790b6ad311e4` | `918dcabd7047a7648a4b146b5330bf856d0ac178` | yes | Classic multi-destination completion; `docs/AI-HANDOFF.md` only |
| 6 | `7cd880b36b4f8fbbf5269cc83a04a037f6c8cf1c` | `7cd3d3eb1f368866bf62753dab21f1cf884e0941` | yes | Blocks per-item UX; `docs/AI-HANDOFF.md` only |
| 7 | `72cb7ddfcb5ed85765ed167831736ba04a3cdf89` | `839c74405401d712c2faeb392c81cf5e82e422cc` | yes | Blocks DOM/pickup hardening; `docs/AI-HANDOFF.md` only |
| 8 | `47c090bcd88387b0f23fcc0f310d3ee4c414ce8e` | `5919fb49d3c0a79ea5b64f1dbad15e4ddc72d9b3` | yes | WCFM isolation; `docs/AI-HANDOFF.md` only |
| 9 | `ec31d6d35ba82edb954f2936b594ecd907c359ab` | `e0c9173f2e065ca35d3bb3981b6bdf0d71959b67` | no | packaged-source capability-matrix verification |
| 10 | `ac2bc94056ebc73f1c6f8ab4a0434f4e68ce7300` | `458e5043f7a374fda5e4c03e75dfcbaca166e0b9` | no | restricted-WCFM-vendor security hardening |
| 11 | `10028a2216619f514dda3ecf7cd1cbb7d50296cc` | `38646a4fee62e8abb7cbb2324bfb9fe22ea160c2` | yes | integrated.2 customer storefront cleanup; `docs/AI-HANDOFF.md` only |

Replay HEAD after commit 11 (before this identity commit): `38646a4fee62e8abb7cbb2324bfb9fe22ea160c2`.

`d534d6a24390f39206f697308f0c4bc42919be46` does not appear in `origin/master..HEAD`.

## Conflict resolution

No runtime conflicts.

Every conflict was confined to `docs/AI-HANDOFF.md`. Resolution preserved:

- current WB-DevWorld repository / control-plane / training truth;
- RC.9 as the published release;
- training in-place install not completed from Cursor;
- post-RC.9 cart-state, Blocks line snapshots, per-item destinations, WCFM isolation, and integrated.2 storefront cleanup as implemented-but-unreleased candidate history;
- WPML explicitly not merged;
- Stage 15 not started;
- FLAIROC not modified;
- `batch/pre-rc10-qualification` as the qualification assembly surface rather than `feat/post-rc9-customer-ux`.

Incoming historical table rows describing candidate functionality were kept. The `Next stage` row was synthesized so it does not restore stale claims that a recovered feature branch is canonical.

## Range-diff

Command:

```bash
git range-diff \
  e6bc7fba16d9d7b96682f2945c518a33a9a16cd5..10028a2216619f514dda3ecf7cd1cbb7d50296cc \
  0f9c2f06f9da0e15840fe27c8b28f7d146a5dd8d..38646a4fee62e8abb7cbb2324bfb9fe22ea160c2
```

Result: 11-commit replay matching the historical sequence. Differences are:

- `-x` provenance lines (`cherry picked from commit ...`);
- intentional `docs/AI-HANDOFF.md` documentation-context resolution;
- leftover `# Conflicts:` comments in the commit messages of replayed commits 1 and 2 (message-only; no source effect).

Runtime patch comparison excluding `docs/AI-HANDOFF.md` is byte-identical between the historical range and the replayed range (670528 bytes).

`git diff --check origin/master..38646a4` reports inherited trailing whitespace in historical markdown and a few historical PHP lines from the recovered commits. CI does not gate on `git diff --check`.

## Preserved master-only safeguards

These remained present after the 11-commit replay and must remain:

- team control plane (`AGENTS.md`, `CURRENT-WORK.md`, `OWNERSHIP.md`, `docs/STATUS_CURRENT.md`, workstream files, `.github/workflows`);
- CI (`Runtime PHP 8.1`, `PHP / PHPUnit 8.2`, `JavaScript / Vitest`, `Control Plane`);
- repository/release safeguards;
- recovery documentation;
- staff-training documentation updates;
- proprietary public-source safeguards;
- PHP 8.1 compatibility repair in `src/Application/Runtime/EcrToRuntimeConfigurationAdapter.php` (blob `10e47f82efac9e2ce5890a4b19a915d08452597d`, identical to protected master).

## Qualification identity

```text
1.0.0-dev.qual.1
```

This identity exists because the reconciled tree includes later protected-master changes, including the PHP 8.1 repair, and therefore cannot ship under immutable `1.0.0-dev.integrated.2`.

It is not RC.10, not stable `1.0.0`, and not a replacement for historical integrated.2.

## WPML status

```text
NOT INCLUDED
```

WPML remains:

```text
feat/post-rc9-wpml @ 3b5b60d0d92b2edc00496d536774f8ac907cd6d2
```

as a separate overlay/certification stream.

## Prohibitions

```text
No RC.10 created.
No tags moved.
No master mutation.
No FLAIROC mutation.
No training-site mutation.
No production mutation.
```

## Source and package evidence

This section is a docs-only follow-up. It does **not** change packaged runtime bytes. Do not rebuild the ZIP after this commit.

### Packaged runtime/source SHA

```text
c0000ab97aff8f2829ed9b173e0e6c7432b4eb9e
```

That SHA is the clean tree from which `1.0.0-dev.qual.1` was packaged. It is the identity commit `7da43bfbbfd05b33832ebc9c8396357a0b511f32` plus one bounded mechanical packaging-identity repair (`integ: recognize 1.0.0-dev.qual.1 as a schema-5 packaging identity`). The historical 11-commit replay was not squashed.

Identity commit (plugin header / `CETECH_DE_VERSION` / readme Stable tag / initial manifest):

```text
7da43bfbbfd05b33832ebc9c8396357a0b511f32
```

### Composer

- `composer validate --no-check-publish`: `./composer.json is valid` (exit 0).
- `composer install --no-dev`: exit 0 (nothing to install; production autoload generated).
- `composer install` including require-dev: **failed in this workstation environment** (Composer curl error 60 / Avast local-issuer intercept of GitHub dist downloads). Development vendor was therefore copied from the sibling Delivery Engine tree that already had PHPUnit `10.5.64` matching this lockfile, then `composer dump-autoload` was run here. PHPUnit below was executed from that local vendor tree.

### PHP lint

Local CLI is **PHP 8.5.0**, not PHP 8.1. Do not treat these counts as a PHP 8.1 proof. GitHub `Runtime PHP 8.1` is the 8.1 gate after push.

- Runtime lint (`cetech-woocommerce-delivery-engine.php`, `uninstall.php`, `src/`, `database/`): **428 files, 0 failures**, PHP 8.5.0.
- Full lint (all tracked/non-vendor `*.php`): **573 files, 0 failures**, PHP 8.5.0.

### PHPUnit

Command: `vendor/bin/phpunit`

```text
PHPUnit 10.5.64
Runtime: PHP 8.5.0
Tests: 994
Assertions: 5596
Deprecations: 5
Exit code: 0
```

### Vitest

`package-lock.json` matched the sibling tree; `node_modules` was copied locally rather than `npm ci` because of the same workstation TLS intercept. Command actually run: `npm run test:js` (`vitest run`).

```text
Test Files  6 passed (6)
Tests       41 passed (41)
Exit code:  0
Duration    17.60s
Vitest      v3.2.7
```

### Control Plane

```text
php scripts/verify-control-plane.php
Delivery Engine control plane: OK
Exit code: 0
```

### Diff hygiene

`git diff --check origin/master..HEAD` reports inherited trailing whitespace in historical markdown (including `docs/AI-HANDOFF.md`) and a few historical PHP lines from recovered WCFM admin-handler whitespace. CI does not gate on `git diff --check`. No new runtime whitespace was introduced to resolve conflicts.

### Secret/binary scan

Tracked source contains no `.env`, credentials, API keys, private tokens, licensed WoodMart binaries, licensed WPML/WCML plugin binaries, ZIP artifacts, `node_modules`, or development `vendor`/PHPUnit package material.

### Qualification artifact

```text
filename: cetech-woocommerce-delivery-engine-1.0.0-dev.qual.1.zip
packaged source SHA: c0000ab97aff8f2829ed9b173e0e6c7432b4eb9e
bytes: 1515275
SHA-256: c2f86650854c2e61aef6493459d2ef2f7b3fa7241e822a8da9827fcd32872a16
```

Built with:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-v1-rc-package.ps1 `
  -Version "1.0.0-dev.qual.1" `
  -ZipFileName "cetech-woocommerce-delivery-engine-1.0.0-dev.qual.1.zip"
```

No `-AllowDirty`. Tree was clean. Historical `cetech-woocommerce-delivery-engine-1.0.0-dev.integrated.2.zip` was **not** rebuilt or overwritten (still `1484938` bytes, SHA-256 `a16a7840f32c8aa95fde3d4ef25c97c39ec995fe1d4ee6036fbb03b8d1a1a9c9`).

### Package verification

Packager `verify-production-package-autoload.php`: **OK**.

Additional extracted inspection:

- ZIP root: single folder `cetech-woocommerce-delivery-engine/` (605 entries, forward slashes only).
- Plugin header Version / `CETECH_DE_VERSION` / readme Stable tag: `1.0.0-dev.qual.1`.
- Production `vendor/autoload.php` present; `vendor/phpunit` absent.
- Absent from package: `.git/`, `.github/`, `.cursor/`, `node_modules/`, `tests/`, `coverage/`, `build/`, `test-results/`, `.env`, `phpunit.xml`, `package.json`, `package-lock.json`.
- Packaged non-vendor PHP lint: **431 files, 0 failures**, PHP **8.5.0** (not PHP 8.1).

First packaging attempt from `7da43bf` failed because the production verifier still classified unknown identities as schema 4. That is a packaging-identity expectation (INTEG-01 failure class B), repaired in `c0000ab` by adding `1.0.0-dev.qual` to the existing schema-5 identity list. Product semantics were not changed.

### PHP 8.1 repair preserved

`src/Application/Runtime/EcrToRuntimeConfigurationAdapter.php` blob `10e47f82efac9e2ce5890a4b19a915d08452597d` remains identical to protected `origin/master`.
