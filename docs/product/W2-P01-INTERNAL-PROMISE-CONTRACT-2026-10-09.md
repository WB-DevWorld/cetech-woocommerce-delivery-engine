# W2-P01 — internal service and promise contracts

Status: **IMPLEMENTED AND INDEPENDENTLY REVIEWED; EXACT PUBLISHED CANDIDATE CI QUALIFICATION PENDING**. Owner continuation2026-10-09 08:04:41 UTC accepts W2-SERVICE-PROMISE-POLICY-1 and implements only P01. [Issue #99](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/99); [accepted design](W2-SERVICE-PROMISE-POLICY-DESIGN-2026-10-09.md); [bounded sequence](W2-SERVICE-PROMISE-IMPLEMENTATION-PLAN-2026-10-09.md). Exact design master `68142106aa2d7ec226a31a6ccbad1f24ac1b5ad8`, treee188, ordered parents [62fc closeoutmaster,9044 reviewedproposal]. The actual design-master execution and this implementation candidate retain separate original evidence.

## Result and boundaries

P01 introduces detached internal values, explicit state/clock/endpoint semantics, canonical identities and unmounted ports. It supplies the contract foundation that later persistence and calculation can consume. It does not calculate a delivery date, save a policy, register a WordPress/WooCommerce callback, modify a cart/order/payment or expose a new shopper endpoint. Constructing a record validates its shape and links; it does not prove native authorization, observed capacity, publication, physical save, final seal or payment.

Development identity is `1.0.0-dev.wave2-promise-contract.1`; schema9 and all23 flags are unchanged. Site adoption is OFF. The retained price/tax/provider/quote/checkout/history behavior and existing closed quote/rule/snapshot formats remain unchanged. Canonical372 IDs and all conformance classifications/counts, audit manifest and seven unapplied DE-QUOTE recommendations remain unchanged. This is not a release or operational compatibility certification.

## Pure contracts

| Area | Internal facts and checks |
| --- | --- |
| Codec and shapes | New PromiseJson/PromiseShape validate strict scalar types, closed fields, duplicate and escaped duplicate JSON keys, object/list identity, byte/depth/node budgets and detached references. Private values require explicit projections; JSON and native PHP serialization/hydration are refused. Existing QuoteJson and RuleSchema are untouched. |
| Service and duration | ServiceIdentity separates five built-in codes from merchant codes and labels. PromiseDuration records explicit elapsed/calendar/business-minute/business-day units and exact nonnegative integer ranges, with zero distinct from omitted/unknown. RuleTime supplies strict immutable UTC microseconds without current-clock capture. |
| Components and graph | PromiseComponent separates preparation/dispatch/transit/buffer/final-mile, source version, explicit endpoint kind, duration calendar and independent operating calendar/completion constraint. Doorstep requires an explicit final-mile component. PromiseGraph validates complete bounded DAGs, all edges/sinks, duplicate/missing/cyclic structure, source/site links and canonical unordered sets. Independent endpoints remain distinct; no joint completion is inferred. |
| Calendars and policy | BusinessCalendarVersion requires all seven explicit weekday facts, IANA/tzdata identity, half-open local windows, closures/dated exceptions and source receipts. Closure wins; no Monday–Friday or country-holiday default. ServicePromisePolicy binds graph/calendar/source/site/effective interval, explicit endpoint/scope/anchor/day/late-payment/capacity rules. Cutoff is a closed local-time or before-closing-minutes union; calculation is deferred. |
| Captured evidence | PromiseAnchor distinguishes the possible Q06 final-seal acceptance interval, explicit quote-capture clock and awaited real Woo payment event. PromiseInput links one captured instant, owner/material/endpoints, policy/calendars/sources/capacity and runtime provenance; typed facts are not a collector or authorization decision. |
| Result and receipt | PromiseResult separates absolute, relative, unavailable and ineligible states and exact per-terminal bounds. Equal absolute bounds allow deterministic future completion; component/relative zero facts remain explicit rather than inferred from range width. Required capacity must be available, correctly sourced and valid for a winning bound; unknown future capacity cannot win payment-relative service. AcceptedPromiseReceipt links exact result, quote and snapshot facts, with a separately recorded optional actual Q06 seal event; a null event establishes no final native acceptance. |
| Public view and ports | PublicPromiseView projects only service label, state, display zone, time/relative explanation and finite safe reasons for an explicitly selected endpoint. It omits origins, suppliers, capacity totals, costs, owner/material/policy/source IDs and internal graph. Unmounted interfaces specify future immutable policy/calendar loading, bounded calculation and capacity observations; there is no adapter or bootstrap registration. |

The new internal receipt profile freezes `evaluated_at == quote.issued_at` and original quote expiry exactly300seconds later. `accept_until` is a separate earlier acceptance boundary. A later P04 writer must reuse that single captured instant and may not recapture a clock or extend expiry on replay. No claim is made that retained v1 receipts use this new profile. Calendar contractv1 stores explicit local weekly/dated windows; the distinct dated-UTC alternative and actual DST occurrence resolution remain a later version/calculation boundary. Publishing a local calendar record is not a DST calculation or normalized gap/fold proof.

Private records use distinct domain-separated SHA256 identities. Set permutations that represent the same facts canonicalize; changed versions, source/content, graph/unit/anchor or other captured facts change identity. Typed reference digests are distinct from the linked record's content digest. The unmounted capacity-observer port is a captured-input/revalidation seam. Initial P03 calculation/capture must first establish an explicit bounded window and unknown observation, then obtain the actual source observation; the placeholder cannot win or claim observed availability. No observation adapter or bootstrap sequence is mounted in P01.

The package verifier recognizes this exact new development identity as schema9, and the two existing schema inspection tests assert its exact header/constant identity while retaining every schema/table guard. Unrecognized format versions, extra fields and contradictory state/linkage combinations refuse with a safe generic exception. This internal format1 is not an extension of retained quote format1.

## Frozen budgets and measured interaction

| Budget | Limit |
| --- | ---: |
| Policy/calendar/component/graph record | 32,768 bytes |
| Internal evidence/result/receipt packet | 65,536 bytes |
| New canonical codec depth / nodes | 24 / 16,384 |
| Graph nodes / edges | 16 / 32 |
| Policy calendar references | 16 |
| Opening intervals per local date | 8 |
| Distinct union of closure/exception dates | 366 |
| Future calculation local-date lookahead / total cart steps | 730 / 100,000 |
| Source/version identity | Explicit site/opaque ID; integer version1–1,000,000; SHA256 content |

Record bytes apply to the whole record, not independently to each collection. Measured test calendar: base328bytes; 366 closures plus exceptions on the same366dates with one opening each30,338bytes succeeds; eight intervals on all366dates114,884rawbytes refuses as a whole. Measured base policy1,164bytes; combined16nodes/32edges/16calendar references15,858bytes succeeds. The structural maximum does not authorize a larger Cartesian-product packet or truncation. Exact/plus-one byte/node/depth and structural bounds have focused refusals. The 730/100,000 future calculation constants do not claim a calendar engine, performance or query/lock proof; P03/P06 still own those observations.

Retained QuoteJson64KiB/depth16/4096nodes, RuleSchema16KiB/depth5/128nodes and existing200quote lines/groups are unchanged. No new schema target or flag is introduced.

## Qualification and remaining acceptance

Local PHP8.3.6 passes282 focused tests/460 assertions and the full4,347 tests/26,726 assertions (zero skips with local tag history). Independent semantic/privacy reviews pass, with separate16-case codec and50-case privacy probes. No-dev production autoload, all21 new classes/interfaces and all770 PHP lint checks pass. Exact development-identity positive/near-match negative package checks pass. Product/control-plane negative fixtures retain372 IDs. Current770-source map is `5d95b0d02ca71929354cc617c9a7cfed1eeffcc03fb95b44dc8aaced0d74f4b8`. The initial local package/full runs failed only stale development-identity assertions; their logs stay preserved and the corrected final checks are reported separately.

Separate actual design-master run37903027773 passed all eight actual jobs and Gate113735893384 executed all three successful steps. Root/independent own original four ZIP/API/CRC/member/immutable-verifier audits independently PASS495/20/143 and agree. Master ledgers are `59a0273ad9ab2e5ddc81af6bd48732536595623432e6ed8b1ea89a44c3c1a664` / `524a3f62ddaad403912431a377d00990a003333844492d2d5a11ba9c6d10d6b0`. These belong to integrated design master6814 and retain the unchanged749-source runtime; they are not P01 candidate execution.

Focused source-derived contract tests and independent adversarial reviews are recorded on Issue99/the exact PR. PHP8.3.6 local results are comparative; the required published candidate lanes remain PHP8.3/8.4/8.5 and existing physical SQL/WordPress/browser qualification. Retained native495/CPT20/HTTP-browser143 inventories stay fixed; their later execution establishes unchanged retained functionality, not a live promise engine. Final counts, source map, immutable candidate/execution/tree roles, original receipts and two bounded freshness passes are recorded at the exact published handoff after completion.

The original32 W2P observations remain the accepted future design inventory. P01's value/shape/digest/privacy and representability tests are bounded evidence for the internal-contract portions; they do not execute the P03 calendar/cutoff/DST/aggregation algorithm, P02 publication/race persistence, P04 physical quote/order handoffs, P05 customer/browser behavior or P06 operational proofs. All ten canonical DE-PROMISE requirements remain PARTIAL_IMPLEMENTATION.

The next separate checkpoint is **approve W2-P01 for integration and implement W2-P02 immutable policy persistence**, after exact P01 qualification. P02 must freeze its actual additive persistence/schema/reader transition and preserve existing records; no guessed schema10 is applied here. Operational quote follow-ups, persistent-cache support/exclusion, native retention, target-stack/staging/pilot/payment and Stable1.0 remain separately controlled.
