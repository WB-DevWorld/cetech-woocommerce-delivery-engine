# Current Work — Qualification Surface Active

Status: CONTROL PLANE ACTIVE + SOURCE RECOVERY COMPLETE + QUALIFICATION BASELINE ASSEMBLED (NOT MERGED)

## Canonical published truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical published branch: `master`.
- Current protected `master`: `b7c9f2bff8a50eab8fcda978c27b73647e49fc74` (merge of PR #14, `docs: reconcile historical desktop delivery-engine artifacts`).
- Published version: `1.0.0-rc.9`.
- Schema target: `5`.
- RC.10: DOES NOT EXIST.
- Stage 15: NOT STARTED.
- Published remote tags: `v1.0.0-rc.2`, `v1.0.0-rc.4`, `v1.0.0-rc.5`, `v1.0.0-rc.6`, `v1.0.0-rc.9`.
- Local-only tags `v1.0.0-rc.3`, `v1.0.0-rc.7`, `v1.0.0-rc.8` remain unpublished by design.

Historical bootstrap `master` `d2ebc620762c6acd1b3a143ee120bea906c6d205` and pre-bootstrap RC.9-line `376c0896df0d85b159e8713c79aadb6c9b8a3839` are ancestors, not the current tip.

## Recovery state
- Post-RC.9 local Git/source recovery is **COMPLETE** (issue #2 / PR #9). Forensic report: `docs/recovery/POST-RC9-LOCAL-RECOVERY-REPORT.md`.
- Desktop artifact reconciliation is **COMPLETE** (PR #14). Result: **ALL DESKTOP ARTIFACTS ACCOUNTED FOR**. Report: `docs/recovery/DESKTOP-ARTIFACT-RECONCILIATION-2026-09-16.md`.
- Redundant local workspaces/worktrees were safely retired after that reconciliation. Primary clone + forensic recovery archive + active isolated QA lab remain. No further source-recovery investigation is currently required.
- Recovered refs/history remain on GitHub as provenance. They are **not** canonical `master`.

| Ref | SHA | Role |
| --- | --- | --- |
| `feat/post-rc9-customer-ux` | `d534d6a24390f39206f697308f0c4bc42919be46` | Historical integrated.2 docs/checksum tip; packaged runtime `10028a2216619f514dda3ecf7cd1cbb7d50296cc` |
| `feat/post-rc9-wpml` | `3b5b60d0d92b2edc00496d536774f8ac907cd6d2` | UNIQUE_STREAM — recovered, preserved, **not** in the core qual.1 baseline |
| `recovery/post-rc9-*` and historical RC.6/RC.7/RC.8 recovery refs | see recovery report | PRESERVE_ONLY provenance |

## Active integration/qualification state
PR **#12** / `batch/pre-rc10-qualification` is the current core qualification surface.

- Head: `be586a454cc9a03395b981ef2b07cce445ef8f10`
- Identity: `1.0.0-dev.qual.1`
- OPEN, DRAFT, **DO NOT MERGE**
- Not canonical `master`
- Not RC.10
- Provenance-preserving replay of the recovered integrated.2 runtime sequence onto the then-current protected `master` foundation (base `0f9c2f06`). Later `master` documentation (PR #14) was **not** merged into it.
- WPML remains a separate stream (`feat/post-rc9-wpml`). Do not merge WPML merely because it exists.

Do not rebase, force-push, rebuild `qual.1`, or mark PR #12 ready for review unless separately authorized.

## Remaining qualification gaps
Constructing the qualification baseline is **done**. Remaining gates (not rerun as this status sync):

- WoodMart physical / current-candidate qualification;
- WP Rocket + Redis cross-session / cache isolation;
- paid multi-destination order through actual shipment creation, Thank You, My Account, and customer email;
- WPML/WCML certification decision and licensed-dependency tests **if** WPML is included (it is not in qual.1);
- production rollout/pilot (separate human-authorized work).

## Active milestone
Physical/runtime qualification of PR #12 / `1.0.0-dev.qual.1`. Do not treat recovered branches or PR #12 as published. Do not create RC.10.

## Integration editor
WS3 / `@wbdevworld`.

## Authorized implementation
No new product feature implementation in this status-sync. No RC.10 promotion. No Stage 15. No FLAIROC/training/production mutation. No merge of PR #12.

## Central leases
- qualification identity: frozen as `1.0.0-dev.qual.1` on PR #12 until separately authorized;
- published release identity/tags: frozen at `1.0.0-rc.9`;
- schema: frozen at 5 on published `master`;
- canonical `master`: no force-push/rewrite.

## Environment authorization
- Production/FLAIROC/training: NO autonomous mutation.
- Isolated local QA lab exists and may run for qualification. No production promotion has happened.
