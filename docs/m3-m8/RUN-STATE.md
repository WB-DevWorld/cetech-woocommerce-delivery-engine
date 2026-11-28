# M3–M8 run state

Read this first in every session, then continue from **Next action**.

Authority: Frank's authorization and starter prompt of 28 November 2026 (kept outside the repository, in Frank's `Claude outputs/` folder as `claude-code-starter-prompt-m3-m8.md`). It is summarised in `CURRENT-WORK.md` (section "M3–M8 batch"). Scope: M3, M4, M7 and M8 to production readiness on staging. M5 and M6 are out of scope.

Last updated: 2026-11-28 14:05 UTC.

## Phase

**Phase 0, Orientation** (budget 3 h). Started 2026-11-28 13:57 UTC.

| Section 11 step | State |
|---|---|
| 1. Auth, fetch, confirm heads | Done. `gh` is signed in as `wbdevworld` with `repo` and `workflow` scopes. `master` = `08422f06` (identity `1.0.0-m3.0-dev.1`). `fix/m2-2026-10` = `ef02a220` (`1.0.0-m2.6`). `8ed5809`, `221164d` and `89c693f` are on the M2 line and not on `master`. `fix/m2-6-records` (`b93452bd`) is not merged. The latest tag is `v1.0.0-m2.5` (`8af9dcd1`). Staging and training facts are re-checked at phase 1. |
| 2. CI on pull requests into `batch/**` | Was missing: `pull_request` covered `master` only. Fixed by PR #203 (to `master`, for Frank) and PR #204 (the same commit `ab40389`, into the batch branch). |
| 3. Worktree, batch branch, state files, leases | Batch branch created and pushed. These files written. Leases: see `CURRENT-WORK.md`. |
| 4. Read sources and the Commerce Bridge; interface note; lane plan | Done. See `COMMERCE-BRIDGE-INTERFACE.md`, `LANE-PLAN.md` (with the phase 0 findings in §1), `REGISTRY-PROPOSALS.md` and `NOTICE-WS1-WS2-DRAFT.md`. The M3 intake sections 1 to 3 are filled (`docs/M3-INTAKE-AND-AUTHORISATION-DRAFT-2026-10-30.md`). The private Bridge reading notes are in Frank's `Claude outputs/m3-m8/`. |
| 5. Phase 0 report with the section 5 questions | Sent in chat 2026-11-28. |

## Branches and worktrees

| Branch | Worktree (beside Frank's folder) | Base | Purpose |
|---|---|---|---|
| `batch/m3-m8-2026-11` | `../cetech-m3m8-lead` | `master` `08422f06` | Integration branch, owned by the lead |
| `fix/ci-pull-requests-into-batch` | `../cetech-m3m8-ci` | `master` `08422f06` | CI trigger (#203, #204) |
| `fix/m3m8-base-merge-m2-6` | `../cetech-m3m8-base` | `master` `08422f06` | Phase 1 base: merge of `1.0.0-m2.6` and version `1.0.0-m3.0-dev.2` (#205) |

Frank's own folder (`cetech-woocommerce-delivery-engine`, on `master`, holding his untracked material) is never switched, reset, cleaned or stashed.

## Lanes

Planned in `docs/m3-m8/LANE-PLAN.md`. Wave A started on 2026-11-28 at 14:50 UTC from contract head `feb90a3` (PR #206). The briefs are in the lead's scratchpad (`lanes/COMMON-BRIEF.md` and the per-lane files).

| Lane | Branch | Worktree | State |
|---|---|---|---|
| L2a promise engine, provider, freeze | `fix/m3m8-l2a-promise-engine` | `../cetech-m3m8-l2a` | working |
| L1 promise dates on customer surfaces | `fix/m3m8-l1-promise-surfaces` | `../cetech-m3m8-l1` | working |
| L2b staff promise-rules screen | `fix/m3m8-l2b-promise-rules-screen` | `../cetech-m3m8-l2b` | working |
| L3 emergency pause | `fix/m3m8-l3-emergency-pause` | `../cetech-m3m8-l3` | working |
| L5 shortcodes, blocks, shopping destination | `fix/m3m8-l5-embeds` | `../cetech-m3m8-l5` | working |
| L7 read-only REST API | `fix/m3m8-l7-rest-read` | `../cetech-m3m8-l7` | working |
| L9 verifier | none (review comments) | none | reviewed #204 and #205 (PASS); reviewing #206 |

The lead splits L2 into L2a (engine) and L2b (screen), so the screen does not wait for the engine.

## Open pull requests from this batch

| PR | Base | Head | Purpose | CI | Verifier |
|---|---|---|---|---|---|
| #203 | `master` | `fix/ci-pull-requests-into-batch` | `pull_request` trigger for `batch/**` | running | n/a (Frank merges) |
| #204 | `batch/m3-m8-2026-11` | `fix/ci-pull-requests-into-batch` | the same commit, into the batch branch | green | PASS. **Merged** 2026-11-28 14:54 UTC as `3cf94b66` |
| #205 | `batch/m3-m8-2026-11` | `fix/m3m8-base-merge-m2-6` | Phase 1 base: `1.0.0-m2.6` merged, `1.0.0-m3.0-dev.2` | WordPress job running | PASS (non-blocking: `BASE-MERGE-M2-6.md` does not mention the dev.2 bump, which is recorded here instead) |
| #206 | `batch/m3-m8-2026-11` | `fix/m3m8-wave-a-contract` | Wave A contract: module seam, four switches (off), promise display contract. Stacked on #205 | running | in review |

## Hours

See `docs/m3-m8/TIME-LEDGER.md`. Block 1 open since 13:57 UTC.

## Next action

1. Verifier review of #204 and #205. Merge each when CI is green.
2. The lead's Wave A contract pull request (`LANE-PLAN.md` §3), stacked on #205. Then start lanes L1, L2, L3, L5, L7 and L9.
3. Phase 1: base merge of `fix/m2-2026-10` into the batch branch (keeping the `master` identity) by pull request, dev package `1.0.0-m3.0-dev.N`, staging backup, install, schema 6 → 12, re-run of the M2 ledger, and a fresh Cash on Delivery order to check Needs Attention.

## Parked questions for Frank

The section 5 inputs, asked once at the end of phase 0:

1. Real promise rules and their owner (cut-off, working days, holidays, lead time per area and fulfilment profile, wording).
2. Confirm or change defaults 3 (date-range wording), 4 (pause rules) and 6 (freeze at order placement).
3. Whether the staging theme's own calendar delivery dates on cart and order pages are to be switched off.
4. Brand name, and how the plugin will be distributed and updated.
5. Staff testers and dates for each gate.
6. Paystack test mode on staging, and browser sign-ins when needed.
