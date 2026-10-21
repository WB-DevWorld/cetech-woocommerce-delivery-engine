# Current implementation — E08 promotion budget installation — 2026-10-21

The owner requests continued implementation in parallel on ws3/promotion-install-e08, based on E07 293f63e18e6b123900594865229fa1ba687ff198. Development identity is 1.0.0-dev.promotion-install.1. This coordinated source checkpoint advances TARGET to12, installs the two dedicated budget tables and registers41 preserved stores while retaining the original39 as a separately named history partition. Internal lifecycle definitions become144; the23 feature flags and372 product classifications remain unchanged. Source development does not modify any installed site or protected database.

Installation preflights both structures before DDL, verifies each created table and then both tables/canonical records before normal migration publication. Correct empty interrupted structures can resume; incompatible or populated partial structures refuse before DDL. Publication-aware diagnostics remain distinct from E07's physical same-owner storage checks; future native issuance must require installation/publication readiness as well as current owned money/rule evidence. Migration seeds no funding account, reservation, eligibility family, merchant value, adoption option or writer.

The current-install native fixtures and receipt verifiers move together to schema12/current41, while historical schema9/10/11 fixtures and immutable previous/reader packages keep their original claims. The older reader's retained39 comparison is separate from preservation of the two new opaque stores. Default/explicit/standalone uninstall and reinstall preserve all canonical and unknown budget rows. Existing boot-time forward migration behavior remains: registering this migration can upgrade an already-active package on an ordinary request, without a new admin-only gate.

Four disjoint lanes implement inspection/migration, preservation, current native qualification, and physical recovery/publication tests. Root owns central source identity/package/CI/publication. E07 push39677602799 passes all six non-WordPress jobs including399 SQL tests/7,461 assertions with all15 new cases non-skipped; WordPress qualification remains running. E08 source review finds no blocker. Targeted PHP checks total700 tests/4,413 assertions with one existing skip;55 strict receipt controls,40 independent missing/false opaque-store probes, all changed-file syntax checks and both control planes pass. Exact142 prior declarations remain unchanged. Twelve new mandatory SQL cases have a separate non-skipped CI minimum; exact committed packaging and fresh SQL/native execution remain separate pending gates. No SSH/site operation, live merchant/adoption/payment, merge or deployment. Format3 admission, default promotion mounting and native checkout/order writing remain OFF and unfinished.

# Historical implementation — E07 durable promotion SQL and merchant eligibility — 2026-10-21

The owner requests continued implementation in parallel on `ws3/promotion-sql-e07`, based on E06 `a161c462088266fe9bef871fd8ce1acc213065fc`. Development identity is `1.0.0-dev.promotion-sql.1`. E06 push39659859417 and E05 push39654298885 passed all eight CI jobs. This task adds dedicated account/reservation SQL storage and a merchant eligibility family using the existing immutable rule lifecycle. Explicit policy payloads select the full fixed promotion and budget account; frozen planning evidence and current checks bind the same stable published-policy reference. Predicates use only captured offer, zone, currency and explicit product membership, with declared priority and refusal on equal rank.

The SQL adapter uses the caller's live transaction, exact canonical row mirrors, full expected-state CAS, current account-before-claim reads and bounded payload transport. A request-aware optional store contract avoids stale claim discovery under a preopened repeatable-read snapshot without caching transaction authority. A request-aware eligibility contract verifies the merchant-selected account before held-budget proof. Expiry never automatically releases budget; explicit action authorization and caller rollback remain mandatory. Allocation is not payment.

Two new table definitions are unregistered provisioning contracts for explicitly owned fixtures. They are not migrations and normal activation remains schema11 with39 preserved stores. Migration discovery would execute a future migration file without a TARGET ceiling; schema12/41-store installation must later coordinate migration, target, manifest and preservation checks. No option ledger, Q03 counter reuse, default account, merchant configuration, profile registration, format3 admission or order writer is introduced.

Independent source review finds no blocker and20 additional codec/history probes pass. Final targeted regression passes727 tests/2,557 assertions with one existing skip;29 changed PHP lints, both control planes and whitespace pass. Fifteen new mandatory SQL tests (budget9, eligibility6) have separate non-skipped CI minimums. Exact committed package and fresh physical CI remain separate pending gates. Local PHP is8.3.6; local MariaDB socket startup was blocked by the sandbox, so physical concurrency checks use the owned CI database. Preserve protected master,23 flags,372 classifications, original quote/order/payment/shipment history and adoption OFF. No SSH/site operation, live payment, merge or deployment. Coordinated installation, full operational reconciliation and native checkout/order issuance remain subsequent work.

# Historical implementation — E06 promotion budget and current evidence — 2026-10-21

The owner requests continued product work in parallel on `ws3/promotion-authority-e06`, based on E05 `6a5a3b7cdb3ff658f11a19221ce59e2e7f3f78b9`. Development identity is `1.0.0-dev.promotion-authority.1`. Four disjoint lanes implement private budget requests/state/reservations, caller-owned transactional reserve/commit/release orchestration, a header-bound original-source/fresh-native/current-campaign composite guard, and current held-budget verification. Every request binds the complete newly compiled quote, not only its original planning quote. Explicit monetary limit/held/spent accounting stays separate from Q03 rate-limit counters. Root adds finite validity intersection that preserves the earliest exclusive source, quote, eligibility or reservation deadline.

The store and eligibility interfaces are trusted same-owner seams; there is no concrete durable campaign ledger or merchant policy adapter yet. Source tests over synthetic stores do not prove production persistence/concurrency. No existing format3 admission tripwire, default registration, model acceptance or native writer is widened. Budget allocation is not payment, expiry never automatically releases funds, and cancellation/refund/no-effect pointer retirement cannot independently authorize release. The explicit trusted action authorizer remains required. Unknown or exhausted funds refuse; an existing owned held claim does not require free balance to remain positive.

Independent source review passes166 new tests/630 assertions plus eight separate source-boundary probes. Root combined new/retained promotion, commercial, source/provider, promise, lifecycle and identity checks pass545 tests/2,303 assertions with one existing skip on PHP8.3.6. Both control planes and whitespace pass. Exact committed package verification and fresh CI remain separate checks. Root coordinates central leases, identity, exact-source package and CI publication. Schema11/39 stores/23 flags/372 classifications, protected master, original quote/order/payment/shipment history and adoption OFF remain preserved. Only source, owned synthetic tests and disposable CI are used. No new store/migration, merchant configuration, SSH/site operation, payment, merge or deployment. Concrete durable budget persistence/reconciliation, header-aware issue handoff, native checkout/order writing and broader promotion/promise/FX composition remain unfinished.

# Historical implementation — E05 fixed-promotion compiled quotes and forward readers — 2026-10-21

The owner requests continued product implementation with independent work in parallel. This branch `ws3/fixed-promotion-format-e05` starts from E04 `ac4f18388054f7cc1c3d803acccfa2f1b4bfcc39`. Development identity is `1.0.0-dev.fixed-promotion-format.1`. The separate fixed-promotion profile compiles original legacy quote provenance, frozen campaign rule/plan and actual fresh native discounted prices and taxes into context/terms/header format3. Explicit mixed groups retain unadjusted prices. Existing formats1/2 and their body digests are preserved.

Forward snapshot envelope3 and outer version4 retain exact money and safe rule/plan references, without private funding, economics or provider context. These are reader-first contracts. Neither synthetic history nor frozen planning evidence authenticates origin, reserves campaign funding, proves payment or provides current acceptance authority. New issued rows may be decoded; accepted format3 rows refuse under the existing usable-at invariant. The new provider requires explicit injection and does not register by default. The direct admission gate, durable service and operation profile refuse format3 before authorizers, provider capture or storage writes. Forward readiness is reader_only=true and writer_enabled=false.

Root coordinates format leases, refusal boundaries, records, version and publication. Independent source review passes106 new tests/444 assertions and468 retained tests/2,732 assertions; eight old canonical byte hashes remain identical. Direct admission aliases and payment paths refuse before callbacks or SQL, and matching capture leases remain unused. Root combined quote/order/promotion/currency/economics/schema checks pass1,875 tests/11,280 assertions with one existing skip on PHP8.3.6. Both control planes and whitespace pass. Exact committed package verification and fresh CI are separate checks. Parent PR118 push39647275611 passed all eight CI jobs; no original-receipt audit is claimed. Native provider mounting, campaign/budget reservation and timed authority, order writing, promotion+promise composition, FX integration and merchant enablement remain unfinished. Schema11/39 stores/23 flags/372 classifications and adoption OFF remain unchanged. No site/SSH, merge or deployment occurs.

# Historical implementation — E04 native commercial capture and replay — 2026-10-21

The owner asks for parallel progress. Two disjoint lanes on `ws3/commercial-handoff-e04`, based on E03 `2eb3fb4596d22e771e8b5d2cb3f68b71086c8b63`, implement an explicit fresh-native capture bridge for fixed planned discounts and a private, bounded commercial evidence packet for export/read-back. A third lane adds strict frozen-fact replay methods needed by that packet. Development identity is `1.0.0-dev.commercial-handoff.1`.

The capture bridge uses retained native money/tax guards through an explicitly injected source. It verifies fresh cost and taxes against the planned discounted amount; it does not apply discounts to Woo, register a provider, produce accepted terms or reinterpret legacy promotion-none profiles. It refuses promise v2 before capture: changed tax/money context cannot silently invalidate an original promise capture.

