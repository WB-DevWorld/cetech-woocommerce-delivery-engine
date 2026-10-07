# Wave 1 shared contracts — review and implementation plan

Status: **OWNER ACCEPTED — WAVE1-PLAN-1; W1-C01–C06 INTEGRATED; W1-C07 DESIGN APPROVED / IMPLEMENTATION AUTHORIZED**. At2026-10-07 01:08:21UTC owner approved W1-C07-EMERGENCY-CONTROL-1 and implementation. Design PR#77 merged unchanged as `11c48f1a7dd6440fb0a355b3b8d045408de9526d`, tree `d576632df57fbf3166ce1f29e970f711c71c4406`; #76 completed. [Issue#78](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/78) tracks the [implementation](W1-C07-IMPLEMENTATION-2026-10-07.md). The approved [design](W1-C07-EMERGENCY-CONTROL-DESIGN-2026-10-07.md) and historical [source inventory](W1-C07-EMERGENCY-SOURCE-INVENTORY-2026-10-07.md) remain policy authority. Frozen IDs/classifications/STABLE-1.0-SCOPE-1 stay unchanged.

## What this delivers

The opening repairs are merged. The next features need common rules for versions, repeated requests, order history, cleanup and explanations. This packet makes those rules reviewable together and divides implementation into seven finite checkpoints. The recommended first checkpoint is small: internal contract types, safe errors, request identifiers and contract tests. It changes no checkout behavior, schema or existing save/reset semantics.

The packet contains proposed decisions, inspected reuse points and limitations, snapshot and data compatibility tables, a threat review, meaningful acceptance cases, ownership/leases and an ordered implementation list. [The traceability CSV](WAVE1-CONTRACT-TRACEABILITY-2026-10-06.csv) maps every one of the 39 primary IDs and the explicitly applicable cross-cutting IDs. Mapping a requirement here does not change its implementation classification.

## Source and authority

- Repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Inspected master: `d38b704b2bf152d80e2d84731c15718417f1a885`; tree `84f86c6842e246fba841c80cbc24fa2b0d390717`.
- Accepted functional merge: PR #55 / `7add0bf3ca66f73982797c0362fb33983da975a2`; PR #56 reconciled its status records without changing production source.
- Actual final-master CI [37504554658](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/actions/runs/37504554658), attempt 1: all eight jobs succeeded, including the executed Required Gates check. This is baseline evidence, not execution of the proposed acceptance cases below.
- Original planning baseline: production PHP was 494 files, installed map `48242148bc1fcada1275cb96553ea7d6e4efc3814ee4cc8f5886ed9ee11a8238`, schema 6. Current integrated C06 is 591 files/schema 8, as recorded above and in current status. The qualified disposable CI listeners use OPcache with JIT disabled. Issue #54 retains original-runtime questions.
- Governing sources: live Issue #48; `PRODUCT-CONSTITUTION.md`; `CAPABILITY-REGISTRY.yaml`; `REALIGNMENT-PLAN.md`; `RELEASE-SCOPE.md`; `DECISION-CONFLICT-REGISTER.md`; `docs/AUTHORITY.md`; accepted opening-correction checkpoints in `CURRENT-WORK.md`.
- The October 5 opening approval archive's `24-ISSUE-48-FINAL-PROPOSED-RECONCILIATION.md`, `25-WAVE1-CONTRACT-FREEZE.md` and `26-WAVE1-BOUNDED-IMPLEMENTATION-ISSUES.md` are historical drafts. Their extra quote/group, source-choice and support/embed packages are separate decisions, not silently added to this five-package task. This packet's `W1-C01`–`W1-C07` are local proposed task labels, not replacements for frozen Requirement IDs or those historical draft labels.

Only the owner and AI agents are working. Codex is packet author/integration editor; independent agents inspected rules/requests, history/lifecycle, and requirements/audit. Functional WS1/WS2/WS3 responsibilities guide review without introducing another person's availability as an acceptance prerequisite.

## Decision summary

The owner accepted the proposed D01–D12 contract baseline. Later operation-specific storage, adoption and activation decisions remain dependencies; the preserved existing protections continue to apply.

