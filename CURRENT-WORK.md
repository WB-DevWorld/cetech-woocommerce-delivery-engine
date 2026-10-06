# Current Work — Post-#32 repository truth synchronization

Status: RC.12 IMMUTABLE — PR #53 REPAIRS IN PROGRESS — UNMERGED — NOT RC.13

## 2026-10-06 — PR #53 review repairs

The owner confirmed that only the owner and AI agents are working. That instruction governs this repair. The visible save, reset, and recovery review in this session is a technical review by the agent. It is not an acceptance by Ben or Emmanuel. Historical human ownership stays recorded and is not reassigned. No merge, release, deployment, or live-site write is authorized.

Historical checkpoints stay `b4776c0194dffdaea3b2ac3a48b829acb68e8a28` for COR-005 and `f7054338d9956c33a0fa50f84aa239e4f3dae90d` for COR-007. The follow-up repairs C1–C3 first, then S1–S3 and U1–U3. P07 and P08 stay outside. PR #52's branch stays unchanged.

- Integration editor: this Cursor WS3 session.
- COR-005 repair lease: bulk worker and dispatch, catalog mutator, bulk job repositories, the in-memory catalog query test seams, and the scan tests. No schema.
- COR-005 repair proof, PHP 8.5.0: before the repair, variation reparent Apply left `error_code` null, a post-preview edit was reported `no_valid_delivery_path`, and a refused checkpoint left 1 item. After the repair, `tests/Unit/Bulk` is 186 tests / 1070 assertions, OK, 2 existing deprecations. That includes the indexed 40,001 walk. The disappeared-candidate, completed-empty-manifest, and incomplete-Apply cases passed before this repair and still pass. The earlier full-list lookup remains no verdict.
- COR-007 repair lease: scoped configuration publish, audit lookup, admin service, the in-memory publication seam, and the save/reset callers. No schema. No new production PHP file.
- COR-007 repair proof, PHP 8.5.0: an older global publish is paused inside option publication while a newer writer commits. The older publish returns unconfirmed and the option stays on the newer revision. A request token remains replayable after 101 later audit rows, with no second write. The same prewarmed resolver reads the committed priority after a lost acknowledgement when only the closed connection is replaced. Rendered save, reset, customize, exception, and product-panel forms carry the opened revision, row identity, and distinct save/reset tokens. A failed publication keeps row 0 for the retry. Another scope's success leaves that draft in place. Customize no-change uses the unchanged-version notice. Native WordPress 7.1.2, default object cache, autoload `off`, no object-cache drop-in: the recovery transient is written on a new connection and the closed connection still rejects queries. SQL group `cor007-real-db`: 11 tests / 100 assertions. Admin unit tests for the service, completion, and callers passed. The container was stopped after the proof.

## 2026-10-06 — COR-007 local completion, after the COR-005 checkpoint

COR-005 remains the earlier commit on `fix/cor-005-007-scan-save`. This section is the COR-007 checkpoint only. P07 and P08 stay unapproved. This does not merge, release, deploy, or write a live site. WS1 has not reviewed the visible copy.

- Integration editor: this Cursor WS3 session. Human ownership is unchanged.
- File lease: scoped configuration repository, service, audit logger, write command and result, the admin page and product-exceptions caller, and the COR-007 tests and report. No schema, ledger, or outbox. No new production PHP file. Installed production PHP remains 494 files.
- Environment: local PHP 8.5.0. Disposable MariaDB `cetech_cor004_cor007`, prefix `cor007_`, on `127.0.0.1:33079` in container `cetech-cor004-mariadb` (mariadb:11.4). Disposable WordPress 7.1.2 in a temporary site, database `cetech_cor004_wp007`, prefix `cor007_`, default `WP_Object_Cache`, no `object-cache.php`. mysqli was loaded with `-d extension=mysqli` because it is not enabled in `C:\tools\php85\php.ini`. The WordPress proof loads `wp-load.php` and does not load `tests/bootstrap.php`.
- The SQL proof is group `cor007-real-db` and is excluded from default `phpunit.xml`. The WordPress script is outside PHPUnit and outside default CI.

## 2026-10-06 — COR-005 catalog scan, then COR-007 after this checkpoint

The owner approved COR-005 and COR-007 as proposed. Implementation is sequential. This checkpoint is COR-005 only. COR-007 starts after this commit. P07 recovery architecture and P08 backlog/liveness limit stay unapproved. This does not merge, release, deploy, or write a live site.