The private packet retains complete original header/context/terms, economics and explicit per-component promotion/currency states. Conversion basis and component-specific material identity are explicit; no default promotion/FX ordering is chosen. Pure historical replay calls no provider or current configuration and confers no authentication/freshness/payment authority. Unknown formats remain opaque. Existing public snapshot extensions and order formats are unchanged. All three lanes are implemented and independently reviewed. New tests pass98/347 (native bridge32/108, carrier replay29/115, packet37/124). Root combined checks pass503/2,033 with one existing skip on PHP8.3.6; nine source lints, both control planes and whitespace pass. Mixed discounted/unadjusted groups retain exact native aggregates, packet factory references stay detached, and same-price cross-component receipts refuse swaps. The optional independently retained packet digest checks integrity; decoding alone does not authenticate origin. Exact committed package verification and fresh CI remain separate.

Schema11/39 stores/23 flags/372 classifications, protected master, historical order/payment/shipment data and adoption OFF remain unchanged. Native quote-profile, checkout/order writer, persistence, merchant enablement and physical certification work remain unfinished. No SSH, site operation, merge/deployment or production release.

# Historical implementation — E03 provider-neutral convert-once currency boundary — 2026-10-21

The owner's continuation implements approved Wave2 package4 on `ws3/currency-convert-once-e03`, stacked above E02 `6096611c12ffe6ee093e992eb94eff51968fa4f2`. Development identity is `1.0.0-dev.currency-convert-once.1`. The bounded contract delegates an explicit base-to-presentment/charged conversion to one injected provider, preserves immutable source/result and conversion provenance, and reuses a matching receipt without another conversion call. Unsupported currency chains and changed source, target, provider or context refuse. This service performs no rate multiplication or rounding and claims no durable/concurrent exactly-once execution.

The three source files and two behavioral test files are implemented. New tests pass48/169; the combined relevant currency/economics/promotion/quote/native-receipt/schema check passes405/1,686 with one existing skip on PHP8.3.6. Provider-chosen rounding and a long exact rate string are preserved; unavailable is never zero. A changed adapter identity is refused before receipt reuse. Trusted callers must supply unconverted base money and complete context; plain QuoteMoney has no origin provenance. Independent final source review and a second new-suite execution pass48/169 with no blockers. Fresh exact committed CI remains separate. Native quote/context formats, existing native provider currency guards and Woo tax/money capture remain unchanged. No production provider is selected or mounted; native currency adoption, quote/cache/order integration and physical adapter certification remain unfinished. Schema11/39 stores/23 flags/372 classifications, protected master, history and adoption OFF are unchanged. No SSH, site operation, merge, deployment or merchant configuration.

# Historical implementation — E02 fixed delivery promotion planning — 2026-10-21

The renewed owner continuation implements approved Wave2 package3's single-fixed promotion subset on its own branch above E01, with development identity `1.0.0-dev.delivery-promotion-fixed.1`. Four pure classes calculate an explicit fixed discount without changing base Rate Cards, native taxes, current quote terms or E01 cost/subsidy observations. A 12.50 list price and 3.00 discount produce 9.50; zero and full-list discounts remain explicit. No clamp, stacking winner, percentage rounding or merchant default is introduced.

Only completely bound terms/component/rule/provider evidence and affirmative eligibility/budget planning states produce a hypothetical pre-tax plan. Unknown, exhausted, ineligible, malformed, changed-context, already-promoted or incompatible-money inputs refuse. Private funding and evidence never enter preview_money. Provider capture owns current planning freshness; the record makes no independent time-age, budget reservation, settlement or native admission claim.

Independent source/test review passes; E02 tests66/199, retained E01 67/111 and retained quote tests115/543 pass on PHP8.3.6; all six leased PHP files lint. Fresh committed CI remains separate. Native quote providers stay promotion-none. Promotion rule matching, durable campaign budgets/reservation, stacking/percentages, native tax and quote integration, persistence/admin and merchant enablement remain unfinished. Schema11/39 stores/23 flags/372 classifications and adoption OFF are unchanged; no site/SSH, history/queue write, merge or deployment.

# Historical implementation — E01 private economics facts — 2026-10-21

Current CI repair: both final candidates passed six non-WordPress jobs but failed HTTP readiness because setup-php truncated the requested8.5.11 to8.5 and installed8.5.12. No runtime guard was waived. The official archived8.5.11 debug build was independently downloaded and verified against archive SHA25647cb0511350e18579f95af9cfd7d2e47ed2ce5e8d88a7df140a4f5e54ed8140b and the existing executable SHA2567dda44e554d086c1c4f441796fc19f11f46055c0a276dad66181f6c11566e46a. The owned Ubuntu24.04 CI runner now restores that exact archive before a pinned normal setup step with updates disabled, then asserts the exact version/binary before qualification. Original listener binary/INI/extension/JIT checks and all native acceptance predicates remain unchanged. Failed executions remain failed; fresh exact-source CI is required. No product bytes, package identity or merchant/site state changes in this repair. Root exclusively owns the installer/workflow and this checkpoint.

Protected master remains `bcfebf7f22da7ea6272fddc096c2b22b67257b87`. The owner's renewed product-completion instruction continues approved Wave2 package3 through a bounded internal foundation on `ws3/economics-facts-e01`, separate from the staff/calendar repair. Development identity is `1.0.0-dev.economics-facts.1`.

Three new pure classes capture independently sourced estimated internal cost, actual internal cost, provider payable and subsidy against the complete existing QuoteTerms digest and exact component set. Known money reuses QuoteMoney and must match its component's currency/precision; unavailable never becomes zero. Known subsidy separately requires its funding source. Legacy cost and customer discount are not inferred as economic facts. Customer money delegates to the unchanged existing public whitelist; private export is explicit, generic JSON/PHP serialization refuses and debug output is redacted.

Independent source review found no blocker. New behavioral tests pass67/111; retained quote value/material/projection tests pass104/475 on PHP8.3.6. Five new PHP files lint successfully. Fresh committed CI is separate. This unmounted capture seam grants no access, admission or settlement authority and does not implement pricing, merchant promotions, margin computation, currency conversion, storage or a native writer. Schema11/39 stores/23 flags/372 classifications and default adoption OFF remain unchanged. No source-site operation, merge, release, deployment or merchant enablement is included.

Remaining implementation follows the approved plan: complete promotions/private economics and currency boundaries, independent Return/Refund policies, labels, logistics/pickup, interfaces and productization. Existing operational, original-history and target compatibility gaps remain separately recorded; the fresh staff review site does not close them.

# Historical continuation — corrected P05 integrated; refreshed P06 qualification — 2026-10-10

The owner instructed **Approve W2-P05 for integration and implement W2-P06** and explicitly authorized publication: **“i approve.. i authorize you”**. Qualified P05 correction [PR112](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/112) is normally integrated as actual master `543275af6844d649268f9ac02db518420dac9fe6`, unchanged tree `19c237227d9f3fd9d8d16b4c7d136fad23f83b07`, ordered parents `[8a7a7815b249c7bc117a64ffcc36db1dd8f73ee0,e4340bfbeb75e8e96444ee240fff873748c36449]`. Both exact correction candidate runs38019278584/38019308681 passed all eight jobs, all879 observations and13 original archives, with separate root and independent source/package/runtime/cleanup audits. The separate actual-master run38022281912 is executing and remains a distinct required gate.

The old unchanged-master and replay failures remain FAILED. The old-production failure-only diagnostic38018515282 reproduced the 12-second deadline branch (12011ms, running child, zero output, no overflow). The controlled physical comparison38021790390 independently proves old duplicate SELECTs (one exact expected assertion) and corrected seven-test/228-assertion PASS with cleanup. Source review accepts the narrow three-class synchronous read-only repair; the timed-out Woo child's internal phase and a unique historical cause remain unknown and are not claimed. PR112 holds the completed evidence ledger and supersedes earlier conditional source-freeze descriptions.

P06 [draft PR111](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/111), original candidate `b01edcbb227568e7f5d040430b836ee7f05a9342`, already passed push38016282203 and PR38016300134: all eight jobs, all933 observations and18 originals, independently audited. That is historical candidate evidence. This normal refresh carries the exact corrected P05 master into P06, pins its predecessor package in all four producers/verifiers, preserves fixed P04 reader3b57 and the reviewed P06 production map `783af96dd98c233fc817dc5e6b92c4c89c70d854252376ee2d31068955e7b3d9`. Relative to corrected P05, production changes only the development version identity. P06 keeps18 original archives; the P05-only correction archive is not appended. Exact refreshed candidate qualification and independent audits remain pending. Neither prior b01 evidence nor candidate evidence substitutes for separate corrected-master qualification.

Root exclusively owns central records, refs/publication, CI/package/source inventories and serial qualification orchestration. Continuing finite parallel leases are read-only: `p06_candidate_scope` reviews refreshed source, packages and evidence truth; `p05_exact_source_receipt_audit` downloads and independently verifies corrected-master originals; `p06_refreshed_receipt_audit` independently derives and audits fresh P06 originals; `p05_transport_diagnosis` reviews bounded repair evidence. Agents do not commit or mutate refs/central files/services.

Permitted mutations remain repository/code/task records and exactly tracked owned disposable pinned CI fixtures. Preserve schema11/39 business stores,23 flags,372 classifications, original promise/payment/money/history and default adoption OFF. No live activation, site/provider payment, deployment, release or registry promotion is authorized. Unsupported pickup/capacity/cache and separately carried native breadth/retention/OS-kill/target-stack obligations retain their bounded dispositions. A normal approved P05 correction requires its own actual-master879/13 audit. P06 must pass fresh933/18 originals plus two bounded final freshness checks before integration handoff; this task does not integrate P06.

## Historical checkpoints — superseded only for the execution states above