| Decision | Proposed contract | Boundary |
|---|---|---|
| D01 | Distinguish logical rule identity, immutable rule-version identity, configuration revision, snapshot format and database schema version. | Preserve existing `config_version` and V1/V2 meaning. |
| D02 | New lifecycle uses draft/scheduled/published/retired; UTC `[effective_from, effective_until)`; published payloads immutable. | Existing Rate Card inclusive `effective_to` is unchanged. |
| D03 | Publish/supersede is atomic, revision guarded and audited; a family declares deterministic precedence and collision policy. | No adoption of an admin-only resolver as customer authority. |
| D04 | Inventory stable/experimental/internal surfaces explicitly; new Wave 1 types start internal. | Visibility or a PHP namespace is not an API stability promise. |
| D05 | Machine error codes, safe parameters and request/correlation identifiers are distinct from localized wording and authorization. | Existing surface payloads change only through separately tested adapters. |
| D06 | Repeatable operations have authority/operation/target-scoped keys, canonical intent and explicit completion states. | Existing scoped-save/reset replay remains protected; generic durability is future work. |
| D07 | V1/V2 remain readable; optional extensions are typed/versioned; mandatory changed semantics require reader support before a new writer. | No historical reconstruction from current configuration. |
| D08 | Inventory every store; preserve contractual history and completion-bearing audit records; expire only explicitly eligible classes. | No invented numeric retention period or blanket audit purge. |
| D09 | Cleanup has owned selectors, fixed cutoff/ceiling, bounded batches, reference rechecks and truthful resumable progress. | No shared Action Scheduler table deletion or global reset feature. |
| D10 | Global checkout suspension overrides module gates and blocks managed admission safely, preserving history. | No silent free/native checkout substitution or shipment cancellation. |
| D11 | One internal reason/provenance context has separate shopper, authorized-admin, sanitized-log and mutation-audit projections. | Internal `toArray()` output is not a public serializer. |
| D12 | Each implementation checkpoint has exact scope, central leases, meaningful proofs and fresh candidate CI. | Green planning CI does not prove future runtime contracts. |

## 1. Versioned rules and policies

Reuse the scoped configuration resolver, fingerprint/version descriptor, permission model and audited transaction boundaries. Do not turn mutable `ConfigurationScope::config_version` into an immutable policy-version ID. `RecordStatus` currently has active/inactive/archived, not the new lifecycle. `ProductDeliveryRuleResolver` is explicitly an admin/test resolver. `RateQuoteEngine::is_effective()` currently treats `effective_to` inclusively; D02 applies to the new lifecycle only.

A future rule version records logical rule ID, version ID/sequence, family and exact scope, immutable validated payload/digest, revision, effective interval, author/change reason, UTC timestamps and the version it supersedes. Draft content can change under optimistic concurrency; publishing seals that version. Correcting published content creates a new version.

| State/transition | Meaning and acceptance rule |
|---|---|
| Draft → published | Validate content, authority, expected revision, interval and conflicts; accept version, material audit and supersession together. |
| Draft → scheduled | Record a future activation intent; it is not customer eligible yet. |
| Scheduled → published | A bounded activation service checks the same preconditions. Late/failed activation never silently counts as publication. |
| Draft/scheduled → retired | Cancel future use without deleting identity/history. |
| Published → retired | Stop future evaluation at the accepted transition while preserving historical references. |
| Retired → new draft version | Create another version; do not mutate retired/published content. |

New intervals have inclusive start, exclusive end, optional unbounded end, and `end > start`. Reject publication/backdating that would reinterpret an already accepted order. Atomic supersession ends the previous accepted version at the new version's start; if activation fails, no half-published pair is visible. Scheduled processing is idempotent. Customer evaluation considers only durably published, currently effective records; when none is valid, the family supplies a truthful unavailable result rather than using an expired version.

Each family declares its specificity/priority order and whether an equal-precedence collision is invalid or has an explicit tie-break. Do not let SQL row order decide. Conflicting overlap for the same logical rule/scope is rejected unless an explicit family policy permits it. Impact preview uses the same evaluator, captures the proposed version and evaluation instant, and writes no rules, revisions or material audit. Store applied immutable version references in newly created contractual facts; later Return/Refund, promise, pricing and label modules supply their own payloads.

## 2. Interfaces, errors, correlation and repeated requests

### Classification and schema

