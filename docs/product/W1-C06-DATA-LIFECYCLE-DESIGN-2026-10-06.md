# W1-C06 data-lifecycle design

Proposal: **W1-C06-DATA-LIFECYCLE-1 — OWNER REVIEW; NOT IMPLEMENTED**.

At 2026-10-06 23:27:34 UTC the owner instructed: “Approve W1-C05 for integration and prepare W1-C06’s data-lifecycle design.” [Issue #72](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/72), under #48, tracks this design-only task. Branch: `ws3/wave1-c06-data-lifecycle-design`.

C05 PR #71 is integrated unchanged as `8b038743a3b67fa8aab2e4a7fcc0c92abbb6c4c5`, tree `48fce27af7cfb22f9685295e44c406786d33084c`. Actual-master run **37546745795**, attempt1, independently passed all eight jobs, complete native299/HTTP66/smoke, PHP2144/12826/1skip, JavaScript8/102 and required SQL113/2892/2deprecations/0skips including C03/C04 classes43+44. Its independently checked575-file map is `44c4d52b2c48f5ab78eefa112d564ea2a53cb64ba2cfc251959674a332872a34`. Candidate run37545681669 and its SQL2891 count remain separate evidence. Actual-merge archive/member fingerprints are in PR #71.

## What the owner would approve

Keep contractual and operational history. Introduce a complete typed data registry, a read-only preview, and one useful bounded cleanup adopter: the geography endpoint’s replaceable response cache, retaining its existing **120-second** lifetime. Align both uninstall paths to a single safe manifest. An explicit uninstall removes only the named temporary/derived data and plugin role permissions; it does not drop any of the32 domain tables.

This is a concrete proposed narrowing of the existing explicit-delete behavior. The old checkbox promises a full configuration/table deletion. Its wording and both executors must change together during C06 implementation. This proposal preserves governed data while COR-030/protected deletion and Issue #50/global reset remain separate; it does not implement those business deletion workflows.

The design changes no production code, runtime setting, schema, cache writer, schedule or stored data. Current development remains schema **8**, identity `1.0.0-dev.wave1-snapshot-readers.1`, **575** production PHP files. C06 implementation and its new runtime proofs require the next specific owner instruction.

## Current-source inventory and observed problems

[Exact source inventory](W1-C06-DATA-INVENTORY-2026-10-06.md) lists all32 table suffixes, columns, indexes, owners and logical references; 16 actively written options plus one legacy option; all23 feature-flag options; user/Woo/session metadata; six transient families; geography files; logger and shared scheduler ownership. This is a source inventory, not inspection of a merchant database or a deletion grant.

| Observed current behavior | Consequence for C06 |
|---|---|
| Explicit uninstall drops27 legacy tables; five C03/C04 stores are preserved | Legacy `audit_log`, shipments and configuration remain vulnerable under that old explicit policy. C06 proposes zero domain-table drops in both paths. |
| Autoload and no-vendor fallback remove different options and roles | Replace duplicated lists with one standalone WP-only manifest/executor. Preserve settings, revisions, markers and operational indices; remove exact plugin capabilities from all currently known roles consistently. |
| Scoped-save completion is inside `audit_log.new_value`, located across all history by token | Preserve every audit row absent a separately proved disposable row family. Age, >100 later audits, disabled writer, removed current scope or missing user does not authorize purge. |
| Existing ordinary transients have separate timeout/value writes | A cleanup row lock alone cannot fence an unfenced `set_transient()` producer. These existing classes stay observation-only under C06; their current WordPress expiry/consumption remains authoritative. |
| Geography response cache has a real120-second lifetime and is fully derivable | Adopt only its three cache get/set paths into a fenced single-row envelope; do not alter query/auth/rate-limit/business semantics. |
| Pack file cleanup uses a full glob/sort, captured references and attempted unlink counts | Do not reuse it as C06 enumeration, reference protection or confirmed-removal proof. This design adds no file deletion. |
| Bulk admin listing is descending despite an `after_id` filter | Do not reuse it as an ascending cleanup cursor. Preserve jobs/items/recipes and their rollback/replay relationships. |
| Current native lifecycle fixtures include an autoload-on marker | Add real native tests for both normal autoload and the no-autoloaded-row fallback, rather than assuming option-cache parity. |

## Typed registry and preservation manifest

One immutable versioned registry is the authority for each class: stable internal class ID, module owner, exact storage adapter and current-site scope, accepted format, normal disposition, explicit-uninstall disposition, existing expiry clock, references/protection predicates, bounded selector, owned hook/group, safe diagnostic projection and source/proof mapping. Duplicate IDs, unresolved ownership, absent store coverage, unknown formats or conflicting dispositions refuse registration. Caller-provided SQL, table prefixes, option names, paths or arbitrary callbacks never become selectors.

The pure manifest is shared with the typed registry and both uninstall entry points, not duplicated behind a Composer branch. It contains no Woo/runtime class dependency. A standalone package-owned helper has a fixed minimal dependency loader for the C03 native connection interfaces/result/transport/factory and the new DataLifecycle types/executor. It loads only those reviewed own-source paths by `require_once`, so the no-vendor path uses the same pinned native SQL owner and cache/checkpoint protocol without Composer, Woo or third-party classes. No user-supplied class/path, generic autoloader, operation profile or global-$wpdb transactional fallback is allowed. Implementation must enumerate/test this exact dependency closure. Missing/unreadable helpers preserve data rather than invoking the old destructive fallback. Source tests compare table inventory to the27 legacy suffixes plus the two C03 and three C04 stores. A newly introduced persistent store needs a registry entry before activation.

| Registered class family | Exact storage authority | Normal cleanup / proposed explicit uninstall |
|---|---|---|
| Configuration and entity truth | Offers, zones/rules, logistics, suppliers, origins, pickups, rates/rules, product rules, scopes/fields/collections | Preserve all rows; normal business editing/reset remains its own authorized operation. |
| Material audit and scoped completion | Entire `audit_log`, including private token/revision/scope completion | Preserve; no generic log age/limit purge. |
| Generic acceptance history | `operation_records`, `operation_changes` as an inseparable acceptance/event pair | Preserve pending, accepted, rejected and no-change records; no expiry/tombstone/lease takeover. |
| Versioned policy/rule history | `rule_family_guards`, `logical_rules`, `rule_versions`, predecessor/supersession and scheduled evidence | Preserve all states, including apparently unreferenced/retired versions. |
| Fulfilment history | `shipments`, `shipment_items`, `shipment_events` plus failure/COD/issue options and Woo metadata | Preserve rows, original references, idempotency identities and operational indices. |
| Bulk recovery/evidence | `bulk_jobs`, `bulk_job_items`, `bulk_recipes` and parent/before/after/token/lease facts | Preserve; terminal-job expiry/P07/P08 are not selected here. |
| Geography/coverage authority | Packs, locations, aliases, provider mappings, coverage groups/members/postcodes; revision and upgrade/repair state | Preserve canonical/promoted/staged identities and current recovery/reference state. Lease expiry is recovery, not deletion. |
| Contractual Woo history | Protected line/package JSON/version, shipping group and policy facts through Woo CRUD/HPOS or legacy meta | Preserve, including malformed/unsupported bytes; no current-source reconstruction. Woo order/privacy deletion retains Woo authority and requires its own adapter disposition. |
| Authored options and operational controls | Exact inventory options, all23 flags, user preferences/review cursors; new C06 coordinator/status | Preserve default and explicit uninstall, apart from the exact derived removals below. Keep original value and autoload state. |
| Old ephemeral classes | Activation/admin notice/general draft60s, scoped draft900s, geography response120s, rate bucket60s | Existing owner expiry/consumption; no C06 pair sweep. Only exact activation notice may be cleared by deactivation/explicit uninstall as declared. |
| New managed geography response cache | Exact new per-site option envelope/key and advisory object-cache group defined below |120-second producer expiry; expired SQL rows eligible for normal cleanup; all valid owned rows may be removed under explicit uninstall’s bounded cache-only pass. |
| Shared/external stores | Woo sessions/shipping settings/logs, core roles/user meta, WordPress cron and AS storage, external object cache, provider/source/pack files | Preserve storage; use only the exact capability and owned-scheduling APIs below. No shared-table drop, global flush/drain or provider/file sweep. |
| Future quote/diagnostic persistence | No assumed quote store or newly approved log retention | Registration/activation waits for its exact ownership, acceptance/reference and expiry contract. No quote/log TTL is invented. |

All32 domain tables are preserved in both lifecycle paths. The seven `DE-DATA-*` rows are not promoted to wholly complete by one cache adopter. In particular, future quote expiry, universal legacy logger adoption/configuration and protected merchant data deletion remain separately incomplete. C06’s new diagnostics use finite safe projections; legacy Woo logger messages/top-level blacklist are not claimed repaired.

## One concrete cache adopter

Reserve only `StorefrontGeographyEndpoint::cache_get/cache_set` and their three children/search/postcode call sites. Keep nonce/capability checks, the rate bucket60s, geography queries/current revision, public response shape, pagination, uncached fallback and120-second TTL unchanged. Old transient/object-cache keys are not migrated or purged and retain their original expiry.

New SQL key grammar: `cetech_de_gc_geo_v1_<64 lowercase hex>`, a purpose-bound HMAC-SHA256 of server-canonical site/kind/country/parent/query/extra/page/opaque geography revision and current locale. Resolve these inputs at the endpoint; caller-supplied option names or digests are not accepted. The current revision is a pack-promotion counter, not a broad proof that every geography mutation changes it. Existing keys omit locale, so the new namespace must include locale to avoid sharing translated labels across request contexts. The current site’s actual `$wpdb->options` table is the only participant. A separate versioned object-cache group/key avoids interpreting old raw arrays as envelopes. Raw queries, addresses, IPs, request tokens or file paths are not persisted in names or diagnostics.

The new single-row JSON envelope has exact fields `format=1`, `class=geography_response_cache_v1`, resolved `site_id`, response `kind`, `identity_digest`, server-generated `generation`, `expires_at` as a finite UTC epoch integer, and typed `payload`. It is written with autoload **off**. Exact kind-specific customer response schemas exclude unknown/private fields and the current search `request_token`; that token is reattached from the current request, as the endpoint already does on cache hits. Children contain at most50 items and search at most25 under their current six-field response schemas; postcode has its exact required/visible boolean schema. The implementation must derive the field allowlists from the inspected endpoint, not accept arbitrary arrays or the old two-key blacklist. Preserve valid response values; do not truncate a result to fit a cache budget.

Proposed cache budgets: payload64KiB, whole envelope66KiB, JSON depth8/nodes1024. Invalid/oversized/unknown-format caches are misses and are retained by cleanup, rather than served, rewritten or treated as approved garbage. A storage refusal simply uses the existing uncached response. No lost cache write can change quote, settings or order acceptance.

The cache producer opens a fresh dedicated no-reconnect session using C03’s `OperationConnectionFactory/OperationSession` ownership primitive; it does not register a generic operation-ledger profile or alter current business adapters. Validate the resolved site/table/unique option-name index and InnoDB before a unit. A lookup obtains a server-created ticket with current observation time and either absence or exact physical ID/current generation. After the uncached query, current-lock the exact cache row and publish only against that ticket: insert only if still absent, or replace the still-observed physical ID/generation. A current newer writer or recreated present identity refuses publication while returning the valid uncached response.

An absent ticket proves **current absence**, not that another entry was never inserted/deleted meanwhile. Do not claim an absent-to-present-to-absent history fence without a retained identity. Anchor ticket and payload `expires_at` to the lookup/query observation time **+120 seconds**; an expired delayed query is not cached, and neither late publication nor object-cache hits extend its validity. Thus120s remains the maximum cache lifetime; slow queries may leave a shorter remaining cached lifetime or bypass storage. This explicitly proposed fence avoids keeping older query facts fresh through a delayed write. Normal newer-cache expiry/deletion cannot make an older expired ticket usable; explicit uninstall remains a finite snapshot-ceiling pass and does not claim to prevent concurrent new cache creation above that ceiling. Commit acknowledgement precedes object-cache publication. The producer never locks the cleanup coordinator, so it cannot invert the worker’s coordinator-then-candidate lock order.

Every cache read validates class/site/format/kind/identity and inline expiry, including object-cache hits. A cached generation must match an authoritative current option identity/generation read, bypassing WordPress option caches; otherwise load the current SQL envelope or return a miss. A portable `wp_cache_set()` cannot order delayed older publication, so a newer SQL generation cannot be overridden by an older advisory cache object. A current-locked native read supplies the linearization point; unsupported routing/owner means uncached fallback. Unsupported storage, malformed envelopes, expired entries and unavailable cache services all produce a fresh endpoint query. Post-commit cache publication/invalidation is advisory and outside the SQL unit. A delayed invalidation may leave an expired envelope in a provider cache, but reads cannot serve it. SQL deletion counts never claim an external cache purge or global physical absence. Persistent-cache compatibility needs actual protocol tests; default WP_Object_Cache evidence does not certify arbitrary Redis/WP Rocket/database drop-ins.

## Schema-neutral progress and transaction ownership

Add one exact per-site autoload-off coordinator option `cetech_de_gc_state_geo_v1`, maximum16KiB, and one bounded lifecycle status option `cetech_de_data_lifecycle_uninstall_status`. Both are preserved operational controls, excluded from cache selectors. No table/index/migration or schema bump is proposed. If the current options participant lacks the required unique identity/InnoDB/authoritative ownership contract, refuse this maintenance feature while the existing geography queries remain available. No implicit connection replacement, nested unit, DDL or out-of-unit WordPress/scheduler/filesystem call can occur inside an owned batch.

Coordinator fields: `format`, resolved `site_id`, `class`, `policy_digest`, server-generated `run_id`, `mode`, immutable `cutoff_utc` and `ceiling_id`, `cursor_id`, monotonic `revision`, private `checkpoint_token`, `last_batch_id/sequence`, `status`, bounded cumulative inspected/deleted/renewed/protected/disappeared/invalid counts and finite `last_error_code`. Statuses are `running`, `paused_refused`, `outcome_unknown`, `completed`. The immutable run-manifest hash binds format/site/class/policy/mode/run/cutoff/ceiling; progress contains no cached payload or principal/address/query/token content. Checkpoint identity is concurrency evidence, never authorization.

Start validates current management authority or the specifically registered internal site worker. Capture authoritative UTC and finite `MAX(option_id)` once and persist the first coordinator checkpoint atomically. After a refused start, no usable run is published. Resume never recalculates cutoff/ceiling. A new pass can replace only a terminal control envelope through a serialized authorized start; late actions for an older run refuse and cannot become a new pass. Single-record control replacement is not expiry of C03/business completions. Per-batch operational status is finite maintenance progress, not a durable business receipt.

## Read-only preview and bounded worker

Preview captures fixed site/class/policy/cutoff/ceiling into a private typed continuation, under an authoritative read unit that rolls back. It writes no coordinator/cache/history row, reserves no C03 identity, emits no material event and schedules nothing. Continuation cannot provide SQL/path selectors or a deletion grant. Preview reports observed candidates, expired candidates and exclusion reasons; it does not approve a material target manifest. Execution independently current-rechecks every candidate.

Proposed budgets for owner approval: **200 candidate inspections**, **50 confirmed SQL deletions**, **one batch per invocation**, **2-second soft wall budget**, **2-second lock wait**, and primary-key windows of at most **1,000 identities**. These are work limits, not new retention periods. A stalled statement can exceed the soft wall budget; statement/lock failure must return a truthful refusal and cannot be described as a hard2-second completion guarantee.

Selector: current-site options, `option_id > cursor AND option_id <= upper`, ascending, literal escaped fixed cache-prefix prefilter and a bounded201-row probe. Compute `upper = cursor + min(1000, ceiling-cursor)` without integer overflow. Validate the complete lower-hex name and envelope in the adapter; a prefix match alone never establishes ownership. Metadata/length checks precede bounded payload decoding. Use primary-key windows to bound sparse/foreign scanning; no OFFSET, unbounded COUNT/list/glob or namespace-wide DELETE. Enumeration is only candidate identity discovery; the effect read is a fresh current row lock.

An empty/short exhausted window advances to its upper boundary; an overflow/truncated window advances only through inspected identities. Reaching50 deletions or a time bound cannot skip uninspected rows. A disappeared, renewed, unknown or protected candidate advances only in a committed checkpoint. A matching row created above the original ceiling stays excluded. An empty expired/accepted page does not mean complete; completion requires exhausting the fixed ceiling. Display inspected candidates and confirmed removals separately; SQL prefilter count is not an accepted total.

Each batch opens a fresh unit, validates the options participant, current-locks the coordinator, verifies site/run/policy/revision/token, and locks candidates in increasing physical ID order. At deletion, require exact registered key/site/format/class/ID, valid inline expiry **strictly before cutoff** for normal cleanup, and the class’s declared non-authoritative reference role. A renewal retains the same ID but has current expiry after cutoff and is protected. Recreated rows have a new ID and cannot be folded into the old pass. Changed registry/protocol, uncheckable ownership or reference semantics pauses/refuses rather than guesses.

Delete by exact physical ID + exact option name + observed envelope guard. Affected-row truth, cursor/counters, revision and checkpoint token commit in **one SQL unit**. No outside effect belongs in it. Only the producer protocol makes cache expiry/deletion safe; existing notice/draft/rate-limit transient pairs remain observation-only even when their timeout appears expired.

## Failure, interruption and acknowledgement

| Outcome | Required truth and next action |
|---|---|
| Candidate becomes current, unknown, disappeared or protected | Preserve it; checkpoint inspected progress and safe reason count if the batch commits. |
| Delete/checkpoint/ownership failure | Acknowledged whole-unit rollback restores all effects/cursor/counts. An optional separate revision-guarded control-only unit may record `paused_refused`; if that fails, retain durable progress and report failure externally. |
| Commit positively proved unsent | Report known refusal only after acknowledged whole rollback; preserve original run/cutoff/ceiling. |
| Sent commit with lost acknowledgement, thrown commit, failed rollback/retirement/read | Return `outcome_unknown`; attempt no second effect or cached-state overwrite in that invocation. |
| Explicit fresh reconciliation | Retire the uncertain handle, open a fresh authoritative session and current-lock the same coordinator. An exact advanced checkpoint confirms stored counters without deletion again. Later serialized progress is confirmed aggregate progress, not invented per-item receipts. |
| Original checkpoint unchanged | Resume the old page only after verified retirement and a granted current lock establish that the old unit ended without committing effects. Mismatch, corrupt state, blocked lock or unverified ownership stays unknown/refused. |
| Worker/process killed | Atomic deletion+checkpoint either commits together or rolls back together. No age/lease expiry guesses a business acceptance. Resume from durable state after reconciliation. |
| Post-commit cache/scheduler failure | SQL progress stays committed. Record/return finite publication or dispatch pending; do not repeat the batch or report global cache/queue success. |

Invalidate only the exact accepted option names plus WordPress `alloptions/notoptions` as required after SQL acceptance, using the current option-CAS precedent. No `wp_cache_flush`, shared group enumeration or pre-commit publication. Actual native tests must use normal autoload rows, all-autoload-off fallback, prewarmed caches, direct physical reads and fresh-process reads. Retained values and their autoload disposition remain unchanged.

## Owned background scheduling

Proposed hook `cetech_de_data_lifecycle_cleanup_batch`, group `cetech-delivery-engine-data-lifecycle`, arguments only server-resolved site/run/checkpoint identity. The scheduler is a wake-up, not the transaction fence. Register one supported site maintenance tick at **300-second cadence** for this adopted cache; each wake-up executes at most one bounded batch. If a run is incomplete, enqueue its own successor only after an acknowledged checkpoint. Require initialized Action Scheduler and a positive returned action ID; do not trust the existing bulk gateway’s boolean or claim an exhaustive global pending count from a bounded probe.

Coordinator locking handles duplicate/racing actions. A running unique action must not suppress its successor; pending-only deduplication with the existing reviewed `unique=false` continuation pattern can be used. Missing scheduling leaves resumable status/explicit continuation available. No synchronous unlimited fallback. Deactivation stops/cancels only exact pending C06 hook/group/site continuations, retains coordinator/data and does not globally drain AS. Re-activation revalidates current registry/readiness and resumes; late older-run actions cannot replay effects. Preserve other modules’ actions, shared tables/logs, in-progress/unknown work, pack liveness and bulk P07/P08 behavior. No new file/import/reset scheduler is adopted.

## Uninstall parity and accurate merchant intent

Default uninstall preserves every data/control/capability sentinel. Deactivation preserves domain/history/settings/control data, retains the existing exact activation-notice consumption and may stop only C06-owned dispatch. No worker is invoked as an unbounded deactivate sweep.

Explicit intent must be the exact saved supported value, not a loosely cast arbitrary string/array. Resolve current site only; network-wide deletion is not implied. Both autoload and standalone fallback execute the same pure manifest:

1. Preserve all32 domain tables, Woo/user/session/meta history, authored options, all23 flags, revisions/schema/migration/recovery markers, indices, existing drafts/rate buckets, provider/pack files and shared scheduler storage.
2. Remove exact activation notice through its native API; remove only valid owned managed geography response-cache rows in the same bounded protocol, using explicit-cache-removal mode instead of claiming they were expired. Unknown/malformed rows are retained with safe reason/status.
3. Remove only the finite18 plugin capabilities from all currently known roles in both paths. Preserve unrelated capabilities, user grants and the WordPress role option itself. This revokes role permission; preserved private completions still require current authorization.
4. Remove the derived `cetech_de_capabilities_version` marker after capability removal. Keep `cetech_de_delete_data_on_uninstall` until the supported steps succeed; clear it only after an acknowledged complete result. Preserve the bounded uninstall-status and coordinator control records.

Uninstall cannot rely on a scheduler to finish after plugin removal. It performs at most one bounded cache batch and records `incomplete`, `refused`, `outcome_unknown`, or `completed` truthfully. Large inventories may leave disposable rows preserved; a later reinstall/explicit continuation can resume. Retained/unknown cache rows are reported, not force-cleared to claim a clean wipe. Capability operations are outside the SQL batch; partial failures retain intent and safe status and are individually idempotent on retry. No helper/API failure may activate the legacy27-table DROP fallback or show unsupported deletion as complete.

Implementation must also change the existing checkbox’s two strings in `DeliverySettingsPage.php`, while preserving its posted name/option compatibility. Proposed label: **“Remove temporary data and permissions when uninstalling.”** Description: **“Removes temporary geography responses, the activation notice and Delivery Engine role permissions. Your settings, order and shipment history, and saved records are kept.”** This accurately narrows the old full-clean-uninstall promise; it adds no protected-data erase/reset product flow.

## Exact future implementation leases

Only the owner and AI agents are working. One root integration editor owns shared files and remote state. Proposed exclusive leases after approval:

| Lease | Concrete files / permitted implementation |
|---|---|
| Registry/lifecycle | New `src/Domain/DataLifecycle/` typed entries/manifest validation/plan/status; shared standalone `src/Bootstrap/DataLifecycleManifest.php`, fixed minimal own-dependency loader and executor; focused unit tests. |
| Cache/worker storage | New `src/Application/DataLifecycle/` and `src/Infrastructure/Persistence/` cache/coordinator repositories using C03 native session ownership; no schema/migration or generic operation profile edits. |
| Sole existing adopter | `StorefrontGeographyEndpoint.php` cache methods/three internal call sites only; preserve queries/auth/nonces/rate limiter/public responses/current120s. |
| Root central integration | `Plugin.php`, `Deactivator.php`, `Uninstaller.php`, root `uninstall.php`, the named `DeliverySettingsPage.php` strings, package/current candidate identity/qualification runner and exact new scheduler registration. No unrelated lifecycle or FeatureFlags changes. |
| Physical/native proof | New `tests/Integration/DataLifecycle/`, focused support fixture session/cache/scheduler adapters and one disposable native WordPress module; required CI selection only for the actual adopted new SQL group. |
| Independent closure | Read-only review of reference/ownership/expiry/commit/cache/privacy and exact final source/evidence; no invented human-availability gate. |

## Thirty planned cases — NOT EXECUTED by this design

| Case | Actual future proof required | Plan |
|---|---|---|
| C06-01 | Registry covers exact32 tables/options/meta/transient/files/queues; duplicates, unknown policy/owner/format and missing stores refuse. | T15/T17 |
| C06-02 | Ordinary cleanup preserves byte/row sentinels in every durable table and exact authored/control options, including retired/unreferenced rules. | T15 |
| C06-03 | COR-007 original token buried under101 later audits replays in a fresh OS process with no second settings write/audit after cleanup pressure. | T15 |
| C06-04 | Real C03 completion/event pair and C04 scheduled predecessor/revision evidence survive cleanup; original completion/activation cannot be recreated from age. | T15 |
| C06-05 | Actual Woo HPOS/legacy V1/V2 line/package/version/shipping facts and historical planner results survive cleanup, including malformed bytes. | T15 |
| C06-06 | Only new managed geo rows are deletable; legacy transient pairs, drafts/tokens/rate buckets, expired active leases and Woo logs remain under their existing owner. | T15/T16 |
| C06-07 | Three cached/fresh endpoint response schemas agree; auth/nonce/rate/query/fallback/TTL120 preserved; request_token stripped/current token reattached; locale and opaque revision identities are distinct. | T16 |
| C06-08 | Default and controlled persistent-cache fixtures enforce inline expiry, finite projections and unknown/oversized fallback; delayed older publication cannot replace a current newer generation or extend its observation-time validity; absent→insert→delete cycle truth is bounded, not a perpetual absence claim. | T16 |
| C06-09 | Read-only dry-run writes no data/control/audit/ledger/schedule and exposes bounded counts plus accurate incomplete continuation. | T16 |
| C06-10 | Fixed cutoff/ceiling excludes later matching identities, including after a refused start/first batch; no eligibility-total substitution. | T16 |
| C06-11 | Empty eligible pages, disappeared candidates, malformed rows and renewed rows advance the candidate cursor; complete only at ceiling exhaustion. | T16 |
| C06-12 |200-inspection/50-delete/1000-ID-window budgets hold over a large sparse inventory without skipping uninspected candidates or overflowing integers. | T16 |
| C06-13 | Two actual SQL connections renew the same key around enumeration/effect locking; current value survives and valid cache is never deleted from a stale read. | T16 |
| C06-14 | Deleted/recreated key has a new physical ID above original ceiling; foreign/similar names/other site rows remain exact. | T16 |
| C06-15 | Concurrent same-run workers serialize; stale revision/checkpoint/run actions cannot repeat or start a new accepted batch. | T16 |
| C06-16 | Refused delete and refused checkpoint roll back effects/counters/cursor together, retaining original cutoff/ceiling; later resume stays truthful. | T16/T20 |
| C06-17 | Positively unsent commit plus acknowledged rollback is distinct from sent/lost acknowledgement and failed retirement. | T16/T20 |
| C06-18 | Actual committed acknowledgement loss followed by fresh-process/current-lock reconciliation returns stored progress with no second deletion. | T16/T20 |
| C06-19 | Kill before/after delete/checkpoint/COMMIT windows; durable effects and progress remain one unit; uncertain owner never uses cached state to retry. | T16/T20 |
| C06-20 | Post-commit object-cache and enqueue failures preserve accepted SQL progress; no global cache/queue success is claimed. | T16 |
| C06-21 | Exact owned AS hook/group/site successor and300s tick bounds; late/duplicate wake-ups safe; shared/foreign/pack/bulk actions and tables preserved. | T16 |
| C06-22 | Current/promoted/provider/referenced geography IDs, pack files and forged/out-of-root/symlink paths all preserved; existing file helper not called by GC. | T16/T17 |
| C06-23 | Actual default deactivation/uninstall preserve domain/control/meta/roles; only declared activation notice/owned dispatch behavior allowed. | T17 |
| C06-24 | Exact explicit intent validation; both autoload/fallback shared-manifest paths retain all32 domain tables and same option/meta/role disposition. | T17 |
| C06-25 | Native normal-autoload and all-autoload-off fixtures with prewarmed alloptions/notoptions/individual caches; physical/current/fresh-process reads agree. | T17 |
| C06-26 | Custom-role exact18-cap removal retains unrelated and user grants; marker/intent/status ordering survives partial capability failure. | T17 |
| C06-27 | Large explicit uninstall stays bounded/incomplete with resumable controls; missing helper/API/unsupported ownership never falls back to DROP or false completion. | T17 |
| C06-28 | Status/log projection rejects nested/renamed private payloads, tokens/query/IP/paths/SQL/exceptions; counts/completeness stay finite and current-site authorized. | T15/T16 |
| C06-29 | Non-InnoDB/custom unsupported routing/index/unknown coordinator refusal preserves existing domain and fresh endpoint behavior; disable/rollback preserves data. | T20 |
| C06-30 | Final implemented candidate executes its new required real-DB/native proofs plus all existing native299/HTTP66/smoke/SQL113/JS102 and PHP gates; immutable source/ZIP/member checks. | T21 |

These30 declarations are reviewable planned work, not test results. Design CI executes only the existing C05 preservation suite. C06’s SQL group and native counts become required only with its specifically authorized implementation and exact final selections; earlier excluded COR groups are not silently combined with default MariaDB evidence.

## Finite design review and preservation qualification

One independent read-only closure passed after the draft made two guarantees precise: an absent cache ticket proves current absence, not an insert/delete history; expiry is anchored to lookup/query observation plus the existing120-second maximum. The standalone no-vendor path now requires a fixed own-source dependency loader and the same C03 native connection owner. All32 domain stores are preserved, and all30 proposed cases remain future implementation work. Local PHP/JavaScript control-plane checks and whitespace checks passed; frozen372 IDs/classifications and production575/schema8 are unchanged. Exact design-candidate CI, receipts and immutable installed-source verification are recorded separately in the design PR, rather than claimed as execution of C06.

## Traceability and next decision

Primary accepted rows: `DE-DATA-001` through `DE-DATA-007`. Cross-cutting: `DE-CONFIG-007`, `DE-SNAP-008`, `DE-FLAG-002`, `DE-RULE-007`, `DE-DIAG-005`, `DE-OWN-001`, `DE-SEC-006`, `DE-SEC-009`, `DE-SEC-010`. Source inventory, disposal/reference predicates and the30 cases map T15–T17/T20/T21. The372 frozen IDs/classifications and stable release scope stay unchanged. Registry coverage is not a promise that every family’s future behavior is now complete.

The owner approves a schema-neutral, current-site preservation manifest; exact shared uninstall narrowing/copy; only geography-response cache adoption at its existing120s; one fixed-cutoff/ceiling progress option; explicit owned atomic batches and reconciliation; stated payload/work/scheduler budgets; and the30 actual implementation proofs. No global reset, protected domain erase, new quote/log age policy, provider pack deletion or C07 emergency/business adoption is bundled into that decision.

Next instruction: **“Approve W1-C06-DATA-LIFECYCLE-1 and implement W1-C06.”**
