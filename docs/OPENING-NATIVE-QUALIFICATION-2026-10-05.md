# Targeted native opening qualification — 2026-10-05

The owner instructed continuation after the four focused repairs were implemented and PR #51 passed its initial CI. This batch adds targeted native evidence for those same COR-001/002/003/010 repairs. It changes validation scripts, CI receipt retention and documentation; the repaired production source is unchanged.

Candidate at continuation start: `08e6aa2ff53d22ac8e5c8c00a66c19a2b74d2b70`, protected-master base `637c02f182ca273b40819631d23ac8e0dcc4004f`. The draft PR records the final committed head, exact CI run and execution outcome. The result below records actual native execution; final documentation-head CI is recorded separately in the draft PR.

## Environment and execution

Existing PHP 8.5 WordPress CI still executes clean bootstrap, Store API/HPOS smoke and upgrade from immutable RC.12. A third WordPress/WooCommerce site is then installed in the runner's disposable MariaDB service for the targeted checks. It creates no order or payment and connects to no existing installed site.

- Native checks require an explicit enable flag. Shell preflight rejects non-loopback hosts before native fixture mutation.
- The database is allocated with a random `cetech_wp_opening_qualification_` suffix and plain `CREATE DATABASE`, failing on collision instead of reusing populated data.
- The runner verifies actual `DB_HOST`, database prefix, disposable marker and native bootstrap. WP-CLI loads `WP_ADMIN=true` before WordPress; production classes come from the staged plugin's production autoload, with no unit-test bootstrap or native-function replacements.
- Native principals are explicitly selected using WordPress APIs. This is not browser authentication, cookies, HTTP form submission or session/cache isolation proof.
- Terminal redirect and `wp_die` hooks are intercepted for selected PHP callback checks. Their validation and persisted effects are native; HTTP redirect/termination behavior is not certified by interception.
- WP Cron is disabled for the third fixture, and the Action Scheduler async request runner is suppressed during the qualification process. Queue records may be inspected, but no background-worker execution policy is qualified.
- Migration cases use actual runner/discovery/version/status classes, synthetic migration callbacks and native WordPress option persistence. Write denial is deliberately simulated with supported option hooks; it is not a real storage outage, crash or atomicity proof. Original schema/status rows and caches are restored and checked by raw SQL/hash comparison.
- Some fixture users/products/entities/jobs remain until the disposable CI site and service are discarded. Fixtures are not production cleanup or retention-policy evidence.

Reproduction uses the existing isolated PHP 8.5 smoke command with `CETECH_DE_NATIVE_OPENING_QUALIFICATION=1` and its loopback disposable database service. The CI workflow enables this flag. Without the flag, the general smoke command reports targeted checks `NOT_REQUESTED`; this is never a native qualification PASS.

```sh
CETECH_DE_NATIVE_OPENING_QUALIFICATION=1 bash scripts/ci-wordpress-php85-smoke.sh
```

Do not point this command at a live or retained site/database. It installs disposable WordPress fixtures and requires the described local CI service.

## Evidence by repair

| Repair | Native evidence collected | Limits retained |
| --- | --- | --- |
| COR-001 | Persisted native role assignment/capability grant and revocation; stored-operation mappings; actual Bulk Cancel POST callback with denied/permitted and swapped-nonce controls; private job PHP rendering; private action/read distinction; rollback ancestry/missing/cycle and unsupported-operation helper guards; SQL job/entity snapshots. | Native Apply/Rollback/Continue/AJAX transport, delegation/creator policy, CLI, external worker, transitive private references and aggregate pagination privacy remain separate. A different recorded actor does not create a delegation grant. |
| COR-002 | Actual Woo product/variation objects, native product/parent `edit_post` ownership mapping and the registered variation edit primitive; foreign/wrong parent and empty-picker/malformed target denial; persisted capability revocation; native nonce helper decisions; production guard plus save/reset service; physical exact-slice field/collection deletion, sibling preservation and ordinary reset audit/no-op SQL evidence. | This composes native guard/service boundaries; it does not dispatch the configuration page POST or query/form roundtrip. HTTP/session proof, audit failure coupling, transactions and stale revisions remain outside. |
| COR-003 | Actual wizard entity PHP callbacks with settings-only denial and correct entity permission; implicit default-area permission and existing-area control; Pickup permission/revocation; native missing/swapped/cross-principal nonce controls; native Administrator recovery wrapper, repair/idempotency and subordinate-role SQL preservation. | Terminal control-flow interception and explicitly selected WP-CLI principals remain declared. No HTTP/cookie/session or installed-WCFM qualification. Recovery is tested, not redesigned. |
| COR-010 | Physical native options and direct SQL read-back for ordered success/repeat/no-op; first/middle application/verification failures and retries; simulated required version/status/reconciliation denial; retained verified progress without repeated `up()`; present invalid/throwing/valid discovery controls; exact original option restoration. | Synthetic callbacks perform no DDL. Native storage crash/atomicity, every retained upgrade path and entirely absent unknown migration manifest semantics remain open. |

