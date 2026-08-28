# Post-RC.6 Bulk Tools — Background Execution Portability

**Status:** Implemented on `integration/post-rc6-bulk-r1` (`1.0.0-dev.bulk.8`, schema **`5`**)  
**Protected baseline:** tagged `v1.0.0-rc.6` / schema `4` (immutable)  
**Does not authorise:** RC.7, schema 6, Stage 15, FLAIROC, In Store / International ECR changes, Return/Refund, location work, packaging a ZIP

---

## 1. Current execution model (before this repair)

1. `BulkJobEngine::create_preview` / `apply` / `rollback` persist a durable job (schema 5 tables), then call `BackgroundQueueInterface::enqueue_job_tick($job_id)`.
2. `ActionSchedulerQueue` treated availability as `as_schedule_single_action` + `as_unschedule_all_actions`.
3. Enqueue used **`as_schedule_single_action(time() + delay)`** only.
4. It did **not** call `as_enqueue_async_action`.
5. It did **not** kick Action Scheduler’s async queue runner, `spawn_cron()`, or any loopback.
6. `Plugin` registers `cetech_de_bulk_job_tick` → `BulkJobWorker::tick($job_id)`.
7. `tick()` claims the job (`CLAIM_TTL` 300s), processes **one bounded batch** (default 25, ~8s budget), then requeues.
8. Duplicate ticks are blocked by `claim_job`.
9. If Action Scheduler is missing, the job fails immediately `background_queue_unavailable` and does **not** walk the catalog.
10. Admin AJAX `cetech_de_bulk_job_status` was **read-only** (poll every 5s). `BulkJobEngine::run_next_tick()` existed for tests/CLI and was not used from wp-admin.
11. UI mapped `previewing` to **“Preparing preview”** with `processed / total` and no runner explanation.

The browser was never the authoritative job store. That remains true.

---

## 2. Why the 5-minute host cron caused the observed delay

Physical evidence on training.cetechbpa.com (2026-08-28):

| Action 387 | Time | Via |
|------------|------|-----|
| created | 15:50:33 | admin request |
| started | 15:54:01 | WP Cron |
| completed | 15:54:01 | WP Cron |

`DISABLE_WP_CRON = 1`. External systemd WP-Cron was every **5 minutes**. A due Action Scheduler action only runs when that timer hits `wp-cron.php`. The one-product preview sat until ~15:54. Once a runner started, the batch finished in the same second.

The temporary training override (`OnUnitActiveSec` 5min → 1min, `AccuracySec` 30s → 10s) is **not** a product fix. Those values are **not** encoded in the plugin. A 1-minute server cron is **not** mandatory product setup.

---

## 3. What happened on each environment (before this repair)

| Environment | Behaviour |
|-------------|-----------|
| A. Normal WP-Cron + regular traffic | Usually starts soon (shutdown spawn / next page load). |
| B. Normal WP-Cron + low traffic | Can stall until some request hits WP-Cron. |
| C. `DISABLE_WP_CRON` + good external cron | Delay ≈ cron interval (observed ~3m28s at 5 minutes). |
| D. `DISABLE_WP_CRON` + slow external cron | Same, worse. |
| E. `DISABLE_WP_CRON` + missing/broken cron | Job stays Previewing/Queued. UI says “Preparing preview”. No resume. |
| F. Loopback/async restrictions | Irrelevant — async path was never used. |
| G. Action Scheduler available but queue runner delayed | Same as slow cron. Presence ≠ healthy runner. |
| H. Action Scheduler unavailable | Immediate fail-safe `background_queue_unavailable`. |

Follow-up actions appearing **Canceled** in Action Scheduler admin: ticks were not unique; `cancel_job_ticks` unschedules leftover hooks when a job becomes terminal. Harmless AS-admin noise, not catalog corruption.

---

## 4. Immediate async path

Action Scheduler’s `as_enqueue_async_action()` already exists on current WooCommerce and was **not** being called.

