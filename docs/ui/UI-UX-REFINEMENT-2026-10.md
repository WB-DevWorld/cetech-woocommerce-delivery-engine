# Parallel UI/UX refinement — October 2026

Status: source implementation and local verification complete on an isolated presentation branch. Integration and deployment are not requested by this checkpoint.

## Owner instruction and baseline

The owner requested: “can you work on these changes in another branch in a way that does not affect the current active branch but can also be seemlessly merged when the time comes?”

Branch: `ws1/ui-ux-refinement-2026-10`. Base: integrated master `c625a59bb61493e00d10b2802a08bac852b27b26`. The checkout is separate from active engine work. The owner instruction authorizes Codex and finite agents to implement the discussed interface refinement on this branch. CURRENT-WORK contains the branch-specific authorization and leases. No active branch is reset, rewritten or merged.

Repository authority outranks historical release-status text. Older governance documents' RC.10/schema5 baseline paragraphs are retained history; current integrated source and latest authority/status govern the actual baseline. Their native WooCommerce ownership, privacy, compatibility and immutable-history rules remain in force. This narrow owner-authorized UI task does not start future business features.

## Goals

- Make frequent staff tasks shorter and clearer while retaining WordPress conventions.
- Show custom inputs only when relevant, preserve drafts, and distinguish inherited/customized/disabled/unconfigured values.
- Make loaded delivery choices searchable without changing identifiers or submitted selections.
- Give customers consistent service/price/estimate hierarchy and visible selection/focus/error states.
- Improve small-screen, long-content, RTL and keyboard use across existing delivery/cart/checkout/order surfaces.
- Consolidate visual tokens and reduce duplicate presentation definitions.

## Scope and boundaries

| Area | Work in this branch | Boundary |
| --- | --- | --- |
| Shared admin design | Consistent palette, hierarchy, spacing, tables, badges, focus and responsive treatment | Existing navigation/capabilities/data remain authoritative |
| Product/variation editing | Progressive disclosure, explicit labels, safe mode switches, local offer filtering and draft feedback | Server still validates scope, revision, tokens, exact modes and members |
| Full scoped editor | Consolidated inheritance explanation and expandable provenance | Effective values and validation stay visible |
| Needs Attention | Concise permission-scoped copy and clear product section | Existing counts, queues, thresholds and queries unchanged |
| Bulk Tools | Concise explanation and accessible current navigation | Preview/apply/worker/recovery semantics unchanged |
| Customer delivery | Existing card selection, price/estimate comparison and focus readability | No new prices/dates/default selection or payload contract |
| Cart/checkout | Existing grouped summaries and responsive controls | No cart/package/address/quote/payment writer changes |
| Orders/shipments | Existing summary hierarchy, long values and tracking clarity | Historical snapshots/public-safe data only; no new lifecycle feature |
| Performance | Local display interactions; no new remote requests or dependencies | No claim of underlying Woo/database latency improvement |
| Compatibility | Scoped styles, existing hooks/data attributes and progressive enhancement | Physical WoodMart/B2BKing/FOX/WPML/WCML/native checkout certification requires exact-stack evidence |

## Acceptance checks

1. Without JS, forms remain usable. With JS, inherited/disabled fields hide only irrelevant inputs and preserve original permission-disabled controls.
2. Switching modes retains inactive draft values; only active allowed controls are successful in form serialization. Guard inputs and collection empty semantics remain intact.
3. Search changes visibility only, never selection or submission. Saved/draft selections outside the initial bounded list remain successful form selections, using available labels or explicit name-not-loaded text without extra per-selection queries.
4. Changing fulfilment settings renders compatible choices using the selected mode's known inherited/override value. The existing view model cannot prove a parent value beneath a saved override: the editor labels that case honestly, keeps loaded selections, and requires saving/reloading for confirmed inherited delivery/pickup details. No parent value is guessed; server authority is not recreated in JS.
5. Public output remains escaped and private supplier/origin/cost fields never enter customer markup.
6. Existing selector/cart/Blocks JS and complete non-database PHP regressions pass. Product/team control-plane checks and local PHP lint pass; the existing CI must supply the production PHP/runtime/native gates before integration.
7. Disposable rendered browser fixtures check desktop/mobile/long labels/RTL/focus/reduced-motion as local presentation evidence. They do not certify a live WordPress/theme checkout.
8. A final comparison permits only declared presentation/test/task-note paths. Master/active branch refs, flags, schema, version, dependency files and engine sources remain untouched by this branch.

