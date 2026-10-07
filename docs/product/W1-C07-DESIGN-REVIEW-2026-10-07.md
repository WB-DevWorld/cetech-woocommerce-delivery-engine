# C07 finite design review

Decision: **PASS for the reviewable W1-C07-EMERGENCY-CONTROL-1 proposal**, not implementation/runtime acceptance. Only the owner and AI agents are working; no outside human-availability gate is introduced.

Source baseline is the actual C06 merge9cbdc6b5f0b9a509cad137d7379d96809f6d240e/tree66536ad4e5d47d820baa18569c729357248972ed. Root drafts [the design](W1-C07-EMERGENCY-CONTROL-DESIGN-2026-10-07.md) and [exact inventory](W1-C07-EMERGENCY-SOURCE-INVENTORY-2026-10-07.md). Independent read-only agents inspected state/C03 ownership, requirements/flags/privacy and pinned Woo checkout/cache/order-pay paths. No C07 code, settings, schema or future tests were executed.

The finite core closure found no remaining concrete blocker: strict bounded option and virtual revision1 fit current C03 codec; changed transition1→2, exact current-lock/row/CAS/absence fences, current authority before effects/replay/disclosure, state/event/completion unit and invalidate-only publication are explicit. Delayed old replay cannot restore old control. Unknown reads/releases fail managed admission; module flags retain their historical/settings meanings.

The route closure confirmed a separate classifier/latch/final guard is necessary because inactive old flags may permit native rates and validation early returns. The proposal covers warm package-rate caches, empty Classic cart/order reconstruction, after-render toggles, provisional StoreAPI updates, paid/zero-total branches and a precise short-lock admission/suspension ordering. It claims neither zero provisional rows nor a transaction spanning Woo/payment. Existing COR029 sealing remains separately reserved.

One concrete clarification was requested and corrected: native Woo before_pay_action is outside its payment try/catch. Returning from a hook does not stop its caller, and an ordinary exception can produce a critical error. The final proposal requires controlled safe notice/native redirect or terminal refusal, verified before gateway invocation; C07-20/21 require actual response and zero-gateway evidence. The strict route behavior is not weakened.

Pausing payments on ALL unpaid managed orders, including legacy pending orders, is explicitly a NEW OWNER REVIEW proposal. It is not inferred as an already-approved D10 decision. Resume requires coherent stored facts/current eligibility/fresh quote equality; mismatch refuses without repricing or rewriting history. Snapshot presence does not prove prior acceptance. Paid-order/gateway callbacks and shipment operations remain independent. A superseded internal per-order-marker alternative is not included and grants no storage/implementation authority.

The complete proposal has thirty future unexecuted cases, finite200-line/200-package/2-second-lock/2048-byte budgets, deterministic current-site all-or-none activation, unsupported partial cohort refusal, exact privacy projections and preservation/rollback obligations. Frozen372 IDs/classifications remain unchanged; wider theme/multicurrency/persistent-cache/API completion is not claimed.

Actual C06 mergeCI37553724693 attempt1 independently passed all8/native342/finalHTTP66/smoke/SQL143(with required classes43/44/30)/PHP2302/14678/JS102, all591 immutable sources. That proves the integrated baseline, not future C07 cases. The design's exact new candidate/run/artifact ZIP/member/source/runtime/cleanup verification must be recorded separately in its PR before owner implementation approval.

Next decision after design qualification: **“Approve W1-C07-EMERGENCY-CONTROL-1 and implement W1-C07.”**