A catalog entry names a surface, contract version, stable/experimental/internal class, owning application service, permitted callers, input/output schema, authorization, projection, compatibility/deprecation policy and cost-budget owner. Existing Store API namespace/shape and PHP types are inventoried as they exist; they are not newly promised stable by this packet. Experimental changes require an explicit version/notice. Stable removal or mandatory semantic change needs a replacement, compatibility window and reviewed consumer fixtures; its duration is a release-policy decision, not invented here.

DE-API-001/003/004/012 include later route/OpenAPI/public-schema/abuse-control implementation. Wave 1 records schema shapes, authorities and budget requirements; it does not build all those routes or claim those IDs complete. REST, hooks, CLI and UI adapters must eventually call the same application service rather than duplicate domain decisions.

### Proposed internal envelope

Version 1 uses a stable code, `message_key`, allowlisted safe parameters/field violations, request ID, correlation ID, completion outcome and a recovery action. Outcome is one of `accepted`, `rejected`, `unconfirmed`, or `not_applicable`; rejection is not uncertain commitment. Recovery advice is explicit, such as `reload_and_submit`, `retry_original_request`, `reconcile_original_request`, or `contact_support`; a bare retryable boolean is insufficient for uncertain effects. Presentation translates wording without changing the machine code.

Initial proposed codes: `invalid_input`, `not_authorized`, `stale_revision`, `intent_conflict`, `outcome_unknown`, `temporarily_unavailable`, and `unsupported_contract`. These are new internal vocabulary, not replacements for current notices or existing `stale_target` and other surface codes. Do not serialize SQL, exceptions, private IDs, nonces, tokens, addresses or raw payloads into generic errors. Exposing resource existence/revision requires target authorization.

A request ID identifies one attempt; a correlation ID links attempts/work; an idempotency key identifies an effect. None authorizes a write. Proposed identifier format is a validated canonical UUID with generated replacement for absent/invalid correlation input; the raw invalid caller value is not echoed or logged. Child work keeps correlation but has its own request ID. Caller-controlled identifiers have bounded length and cannot select a stored private completion by themselves.

### Idempotency and concurrency

Future namespaces include site, authenticated authority/principal, operation version, exact target identity and key. Canonical intent covers the original operation/version, exact target/row identity, opened revision or other declared precondition, and validated semantic payload. Request/correlation IDs and attempt timestamps are excluded: a new attempt ID cannot change a replay's intent. Canonicalization validates typed input, sorts object keys, preserves meaningful list order, and distinguishes omission/null/zero/false/empty except for declared operation equivalences. Preserve COR-007's accepted omitted-list → empty replacement equivalence and opened row/revision/token envelope. Do not replace its hashing or replay behavior as part of W1-C01.

Authorization is checked before mutation **and before replay disclosure**. Same namespace/key/intent returns recorded completion without a second effect/audit. Changed intent conflicts. Different authorities cannot retrieve one another's completion. Resource revision and immutable row identity still gate new effects; a key is not a stale-write bypass.

Future durable reservation/completion records distinguish pending, accepted, rejected and unconfirmed states. A same-key operation still pending performs no second effect while awaiting its original completion/reconciliation. Known precommit rejection has no accepted mutation. Lost commit acknowledgement is unconfirmed and must reconcile the original key before another effect. Accepted mutation with publication pending remains accepted internally while the externally reported completion is unconfirmed until publication succeeds. Completion and material change/audit require one owned database unit; external effects require their own later delivery contract. This is not a generic exactly-once guarantee.

`ScopedConfigurationAdminService`, repository local-unit/publication methods, connection replacement and direct audit token lookup are strong existing adapter evidence. They are not a generic ledger or public API. W1-C01 uses an in-memory model only; W1-C03 must prove durable uniqueness/concurrency on two connections before any generic production use. Completion expiry is disabled unless an approved operation-specific policy and replay-after-expiry behavior exist. Current scoped completion records are preserved regardless of unrelated log-retention policy.

## 3. Snapshot evolution and historical compatibility

Keep format version, selection-intent contract version, policy version and schema version separate. Reserve optional typed envelopes for quote/pricing policy, promise, fulfillment labels, Return Policy and Refund Policy. Each later writer supplies real order-time identity/version and applicable customer content. Absence means **not recorded**, not a fabricated empty policy, inferred promise or zero price.

