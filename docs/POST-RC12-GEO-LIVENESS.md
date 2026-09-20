# POST-RC.12 — Ghana Location Pack liveness (Issue #33)

**Current candidate identity:** `1.0.0-dev.geo-live.2`  
**Schema:** `6` (unchanged; no schema 7)  
**Branch:** `fix/geography-pack-liveness`  
**Base:** protected `master` `83effbf081e54b47ef088673c8ac18217ab9dfda`  
**Runtime / package-source SHA:** `cd5cdfbbebfa243feb5f0bf66121edee55b16e87`  
**PR:** https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/34  
**Not RC.13. Not deployed. Not merged.**

## Frozen geo-live.1 package (historical evidence — do not overwrite)

geo-live.1 is **not** authorized for training deployment or merge. The direct tick-successor fix is accepted; the first watchdog could self-chain as immediate async actions.

- Runtime / package-source SHA: `afb3b4bee9a09b235b639a227564a0b0b3166cd5`
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-live.1.zip`
- Bytes: `1,783,293`
- SHA-256: `6281f1b3f7d080ac9bb528fc1a117be909ac1ae05c052d6fe704cc053192ccbf`

Do not rebuild or mutate this ZIP or its checksum record.

## geo-live.2 package (dev qualification, after CI)

Built from a clean committed tree after GitHub CI SUCCESS on runtime/package-source `cd5cdfbbebfa243feb5f0bf66121edee55b16e87`. Do not treat this ZIP as RC.13 or as a replacement for RC.12 or geo-live.1.

- Source SHA: `cd5cdfbbebfa243feb5f0bf66121edee55b16e87`
- Filename: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-live.2.zip`
- Bytes: `1,786,463`
- SHA-256: `c5ea75fd407aa32a9e443a3f883e3036785cc776dcf715a040026087fdf8c149`
- Production-package verifier: PASS (staged and extracted)
- Packaged PHP lint: `499 files / 0 failures`
- Not deployed to training

## Training forensics (read-only; captured before any further mutation)

Training currently runs **`1.0.0-dev.checkout-mdest.1`**, schema **6**. geo-live.1 was never deployed there. This investigation did not click Continue / retry, did not run safe reconciliation, and did not edit geography rows.

Owner clicked **Continue / retry** exactly once (Action Scheduler action **1889**). That remains owner-reported truth. Later HTTP POSTs to Location Packs are recorded below; they are **not** rewritten as additional owner Continue / retry clicks because POST bodies were not captured.

### Original stall

| Field | Value |
|---|---|
| status | `importing` |
| import_cursor | `321972` |
| updated_at | `2026-09-20 19:50:49` |
| processed / imported / skipped | `3134` / `4` / `3130` |
| target_generation | `3` |
| pending `cetech_de_geography_pack_tick` | none |
| last geography tick | COMPLETE |

### After the owner's one Continue / retry (action 1889)

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
| owner-click worker | action **1889** COMPLETE (created `21:25:10`, started `21:25:49`, completed `21:25:50` via WP-Cron) |
| pending / in-progress tick after 1889 | **none** |

Delta from that one click: processed **+630**, imported **+1**, cursor **321972 → 388306**.

### Latest owner UI, then later captured DB state

Owner screenshot after 1889 showed processed **4126** / imported **5**. That is **not** proof Issue #33 is resolved, and the pack was **not** frozen at 3764.

Read-only refresh on 2026-09-20 (plugin still `1.0.0-dev.checkout-mdest.1`):

