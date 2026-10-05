# Public-only import review repair — 2026-10-05

PR #51 review `5414489883`, inline finding `4183977384`, identified a P1 at candidate `84dd6590a43660672db230c3dc4186e97fe7b277`: the initiating configuration-import handler saved uploaded supplier/origin rows even when its stored action flag excluded private sources. Existing authorization correctly denied disclosure of those rows, which also hid the normal preview/history/Apply controls and denied progress. Earlier seeded Ready-job and native read/action tests proved their narrower boundaries; they did not prove the initiating mixed-package workflow.

The owner requested continuation. This returns the bounded COR-001 review repair to the existing WS3 integration editor. PR #51 stays draft and unmerged; the published finding remains review history for competent human recheck. No reviewer approval or thread resolution is supplied by this work.

## Resulting behavior

The initiating wp-admin import validates the **original** uploaded byte size and package format/version first. Without `manage_private_sources`, it then creates an independent public-only package before storing or enumerating a job: recognized `suppliers` and `origins` sections are omitted, and the package's private flag, exported-section list and counts describe the remaining payload. Source format/plugin/schema/export-time metadata and every remaining public row/reference value are preserved. No capability is granted.

With private authority, the original mixed package is retained. Genuine private jobs still require that authority for later reads/actions; revocation still denies them. Existing retained raw mixed jobs are not rewritten or migrated: their private-content read denial and existing public-only action distinction remain. To obtain the repaired normal public workflow for an old retained mixed job, submit a new import through the supported initiating route.

Production changes are limited to `src/Application/Bulk/Portability/ConfigurationPackage.php` and the initiating import wiring in `src/Presentation/Admin/BulkToolsPage.php`. Access helpers, progress endpoint, worker/importer, reference resolution, conflict behavior, schema and dependencies are unchanged.

## Reproduction and local evidence

The new actual-initiating-handler regression was first executed against the old production source. Both source-package private-flag values reproduced the blocker: two failures at the first job-read authorization assertion. The proper red execution had no PHP error. With the repair, the eight expanded regression cases pass on PHP 8.3.6 and 8.5.11: **197 assertions** each.

The cases exercise real production initiating/action/render/progress handlers and worker/importer with explicitly labelled WordPress transport and in-memory persistence fixtures:

- Mixed source packages marked private or public: upload, public-only durable manifest, visible preview/history, progress read/advance, read-only Ready preview, rendered Apply control, actual Apply and Continue to Completed, and no retained private sentinel or private repository access/write.
- Public cancellation and import-capability revocation denial.
- Genuine private upload retained unchanged, followed by private-capability revocation denial.
- Malformed JSON, unsupported original version, and a **valid** mixed original package over the 5 MiB limit whose projection would fit: no job or queue creation.
- Source immutability, preserved provenance metadata and serialized public rows/reference fields, without reference lookup or a new transitive-authority policy.

The focused new/existing authorization/portability group passes **33 tests / 296 assertions**. The complete local PHP 8.3 execution finishes all **1,478 tests** with zero failed tests and one existing PDO SQLite skip. Full PHP 8.5 completion and final source-head CI results are recorded in PR #51 after execution; partial logs without a completion summary are not used as passing evidence.

Full PHP 8.3/8.5 lint: **714 files each, zero failures**. Team/product control planes and negative fixtures pass; all **372 Requirement IDs** remain unchanged. Independent final source review passes with explicit limits, bound to these hashes:

| File | SHA-256 |
| --- | --- |
| `ConfigurationPackage.php` | `7a3e4198b7c79de9a683b3228e6c38a22c7ee962a3d7de7275c2505350c08bc3` |
| `BulkToolsPage.php` | `af53c123ff64515dec974d957277560a5b8ee30c702261f4307c1c10be17c0be` |
| `BulkPublicConfigurationImportWorkflowTest.php` | `8ffb61b728e06f57e3c795dc373adc6e7709920e2fca37469ac5ecc845075999` |
| `opening-public-import.php` | `22e128535b4a3e8fd9bf7d8b297a2d7fc11bfa0206e04b714e3bb6077e9ca8be` |
| `opening-runner.php` | `ca590b2917518c57ecf5b6fba8032cc161b597b4d9198e7f1b32598ca265a053` |

## Supplementary native execution

The qualification runner adds `public-import` after `authority` and before `configuration`/`migrations`. The order preserves the wizard's initial empty-zone precondition. Existing guarded third fresh CI-only loopback WordPress/MariaDB environment, source/runtime receipts, fail-fast evidence retention and async-request suppression remain in use. The new module independently refuses an unmarked, non-loopback or unexpected database before fixture mutation. It creates synthetic principals/import jobs and one public offer; no order, payment or existing installed site is used.

The new module collects native persisted role/capability grants and revocations; native nonce and actual production initiating/Apply/Continue PHP callbacks; production detail/current-history/Apply-control rendering; progress read/advance and the real public import worker; and physical SQL job/item/public/private-store evidence. It requires no public write during preview, one public write at completion, no private payload sentinel in the public job/items, and byte-equal supplier/origin stores. Genuine private retention/access then revocation and the explicitly seeded legacy raw mixed shape remain negative controls. User/role, hooks, principal, request globals and notice/draft transients are restored; fixture jobs/public offer stay in the disposable database until discard.

Native completion is **not preclaimed by this report**. The existing CI command must execute the committed candidate. PR #51 records the exact published commit/tree, both CI run outcomes, downloaded artifact identities/digests, authoritative unique case counts and per-module outcomes after receipt inspection. Expected installed production map: **494 PHP files**, manifest SHA-256 `64f6ce3974ff1b2199eedac842195532726b268803722c221fd8e041475f7eb5`. This supersedes the earlier unchanged-source manifest for this later two-file repair. Earlier 167-check results remain valid historical evidence at their recorded heads.

The new module ordinarily records 40 checks, including grants/preconditions and one preview/apply tick each; bounded extra ticks change that count. Neither this anticipated count nor the eventual combined receipt total is a canonical scenario count. The frozen audit stays **665 complete / 0 partial / 0 unclosed**, with the unchanged **36-unit/64-scenario** registers.

## Evidence limits and next gates

Direct native callback invocation selects the core AJAX context through a temporary `wp_doing_ajax` filter and intercepts the AJAX terminal handler. Redirect interception throws inside the initiating handler's broad catch: the receipt checks the **first attempted supported job redirect** and records the induced later error redirect. This proves callback decisions and durable workflow state; it does not certify browser/HTTP redirect completion, success notices, login/cookies/session transport or frontend polling.

History evidence covers the precise recent-job link on the current page, not pagination privacy. Private-authorized controls prove retention/access and revocation without advancing private writes. Existing importer health summaries expose aggregate supplier/origin inventory counts even for clean public imports; no new aggregate privacy policy is selected. Public supplier/origin references and opaque public-field privacy, granular import authority, CLI/delegation, external worker scheduling, transaction/crash semantics and unsupported config rollback remain separate contracts.

Competent human WS3 recheck remains required. Other COR units/contracts, installed-theme/cache/session gates, paid-order shipment/email proof, broader upgrade/storage qualification and promotion remain separately scoped. No merge, release, deployment or live-site mutation is performed. Exact final head/CI and the required two bounded freshness passes belong to the final PR handoff.
