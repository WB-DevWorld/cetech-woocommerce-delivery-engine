# COR-006 claim fence

Date: 2026-10-05. This follows the COR-004 checkpoint on `fix/cor-004-006-catalog-worker`. It fences existing `claim_token` and `claimed_at` columns. It does not add a ledger, outbox, revision column, or schema, and it does not claim the full COR-006 contract.

## COR-001 dependency for this batch

Job creation and later admin actions still use the COR-001 capability recheck from the PR #51 candidate. This batch does not add a creator-only, foreign-job, CLI, private, or background-worker grant. A worker may update a job only while its submitted token still matches the stored token, or while both are empty.

## Behavior that was proved

- One job claim is a single conditional update. Two operating-system processes, using two database connections, both waited until each had connected and then claimed the same job. Exactly one process received the claim. The stored token was that winner's token.
- A second unexpired token cannot save over the owner. The losing save reports `Stale bulk claim.` Counters and the owner token stay as they were.
- An expired item claim can be renewed by one claimant. The previous token cannot write the outcome afterward. A pending item has one claimant. Saving a non-claimed outcome clears `claim_token` and `claimed_at` only after the caller's token matches, so the completed row does not stay locked and a stale outcome cannot land.
- Inserting the same job, target, and external key twice keeps one row and returns that durable row. A failed insert that does not leave a row reports `Bulk item write failed.`
- An unchanged job save is not reported as a failure. A required counter change that the database rejects reports `Bulk job write failed.` and leaves the previous counter in place. The rejection was a disposable-database trigger on `cetech_cor006_worker`, not an application schema change.
- A failed claim or item-claim statement reports `Bulk job write failed.` or `Bulk item write failed.` A claim that loses the conditional update returns no job or skips that item.

## Red then green

PHP 8.5.0. The first Bulk unit run after the fence was 4 errors / 152 tests: explicit-id fixtures were treated as missing rows, and the existing simulated crash test wrote a new token onto an unclaimed item. The repository again accepts a first save of an explicit id, and that test now plants the expired claim through the same-token claim path. The Bulk unit suite is then 152 tests / 871 assertions, OK, with 2 existing deprecations. Physical MariaDB proof uses `phpunit.cor006-sql.xml` against database `cetech_cor006_worker` on `127.0.0.1:33079`: 3 tests / 33 assertions, OK. The group `cor006-real-db` is excluded from the default suite and is not part of the CI real-database file, whose five tests stay unchanged. The test database adapter reports a failed statement as a false result, which is the WordPress failure signal those fences require. The in-process database double also applies `IS NULL` and timestamp comparisons, so a booted preview can still record `background_queue_unavailable` when no scheduler is present.

## Boundaries that stay open

P07 is not implemented and was not executed. There is no reviewed joined-or-reconcilable durable recovery boundary for a crash between a catalog mutation and its outcome row. That needs an owner decision before any crash-recovery test. The existing in-memory crash test remains a simulated status recovery, not that proof.

P08 is not implemented and was not executed. There is no approved backlog or liveness limit, so Action Scheduler qualification is not claimed. The count of prepared tests and the existing pending-action fixture are not that limit.

In-memory fencing checks the same token rules in one process. It is not physical concurrency proof. The two-process result is the concurrency proof, and it covers one job claim plus the separate stale-save and item-claim processes.
