# W2-P04 — reader-first guarded promise handoff

Owner authorization: **Approve W2-P03 for integration and implement W2-P04**, 2026-10-09 12:45:25 UTC. Issue105 tracks only this accepted P04 checkpoint. P03 integrated normally through PR104 as actual master `c625a59bb61493e00d10b2802a08bac852b27b26`, unchanged qualified tree `22c37bdabd29371fb6c087a01cf8b6c02f6f5f7e`, ordered parents `[5d3d17c88327593c6204015acc1cfcaffaf43ddd,bb808d42615c69fbf6868de8751b40a6c7a17dca]`. Separate actual-master CI37932348440 passes all8actualjobs and both original audits, recorded on PR104/comment6081516744; it remains distinct from the accepted candidate executions.

The accepted [design](W2-SERVICE-PROMISE-POLICY-DESIGN-2026-10-09.md) and [implementation plan](W2-SERVICE-PROMISE-IMPLEMENTATION-PLAN-2026-10-09.md) govern this implementation. This document fixes the inspected source transition; it does not activate P05/P06, customer configuration UI, deployment, release, live payment or site adoption.

## Two reviewable sub-handoffs

1. Mount exact old/new forward readers and a distinct required-promise readiness capability. New promise writes remain OFF. Review the closed formats, preservation, unknown-format protection and required-C05 distinction independently, then record the exact reader commit.
2. Implement the separately gated promise profile, acknowledged mandatory saved order packet and final placement/payment checks on that reviewed reader foundation. Independently review and qualify the complete exact candidate. The owner's instruction authorizes this bounded second sub-handoff; it does not authorize default adoption or later UI work.

## Frozen format and digest transition

| Carrier | Retained | New promise carrier |
|---|---|---|
| Quote context / terms / header / durable row | format1, exact original canonical bytes and digest domains | format2, profile `service_promise_v1`, profile version1 |
| Mandatory `delivery_quote` envelope | format1, legacy profile and existing marker1 | format2, new profile and same ownership marker2 |
| Outer order line/package snapshot | formats1/2 | format3 only with envelope2 and marker2 |
| Optional C05 `extensions.promise` | optional version1, unchanged semantics | distinct required-promise compiled readiness; never repurpose optional version1 |
| Physical quote storage | schema10 existing bounded JSON columns | no DDL needed for these explicitly versioned carriers |

Context2 contains every retained base field and `promise_capture` with exact fields `format`, `site_key`, `base_digest`, `groups`. Each captured group identifies `component_key`, exact aggregate assignment-decision receipt (including absence/inheritance fences), policy reference, canonical private input bytes and input digest. Terms2 retains the existing legacy money groups and adds `promise_packets` with exact per-group component key and closed historical packet. The P04 historical packet freezes canonical input/result bytes and original digests, structured public projection and captured customer text. Its historical parser checks closed structure, byte bounds, linkage and recorded digests without rerunning current policy, calendar, IANA calculations or localization.

The input material digest binds the **format1 native base context projection** before promise results; `base_material_digest()` exposes that explicit projection. The format2 material/body domains bind the complete capture and results. They never include a receipt naming their own digest. Existing `policy_digest` remains the retained money policy digest; promise policy references have separate names and domains. TTL remains exactly300seconds. Existing limits remain:200 groups/lines,64KiB quote/snapshot packets,16depth,4096nodes,32768byte P01 records and100000 shared calculation steps. Overflow refuses; no truncation or undocumented increase.

Envelope2 retains the existing named quote/money/provenance fields and adds `promise_packet` and `promise_packet_digest`. Its closed aggregate group set must equal all money and provenance component keys. Its packet uses a separate `cetech-required-promise-snapshot-v1` digest domain. Every group must link to the same original issue time, expiry, base material and trusted native/opaque site binding.

## Native authority, acknowledgement and history

Native capture maps trusted numeric site/owner and native component references to the P02 opaque site binding, exact revisioned assignment and acknowledged immutable policy/calendar bodies. It captures one clock and actual attested runtime/tzdata content outside owned quote SQL, calculates all groups with the shared P03 budget and freezes the result before issue. Browser text, caller-supplied result objects, current offers or arbitrary runtime labels are not authority.

Current-source guards re-read exact captured P02 bodies, assignments and producer receipts through the **existing OperationSession**. They acquire no replacement owner and invoke no clock, provider, network or user callback while owned quote SQL is locked. The initial mounted capacity adapter supports only explicit `none`; required or unknown observations refuse unless a captured adapter also supplies the exact same-owner current-verification proof. An observation is never a capacity hold.

