# W2-Q02 — DeliveryQuote storage and readiness

Status: **IMPLEMENTED / FINAL CANDIDATE QUALIFICATION PENDING / SEPARATE OWNER INTEGRATION HANDOFF**. Owner instruction at 2026-10-07 05:40:20 UTC: “Approve W2-Q01 for integration and implement W2-Q02.” [Issue #85](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/85) tracks this checkpoint. Q01 integrated normally through PR #84 as `090a0ad535ee8faac59d2763333d762cb89ef0d0`, tree `548c3855a020d7e3035773236e2d8a90acf02d82`; actual-master CI 37577762509 attempt1 passed all eight jobs, native380 and final HTTP98, with all635 installed sources verified against immutable merge bytes.

## Completed behavior

Schema9 adds `delivery_quotes`, `delivery_quote_bindings`, and `delivery_quote_budget_windows`. These store exact issued/accepted/invalidated/stripped quote facts, prepared/verified/sealed placement facts, and minute counters or single-use admission facts. They are internal storage primitives. Q03 will implement coordinated issuance, acceptance, admission and retention; Q04–Q06 will implement the retained price provider, cart readers and final placement. Current shopper pricing and order writers do not use these stores.

The existing rate table receives only the named `quote_candidate_range(delivery_offer_id,destination_zone_id,base_currency,id)` index. The approved design used the proposed name `currency_code`; the retained schema actually stores `base_currency`. The design explicitly records this source alignment. No currency column, price rewrite or alternate price engine is introduced.

The forward migration inspects existing structures and rows before DDL, verifies each of four additive units, and publishes schema9 only through the existing MigrationRunner after complete verification. Incompatible types, lengths, null/default/extra attributes, unique keys, prefix indexes, collations, engines or stored facts refuse. Compatible empty partial structures can resume; populated incompatible structures remain untouched. The old32 tables and their data stay intact. Schema8 readers retain old data, while new quote writes require physical schema9 and its successful migration status.

Readiness checks the current native connection and physical options rather than accepting cached schema status. It retains C03/C04 baseline readiness and performs no runtime history walk. Migration record inspection uses fixed ceilings, pages of at most100, and transport-side size markers. Header payloads are capped at4KiB; bodies and binding mappings at64KiB. Exact persisted codecs reject malformed JSON, duplicate keys, unsupported identities/formats, inconsistent digests, revisions, transitions and backlinks. Private storage values refuse generic JSON/PHP serialization; explicit row hydration is internal.

The repository requires an owned active same-site OperationSession transaction. Reads are bounded and site scoped; writes retain original UUIDs, namespaces, headers, bodies, mappings and lease times. All-column compare-and-swap refuses stale facts. Binding writes lock and validate the actual physical quote before the binding; consumed admission changes lock the budget then validate the physical quote on the same session. An in-memory parent object alone cannot establish that relation. These facts do not establish C03 completion, current customer authorization, native order admission or a final placement receipt.

## Preservation

C06 explicitly inventories all35 domain tables: the original32 plus the three quote stores. New quote classes are preserve-only. Expiry alone grants no deletion permission. Actual deactivation, Composer uninstall and the standalone no-vendor uninstall must preserve quote rows and existing history. Cleanup algorithms, reference-safe stripping and retention progress belong to Q03.

Adding preserve-only classes changes the complete lifecycle policy digest. Existing old-policy direct batches refuse `policy_changed`; the existing read path reports `outcome_unknown` after retirement, also propagated by uninstall. Both preserve original checkpoint, intent and role facts. Q02 tests this existing disposition and does not silently migrate old cleanup authority.

## Executed proof and remaining qualification

Local results below are comparative PHP8.3.6 / MariaDB10.11.14, distinct from pinned CI PHP8.5.11 / MariaDB11.4.13 / WordPress7.1.2 / WooCommerce11.1.2.

| Proof | Result and boundary |
|---|---|
| Exact schema/readiness units |61 tests /204 assertions PASS. |
| Strict storage codec/repository units |53 /125 PASS. Wrong consumed principal and consumption before quote creation reproduced two red failures before correction. Missing transport marker and orphan parent writes reproduced three red failures before correction. |
| Required physical quote storage class |30 /599 PASS, zero skips/errors/failures. Includes all four migration interruption units, original32-table byte preservation, conflicting structures, uniqueness, corrupt rows, exact CAS, rollback/fresh connection/site isolation and physical parent guards. Two parent-row regressions were independently red2/18 before correction. |
| Existing physical DataLifecycle class |30 /345 PASS alongside quote storage; combined60 /944. Owned database had zero remaining tables and its server was stopped. |
| DataLifecycle units |162 /2046 PASS, including preserve inventory and old-policy disposition. |
| Native WordPress obligations |12 new source-derived native case IDs cover actual dbDelta/options publication refusal/retry, cache/connection replacement, fresh OS wp-load reader and all three lifecycle preservation routes. Execution is required on the immutable final candidate; fixture syntax checks are not execution proof. |

Final local configured suite:2794 tests /17084 assertions /1existing skip PASS, JavaScript8files/102 PASS, package/autoload/production lint643/0, repository lint1009/0, control plane and negative registry fixtures PASS. The default128MiB CLI process exhausted memory and has no suite verdict. A separately labeled512MiB CLI test process completed in28.211s at153.54MiB. The pre-storage full-suite baseline already used127.56MiB. No production or listener INI setting changed.

Physical SQL uses real DDL/native connections with the existing whole-table dbDelta test adapter and isolated option doubles. Actual WordPress dbDelta ALTER, update_option publication and default object cache are established separately by the native fixture without tests/bootstrap.php. No physical group is silently excluded from required CI: the new quote-storage class requires at least30 non-skipped cases. Existing operation43/rule44/data30/emergency30 minima remain intact.

Final candidate, tree, complete unit/SQL/JavaScript/lint/package counts, CI run/attempt, exact artifact ZIP and JSON member hashes, source-derived installed map and complete native/HTTP case sets are recorded on the checkpoint PR after execution. Existing OPcache-on/JIT-disabled listener and20-second client bound stay unchanged. Failed evidence remains failed; only complete fresh qualification supports the integration handoff.

## First pinned qualification and bounded correction

Candidate `fff463d998c9093cf8663475e754c62bd08a4903`, tree `f405ee07038fbd8c3bc02dcb92a4e4ea27d88742`, was published on draft PR#86. CI37579920661 attempt1 failed SQL/WordPress/Required Gates. PHP8.3/8.4/8.5 each passed2794/17084/1skip; PHP8.4 reported2 deprecations and PHP8.5 reported15. Production643/0, repository1009/0, package/JavaScript/control-plane passed. These are exact execution results; the15-count is not labeled as the historical14.

Two obsolete qualification assertions caused the failures: the emergency-control SQL preservation test expected32 current tables, and the native C04 assertion expected current target8. SQL completed204/4694 with1failure/2deprecations. Native recorded183PASS/1FAIL (184unique), overallFAIL; all12 Q02 cases and HTTP were not reached. Native artifact11463749601 ZIP51224/SHA256db7f9957ebe0b12275c1b97626f9d1f29fc0fe858b9ecfb9edd8049bbdcc8588; JSON854506/SHA256aa5fa7ffb45d5ddd969b2206c5bf16f4ca74d1a4167a3562839d8ef559d61101. All643 installed source bytes still matched the immutable candidate mapc06b58d5c2c76aa0b0b7fb6f3b1790ebe0d77b1f291194aa6c8c9b17490866b5. Smoke passed separately; failed evidence is retained as failed.

The correction strengthens preservation checks to original32 plus current35 and retains every per-table comparison. Focused1/51 and full EmergencyControl31/908 passed locally with no skips; owned database empty/server stopped. The historical native C04 case ID now proves migration8 remains8 and the current candidate target is9. No production source/map, runtime, timeout, case inventory or required gate changes. A fresh successor run must complete exact392native/98HTTP execution and all eight jobs.

Next owner task after qualification: **“Approve W2-Q02 for integration and implement W2-Q03.”** Q03 adds the durable lifecycle coordinator and bounded admission/retention algorithms to these stores.