- Approved COR-005 membership: preparation fixes the normalized target, action, variation policy, and a finite catalog ceiling H. Selected IDs stay that set. Filter and Entire Catalog walk only the requested object type inside H. H bounds the scan. It does not freeze product facts or create one catalog snapshot. Apply stays unavailable until enumeration and dry-run succeed and the exact manifest is approved. An empty completed manifest is a no-target result and cannot broaden the request. Apply uses only approved identities. A later filter, action, or variation-policy change needs new preparation and approval.
- Approved COR-005 observation: each candidate is judged on facts at its first recorded eligibility decision. Decisions persist across resume. A failed evaluation with no recorded decision may retry against later facts, visibly. The candidate cursor and accepted IDs are separate. Rejected pages still advance the cursor. IDs above H are excluded. An edit before evaluation uses updated facts. An edit after a recorded decision keeps that decision. A later match behind the cursor is not added. A disappeared product is unavailable and is not replaced. A lower-ID backfill is included only when first encountered ahead of the persisted cursor. An approved target that later changes is rechecked and returned stale without rewriting the manifest. A nonmember that matches after preparation is not added by Apply. Authorization stays COR-001. COR-004 exact filter identity stays.
- Approved COR-005 counts: scanned identities with a recorded decision, accepted targets so far, and preparation state incomplete, complete, or failed. At completion the effective total is the unique approved manifest, not the SQL prefilter count. An interruption, work budget, empty or short accepted page, or failed query is not completion. The progress line does not show an exact percentage against an unproved denominator. An incomplete scan is never a completed zero-target job.
- Branch: `fix/cor-005-007-scan-save`, from PR #52 head `1d62967b6559ee8390ddd8f787cc58ce2729c005`. The existing `fix/cor-004-006-catalog-worker` branch and the `fix/needs-attention-count-contract` checkout were not edited.
- Integration editor: this Cursor WS3 session. Human ownership is unchanged. No other active lease was present on this worktree.
- Environment: local PHP 8.5.0 and disposable MariaDB `cetech_cor004_catalog` on `127.0.0.1:33079`. The container `cetech-cor004-mariadb` was stopped after the SQL proof. No training, Pilot, FLAIROC, production, or live-site connection.
- File lease for this checkpoint: catalog query and definition files, `BulkJobWorker.php`, `BulkJobWorkerDispatch.php`, `BulkJobAdminCopy.php`, `BulkToolsPage.php`, `assets/admin/bulk-tools.js`, the scan tests, this checkpoint, and `docs/COR-005-SCAN-PROGRESS-2026-10-06.md`. No schema. Preparation state is stored in the existing job summary and `checkpoint_cursor`. No new production PHP file. Installed production PHP remains 494 files.
- COR-007 is approved and is the next batch on this same branch after this commit. Its file lease begins then: scoped configuration persistence, service, audit, command, result, and the callers required for the local completion boundary. P07 and P08 remain outside both leases.

## 2026-10-05 — COR-004 then COR-006 catalog identity and worker fencing

The owner authorized this bounded batch in the same run: implement COR-004, then COR-006 where its prerequisites allow, with a tested commit after each correction. This does not merge, release, deploy, or write the training site, orders, or payments. PR #51 remains the unmerged COR-001/002/003/010 candidate. This batch starts from that exact head and does not treat it as protected master.