| Field | Value |
|---|---|
| pack id | `1` |
| country_code | `GH` |
| provider / dataset | `geonames` / `gazetteer` |
| status | `importing` |
| import_cursor | `452266` |
| progress | `phase=admin1 processed=4384 imported=5 skipped=4379 total=0 scanned=258 target_generation=3 active_generation=0 attempt_seq=3` |
| target_token | `geonames:GH:1:9db333869221df3d` |
| checksum | `c649a65fab3b4027b6246022f4f65b9f658d0ac04119ef9d3bd338eaac39809a` |
| source_url | `https://download.geonames.org/export/dump/GH.zip` |
| source_reference | `/home/cetechtraining/htdocs/training.cetechbpa.com/wp-content/uploads/cetech-delivery-engine/geography/GH.geonames-GH-1-9db333869221df3d.c649a65fab3b.txt` |
| dataset_version | `2026-09-20.c649a65fab3b` |
| source file | exists, readable, **2,464,452** bytes, SHA-256 matches persisted checksum |
| lease_owner / lease_role | empty |
| lease_acquired_at / lease_expires_at | `0` / `0` |
| last_error | empty |
| installed_at | `NULL` |
| updated_at | `2026-09-20 23:15:50` |
| live lease | **no** |

Tick counts: pending **0**, in-progress **0**, failed **0**, canceled **30**, complete **5**. Download complete **2**. Liveness hook: **none** (training does not run geo-live).

### Action Scheduler after 1889

All of these share the same args hash `33a07da1bb4e20af93ba8328bdd4ab01` and group `cetech-delivery-engine-geography-1`.

| ID | hook | status | scheduled GMT | last attempt GMT | notes |
|---|---|---|---|---|---|
| 1889 | `cetech_de_geography_pack_tick` | complete | 21:25:10 | 21:25:50 | owner Continue / retry; WP-Cron; claim 19320 |
| 1893 | `cetech_de_geography_pack_tick` | canceled | 23:12:51 | 0000-00-00 | created then canceled; never ran |
| 1894 | `cetech_de_geography_pack_tick` | canceled | 23:12:51 | 0000-00-00 | created 23:12:51, canceled 23:13:00; never ran |
| 1895 | `cetech_de_geography_pack_tick` | canceled | 23:13:00 | 0000-00-00 | created then canceled; never ran |
| 1896 | `cetech_de_geography_pack_tick` | complete | 23:13:00 | 23:15:50 | WP-Cron; claim 19368; started and completed 23:15:50 |

No geography download or liveness actions were created after 1889.

### Exact cause of processed 3764 → 4126

Canceled actions **1893–1895 did not execute**. The only completed Action Scheduler tick after 1889 is **1896**, whose `updated_at` (`23:15:50`) and progress `scanned=258` match **4126 → 4384**, not 3764 → 4126.

3764 → 4126 therefore happened in the 23:12:51–23:13:01 window from **in-request** `GeographyPackService::tick()` on Location Packs POSTs, not from a delayed geography-tick successor.

Nginx (`/home/cetechtraining/logs/nginx/access.log`), client `154.161.82.50` / Chrome:

- `23:12:45` GET Location Packs
- `23:12:52` POST Location Packs (HTTP 200)
- `23:13:01` POST Location Packs (HTTP 200)
- heartbeat `admin-ajax.php` on the same page
- WP-Cron for 1896 is **not** in this nginx snippet; Action Scheduler logged it as WP-Cron at `23:15:50`

Training still uses the old unique=`true` + `unschedule_all` enqueue, which produced the 1893–1895 cancel storm and left 1896 pending until WP-Cron.

Owner-reported Continue / retry remains **exactly one** (1889). The two later POSTs are observed HTTP form posts to Location Packs; bodies were not logged, so they are not labeled as additional Continue / retry clicks.

4126 → 4384 is action **1896**. Neither delta proves Issue #33 is resolved on training.

## Unique-running-action hypothesis: **PROVEN / ACCEPTED**

Action Scheduler 3.9.2 `ActionScheduler_DBStore::build_where_clause_for_insert()` unique branch matches:

- `status IN (pending, in-progress)`
- same `hook`
- same `group_id`

Arguments are **not** part of uniqueness. SQLite reproduction of that INSERT … WHERE NOT EXISTS query creates **no** successor while the current row is `in-progress`. `unique=false` does create a pending successor that survives completion.

