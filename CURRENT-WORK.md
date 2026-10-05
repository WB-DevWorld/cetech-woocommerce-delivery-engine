# Current Work — Post-#32 repository truth synchronization

Status: RC.12 IMMUTABLE — OPENING CORRECTIONS AUTHORIZED ON ISOLATED FIX BRANCH — UNMERGED — NOT RC.13

## 2026-10-05 — targeted native qualification continuation

Native checkpoint: first candidate `7b94884` retained a failed variation-capability fixture precondition in PR CI `37304120794` (96 checks passed, one failed; later configuration/migration checks not run). The correction changes only the native fixture and evidence wording: grant/revoke the registered variation primitive while retaining native foreign-parent and production relationship denial. Production repairs are unchanged. Corrected-candidate execution remains pending until its exact-head CI and receipt are inspected.

The owner again instructed continuation after the four repairs and draft PR #51 were handed off. This continues the same bounded validation queue with targeted native WordPress/WooCommerce/MariaDB checks for COR-001/002/003/010. It does not authorize the reserved business contracts, COR-004/006 implementation, human review substitution, merge, release or live-site operations.

- Candidate: PR #51 head `08e6aa2ff53d22ac8e5c8c00a66c19a2b74d2b70`; protected master remains `637c02f182ca273b40819631d23ac8e0dcc4004f` at continuation start. Existing repair source remains unchanged by this qualification batch.
- Environment: a third fresh CI-only WordPress site in the runner's disposable MariaDB service, uniquely allocated database name beginning `cetech_wp_opening_qualification`, loopback connection and explicit disposable-site marker. No existing installed site or external database is used. The fixture may create test principals, roles, products/variations and plugin configuration/jobs/entities, and manipulate and restore fixed migration options for synthetic migration fault cases. It creates no order or payment.
- Integration editor / exclusive added leases: Codex for `scripts/qualification/opening-*.php`, `scripts/qualification/admin-context.php`, the qualification-only wiring in `scripts/ci-wordpress-php85-smoke.sh` and artifact step in `.github/workflows/ci.yml`, plus this checkpoint and the qualification handoff. Review agents prepare modules outside the repository; only the integration editor applies changes. Human ownership stays unchanged.
- Evidence: native capability mapping, nonce verification and physical SQL state supplement existing regression fixtures. Direct PHP callbacks/services, redirected/die control-flow interception and simulated option-write denials will be identified explicitly. These are not HTTP/browser/session proofs or exhaustive native storage-crash, upgrade, theme/cache/payment certification.
- New results are recorded separately in `docs/OPENING-NATIVE-QUALIFICATION-2026-10-05.md` and CI's machine receipt; the frozen 36-unit/64-scenario audit registers remain unchanged.

## 2026-10-05 — bounded opening corrections

After presentation of the four-unit approval packet, the owner instructed:
"let us continue to the next steps.. if possible, tackle multiple steps one after another in the same run.. be as thorough as necessary".
In that context, this authorizes the presented focused COR-001/002/003/010 repairs, isolated validation, commits and a draft PR. It does not freeze the full Wave 1 contract or authorize merge, release, deployment, live-site mutation, payment/order tests or external messages.

- Base: protected master `637c02f182ca273b40819631d23ac8e0dcc4004f`.
- Branch: `fix/audit-opening-authority-migration`.
- Integration editor / current central-file lease holder: Codex, acting under this owner instruction. Review agents prepare proposals outside the checkout; only the integration editor applies repository changes. Recorded human workstream ownership remains unchanged.
- Environment: fresh disposable local checkout, PHP unit/integration fixtures and supported PHP runtimes. No training, Pilot, FLAIROC, production, POS or external database connection is authorized.
- COR-001: wp-admin stored-operation/private-content checks before later actions, job presentation and AJAX. Foreign-job delegation, CLI authority and broader qualification remain reserved; no creator-only/sharing grant or worker-user policy is introduced.
- COR-002: recognized scope/object/actual-parent permission, exact represented slice and ordinary successful reset audit. COR-007 fault/transaction/revision policy remains reserved.
- COR-003: existing entity permissions for wizard writes, including implicit area creation. Existing Administrator recovery remains intact and requires separate native qualification.
- COR-010: prerequisite stop, durable progress/status read-back, retry truth and unambiguous discovery. Unknown absent migration files and native storage qualification remain reserved.
- COR-004/006 remain outside this authorization with their original COR-001 prerequisite. The nine proposed atom scopes, requirement classes and release intent are unchanged.

