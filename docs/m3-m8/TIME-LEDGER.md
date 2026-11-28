# M3–M8 time ledger

Allowance: 72 hours of active work (Frank's authorization, 28 November 2026, section 2).

Rules:
- Count the lead session's wall-clock time while work is in progress. Agents running in parallel inside a block do not add hours.
- CI time counts.
- Time spent waiting for Frank or other people is not counted: answers, approvals, sign-ins, merges, staff testing. Each pause is logged with its reason.
- All times are UTC.

## Budget by phase

| Phase | Budget (h) | Used (h) | Status |
|---|---|---|---|
| 0 Orientation | 3 | 0.55 | done 14:30 |
| 1 Base | 6 | 0 | in progress (#205 CI running) |
| 2 Wave A | 15 | 0 | not started |
| 3 Wave B | 18 | 0 | not started |
| 4 Wave C | 12 | 0 | not started |
| 5 Final | 8 | 0 | not started |
| Reserve | 10 | 0 | — |
| **Total** | **72** | — | |

A phase that runs more than 25% over its budget stops work: report it and propose what to move or drop.

## Work blocks

| # | Start (UTC) | Stop (UTC) | Phase | What was done | Block (h) | Running total (h) |
|---|---|---|---|---|---|---|
| 1 | 2026-11-28 13:57 | 14:30 | 0 | Section 11 complete. Auth and heads confirmed. CI trigger gap fixed by #203 and #204. Batch branch and worktrees created. Four research agents covered the Commerce Bridge, registry, code and design inputs. Staging read-only check. Interface note, lane plan, registry proposals, notice draft, intake §1–3, `CURRENT-WORK` leases. Phase 1 base merge and dev.2 bump in #205, with local gates equal to `master`'s baseline | 0.55 | 0.55 |
| 2 | 2026-11-28 14:30 | open | 1 + 2 | Phase 1 (merge #205, package, staging) in parallel with the Wave A contract pull request | — | — |

## Pauses (not counted)

| Start (UTC) | Stop (UTC) | Waiting for | Reason |
|---|---|---|---|
