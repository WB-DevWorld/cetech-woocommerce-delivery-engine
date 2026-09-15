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

To be appended by the docs-only follow-up commit after tests and packaging. The ZIP source SHA must remain the identity/runtime commit, not the later docs evidence tip.