This is an **Action Scheduler 3.9.2 uniqueness-contract reproduction** / **source-faithful Action Scheduler lifecycle integration test**, not a full production Action Scheduler package end-to-end test. Training deployment will exercise the real WooCommerce Action Scheduler runtime.

`GeographyPackService::run_tick()` called `ActionSchedulerReadiness::enqueue_unique_async()`, which previously:

1. `as_unschedule_all_actions($hook, null, $group)` (pending only — the running tick was not canceled; this is the 1886–1888 / 1893–1895 cancel storm);
2. `as_enqueue_async_action($hook, $args, $group, true)`.

From inside running action **1889**, unique=true therefore returned **1889 itself**. 1889 then completed. The pack stayed `importing` with no pending continuation.

Manual Continue / retry works because it runs **outside** a tick: unschedule + unique enqueue creates a **pending** action that WP-Cron executes once. The in-tick enqueue cannot leave a successor under unique=true.

## Accepted direct tick successor (geo-live.1, retained)

`enqueue_unique_async()` no longer unschedules as part of enqueue and no longer uses `unique=true`. It:

- reuses an existing **pending** continuation for the same hook/group;
- otherwise enqueues with `unique=false` so the in-progress action cannot suppress the successor;
- caps pending actions for that hook/group at **one**.

This remains the primary import-progress mechanism: tick A → tick B → tick C → Ready without administrator involvement.

## geo-live.2 watchdog correction

geo-live.1 registered `cetech_de_geography_pack_liveness` to `ensure_import_liveness()` and then enqueued another **immediate** async liveness action while any pack stayed Pending/Importing. Queue depth was capped at one pending watchdog; **execution rate was not**. That design is replaced.

Watchdog is a delayed safety net only:

- hook `cetech_de_geography_pack_liveness`
- group `cetech-delivery-engine-geography-liveness`
- interval **60 seconds** via `as_schedule_single_action(..., unique=false)` plus pending cap 1
- at most one future watchdog
- next check, if still needed, is scheduled for a **future** time (`current_time() + 60`)
- no immediate recursive liveness self-loop
- if a healthy current-generation tick or download is pending/running, or a live pack lease exists, the watchdog does **not** mutate pack work
- orphan recovery re-arms one continuation for the **same** pack identity (id, source, checksum, target generation/token, cursor, progress)
- Failed is terminal for automatic processing; Ready is terminal; watchdog unschedules when no unfinished packs remain

A pack is orphaned only when status is Pending or Importing, no valid current-generation tick is pending, no tick is running, no relevant download is pending/running, and no live conflicting pack lease exists.

## Deduplication / concurrency

- at most one pending pack-tick continuation per pack group;
- at most one pending delayed watchdog;
- pack lease still fences concurrent import mutation;
- generation token fence still rejects stale workers;
- Ready / Failed packs schedule no automatic tick successor;
- `tick()` returns immediately for Ready/Failed (`reason=terminal`) and does not import a Failed pack;
- Failed packs do not auto-resume (retry remains the contract);
- when WordPress uploads are unavailable, `store_generation_file()` keeps the provided readable source instead of failing the pack (unit tests have no `wp_upload_dir`); copy failure still fails the target;
- official download still unschedules ticks then enqueues the download hook.

## Intentionally killed diagnostic PHPUnit runs

Some earlier PHPUnit processes were intentionally killed while diagnosing a hang caused by an unguarded diagnostic loop that kept calling `install()` + `tick()` after a pack had already become Failed. That loop is now guarded. Those killed processes (including Windows exit `4294967295`) are **not** qualification failures. Qualification uses the later completed suites only.

## Out of scope

Issue #31 and Issue #32 are untouched. Training/FLAIROC/production/POS were not mutated. RC.12 was not retagged. RC.13 was not created. geo-live.1 ZIP was not overwritten. geo-live.2 was not deployed or merged.
