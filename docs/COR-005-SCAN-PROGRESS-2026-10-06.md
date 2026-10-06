# COR-005 catalog scan progress

Date: 2026-10-06. Base: `1d62967b6559ee8390ddd8f787cc58ce2729c005` on `fix/cor-004-006-catalog-worker`. This checkpoint is the isolated branch `fix/cor-005-007-scan-save`. It records the approved scan, high-water, and count policy. It does not implement COR-007, P07, or P08.

## Approved policy

Preparation fixes the normalized target, action, variation policy, and a finite ceiling H. Selected-ID requests use that ID set, and H is the highest selected ID. Filter and Entire Catalog requests walk only the requested object type, and H is the highest ID of that type at the first enumeration tick. H limits which identities can enter the scan. It does not freeze product facts.

The scan is sequential. Each candidate is accepted or rejected from the facts at its first recorded decision. The job cursor is the last candidate examined, including rejections. A short page of accepted targets does not finish the scan. Completion is the candidate range being exhausted inside H. A failed SQL or effective evaluation fails the job. It is not a completed scan and it is not a zero-target result.

While preparation is incomplete, the stored total is the number of accepted targets so far. At completion that total is the approved manifest size. It is not `WooCommerceCatalogTargetQuery::count`. Progress copy shows scanned candidates and targets so far. It does not show a percentage of an unproved denominator.

Apply rechecks each approved identity before writing. A target that no longer matches, or a variation whose parent changed, is skipped as stale and does not rewrite the manifest. A product that starts matching after preparation is not added. Authorization remains the existing COR-001 gate. COR-004 filter identity is unchanged.

## Storage

`summary['preparation']` holds `high_water`, `scanned`, `effective`, and `state` (`incomplete`, `complete`, or `failed`). `checkpoint_cursor` is the candidate cursor. No schema, ledger, or new production PHP file was added. The page result is an array returned by `CatalogTargetDefinition::candidate_page`.

After a selected-ID manifest is materialized, the stored ID list is cleared and `selected_ids_materialized` stays true. Apply membership then rechecks that the product or variation still exists. It does not treat the cleared list as a rejection of every ID.

## Red then green

PHP 8.5.0. Before the repair, an empty accepted page from `page_after` set `enumeration_complete` true. The failing assertion was `Failed asserting that true is false`. The SQL prefilter value was not used as that completed total; the total became 0.

After the repair, `tests/Unit/Bulk/CatalogScanProgressTest.php` walks 40,001 in-memory candidates and accepts the one simple product after the rejected prefix. The same file covers resume after rejected pages, lower-ID backfill only ahead of the cursor, selected IDs, a thrown scan that stays failed, a post-preview nonmember that Apply does not add, and a stub `wpdb` walk of 2,501 candidates. That file is 7 tests / 51 assertions, OK.

`tests/Unit/Bulk` is 180 tests / 1031 assertions, OK, with 2 existing deprecations. Disposable MariaDB `cetech_cor004_catalog` (`phpunit.cor004-sql.xml`) is 5 tests / 30 assertions, OK, including 30 rejected stock rows and one later in-stock row. The container was stopped afterward. Local PHP 8.5 full suite is 1518 tests / 9694 assertions, 1 skip, 14 existing deprecations, OK. That assertion count is local and is not the CI count. The console printed `The system cannot find the path specified.` once and the suite still exited 0. JavaScript is 8 files / 102 tests, OK. Production lint is 494 files / 0 failures.

## Discriminator map

These map the approved cases onto tests that already passed. No extra cases were added only to raise counts.

| Approved case | Existing proof | Outstanding |
| --- | --- | --- |
| Interrupt and resume through rejected pages, unique accepted IDs, truthful scanned count | `CatalogScanProgressTest::test_a_late_match_survives_more_than_40000_rejected_candidates` and `test_resume_after_rejected_pages_does_not_accept_twice` | A crash between the source commit and `save_item` remains P07 and was not tested. |
| Change before evaluation versus a recorded rejection behind the cursor | `test_resume_after_rejected_pages_does_not_accept_twice` changes product 1 to simple after the cursor has passed it. It is not accepted. | |
| Lower-ID backfill ahead of the cursor, and IDs above H | `test_lower_id_backfill_is_included_only_ahead_of_the_cursor` accepts ID 4 and excludes ID 11. High water stays 10. | |
| Selected IDs stay that set | `test_selected_ids_do_not_gain_an_unlisted_product` | Exact variation-parent identity during the scan is not asserted in this file. |
| Failed evaluation is not a completed zero-target job | `test_a_failed_scan_is_not_a_completed_zero_target_job` | A completed empty manifest that must not broaden the request has no dedicated assertion in this file. |
| Approved target that later changes, and a later nonmember | `test_a_product_that_matches_after_preparation_is_not_applied` returns `stale_target` and does not add product 9. The manifest total stays 1. | A candidate that disappears during the scan is not a separate case. |
| Rejected SQL rows still advance the cursor | `CatalogTargetIdentitySqlTest::test_scan_keeps_the_candidate_cursor_through_rejected_rows` on disposable MariaDB | |
| Progress has no exact percentage | `tests/js/bulk-tools-catalog.test.js` expects `Preparing preview · scanned 400 · targets so far 1` | |
| Revoked permission denies Apply | `BulkJobAdminAuthorizationTest::test_revoked_stored_private_inclusion_denies_apply` | A wrong-principal case was not added to the scan file. |
| Incomplete preparation does not Apply | The worker processes items only when `enumeration_complete` is true. The failed-scan test stays out of Ready. | There is no separate assertion that a non-failed incomplete job refuses Apply. |

The earlier full-list lookup that stopped after about two minutes is not in this table. PHPUnit printed no result for that run. The completed indexed run is the 7 tests / 51 assertions above.

## Limits

This does not prove a WordPress product catalog, Action Scheduler liveness, or a crash between a source commit and `save_item`. Those remain outside this checkpoint. PHP 8.3 and 8.4 are not installed in this checkout. CI on this head is not claimed here.
