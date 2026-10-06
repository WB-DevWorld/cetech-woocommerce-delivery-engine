# W1-C04 rule lifecycle — implementation checkpoint

Status: **OWNER-APPROVED IMPLEMENTATION / INTEGRATION DECISION PENDING**. At 2026-10-06 22:12:16 UTC the owner instructed “Approve W1-C04-RULE-LIFECYCLE-1 and implement W1-C04.” The approved design PR #67 merged unchanged as `faf8022c6578bd9f77ecb8bc0278254426e68858`, tree `881669e06cdf0715b545157035f53e1f4f3d5ecc`. Its actual-merge run37539125349 attempt1 passed all eight jobs, native235/final HTTP66/smoke/source539; that is preservation of integrated C03, not execution of C04. [Issue #68](https://github.com/WB-DevWorld/cetech-woocommerce-delivery-engine/issues/68) tracks implementation under #48. Branch: `ws3/wave1-c04-rule-lifecycle`.

This adds an internal way to draft, schedule, publish and retire immutable versions of a rule. Publication or activation accepts the rule change, predecessor cutoff, revisions, material event and recorded completion in one C03 transaction. A retry reads the original completion; an uncertain commit is reconciled without repeating the change. Preview compares the current rules with a proposed overlay without storing anything.

The [approved design](W1-C04-RULE-LIFECYCLE-DESIGN-2026-10-06.md) remains the authority for the exact schema, lifecycle and24 proof cases. Current development identity is `1.0.0-dev.wave1-rule-lifecycle.1`, target schema8. Production family registration defaults empty. These internal contracts do not change existing Rate Card dates/precedence, ECR, scoped saves, checkout or order writes. A selected version reference is real captured rule identity; it is not a fabricated historical order-policy reference. C05–C07 and specific business adapters remain separately bounded work.

## Implemented source

| Area | Concrete implementation |
|---|---|
| Domain and strict codecs | `src/Domain/RuleLifecycle/`: exact finite family declarations, strict detached schemas, UTC microseconds, intervals, guard/logical/version identities, states, original scheduling facts, coherent snapshots, evaluator/conflicts and explicit safe/admin projections. |
| Atomic commands | `RuleLifecycleCommand.php`; `RuleLifecycleService.php` / `RuleLifecycleOperationProfile.php`: exact original envelope, C01 namespace/intent, C03-owned reservation/effect/reconciliation, current family/logical/ordered version locks, current authority, predecessor cutover, event/completion links. Lifecycle publication has no post-commit cache publisher. |
| Read/preview/activation | `RuleLifecycleReadService.php`, `RuleImpactPreviewService.php`, `RuleActivationService.php`: bounded coherent reads, current grants before disclosure, one evaluator/instant,25-candidate fixed-ceiling keyset page and separate original-envelope activation/reconciliation. No Action Scheduler hook, cron, route or durable queue is registered. |
| Persistence | `WpdbRuleLifecycleRepository.php`: explicit same-site owner, current locks, guarded row changes, one joined current-set capture with direct-predecessor evidence, bounded due pages. Current candidates and preserved history are distinct completeness boundaries. |
| Additive schema | `RuleLifecycleSchema.php`, `RuleLifecycleReadiness.php`, migration `20261006214731_create_rule_lifecycle_tables.php`: per-site InnoDB `rule_family_guards`, `logical_rules`, `rule_versions`; exact full indexes/ASCII identities; preflight before DDL; fixed-ceiling population validation; truthful schema8/status plus all five C03/C04 tables before a writer. |
| Required proof fixtures | `tests/Support/RuleLifecycle/**`, `tests/Integration/RuleLifecycle/**`; the only registered test family is `fixture_availability_v1`. Required CI separately counts44 concrete rule-lifecycle cases and43 operation-store cases. Default unit configuration excludes physical SQL groups; the blocking real-DB configuration explicitly includes them. |

Immediate drafts keep `effective_from=NULL` in their original intent. Publication seals it at the accepted database instant and returns a separate sealed content digest. Scheduling stores the original version/logical/predecessor revisions. Early activation is a retryable rejection; late activation starts live use at actual acceptance, preserving authored dates. Expired/refused/revoked activation never shortens or resurrects the predecessor. Another unrelated logical edit does not automatically invalidate the narrower original activation envelope; current complete conflicts are still rechecked under the family guard.

The family guard serializes cross-logical and hierarchical conflicts. Candidate bound1000 plus one overflow and preview subject bound100 are fail-closed. Active capture includes one direct predecessor per active version as bounded integrity evidence, without scanning all retired ancestors or counting retired evidence as live candidates. Migration diagnostics retain their separate bounded whole-population validation.

## Preservation and readiness

All three rule stores and C03 completions remain outside the existing legacy uninstall/delete lists. Retire, writer disable, deactivate, default/explicit uninstall and the no-vendor fallback preserve them. No TTL, automatic purge, conversion of legacy rules/audits, destructive rollback or new mandatory snapshot format is introduced. This does not change historical explicit-delete behavior for legacy configuration tables.

