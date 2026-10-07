# W2-Q03 durable internal DeliveryQuote lifecycle

Status: **IMPLEMENTED / IMMUTABLE CANDIDATE QUALIFICATION PENDING**.

## Authorization and baseline

The owner instructed “Approve W2-Q02 for integration and implement W2-Q03” at2026-10-07 06:40:53UTC. Q02 PR#86 merged unchanged as `64a0d1856a5fb4780ccaf98e4d79766d1695cb2e`, tree `71bf29d32433a1d3fd0933c6ec4abf7659d1bae3`. Actual-master CI37582927815 attempt1 passed all eight substantive jobs, complete native392/finalHTTP98/smoke/cleanup and all643 exact source hashes/mapc06b58d5. Issue#85 completed. Q03 is tracked in Issue#87 from that actual merge on `ws3/wave2-q03-durable-lifecycle`.

The approved [design](W2-DELIVERYQUOTE-LIFECYCLE-DESIGN-2026-10-07.md) and [six-checkpoint plan](W2-DELIVERYQUOTE-IMPLEMENTATION-PLAN-2026-10-07.md) remain authoritative. Schema9 is unchanged. Development identity is `1.0.0-dev.wave2-quote-lifecycle.1`. Qualification receipts are bound to the final immutable candidate and published on its PR after execution; this source record does not certify itself.

## Implemented boundary

This checkpoint coordinates finite internal issue, accept, invalidate, bind and seal profiles through C03. Quote changes, sanitized events and operation completions share the exact owned transaction. A committed issuance means a quote was issued; it does not mean shopper acceptance, placement, payment, stock or capacity reservation.

A dedicated admission gate precedes generic C03 pending insertion and expensive capture. It uses server-derived owner/session facts, physical current control, site-then-session minute counters and an immutable namespace/intent/attempt lease. New attempts are bounded at20/session and200/site per minute. A live capture capability is used once. The lease expires after60 seconds without renewal or takeover; fresh processes reconcile/read the original facts rather than restart capture. Minimal admission tombstones remain even after old minute counters are cleaned.

Internal source evidence is an explicit same-session finite interface with a conservative unavailable default. Trusted fixtures implement it using actual current SQL locks. The provider registry is empty by default, and only test fixture providers are registered for this checkpoint. Q04 supplies the actual retained native price provider and its complete source/currency/tax fences. No existing shopper endpoint, cart rate, snapshot reader or order writer is activated here.

Acceptance resolves the original body/revision/expiry and namespace. A different token cannot renew or accept again. Current authorization precedes lookup, replay and disclosure and is rechecked before write/publication. Reads distinguish current unavailable evidence from known material change; uncertain reads never fabricate invalidation. Commit failure distinguishes an unsent commit with acknowledged rollback from an actual committed outcome whose acknowledgement was lost. Recovery uses a replacement physical owner and original namespace; it never reruns a completed mutation.

The separately named quote retention adopter keeps C06's default table/uninstall preservation policy. It current-locks exact producer namespaces (including missing-key fences) before control, target and reference locks. Only a never-accepted, expired-for30-additional-minutes and conclusively unreferenced private payload may be stripped. Accepted, pending, unconfirmed, prepared/sealed or historical references are protected. Unknown/malformed reference evidence retains the body. A fixed high-water, durable predecessor-linked page envelope,100-inspection/mutation limits and2-second soft work budget make progress finite. A refused checkpoint rolls back the page. Admission/operation tombstones and accepted/history facts are not age-deleted.

## Proof obligations and executed evidence

The approved Q03 obligations are W2Q13–19,27–33 and36–38. Internal fixture success does not qualify native pricing or shopper placement. Unit, two-connection SQL, fresh OS process and actual wp-load/default-cache proofs are required; the final exact source/artifact identities are recorded on the implementation PR after execution. Existing392 native and98 HTTP case inventories remain preserved; new native cases are derived from source rather than assigned a success count in advance.

Comparative local PHP 8.3.6 configured suite: **2,862 tests / 17,683 assertions / one existing skip**, PASS. Focused durable lifecycle22/160, admission22/330 and retention20/98 use the SQLite transaction transport and do not replace the physical MariaDB proof. JavaScript8files/102tests, PASS. Production package/autoload/lint666/0 and repository lint1049/0 passed; all23 new production types are explicitly checked by the package verifier. Team and product control planes, including negative fixtures, passed.

Meaningful red→green regressions are preserved: a paused uncertain admission could regain capture; authorization revoked during final release could disclose private facts; an unchanged invalidation consumed the future actual-change namespace; and native mysqli row/namespace canonicalization prevented first issuance. The producer/retention lock-order counterexample led to nonlocking immutable prerequisite reads and globally ordered collector locks, with permanent unit coverage and an actual two-connection SQL wait proof. The later demonstrated native audit-gap deadlock is corrected by using the already-owned parent and current exact positive event identity, avoiding an empty secondary-index event gap while preserving current accepted-event reads. The original race now completes without deadlock and preserves the accepted body. Refused cache deletion cannot acknowledge publication while a private marker remains. Historical failures remain separate; none is promoted to a passing qualification.

Physical comparative PHP8.3.6 / MariaDB10.11.14 group `quote-lifecycle-real-db`: **46 tests / 960 assertions**, zero skips/errors/failures, PASS. Actual concurrent process limits, stale read-view control fences, commit dispatch/acknowledgement distinctions, immutable original replay in a fresh OS process, both acceptance/retention race orderings with zero deadlocks/timeouts, protective references, fixed-ceiling100-candidate pages and checkpoint rollback are separate real SQL evidence. Owned database tables returned to zero and the disposable server stopped. CI explicitly requires all46 new class cases alongside each retained SQL minimum; this comparative run does not qualify the pinned WordPress runtime.

The20 new native source case IDs preserve the previous392 native cases and98 authenticated HTTP cases. Syntax and controlled fault-seam checks passed; only fresh candidate CI can qualify actual wp-load/default-cache execution. No final native/HTTP result is claimed by this source record.

## Next checkpoint

After complete qualification and separate owner integration approval, W2-Q04 implements the retained existing-price provider. Q05 adopts cart confirmation/readers and Q06 adopts final placement. Next owner task after Q03 qualification: **Approve W2-Q03 for integration and implement W2-Q04.**