# Current checkpoint — W2-P05 integrated; W2-P06 qualification

Owner authorization2026-10-09 21:42:59UTC accepts P05 integration and implements only bounded P06 under [Issue110](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/110). P05 PR109 integrated unchanged as master `8a7a7815b249c7bc117a64ffcc36db1dd8f73ee0`/tree `89c6fb79bf438fa880bbb3dfa55f0150835da2d4`; accepted candidate runs37974932537/37974937611 and their 24 original audits remain distinct. Separate actual-master run37995201494 attempt1 failed during Docker Hub container startup before SQL/WordPress execution. Attempts2 and3 both passed all 806 retained observations, then failed the first P05 HPOS bounded payment-child transport. Each failed receipt records 20 passing observations including both tracked cleanups. Root and independent downloads verify all ten original archives from each attempt against GitHub digests. Six non-WordPress jobs passed; WordPress and Required Gates failed. The exact child cause is unresolved because the old generic error combines timeout, output overflow, exit, JSON and PID checks. Exactly one unchanged-source retry of the payment-child failure was performed (attempt3); no further retry followed. Full actual-master 879 qualification remains incomplete, and the original failures retain their actual status.

[P06 operational boundary](product/W2-P06-OPERATIONAL-HANDOFF-2026-10-09.md) and [32-observation coverage](product/W2-P06-OBSERVATION-COVERAGE-2026-10-09.md) define exact package/history/lifecycle/bounds/source-read work. Development identity `1.0.0-dev.wave2-promise-qualification.1`; schema11/39 preserved stores/23 flags/372 classifications/adoption OFF remain protected. Qualification and independent original receipts are pending. No live site/database/provider payment/deployment/release or production certification is inferred.

## Historical P05/P04 status

## Historical P05 correction source-freeze checkpoint — superseded by PR112 execution ledger

# P05 integration correction within the authorized P06 checkpoint — 2026-10-10

The owner instructed **Approve W2-P05 for integration and implement W2-P06**, and explicitly authorized GitHub publication: **“i approve.. i authorize you”**. Qualified P05 PR109 integrated unchanged as actual master `8a7a7815b249c7bc117a64ffcc36db1dd8f73ee0` / tree `89c6fb79bf438fa880bbb3dfa55f0150835da2d4`. P06 candidate `b01edcb` / tree `452f567` passes push38016282203 and PR38016300134: all eight jobs, all933 observations and18 original archives per execution, independently verified exact source/packages/runtime/cleanup. P06 remains draft because the separately required P05 actual-master qualification hold is unresolved. This correction does not integrate or accept P06.

Actual-master run37995201494 attempts2/3 and source-identical transfer-ref replay38016146018 remain FAILED at the first P05 HPOS bounded payment child. Each has ten independently verified originals, all806 retained observations PASS and20 exact P05 cases PASS including both tracked cleanups; seven P05 HPOS cases and CPT/HTTP qualification are missing. The generic exception does not establish the precise cause. One diagnostic execution38018515282 at `0f17e6b90ff3ce80642963ae35c7d3dab1a4c0e4` / tree `ebaf53f`, sole parent8a7, changes only the previously reviewed failure-only fixture probe. Production/workflow/all879 checks and12-second/4KiB/16KiB/exit/JSON/PID/payment/cleanup limits remain unchanged. The diagnostic is distinct from original master qualification; its result and cause remain pending.

This branch prepares a **conditional repair hypothesis**, not a claim that the P05 failure is diagnosed. The three source classes and two physical SQL regression files are byte-identical to independently reviewed and fully qualified P06. They remove duplicate source/receipt SELECTs in one synchronous read-only prime, return materialized groups to both callers, retain ordered locks/proofs/deadlines and require a final uncached original-owner query. Nothing survives a prime or transaction boundary. All other production bytes, P05 development identity, schema11,39 stores,23flags,372classifications, adoption OFF and original promise/money/payment/history behavior remain unchanged.

Root exclusively owns this correction's central files, CI/package source inventories, refs/publication and qualification orchestration. The finite agent lease `p05_correction_preparation` covered exactly the three source classes and two new SQL files in the isolated root-created `fix/wave2-p05-bounded-source-integration` checkout. Independent `p06_candidate_scope` and `p05_transport_diagnosis` review without edits. Agents do not commit or mutate refs/services/central files. Only repository changes and owned marked disposable pinned CI SQL/WordPress fixtures with exact tracked cleanup are permitted; no live activation, payment, deployment, release, business-data deletion or destructive migration.

CI retains all879 P05 observations, twelve primary receipt archives, all eight required jobs and existing runtime/timeout pins. Seven successful non-skipped SQL cases are additionally mandatory (4 lifetime +3 query/capture), taking physical SQL377 to384. Two reused exact generic package validators build only the clean committed current development ZIP, verify its full closure and install that exact extraction in native qualification. Its ZIP/report have a separate thirteenth archive; they do not replace any primary receipt. The reviewed failure-only transport probe and its seven controlled tests preserve every original acceptance guard. No P06 pure/bounds/lifecycle receipt or operational certification is imported.

Do not merge this conditional correction or mark P06 integration-ready before the diagnostic identifies the actual transport reason, independent review establishes the source-derived repair disposition, the exact correction passes its879/13 original audit, and bounded final freshness passes. A normal merge within the owner's approved P05 integration correction must then receive a separate actual-master qualification and original audit. Refresh P06 against that corrected master and qualify its exact933/18 candidate before handoff. Earlier failed executions remain failed; future actual identities belong in the PR/Issue ledger. The current parent-source failure remains an open hold, not a waived gate.

Details: [P05 integration correction](product/W2-P05-INTEGRATION-CORRECTION-2026-10-10.md).

The earlier checkpoint below is historical and remains preserved.


## Owner continuation — W2-P05 — 2026-10-09 16:19:41 UTC