## Merge strategy

Keep changes on this branch with a draft PR to master. Add only the minimal branch authorization to CURRENT-WORK; retain active engine records verbatim. No change to STATUS_CURRENT, release identity, bootstrap, shared domain contracts, package or CI files is needed. Before owner-requested integration, update this branch from latest master, review any shared presentation overlap and rerun affected checks. Never force-reset another branch. Conflict-free integration cannot be guaranteed while other work continues; conflicts must be resolved and verified before merging.

## Verification ledger

Local verification used PHP 8.3.6, PHPUnit 10.5.66, Vitest 3.2.7 and headless Chrome 153.0.8010.12. Development dependencies and disposable runtime/fixtures stay outside the committed production tree. No dependency files changed.

| Check | Result | Evidence boundary |
| --- | --- | --- |
| Exact integrated baseline, full non-database PHPUnit | 4,617 tests / 27,653 assertions passed; one historical tag test skipped in the exported archive | Baseline comparison only; archive has no Git metadata |
| UI candidate, full non-database PHPUnit | 4,624 tests / 27,716 assertions passed, no skips | Includes four new admin and three new customer presentation cases; historical tag test runs in the Git checkout |
| Full JavaScript suite | 10 files / 130 tests passed | Baseline 9 files / 123 tests; seven new focused editor cases |
| PHP syntax lint | 1,309 files, zero failures on PHP 8.3.6 | Existing repository lint excludes installed development dependencies |
| Team and product control planes | Passed, including negative fixtures; 372 unique requirement IDs retained | No classification or authority-file rewrite |
| Independent source review | No outstanding P1/P2 findings; only 23 declared presentation/test/task-note paths changed | Engine, schema, flags, version, dependencies, CI and registry untouched; CURRENT-WORK base prefix retained |
| Rendered admin/customer browser fixtures | Passed 320/375/768/1280 px in LTR/RTL, long content, keyboard focus, forced colours, reduced motion and 200% CSS zoom, with no browser errors or document overflow | Actual candidate PHP renderers and assets with repository test stubs; customer selector also checked at 300/360 px embedded widths |
| Progressive enhancement and form serialization | No-JS admin controls remain usable; JS mode panels omit inactive values, preserve selected members, revisions/row/token and drafts; search leaves selections intact | No live configuration writes; no-JS long-select overflow found and repaired with fieldset min-width |
| Merge baseline freshness | Master still equals c625a59 at final local verification | Recheck then-current master before eventual integration |

The initially incomplete local PHP runtime caused native-probe child-process failures. Corrected inherited extension/runtime settings passed both the unchanged baseline and candidate; only the corrected runs above are acceptance evidence. No repository tests or engine behavior were weakened to obtain those results.

Remaining integration evidence: the existing pinned PHP 8.3/8.4/8.5, MariaDB and WordPress/WooCommerce CI gates must run on the published exact candidate. This local fixture exercise is not a live WordPress, native checkout or physical WoodMart/B2BKing/FOX/WPML/WCML certification. Review those target surfaces before activation. Local fixture screenshots and logs are disposable verification outputs, not a release artifact.

Functional limitations remain explicit: search covers the existing bounded loaded offer list; off-page selected IDs are retained without new queries. When the current view model cannot establish inheritance beneath a saved override, saving/reloading confirms the parent delivery/pickup details. Neither limitation changes server validation, price calculation or business state.