| Stored data | New-reader obligation |
|---|---|
| Existing V1 line/package | Same historical facts; absent new fields stay not recorded. |
| Existing V2 line/package | Preserve line-level destination, pickup, identity, group and money facts. |
| V1/V2 with optional extra fields | Recognized extensions use their versioned parser; unknown optional fields are ignored without rewriting JSON. |
| New mandatory format/semantics | Explicit version parser/adapter required; deploy compatible readers before enabling writers. Old-reader rollback is unsafe once unsupported mandatory writes exist unless a reviewed adapter is supplied. |
| Missing JSON | Truthfully unavailable; no reconstruction from current product/configuration. |
| Malformed or partial JSON | Preserve original bytes; safe diagnostic; block actions requiring absent contractual facts. |
| Unsupported format, metadata/JSON version mismatch, selection-contract mismatch | Preserve and report mismatch; never silently interpret as V2. |

Existing `OrderDeliverySnapshotReader` already reports missing/malformed/partial/version mismatch. `write_line_snapshot()` guards nonempty existing line JSON. `handle_order_created()` currently rebuilds and overwrites package JSON/version, so package write-once protection must not be claimed complete. The exact checkout-draft → accepted-placement guard is the separately reserved COR-029 decision. W1-C05 is reader/optional-extension compatibility, not a blanket non-null snapshot freeze or an unapproved package writer repair. Placement-dependent writer activation waits for that decision and its own checkpoint; incomplete checkout drafts must remain distinguishable from accepted history.

Historical shipment readers/planner must continue using stored facts, not the live ECR/rate engine. Configuration deletion or new rules cannot alter historical charge, group, endpoint or policy text. Migration adds readers/storage only when approved; it never backfills missing historical policy facts from current settings.

## 4. Data lifecycle and emergency controls

Every registry entry identifies class, owner, exact stores/prefixes and site scope, preserve/expire policy, expiry clock, references/protection predicates, bounded selector/cursor, hook/group, redaction, deactivation and uninstall disposition. A new store cannot ship without an entry. The registry is schema-neutral; it does not grant bulk reset/uninstall scope beyond the explicit merchant action.

| Class / existing store family | Proposed normal retention / cleanup |
|---|---|
| Woo order line/package snapshot meta; snapshotted policy text | Contractual history: preserve; excluded from ordinary GC. Woo order deletion/privacy workflows retain Woo authority and need an explicit adapter/disposition, not silent plugin GC. |
| Future immutable rule/policy versions | Preserve all policy history from ordinary GC, whether currently referenced or not; configuration removal does not cascade into historical truth. |
| Scoped save/reset completion-bearing `audit_log` records | Preserve. Token lookup is required for COR-007 replay; no blanket age purge. Separate durable migration/expiry approval is required before deleting them. |
| Other material mutation audit | Preserve by default; distinguish from disposable diagnostic logs. No accepted numeric purge period exists. |
| Shipments/items/events | Preserve by default as operational/history truth; not a transient cache. Later dispute/deletion policy remains explicit. |
| Future unaccepted/expired quotes | Lifecycle-defined expiry only after accepted snapshot facts are independently preserved; referenced/accepted records excluded. No quote TTL invented by this packet. |
| Operational logs / transient traces | Configurable redacted retention; new numeric periods require explicit policy. Keep current established local TTLs; no new blanket purge. |
| Same-scope failed admin draft | Register existing 15-minute draft behavior; distinct from permanent completion evidence. |
| Options/configuration/entity rows and user/order meta | Exact ownership and protection inventory; deactivation preserves. Explicit uninstall follows reviewed inventory and reference exclusions. |
| Bulk jobs/items/recipes and scheduler work | Preserve active/unresolved/referenced work; owned hook/group only. P07/P08 recovery/liveness and future terminal-job expiry are separate decisions. |
| Geography generations, pack DB records/files, provider mappings | Preserve promoted/current/referenced truth. Pack reset/file deletion remains separately governed; no reference-based guess grants deletion. |
| Caches, leases, rate-limit counters | Enumerate existing TTLs/namespace ownership; expiring a lease never authorizes deletion of active domain/history records. |

Cleanup first offers a dry-run. Execution records a fixed observed cutoff and finite identity ceiling; rejected/referenced rows advance progress without changing eligibility. Recheck references/ownership immediately before each deletion. Bound each batch and record its cursor atomically with its effects where applicable. A refused batch stays incomplete/failed, never falsely complete. Interrupted work resumes from durable progress. No full-table truncation, shared Action Scheduler-table deletion, global action draining or deletion of another site/module's rows. Numeric budgets and expiry periods belong to the specific implementation/data-class decision.