Readiness uses authoritative option/status rows and physical metadata. A failed schema8 migration also gates the current C03 generic store because they share latest migration status; native proof separately checks that an actual legacy configuration read and known-no-rate quote still return the same result. If schema8 persisted but status publication failed, recovery verifies without repeating DDL. Unknown registered-family formats, corrupt rows/relationships, incompatible engine/collation/indexes or unnamed structure changes refuse without rewriting data.

## Twenty-four-case source map

`SQL` means `tests/Integration/RuleLifecycle/RuleLifecycleRealDatabaseTest.php`; names below are actual `test_...` methods. Domain, Coordinator, ReadService, Preview, Activation, Schema, Readiness and Migration are corresponding files under `tests/Unit/RuleLifecycle/`. Native IDs are in the three `scripts/qualification/opening-rule-lifecycle*.php` modules. **A source map is not runtime qualification; executed local/CI receipts are separately recorded.**

| Design case | Concrete proof references |
|---|---|
| C04-01 | Domain `test_utc_microsecond_round_trip_preserves_boundaries_and_explicit_offset`, `test_interval_start_inclusive_end_exclusive_and_adjacency_is_not_overlap`; SQL `test_actual_decision_start_inclusive_end_exclusive_and_no_expired_resurrection`, `test_actual_future_backdated_expired_or_regressed_clock_refuses_without_rule_change` datasets. |
| C04-02 | Domain `test_selection_excludes_scheduled_draft_retired_and_expired_heads`; SQL decision-boundary and expired/revoked activation tests. |
| C04-03 | Domain detached canonical schema and strict sealed state tests; SQL `test_scheduled_edit_refuses_and_cancelled_replacement_has_new_sequence_and_uuid`; Coordinator `test_sealing_rejects_altered_author_or_private_reason_without_event`. |
| C04-04 | SQL `test_schedule_seals_and_early_original_activation_retry_accepts_when_due`, `test_late_activation_keeps_authored_start_and_cuts_over_at_real_acceptance_only`, expired/revoked test; Coordinator original early retry. |
| C04-05 | SQL `test_expired_or_revoked_activation_never_shortens_or_resurrects_predecessor`, `test_unknown_overlap_activation_preserves_predecessor_and_current_heads`. |
| C04-06 | SQL `test_two_process_publish_tokens_one_original_revision_accept_once_then_loser_is_stale`: actual OS processes and retained original-envelope loser retry. |
| C04-07 | SQL `test_missing_first_family_guard_race_has_one_generation_and_no_duplicate_guard`, `test_different_hierarchical_logicals_publish_through_same_family_guard_without_overlap_gap`, `test_real_equal_rank_collision_refuses_new_publication_without_uuid_order_winner`. |
| C04-08 | SQL `test_actual_repeatable_read_snapshot_cannot_override_newer_current_guard_and_version`, `test_retired_scheduling_predecessor_makes_original_activation_stale_and_fresh_process_preserves_original_envelope`. |
| C04-09 | SQL `test_actual_second_connection_sees_whole_cutover_only_after_commit`; Readiness strict atomic cutoff validation; current-snapshot direct-predecessor integrity tests. |
| C04-10 | SQL `test_event_completion_and_proven_unsent_commit_roll_back_all_rule_effects` audit/completion/unsent datasets; Coordinator audit/unsent rollback tests. |
| C04-11 | SQL `test_actual_rule_commit_then_injected_lost_ack_reconciles_without_second_transition`, process-death datasets, `test_real_family_guard_lock_timeout_preserves_draft_and_known_history`; native `NATIVE-C04-ACTUAL-RULE-COMMIT-LOST-ACK-READ-ONLY-RECONCILIATION`. |
| C04-12 | SQL `test_fresh_process_original_publish_replay_after_101_later_material_events`: new OS process, original receipt and unchanged current facts. |
| C04-13 | SQL `test_cross_principal_and_site_namespaces_never_replay_another_principals_receipt`, denied/revoked replay and retired original-predecessor tests; ReadService scope/subject denials; native current WordPress authority before private replay. |
| C04-14 | Domain comparator direction/shuffled selection, adjacent windows, declared predecessor collision and equal-rank/unknown-overlap tests; SQL equal-rank refusal and policy-corruption preservation. |
| C04-15 | Preview `test_baseline_overlay_use_same_evaluator_and_captured_time_without_writes`; SQL `test_actual_preview_same_evaluator_overlay_parity_zero_writes_and_stale_mutation_refusal`; native `NATIVE-C04-BASELINE-OVERLAY-PREVIEW-LEAVES-ALL-FIVE-STORES-UNCHANGED`. |
| C04-16 | SQL preview stale mutation, `test_unrelated_logical_edit_does_not_stale_narrow_sealed_activation`, and fresh-process original-envelope reconstruction after predecessor/logical changes; Preview future cutover and opened-overlay tests. |
| C04-17 | Domain unbounded candidate generator; Preview infinite subjects/1001st candidate/unknown nested subject; SQL physical candidate1001 and bounded infinite subjects/refusal tests. |
| C04-18 | SQL create/edit/publish/retire history and replacement tests; Domain captured selected reference and hypothetical distinction; native family-disable/lifecycle five-store preservation. |
| C04-19 | Activation/ReadService bounded due-page and original envelope tests; SQL `test_due_page_25_fixed_ceiling_keyset_and_unavailable_item_never_claim_activation`; native default-empty family registry. No automatic production hook/adopter. |
| C04-20 | Schema/Readiness/Migration exact structure/population refusal tests; SQL physical fault datasets; native partial-first-table, named-unique repair, engine/collation/prefix-index/unknown-column/unknown-family refusal, registered row-relationship preflight and unchanged legacy/schema sentinel IDs. |
| C04-21 | Native denied-schema / denied-status / verify-only recovery IDs plus `NATIVE-C04-FAILED-SCHEMA-LEGACY-CONFIGURATION-AND-NO-RATE-QUOTE-UNCHANGED`. |
| C04-22 | Native `opening-rule-lifecycle-preservation.php`: actual published-rule seed, all five table byte snapshots, writer/family disable, deactivation, default/explicit/no-vendor uninstall and original globals/options/connection cleanup. |
| C04-23 | Domain schema/reference detachment, safe/admin projections and direct serialization refusal; Coordinator corrupt result/event links; SQL safe serialization/policy-corruption tests; ReadService scoped private disclosure refusals. |
| C04-24 | Blocking `rule-lifecycle-real-db` group/class count44 independently of C03 count43; all native cases; exact-candidate required PHP/JS/MariaDB/WordPress/control-plane gates, installed classmap/source map and final HTTP receipt binding. |

