# Opening corrections — 2026-10-05

Four focused repairs are implemented on `fix/audit-opening-authority-migration`, with regression checks and an independent source review. This is an unmerged review candidate, not completion of the full corrective program or approval for release or deployment. Relates to Issue #48; it does not close that planning issue.

## Authority and identity

The owner's instruction to continue followed presentation of the four-unit approval packet. The bounded authorization and exclusive integration-editor reservations are recorded in `CURRENT-WORK.md`. Codex applies changes; review agents provide proposals or independent inspection. Human workstream ownership remains unchanged.

- Repository: `WB-DevWorld/cetech-woocommerce-delivery-engine`.
- Base: protected `master`, `637c02f182ca273b40819631d23ac8e0dcc4004f`.
- Exact production/test source head reviewed and tested locally: `73a88274c7864f0fdb87655653141fc65ece0c3f`.
- Connected-publisher equivalent production/test commit: `69c1e97fd570ee6af4c60e94dd4cce12fa89fae9`. Publication creates service commit metadata, so commit IDs differ. All five ordered published checkpoints have exactly the same Git tree hashes as their local counterparts; the tested production/test tree is `3931f238dd4e021f7734624c4ae8b45590b595a3` at both source commits. Local test evidence is bound to that identical content, not represented as execution of an unavailable local SHA on GitHub.
- The following handoff commit changes documentation only. Its exact final head is available in Git and the draft PR; remote CI must be evaluated against that head.
- Environment: disposable local checkout, PHP fixtures and supported PHP runtimes. No external database, live WordPress site, training, Pilot, FLAIROC, production, payment or order mutation was performed.
- Schema target remains `6`. Published tags, frozen ZIPs and the completed audit's evidence/classification counts remain unchanged.

## Implemented behavior and limits

| Unit | Repair and regression evidence | Remaining boundary |
| --- | --- | --- |
| COR-001 | Recheck the stored operation's existing capability before wp-admin Apply, Cancel, Rollback or Continue. Filter job lists/details and protect AJAX disclosure/advancement. Rollback resolves original-operation ancestry and denies missing/cyclic ancestry. Retained private package data needs read permission; a stored public-only import retains its established skip-private Apply behavior. Tests use actual admin POST/render/AJAX entry points and compare job/queue state. | CLI, background-worker principal, foreign-job delegation, aggregate pagination privacy and transitive private references remain reserved. Actor attribution creates no grant; no creator-only rule is invented. |
| COR-002 | Resolve recognized scope, strict object identity, Woo object type, native object permission, actual variation parent and represented slice before save/reset/render. Empty pickers are allowed only without a parent target. Exception edit/reset forms preserve the selected slice. A successful reset deletes exactly that slice, clears resolver memoization and appends the ordinary reset audit; no-op resets add no audit. Tests cover denied foreign targets, valid owned targets, wrong/valid parents, malformed identity/scope/slice, picker access, exact-slice reset and audit. | Native WordPress principals/nonces and durable persistence require targeted qualification. COR-007 audit failure, transaction and stale-revision semantics are not chosen by this repair. Exception rows continue to represent their existing selected slice, without a new multi-slice presentation contract. |
| COR-003 | Wizard entity writes require the existing offer, rate-card, zone or Pickup capability in addition to settings/action nonce gates. Implicit default-area creation separately requires zone permission; existing-area rate creation keeps its narrower permission. Actual handlers are tested for denied/allowed principals, nonce failures, vendor/admin boundaries and capability revocation. | Existing Administrator recovery is preserved. Its physical WordPress qualification remains open. Test doubles do not certify native nonce expiry or meta-cap mapping. |
| COR-010 | Stop at the first migration application, verification, version-write or status-write failure. Read back required persisted progress/status. Reconcile an already verified version's failed status on retry without rerunning its `up()` before advancing. Reject duplicate IDs/versions and reject a batch containing a present invalid/throwing discovery file. Fault fixtures run the real migration classes in isolated child processes. | Entirely absent unknown migration files need a separate manifest/contract decision. Real storage failure/atomicity and all retained upgrade environments remain open. No schema rollback, transaction/outbox policy or new migration registry is introduced. |

COR-004/006 are outside this batch and retain their original COR-001 prerequisite. The nine proposed atom scopes, 22 unanswered business semantics and broader Wave 1 qualification are not approved by these focused repairs. The frozen 64 qualification scenarios are not marked complete by the new regression cases.

## Local validation

| Check | Exact source | Result |
| --- | --- | --- |
| Untouched-base PHPUnit, PHP 8.3.6 | `637c02f182ca273b40819631d23ac8e0dcc4004f` | 1,403 tests, 8,944 assertions, zero errors/failures, one skipped |
| Focused four-unit regressions, PHP 8.3.6 | `73a88274c7864f0fdb87655653141fc65ece0c3f` source | 67 expanded cases, 357 assertions, pass |
| Full PHPUnit, PHP 8.3.6 | `73a88274c7864f0fdb87655653141fc65ece0c3f` | 1,470 tests, 9,301 assertions, zero errors/failures, one skipped |
| Full PHPUnit, PHP 8.5.11 | same | 1,470 tests, 9,301 assertions, zero errors/failures, one skipped; 14 non-fatal deprecations reported |
| Full PHP lint, PHP 8.3.6 and 8.5.11 | same | 707 files on each runtime, zero failures |
| Composer metadata, Composer 2.10.3 / PHP 8.5.11 | same | `composer validate --no-check-publish`: valid |
| Team control plane, PHP 8.5.11 | same | pass |
| Product control plane and negative fixtures, Node | same production/test source, documentation-only work in progress | pass; 10 files, 372 unique Requirement IDs |
| Independent source review | `73a88274c7864f0fdb87655653141fc65ece0c3f` | pass; both concrete findings resolved |