## Machine receipt and review

`opening-qualification-results.json` contains checkout commit/tree and candidate head separately, hashes of the actual installed production PHP sources, actual PHP/WordPress/WooCommerce/database/HPOS/schema details, per-check PASS/FAIL and durable before/after evidence. It is updated after each recorded check, preserving the first contrary result; the runner fails CI on divergence. The WordPress job uploads the receipt as `opening-native-qualification`, including on failure when a receipt exists. CI logs remain the evidence for a refusal before receipt creation.

The recorded check total includes fixture creation, preconditions, grant-state checks and restoration checks as well as behavior assertions. It is not that many independent regression scenarios, canonical scenario completions or a certification percentage. First failed execution evidence must remain identifiable even if a later corrected candidate passes.

Independent source review passed after the isolation guard was tightened. Copied authority/configuration/migration modules were verified byte-identical to their reviewed proposals. The review did not execute the native environment. Local PHP 8.3/8.5 lint, shell/workflow checks and final native CI results are recorded in the PR handoff after execution.

Changed files: `CURRENT-WORK.md`, this document, `.github/workflows/ci.yml`, `scripts/ci-wordpress-php85-smoke.sh`, and the five PHP files under `scripts/qualification/` (`admin-context.php`, `opening-runner.php`, `opening-authority.php`, `opening-configuration.php`, `opening-migrations.php`). Qualification scripts are not copied into the production plugin stage or a release package.

## First execution and fixture correction

The first native candidate `7b94884f1043b2d2f13ff65d244585100203e7a3` failed at `NATIVE-COR002-NATIVE-META-CAP-MATRIX`: 96 checks passed and one failed. All 83 authority/wizard/recovery module checks passed; configuration stopped at its permission precondition, so remaining configuration checks and migrations were not executed. Six other CI jobs passed; the required-gates job failed because the WordPress job failed. This is retained contrary evidence, not a successful qualification.

- PR run [37304120794](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/actions/runs/37304120794), receipt artifact [11342612961](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/actions/runs/37304120794/artifacts/11342612961), ZIP SHA-256 `9a1ca4201b00e365d82362567cd9241e8b3d6e6b340675c9b76c2b92e65d8bfb`. Downloaded receipt inspected directly; digest matched artifact metadata.
- Matching push run [37304114104](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/actions/runs/37304114104) retains separate first-failure artifact `11342233633`.
- Actual environment: PHP 8.5.11, WordPress 7.1.2, WooCommerce 11.1.2, MariaDB 11.4.13, HPOS enabled, schema 6. PR checkout `ab706c803da9848dd773ee2296d519136600ae5c` has exactly the candidate tree `8686f667865cb21b79dbad5d62dc6ca45c90c6ff`.
- All 494 installed production PHP hashes matched the expected repair sources, manifest SHA-256 `69b6774a57f6cbc538f5f24353963f1695510f85214f2b5f4c59ace2e348ffdc`.