## Finite review and proof boundaries

Only the owner and AI agents are working. Exclusive file owners implement domain, schema, transaction/repository, read/preview and native SQL proofs; root owns central integration/qualification. One finite independent source closure checks concrete links and scope boundaries. Findings corrected during implementation include missing scheduling fields in strict hydration; ignored altered author/reason on sealed transitions; completion/event identity/state/revision/time links; historical receipt validation before changed-intent classification; active capture versus full history; and current object grants before raw capture or independently supplied decision subjects. The implementation PR records closure and actual executed results.

Local development runtime is PHP8.3.6 / MariaDB10.11.14. The real SQL fixture alone may set its owned session timestamp for deterministic microsecond timelines; production acceptance still reads authoritative `UTC_TIMESTAMP(6)` after current locks. Unit coordinator/read storage is a separately labeled SQL-shaped/SQLite protocol seam, not a substitute for two real connections or process proofs. Failed test fixtures and intermediate implementation assertions are not promoted to final passes.

## Executed local checkpoint

| Proof | Result |
|---|---|
| Focused C04 units | PHP8.3.6, **142 tests /839 assertions**, PASS. Domain47/169; schema/readiness/migration33/137; coordinator29/177; repository6/39; read/preview/activation27/317. |
| Actual C04 SQL | PHP8.3.6 /MariaDB10.11.14, **44 tests /717 assertions**, PASS, zero skips; native connections, OS-process barriers/crashes/replay, deterministic fixture-session SQL microseconds. Disposable server stopped afterward. |
| Full configured local suite | PHP8.3.6, **1990 tests /12125 assertions /1 existing skip**, PASS. Physical SQL groups remain separate. |
| JavaScript | **8 files /102 tests**, PASS. |
| Production stage | No-dev autoload/classmap/schema8/package checks, **570 production PHP files /0 lint failures**, PASS. |
| Repository | **869 PHP files /0 lint failures**, PASS; narrow last native module syntax also passed. |
| Control plane | Team/product verifiers PASS, **372 frozen IDs unchanged**. |
| Finite independent source closure | All concrete findings closed; reviewer independently reran C04 units142/839 PASS. Exact native execution/CI remains separately required. |

The first full-suite pass had nine fixture/current-target failures: two old target() assertions; the legacy fake metadata parser did not report TEXT collation, correctly preventing schema8 verification; and the old uninstall test expected two retained tables rather than all five. Narrow fixture fixes restored twelve lifecycle tests and the final full suite. No production readiness condition was weakened. Intermediate141/832 C04 and44/707 SQL counts are superseded by the final local results above; none substitute for CI.

Final exact-candidate qualification requires PHP8.3/8.4/8.5, all113 real MariaDB cases (26 geography +43 C03 +44 C04), complete preserved HTTP66 plus269 native cases, smoke, cleanup and every installed production source entry checked against immutable Git. Native modules load actual WordPress/WooCommerce and production autoload without `tests/bootstrap.php`. The approved listener profile and20-second client bound stay unchanged. ZIP and receipt-member bytes/hashes, candidate/tree/execution head, run/attempt, source map and complete case counts are recorded separately in the implementation PR, avoiding self-referential source commits.

After qualification, the next owner decision is **“Approve W1-C04 for integration and implement W1-C05.”** C05 adds reader/optional snapshot-extension compatibility under the accepted plan; COR-029 placement-dependent writer decisions remain separate. No C05 implementation is started by C04.