- Base candidate: `d5c8e2f3c467a7dc38637bb2cdc3e881fc1c24f8` on `fix/audit-opening-authority-migration`.
- Protected master at start: `637c02f182ca273b40819631d23ac8e0dcc4004f`.
- Branch / worktree: `fix/cor-004-006-catalog-worker` in an isolated worktree. The existing `fix/needs-attention-count-contract` checkout was not edited.
- Integration editor: this Cursor WS3 session. Human workstream ownership is unchanged. No other active lease was present on this worktree.
- Environment: disposable local PHP and a fresh MariaDB database whose name begins with `cetech_cor004_`. No training, Pilot, FLAIROC, production, or POS connection.
- Exclusive file lease for COR-004: `src/Application/Bulk/Catalog/CatalogTargetFilters.php`, `CatalogFilterMatcher.php`, `WooCommerceCatalogTargetQuery.php`, `InMemoryCatalogTargetQuery.php`, new catalog identity tests, this checkpoint, and the COR-004 report. `BulkToolsPage.php` stays on the existing COR-001 admission path unless a caller change is required.
- COR-006 file lease begins only after the COR-004 commit: `WpdbBulkJobRepository.php`, `BulkJobWorker.php`, `BulkJobRepositoryInterface.php`, and their in-memory adapter/tests. Schema changes are not authorized by this lease.
- COR-005 scan/count policy, COR-006 P07 recovery architecture, and COR-006 P08 backlog limit remain outside this authorization until their own reviewed inputs exist.
- COR-004 repair is in `docs/COR-004-CATALOG-TARGET-IDENTITY-2026-10-05.md`. PHP 8.5 Bulk unit suite at that checkpoint: 147 tests / 849 assertions, OK, 2 existing deprecations. Disposable MariaDB catalog proof: 4 tests / 23 assertions, OK. Commit `0af5a8ca36f7a81c4a91bf5283318fb555694c88` is that tested checkpoint. The PR52 review repair rejects a nonempty unsupported filter before normalization and aligns Remove-mode inheritance with SQL. Focused proof: 9 tests / 20 assertions and MariaDB 4 tests / 24 assertions.
- COR-006 fence is in `docs/COR-006-CLAIM-FENCE-2026-10-05.md`. It uses the existing claim columns only. P07 recovery architecture and P08 backlog/liveness limit were not chosen and were not executed. Disposable MariaDB worker proof on `cetech_cor006_worker`: 3 tests / 33 assertions, OK, including two processes and two connections. Commit `1ebb5f3ab60a12bd8dad405fa84073c9370b5dc5` is that fence. The following harness commit teaches the in-process database double to apply the same `IS NULL` fence; PHP 8.5 full suite on that fix is 1490 tests / 9534 assertions, 1 skip, 14 existing deprecations, OK. PHP 8.3 and 8.4 are not installed in this checkout. JavaScript is 101 tests, OK. Installed production PHP count remains 494.
- PR #52 review repair keeps a stale worker from writing after takeover. Authorized detail renders a stored invalid filter without Apply. Identical outcome replay is not a second credit, and a failed release stays held. Bulk unit directory: 158 tests / 902 assertions, OK, 2 existing deprecations. Physical `cetech_cor006_worker` suite: 7 tests / 86 assertions, OK, including two `BulkJobWorker` processes. Catalog SQL remains 4 tests / 24 assertions. The post-lock outcome gap remains P07 and was not proved. P08, COR-005, and COR-007 stay separate. Worker checkpoint `357df77e1c0018b8f5aa28414208e4530a0b7a97`. Local PHP 8.5 full suite on that head: 1496 tests / 9565 assertions, 1 skip, 14 existing deprecations, OK. That assertion count is local and is not the CI count. JavaScript remains 8 files / 101 tests. Local production lint is 494 files / 0 failures. Local recomputed installed map is `2e398f66647e52f6fe93c83bf44cbbb7b734870133de0e92817de4943c8670db`; the CI-printed map is the authoritative one. CI run 37359145978 on documentation head `f5b830fced7311d06c818cef8da3924f3a9b07d7` reported PHP 8.3, 8.4, and 8.5 at 1496 tests / 9568 assertions / 1 skip. PHP 8.5 retained 14 deprecations and PHP 8.4 retained 2. The PHP 8.3 summary did not report a deprecation count. The re-review downloaded that head's opening receipts: native artifact 11366176948, 207 unique PASS, ZIP SHA-256 `dc7153a9fcf7530860d18c11089c5a3a4a8aa2942fa874f5321bd92ed4d34ba2`; HTTP artifact 11366376216, 66 unique PASS (62 driver and 4 lifecycle), ZIP SHA-256 `4522f3df6d180d4e5c2fc9277dc9b4f7d11df8fb34831354a20b0d4104c037d6`.
- PR #52 follow-up keeps the configuration-import item lock through the rules write and reports transaction and locking-read failures explicitly. A stale owner does not enqueue, and a replayed outcome is not credited. Code checkpoints: `92074e5ca1c490f2c4af87f018ecd89c04d23dae` and `7b62edb10e1ed07132ae181e906d51ae25d8e776`. Additional files for this existing callback: `AbstractWpdbRepository.php`, `WpdbDestinationRuleRepository.php`, and `BulkJobWorkerDispatch.php`. No schema and no new production PHP file. The unrepaired inner `START TRANSACTION` let the second connection update the item immediately. After the join, `phpunit.cor006-sql.xml` is 8 tests / 99 assertions, OK. Catalog SQL remains 4 tests / 24 assertions. Bulk unit directory: 166 tests / 926 assertions, OK, 2 existing deprecations. Local PHP 8.5 full suite on this code: 1504 tests / 9589 assertions, 1 skip, 14 existing deprecations, OK. That assertion count is local and is not the CI count. The console printed `The system cannot find the path specified.` once and the suite still exited 0; the cause was not established. JavaScript remains 8 files / 101 tests. Local production lint is 494 files / 0 failures. P07, P08, COR-005, and COR-007 stay separate.
- The remaining joined-rollback defect is repaired at `eed70a85b41e17725be68f301aa43ae4dc683b6b` and `075d56a1f151adcb5fbd938f004a7d819d9905ef`. Before the repair, a failed destination-rule insert followed by a rejected `ROLLBACK` let `call_while_item_claimed` return the importer failure normally. The owner keeps that connection unresolved and keeps both diagnostics. The first of those commits still left the depth open, so a later rules replacement joined it and wrote. `075d56a1f151adcb5fbd938f004a7d819d9905ef` throws before that later delete or insert. A later item, job, and release write are also refused, and no later owned `START TRANSACTION` or `COMMIT` is sent. A successful rollback still returns the importer failure and restores the old rule. Disposable MariaDB rejects the rules `INSERT` with trigger text `rejected destination_rules insert`, then a successful rollback leaves `@@in_transaction` at 0 and the previous `NG` rule. That rejection is not a server crash. `phpunit.cor006-sql.xml` is 9 tests / 105 assertions, OK. Catalog SQL remains 4 tests / 24 assertions. Bulk unit directory: 169 tests / 950 assertions, OK, 2 existing deprecations. Local PHP 8.5 full suite: 1507 tests / 9613 assertions, 1 skip, 14 existing deprecations, OK. That assertion count is local and is not the CI count. The same unattributed path message printed once and the suite exited 0. JavaScript remains 8 files / 101 tests. Production lint remains 494 files / 0 failures. No schema and no new production PHP file. The unresolved flag blocks this owner's later writes until the failed session is closed. `5a007449811f0b85a93603d099f8ab9bcea00341` closes that session before the exception returns. Before the repair, the same process caught the failure, claimed another bulk job, and `WpdbCanonicalLocationRepository::finalize_generation` issued `START TRANSACTION`, which committed the deleted rule. An independent connection then no longer saw `NG`. After the repair it still reads `NG`, both diagnostics remain, and metadata is cleared only when `close()` succeeds. A failed `close()` leaves the claim and the geography start unable to commit the deletion. The rejected `ROLLBACK` is injected false/`last_error`, not a server rollback failure. Disposable MariaDB ends the session with `KILL CONNECTION_ID()` and a second connection reads `NG`. `phpunit.cor006-sql.xml` is 10 tests / 120 assertions, OK. Catalog SQL remains 4 tests / 24 assertions. Bulk unit directory: 171 tests / 965 assertions, OK, 2 existing deprecations. Local PHP 8.5 full suite: 1509 tests / 9628 assertions, 1 skip, 14 existing deprecations, OK. That assertion count is local and is not the CI count. The same unattributed path message printed once and the suite exited 0. JavaScript remains 8 files / 101 tests. Production lint remains 494 files / 0 failures. The continuation matches the Action Scheduler runner's catch-and-continue shape. That runner is not vendored here, and this is not a liveness test. P07, P08, COR-005, and COR-007 stay separate.
- `31225f4ae23ba9577f0c2a8f33ee389214b543ab` abandons the session when a joined rules replacement rejects both the outer `COMMIT` and the following `ROLLBACK`. Before the repair the exception was `Bulk item write failed. Simulated SQL failure: COMMIT Rollback also failed.` and omitted `Simulated SQL failure: ROLLBACK`, and the catch returned without closing the connection. The repair copies both diagnostics, then closes or quarantines before the exception escapes. It does not send a second `ROLLBACK` when the unresolved flag is already set. An independent connection still reads the original `NG` rule after a later claim and `WpdbCanonicalLocationRepository::finalize_generation`. WordPress 6.8 `wpdb::query()` returns false when `ready` is false, before `check_connection()`. This repair does not reconnect. Adapter reconnect remains only in the earlier accepted test. The rejected statements are injected false/`last_error`, not a server rollback failure. `phpunit.cor006-sql.xml` is 12 tests / 147 assertions, OK. Catalog SQL remains 4 tests / 24 assertions. Bulk unit directory: 173 tests / 980 assertions, OK, 2 existing deprecations. Local PHP 8.5 full suite: 1511 tests / 9643 assertions, 1 skip, 14 existing deprecations, OK. That assertion count is local and is not the CI count. The same unattributed path message printed once and the suite exited 0. JavaScript remains 8 files / 101 tests. Production lint remains 494 files / 0 failures. No schema and no new production PHP file. P07, P08, COR-005, and COR-007 stay separate.