Existing `Deactivator` preserves domain data; explicit `Uninstaller` and root fallback deletion paths have an allowlist but are not fully equivalent. The implementation checkpoint inventories both and tests the same disposition, rather than assuming existing uninstall completeness. Default deactivation/uninstall preserve sentinels; explicit uninstall requires merchant intent, reviewed exact owned selectors and contractual/reference handling. Global reset UI/backup receipts remain Issue #50 and COR-030 scope.

### Emergency control

Proposed global states are `enabled` and `checkout_suspended`, overriding checkout-affecting module flags. Suspension stops new engine choices/quotes and final managed checkout admission; it does not simply turn off shipping/validation and permit an accidental free/native checkout. Recheck at final admission, including a toggle after page render. Unmanaged Woo commerce remains under Woo authority.

Configuration, policy history, order snapshots and existing shipment operations remain intact; this is not shipment cancellation. Re-enable requires current eligibility/quote revalidation, not reuse of stale selections. Persist state/revision/reason/actor/UTC transition with existing administrative authority/CSRF protection and the common concurrency/replay contract. Diagnostics show effective state, reason and derived impact with authorized/private and safe public projections. Bounded cohort activation is deterministic and consistent across cart/checkout requests; a rollout with undefined cohort policy is not enabled. Actual Classic/Blocks/Store API parity is required before adoption.

## 5. Explanation, material audit and privacy

Reuse ECR field provenance/reasons, coverage diagnostics, server quote result and Woo logger wrapper. They remain internal inputs, not a second pricing/rule engine. `RateQuoteResult::toArray()` and `CoverageMatchDiagnostic` contain internal identities/details and cannot be directly serialized for shoppers. Existing top-level redaction in logger/audit paths is not a universal recursive sanitizer; pre-encoded strings take different repository paths. This inspection does not by itself establish a live public disclosure.

An internal decision context carries contract version, operation, UTC evaluation time, request/correlation IDs, stable reason codes, outcome/completeness, applied configuration/rule versions, bounded provenance and trace, and material change/revision facts where applicable. Trace limits report truncation/incompleteness rather than pretending exhaustive analysis.

| Projection | Allowed purpose / constraints |
|---|---|
| Shopper | Safe reason category, approved labels/estimate, truthful status/recovery and correlation reference; no suppliers/origins/rate cards/costs/margins/routes/private row IDs. |
| Authorized admin | Capability/object-scoped provenance and permitted operational identities; deny on revoked authority before replay/read. |
| Sanitized diagnostic log | Severity, operation, stable safe code, correlation, revision/outcome/completeness; no raw addresses/coordinates/credentials/tokens/payload bodies. |
| Material mutation audit | Sanitized accepted change facts, actor/reason and private completion identity required for replay. Diagnostic logging is not evidence of committed mutation. |

Use schema allowlists and typed projection, not only recursive key blacklists: private data renamed to an unexpected key must also be rejected. Redact at the representation boundary before strings are encoded. Existing purpose-limited access to a shopper's own cart address remains intact; public diagnostics/logs and authenticated address editing are different purposes. Cached/uncached decisions have the same semantic reasons and chosen-version provenance; cache metadata may differ. A rejected audit rejects a mutation that requires material audit; an optional diagnostic log failure must not masquerade as mutation acceptance/rejection.

### Threat and privacy review

| Boundary | Required defense / proposed proof |
|---|---|
| Untrusted resource IDs, revisions, correlation and tokens | Typed validation and server-resolved site/actor/target; caller values cannot authorize effects or select another user's completion. |
| Stale/recreated resource and repeated request | Exact identity/revision plus canonical intent; private replay requires current authorization. |
| Concurrent/unknown commit | Owned durable reservation/unit and reconciliation; no new effect based on an uncertain acknowledgement. |
| Internal objects to public/log output | Allowlisted projections, nested/renamed-field negative fixtures, bounded safe errors; no raw exception or SQL output. |
| Cleanup/uninstall and provider/pack files | Explicit module/site inventory, exact owned selectors/reference checks; no path/URL supplied by untrusted data authorizes deletion. This defines the boundary, not a new importer/reset feature. |
| Quote/geography/diagnostic work cost | Surface inventory must declare budget/rate-limit authority; public route activation waits for its later abuse-control contract. |
| Order history and emergency toggle | Immutable historical reads, managed admission recheck, no free/native fallback; actual runtime cases required beyond static review. |