The owner explicitly instructed **Approve W2-P04 for integration and implement W2-P05**, using parallel agents. Qualified P04 `fc5bc07feba968f8a75502c0551c36fe62a17319` integrated normally through PR107 as actual master `3b57ffc53b64f485aa9ad30e2d9e5d424afc3468`, unchanged tree `dadd37ccdbb3877becc4387a9507aa5b88e2a14d`, ordered parents `[8129601f8d8a4ebb0326f3815c8312123395fb15,fc5bc07feba968f8a75502c0551c36fe62a17319]`. The separate actual-master CI37958364515 attempt1 passed all eight substantive jobs and gate113928587186. Root and independent downloads of all nine originals agree on retained806 observations,827 exact production blobs/map49a6a01a, pinned runtime and cleanup; the durable [integration ledger](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/107#issuecomment-6085490376) is separate from candidate runs37952842097/37952828069. Issue105 is completed. [Issue108](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/108) owns only P05 on `ws3/wave2-p05-native`, based on exact integrated P04 master.

Root exclusively owns central records, version/schema/bootstrap/lifecycle manifest, package/CI/source inventories, refs/publication and qualification orchestration. Finite disjoint leases: `p05_admin` owns new Configuration application/native authority adapters and PromiseConfiguration admin page/tests; `p05_customer` owns shared public presentation/PDP/Classic/Blocks/Store API view adapters and their explicitly declared presentation files/tests; `p05_shipment` owns original/current structured shipment promise application/persistence/adapters/tests and explicitly declared shipment files, proposing any additive central storage change to root first; `p05_qualification` owns separate source-derived P05 native/HTTP/browser runners/verifiers/physical tests; `p04_scope_freshness` independently reviews cross-lane architecture/security/source; `p04_receipt_crosscheck` independently verifies actual-P04-master original receipts. The customer lane additionally owns the finite PromiseShipmentLegacyEtaGuard persisted-order correction and its shipment ETA regression fixture after the admin lease completed. The shipment lane additionally owns the finite native shipment/payment parent and fresh-process child qualification helpers through final READY; qualification owns their serial execution and the HTTP/browser files. Agents do not commit, mutate refs or edit central files. Exact WS1/WS2 native/customer/server scope is authorized by this owner instruction; it does not grant unrelated workstream takeover.

P05 reuses P02 immutable lifecycle/assignment and P03 deterministic calculation with P04 acknowledged original packet/payment guards. Implement protected native editing/preview/assignment, truthful authorized localized customer parity, immutable acknowledged original shipment attachment and separately audited current prediction from genuine persisted Woo payment events. Hypothetical preview/PDP preliminary views do not mint admission authority. Published versions and original accepted promises remain immutable. Root freezes additive persistence requirements before writes; no destructive migration or cleanup authority is permitted.

Permitted mutations are repository/code/tests/task records and exact tracked objects in owned disposable local/CI SQL/WordPress fixtures. Default adoption OFF; no automatic examples or default calendars/services. Schema11 adds only the preserved private shipment_promises table: current39 stores, exact retained P04/P02 set38 and legacy set35. Quote SQL participant profiles remain unchanged. Existing23flags,372classifications, legacy quote/order/money/payment/history, and all806 retained P01-P04 observations remain protected. No live site/database/payment/deployment/tag/release, capacity holds, current-source substitution for original facts, P06 operational certification or live adoption is included. Full exact-candidate qualification and independent original receipts are required before P05 integration handoff.

P05 implementation and comparative native/HTTP qualification are complete; committed pinned CI and its independent original receipts remain pending. Native configuration wraps immutable P02 command/assignment services and scoped C07 authority; PDP preview is hypothetical. Authoritative cart/rate/placement consumers retain the base options builder, while PDP/variation and explicit browsing-location refresh receive public decoration. Saved quote projections provide final group promises. Original shipment linkage and independently revisioned current estimates are distinct; fresh persisted Woo orders fence grants and metadata writes from unsaved caller state. Staff updates use signed original command envelopes and exact revision/replay identity. Native pickup promise mapping and required capacity remain conservatively unavailable without supported adapters. This is not P05 integration approval, release or P06 operational certification. CI retains eight jobs and all806 observations, adds separate source-derived P05 proof and12 originals, and extends only the finite WordPress job ceiling to60minutes.

Comparative source freeze: PHP 8.3.6 full 4,935 tests / 29,422 assertions PASS, including the final two persisted-prediction regressions; JavaScript11files/143tests PASS, all1413 current repository PHP lint0, production-only857autoload/lint0 and both control planes PASS. Retained strict protocols17+16+9+9+9+18 and new7+2 adversarial protocol/browser controls pass. Independent review covers 46 changed/additive production PHP paths and one customer JavaScript path. Their sorted compact JSON path-to-SHA256 map is `d8dcfd30d1bba575866eea7f4657ea7e718aea43202513f12d71fb61de00651c`; full857 installed-production map379c46ac30c5578339555c5a8f6a7c547dba8278d89c30a686a94fdd1309c8c8. These are local/comparative checks, distinct from required committed pinned CI and all12 original artifacts. Final comparative native qualification passes all 27 HPOS and 27 fresh-process CPT cases, including exact shipment/configuration cleanup and native storage-mode restoration. A separate owned negative fixture prevents Woo stock effects from contaminating the paid quote. The saved-order payment child follows Woo’s real payment-form GET/customer-session lifecycle before first admission; the gateway pays exactly once without weakening original source or payment guards. All 19 HTTP/browser observations passed on the same unchanged production map. These PHP 8.3.6 / MariaDB 10.11.14 diagnostics remain separate from pinned primary CI.

## Historical P04 checkpoint

# Current status — approved P03 integrated; P04 implementation

Owner authorization2026-10-09 12:45:25UTC accepts P03 integration and implements bounded P04. Actual master `c625a59bb61493e00d10b2802a08bac852b27b26` has unchanged qualified P03 tree22c37; PR104/Issue103 completed. Separate actual-master CI37932348440 and two original audits PASS (PR104/comment6081516744), independently of accepted PR/push qualification37928818620/37928757232 (all8jobs,495/20/143+19+49,799exactproduction sources/mapce61). P04 tracked by [Issue105](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/105) on ws3/wave2-p04-handoff. [Frozen transition](product/W2-P04-PROMISE-HANDOFF-2026-10-09.md): new strict forward readers/required-promise readiness first with writesOFF, then separate gated immutable promise profile and acknowledged saved-order handoff. No new profile is qualified or adopted yet. Schema10,23flags,372classifications and all retained v1 guarantees remain unchanged. No later P05/P06/UI/live release work is authorized.

Reader checkpoint69699ecb/tree9e1d21 is independently accepted (Issue105/comment6082223622); clean root4,731tests/28,139assertions,805 production autoload/lint and both control planes pass. Guarded writers and lazy native composition are implemented and under final native/HTTP qualification. Physical source proof31tests/393assertions includes same-owner ordered absence locks, concurrent P02 mutations, calendar expiry after SQL retirement, capacity-port refusal and actual stalled owner with no replacement. New adoption staysOFF by default, with protected configure-OFF then separate enable; no new UI or live adoption. P04 is pending complete frozen-candidate qualification.


Current-master refresh: the separately approved UI PR106 merged as `8129601f8d8a4ebb0326f3815c8312123395fb15`. Its presentation source and task authorization are preserved in the normal two-parent P04 refresh. Combined source remains827 production files, map `49a6a01a08c451855b7a19770f550be8ca46c811b34ab451c626a35be5eeb6b2`; P04 production/protocol bytes, runtime pins and all806 authored observations are unchanged. Final combined-candidate CI and independent original audits remain pending; the current PR evidence comment will record their exact result.

## Historical P03 status

# Current status — P02 integrated / P03 calculation in progress

Local source freeze: six reviewed pure production classes,799total production PHP files/map`ce61ac5bf6144636a14eef7ed646e736083d78c07202f02fb8f23374b87b3932`. Comparative PHP8.3.6 full4617tests/27654assertions/no skips; new100tests/525assertions; production-only autoload/all799lint;49 authored pure vectors;9P03+9P02+16retained strict negative protocol tests and both control checks pass. Finite independent acceptance audit14manualcases/466pointwise probes passes. These local results are separate from the required pinned exact-candidate CI/original artifact audit.

Owner continuation 2026-10-09 11:45:15 UTC accepts the preceding qualified P02 handoff and continues its named bounded P03 checkpoint. P02 PR102 is integrated unchanged as `5d3d17c88327593c6204015acc1cfcaffaf43ddd`, tree3759041. Both candidate runs37920703612/37920698865 passed all8 actual jobs; separate root/independent original receipts agree on793sources/map6b8, retained495/20/143 plus P02native19, PHP4517/27128/one existing skip, SQL348/6726 including15P02 cases. Separate actual-master run37926035618 also passed all8jobs/Gate113811524090/all3steps, and independent/root original495/20/143+P0219 audits agree; durable original master ledger is PR102/comment6080636394.

[Issue103](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/103) authorizes [pure bounded P03 calculation](product/W2-P03-DETERMINISTIC-CALCULATION-2026-10-09.md). Development `1.0.0-dev.wave2-promise-calculation.1`; schema10,38preserved stores,23flags,adoptionOFF and372classifications remain fixed. Implementation and qualification are in progress; no customer/runtime writer, release or live activation is mounted. CURRENT-WORK records finite leases and environment.

## Historical P02 publication status

# Current Status — P01 integrated / P02 persistence in progress

Local pre-publication proof on reviewed793 production files/map`6b8d4d5137d912804209912acdc7f8ddc785d6e7304d5a99eeed5d59b3d98aae`: PHP8.3.6 full4517tests/27129assertions PASS; new physicalSQL15/188 and retained lifecycle/emergency61/1253 PASS on disposable MariaDB10.11.14; warning-free comparative WP7.1.2/Woo11.1.2 P02native19/19 and tracked cleanup PASS; production autoload/all793lint, protocol9+retained16 and control checks PASS. Independent source review approves exact23newproductionfiles; central review approves current38/retained35 preservation and package/CI boundaries. These local/comparative results do not replace pinned CI or original495/20/143 receipts.

Current owner instruction2026-10-09 09:11:24 UTC approves P01 and implements P02 under Issue101. P01 PR100 integrated unchanged as `d07b8eb4dd1153b629a62b800d165c5ca92bc102`, treeb2f3168; its separate actual-master37909827326 all8jobs and independent/root original495/20/143 audits pass. P02 freezes schema10/three preserved promise tables with strict immutable bodies, acknowledged lifecycle/source receipts and revisioned assignments, without a calculator or runtime writer. Development `1.0.0-dev.wave2-promise-storage.1`,23flags/site adoptionOFF/372classifications unchanged. [P02 boundary](product/W2-P02-IMMUTABLE-PROMISE-STORAGE-2026-10-09.md); CURRENT-WORK records finite leases. Implementation and exact-candidate qualification are in progress; this is not a release, activation or completion of promise calculation.

## Historical checkpoint records

# Current Status — quote closeout integrated / promise design proposed

## Historical checkpoint — W2-P01 internal contracts, 2026-10-09

Owner continuation accepts service/promise design PR #98, integrated unchanged as `68142106aa2d7ec226a31a6ccbad1f24ac1b5ad8`; Issue #97 completed. [Issue #99](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/99) authorizes only additive strict internal service/policy/calendar/component/input/result/receipt/privacy values and unmounted ports. Root and parallel finite leases are recorded in CURRENT-WORK.md. P01 implementation and independent review pass. Local PHP8.3.6 focused282/460, full4347/26726/no skips, production autoload/all770lint and control checks pass;770-source map5d95b0d02ca71929354cc617c9a7cfed1eeffcc03fb95b44dc8aaced0d74f4b8. Exact published candidate qualification remains pending. Separate actual design-master CI `37903027773` passed all eight actual jobs, executed Gate113735893384 and independent original495/20/143 receipt audits; it remains distinct from candidate execution.

Development identity is `1.0.0-dev.wave2-promise-contract.1`; schema 9, 23 flags and canonical 372 classifications remain unchanged. No active promise calculator/persistence/shopper or Woo hook is added. Site adoption is OFF. Seven quote promotions and P02–P06, target-stack physical/pilot/payment/release decisions remain separately scoped. Prior statuses below are retained history and do not override this newer checkpoint.

## Historical checkpoint records


Current checkpoint: **PR #96 closeout accepted/integrated; W2-SERVICE-PROMISE-POLICY-1 DESIGN PROPOSED, IMPLEMENTATION UNSTARTED**. Actual closeout master `62fc5d00f93b2d651b4e20ab7ad4019b92fc82dc` has the exact reviewed tree `ebff4a3d8ed24cfcace102a4de975f264552888a`; Issue #95 completed. Separate master run 37896772757 and exact original receipts are recorded on PR #96 after execution, independently of original Q06 master e81/run 37868163553.

[Issue #97](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/97) prepares the accepted Wave 2 package2 [service/promise design](product/W2-SERVICE-PROMISE-POLICY-DESIGN-2026-10-09.md), [six bounded checkpoints and quote follow-ups](product/W2-SERVICE-PROMISE-IMPLEMENTATION-PLAN-2026-10-09.md) and [ten-row traceability](product/W2-SERVICE-PROMISE-TRACEABILITY-2026-10-09.csv). Calendar/closure/cutoff/anchor/graph/capacity/required-history contracts are proposed, not implemented or physically accepted. All32 future observations are UNEXECUTED_DESIGN. Runtime, tests, schema 9,23 flags,749 production sources and all 372 registry rows/classifications/counts are unchanged. Site adoption remains OFF; cache/retention and target-stack gates remain. No deployment/tag/release or Stable 1.0 claim.

The predecessor current block below is historical and superseded by the exact integration above; original implementation evidence remains separately authoritative.

Current checkpoint: **Q01–Q06 integrated and actual-master qualified; conformance closeout under review** (2026-10-09).

Q06 PR #94 merged unchanged as `e81db297bb550d420269bc905402eaffcc428aeb`, tree `bd6e91bd826ddb774b2c68d58bb828303ca8cfd4`. Separate actual-master CI `37868163553`, attempt 1, passed all eight actual jobs including required gate `113622528828`; root and independent original receipts prove 495 primary native / 20 fresh-process CPT / 143 HTTP/browser, smoke, both actual paid/free Blocks buttons and exact cleanup. Development is `1.0.0-dev.wave2-quote-placement.1`, schema 9, 23 flags, 749 production PHP files/map `4583a62edf4d90f09aca04f042766b7cf1614df4611a7e41cb0eacd57d36c579`. [Final original ledger](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/94#issuecomment-6072335463) and [freshness](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/94#issuecomment-6072345333) distinguish candidate/master evidence; Issue #93 is completed.

The owner authorized [Issue #95](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/95)'s docs-only [conformance closeout](product/W2-DELIVERYQUOTE-CLOSEOUT-2026-10-09.md). Its 48-obligation and 12-requirement review distinguishes unsupported external-cache/provider contexts, unmounted native retention and missing wider execution evidence. It changes no frozen 372-ID classification/count. [Adoption readiness](product/W2-DELIVERYQUOTE-ADOPTION-READINESS-2026-10-09.md) requires separately authorized exact-stack staging, retained-data/rollback rehearsal, physical acceptance and an explicit pilot decision. Site adoption remains OFF; no live site was inspected or changed. RC.12 remains immutable; this is not a release or Stable 1.0 declaration.

## Historical implementation and source-freeze records

Pending descriptions below are historical and superseded by the actual-master ledger and current closeout above. Failed runs remain failed.

Current checkpoint: **Q05 integrated and verified; Q06 implemented, final qualification pending**. The seventh candidate `f6197e736010fc5899e1e2c69d8efd47cbb71bca` completed FAIL in both attempt1 runs37842188491/37842192967: six non-WordPress jobs, native495/CPT20/smoke/cleanup PASS, HTTP141 with all27 direct cases PASS, paid Blocks FAIL and free Blocks/history missing. Comparative direct late cases pass through both post-ack mutations, cross-route recoveries and native unsaved payment validation. Its full-sequence Blocks refusal is localized to the harness's second WordPress login changing only the token-bound owner session hash; no order or payment effect occurs. The reviewed fixture successor transfers the exact existing authenticated HTTP cookie jar to Chromium and preserves every actual button/payment/history predicate. Protocol18 and comparative actual paid/free buttons/history27/five fixture-cleanup cases pass, but the aggregate repeat-cleanup command flag fails; this comparative execution remains FAIL and full pinned cleanup is required. Production749/map4583, schema9,23flags,372requirements and495/20/143 qualification inventories remain fixed. Complete pinned receipts and final two-pass freshness remain required. Prior failed candidates stay failed in PR94/Issue93; live adoption remains OFF.

Eighth candidate `22c29aa4b8c94af396f0901e56539cc55941d167`, tree `6ca4d65f6eabc54393e6f4d3c5c1f0db26e8dd2f`, completed push37845537354 and PR37845543266 attempt1 with all495 primary native,20 fresh CPT,143 HTTP/browser and smoke receipts PASS, including both actual Blocks buttons, history and every cleanup/lifecycle assertion. Root and independent immutable receipt replay agree. Both whole runs remain FAIL: the WordPress verifier invocation reports `fatal: Not a valid commit name 6cc1edd6153aeb21ec9b3bbec467f7ec7b7c0799` before `quote_placement_receipts=FAIL`; actual Gates113552664456/113552144805 fail. The WordPress checkout used default shallow depth1, omitting the required integrated Q05 ancestry baseline. Root holds the finite CI/docs/publication lease and adds full Git history only to that job. No production, receipt predicate, runtime pin, source inventory or495/20/143 case changes are required. Fresh complete pinned successor execution remains required before qualification; the eighth failed runs and their passing underlying receipts are preserved separately in PR94/Issue93.

The historical task and evidence records below remain preserved; the current checkpoint and published PR94 ledger supersede their pending descriptions.

Q03 actual-master integration separately qualified: CI37589028902 attempt1 all8 substantive jobs, native412/HTTP98/smoke/cleanup, all666 exact merged-source hashes/map7bdb9b64555b1abebff539037df466c3ae0cc7022939f289e7b17ad909399777. PHP2862/17683/1skip across8.3.35/8.4.26/8.5.11; SQL250/5703 with all retained minima. PR88 merged; Issue87 completed. Exact actual-master and candidate artifact fingerprints remain separately recorded on PR88.

# CETECH Delivery Engine — Current Status

Current task: **Q03 approved and integrated; W2-Q04 retained price provider implemented / immutable candidate qualification pending**, owner instruction2026-10-07 07:41:59UTC, [Issue #89](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/89). PR #88 merged qualified825cb704 as1555d5ec19052eac4d9f1a019dbdc9aa058cf487/tree2b5ef07c37979e10e0eec3c84d45274d6e535363. Candidate37587434368 all8/native412/HTTP98/666 exact sources passed. Separate actual-master CI37589028902 passed all8jobs/native412/HTTP98/smoke/cleanup and all666 exact installed sources; old Q03 pending wording below is its historical freeze. Q04 adopts retained legacy fixed-base pricing internally; Q05/Q06 remain shopper/cart/placement adoption. Schema9 retained.

Current task: **Q02 approved and integrated; W2-Q03 implemented, candidate qualification pending**, owner instruction2026-10-07 06:40:53UTC, [Issue #87](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/87). Q02 merged normally at `64a0d1856a5fb4780ccaf98e4d79766d1695cb2e`, tree `71bf29d32433a1d3fd0933c6ec4abf7659d1bae3`; qualified candidate99e3626a/CI37581625505 all8/native392/HTTP98/643 exact installed sources. Separate actual-master CI37582927815 attempt1 passed all8/native392/HTTP98/smoke/643 exact sources; Issue #85 completed. Q03 adds internal issue/accept/reconcile/admission/retention under the approved plan; real pricing/cart/placement remain later checkpoints. Schema9 remains. Earlier pending Q02 descriptions below are historical freeze records and are superseded by this current authorization.

Current task: **Q01 accepted and integrated; W2-Q02 quote storage/readiness implemented, final qualification pending** (owner instruction 2026-10-07 05:40:20 UTC), [Issue #85](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/85). Q01 merged unchanged through PR #84 as `090a0ad535ee8faac59d2763333d762cb89ef0d0`, tree `548c3855a020d7e3035773236e2d8a90acf02d82`. Q02 adds forward schema9, strict storage/readiness and explicit preservation; [checkpoint](product/W2-Q02-STORAGE-READINESS-2026-10-07.md). Local comparative units2794/17084/1skip, physical quoteSQL30/599, retained DataLifecycleSQL30/345, JS8/102 and package/lint643/0 passed. Native WordPress and pinned immutable-candidate CI remain required before integration. Durable lifecycle/admission, the real provider and shopper adoption remain the next accepted checkpoints. Earlier pending Q01 wording below is historical.

Current task: **W2-Q01 internal quote contract, implemented / separate integration acceptance pending**, Issue#83. Approved design plus proven SQL fixture repair integrated through PR#82 as `6f3cad770a9a0cb3a3204b373ec761368097d6ef`/tree88867bb6 after complete pinned candidate CI37575281557 (all8, SQL174/4137, native380/HTTP98,615 exactsources). Q01 starts there, introduces20 internal classes and development1.0.0-dev.wave2-quote-contract.1/schema8/635productionPHP, with no storage or shopper adoption. See [implementation](product/W2-Q01-INTERNAL-CONTRACT-2026-10-07.md). Earlier task wording below is historical and superseded.

Current authorization2026-10-07 05:01:14UTC: **W2-QUOTE-LIFECYCLE-1 accepted; implement W2-Q01 after resolving the existing SQL discrepancy before integration**. Issue#83 tracks the [proven SQL observer repair](product/W2-SQL-OBSERVATION-REPAIR-2026-10-07.md); approved design PR#82 requires fresh complete pinned qualification before integration. This supersedes the proposed-task wording below. Q01 stays a separate schema-neutral internal checkpoint, with no shopper adoption or production quote storage.

Current task: **Wave 2 DeliveryQuote lifecycle design — W2-QUOTE-LIFECYCLE-1 proposed** under [Issue #81](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/81). Owner request2026-10-07 04:27:15UTC. Design baseline protected master `f6f7ee7c84c5d92753629b3884535a0d1b0deffb`, tree `e15951e301c8df6c8b03d7eb4a6adca7faaa4850`; all C01–C07 integrated, #48/#78 completed. Final master CI37570943997 passed all8/native380/finalHTTP98/smoke/cleanup, independently verified615-source map7563a483. See [proposed lifecycle](product/W2-DELIVERYQUOTE-LIFECYCLE-DESIGN-2026-10-07.md), [six bounded checkpoints](product/W2-DELIVERYQUOTE-IMPLEMENTATION-PLAN-2026-10-07.md) and [source inventory](product/W2-DELIVERYQUOTE-SOURCE-INVENTORY-2026-10-07.md). This is design only;48 future cases are unexecuted. Schema8, existing production runtime and all frozen classifications are unchanged.

Current Wave 1 work: **C01–C07 are owner-approved and integrated; shared-contract foundation complete**. At 2026-10-07 04:09:13 UTC the owner accepted W1-C07 integration. [PR #79](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/79) merged unchanged as `e6f715cb7ac56197b01d07012b2bb95f8f591810`, tree `07bd4d1801c93804220d0bfd5f32cef5978cecc2`; #78 completed. Candidate CI37567933321 passed all eight jobs, native380/finalHTTP98/smoke with independently verified615-source map `7563a48389b93dd865a279646cb5763800fdeca64a6973ed1fd616a7a909af30`. Schema8 and development identity1.0.0-dev.wave1-emergency-control.1. See the [closeout](product/WAVE1-CLOSEOUT-2026-10-07.md) for separate candidate/actual-master evidence and the next DeliveryQuote design task.

Last reconciled: 2026-10-07 during owner-approved C07 integration closeout. Actual functional-merge CI37570164128 and the documentation reconciliation are separately bound and recorded on their PRs after execution. Historical design-merge37555635522 remains failed; subsequent repairs and complete qualification do not rewrite it. RC.12 stays its immutable schema6 release. The accepted disposable profile is OPcache enabled/JIT disabled; broader production compatibility and original-runtime questions remain separate.

## Canonical repository
- Organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`
- Default/canonical development branch: protected `master`
- Owner-accepted functional `master` baseline: `e6f715cb7ac56197b01d07012b2bb95f8f591810` (C07 implementation PR#79, unchanged qualified tree; current integrated schema8). Documentation-only successors retain this functional baseline and record their exact current master on the integration PR. C02 merge `4dcfc160d7b4f5606ee899dc56216cae3aa9b882` / actual-master CI `37517302600` SUCCESS is included history. C01 merge `1f8d0bcf0a0cc98204e78fb17a0543da1839cdd1` and opening-repair merge `7add0bf3ca66f73982797c0362fb33983da975a2` remain included evidence.
- Historical repository-truth baseline before PR #46: `88c9ec09f3cfabf73b83780c0c37d397e9acdad6` (PR #43 / Issue #32; CI `35766468276` SUCCESS). Pre-opening-correction master was `637c02f182ca273b40819631d23ac8e0dcc4004f`.
- RC.12 publication merge on `master`: `78594ad8962868683726373f58f4a8b1b48e4d0e` (PR #27 / `release/rc12` onto `3d786ba6440a5f6f850d736bda0da5a5f5236c1f`).
- Later documentation commits on `master` are **not** the RC.12 tag source.
- Repository visibility: public during GitHub Free branch/ruleset protection use.
- Composer license declaration remains `proprietary`; public visibility is not an open-source license grant.

## Opening corrections — integrated, not a release

- [PR #55](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/55) integrates the approved bounded repairs for COR-001–007 and COR-010, including catalog scan/Apply and scoped-save repairs. Reviewed candidate: `c133f04243a2f53082347c48a8ca134cc4c5791a`; its tree is identical to the functional merge tree above. COR-006 P07 crash recovery and P08 backlog/liveness, COR-008/009 and reserved business/security contracts remain separate work.
- PR #51 is merged through ancestry; PR #52 and PR #53 are closed as fully included through PR #55. Original checkpoint heads and branches are retained. `CURRENT-WORK.md` records owner acceptance and exact ancestry.
- Candidate CI `37500871628` and `37500861793` passed all eight jobs on attempt 1. The actual merged-master run `37502749839` also passed all eight jobs on attempt 1. Independently downloaded candidate and merged-head receipts each verified 207 native and 66 authenticated HTTP unique PASS cases, required assertions, cleanup and the physical installed-source map; the separate Store API smoke also passed.
- Qualification runtime: PHP 8.5.11 / WordPress 7.1.2 / WooCommerce 11.1.2 / MariaDB 11.4.13; HPOS active, schema 6. The two disposable CI web listeners retain OPcache and disable JIT. This explicit CI profile is qualified; it does not certify the original JIT-1235 profile or redefine commercial PHP support.
- Production PHP: 494 files; installed map `48242148bc1fcada1275cb96553ea7d6e4efc3814ee4cc8f5886ed9ee11a8238`. Historical RC.12 package counts below belong to that immutable package.
- Ordinary CI MariaDB coverage is separate from earlier local targeted groups `cor005-real-db` (2 tests / 19 assertions) and `cor007-real-db` (12 / 105), which remain excluded from that job.
- [Issue #54](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/54) retains exact upstream JIT-defect attribution, qualification of the original runtime and the separate historical timeout. Real cores locate the fault in PHP JIT scheduling; the CI-only mitigation is integrated. Historical failed receipts remain failed evidence.
- Current development task: [Issue#81](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/81), Wave2 DeliveryQuote design. C01–C07 are accepted/integrated; #48/#78 completed. Wider capability, compatibility and release qualification remain separate from the completed Wave1 foundation.

## PHP runtime policy
- Canonical policy: `docs/PHP-RUNTIME-POLICY.md`. Realignment evidence: `docs/PHP-85-CI-REALIGNMENT.md`.
- **Supported / certified range today:** PHP 8.3, 8.4, and 8.5.x.
- **Minimum supported PHP:** 8.3. Plugin header, Composer, and activation guard are `>=8.3`. This is the WordPress + WooCommerce recommended floor, not the oldest version those products can still boot.
- **Recommended production PHP:** latest qualified stable release under the canonical policy. The 21 September policy checkpoint recorded PHP 8.5.10; opening-correction CI now explicitly exercises PHP 8.5.11 in the profile above. A CI patch pin is not a new production-runtime certification.
- **CI:** PHP 8.3 Minimum Supported (blocking); PHP 8.4 Compatibility; **PHP 8.5 CETECH Production Target (blocking)** plus MariaDB and WordPress/WooCommerce jobs. PHP 8.1 and 8.2 must not have support lanes.
- PHP 8.6 pre-release must not be used as a production target. A later stable PHP line is added only after WordPress, WooCommerce, Delivery Engine, and CETECH stack qualification.
- PHP 8.1 and PHP 8.2 are **not** supported and must not be advertised.
- Historical RC.12 required checks (`Runtime PHP 8.1`, `PHP / PHPUnit 8.2`) are provenance of that tag. They must not be read as current commercial support. FLAIROC Stage 0B already recorded PHP 8.5.5.
- Isolated PHP 8.5 QA Compose: `docker/php85-qa/`.


## Current tagged release candidate — RC.12
- Tag: `v1.0.0-rc.12`
- Type: unsigned annotated `tag`
- Annotated tag object: `89f34883a017b8bb66f98db345fbbae0d8dd72b0`
- Peels to release source: `78594ad8962868683726373f58f4a8b1b48e4d0e`
- Merge parents: `3d786ba6440a5f6f850d736bda0da5a5f5236c1f` + `3eaf0d77ec277e4bb3b633da1ac08e08c0790ea7`
- Merge time: `2026-09-20T16:15:10Z`
- Version identity: `1.0.0-rc.12`
- Schema: `6`
- Protected-master CI run `35522128310`: SUCCESS (Runtime PHP 8.1, PHP/PHPUnit 8.2, JavaScript/Vitest, Control Plane)
- GitHub prerelease: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/releases/tag/v1.0.0-rc.12
- Final ZIP: `cetech-woocommerce-delivery-engine-1.0.0-rc.12.zip`
- Final ZIP bytes: `1,767,204`
- Final ZIP SHA-256: `46508c566b505ac470ae94d2829de068e4ff1b53bb4c22e31201e038fb8e03d1`
- Downloaded GitHub asset verification: PASS (same filename, bytes, SHA-256)
- Production-package verifier: PASS
- Packaged PHP lint: `498 files / 0 failures`
- Clean install: `clean version=1.0.0-rc.12 schema=6 tables=all_tables_ok geo=schema6_tables_ok`
- RC.11 → RC.12 upgrade/retention: PASS (`sentinel=1 zone=1 rule=1 option=keep_me coverage_groups=1`; schema-6 conversion `completed`, `converted=1`, countries `GH`)
- Optional geo.16 → RC.12 identity smoke: PASS (`sentinel=1 option=keep_geo16`)
- Issue `#26`: CLOSED / COMPLETED
- Evidence: `docs/RC12-PROMOTION.md`
- Do not move this tag to a later documentation commit. Do not rebuild the ZIP for docs closeout.

## Post-RC.12 merged — Issue #32 (not a release)
- Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/32
- MERGED via PR #43 onto protected `master` `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`.
- Issue #32: CLOSED / COMPLETED.
- Development identity: `1.0.0-dev.attention-count.1`.
- Schema: `6` (unchanged).
- Requires PHP: `8.3`.
- Runtime / package-source SHA: `6e9e2ea71796170afb0508954a18d206cdc7017a`.
- Final PR head: `658316f775099b0dfc43205bb07c905681a4af10`.
- Runtime CI `35759882478`: SUCCESS.
- Final-head PR CI `35760847856`: SUCCESS.
- Post-merge master CI `35766468276`: SUCCESS.
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.attention-count.1.zip`.
- Bytes: `1,848,319`.
- SHA-256: `2027e5941916dff0302d7e2e545de5a2fd027f0c8f1615d97e3991dcfc5fdcb3`.
- Training physical QA: PASS; canonical aggregate / Overview / menu badge = `8 / 8 / 8`.
- Last recorded training runtime: `1.0.0-dev.attention-count.1`; this historical physical qualification is not RC.13, production promotion or a fresh live-site observation.
- Evidence: `docs/POST-RC12-NEEDS-ATTENTION-COUNT.md`.

## Post-RC.12 merged — Issue #31 (not a release)
- Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/31
- MERGED via PR #42 onto protected `master` `d66e5e366612468d7e5b4874e1a4290c5d2582cc`
- Post-merge CI: run `35756300042` SUCCESS
- Development identity: `1.0.0-dev.shipment-order-read.1`
- Schema: `6` (unchanged)
- Requires PHP: `8.3`
- Runtime / package-source SHA: `528e7c8b264f74848c85d77e172a25213db327dc`
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.shipment-order-read.1.zip`
- Bytes: `1,845,224`
- SHA-256: `3dd4602ceedc26bcc3dab673271752c47c47e02d7e90e81fed832e9d498539f5`
- CLOSED / COMPLETED. Not RC.13. Training at Issue #31 closeout used `1.0.0-dev.shipment-order-read.1`; current training has since advanced to `1.0.0-dev.attention-count.1`.
- Evidence: `docs/POST-RC12-SHIPMENT-ORDER-READ.md`

## Post-RC.12 merged — Issue #39 (not a release)
- Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/39
- MERGED via PR #41 onto protected `master` `baa273c2af00672d22268eb6968012ac7a51cfdd`
- Post-merge CI: run `35726028768` SUCCESS
- Development identity: `1.0.0-dev.address-ux.3`
- Schema: `6` (unchanged)
- Requires PHP: `8.3`
- Previous `.1` runtime SHA: `797bdce784ecbe44996cc0e46dbe8d2920a52b08`
- Previous `.2` runtime SHA: `b2acea7ba75f31cd6a7851fcbf594bf798bfd79d`
- Runtime / package-source SHA: `3f02e10e377d641d7036357242033b3f6534cbb8`
- PR: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/41
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.address-ux.3.zip`
- Bytes: `1,841,881`
- SHA-256: `5f17abeaedbb078055963d98cd5992fadf9ed7ec2c8d7f5f1d785a294129265d`
- Frozen `.2` ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.address-ux.2.zip` (`1,837,972` bytes, SHA-256 `e914efc0ee73132efffab6899cf9627ac3d01ecf6b579f190b3050cdd43e117f`)
- Frozen predecessor ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.address-ux.1.zip` (`1,832,223` bytes, SHA-256 `2a28798140fafaa7d7b4e20bc4e3878fd1949f7014af854b002960a3ae1120fe`)
- CLOSED / COMPLETED. Not RC.13. Training at this issue's closeout used `1.0.0-dev.address-ux.3`; later recorded qualification advanced to `1.0.0-dev.attention-count.1`.
- Evidence: `docs/POST-RC12-CART-CHECKOUT-ADDRESS-UX.md`

## Post-RC.12 merged — Issue #38 (not a release)
- Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/38
- MERGED via PR #40 onto protected `master` `ed3753d73e262d8c7467fa936e3e36060960e58c`
- Development identity: `1.0.0-dev.pdp-precision.2`
- Schema: `6` (unchanged)
- Requires PHP: `8.3`
- Runtime / package-source SHA: `05b9b393995c884d30f9d761513fcd3beb622587`
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.pdp-precision.2.zip`
- Bytes: `1,824,330`
- SHA-256: `1b0c02dcc88370b5f8535c59acb00ad3773a95b2182539b333778046a913557e`
- Frozen pdp-precision.1 ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.pdp-precision.1.zip` (`1,823,157` bytes, SHA-256 `5773b5b2eb9d63bdc5e8b92bbbd156cb78ba4bc4deb74f34db105673542d3fda`)
- CLOSED / COMPLETED. Not RC.13.
- Evidence: `docs/POST-RC12-PDP-LOCATION-PRECISION.md`

## Post-RC.12 merged — Issue #35 (not a release)
- Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/35
- MERGED via PR #36 onto protected `master` `514ddfc1b4d10b2171d3d3bae020381198c59d2b`
- Development identity: `1.0.0-dev.geo-country.5`
- Schema: `6` (unchanged)
- Requires PHP: `8.3`
- Runtime / package-source SHA: `ea2d412326e369bc7168b9de0dd01875673e71e6`
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.5.zip`
- Bytes: `1,812,883`
- SHA-256: `0d02bb14e2652dd635e858a3c8d4e65c233de81872ffa772225530a525e3d54d`
- Frozen geo-country.4 ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.4.zip` (`1,798,250` bytes, SHA-256 `2fc4996c0e403c41bce200dec5be680a05f56f9e666b5a3e4a13a400dba24354`)
- Frozen geo-country.3 ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.3.zip` (`1,797,361` bytes, SHA-256 `a86a940d8ebac2c9e293dad8690ed8f121b5afa66cb3f1e0537f3f4152b26c66`)
- Frozen geo-country.2 ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.2.zip` (`1,798,020` bytes, SHA-256 `6e7f76e2d218a01e2af401cc646471dc2ce27afc8a52c6ad15e671df0b88122c`)
- Frozen geo-country.1 ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-country.1.zip` (`1,794,741` bytes, SHA-256 `216e3a28d37e6af5f6cf97a69ef362dc946a94b1d26d8508c3d747d582198a6c`)
- PR: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/36
- CLOSED / COMPLETED. Not RC.13. Training physical repair QA COMPLETE 2026-09-21.
- Evidence: `docs/POST-RC12-GEONAMES-COUNTRY-IDENTITY.md`


## Post-RC.12 merged — Issue #33 (not a release)
- Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/33
- MERGED via PR #34 onto protected `master` `5abfab0b5078e67b158f282088022b2ac2566f22`
- Development identity: `1.0.0-dev.geo-live.2`
- Schema: `6` (unchanged)
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-live.2.zip`
- Bytes: `1,786,463`
- SHA-256: `c5ea75fd407aa32a9e443a3f883e3036785cc776dcf715a040026087fdf8c149`
- Frozen geo-live.1 ZIP (do not overwrite): `cetech-woocommerce-delivery-engine-1.0.0-dev.geo-live.1.zip` (`1,783,293` bytes, SHA-256 `6281f1b3f7d080ac9bb528fc1a117be909ac1ae05c052d6fe704cc053192ccbf`)
- Not RC.13.
- Evidence: `docs/POST-RC12-GEO-LIVENESS.md`

## Post-RC.12 merged — Issue #29 (not a release)
- Issue: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/29
- MERGED via PR #30 onto protected `master` `83effbf081e54b47ef088673c8ac18217ab9dfda`
- Development identity: `1.0.0-dev.checkout-mdest.1`
- Package-source SHA: `b07c3eb1ee3556e5b184ce072c1833ba04459cf9`
- ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.checkout-mdest.1.zip`
- Bytes: `1,778,523`
- SHA-256: `8ceb27dfa3381db23917fbcae45f31453e8588598241f0c4e9af4fd7855c5df1`
- Schema: `6` (unchanged)
- Not RC.13.
- Evidence: `docs/POST-RC12-CHECKOUT-MULTIDEST.md`

## Prior tagged release candidate — RC.11
- Tag: `v1.0.0-rc.11`
- Annotated tag object: `acaae9bfc9758cdee1b3f2ec47e94848e83f87da`
- Peels to release source: `384f564f64a2db766ae6907392e95fb366fb8533`
- Version identity: `1.0.0-rc.11`
- Schema: `5`
- Final qualified ZIP: `cetech-woocommerce-delivery-engine-1.0.0-rc.11.zip`
- Final qualified ZIP bytes: `1,545,789`
- Final qualified ZIP SHA-256: `97423a95273f6148ee855d1fb8a66c6c66e47aa20cb2c5868ecf2f84b2a9a521`
- Tag is annotated but unsigned. Do not describe it as signed.
- RC.11 is immutable. Do not move the tag or reuse/overwrite the qualified ZIP identity.

## RC.12 promotion
GitHub issue #26 — `[RC12] Promote owner-accepted geography baseline to RC.12`

- Issue `#26`: CLOSED / COMPLETED
- Release branch: `release/rc12`
- PR #27: MERGED
- Version identity: `1.0.0-rc.12`
- Schema: `6`
- Scope: release identity/bookkeeping/qualification only; no new runtime feature work
- Annotated tag `v1.0.0-rc.12` published; GitHub prerelease published
- Closeout comment: https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/26#issuecomment-5751247411

## Closed Issue #23 / merged PR #24
Issue #23 — `[POST-RC11] Canonical geography, coverage groups, cascading location UX, and delivery-card redesign`

- Sole owner: `@wbdevworld`
- Issue #23: CLOSED / COMPLETED
- PR `#24`: MERGED at `3d786ba6440a5f6f850d736bda0da5a5f5236c1f`
- Protected-master post-merge CI run `35519854001`: SUCCESS
- Owner-accepted geo.16 remains immutable qualification provenance:
  - Identity: `1.0.0-dev.geo.16`
  - Package-source SHA: `7aeb4c573d04d12d8101c0e05bc8858ff332d63f`
  - ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.16.zip`
  - Bytes: `1,764,171`
  - SHA-256: `500b09878ba08b879e5bdfdb0ea6e58726a71fe90a6994a83bb96b1e314a3925`
  - Schema: `6`

## RC.11 promotion evidence
- Issue #20 — `[RC11] Promote owner-accepted issue #18 baseline to RC.11`: CLOSED / COMPLETED.
- PR #21 release-promotion head: `b397489afc29d65a2b62a1afa57ba8ca6db3ef67`.
- PR #21 merged to protected master release source: `384f564f64a2db766ae6907392e95fb366fb8533`.
- Release-branch CI run `35144591865`: SUCCESS on Runtime PHP 8.1, PHP/PHPUnit 8.2, JavaScript/Vitest, Control Plane.
- Protected-master post-merge CI run `35144871814`: SUCCESS on all four required jobs.
- Final package + isolated WordPress qualification run: `35145775218` SUCCESS.
- Production package verifier: PASS.
- Packaged PHP lint: `448 files / 0 failures`.
- Clean install evidence: `clean version=1.0.0-rc.11 schema=5 tables=all_tables_ok`.
- RC.10 → RC.11 upgrade/data-retention evidence: `upgrade version=1.0.0-rc.11 schema=5 tables=all_tables_ok sentinel=1 option=keep_me`.

## Included post-RC.10 correction — issue #18
Issue #18 — `[P2] Show authoritative delivery price on product-page delivery options`

- Sole owner: `@wbdevworld`
- Final accepted candidate source: `ecb0a69f8375712de0fb7ba53ee8abe2f9b438e5`
- Accepted development identity: `1.0.0-dev.pdp-price.3`
- Integrated via PR #19 into protected master commit `35ff33d6788279a3ab75627f9eb9756b988ccb04`
- Issue #18: CLOSED / COMPLETED

Accepted behavior includes authoritative customer-facing PDP delivery prices, quantity-aware pricing, price + configured ETA display, fail-closed unquoted delivery, Delivery/Pickup capability before location, no implicit store-base-country PDP quote, correct ETA singular/plural, public-safe price payloads, Storefront/WoodMart/mobile qualification, and qualified PDP/cart/checkout amount parity.

## Prior immutable release — RC.10
- Tag: `v1.0.0-rc.10`
- Annotated tag object: `506c2067f963ae7c1753fa0f32e2dc0e30b7d1c9`
- Peels to: `d1409258caf1a90675b689ab105471460de4c713`
- Version identity: `1.0.0-rc.10`
- Schema: `5`
- Original final ZIP SHA-256: `6f451d7d898773ee257ab511f41c7db7199b1017c02de638a7b27c2c584d39ab`
- RC.10 remains immutable.

## Historical baselines preserved
- `v1.0.0-rc.9` peels to `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`.
- Earlier published tags remain immutable.
- Historical qualification branch/PR #12 remains provenance only; do not use it as current team baseline.
- Historical recovered `feat/post-rc9-customer-ux` remains provenance only.
- WPML overlay remains separate on `feat/post-rc9-wpml`; not part of RC.10, RC.11 or RC.12 core certification.
- Superseded RC.10 docs-only PR #17 is closed and must not be merged into current master.

## Certification boundaries
- WPML/WCML certification: separate / not included.
- WP Rocket certification: separate / not certified.
- WoodMart physically qualified on 8.4.1 for issue #18 PDP-price acceptance.
- Issue #18 owner QA used WordPress 7.1 / WooCommerce 11.0.1 in its isolated lab.
- RC.11 package clean-install/upgrade smoke used isolated GitHub Actions WordPress/WooCommerce containers; WooCommerce 11.1.0 was installed during the successful run.
- RC.12 isolated qualification used WordPress `php8.2-apache` + WooCommerce `11.0.1` in Compose project `cetech-rc12-qual`. That isolated lab is historical provenance, not a FLAIROC or production claim, and is **not** the CETECH PHP 8.5 production target. Current isolated QA Compose is `docker/php85-qa/` (`wordpress:php8.5-apache` + MariaDB 11.4).
- Historical RC.12 training installation: schema 6, qualification **PASS**. Later recorded training evidence is `1.0.0-dev.attention-count.1`; neither record certifies the merged opening-correction source on that live site.
- FLAIROC: **NOT DEPLOYED**.
- Production: **NOT DEPLOYED**.
- CETECH Pilot: **NOT STARTED**. This tag/prerelease is not Pilot authorization.
- POS repository / VitePOS: outside scope.

## Development baseline
- Immutable RC.11 release anchor: `v1.0.0-rc.11` / `384f564f64a2db766ae6907392e95fb366fb8533`.
- Current tagged RC.12 release source: `v1.0.0-rc.12` / `78594ad8962868683726373f58f4a8b1b48e4d0e` (schema `6`).
- New work should branch from latest protected `master` unless the owner names a different surface.
- Release source and later documentation commits are deliberately allowed to differ; never move a release tag to follow later master commits.

## Owner-accepted product truth
- `PRODUCT-TRUTH-BASELINE-1` was accepted by the owner on 2026-09-19 with six decisions resolved in `docs/product/DECISION-CONFLICT-REGISTER.md`. Decision 7 (21 September 2026, superseded) incorrectly retained PHP 8.1 as a commercial floor. Decision 8 records the owner correction: supported/certified PHP 8.3–8.5.x, minimum 8.3, CETECH production 8.5.x.
- The approved 372-Requirement registry and companion artifacts live under `docs/product/`; `docs/AUTHORITY.md` defines which artifact governs each class of truth.
- Stable 1.0 scope is frozen as `STABLE-1.0-SCOPE-1`. This product baseline is not a claim that missing capabilities are implemented or that Stable 1.0 has shipped.
- Product-control-plane publication PR #25 merged at `6ee4cef088f0bda2633d4b8e37abf3e37634426b`; it is an ancestor of immutable RC.12 source `78594ad8962868683726373f58f4a8b1b48e4d0e` and the newer functional baseline above.
- Frozen Requirement IDs must not be renumbered. RC.12 promotion does not change product-registry classifications.

## Completed forensic / release-control work
- 2026-09-15 local Git/source recovery (issue #2 / PR #9).
- 2026-09-16 Desktop artifact reconciliation (PR #14): all relevant desktop artifacts accounted for.
- 2026-09-16 owner QA of pre-RC.10 qualification baseline: PASS with documented boundaries.
- 2026-09-16 RC.10 promotion completed and tagged.
- 2026-09-16 issue #18 owner acceptance, integration and post-merge CI completed.
- 2026-09-16 RC.11 promotion, final package qualification, annotated tag and closeout completed.
- 2026-09-19 product-control-plane publication PR #25 merged to protected master.
- 2026-09-20 Issue #23 / PR #24 merged as owner-accepted `1.0.0-dev.geo.16`; protected-master CI `35519854001` SUCCESS.
- 2026-09-20 PR #27 merged as RC.12 identity promotion; protected-master CI `35522128310` SUCCESS; annotated tag `v1.0.0-rc.12` / `89f34883a017b8bb66f98db345fbbae0d8dd72b0` published; GitHub prerelease published; Issue `#26` CLOSED / COMPLETED.

## Geography provenance (immutable; not rebuilt)
- Frozen physically qualified geography/runtime candidate: `1.0.0-dev.geo.15` / `eb9f4e4893b32ec8e59d47fb90e13adfed85e78e`; schema `6`. ZIP `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.15.zip`, `1,763,366` bytes, SHA-256 `95340eed81a3e7d7b5a7bddb3635a825870a6916d5716d27e6ea12f1fcd68690`.
- Frozen rejected physical-QA candidate: `1.0.0-dev.geo.14` / `ea53d0486593199898269479be624de262127692`; schema `6`. ZIP SHA-256 `cc3f320e8f9856c08d0e5c50ee05f41363db8a8ba79135bde23befdeaa212cc7`.
- Frozen rejected technical-review candidates geo.1–geo.13 remain provenance only. Do not rebuild or reuse those package identities.
- Architecture: `docs/POST-RC11-CANONICAL-GEOGRAPHY-COVERAGE.md`.
- Historical qualification PR `#12` / `1.0.0-dev.qual.1` is provenance only.

## Recorded P3 / later cleanup (not in RC.12)
- Blocks script dependency warning.
- Variable ETA copy/prefix.
- Lamp default selection UX.
- Optional Blocks totals evidence polish.

## Documentation and next development task
- The opening-integration closeout reconciles current work, status and product execution notes only. The historical `docs/rc12-training-realignment` stream describes RC.12 training material; it is not the current repair task.
- Later documentation commits on `master` are **not** the RC.12 tag source and must not receive the `v1.0.0-rc.12` tag.
- Training's last recorded physical package is `1.0.0-dev.attention-count.1`, schema 6. This repository closeout did not query or change the site; earlier training qualification is not Stable-1.0 certification.
- CETECH Pilot: **NOT STARTED**. FLAIROC: **NOT DEPLOYED**. Production: **NOT DEPLOYED**.
- Issue #48's Wave 1 packet is owner accepted as `WAVE1-PLAN-1`. C01/C02 primitives and explanation contracts are integrated. The current output is W1-C03-STORAGE-1's concrete storage proposal; later durable implementation/rule/history/cleanup/adoption units retain their explicit dependencies.

## Remaining separate work
1. Create the controlled CETECH production Pilot only after a separate owner authorization; this tag/prerelease is not that authorization.
2. Certify the Stable 1.0 launch floor: WooCommerce/HPOS, Classic, Blocks, Storefront, current supported WoodMart, B2BKing, and FOX/WOOCS.
3. Certify WPML/WCML before Stable 1.0 only if it will be advertised as supported at launch; keep other optional targets explicitly uncertified until evidence exists.
4. Recorded P3 cleanup remains later work.
5. Do not start Stage 15.
6. Do not create RC.13 from this closeout.

## Deferred / not started
- Stage 15 is NOT STARTED.
- Advanced carrier APIs.
- Richer customer shipment timeline/emails where outside current V1 scope.
- Later POD/OTP/QR/GPS/photo/driver workflows unless scope is explicitly reopened.