Scheduling `as_schedule_single_action(time())` marks work due *now*, but with `DISABLE_WP_CRON` the due action still waits for external cron. Async enqueue plus a best-effort `ActionScheduler_AsyncRequest_QueueRunner` kick is the native path that does not require a 1-minute systemd timer.

Loopback may still fail. That is expected and must not fail the durable job.

---

## 5. Smallest portable repair

Keep Action Scheduler + bounded batches. No schema 6. Browser is never authoritative. Closing the browser must not cancel or corrupt a durable job.

```text
JOB CREATED
    persist durable job
    enqueue Action Scheduler (prefer async; unique when supported)
    best-effort immediate/async kick (never unbounded process_queue)
    if the Bulk Tools job page is open:
        AJAX may advance ONE unclaimed batch
    if a runner has not advanced the job within ~12s:
        show waiting copy + Process next batch
        (this includes jobs that already finished a batch and then stalled)
    resume automatically when any runner (AS, AJAX continue, CLI) becomes available
```

Chosen WordPress-native fallback:

1. **Prefer `as_enqueue_async_action`** when present; otherwise `as_schedule_single_action(time())`.
2. **Best-effort kick** of Action Scheduler’s async request runner. Tolerate loopback failure. Never run an unbounded `process_queue()`.
3. **Bounded admin continue** from the open Bulk Tools page (AJAX poll + explicit “Process next batch”). Same `claim_job` lock as the background worker.
4. **Cancel** still persists first; an unclaimed waiting job can finalize cancel on the same admin request so Cancel does not wait for cron.
5. **CLI** `wp cetech-de bulk continue <id>` remains available and is not the normal customer requirement.

Waiting / stale are derived (`BulkJobRunnerState`), not a schema change. A claimed tick is **Processing**. After the claim is released, recent worker activity stays **Processing**. If nothing advances the job for **12 seconds**, the UI becomes **Waiting for the site’s background runner** with **Process next batch**, including after a batch already ran. After **10 minutes** the job is stale (Needs Attention).

Not chosen: processing the whole catalog in the creating HTTP request; making systemd/cPanel cron a hidden product dependency; schema 6.

---

## 6. Safety / concurrency

- One tick = one bounded batch (existing `batch_size` + time budget).
- `claim_job` prevents duplicate concurrent workers (AS + AJAX + Resume + CLI).
- Idempotent item processing and rollback fingerprint checks are unchanged.
- Refresh/close does not write job state from the browser.
- Missing cron after the administrator leaves: job stays durable; UI said they can leave; Needs Attention after **10 minutes** with no worker activity; Resume still works when they return.
- Cancel while a tick is claimed waits for that tick to finish or for claim TTL — existing behaviour.

---

## 7. After this repair (environments A–H)

| Environment | Behaviour now |
|-------------|---------------|
| A. Normal WP-Cron + regular traffic | Async enqueue + kick; AJAX continue while the job page is open. |
| B. Normal WP-Cron + low traffic | Same. After 12s without a runner: waiting copy + Process next batch. |
| C. `DISABLE_WP_CRON` + good external cron | Does not wait solely for that timer. Async/loopback kick first; AJAX continue if the page is open. |
| D. `DISABLE_WP_CRON` + slow external cron | Same. Waiting + resume if kick/loopback does not start a worker. |
| E. `DISABLE_WP_CRON` + missing/broken cron | Job stays durable (not silently failed). Waiting copy + Process next batch. Needs Attention after 10 minutes. |
| F. Loopback/async restrictions | Kick failure is ignored. Durable job remains. AJAX continue / later runner. |
| G. Action Scheduler available but runner delayed | Presence ≠ healthy. Waiting + resume; System Status `not_running` when stale. |
| H. Action Scheduler unavailable | Immediate fail-safe `background_queue_unavailable`. Catalog not walked. |