Exclusive source-file reservations for this batch:
- `src/Presentation/Admin/BulkJobAccess.php`, `BulkToolsPage.php`, `BulkJobProgressEndpoint.php`;
- `src/Application/Configuration/Admin/ProductVariationScopeGuard.php`, `ScopedConfigurationAuthorization.php`, `ScopedConfigurationTargetGuard.php`, `ScopedConfigurationAdminService.php`;
- `src/Application/Configuration/Catalog/ProductExceptionsQuery.php`;
- `src/Presentation/Admin/ProductExceptionsPage.php`, `ScopedConfigurationPage.php`, `SetupWizardPage.php`;
- `src/Application/Configuration/ContextualEntityService.php`;
- `src/Bootstrap/Plugin.php` (only the configuration caller's dependency wiring);
- `src/Core/Versioning/MigrationRunner.php`, `MigrationDiscovery.php`, `SchemaVersion.php`, `MigrationStatus.php`.

Test reservations: new `BulkJobAdminAuthorizationTest`, `ScopedConfigurationCallerAuthorityTest`, `SetupWizardEntityAuthorizationTest`, `MigrationRunnerFailureTest` and their isolated fixtures, including `RestoresWordPressFixtureGlobals`; `tests/bootstrap.php` only for resource-specific `edit_post` and the missing `esc_js` fixture support. The exact final changed paths and check results are recorded in `docs/OPENING-CORRECTIONS-2026-10-05.md`. Schema target stays 6; published/frozen package identities are untouched. No release package is built by this task.

The sections below preserve the earlier Issue #45 and release/deployment checkpoints; their docs-only authorization does not describe this newer bounded owner instruction.

## Canonical repository truth
- Canonical organization repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Canonical development branch: protected `master`.
- Protected `master` baseline at the start of Issue #45: `88c9ec09f3cfabf73b83780c0c37d397e9acdad6` (PR #43 MERGED; Issue #32 CLOSED / COMPLETED). PR #46 carries this documentation synchronization; once it merges, its merge commit becomes the newer protected-master head. Do not treat `88c9...` as the post-PR-#46 head.
- Post-merge CI run `35766468276`: SUCCESS across PHP 8.3, PHP 8.4, PHP 8.5 production, PHP 8.5 MariaDB, PHP 8.5 WordPress/WooCommerce, JavaScript/Vitest, Control Plane, and CI Required Gates.
- Immutable RC.12 release source: `78594ad8962868683726373f58f4a8b1b48e4d0e`.
- Tag `v1.0.0-rc.12` must not be moved. Do not rebuild or overwrite the RC.12 ZIP.
- Schema remains `6`.
- Do not create RC.13 unless a separate release-promotion task explicitly authorizes it.

## Current training truth
- Training site: `https://training.cetechbpa.com`.
- Installed/active candidate: `1.0.0-dev.attention-count.1`.
- Package-source SHA: `6e9e2ea71796170afb0508954a18d206cdc7017a`.
- Frozen ZIP: `cetech-woocommerce-delivery-engine-1.0.0-dev.attention-count.1.zip`.
- ZIP bytes: `1,848,319`.
- ZIP SHA-256: `2027e5941916dff0302d7e2e545de5a2fd027f0c8f1615d97e3991dcfc5fdcb3`.
- Training physical QA: PASS.
- Verified post-deploy Needs Attention state for the same authorized administrator and stable data: catalog `0`, stale bulk `0`, shipment creation `2`, operations `3`, COD `3`, canonical aggregate `8`, Overview `8`, menu badge `8`.
- Training deployment is qualification evidence only; it is not RC.13, Pilot, FLAIROC, or production promotion.

## Completed post-RC.12 fix
Issue #32 — `[P2] Overview Needs Attention count omits actionable shipment/COD work`
- PR: #43 — MERGED.
- Issue #32 merge commit / protected-master head before PR #46: `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`.
- Final PR head: `658316f775099b0dfc43205bb07c905681a4af10`.
- Runtime/package-source SHA: `6e9e2ea71796170afb0508954a18d206cdc7017a`.
- Development identity: `1.0.0-dev.attention-count.1`.
- Runtime CI `35759882478`: SUCCESS.
- Final-head PR CI `35760847856`: SUCCESS.
- Post-merge master CI `35766468276`: SUCCESS.
- Technical review: PASS.
- Training physical QA: PASS.
- Issue #32: CLOSED / COMPLETED.
- Evidence: `docs/POST-RC12-NEEDS-ATTENTION-COUNT.md`.

## Repository synchronization checkpoint
Issue #45 — `[REPO] Post-#32 repository truth sync and branch retirement`
- Branch: `docs/post-issue32-repository-truth`.
- Base: protected `master` `88c9ec09f3cfabf73b83780c0c37d397e9acdad6`.
- Scope: documentation/control-plane truth, stale PR retirement, branch classification, and safe branch retirement only. PR #46 is the merge surface for this checkpoint; after merge, Issue #45 is expected to close via `Closes #45`.
- No plugin runtime, schema, package, tag, geography, coverage, rate, checkout, shipment, COD, production, FLAIROC, Pilot, or POS mutation.
- Obsolete PR #15 is CLOSED / SUPERSEDED and must not be merged.
- Branch classification record: `docs/REPOSITORY-BRANCH-RETIREMENT-2026-09-22.md`.

## PHP policy
- Minimum supported PHP: **8.3**.
- Supported/certified range: PHP **8.3, 8.4, and 8.5.x**.
- CETECH production/currently qualified latest stable line: PHP **8.5.x**.
- PHP 8.1 and 8.2 are not supported and must not be advertised.
- Canonical policy: `docs/PHP-RUNTIME-POLICY.md`.

## Central leases
- published release identity/tags: `v1.0.0-rc.11` and `v1.0.0-rc.12` immutable;
- schema/migrations: frozen at `6` for this docs-only task;
- canonical `master`: no force-push/rewrite;
- product-control-plane Requirement IDs: frozen; no renumbering;
- frozen development ZIPs remain immutable;
- GH Location Pack / Accra / Kumasi / Greater Accra charges / sentinel Test AREAAA: not mutated;
- Zone 2 display name remains `Ashanti Region`.

## Environment authorization
- CI and documentation/control-plane verification are authorized for Issue #45.
- Training currently contains the physically qualified `1.0.0-dev.attention-count.1`; no further training mutation is authorized by Issue #45.
- CETECH Pilot: NOT STARTED.
- FLAIROC: NOT DEPLOYED with the post-RC.12 candidate stream.
- Production: NOT DEPLOYED.
- POS repository: outside scope.

## Explicit non-actions
Do not create RC.13. Do not move `v1.0.0-rc.12` or overwrite its ZIP. Do not overwrite frozen development artifacts. Do not start CETECH Pilot. Do not deploy FLAIROC or production. Do not touch POS. Do not start Stage 15. Do not rename Ashanti Region. Do not mutate geography, coverage, rates, shipment lifecycle, COD semantics, or the Bulk stale threshold.