## Earlier opening-corrections checkpoint

Status preserved: RC.12 IMMUTABLE — OPENING CORRECTIONS AUTHORIZED ON ISOLATED FIX BRANCH — UNMERGED — NOT RC.13

## 2026-10-05 — authenticated HTTP opening qualification and Bulk notice repair

The owner requested the next steps after the repaired public-import handoff. Published WS3 re-review `5414988387` at `425559d2c47b1ab6831642fdd4d3852abba78ff3` passes the bounded technical repair and supersedes the earlier P1 hold for newly initiated public imports. It is an owner-requested Codex COMMENT review, not competent-human acceptance or permission to merge/promote. This continuation qualifies the remaining HTTP admin boundary of the same COR-001/002 repairs; it does not select new business/security contracts or reopen the completed audit.

- Environment: existing third fresh CI-only loopback WordPress/WooCommerce/MariaDB site, allocated disposable database, explicit enable/marker/actual-host guards. Real native login cookies, rendered form nonces, wp-admin POST/redirects and admin-AJAX requests are exercised by a bounded local HTTP client. Synthetic principals, import jobs/public configuration rows and owned test products/variations only; no existing installed site, order/payment or external recipient.
- Scope: public import initiation/preview/Apply/completion and nonce/capability-denial HTTP controls; actual scoped-configuration save and represented-slice exception reset where supported by the same bounded fixture. SQL snapshots bind durable effects. Browser JavaScript/polling, installed theme/cache/session certification, private-reference/aggregate policy, CLI/delegation/background-worker contracts and COR-007 failure/transaction/revision semantics remain separate.
- Exclusive integration editor / leases: Codex for qualification-only `scripts/qualification/opening-http-*`, bounded CI shell/artifact wiring, this checkpoint and the HTTP qualification report, plus the narrowly recorded BulkToolsPage notice integration. Agents prepare proposals/reviews outside the checkout; human ownership stays unchanged. After actual candidate fd6d254 reached real login/POSTs, its denied-nonce/redirect test exposed missing Bulk flash rendering. The same authorized COR-001 queue now reserves `src/Presentation/Admin/BulkToolsPage.php` for one call to the existing notice renderer after capability admission; no new permission, nonce, storage or redirect contract. Schema, dependencies and frozen release/tag/package identities remain unchanged. The installed map still contains 494 files; only this production file/hash changes from the preceding public-import repair.
- Guarded setup first pins fresh-site home/siteurl to the exact loopback listener origin and verifies native plus physical SQL read-back; no URL/authentication filter is replaced. The fixture may add a guarded temporary MU file only inside the disposable site to suppress asynchronous dispatch across HTTP requests and prove the correct listener/site. Credentials/cookies/nonces remain in private ephemeral files/memory and are excluded from uploaded receipts/logs. The server is loopback-only and its lifetime/cleanup are bounded; terminal handlers and native authentication/nonce functions are not replaced or intercepted.
- Exact committed source and actual CI/receipt outcomes will be recorded in the report/PR after execution. Native/callback 207-check evidence remains historical at its own head; new HTTP counts are supplementary checks, not canonical 36-unit/64-scenario completion or release approval. The 372 Requirement IDs and completed audit remain unchanged.

