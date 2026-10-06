# W1-C05 historical snapshot readers and optional extensions

Status: **OWNER-AUTHORIZED / IMPLEMENTED; INTEGRATION PENDING**.

At 2026-10-06 22:54:53 UTC the owner instructed: “Approve W1-C04 for integration and implement W1-C05.” [Issue #70](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/70) tracks this checkpoint under #48. Branch: `ws3/wave1-c05-snapshot-reader`.

The approved C04 candidate was merged unchanged through PR #69 as `246bd2d7b5df551a950cc37ffc1f79253cce058c`, tree `cabe08d80965431ddea41f9bfb94cf64c863773e`. Its actual-merge run 37543612765, attempt 1, passed all eight jobs, 269 native cases, all 66 final HTTP cases, and the separate smoke. All 570 installed production PHP files match the immutable merge tree; map SHA-256 `b310cf39f644e81edbf5cc04f3d6d338e3cf911f737aed8a68dea59379453f20`. PHP 8.3/8.4/8.5 each ran 1,990 tests / 12,125 assertions / 1 existing skip. Required MariaDB ran 113 tests / 2,891 assertions / 0 skips, including 43 C03 and 44 C04 cases. Actual merge archive/member fingerprints are recorded separately in PR #69. This is C04 integration evidence, not C05 execution.

## What C05 changes

An order's saved delivery facts remain the source for historical reads. A reader cannot replace a missing old policy with today's settings, turn absent prices into zero, or infer an old promise from current rules.

C05 adds bounded, truthful V1/V2 parsing and an independent parser for optional recorded facts. It reads protected order and item metadata through Woo CRUD. Reading changes no stored JSON, version metadata, row identity, configuration, or material audit.

The current development identity is `1.0.0-dev.wave1-snapshot-readers.1`. Database schema stays **8**. Snapshot format remains **1 or 2**; selection-contract version, optional-envelope version, captured policy/reference version, and database schema are separate values.

## Historical compatibility

| Stored input | Reader behavior |
|---|---|
| Valid V1 or V2 line/package | Preserve the historical facts and existing core DTO serialization. New facts remain not recorded when absent. |
| Missing metadata | Report missing; obtain no replacement facts from live products or configuration. |
| Malformed JSON, duplicate member ambiguity, excessive size/depth | Report malformed; retain the original bytes. |
| Partial or wrongly typed known base facts | Report partial; do not coerce fractional IDs, arrays, or malformed groups into plausible history. |
| Unsupported format, invalid/mismatched version metadata, incompatible selection contract | Report version mismatch; do not reinterpret as V2. |
| Unknown top-level optional fields | Ignore them while preserving the stored bytes. |
| Unknown optional extension name/version | Ignore its content without projecting or rewriting it. |
| Known optional extension with invalid data | Keep valid base facts; report that extension unavailable with a finite diagnostic. |
| Required extension semantics or an ambiguous envelope | Refuse unsupported base semantics; never treat a required fact as optional. |

Existing legitimate legacy availability labels remain explicit compatibility values. Reader hardening does not alter writer behavior or require an action-only shipment grouping fact merely to display an old readable snapshot. The existing historical planner retains its own checks for facts needed to create a shipment.

## Optional extension format

The internal reserved member is an `extensions` JSON object. Its finite namespaces are `quote_policy`, `promise`, `fulfilment_labels`, `return_policy`, and `refund_policy`. Each independently uses:

```json
{
  "version": 1,
  "required": false,
  "data": {}
}
```

The example illustrates the envelope only; an empty `data` object is not valid recorded content. Each namespace has its own exact payload schema:

| Namespace | Captured facts |
|---|---|
| `quote_policy` | Reference, explicit currency, exact nonnegative decimal amount, optional customer text. No tax, promotion, tariff or private-cost calculation is inferred. |
| `promise` | Reference, explicit valid UTC start/end with end after start, optional customer label. No promise is inferred from an estimate or current rule. |
| `fulfilment_labels` | Reference and a bounded nonempty list of captured labels. |
| `return_policy` | Its own reference and captured customer text. |
| `refund_policy` | Its own reference and captured customer text; it does not inherit the Return Policy. |

Each reference has a captured opaque `id`, string `version`, and 64-character content digest. Parsing validates stored shape, not the existence or present content of a current policy. It neither looks up a C04 rule nor fabricates a `RuleVersionReference` from today's selection. A reference digest is a stored identity fact, not a claim that the whole current policy can be reconstructed from the small extension payload.

Typed results expose explicit internal facts, a customer projection, and finite diagnostics. Customer projections exclude reference identities and unknown fields. Generic JSON serialization is refused. Validated arrays and exported arrays are detached from caller references. This internal checkpoint adds no customer-facing rendering or public API stability promise; an actual later renderer owns output escaping and current authorization.

Finite limits cover the original snapshot JSON (1 MiB and depth32), duplicate keys, extension count16, entry16KiB and aggregate64KiB, depth5/nodes128, text, labels, and exact integers/decimal strings. Extension entry/aggregate budgets count the decoded representation re-encoded with unescaped Unicode/slashes; the separate whole-snapshot limit counts original stored bytes. Missing namespaces report `not_recorded`; no empty policy, empty label list, zero price, or default promise is invented.

## Mandatory formats and writer boundary

All C05 extensions are optional under existing V1/V2. Any `required: true` remains unsupported under those formats, including a known parser. The old reader ignored new fields; a nested required marker cannot make mandatory writes safe for rollback to that reader.

C05 enables no new mandatory writer or format. A future mandatory format needs its own reader/adapter, an explicitly qualified compatible-reader rollout and rollback path, and separate writer authorization. The COR-029 checkout-draft versus accepted-placement decision and package finalization remain separate. `OrderDeliverySnapshotBuilder`, `OrderDeliverySnapshotPersister`, the writer gate, checkout hooks, package write behavior, and stored core DTO writers are not edited here. Historical JSON is not backfilled.

## Proof scope

The accepted Wave 1 cases remain the authority:

| Case | Meaningful execution required |
|---|---|
| T12 | V1/V2 facts, raw protected JSON/version rows, and historical shipment plans remain unchanged after reads, configuration edit/deletion, and the actual existing schema8 migration. |
| T13 | Unknown optional fields are compatible; unsupported mandatory, malformed, partial, duplicate or mismatched content is unavailable truthfully and never rewritten. |
| T14 | Only actual recorded typed facts are returned; absence remains not recorded; Return/Refund references are independent; projection/detachment bounds hold; no mandatory writer/rollback is silently enabled. |
| T21 | Finite source closure, complete exact-candidate native and HTTP execution, required CI, immutable installed-source map, and separate ZIP/member hashes. |

The native module persists owned fixture orders, product items and shipping items through real Woo CRUD with HPOS. It compares actual protected metadata rows and fresh reads. It seeds historical facts explicitly; it does not claim a new checkout/business writer was exercised. Configuration mutations and migration fixtures use only the marked disposable qualification environment. Receipt evidence contains safe statuses, counts and hashes; raw addresses, policy text and stored payloads remain private in-process.

## Implementation and proof ownership

Only the owner and AI agents are working. Root owns shared integration, status, development identity, package verification, runner selection and publication. Exclusive owners implement the reader/JSON boundary, new extension types, and the native module. A read-only reviewer closes concrete source findings once, then verifies the immutable candidate. Existing full SQL cases, HTTP sequence, required gates, listener profile and 20-second client bound remain required.

Concrete reader failures recorded before repair on PHP 8.3.6: fractional product ID `16.75` and a mixed valid/malformed group list both returned success instead of partial; a duplicate `product_id` was accepted with last-value-wins instead of malformed. The regression run was **3 tests / 3 assertions, 3 failures**. This is local red evidence, not a CI result.

## Frozen source and executed local proofs

| Implementation | Proof |
|---|---|
| `OrderDeliverySnapshotReader.php`, `OrderDeliverySnapshotJson.php`, `OrderDeliverySnapshotIntegrity.php` | `SnapshotReaderCompatibilityTest`: **86 tests /326 assertions**, PASS; existing plus new Order/Shipment directories **410/2236/1 existing skip**, PASS. |
| Four `SnapshotExtension*.php` internal classes | `SnapshotExtensionsTest`: **68 tests /375 assertions**, PASS. All five independent payload schemas, exact references/money/time, absence/unknown optional/mandatory refusal, malformed data, input/output detachment, namespace binding, explicit projections and byte/depth/node budgets. |
| `opening-snapshot-readers.php` | **30 new native cases**, finite source review and PHP8.3.6 lint PASS. Runtime execution is separately required on the published candidate; source review is not native execution. |
| Complete configured local PHP suite | PHP8.3.6: **2144 tests /12826 assertions /1 existing skip**, PASS. Physical SQL groups remain separate. |
| Production package | **575 production PHP files**, schema8/no-dev autoload/boot references/Linux classmap and lint **575/0**, PASS. |
| Repository lint and control plane | **877 PHP files /0 failures**, PASS. Team/product validators PASS,372 frozen IDs unchanged. |
| Finite independent source closure | **PASS**; reviewer independently reran all new C05 tests **154/701**, PASS. |

All counts above are local PHP8.3.6. Fresh PHP8.3/8.4/8.5, required MariaDB113 and JavaScript102 remain exact-candidate CI evidence. There was no local new SQL group or local native WordPress run claimed for C05. The initial package check recognized only the preceding C04 identity and selected its historical default schema4 branch for the new header; adding the new C05 identity to the existing schema8 package branch restored the strict schema8/five-store check. The production schema target was never changed.

The only finite reviewer finding was a native fixture that supplied invented V2 identities and incomplete address shapes. It was corrected to use the existing production address/context/group constructors, preserving strict facts and raw-meta assertions. This was a fixture correction, not a product red result. No source blocker remains.

## First published native qualification

Candidate `ecdc8c3d881705892d1d4fea5ad5ed1664711fbe`, tree `f4b32b643146babaa8d0e72e23e381eed7b454fa`, run 37545158085 attempt1 is retained as failed qualification. Its receipt has 298 recorded unique cases: 297 PASS and one FAIL, with the final schema-restored case not reached. All 29 substantive C05 cases passed; its cleanup case reported only `products_removed=false`. Order, item/protected metadata, configuration/audit, owned migration-table and context cleanup booleans were true. HTTP did not execute.

Independently downloaded native artifact 11449789658 is 52,497 ZIP bytes, SHA-256 `f71fa6a7ac67ed3f4ed1459134850211f906e136f6ff10e2fa1c0b023d3d0741`; its JSON member is 922,322 bytes, SHA-256 `b403e1d95c1a1a009a5893877fe8b6e9424c314397b41361eed6454efd52fc3c`. This failed execution is not a complete native or HTTP pass. The product sources stay fixed while the fixture cleanup discrepancy is diagnosed; the corrected fixture requires a fresh exact-candidate run.

The grounded fixture correction retains the complete cleanup assertion. WooCommerce 11.1.2 declares `wc_get_product()` as returning a product, `null`, or `false`, and its factory can return an instance-cache object before a datastore read. The old receipt did not distinguish these outcomes and did not prove physical product-row absence. The corrected fixture requires zero owned product IDs in both posts and postmeta, clears only those fixtures' WordPress/Woo product caches, and requires a fresh lookup to be `null` or `false`. Fixed pre-refresh return-type counters establish the observed distinction on the next run. No production code, runtime setting, case ID, or test-product deletion behavior is changed. Local PHP8.3.6 lint and whitespace checks pass; fresh native execution remains required.

## Concrete T12–T14 mapping

| Accepted case | Actual sources and assertions |
|---|---|
| T12 | Reader tests `test_canonical_numeric_legacy_ids_and_real_zero_money_remain_readable`, `test_absent_group_and_absent_quote_are_not_fabricated_for_old_v1`, `test_v2_destination_recipient_pickup_and_variation_facts_stay_historical`, `test_reader_and_historical_shipment_use_saved_facts_with_no_mutating_crud_or_live_product`. Native V1/V2 persisted historical reads and plans; exact physical rows; actual configuration edit/deletion; actual isolated schema8 migration; owned cleanup. |
| T13 | Reader type/version/metadata/selection/partial/missing datasets; original three red cases; duplicate escaped members; JSON object/byte/depth bounds; malformed/duplicate/overflow groups; incompatible mandatory semantics; unknown optional and separately malformed known optional extensions. Native eight line/four package failures, optional/mandatory cases and exact raw-row preservation. |
| T14 | Extension68 cases for five independent schemas, captured references/text/amounts/time/labels, no absent defaults, strict input/output and namespace/projection/budget boundaries. Reader recorded-policy and empty-object cases. Native absent/all-five/unknown/malformed/mandatory projections and detached facts. No mandatory format or writer was added. |
| T21 | Independent finite source closure; package575/lint877/control372. Candidate CI must execute all299 native unique cases (269 preserved +30 C05), all66 final HTTP cases, separate smoke and all113 required SQL cases, then compare every575 installed source entry with immutable Git. |

Exact head/tree, execution/run/attempt, downloaded artifact IDs, archive/member bytes and hashes, runtime and source bindings are recorded in the implementation PR to avoid a self-referential source commit.

After a qualified C05 checkpoint, the next owner instruction is **“Approve W1-C05 for integration and prepare W1-C06’s data-lifecycle design.”** C06 first inventories exact stores and reviews preserve/expire rules and bounded cleanup. C06–C07 and reserved business contracts are not implemented by C05.
