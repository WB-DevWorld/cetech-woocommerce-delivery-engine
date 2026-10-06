# W1-C04 rule lifecycle — W1-C04-RULE-LIFECYCLE-1

Status: **PROPOSED FOR OWNER APPROVAL — DESIGN ONLY**. At 2026-10-06 21:47:31 UTC the owner instructed: “Approve W1-C03 for integration and prepare W1-C04’s rule-lifecycle design.” Qualified [PR #65](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/65) head `20392bcb852b31451ec703ec0eed45067fb6be2c` merged normally as `75db93024464772673c6572455f686d3b5be37c9`, tree `79e927b5466940bf91afaa431c76a7f2d37b750e`, identical to the candidate. Implementation Issue #64 is completed. [Issue #66](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/66) tracks this design under #48. C01–C03 are integrated. This packet adds no production code, DDL or adopter; current development schema is **7**, production PHP **539** files.

## What the next implementation will accomplish

C04 supplies internal services for creating and editing drafts, sealing scheduled versions, publishing or retiring versions, evaluating eligibility/conflicts, and previewing a proposed change. Three new stores preserve rule identity and version history. C03 owns each accepted database change, material event and completion together. A scheduled change becomes effective only after an activation actually commits; a planned date is not proof of activation.

This is a usable lifecycle foundation with a finite injectable family contract. Production family/adopter registries remain **empty**. Existing Rate Cards, scoped configuration, product rules, Return/Refund, promises and labels are not adopted or rewritten. Their separate policy and runtime adapters follow later. No customer route, admin form, scheduler hook or checkout writer is introduced by the foundation. Owner approval of this packet authorizes its exact schema8 and implementation/proof scope, not a universal business precedence policy.

## Authority and inspected reuse

The accepted [Wave 1 plan](WAVE1-CONTRACT-PLAN-2026-10-06.md), D01–D03/D06/D08/D11/D12 and T09–T11/T20/T21 govern the proposal. Frozen `CAPABILITY-REGISTRY.yaml` entries DE-RULE-001–007 govern lifecycle, metadata, eligibility, precedence, history, preview and preservation. `PRODUCT-CONSTITUTION.md` retains one authority, field-level inheritance, separate Return/Refund systems and labels that do not determine fulfillment or economics. Frozen IDs/conformance/release intent are unchanged.

| Inspected source | Reuse or preserved boundary |
|---|---|
| `src/Application/Operation/OperationCoordinator.php`, `src/Domain/Operation/OperationProfile.php` | Owned no-reconnect session; reservation, locking/current reads, mutation/event/completion; read-only reconciliation. |
| `OperationSchema.php`, `OperationMaterialEvent.php` in `src/Domain/Operation/` | Finite typed receipt/event facts; positive before revision and strictly increasing after revision. No arbitrary payload/date/free-text serialization. |
| `src/Infrastructure/Persistence/OperationStoreSchema.php`, `OperationStoreReadiness.php`, `src/Core/Versioning/MigrationRunner.php` | Verified additive migration, authoritative status/options and native transaction readiness. |
| `src/Application/ProductRule/ProductDeliveryRuleResolver.php` | Specificity descending, priority ascending, rule-ID ascending; explicitly admin/test only. |
| `src/Application/RateQuote/RateQuoteEngine.php`, `src/Application/Calculator/AdminRateCardTester.php` | Specificity descending, priority ascending, card-ID ascending; legacy inclusive `effective_to` remains unchanged. |
| `src/Application/Destination/DestinationZoneMatcher.php` | Priority ascending before specificity; natural name/code order and ID fallback. Not the product/rate comparator. |
| `src/Application/Configuration/EffectiveConfigurationResolver.php` | Global → product → variation; only explicit field/collection instructions override. Remains the ECR authority. |
| `src/Application/Contracts/DecisionContextAdapter.php`, `src/Domain/Contracts/DecisionContext.php` | Read-only existing evidence inputs. Fixed product targets/operation vocabulary cannot be repurposed as arbitrary rule targets. |

The policy choice below is an inference from these sources and the accepted plan. It requires implementation proofs; source inspection and green design CI do not execute C04.

## Identities, family declarations and bounded codecs

Keep site/family identity, logical-rule UUID, immutable version UUID/sequence, mutable family/logical/version revisions, payload/scope format, C01 operation version and database schema separate. `config_version`, `RecordStatus` and existing Rate Card IDs do not acquire new meanings. All selectors contain the resolved current site; a UUID/hash never authorizes access.

`RuleFamilyProfile` declares a fixed ASCII family code, contract version and policy digest; strict scope/payload codecs; current authorization; matching and scope-overlap analysis; comparator directions; equal-rank collision policy; validated fallback/unavailable result; input-evidence adapter and cost limits. Registry instances accept at most64 unique typed profiles, reject duplicates/changed declarations, and default to empty. No wildcard/unregistered-family fallback. A future policy change needs a supported new declaration/reader and reviewed adoption; the stored guard policy digest must match before use.

The first implementation's fixture family is `fixture_availability_v1`, confined to tests: exact synthetic global/product/variation subjects with a supplied synthetic parent relationship, payload `{availability: allow|deny}`, specificity descending, signed numeric priority ascending. Equal rank for overlapping subjects is a conflict, not an arbitrary UUID/SQL-order winner. Different logical identities with overlapping applicability use that same policy; an explicit same-logical successor is the only permitted cutover overlap. Unknown overlap refuses. Existing business comparators above are preserved and are not replaced by this fixture policy.

Scope/payload objects allow only profile-declared fields/types; scope ≤4096 encoded bytes, payload ≤16384, ≤128 schema nodes, depth≤5 and ≤32 fields/object. Canonical serialization sorts object keys, preserves meaningful list order and rejects ambiguous or unsupported values; C01 intent rules remain authoritative. Hashes use separate versioned prefixes `cetech-rule-scope-v1:` and `cetech-rule-content-v1:` over those canonical bytes. Content includes payload format/bytes, authored interval/start mode, priority, intended predecessor and family-policy digest. Sealing detaches PHP references. All persisted JSON is private, never a generic public/log serializer. No raw request token/nonce/cookie/credential is stored in the rule tables.

Require original positive WordPress author ID, server-resolved actor authority and a private nonempty UTF-8 change reason of at most512 bytes without control characters. Free-text reason is governed private administrative data, not echoed into generic errors/logs/receipts. Material events use finite reasons instead. Deleted/revoked authors do not erase history; activation authorization is rechecked as specified below.

## Concrete additive storage proposal

Propose schema **8** and verifiable migration `20261006214731_create_rule_lifecycle_tables`. These are declarations for approval, not SQL executed by this task. Physical names are `{wpdb.prefix}delivery_engine_` plus these three suffixes. Pin InnoDB; use the resolved site charset/collation for JSON/reason text. IDs/sequences/revisions are unsigned64-bit SQL values but must fit positive PHP integers; refuse overflow. UUIDs/digests/codes have explicit ASCII binary collation and exact validation. Every unnamed default is absent; nullable columns default NULL. No foreign keys/cascades, prefix unique keys or implicit row-order precedence.

```sql
CREATE TABLE {prefix}rule_family_guards (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint unsigned NOT NULL,
  family_code varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  family_format smallint unsigned NOT NULL DEFAULT 1,
  policy_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision bigint unsigned NOT NULL DEFAULT 1,
  created_at datetime(6) NOT NULL,
  updated_at datetime(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY site_family (site_id, family_code)
) ENGINE=InnoDB;

CREATE TABLE {prefix}logical_rules (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint unsigned NOT NULL,
  family_guard_id bigint unsigned NOT NULL,
  logical_uuid char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scope_format smallint unsigned NOT NULL DEFAULT 1,
  scope_json longtext NOT NULL,
  scope_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision bigint unsigned NOT NULL DEFAULT 1,
  last_version_sequence bigint unsigned NOT NULL DEFAULT 0,
  current_published_version_id bigint unsigned NULL,
  draft_version_id bigint unsigned NULL,
  scheduled_version_id bigint unsigned NULL,
  created_at datetime(6) NOT NULL,
  updated_at datetime(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY site_uuid (site_id, logical_uuid),
  KEY family_rules (site_id, family_guard_id, id)
) ENGINE=InnoDB;

CREATE TABLE {prefix}rule_versions (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint unsigned NOT NULL,
  logical_rule_id bigint unsigned NOT NULL,
  version_uuid char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  version_sequence bigint unsigned NOT NULL,
  row_revision bigint unsigned NOT NULL DEFAULT 1,
  state varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload_format smallint unsigned NOT NULL DEFAULT 1,
  payload_json longtext NOT NULL,
  content_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  priority int NOT NULL,
  start_mode varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  effective_from datetime(6) NULL,
  effective_until datetime(6) NULL,
  author_user_id bigint unsigned NOT NULL,
  change_reason text NOT NULL,
  supersedes_version_id bigint unsigned NULL,
  scheduled_revision bigint unsigned NULL,
  scheduled_logical_revision bigint unsigned NULL,
  scheduled_predecessor_row_revision bigint unsigned NULL,
  sealed_at datetime(6) NULL,
  scheduled_at datetime(6) NULL,
  published_at datetime(6) NULL,
  retired_at datetime(6) NULL,
  created_at datetime(6) NOT NULL,
  updated_at datetime(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY site_version_uuid (site_id, version_uuid),
  UNIQUE KEY logical_sequence (site_id, logical_rule_id, version_sequence),
  KEY activation_due (site_id, state, effective_from, id),
  KEY logical_eligibility (site_id, logical_rule_id, state, effective_from, id)
) ENGINE=InnoDB;
```

Application verification enforces same-site links, exact registered family/digest/format, immutable logical scope/family, increasing sequences, pointer membership and state relationships. One logical rule has at most one draft or scheduled version, and one published head. A scheduled version occupies the editing slot; cancel it before creating its replacement. A published pointer may refer to an expired version; it is a consistency pointer, not eligibility or an expired-rule fallback. Retiring its head clears the pointer. A retired version is never made published again. Corrections create a new version under the same logical identity; changing scope/family creates a new logical identity.

Family guards serialize all mutations in one site/family, including different rules/scopes. The first mutating create may insert the missing guard with revision1 under the full unique key, then current-lock it. Duplicate-key contention waits/reloads that exact guard; other errors refuse. An acknowledged changed command advances its revision once, including first creation1→2, so C03 audit never needs an invalid0→1. Guard bootstrap is part of the same changed unit; previews/readiness/evaluation do not insert guards. C03 `operation_changes` supplies immutable transition history; no fourth audit store is proposed.

## State, intervals and accepted time

UTC microsecond timestamps are canonical; adapters accept an explicit UTC instant or convert a declared offset before validation, never guess a local ambiguous time. `start_mode` is `immediate` or `at`. Immediate drafts have `effective_from=NULL`; sealing on immediate publication fills it with the effect-unit acceptance instant, then computes the final content hash. `at` requires an authored start. An explicit end must be strictly later than the start and, on publication, strictly later than actual acceptance. Scheduling requires a future `at` start; immediate publication refuses future starts and caller backdating. Actual late activation is the declared exception below, preserving authored intent but never backdating live use.

| Transition | Preconditions / accepted effect |
|---|---|
| New/edit draft | Current authority, exact logical/version identity and opened revisions; strict content. Draft edits may replace unsealed bytes; semantic no-change returns not_applicable with no second material event. |
| Draft → scheduled | Future valid interval; complete conflict evaluation; bind predecessor/current logical identity. Seal payload, authored interval, reason/author, priority and content hash; store scheduling acceptance. No predecessor retirement/publication. |
| Draft → published | Immediate mode or exact non-backdated current start; complete collision check. Seal content, set published_at and new head; supersede the declared current predecessor at that same instant. |
| Scheduled → published | Revalidate due time, current scheduled/logical/predecessor identities, current authority and all conflicts. Set published_at and cut over only in the accepted unit. |
| Draft/scheduled → retired | Cancel the exact version, preserve bytes/identity; free the draft/scheduled slot. No published predecessor changes. |
| Published → retired | Set retired_at at the accepted instant and clear its current head; preserve content/declared interval/history. |
| Retired → another draft | Allocate another immutable version UUID/sequence. Never reopen or reseal the old version. |

Live eligibility requires durably published state, valid declared content and `max(effective_from, published_at) ≤ evaluated_at < effective_until` when the end exists. An accepted retirement excludes the record from subsequent live selection. Historical cutover facts use the half-open end `min(effective_until, retired_at)`, with absent ends unbounded, without changing authored dates. Historical order/shipment readers use their stored applied identity and facts; they never ask current state which rule used to apply.

The effect unit captures one authoritative server UTC instant **after locks and validation**, checks it against the stored previous transition time (clock regression refuses), and uses it consistently for new publication, predecessor retirement, revisions and audit facts. Once committed, this is an immutable accepted transition fact. The final reader sees the whole committed cutover. No backdating can reinterpret an already accepted order; C04 does not add an order writer.

| Requested timeline | Actual result |
|---|---|
| Schedule10:00, activate10:00 | New version eligible from10:00. |
| Schedule10:00, activate10:07 | Authored start remains10:00; live start is10:07, late=true. Prior valid version remains until the10:07 cutover. |
| Schedule ends10:05, attempt10:07 | Reject as activation_expired; no new eligibility or predecessor change. It remains scheduled/excluded until an explicit cancellation. |
| Prior expires10:03, activation10:07 | Truthful unavailability between10:03 and10:07; no extension/resurrection of the prior version. |
| Activation too early/fails | Known rejection when no effect is proved; not terminal no-change. Original activation request can be retried when due. |
| Possibly committed activation | Unconfirmed; reconcile original request on a fresh current-lock session before another effect. Never infer activation from its scheduled date. |

Retirement/activation are domain mutations, not C03's optional post-commit `publish()` callback. These profiles use `publication_schema=null` / publication_state=none: authoritative rule selection reads the committed database generation, with no global option/cache publisher. A later adopter may add a separately approved cache/publication contract. A server clock does not independently change state: an ended published version becomes ineligible by interval evaluation; it remains preserved without an automatic retirement write.

## Commands, transaction and activation boundary

Proposed internal operation names at version1: `rule.draft.create`, `rule.draft.edit`, `rule.schedule`, `rule.publish`, `rule.retire`, `rule.activate`. Create includes a server-issued original logical/version UUID and exact absence precondition; retry retains that envelope. Identity includes C01 site/authority/principal/operation/exact target/token. Canonical intent includes policy/scope/content digest, original UUIDs/row identities, opened logical/version/family revisions for user commands, start mode/interval, predecessor and reason. Request/correlation IDs and the eventual execution instant are excluded. Changed intent conflicts; new attempts cannot select another authority's receipt.

Activation has its own stable envelope: original sealed version UUID/digest, logical revision after scheduling and predecessor identity/revision. Scheduling atomically stores `scheduled_revision` as the version's new row_revision, `scheduled_logical_revision` as the logical row's new revision, and `scheduled_predecessor_row_revision` as the locked intended predecessor's original revision. The predecessor revision is NULL exactly when supersedes_version_id is NULL; other scheduling fields are positive and mandatory for every ever-scheduled version. Preserve them, scheduled_at and sealed content through publication/retirement. Every fresh-process activation/reconciliation builds the original envelope from these sealed facts and immutable identities, never today's revisions. Its token is derived from version UUID plus scheduled_revision, under an explicit service authority/site/family namespace. First activation requires current version/logical/predecessor rows still match these original preconditions; accepted replay uses C03's original completion. Do **not** freeze scheduling-time family revision in this envelope: another unrelated rule's edit does not automatically invalidate the schedule. Activation still locks the current guard and fully rechecks conflicts. Recheck both current activation authority and the original scheduling author's required object grant; revocation prevents a new effect, never deletes already accepted truth. External caller tokens are not service authorization. User-facing adapters later own capability/nonce checks; no UI/REST adapter is built by C04.

Lock order: C03 operation record → full site/family guard → affected logical rows by ascending ID → affected versions by ascending ID. Use only the supplied C03 session and current locking reads; an earlier snapshot/prewarmed cache cannot decide the current identity/revision or absence of overlap. Conflicts across other logical rules are checked while holding the family guard. No family-wide transaction includes a catalog walk, remote call, DDL or WordPress transparent reconnect. Native certified default remains the C03 verifiable MariaDB owner; unsupported/custom routing requires its equivalent owner and separate qualification.

Within that unit accept new/edited/sealed bytes, state/head/predecessor cutover, each affected row revision and one family revision increment, sanitized material event and C03 completion together. Refused event/receipt/commit rolls the whole unit back when that can be proved. Lost acknowledgement or failed rollback stays unconfirmed. Reconcile invokes no mutator; replay returns immutable completion without a second version, retirement, revision or event. A replay after later versions returns the original accepted result, not today's head. Authority is rechecked before sensitive lookup and disclosure.

Receipt facts use finite family/state/action/reason codes, positive logical/version IDs, UUIDs, family and row revisions, content/scope digests, `late` boolean and accepted UTC epoch-microsecond integer. A predecessor ID0 explicitly means absent; never fabricate a UUID. Material event revision is guard before→after, with finite changed fields (`draft`, `sealed_content`, `schedule`, `publication`, `retirement`, `head`). All are representable by the existing `OperationSchema`; raw payload, reason text, exception/SQL and full draft context are excluded. Immediate-mode intent keeps the original NULL start and draft command digest across retries; the separately sealed acceptance-time digest is a result fact, never a replacement request intent. `validate_accepted_facts` ties result, event, namespace and every accepted revision/identity; unknown/corrupt links refuse outcome_unknown without rewriting data.

Provide a bounded application `scan_due_page` and `activate_original` service, with ≤25 scheduled candidates per call, fixed observed ID ceiling and keyset `(effective_from,id)`. The page contains original activation envelopes, not proof of successful effects. Each activation owns its separate C03 unit; an unknown result is reconciled, not blindly retried or counted published. Caller carries observed ceiling/cursor; no durable generic queue/progress store or unattended execution claim. C04 registers no Action Scheduler hook/cron, host setting or fallback HTTP loop. A future queue adopter must persist truthful cursor/recovery/liveness under its own reviewed scope. This boundary does not implement P07/P08.

## Evaluator, conflicts and read-only impact preview

The pure `RuleLifecycleEvaluator` filters ineligible states/intervals, validates complete candidate evidence, applies the registered family's matcher/comparator/collision/fallback, and returns a typed `RuleDecision` with selected immutable version reference, accepted family revision, evaluation instant, finite reasons and completeness. No price/tax/fulfillment engine is duplicated. ECR/coverage/quote context is captured through read-only adapters when the family declares that input; legacy evidence keeps its limitations and missing rule versions remain missing.

At most1000 candidate versions per family evaluation/conflict check, fetched with one extra row to detect overflow. At most100 explicit preview subjects; no full catalog enumeration. Above either bound, unknown codec/overlap or incomplete evidence returns incomplete/unavailable and blocks publication rather than selecting from a truncated set. Publication/scheduling examine published and scheduled candidates over the relevant intervals, including cross-scope matches. Declared same-logical predecessor overlap is allowed only by atomic successor cutover; all other overlap uses the family collision policy. A future higher budget or broad catalog preview needs its bounded design rather than removing these limits.

Use the same evaluator on captured baseline candidates and an explicit hypothetical overlay of the proposed version/cutover, at one UTC instant. Preview identifies the hypothetical input, opened family/logical/version revisions, candidate digest and interval; future-time evaluation reports hypothetical_due, never confirmed scheduled activation. Authorize before capture and disclosure. Use a read-only consistent database view or one coherent joined query; do not mix generations across reads. Before mutation, reload current locks, compare original user preconditions and rerun the evaluator: preview is advisory, not stale-write permission. It writes no rules, guard, revisions, operation reservation/completion, material audit or durable trace.

Create separate allowlisted `RuleDecision`/`RuleImpactPreview` projections. Authorized admin can see declared rule/version/conflict identities and bounded private explanations. Generic safe output/logs carry finite reasons, completeness and correlation only, with no rule IDs/payloads/scope JSON/reason text. Unknown fields, nested/renamed private input and direct generic JSON serialization refuse. Do not widen C02's fixed product `DecisionContext` or use its `toArray()` as a universal rule serializer. C04's selected reference is available for later approved snapshot writers; DE-RULE-005 order-time adoption remains incomplete until that writer's separate checkpoint.

## Migration, readiness, retention and compatibility

Reserve `RuleLifecycleSchema` and `RuleLifecycleReadiness`. Before `dbDelta`, inspect all three existing tables, exact engine/site collation/columns/full ordered indexes/no prefix, bounded strict row bytes, same-site relationships/pointers/digests/states and supported profile formats. Unknown/corrupt or populated incompatible/partial structures refuse before ALTER. Only absent structures or explicitly compatible **empty** first-install partial tables may receive named missing columns/indexes. No automatic engine conversion, shrinking, merging, payload reinterpretation or legacy rule backfill. Verify again before publishing schema8 and migration success; partial DDL is durable and is not described as rolled back. Retry completes only approved additive missing structures.

Runtime readiness reads authoritative native option/status data and exact physical definitions; both C03 and C04 tables must be ready before lifecycle writes. Population checks are bounded/fixed-ceiling migration/read diagnostics, not a whole-history scan on every request. A failed schema8 migration leaves the marker7 and legacy data intact, but the shared latest migration status becomes failed: **existing C03 readiness also refuses generic operations until runner reconciliation**. Legacy configuration/quote services remain available. No change to C03 readiness/status semantics is silently promised. Denied version/status publication stays unavailable/unconfirmed; persisted8 with failed status is reconciled by verification without lowering markers or rerunning destructive DDL.

| New class | Retention / deactivate / both uninstall paths / writer-disable rollback |
|---|---|
| rule_family_guards | Preserve identity, declared policy and generation; no age-based takeover/deletion. |
| logical_rules | Preserve identity, scope and pointers/history linkage; no cascade on product/user deletion. |
| rule_versions | Preserve every draft/scheduled/published/retired record, authored/accepted interval and payload/history; no ordinary GC even when unreferenced. |
| C03 completion/material-event references | Preserve original replay/history independently of diagnostic retention. |

Dedicated inventory entries satisfy these new classes' DE-DATA-001 obligation without claiming the C06 full inventory complete. Keep suffixes outside `ConfigurationTables::all_suffixes()` and its legacy global missing-table/uninstall allowlist. Neither explicit legacy uninstall path deletes new stores. Disabling writers preserves schema8/data; old schema7 code may ignore the new tables but must never republish or reinterpret their records. No new mandatory order snapshot format/backfill or destructive down migration. Partial restore of rules and their C03 acceptance/event stores cannot establish a consistent history; backup/restore is not this foundation's guarantee.

## Implementation lease and finite proof matrix

After approval create one implementation child from this design's actual integrated candidate. Root solely leases SchemaVersion/development identity, migration registration, narrow current package/native schema assertions, blocking CI real-DB selection and central docs. New exclusive paths: `src/Domain/RuleLifecycle/**`, `src/Application/RuleLifecycle/**`, `src/Infrastructure/Persistence/RuleLifecycleSchema.php`, `RuleLifecycleReadiness.php`, `WpdbRuleLifecycleRepository.php`, one new migration; focused `tests/Unit/RuleLifecycle/**`, `tests/Integration/RuleLifecycle/**`, fixture support and native qualification module. Reuse existing C03 APIs without weakening them. No unrelated resolver/RateCard/checkout/audit retrofit. Source-dependent tests may raise production counts;539 is this design baseline, not a cap.

Group `rule-lifecycle-real-db` must be required in the blocking default MariaDB CI job, independently from required `operation-store-real-db` and the26 existing geography cases. Exact group count/case assertion is installed with implementation; no skip may be promoted to proof. Native WordPress modules load real wp-load.php without unit bootstrap, prove physical dbDelta/options/cache/lifecycle behavior and retain235 existing native/66 HTTP cases. HTTP20-second bound and qualified OPcache-on/JIT-disabled listener profile stay unchanged. All cases below are **planned, not executed by this design**.

| Case | Required implementation proof | Plan |
|---|---|---|
| C04-01 | Exact UTC start inclusive/end exclusive, nullable end, invalid/equal/inverted windows, immediate/future/backdated/clock-regression refusals. | T09 |
| C04-02 | Draft/scheduled/retired excluded; expired published head gives unavailable without fallback/resurrection. | T09 |
| C04-03 | Scheduling seals detached content/interval/priority/reason; editing sealed versions refuses; replacement has new UUID/sequence. | T09/T10 |
| C04-04 | Due/early/late/expired activation timelines above; early original-token retry succeeds when due, no fictitious past eligibility. | T09 |
| C04-05 | Failed/conflicting/revoked activation preserves predecessor/window/heads; independently expired predecessor remains unavailable. | T09/T10 |
| C04-06 | Two real connections/process barriers publish different tokens against one original rule revision: one acceptance, one stale, no duplicate event. | T10 |
| C04-07 | Different logical rules/hierarchical scopes contend through the same family guard, including missing-first-guard race; no overlap gap. | T10 |
| C04-08 | Membership read view/prewarmed input before newer commit cannot defeat current-lock identity/policy/predecessor checks; a predecessor retired after scheduling makes the original activation stale. | T10 |
| C04-09 | Atomic successor/predecessor cutoff/head/revisions/event/completion under concurrent reader; no mixed cutover or shortened old window on failure. | T10 |
| C04-10 | Rejected event/completion/known unsent COMMIT leaves all rule effects absent; same-original-envelope recovery retains rejection/acceptance truth. | T10 |
| C04-11 | Real committed acknowledgement loss, process death before/after commit and active-session lock timeout reconcile without a second transition/event. | T10 |
| C04-12 | Fresh OS process replays original completion after101 later material events/versions; current head/history unaffected. | T10 |
| C04-13 | Namespace/site/principal isolation; revoke before lookup/disclosure/activation; recreated/changed target cannot bypass original preconditions. | T10 |
| C04-14 | Family-specific comparator directions, shuffled SQL/candidate order, equal-rank/cross-scope collisions and unknown-policy refusal deterministic. | T11 |
| C04-15 | Same evaluator yields baseline/overlay preview parity at same captured instant; byte/revision/operation/event counts unchanged. | T11 |
| C04-16 | Preview stale after capture refuses mutation; hypothetical future preview does not count as published; unrelated family edit does not stale activation's narrower envelope. Fresh process after changed logical/predecessor rows reconstructs the unchanged sealed scheduling envelope/token, not newer preconditions. | T10/T11 |
| C04-17 | Candidate1001/subject101, unbounded generators and unknown/renamed nested data report incomplete/refuse safely; no winner from partial evidence. | T11 |
| C04-18 | Sealed bytes, authored/actual times and history remain after retire/writer disable; selected version reference real, no fabricated V1/V2 order policies. | T10/T20 |
| C04-19 | Due-page25 bound/fixed ceiling/keyset, refused/unknown item truthful, no production AS/bootstrap/route/family registration. | T09/T21 |
| C04-20 | Physical three-table partial first install/retry; wrong engine/collation/full index/row relationship refuses before ALTER; foreign/legacy/store/order sentinels intact. | T20 |
| C04-21 | Version/status publication refusal and persisted-version recovery; schema8 failure also gates C03 as documented; legacy configuration/quotes boot. | T20 |
| C04-22 | Default/explicit deactivate/uninstall with/without autoload preserve all three stores/C03 completions; non-destructive old-writer rollback. | T20 |
| C04-23 | Private admin versus safe log projections, detached arrays, corrupted/unknown hashes/formats/event links and hidden direct serializer. | T11/T21 |
| C04-24 | All required real SQL cases plus actual native migration/state/option/cache proofs, PHP8.3/8.4/8.5, JS, production/classmap/source and complete HTTP required gates. | T21 |

Direct selected rows: DE-RULE-001–006 and DE-API-007; cross-cutting DE-SEC-001/003/010. Preserve DE-RULE-007 history and new-store DE-DATA-001, with remaining C05–C07 order writing/cleanup/emergency adoption explicit. Do not promote frozen registry classifications after a foundation or design checkpoint.

## Review and next owner decision

Root is sole central/remote editor; three read-only analyses cover policy, storage and transaction/preview boundaries. Finite independent closure found one concrete recoverability gap: the original activation's scheduling-time revisions were not durably stored. The proposal now adds three immutable scheduling revision columns and a fresh-process/stale-predecessor proof; retries cannot rebuild preconditions from today's rows. Closure rechecks are recorded in the design PR. Local team/product control planes and declaration checks pass: all10 accepted-plan C04 rows,24 unique planned cases,three declared tables,stored scheduling facts,real inspected source paths and an empty production diff. These checks execute no proposed SQL/lifecycle cases. Only the owner and AI agents are working. Design-candidate required CI/native/HTTP/source verification is **preservation evidence for current C03/schema7**, not execution of C04's24 future cases. C03 candidate versus actual-merge and this design's runs/archives/members are recorded separately in their PRs.

Next prompt: **“Approve W1-C04-RULE-LIFECYCLE-1 and implement W1-C04.”** That approves these three additive schema8 stores, family declaration and time/conflict contracts, internal lifecycle/preview/activation services, default preservation and24-case proof scope. Business-family/checkout/snapshot/queue adoption still needs its concrete adapter policy. The next checkpoint can proceed without inventing that policy or waiting for another person's availability.
