# W1-C07 emergency-control design

Proposal: **W1-C07-EMERGENCY-CONTROL-1 — OWNER REVIEW REQUIRED; NOT IMPLEMENTED**.

The owner approved W1-C06 integration and requested this design at2026-10-07 00:45:41UTC. [PR#75](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/pull/75) merged its qualified head `0ebeca8d33b17fec9fd70cb7706a50d447ad8d91` unchanged normally as `9cbdc6b5f0b9a509cad137d7379d96809f6d240e`, tree `66536ad4e5d47d820baa18569c729357248972ed`. [Issue#76](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/76), under #48, tracks design only. Production remains `1.0.0-dev.wave1-data-lifecycle.1`, schema8,591PHP files; this proposal changes none of those sources or settings.

## What the owner would be approving

A store administrator could pause new Delivery Engine delivery/pickup checkout admissions across Classic, Blocks and direct Store API. A separate, durable global control overrides checkout modules without switching their saved flags off. Existing paid orders, snapshots, rules, configuration and shipment operations continue under their existing authority.

**Concrete additional payment policy proposed here:** the pause also holds new payments on **all unpaid engine-managed orders**, including legacy pending orders, through Woo's native order-payment page. This is an explicit proposed impact, not a claim that the earlier D10 decision already settled unpaid-order payment policy. It closes the existing-order payment path that bypasses normal checkout hooks. The pending order is retained without repricing or cancellation. A previously admitted payment already in progress may finish; gateway callbacks and paid-order shipment work continue.

After resuming, carts need fresh eligibility, choice, destination and quote validation. Order-pay also needs a fresh admission using coherent stored order facts and current eligibility, with the quote matching the saved order's money facts. If that cannot be proved, the payment is refused and the shopper returns to checkout; C07 never edits the saved order price to make it pass. No per-order admission metadata, new snapshot format or historical acceptance inferred from a snapshot is proposed.

Approval values are one <=2KiB preserved state option, one C03 administrative transition profile, two-second native lock waits, finite reasons, current-site all-or-none adoption and the thirty future cases below. This approval would authorize a separately tracked implementation, not certify unexecuted runtime cases.

## Authority, inspected reuse and limits

WAVE1-PLAN-1 D10/T18–T21 govern the emergency boundary. The [source inventory](W1-C07-EMERGENCY-SOURCE-INVENTORY-2026-10-07.md) records exact flags, hooks and current unsafe fallbacks. Frozen372 Requirement IDs and their classifications are unchanged. Primary C07 rows: DE-API-002/012, DE-FLAG-001–005, DE-RULE-007. Applicable cross-cutting rows: DE-FAM-005, DE-OWN-001/003, DE-SEC-001/003/007/010; additional inspected compatibility/privacy/cost boundaries are inventoried separately. This design does not mark whole API, rollout or compatibility families complete.

The October5 approval archive's Wave1 drafts were inspected for chronology. Current accepted Wave1 plan and code outrank their broader bundled proposals. No quote/group economics, inactive-configuration recovery, protected-delete/global reset, P07/P08, general public API or mandatory snapshot sealing is added here.

Current flags are independent cached booleans, not coordinated emergency truth. Shipping/validation short-circuit when upstream flags are off; package and rate callbacks may return native Woo inputs. Woo's cached package rates can bypass the rates filter entirely. Store API update-order callbacks also run during provisional updates. These are inspected integration hazards, not new runtime regression verdicts.

## State and effective behavior

| Physical/control view | Checkout behavior | History and administration |
|---|---|---|
| Exact ready current absence | Virtual legacy `enabled`, revision1, physical ID0, initialized=false; existing module/readiness behavior remains | Actor/time are not recorded; absence does not activate a fresh installation |
| Valid `enabled` record | Existing module gates still apply; final managed admission requires fresh validation | State readable under current authority |
| Valid `checkout_suspended` record | No new engine choices, quotes or final managed admission; no engine/native fallback for an owned line | Authoring, diagnostics, historical reads and existing paid-order/shipment work remain available |
| Corrupt/unknown/oversized/unreadable/unready state | Refuse managed or unresolved admission with a safe temporary-unavailable result | Preserve the row; show truthful unavailability to authorized administrators |

An independent application control service supplies effective checkout state. Do not change `FeatureFlags::get()` to return false for every flag, disable bootstrap registration, or couple state to `cetech_de_global_configuration_version`. The23 existing values and activation chain retain their meanings. C07 does not implement their currently reserved adapters.

Current-site all-or-none is the proposed deterministic activation policy: the same qualified control/admission contract covers Classic, Blocks and direct Store API. Emergency suspension applies to every managed cart/order in that site. No random, percentage, user-ID, IP/address, currency, browser-cookie or surface-specific cohort is enabled. Undefined partial rollout is rejected. DE-FLAG-004 remains partial outside this bounded site/surface policy; future percentage rollout needs its own identity/continuity/privacy decision.

## Schema-neutral storage and ownership

Propose exact current-site option **`cetech_de_checkout_control_v1`**, autoload off at first insertion, strict JSON format1, maximum2048bytes/depth2/16nodes. Required physical fields: `format_version=1`, server `site_id`, `state` (`enabled` or `checkout_suspended`), positive `revision>=2`, finite `reason_code`, positive authenticated `actor_user_id`, positive `changed_at_epoch` from native UTC. Reject extra/duplicate fields, wrong types/site, invalid reasons and oversized/unsupported records. Existing autoload disposition is preserved on update.

Finite reasons: `operator_pause`, `incident_pause`, `maintenance_pause` for suspension; `resume_verified` for enablement. Virtual absence uses a distinct internal `legacy_default` view with initialized=false and explicitly unrecorded actor/time; it is not serialized as a fabricated physical record. Revision starts at1 because current C03 material events require before_revision>=1; first changed transition is1→2. Same-state requests are not_applicable: no state insertion/revision increment/material audit, though C03 may record their private completion.

Use a dedicated finite store and **one** bound C03 `checkout.emergency.transition` v1 profile, target `checkout:global`, authority `checkout.emergency.admin.v1`, server principal `wp-user:<current-ID>`. Declare the exact same-site native options participant alongside existing C03 operation/completion/material-event stores. Do not widen DataLifecycleOptionsStore's cache/coordinator-only write allowlist, reuse global wpdb inside an owned native transaction, or add a generic caller-supplied SQL/profile interface.

Readiness verifies standard current-site routing, InnoDB, full unique option_name and primary identities, fresh no-reconnect ownership and absence of an ambient unit. A current locking read uses the exact unique key, never a previously opened consistent snapshot. If absence is held through a decision/first insert, verify REPEATABLE-READ and actual unique-index missing-key range locking; deadlock/concurrent insert refuses after acknowledged rollback. Unknown isolation/readiness cannot grant admission.

Administrative updates compare opened physical ID (0 for absence), revision and exact old bytes under lock; update uses fixed-name/ID/old-byte CAS, or insert only against locked current absence. State, sanitized material event and durable completion are accepted together. C03 reservation alone is not acceptance. Native UTC_TIMESTAMP(6) is parsed strictly to an epoch; do not add an unapproved UNIX_TIMESTAMP SQL call or arbitrary text type to OperationSchema. Refuse backward accepted time and revision overflow.

Register this option as preserved authored operational control in the C06 manifest and inventory. Both uninstall paths preserve it, its C03 events/completions and all32 durable stores. No age expiry, reset, migration/index creation, new table or schema bump is proposed. Approved writers never delete/reinitialize the control; exact absence is only current absence, not proof of an everlasting never-created history or protection against out-of-band database edits.

## Administrative transition, replay and publication

The Settings page uses existing `manage_delivery_settings`, current site/principal/WCFM isolation and an exact nonce-protected POST action. Its opened envelope binds physical ID/revision, desired state, finite reason and original request token. Canonical intent includes those preconditions and semantic fields; correlation/request IDs remain attempt metadata. Check authority before lookup, before mutation and before replay disclosure.

| Outcome | Required behavior |
|---|---|
| Accepted changed transition | State, actor/time/revision, event and completion are committed in one C03 effects unit |
| Audit/target/checkpoint refusal | Whole effects unit rolls back; no success copy |
| Positively unsent commit with acknowledged rollback | Known rejection; preserve the opened draft/envelope for a permitted retry |
| Sent commit with lost acknowledgement/uncertain retirement | Report unconfirmed; retire owner, fresh original-key reconciliation before another effect |
| Accepted state with publication pending | Physical state remains authoritative; original token retries only recorded publication/marking |
| Same token/intent | Recorded completion, no second state change or event; current authorization still required |
| Same token with changed intent | Conflict; no state change |
| Old accepted request replayed after an opposite newer transition | Return old recorded completion and separately read current state; never restore or report the old state as current |

Publication is invalidate-only: fixed option entry plus advisory alloptions/notoptions. It never republishes a recorded value with update_option/add_option or forces an obsolete cache value. An already absent cache entry satisfies invalidation; wp_cache_delete(false) alone is not failure. Provider errors leave publication pending and truthful UI. Admission never relies on that cache publication, FeatureFlags memoization or a stored authorization grant.

Proposed copy: “New delivery and pickup checkouts are paused. Payments on unpaid delivery orders are also paused. Existing paid orders and shipments continue.” Resume: “Checkouts are enabled. Delivery choices will be checked again.” Stale form: “These controls changed. Reload the current state before submitting again.” Unconfirmed: “The control change could not be confirmed. Check the current state or retry this request.” Replay: “This request was already completed.” Display the separately observed current state beside replay copy.

## Managed ownership and cached choices

Create one server-side tri-state ownership classifier: **managed / unmanaged / unresolved**. It is independent of emergency/module gates. Resolve actual site, product and exact variation-parent identity from Woo objects, not submitted IDs alone. Existing owned selection/context/package/order evidence establishes possible ownership even if malformed; corrupt owned evidence cannot become native commerce. A successful authoritative no-match may prove unmanaged; resolver failure, no returned options or a discarded malformed selection does not.

Capture a request ownership latch before restoration/reconciliation can discard markers. Bind line identity, product/variation-parent, quantity, destination/context and cart-to-order mapping. Latches and admitted stamps are server-created, request-local, never client authority. Final order-item reconstruction is required when Classic has already emptied the cart. Use explicit flag-neutral ownership queries; the existing `assess_product_selection()` none result is insufficient. Unknown/oversized ownership analysis refuses rather than declaring unmanaged.

Proposed analysis ceiling:200cart/order lines and200packages per admission, with no partial success. Exceeding it refuses a managed/unresolved admission truthfully; it does not truncate or alter prices. Existing endpoint authentication, rate limiting and Woo input limits remain. Keep expensive resolver/quote/plugin work outside the short control lock; lock wait is bounded at2seconds. This is a finite control design, not completion of every public abuse-budget requirement.

While suspended, reject selector acceptance, variation/location offer generation, cart selection/reselection and managed quote/rate production before acceptance. Shoppers may retain/remove cart items and edit their own draft address; draft editing is not quote acceptance. Preserve an owned package identity instead of moving invalid/paused lines into a residual native package. Mixed carts block final placement due to their managed lines, while ordinary residual-package calculations remain Woo-owned. A genuine enabled pickup can cost zero; a pause cannot manufacture such a quote.

Put server control revision/state in hash-included managed-package metadata and re-resolve on any epoch change. Invalidate only this managed package's derived session quote/rate view, not global Woo shipping caches. The final guard independently rejects stale cached selected offers even if package-rate callbacks were bypassed. Resume increments revision and triggers current selection, quantity, destination, eligibility and quote checks; old choices remain drafts until accepted again.

## Final admission and concurrent toggle ordering

Pinned source inspected is WooCommerce11.1.2. Implement early refusal at priority-100 on cart/checkout validation and pre-create/request-update boundaries for clear UX. Those checks never mint final admission. Keep the independent guard registered when the old flags are off.

| Route | Last adopted final admission hook | Scope |
|---|---|---|
| Classic | `woocommerce_checkout_order_processed`, proposed PHP_INT_MAX | After supported preparation callbacks, before gateway or no-payment completion |
| Blocks/direct Store API | `woocommerce_store_api_checkout_order_processed`, proposed PHP_INT_MAX | Final POST checkout processing, before gateway or no-payment completion |
| Native order-pay | `woocommerce_before_pay_action`, proposed PHP_INT_MAX | After Woo nonce/order-key/ownership checks, before gateway; unpaid managed orders only |

Before the short lock, read current enabled revision R and revalidate the immutable server order/cart fingerprint, current ownership, choices and quote. Then current-lock the control and require enabled at the sameR and unchanged bound facts. Successfully finish and retire the read owner before issuing a process-local admission stamp. Unknown reads/release/retirement issue no stamp and refuse. A pause/resume between quote and final read changesR and requires fresh validation.

The decision under the current control lock is the **linearization point**. If suspension wins first, admission is refused. If admission wins first, that already admitted payment may finish after a later suspension. Do not hold option locks across Woo persistence, callbacks or payment/network work; do not claim one atomic Woo/C03/payment unit or external exactly-once delivery.

At order-pay, classify using actual order items and protected engine facts, not snapshot presence as proof of earlier acceptance. Under enabled state, use coherent stored facts as validation input, require current eligibility and fresh quote equality with the order's stored money/currency/tax facts using existing quote semantics. Do not reprice, repair missing facts, rebuild history from current configuration or accept partial/unknown snapshot data. A mismatch/unavailable proof refuses payment and directs a new checkout. Pure unmanaged Woo order payment stays native.

Order-pay needs its own controlled response adapter: Woo11.1.2 invokes before_pay_action outside its payment try/catch. Returning from that callback does not stop payment, and an ordinary thrown exception can become an uncaught critical error. Denial must add a safe native Woo notice and terminate through a verified Woo-owned GET redirect (never a submitted URL), or a safe terminal unavailable response if redirection cannot be completed. Prove termination before gateway invocation in a real child request; do not reuse the Classic/StoreAPI exception strategy blindly. Tests follow the redirect and inspect the notice, response and unchanged order bytes; no raw exception is shown.

Known limits remain explicit: Classic may already have persisted a provisional order/snapshot and COD manual-action index before final admission; Store API can persist/update a checkout draft. Denial means no new final admission/payment, not zero provisional rows. The additional order-pay guard covers later attempts to pay these rows while paused/unavailable and applies fresh admission when enabled. Existing paid-order callbacks/history/shipments are preserved. This does not fix COR-029's mandatory snapshot sealing or cancel/delete provisional records. Arbitrary later third-party callbacks at the same priority are not globally controlled; physical supported-hook ordering and the declared compatibility surface must be verified, and an unsupported admission route remains blocked rather than silently bypassed.

## Explanations and privacy

Reuse C01/C02 patterns with an explicit **site-control** decision shape; current C02 product-target contexts and reason vocabulary cannot be used by inventing a product ID. Proposed domain codes include `checkout_suspended`, `control_unavailable`, `stale_control_revision`, `checkout_revalidation_required` and `unsupported_activation_policy`, mapped through declared adapter schemas. Existing ContractError vocabulary is not silently enlarged.

Shopper output contains a generic temporary-pause/revalidation message and safe correlation reference. It excludes operator/actor/reason, row/event/completion/token IDs, addresses, supplier/origin/rate-card/cost/margin facts and raw exceptions/SQL. Authorized settings readers use manage_delivery_settings; technical impact diagnostics use view_delivery_diagnostics and current object/site authority. Check before loading and again before disclosure. Finite logs contain operation/safe reason/outcome/revision/completeness only, not arbitrary control/context serialization.

Diagnostics distinguish stored state, current effective state, module readiness, bounded activation, publication pending and observed impact. Counts are bounded observed estimates, never an exhaustive live affected-cart count. No customer-address scan, general log-retention policy or historical audit cleanup is added.

## Thirty future implementation obligations

**NOT EXECUTED by this design.** These map T18–T21 to implementation tests, native WordPress and real authenticated Woo routes. Method counts alone do not prove them.

| Case | Meaningful required proof |
|---|---|
| C07-01 | All23 current flags/defaults/activation behavior unchanged; reserved adapters remain truthful |
| C07-02 | Strict physical/virtual codec; duplicates, wrong site/types/state/reason/revision/size refuse |
| C07-03 | Current checked absence rev1 preserves legacy gates; first changed1→2 and same-state not_applicable match current C03 codec |
| C07-04 | Native options route/engine/full identity/isolation/readiness and wrong/ambient owner refuse without changing controls |
| C07-05 | Capability, nonce, current principal/site/WCFM denial; revoked authority before replay and final disclosure |
| C07-06 | Two concurrent forms/current missing-key insertion and preopened-view/row-recreation races preserve the accepted newer control |
| C07-07 | State/event/completion acceptance and refused audit/CAS rollback are one durable effects unit |
| C07-08 | Same token after >100 later events/fresh process replays without second effect; changed intent conflicts; opposite newer state stays current |
| C07-09 | Actual unsent commit versus committed lost acknowledgement/fresh reconcile; no duplicate mutation/event |
| C07-10 | Publication refusal and delayed old replay only invalidate; cross-request warmed options/alloptions/advisory cache cannot hide a newer pause |
| C07-11 | Owned/malformed/removed selection markers and authoritative resolver failure cannot downgrade managed ownership; exact variation-parent checked |
| C07-12 | Paused product/variation choices, reselection and managed quote production refuse; authorized draft-address/cart edits remain usable |
| C07-13 | Warm Woo shipping cache, inactive old flags and residual-package paths cannot yield native/free fallback for managed lines |
| C07-14 | Classic toggle after render and after early validation blocks paid and no-payment final admission; provisional bytes retained |
| C07-15 | Actual Blocks browser/StoreAPI sequence gives same after-render/final decision; provisional update cannot mint admission |
| C07-16 | Direct authenticated Store API POST cannot bypass the final guard or authority/nonce/cart identity |
| C07-17 | Empty Classic cart uses bound server latch and actual order items; forged client stamp/order metadata refuses |
| C07-18 | Pickup/mixed cart pause blocks managed admission without invented zero rate; pure unmanaged cart stays Woo-native |
| C07-19 | Two real connections prove both admission-before-pause and pause-before-admission orderings and unknown-read/release refusal |
| C07-20 | Native order-pay on new provisional and legacy unpaid managed orders is held during pause with controlled response/notice/termination and zero gateway calls; unrelated unpaid Woo orders stay native |
| C07-21 | Order-pay after resume uses fresh coherent facts/eligibility/quote equality; changed price/missing facts gives a safe controlled refusal with zero gateway calls and no order/history rewrite |
| C07-22 | Resume invalidates only managed derived cache; old choices cannot bypass current quantity/destination/config/quote validation |
| C07-23 | Server current-site/locale/currency/customer isolation across Classic/Blocks/StoreAPI/order-pay; no caller state/authority substitution |
| C07-24 | Deterministic current-site all-or-none policy across fresh processes/transports; undefined percentage/cohort refuses |
| C07-25 | Shopper/log/admin nested/renamed private data and arbitrary serialization negative fixtures; truthful bounded impact and publication state |
| C07-26 |200line/200package and2second lock budgets refuse without partial admission, truncation or global cache purge |
| C07-27 | V1/V2 snapshot bytes, C03/C04/COR007 completions, rules/options/all32 stores and existing paid/shipment operations remain intact |
| C07-28 | Actual ongoing gateway/paid-order/COD/status/tracking flows preserve existing authority; pause is not cancellation |
| C07-29 | Writer disable/unsupported route/failed readiness preserve state/history and fail managed admission safely; no schema success or destructive rollback claim |
| C07-30 | Exact final implementation candidate: all8 substantive CI gates, required real-DB selection, new complete native/authenticated Classic/Blocks/StoreAPI/order-pay cases, cleanup and every source/ZIP/member fingerprint |

## Implementation leases, validation and next decision

Only the owner and AI agents are working. Root owns FeatureFlags/bootstrap/settings/status/shared interface and runtime integration edits. Parallel exclusive leases may separate the new state/profile/store/units, ownership/admission application logic, Woo adapters/native/browser fixtures and independent read-only verification. Record exact file scopes first; do not expand C06's existing write permissions or overwrite another agent's files.

Future reserved new paths: Domain/EmergencyControl typed values/codec; Application/EmergencyControl service/profile/classifier/admission; Infrastructure/Persistence finite emergency-control store; Presentation/Admin settings/diagnostic adapters; Integrations/Blocks and explicitly inventoried Classic/cart/shipping/order-pay bridges. Existing C03 codec, scoped COR007 save/reset, rule evaluator, price/tax authority and snapshot formats remain preserved. New public/control schema and reason vocabularies require explicit catalog entries/projection tests; no arbitrary route factory is added.

Implementation must prove real two-connection/fresh-process/native option-cache behavior and all paid/zero-total/unpaid-order paths in the supported runtime. Its new physical SQL class becomes a separate required CI selection/count with no silent skips. Exact future method/case IDs are derived from implementation source; design CI executes only the existing baseline342native/66HTTP/smoke/143SQL/2302PHP/102JS checks. Passing design CI is not C07 runtime proof or a new theme/multicurrency/persistent-cache certification.

Rollback disables the new administrative writer while retaining state, completions and guards/readers needed by managed admission; module flags are not reset and no data is dropped. A package without these guards is not claimed to enforce the adopted pause. Wider compatibility/release deployment decisions remain separately tracked.

**Next owner decision after finite review and design qualification: “Approve W1-C07-EMERGENCY-CONTROL-1 and implement W1-C07.”**