## Schema and migration decision

The original planning change and W1-C01/W1-C02 were **schema-neutral** against schema6. C03/C04 later received separate owner-approved additive designs and are now integrated at schema8. Optional snapshot envelopes use protected JSON metadata; C05 enabled no mandatory business writer. C06 implementation is integrated schema-neutral; this C07 control design is also schema-neutral. Existing audit rows were not repurposed or migrated by the first checkpoint; separately approved store designs govern later persistence rather than being silently authorized by the initial plan.

Before additive persistence: specify exact tables/indexes and transaction owner; prove two-connection uniqueness/visibility; add a forward, repeatable migration and readiness checks; preserve unrelated/site-owned rows; prove refusal/failure/retry truth using the accepted migration framework. A failed migration blocks only its dependent new feature and does not publish a false schema success. Rollback means disable the new writer and preserve new/old data; do not describe a destructive table drop as safe rollback. Restore old-reader compatibility or block rollback once new mandatory snapshot versions exist. Every approved persistent class receives a retention entry before activation. Owner-approved W1-C03-STORAGE-1 supplies the two per-site InnoDB stores, version7 migration, isolated readiness/no-reconnect ownership, immutable identity/intent and preservation entries. Its implementation checkpoint maps the 20 proof cases to executed tests and separately qualified candidate evidence. C03 is owner-integrated at schema7; Owner-approved C04-RULE-LIFECYCLE-1 supplies three exact additive schema8 stores. The separate C04 implementation checkpoint records execution; the planning document does not implement them.

## Acceptance matrix — C01–C06 integrated, C07 implementation authorized

The planning PR did not execute these future cases. C01 implements T01–T04 and is integrated after exact-candidate qualification. C02 implements T05–T06 with explicit schemas, current authorization before loading and disclosure, real cached/fresh configuration/coverage comparisons and legacy fallback fixtures. Local PHP 8.3.6: 106 new C02 tests / 461 new assertions; combined Contracts 208 / 827; complete configured suite 1743 / 10675 / 1 skip, PASS. Finite independent privacy/bounds/reference probes passed. Candidate CI/native/HTTP proof is recorded against the implementation PR's actual head, separately from local execution. C03's T07/T08/T20/T21 cases are qualified in its integrated implementation checkpoint and actual-master receipts; C04's24 cases under T09–T11/T20/T21 are qualified in integrated PR#69; C05 T12–T14/T21 is qualified and owner-integrated in PR#71. C06’s30 obligations under T15–T17/T20/T21 are executed and qualified in integrated PR#75. C07’s approved30 cases under T18–T21 are being executed by its implementation checkpoint; they were NOT EXECUTED by design CI. Earlier SQL/WordPress evidence stays separate. Audit projection tests do not prove durable append/transaction adoption.