## 2026-10-05 — PR #51 public-only import review repair

The owner requested continuation after the native qualification handoff. Latest published WS3 review at head `84dd6590a43660672db230c3dc4186e97fe7b277` identified P1: a newly uploaded mixed package stored with `include_private_sources=false` remains action-authorized but loses normal review/history/progress access because its retained private rows require private read authority. This continuation repairs that same COR-001 workflow and adds actual-handler and native callback/SQL evidence. It does not grant private authority or choose reserved transitive-reference, delegation, worker, transaction or business contracts.

- Environment: existing disposable checkout/unit fixtures and the already guarded third fresh CI-only loopback WordPress/MariaDB site. Synthetic principals, import jobs and test configuration rows only; no live-site, order/payment, release, merge or deployment.
- Exclusive integration editor / leases: Codex for `src/Application/Bulk/Portability/ConfigurationPackage.php`, initiating import wiring in `src/Presentation/Admin/BulkToolsPage.php`, bounded Bulk regression tests and qualification-only scripts/receipt wiring plus this checkpoint and the review-repair report. Agents prepare proposals outside the checkout; human ownership remains unchanged.
- Repair direction: validate original input before storing a public-only package projection; omit recognized supplier/origin rows and reconcile their manifest entries, preserving remaining public sections and existing reference semantics. Retained raw/private-bearing historical jobs and genuinely private imports retain their existing read/action denial and revocation checks.
- Published review `5414489883` / inline finding `4183977384` remain historical review evidence. This work will update the draft PR with a tested repair; it will not resolve the review thread or publish an approval on the owner's behalf.
- Completed audit coverage, 372 Requirement IDs and frozen 36-unit/64-scenario registers stay closed/unchanged. New qualification counts are supplementary proof only.
- Repair/source checkpoint: two production files, eight expanded actual-handler regressions and the reviewed native public-import module are integrated. Proper red regression reproduced both source private-flag cases; final focused regressions, PHP 8.3 full suite, PHP 8.3/8.5 full lint and team/product controls pass. `docs/OPENING-PUBLIC-IMPORT-REVIEW-REPAIR-2026-10-05.md` records behavior, source hashes and limits. The draft PR records the exact published candidate/tree, final full-suite and native CI receipt outcomes and two bounded freshness passes after execution; native PASS is not inferred from source review. Next human owner/action remains competent WS3 recheck, with separately scoped remaining qualification/contracts.