Public Bulk Tools copy uses Queued / Starting background work / Processing / Waiting for the site’s background runner / Paused / Completed. Action Scheduler jargon stays off the ordinary job page (System Status / Technical details only).

---

## 8. Tests required (this repair)

| # | Requirement | Evidence |
|---|-------------|----------|
| 1 | Queue available + immediate worker | `ActionSchedulerQueueTest::test_prefers_async_enqueue_and_kicks_without_draining_the_queue`; Plugin-boot isolated test enqueues async via live `WpActionSchedulerGateway` |
| 2 | Delayed scheduler | `BulkJobEngineTest::test_delayed_scheduler_does_not_start_until_continue` |
| 3 | Scheduler never runs | `test_scheduler_never_running_leaves_durable_preview_unfailed` |
| 4 | Action Scheduler unavailable | `test_unavailable_queue_fails_safe_without_mutating`; Plugin-boot integration fail-safe through `WpdbBulkJobRepository` |
| 5 | Duplicate worker/tick | `test_continue_is_blocked_while_another_worker_holds_the_claim` |
| 6 | Browser/manual resume | `continue_job` + AJAX `advance=1` + `ACTION_CONTINUE` |
| 7 | Close/reopen Bulk Tools | `test_reopening_bulk_tools_does_not_cancel_and_resume_is_idempotent` |
| 8–10 | Preview / Apply / rollback resume | continue + `test_apply_resume_does_not_duplicate_mutations` + `test_rollback_resume_does_not_duplicate_restore` |
| 11–12 | Cancel while waiting / during processing | `test_cancel_while_waiting_does_not_require_a_later_runner`; `test_cancel_during_processing_keeps_counters_and_does_not_reverse` |
| 13–15 | No duplicate mutations / rollback / lost counters | apply/rollback resume + `test_preview_continue_does_not_lose_counters` |
| 16 | No unbounded request | `test_continue_never_processes_an_unbounded_request` |

UI/health: `BulkJobRunnerStateTest` (including a started job that then stalls), `BulkQueueHealthTest`, `BulkToolsUiConsistencyTest`, JS poll waiting/resume.

Disposable WordPress: `tests/Integration/BulkBackgroundPortabilityIntegrationTest.php` boots the real `Plugin` against `LifecycleHarness` (WP/wpdb doubles, schema 5 tables). Environment H is the default harness (no AS functions). Environment A/C enqueue is a **process-isolated** boot with Action Scheduler function doubles so the live gateway is used without marking AS available for the rest of the suite.

There is **no** docker-compose / wp-env live WooCommerce Action Scheduler runner in this repository. A real host runner still depends on loopback/cron and is owner physical QA. That is not a 1-minute cron requirement.

**Tests run (2026-08-28, pre-package source `ed57664`):** PHPUnit **735 / 4157 OK** (5 pre-existing deprecations); focused Bulk + integration **108 / 510 OK**; JS **22/22**; PHP lint on 29 changed files **0 errors**. Packaged as `1.0.0-dev.bulk.8` — see `docs/POST-RC6-BULK-BACKGROUND-PORTABILITY-QA.md`. **No physical PASS.**

---

## 9. Training server evidence (record only)

Action 387: created `2026-08-28 15:50:33`; started `2026-08-28 15:54:01` via WP Cron; completed `2026-08-28 15:54:01` via WP Cron.

Temporary training systemd override is operational QA relief only. Do not document it as product setup.

---

## 10. Package identity

Packaging record: `docs/POST-RC6-BULK-BACKGROUND-PORTABILITY-QA.md`.

| Item | Value |
|------|--------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.bulk.8.zip` |
| Source | `ed57664e61087aca09eda58b904b9edf0e78aac2` |
| Schema | `5` |
| Bytes | `1240853` |
| SHA-256 | `d8db4669e7a85ba178b9d8b7b6ab6a22e015562fe81cdc8e15262df55b263ea1` |

Historical `1.0.0-dev.bulk.7` remains immutable. **No physical PASS.** Cursor must not install the ZIP.