| Case | Required proof | Checkpoint |
|---|---|---|
| T01 | Catalog rejects unclassified exports, duplicate versions and invalid deprecation declarations; existing public shapes preserved. | C01 |
| T02 | Stable safe codes survive localization; generated/validated IDs propagate; malformed/oversized correlation is not echoed; no private/SQL/exception error data. | C01 |
| T03 | Object-key reordering is equivalent; ordered lists and omitted/null/zero/false/empty retain declared distinctions; current scoped empty-list equivalence is unchanged. | C01 |
| T04 | In-memory operation model distinguishes replay/conflict/rejection/unknown; does not claim generic durable production deduplication. | C01 |
| T05 | Shopper/log projections reject nested and renamed private data and unknown fields; authorized admin/address-editor purposes remain distinct. | C02 |
| T06 | Cached/uncached reasons and version provenance agree; truncated/incomplete diagnostics remain explicitly incomplete. | C02 |
| T07 | Two connections and a fresh process prove one durable acceptance, cross-authority isolation and no duplicate write/audit under replay. | C03 |
| T08 | Audit rejection, unsent commit, lost acknowledgement and publication pending have distinct outcomes; unknown replay reconciles before an effect. | C03 |
| T09 | Exact interval boundaries, invalid windows, draft/retired/scheduled exclusion and failed activation produce defined truthful results. | C04 |
| T10 | Concurrent publish/supersede cannot expose mixed versions or undeclared overlap; immutable payload/history retained. | C04 |
| T11 | Impact preview uses the same evaluator and changes no row/revision/material audit; explicit precedence/conflict is deterministic. | C04 |
| T12 | V1/V2 fixtures and their bytes remain unchanged after new readers, configuration changes/deletion and schema upgrade; shipment reads stay historical. | C05 |
| T13 | Unknown optional fields are compatible; unknown mandatory format, partial/malformed/mismatched data fails truthfully without rewriting. | C05 |
| T14 | New quote/policy/label fields are real typed facts or absent, never fabricated for old orders; mandatory writer activation/rollback is gated. | C05 |
| T15 | Cleanup skips snapshots/policy/completion/reference records; replay after cleanup pressure and >100 later audits still causes no second scoped effect. | C06 |
| T16 | Fixed-cutoff/ceiling cleanup is bounded and resumes after a refused batch; references rechecked; foreign rows/shared AS tables/pack identities retained. | C06 |
| T17 | Default deactivate/uninstall preserve sentinels; explicit allowlist behavior agrees with and without autoload, retaining governed history/references. | C06 |
| T18 | Toggle after render blocks final managed admission in Classic/Blocks/Store API without a zero-charge fallback; ordinary Woo and existing history/shipments preserved. | C07 |
| T19 | Permission/stale/repeat/cross-request object-cache cases are truthful; re-enable revalidates old choices; cohort behavior consistent. | C07 |
| T20 | Failed/additive migration does not publish schema success; retry preserves old rows/snapshots; writer disable/rollback compatibility proven. | C03–C07 where persistence applies |
| T21 | Final adopted checkpoint maps actual IDs/files/proofs to exact candidate; required CI and relevant native/real-DB/Woo cases pass. | Each adopted checkpoint |

Existing preservation sources: configuration completion/caller-authority/versioning/resolver tests; `OrderDeliverySnapshotV2Test`; `OrderDeliverySnapshotPersisterStoreApiTest`; `HistoricalShipmentPlannerTest`; `SchemaUpgradeDoesNotTouchSnapshotsTest`; `SchemaV4InspectionTest`; `BlocksPublicPayloadTest`; coverage diagnostic tests. The accepted `cor005-real-db` 2/19 and `cor007-real-db` 12/105 are earlier local proofs outside default MariaDB CI; they are not new Wave 1 execution.

## Seven implementation checkpoints

W1-C01–C06 are **OWNER ACCEPTED / INTEGRATED**, tracked by completed Issues#58/#60/#64/#68/#70/#74 and merged PRs#59/#61/#65/#69/#71/#75. C07’s design is **OWNER ACCEPTED / INTEGRATED**, #76 completed through PR#77; its **AUTHORIZED IMPLEMENTATION** is tracked by #78. Independent fixture/review work proceeds in parallel under exclusive file leases; root owns shared integration files. Completion is recorded against each actual candidate.