The fixture incorrectly assumed Woo variations enforce author ownership through native `edit_post` mapping like products. [WooCommerce 11.1.2 registration](https://github.com/woocommerce/woocommerce/blob/11.1.2/plugins/woocommerce/includes/class-wc-post-types.php) leaves variation `map_meta_cap` disabled; [WordPress 7.1.2 mapping](https://github.com/WordPress/WordPress/blob/7.1.2/wp-includes/capabilities.php) returns the registered singular primitive. Native evidence returned `edit_product` for both owned and foreign variations. The original fixture omitted that primitive and also expected native foreign-variation denial, so its precondition was false.

The corrected fixture grants the registered variation primitive through persisted native user capabilities, records each permission decision and post-type mapping, and tests removal/restoration of that grant. Native product and parent ownership denials stay intact. With a foreign variation's primitive allowed, its foreign actual parent must still be denied by the production explicit parent gate. Wrong-but-owned-parent rejection remains required. No native function replacement or production authorization change is introduced. The later corrected execution below passed these assertions; the original failed result remains retained.

## Completed native execution

Corrected tested candidate `faa287e70e47c4a2b9d174d3ba6efddd6d4e263e`, tree `ec8965457c1a50ebbc047b61a8177ad0defe26bf`: **167 recorded checks, all PASS**. The installed production PHP manifest stayed unchanged at `69b6774a57f6cbc538f5f24353963f1695510f85214f2b5f4c59ace2e348ffdc` across all 494 files. The following documentation-only checkpoint does not alter the scripts or repaired source; its exact head and CI result are recorded in PR #51.

| Module | Recorded checks | Executed outcome |
| --- | ---: | --- |
| Authority/wizard/recovery | 83 | Native role/capability and nonce controls, selected actual PHP callbacks, persisted effects and Administrator recovery passed. |
| Configuration | 58 | Native Product/Parent ownership and Variation primitive controls; foreign-parent denial, revocation/restoration, guard/service save, physical exact-slice reset and ordinary audit/no-op passed. |
| Migrations | 25 | Ordered native-option progress, synthetic callback and simulated write-denial stop/retry/reconciliation, discovery controls and exact restoration passed. |
| Runner | 1 | Schema restored to 6. |

PR execution [37305412977](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/actions/runs/37305412977) used merge checkout `6f92e708b6f84e15136f30998ae6247644bc2059`, whose tree is exactly the tested candidate tree above. Its [receipt artifact 11342739003](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/actions/runs/37305412977/artifacts/11342739003) ZIP SHA-256 is `6618fccc2afc75571ab26b777a79e34d6c3211d581535635e19393b8dbfdc78c`. Push execution [37305407099](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/actions/runs/37305407099) ran the exact candidate checkout and completed all eight CI jobs successfully; its [receipt artifact 11343193639](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/actions/runs/37305407099/artifacts/11343193639) ZIP SHA-256 is `79a59f98450895f68718870b8017971bfa978996ded97c7b04616308de616ca0`.

Both receipts were downloaded and parsed directly. Artifact ZIP hashes matched GitHub metadata; candidate/tree/runtime and all installed file hashes matched expectations; their 167 unique case/status sequences matched. Runtime was PHP 8.5.11 / WordPress 7.1.2 / WooCommerce 11.1.2 / MariaDB 11.4.13, HPOS enabled, schema 6. Native variation edit was permitted while the foreign actual parent was denied with the explicit parent-permission reason. Original migration schema/status rows restored with identical SHA-256 `839127ae491740e413d8605eb2525424a6b7c64d556c37080782719955c224f0`; fixture marker deleted and no cleanup failures. Those hashes bind this synthetic fixture's row snapshots, not a general storage-atomicity guarantee.

Corrected-source PHP 8.3/8.4/8.5 suites each passed 1,470 tests / 9,304 assertions / one skipped. PHP 8.4 reported two existing deprecations; PHP 8.5 reported the 14 retained sites. The separate MariaDB suite passed 26 tests / 1,738 assertions / zero errors, failures or skips, with two deprecations. These results do not convert the recorded native check count into canonical scenario completions. Independent source and bounded passing-receipt review both passed: the reviewer checked unique IDs/counts, native permission decisions, SQL reset/audit state, migration stop/retry traces, original-option restoration and equality of all 494 installed source hashes. This review is not a second native execution or human approval.

## Remaining gates

The frozen 36-unit/64-scenario registers and 372 Requirement IDs retain their original status and identity. Targeted new results are supplementary evidence, not a rewrite of the accepted audit. COR-004/006, COR-007 and other reserved business/authority contracts remain outside this continuation. Existing theme/cache/session and paid-order shipment/email gates remain open.

PR #51 stays draft and unmerged. Competent human WS3 review, outstanding business decisions and separately authorized broader qualification remain necessary before promotion. No release, deployment, live-site mutation or external message is authorized by this validation continuation.
