Current owner continuation2026-10-09 11:45:15 UTC accepts qualified P02 integration (PR102/master5d3d17c) and implements only P03 pure bounded calculation underIssue103. [P03 boundary](W2-P03-DETERMINISTIC-CALCULATION-2026-10-09.md) governs the new finite leases; P04–P06 remain later checkpoints. Original historical design observations below retain their meaning and are not all claimed executed.

# Service/promise policy — bounded implementation proposal

Current owner checkpoint2026-10-09 09:11:24 UTC: P01 accepted/integrated under PR100/masterd07b8eb; P02 immutable persistence authorized under Issue101. Its [frozen additive schema/reader transition](W2-P02-IMMUTABLE-PROMISE-STORAGE-2026-10-09.md) governs implementation. P03–P06 remain later bounded checkpoints; original design observations below retain their historical/unexecuted meaning.

Historical P01 checkpoint: **OWNER ACCEPTED 2026-10-09; W2-P01 internal contracts authorized under Issue #99. P02–P06 remain later separately accepted checkpoints.** The owner continuation and exact accepted source are recorded in CURRENT-WORK.md. All original proposed semantics and unexecuted future observations below are retained as the accepted design record; this overlay does not turn those algorithm/physical observations into P01 test results.

## Original reviewed design record


Status: **PROPOSED; NO IMPLEMENTATION CHECKPOINT STARTED**. Governing proposal: [W2-SERVICE-PROMISE-POLICY-1](W2-SERVICE-PROMISE-POLICY-DESIGN-2026-10-09.md), [Issue #97](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/97). These are promise checkpoints `W2-P01`–`W2-P06`, not an invented seventh DeliveryQuote checkpoint. The owner/AI team can execute accepted finite leases; cross-workstream ownership and central edits remain recorded in CURRENT-WORK.md.

## Dependency and handoff order

| Checkpoint | Bounded work | Protected boundary and required handoff |
| --- | --- | --- |
| W2-P01 — internal contracts | Strict detached service/policy/calendar/component/result values; explicit anchors/units/states/privacy projections; canonical identities, proposed limits and contract ports. | Pure internal domain only. No schema, migration, shopper route, active adapter or flag. Freeze decisions and demonstrate meaningful value/digest/shape refusals, retained PHP/control checks. Accept/merge separately before dependent work. |
| W2-P02 — immutable policy persistence | Additive owned policy/calendar storage and read-only references, C04 publication/effectiveness model, revisioned assignments and complete source receipts. | Freeze actual schema/format transition from current schema 9; no destructive migration or new promise writer. Real DB publish/edit/race/replay/readiness and retained-data preservation proofs. WS3 owns contracts/schema/security; runtime consumers remain inactive. |
| W2-P03 — deterministic calculation | Calendar/closure/cutoff/timezone/DST, component graph, absolute/relative result and configured-capacity observation ports using the frozen policy. | Pure bounded application service; no HTTP/network under SQL ownership. Validate all future algorithm obligations, performance/whole-result refusals and unknown/known-zero distinctions. No inferred capacity hold. |
| W2-P04 — reader-first quote/order handoff | First closed forward readers and new C05 required-promise readiness with writes OFF; then distinct promise-enabled profile and acknowledged immutable quote/order packet under explicit gating. | Two reviewable sub-handoffs: reader support before writes. Preserve v1 bytes/digests, original retries/expiry/money, physical saved-order verification, payment gates and uncertain outcomes. Fresh HPOS/CPT/SQL/failure/old-history receipts. |
| W2-P05 — native configuration and customer parity | Protected policy/calendar editing/preview/assignment and truthful PDP/Classic/Blocks/Store API views; separate original/current shipment estimate. | WS2 server/native integration and WS1 customer UX only after contracts; C04/C03/C07 and authorization retained. Default adoption OFF. Real requests/browser prove required refusal, localized parity and no public private payload. |
| W2-P06 — full qualification and operational handoff | Source-derived complete retained/new native/SQL/HTTP/browser inventories, exact package, upgrade/reader-preserving disable/uninstall/rollback and bounded-step/query observation. | Independent original-artifact audit; exact source/runtime/cleanup; physical acceptance remains distinct. Integration is not live activation, release or completion of all Stable 1.0 work. |

No checkpoint is authorized for runtime implementation by this docs proposal. After concrete design acceptance the recommended instruction is “Approve W2-SERVICE-PROMISE-POLICY-1 and implement W2-P01.” Each later source boundary gets an exact tested handoff rather than a broad uncontrolled merge. Versioned policy uses C04 and shared command/control seams; existing RateQuoteEngine, rate cards, native Woo shipping, geography, canonical grouping, money and protected order history are reused.

## Future observations — all UNEXECUTED_DESIGN

`W2P-*` labels below are finite design-observation IDs, not additional canonical requirements or runtime case counts. Implemented inventories will be derived from their actual source and frozen before execution; the existing 495/20/143 qualification remains unchanged by this document.

| Observation | Concrete expected evidence | First owning checkpoint |
| --- | --- | --- |
| W2P-01 | Separate preparation/dispatch/transit/buffer/final-mile ranges; exact known-zero phase, unknown/missing refusal and no false doorstep endpoint. | P01/P03 |
| W2P-02 | Built-in service codes and merchant-defined IDs are distinct from translated labels/price; names alone supply no policy or admission authority. | P01 |
| W2P-03 | Explicit elapsed/calendar/business-minute/business-day semantics and exclude-start business-day counting (after-cutoff Friday→Monday start +1day→Tuesday); zero, reversed/overflow/noninteger inputs and distinct digests. | P01/P03 |
| W2P-04 | One server capture instant; conservative final-placement/seal acceptance envelope (never Q05 Confirm), explicit capture-start service and payment-relative states; unknown event never yields a fake absolute date. | P01/P03 |
| W2P-05 | Closed result/receipt schemas, equal bounds for explicit known zero, unknown-version/extra-field rejection and detached immutable values. | P01 |
| W2P-06 | Split/overnight weekly windows, non-Monday–Friday weeks, break intervals and business-minute carry produce exact lower/upper endpoints. | P03 |
| W2P-07 | Closures override weekly/exceptional openings; configured public holiday/carrier version changes affect new results; no automatic holiday assumptions. | P03 |
| W2P-08 | Origin/dispatch/destination calendars in different IANA zones keep UTC/local dates explicit, including cross-midnight handover. | P03 |
| W2P-09 | DST gap refuses implicit normalization/arbitrary offsets; repeated local time requires exactly round-tripping offset/fold; elapsed/day arithmetic retains frozen zone-data provenance. | P03 |
| W2P-10 | Before/at/after cutoff and effective interval endpoints; exact equality misses window; closure/cutoff selects next eligible start only when policy allows. | P03 |
| W2P-11 | Closing18:00, cutoff16:00, request15:59 and upper6hours cannot win Express by cutoff alone or by clipping upper completion. | P03 |
| W2P-12 | Same Day/Next Day literal local completion constraints and order-acceptance midnight cap (23:58issue,00:03expiry,00:00acceptance→newquote) refuse misleading6–24/24–48hours; show honest alternative service without changing original selection. | P03/P05 |
| W2P-13 | Sequential legs include processing/handover/buffers and next-opening waits, with monotonic complete range rather than label sum. | P03 |
| W2P-14 | Parallel branches join by max lower/max upper; independent endpoints stay independent, incomplete required branch refuses complete result. | P03 |
| W2P-15 | Cycles, duplicate/missing node/edge, unknown units/timezone/source and graph limits refuse before any partial winning promise. | P01/P03 |
| W2P-16 | Explicit no-capacity mode differs from required unknown/unavailable/stale/wrong-source; no missing adapter becomes unlimited or reserved capacity; payment-relative required future window cannot use generic current availability. | P03 |
| W2P-17 | Revisioned native capacity observation change between preview/Confirm/final placement causes a fresh reviewed path; no hold is inferred from quote budget slots. | P04/P06 |
| W2P-18 | Policy/calendar/source version edits change new promise digest; canonical equal members/order do not change identity; accepted bytes remain immutable. | P01/P02 |
| W2P-19 | Permission/concurrency/effective-time publication races and C03 original command replay produce one immutable version/event; stale preview is not authority. | P02/P05 |
| W2P-20 | Public shopper view excludes origins/supplier/capacity/cost/private policy fields; guest/user isolation and unauthorized preview disclose nothing. | P01/P05 |
| W2P-21 | Forward reader accepts exact old/new formats with new writes OFF; future unknown bytes preserved/refused, no weaker legacy fallback or reinterpretation. | P04 |
| W2P-22 | Required promise is refused by unsupported C05 v1; distinct new readiness contract and physically saved mandatory quote facts agree before seal/payment. | P04 |
| W2P-23 | Quote TTL, promise deadline, capacity validity and retention grace remain distinct; crossing each boundary denies required continuation without extending original quote. | P04 |
| W2P-24 | Original issue/accept retries and lost-ack/cross-route recovery retain original window/anchor/policy/expiry and one effect; replay never recaptures current clock. | P04/P06 |
| W2P-25 | Policy/calendar/material/source mutation after issue cannot silently update accepted promise; new explicit Refresh/Review/Confirm preserves old quote/order. | P04 |
| W2P-26 | Delayed saved-order payment obeys captured anchor/late-payment rule; actual payment-relative event creates only audited current prediction and no duplicate payment. | P04/P05 |
| W2P-27 | Exact per-group HPOS/CPT saved promise packet and native money/history survive live policy/calendar deletion/timezone edits; no session-text authority. | P04/P06 |
| W2P-28 | Shipment creation copies structured original promise once; current ETA reason/event updates retain original values and old text-only histories. | P05 |
| W2P-29 | Real PDP incomplete-destination estimate and Classic/Blocks/Store API final results use the same calculator/view; localization exposes correct zone/range/anchor. | P05/P06 |
| W2P-30 | Required unavailable/ineligible policy hides/refuses service; stale submitted choice, adoptionOFF protected order and emergency pause never enter unguarded payment fallback. | P04/P05 |
| W2P-31 | Full native bounds/plus-one/horizon/record-byte/cart-step/query tests prove bounded work, deduplicated sources and no callback/network while owned SQL locks exist. | P03/P06 |
| W2P-32 | Fresh package upgrade, new sealed promise history, disable/deactivation/both uninstall paths/forward-reader rollback preserve records and unknown references; exact tracked cleanup. | P02/P04/P06 |

## Quote follow-ups carried forward separately

The accepted closeout's 39 covered/nine bounded disposition is preserved. These finite follow-ups do not become fulfilled merely because a promise design is published.

| Follow-up proposal | Bounded obligations | Required next scope and acceptance |
| --- | --- | --- |
| Native retention design/adopter | W2Q-27/29/30 | Design complete native HPOS/CPT/order-item/placement references and exact owner absence authority; preserve accepted/history/pending/unknown refs under races. Then mount an authorized bounded consumer with physical restart/budget/privacy proof. Current unknown-reference fallback remains; expiry alone is not cleanup. |
| Native breadth qualification | W2Q-13/31/37 | True concurrent multi-group issue/replay; actual login/logout/session rotation and reissue; actual native 200/201 line/group limits plus query/range-lock observations. Separate fixture changes and original pinned receipts; no weaker predicates. |
| Actual process interruption | W2Q-30/45 | Owned disposable worker OS kill/restart at collector/placement stages, reconcile original outcomes and preserve payload/history without replacement effects. Lost-ack mocks are not OS-kill proof. |
| Persistent cache support or pilot exclusion | W2Q-36 | Retained path actively refuses external object cache. A separately accepted adapter must prove native generation/expiry/owner/mutation with physical cache; otherwise explicitly exclude it from chosen pilot. Do not weaken the exact default-cache guard. |
| Sealed-history deployment rehearsal | W2Q-39 | New Q06 sealed order survives representative package upgrade, disable/deactivation/both uninstall paths/forward-reader rollback with no destructive down-migration. Target environment/writes/backup/physical acceptance need separate authorization. |

Service/promise domain planning can proceed without asserting those operational scopes are complete. A chosen pilot must resolve/exclude unsupported cache/providers and agree qualified retention or explicit bounded interim growth/privacy/stop controls before activation. Proposed seven DE-QUOTE registry alignments remain a separate narrow reconciliation decision; no canonical row/count is modified here.

## Review and stop boundary

Independent reviews must verify original requirement wording, existing source anchors, concrete proposed choices, complete 32 observation IDs, reader/capacity/clock/privacy boundaries, bounded ownership order and untouched runtime/registry. Root validates CSV links/sets and protected bytes, runs proportional controls, publishes an exact docs-only commit/PR and evaluates required candidate CI separately from actual-master evidence. Final handoff uses two bounded freshness passes.

The reviewable next acceptance is this design plus its explicit decisions and P01 scope. It is not target-site permission or a promise to silently implement all six checkpoints. Staff/owner physical UX acceptance, live Paystack/plugin-stack effects, pilot/production and Stable 1.0 release retain their separate gates.
