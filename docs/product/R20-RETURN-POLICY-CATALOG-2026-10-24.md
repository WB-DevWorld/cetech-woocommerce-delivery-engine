# R20 — Return Policy catalog authoring

The owner renewed permission on 24 October 2026 to continue the engine in seven to ten parallel streams and stated that they perform all the work. Root/Codex owns this finite continuation and its agents. Base is qualified R19 `8c997a4cf9489f0f4020445fd50b8402be0201c0`, tree `92cc6b65cdad0188ed1f5c8fbef8fa4636fd1f7f`: run 40035442225 passed all eight jobs and independent original-artifact audits. N18 and the older commercial work remain closed. This task does not merge either draft.

## Bounded behavior

Add Return-only administrative catalog authoring through C01: create/edit drafts, publish, schedule, retire/cancel and reconcile the original operation. Persist original content, identity, CAS and audit through the existing rule and operation stores. Catalog reads expose current heads and immediate predecessors only. They do not provide a complete retired-version/audit archive, resolve merchant/product inheritance, activate a due schedule, select defaults, render customer text or write order history. Refund Policy remains independent. No runtime family registration or UI is added in this step.

Use source and owned disposable remote CI only. Owned in-memory PDO SQLite unit doubles are permitted and confer no physical proof. Do not start a local WordPress or MariaDB service, use SSH, touch protected checkpoints, select merchant terms, enable adoption, invoke payments, merge, release or deploy. Existing schema 12, 41 current/39 retained stores, 144 lifecycle declarations, 23 flags and 372 classifications remain unchanged. No C01/C03 protocol or generic core changes are planned.

## Frozen production interfaces

Exactly two new production classes are planned, taking the census from 941 to 943. Existing R19 content/version codecs remain unchanged.

`Domain\\ReturnPolicy\\ReturnPolicyFamily implements RuleFamilyProfile`:

- Constants CODE=`ReturnPolicyContent::FAMILY`, VERSION=1.
- Constructor requires three trusted callbacks: scope grant `(OperationIdentity,array scope):bool`; author eligibility `(int site,int author,array scope):bool`; identity-to-author binding `(OperationIdentity,int author,array scope):bool`. All require strict true and fail closed on exceptions.
- Exact scope and subject schemas are `{policy_uuid: uuid}`. This is catalog identity, not a product/variation/global resolution scope. `checked_scope(array):array`, `definition_hash():string`, and ordinary C01 profile methods are public.
- Payload schema delegates to `ReturnPolicyContent::payload_schema()`. Declaration hash explicitly commits this catalog identity, canonical content, zero priority, equal-rank refusal, no activation, and schemas. Match/overlap compare only the policy UUID; specificity is zero; precedence is explicit priority descending and equal-rank policy is refuse.
- `for_author(int author_user_id): self` creates a separate private-author-bound profile while preserving original trusted callbacks/declaration. The original family remains unchanged. For the five normal write operations, an unbound family refuses. A bound profile rechecks scope grant, author eligibility and identity/author binding on every C01/C03 authorize call. `rule.activate` always refuses in this task. Read authorizations use the original scope callback. The binding callback is essential: two independently allowed principals/authors do not establish their relationship.
- No supplied declaration hash proves callback provenance. Trusted host code constructs and retains the concrete family; request data cannot provide a profile or replace a callback.

`Application\\ReturnPolicy\\ReturnPolicyCatalogService`:

```php
__construct(ReturnPolicyFamily $family, OperationConnectionFactory $connections,
    callable $authorize_catalog, ?OperationReadiness $readiness = null,
    ?OperationPhaseObserver $observer = null)
attempt(OperationIdentity $identity, mixed $payload, RequestContext $request): OperationAttemptResult
reconcile(OperationIdentity $identity, mixed $payload, RequestContext $request): OperationAttemptResult
capture(OperationIdentity $identity, array $authorization_scope, RequestContext $request): RuleSnapshot|ContractError
```

- The catalog callback `(OperationIdentity):bool` is a separate explicit family-wide disclosure grant; a per-policy grant cannot authorize a family scan. Check it before any connection and again at disclosure, fail closed on exception/non-true.
- Both write entrypoints parse the original complete C01 envelope and validate `ReturnPolicyContent::from_rule_payload` before any service SQL. Require Return family, five normal operations only, priority zero, and exact equality of scope.policy_uuid and logical_uuid. Do not invent IDs, authors, dates, CAS, defaults or a separate seal command.
- Construct C01 RuleLifecycleService using a registry containing the family profile bound to the original command author. This keeps author binding inside every existing C03 authorization boundary, including original replay/reconciliation. Pass original identity/payload/request unchanged. Do not rebuild retries from advanced rows.
- Return existing C03 outcomes/completion; invalid input and refusal use existing structured ContractError codes, never raw exception details.
- Capture uses C01 RuleLifecycleReadService under the original concrete family and the supplied schema-valid authorization scope. Validate scope/logical UUID coherence and canonical content of all returned candidates through ReturnPolicyVersion with the original profile; priority must stay zero. Refuse incomplete or malformed captures. Keep C01 per-policy authorization checks and the extra family-wide check at final disclosure. No mutable SQL or automatic activation in capture.
- Inner and escaped outer 16KiB limits, Unicode and multiline preservation, original audit IDs, transactions, rollback/retirement and completion validation remain those of R19/C01/C03. Raw authored HTML requires authorization and escaping in a future UI; this layer renders nothing.

## Leases and evidence

Root alone edits central records, development identity, package tooling, workflow/minima, integration, commits, refs and PRs. Agents do not commit or publish.

1. Family owner: new ReturnPolicyFamily and its own family unit tests.
2. Service owner: new ReturnPolicyCatalogService only.
3. Behavioral unit owner: new catalog happy-path/lifecycle tests and a new shared test-only catalog fixture.
4. Security unit/reviewer: new author-binding/privacy/refusal tests only; independent implementation review.
5. SQL owner: new Return Policy real-database test, envelope/support and process worker only. Reuse existing rule/operation proof transports without modifying them.
6. Independent conformance/recovery reviewer: inspect source contracts, original-intent recovery and read disclosure; no implementation edits.
7. Root: integration, source inventories, meaningful combined regression, exact package and owned CI qualification.

Plan ten added physical cases (source-derived census/minimum is authoritative): create/edit/no-change; publish/retire/original replay; schedule/read-without-activation/cancel; principal/author/scope refusal; foreign-family/target refusal; audit rollback; completion rollback; proven-unsent commit rollback; lost acknowledgement/fresh-process reconciliation; and two-token publication contention. New workers publish any data-bearing markers atomically and preserve existing finite SQL/observer/process limits. Existing 515 SQL cases and all 26 guards remain; add a separate minimum for this class. Do not claim a physical case from a skipped local invocation. Full CI, original artifacts and source freshness remain distinct final gates.