## 2026-10-05 — targeted native qualification continuation

Native checkpoint: first candidate `7b94884` retained a failed variation-capability fixture precondition in PR CI `37304120794` (96 checks passed, one failed; later configuration/migration checks not run). The correction changes only the native fixture and evidence wording: grant/revoke the registered variation primitive while retaining native foreign-parent and production relationship denial. Production repairs are unchanged. Corrected native execution at `faa287e70e47c4a2b9d174d3ba6efddd6d4e263e` passed all 167 recorded checks (83 authority/wizard/recovery, 58 configuration, 25 migrations, one final schema check). PR/push receipts were downloaded, artifact digests and all 494 installed source hashes verified, and their case/status sequences matched. Schema/status restoration hashes matched. Push CI `37305407099` completed all eight jobs successfully. The final documentation head and exact-head CI are recorded in draft PR #51; no promotion is authorized.

The owner again instructed continuation after the four repairs and draft PR #51 were handed off. This continues the same bounded validation queue with targeted native WordPress/WooCommerce/MariaDB checks for COR-001/002/003/010. It does not authorize the reserved business contracts, COR-004/006 implementation, human review substitution, merge, release or live-site operations.

- Candidate: PR #51 head `08e6aa2ff53d22ac8e5c8c00a66c19a2b74d2b70`; protected master remains `637c02f182ca273b40819631d23ac8e0dcc4004f` at continuation start. Existing repair source remains unchanged by this qualification batch.
- Environment: a third fresh CI-only WordPress site in the runner's disposable MariaDB service, uniquely allocated database name beginning `cetech_wp_opening_qualification`, loopback connection and explicit disposable-site marker. No existing installed site or external database is used. The fixture may create test principals, roles, products/variations and plugin configuration/jobs/entities, and manipulate and restore fixed migration options for synthetic migration fault cases. It creates no order or payment.
- Integration editor / completed native qualification leases: Codex for `scripts/qualification/opening-*.php`, `scripts/qualification/admin-context.php`, the qualification-only wiring in `scripts/ci-wordpress-php85-smoke.sh` and artifact step in `.github/workflows/ci.yml`, plus this checkpoint and the qualification handoff. Review agents prepare modules outside the repository; only the integration editor applies changes. Human ownership stays unchanged.
- Evidence: native capability mapping, nonce verification and physical SQL state supplement existing regression fixtures. Direct PHP callbacks/services, redirected/die control-flow interception and simulated option-write denials will be identified explicitly. These are not HTTP/browser/session proofs or exhaustive native storage-crash, upgrade, theme/cache/payment certification.
- New results are recorded separately in `docs/OPENING-NATIVE-QUALIFICATION-2026-10-05.md`, CI's machine receipt and draft PR #51; the frozen 36-unit/64-scenario audit registers remain unchanged. Integration editing for this continuation is complete; the next owner/action is competent human WS3 review and separately scoped remaining qualification/contracts.

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