PHPUnit is 10.5.65. The skipped test is `ActionSchedulerGeographyLivenessTest::test_sqlite_unique_insert_treats_in_progress_as_existing_unique_action`: these local runtimes lack PDO SQLite. It was also skipped on the untouched PHP 8.3 baseline. Default PHPUnit excludes the repository's real-database and scale groups. Those exclusions are not successful native database checks.

A PHP 8.5 diagnostic rerun with `--display-deprecations` again passed 1,470 tests / 9,301 assertions and identified all 14 sites. Two are existing `fgetcsv()` calls without an explicit escape argument in `CatalogCsvMapper.php`; twelve are existing test `ReflectionMethod`/`ReflectionProperty::setAccessible()` calls. The ten affected files are byte-identical to the base. None of the diagnostic sites is a changed path or new test. This is source comparison and a candidate rerun, not an executed PHP 8.5 baseline comparison. The retained deprecations are recorded, not repaired outside this batch.

The independent review found a parent-bearing empty-picker bypass and test-global leakage. The production picker predicate and its actual-render regression were corrected. All three new admin suites now snapshot/restore synthetic WordPress globals and GET/POST/REQUEST. The missing `esc_js` fixture is test-only and does not certify native escaping. The reviewer inspected source and did not independently execute the tests.

Reproduction commands, after installing development dependencies with Composer:

```sh
composer validate --no-check-publish
bash scripts/ci-lint-php.sh .
vendor/bin/phpunit
vendor/bin/phpunit --filter 'BulkJobAdminAuthorizationTest|ScopedConfigurationCallerAuthorityTest|SetupWizardEntityAuthorizationTest|MigrationRunnerFailureTest'
php scripts/verify-control-plane.php
node scripts/verify-product-control-plane.mjs --self-test
```

Use PHP 8.3 and PHP 8.5 separately for PHP lint and PHPUnit. PHP 8.4, JavaScript/Vitest, production-tree staging and isolated MariaDB/WordPress/WooCommerce smoke checks are additionally configured in existing remote CI. Their final-head outcomes must be read from the actual run; this local receipt does not pre-claim those results. A green general smoke job is not targeted native proof of every new authority/failure case, theme behavior, cache/session isolation or paid-order shipment/email behavior.

No JavaScript source was changed. No release ZIP was built. No dependency or lock-file change was committed.

## Exact changed paths

Production:

- `src/Application/Configuration/Admin/ProductVariationScopeGuard.php`
- `src/Application/Configuration/Admin/ScopedConfigurationAdminService.php`
- `src/Application/Configuration/Admin/ScopedConfigurationAuthorization.php`
- `src/Application/Configuration/Admin/ScopedConfigurationTargetGuard.php`
- `src/Application/Configuration/Catalog/ProductExceptionsQuery.php`
- `src/Application/Configuration/ContextualEntityService.php`
- `src/Bootstrap/Plugin.php` — configuration caller dependency wiring only
- `src/Core/Versioning/MigrationDiscovery.php`
- `src/Core/Versioning/MigrationRunner.php`
- `src/Core/Versioning/MigrationStatus.php`
- `src/Core/Versioning/SchemaVersion.php`
- `src/Presentation/Admin/BulkJobAccess.php`
- `src/Presentation/Admin/BulkJobProgressEndpoint.php`
- `src/Presentation/Admin/BulkToolsPage.php`
- `src/Presentation/Admin/ProductExceptionsPage.php`
- `src/Presentation/Admin/ScopedConfigurationPage.php`
- `src/Presentation/Admin/SetupWizardPage.php`

Tests and fixtures:

- `tests/Integration/MigrationRunnerFailureTest.php`
- `tests/Integration/fixtures/migration-runner-failure-proof.php`
- `tests/Support/RestoresWordPressFixtureGlobals.php`
- `tests/Unit/Bulk/BulkJobAdminAuthorizationTest.php`
- `tests/Unit/Configuration/Admin/ScopedConfigurationCallerAuthorityTest.php`
- `tests/Unit/Presentation/Admin/SetupWizardEntityAuthorizationTest.php`
- `tests/bootstrap.php`
- `tests/stubs/bulk-ajax-stubs.php`

Documentation: `CURRENT-WORK.md` and this file.

## Handoff

Publish the isolated branch as a draft PR and record its exact head and CI outcomes. Perform exactly two bounded final freshness passes against current remote/batch truth. The first checks the base, branch collision and relevant issue/PR state; the second checks the final remote head, draft status and actual CI outcomes. Do not start a third refresh loop.

The next human action is technical review within WS3's recorded ownership, followed by separately authorized targeted native qualification and outstanding contract decisions. Independent AI review and passing tests do not replace the competent human review required for critical WS3 changes. This batch remains unmerged and does not authorize any promotion or live-site work.