| Unit | Concrete output and reuse | Dependency / schema / central lease | Acceptance |
|---|---|---|---|
| W1-C01 — common internal contracts | Classification catalog; internal error/request/outcome value types; canonical-intent rules and in-memory model fixtures. Reserve new `src/Domain/Contracts/` and `tests/Unit/Contracts/` only. No REST routes, bootstrap wiring, scoped-save retrofit or production ledger. | Packet/core decisions accepted and C01 specifically authorized; no schema. Codex/WS3 shared contract lease; AI Woo/frontend reviews of future adapter boundaries. | T01–T04; existing surfaces unchanged; exact-head required CI. **Recommended first implementation.** |
| W1-C02 — explanation projections | Typed decision context, safe shopper/admin/log projections and bounded trace contract; wrappers around existing provenance/diagnostics. Reserve new contract files and focused tests; later audit/logger adoption uses explicit additional leases. | C01; no schema. One domain/interface editor; privacy/presentation review. | T05–T06; no second pricing engine; no arbitrary object serialization. |
| W1-C03 — durable operation primitives | Approved additive store/uniqueness, transaction/outcome/reconcile contract for new generic operations. Reuse transaction failure distinctions; preserve current scoped audit completions. No claim of external exactly-once delivery. | C01–C02; separately reviewed additive migration/index/retention design and mutation adoption scope. Sole schema/persistence editor. | T07–T08/T20; two connections/fresh process; no old audit cleanup or silent retrofit. |
| W1-C04 — rule lifecycle | Version records/state/interval/conflict evaluator, read-only impact preview and atomic publication/activation. Reuse ECR as adapter input, not replacement. | C01–C03; additive persistence explicitly reviewed. Rule/schema lease; [approved C04 design](W1-C04-RULE-LIFECYCLE-DESIGN-2026-10-06.md) specifies three schema8 stores, per-family guards, time/conflict/preview and24 cases mapped in its separate implementation checkpoint. Family precedence frozen before an adopter. | T09–T11/T20; existing Rate Card date/precedence unchanged. |
| W1-C05 — snapshot reader/extensions | V1/V2 compatibility fixtures and optional typed extension dispatch using Woo CRUD/protected meta. Reserve `src/Application/Order/OrderDeliverySnapshot*`, historical reader/tests through one editor. | C01–C02; no schema expected for optional envelopes. Mandatory formats and package finalization writer wait for explicit COR-029 disposition and separate approval. | T12–T14; no historical rewrite; no blank non-null draft freeze. |
| W1-C06 — data registry/cleanup | [Implemented](W1-C06-IMPLEMENTATION-2026-10-07.md) exact preservation registry, managed120s geography cache, zero-domain-drop shared uninstall, bounded preview/atomic worker. | C03 native ownership; schema8 unchanged. Fixed cutoff/ceiling,200inspect/50delete/2s/1000-ID windows/300s cadence; exact-site handlers stopped, shared AS rows retained. | Thirty obligations under T15–T17/T20/T21 qualified; owner-integrated PR#75. Reset/COR030, pack deletion and unrelated expiry remain separate. |
| W1-C07 — emergency/adoption | [Approved exact design](W1-C07-EMERGENCY-CONTROL-DESIGN-2026-10-07.md) and [implementation](W1-C07-IMPLEMENTATION-2026-10-07.md): coordinated control, C03 state/event/completion, independent ownership/cache epochs, Classic/Blocks/StoreAPI and all unpaid managed-order payment admission, privacy diagnostics. | One strict preserved option, no schema change. Sole bootstrap/settings/Woo integration editor. Approved current-site all-or-none policy, bounded current-lock admission and30 implementation obligations. | T18–T21, implementation qualification pending. Pending-payment pause explicitly approved; history preserved and physical/runtime parity required. |

Each future issue must name its exact candidate/base, selected requirement rows, permitted files, environment, migration permission, failure cases and completion evidence. Do not close whole requirement families after one primitive checkpoint. Acceptance of C01 does not automatically start all seven or change a reserved business contract.

## Remaining decisions and approval boundary

The packet recommends D01–D12 and W1-C01 as the first schema-neutral implementation. Core vocabulary/interval/collision proposals are reviewable here. Later activation still needs concrete storage/index design, specific rule-family precedence, data-class expiry/batch budgets, rollout cohort policy, stable public-surface promotion and deprecation window. Default preservation and internal-only classification permit C01 without inventing those later values.

Reserved business contracts remain separate: quote/group tariff/tax/promotion economics; source-independent destination/handoff; WP floor/embed transition; inactive/invalid recovery COR-012; portability COR-021; placement guard COR-029; protected-delete retention COR-030; global reset Issue #50; COR-008/009 atomic/fencing boundaries; P07/P08. COR-005/COR-007 are already accepted and implemented; they are not unanswered choices in this packet. These reservations can block their dependent future adapters, not schema-neutral C01.

[Issue #48](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/48) originally stopped at a reviewable planning package. Later explicit owner decisions authorized and integrated C01/C02; PRs #57/#59/#61 are merged. Issue #48 remains open; C03 design/implementation Issues #62/#64 are completed. C03–C05 are qualified and owner-integrated. PRs#69/#71 are merged; #66/#68/#70 completed. #72/#74 completed and PR#73/#75 merged C06 design/implementation. #76 completed the accepted C07 design; #78 tracks authorized implementation. After complete implementation qualification, the next owner decision is “Approve W1-C07 for integration.” Design CI remains separate from runtime-adopter execution.
