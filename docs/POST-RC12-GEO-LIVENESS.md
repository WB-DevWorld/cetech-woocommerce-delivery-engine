# POST-RC.12 — Ghana Location Pack liveness (Issue #33)

**Candidate identity:** `1.0.0-dev.geo-live.1`  
**Schema:** `6` (unchanged; no schema 7)  
**Branch:** `fix/geography-pack-liveness`  
**Base:** protected `master` `83effbf081e54b47ef088673c8ac18217ab9dfda`  
**Runtime / package-source SHA:** `afb3b4bee9a09b235b639a227564a0b0b3166cd5`  
**PR:** https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/34  
**Not RC.13. Not deployed.**

## Package (dev qualification, after CI)

- Source SHA: `afb3b4bee9a09b235b639a227564a0b0b3166cd5`
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-live.1.zip`
- Bytes: `1,783,293`
- SHA-256: `6281f1b3f7d080ac9bb528fc1a117be909ac1ae05c052d6fe704cc053192ccbf`
- Built from a clean committed tree after GitHub CI SUCCESS on PR #34. Do not treat this ZIP as RC.13 or as a replacement for RC.12.

## Training forensics (read-only; captured before any further mutation)

Owner clicked **Continue / retry** exactly once after the original stall. This investigation did not click it again, did not run safe reconciliation, and did not edit geography rows.

Original stall (owner-reported):

| Field | Value |
|---|---|
| status | `importing` |
| import_cursor | `321972` |
| updated_at | `2026-09-20 19:50:49` |
| processed / imported / skipped | `3134` / `4` / `3130` |
| target_generation | `3` |
| pending `cetech_de_geography_pack_tick` | none |
| last geography tick | COMPLETE |

Post-manual-retry capture (training, after the one owner click; no further retry):

| Field | Value |
|---|---|
| pack id | `1` |
| country_code | `GH` |
| status | `importing` |
| import_cursor | `388306` |
| processed / imported / skipped | `3764` / `5` / `3759` |
| phase | `admin1` |
| target_generation | `3` |
| target_token | `geonames:GH:1:9db333869221df3d` |
| checksum | `c649a65fab3b4027b6246022f4f65b9f658d0ac04119ef9d3bd338eaac39809a` |
| source file | exists, readable, `2,464,452` bytes, checksum matches |
| lease_owner / lease_role | empty |
| lease_acquired_at / lease_expires_at | `0` / `0` |
| owner-click worker | Action Scheduler action **1889** COMPLETE (created `21:25:10`, started `21:25:49`, completed `21:25:50` via WP-Cron) |
| canceled churn in the same minute | **1886–1888** |
| pending / in-progress tick after 1889 | **none** |

Delta caused by the owner's one click: processed **+630**, imported **+1**, cursor **321972 → 388306**.

## Unique-running-action hypothesis: **PROVEN**

Action Scheduler 3.9.2 `ActionScheduler_DBStore::build_where_clause_for_insert()` unique branch matches:

- `status IN (pending, in-progress)`
- same `hook`
- same `group_id`

Arguments are **not** part of uniqueness. SQLite reproduction of that INSERT … WHERE NOT EXISTS query creates **no** successor while the current row is `in-progress`. `unique=false` does create a pending successor that survives completion.

`GeographyPackService::run_tick()` called `ActionSchedulerReadiness::enqueue_unique_async()`, which previously:

1. `as_unschedule_all_actions($hook, null, $group)` (pending only — the running tick was not canceled; this is the 1886–1888 cancel storm);
2. `as_enqueue_async_action($hook, $args, $group, true)`.

From inside running action **1889**, unique=true therefore returned **1889 itself**. 1889 then completed. The pack stayed `importing` with no pending continuation.

Manual Continue / retry works because it runs **outside** a tick: unschedule + unique enqueue creates a **pending** action (1889) that WP-Cron executes once. The in-tick enqueue cannot leave a successor under unique=true.

## Prevention

`enqueue_unique_async()` no longer unschedules as part of enqueue and no longer uses `unique=true`. It:

- reuses an existing **pending** continuation for the same hook/group;
- otherwise enqueues with `unique=false` so the in-progress action cannot suppress the successor;
- caps pending actions for that hook/group at **one**.

## Recovery

`GeographyPackService::ensure_import_liveness()` re-arms an `importing` or orphaned `pending` pack when:

- the pack lease is empty or expired; and
- no pending and no in-progress tick/download exists.

Recovery reuses the persisted source, checksum, generation token, and cursor. It does not mint a new dataset generation.

Liveness is registered on `action_scheduler_init`, `action_scheduler_after_execute`, `action_scheduler_failed_execution`, and a bounded `cetech_de_geography_pack_liveness` successor while unfinished packs remain.

## Deduplication / concurrency

- at most one pending continuation per pack group;
- pack lease still fences concurrent import mutation;
- generation token fence still rejects stale workers;
- Ready / Failed packs schedule no automatic tick successor;
- `tick()` returns immediately for Ready/Failed (`reason=terminal`) and does not import a Failed pack;
- Failed packs do not auto-resume (retry remains the contract);
- when WordPress uploads are unavailable, `store_generation_file()` keeps the provided readable source instead of failing the pack (unit tests have no `wp_upload_dir`); copy failure still fails the target;
- official download still unschedules ticks then enqueues the download hook.

## Out of scope

Issue #31 and Issue #32 are untouched. Training/FLAIROC/production/POS were not mutated. RC.12 was not retagged. RC.13 was not created.