The full immutable promise input/result and original public projection are physically present in the mandatory snapshot before Q06 preparation/verification/sealing and before any gateway or free completion. Actual `order_accepted` authority is the acknowledged Q06 final seal, not Q05 Confirm or quote `accepted_at`. The accepted promise receipt is derived from the exact physical saved packet plus its **separately acknowledged original Q06 operation receipt and sealed binding**. That separate evidence already binds the full snapshot digest and final event. No self-referential receipt is injected into the body/snapshot/seal it names, and no post-seal rewrite is needed to invent acceptance. Unresolved typed links alone prove no final acceptance. A trusted linkage reader must verify the original producer receipt and saved bytes before projecting an accepted event.

Historical original facts remain frozen when assignments, policies/calendars, timezone data, locale or current offers change or disappear. Relative payment-anchored commitments remain relative. Actual payment confirmation may support a separate idempotent audited current prediction in its owning later flow; redirects, gateway returns and pre-payment hooks are not confirmation and cannot rewrite or retract the original accepted commitment.

## Timing, recovery and activation

For the new profile, required feasibility is checked at final seal and again at the final native gateway/free boundary using a clock captured outside owned SQL. Quote expiry, captured `accept_until` and required-capacity expiry are exclusive: equality denies. Captured absolute endpoint feasibility is checked without recalculation or repricing. Fresh saved order-pay is a new admission and checks the same original deadlines. Existing v1 same-acknowledged continuation remains unchanged. A later genuine payment confirmation after admitted gateway handoff is history, not retroactive admission failure.

Original retries, lost acknowledgements and cross-route recovery reuse the original quote, clock, packets, order and operation namespaces. They never renew TTL, recalculate promises, manufacture a new source receipt or duplicate a payment effect. Unknown commits block new payment until exact original recovery. Explicit new review after a proven no-effect terminal rejection preserves the original rejection receipt/history.

A separate revisioned protected adoption decision for `service_promise_v1` defaults OFF. Its closed private configuration records the explicit native/opaque site binding and a finite native service-ID to P01 service kind/code plus opaque endpoint map, authorized through the headless adoption seam. No native label is guessed into Standard and no caller-supplied service/promise result grants authority. Every native line in a group must resolve the same effective P02 policy; heterogeneous resolution refuses. Optional unavailable/ineligible promises may retain honest unavailable facts only when the captured policy is optional; required failures refuse. Existing legacy adoption and23 feature flags are unchanged. Loaded and physical marker/member censuses protect new, unknown, malformed and missing packets even with adoption OFF. Disable, upgrade, uninstall and rollback preserve referenced original facts conservatively; unsupported formats never fall back to weaker readers or legacy payment.

## Qualification and publication

Retain the original495 native,20 fresh CPT and143 HTTP/browser cases, plus P02 native19 and P03 pure49 inventories. Add a separate source-derived P04 inventory for actual reader/readiness, physical HPOS/CPT packets, original seal linkage, deadlines/recovery, old history and preservation. Pure fixtures cannot certify native runtime attestation or payment admission. Full exact-candidate PHP/SQL/WordPress qualification, original artifact/source/runtime audit and bounded terminal freshness are required before qualified handoff. Failures remain failed evidence. No claim that all32 future design observations or later UI/native prediction flows execute in P04.

## First reader review checkpoint

Independent finite review accepts the20 reader/domain/loaded-and-physical ownership files, exact map `508a2dfaae7d1e9412ec116e9d96238889bba5a0f09485d2789e6892a27a1bf1`, ledger `dce935aa812acbe0b4616dbaed28ed52f4030a5b9064b1fa8809d048208f8278`. Independent reader/domain230tests/917assertions and physical census214/1528 pass. Author domain48/96 and Order351/1503 pass. Root independently exported old actual-master c625 fixture/legacy context, terms, header, envelope and issued/accepted/stripped row bytes; new readers preserve the exact original vector SHA `3d5f929cc05b65aa3b88d30511453102f83a518bfb7dd1f6f184a51a91cd2536` (durable two-test20assertion regression). Required readiness remains reader-only; no new provider/native writer/adoption is mounted in this first commit. This acceptance excludes writer/seal/payment authority and complete native qualification.
