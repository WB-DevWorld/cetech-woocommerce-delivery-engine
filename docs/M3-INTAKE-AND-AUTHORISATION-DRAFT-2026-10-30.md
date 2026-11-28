# CETECH Delivery Engine — M3 intake and authorisation — DRAFT for Frank — 2026-10-30

Status (2026-11-28): **authorised.** Frank approved the M3–M8 authorization and starter prompt on 28 November 2026. It widens this intake to M3, M4, M7 and M8 and replaces its training-site route with staging. Sections 1 to 3 below are filled from that authorization; each filled entry says so. The inputs that only Frank or management can give are still marked open in section 2. The text that follows this paragraph is the 30 October draft, kept as written.

Original status: **draft, not authorised.** Written by Claude Code after the reunification merge (PR #190, `master` `fbca3e30`) because Frank asked, on 2026-10-30, to "go on to M3 work" once reunification was done. The M2 authorisation of 28 October 2026 names three M3 topics but gives M3 no scope, owner or acceptance list, and the reunification assessment (`docs/M2-REUNIFICATION-ASSESSMENT-2026-10-30.md`) lists the inputs still missing. This document is the intake for those inputs, in the shape of the M2 authorisation. Nothing in it is approved until Frank fills section 2 and signs section 1. **No M3 code starts before that.**

## 0. Baseline facts (verified 2026-10-30)

| Fact | Value |
|---|---|
| `master` head | `fbca3e307bdd6e3e86ca53b71db7278eb19bab2c` (merge of PR #190 by Frank, 20:09 UTC) |
| Source identity on `master` | `1.0.0-m3.0-dev.1`, `SchemaVersion::TARGET` `12`, `Requires at least: 6.5` |
| Frozen M2 deliverable | tag `v1.0.0-m2.4` on `9f045ef4`, GitHub prerelease with the verified ZIP (SHA-256 `35160e7f…`), schema 6; never moves |
| Training site | `1.0.0-m2.4` / schema 6 since 2026-10-30 16:49 UTC; every M2 acceptance row PASS |
| Business site | untouched; no go-live approval exists |
| Engines on `master` that M2 never used | `ServicePromise` (calendar arithmetic, promise policy and calendar persistence, shipment estimates, native handoff; adoption option `cetech_de_service_promise_adoption`, default off), `EmergencyControl` (checkout admission, pause command, ownership latch; writer switched off by the constant `CETECH_DE_EMERGENCY_WRITER_DISABLED`), `DeliveryPromotion`, `DeliveryEconomics`, `DeliveryCurrency`, `DataLifecycle`, `RuleLifecycle`, `Operation` |
| Schema 6 → 12 | migrations 7–12: operation tables, rule-lifecycle tables, delivery-quote tables, promise storage tables, shipment promise table, promotion budget tables; additive, never run on training |

### 0.1 What changed after this draft was written (checked 2026-11-26)

The table above is the 30 October baseline and is left as written. Since then:

| Fact | Value |
|---|---|
| `master` head | `08422f0` (2026-11-26), after the m2.5 forward port (PR #195) and the CI repair (PR #199) |
| Current M2 release | tag `v1.0.0-m2.5` on `8af9dcd1` (`fix/m2-2026-10`), prerelease with the verified ZIP (SHA-256 `127dd86e…`), schema 6; accepted by Frank on staging on 2026-11-03. `v1.0.0-m2.4` stays as the M2 sign-off package |
| Staging site | `1.0.0-m2.5` / schema 6 (seen 2026-11-25); staff tested it on 24–26 November with a 43-test kit |
| Fixes merged into `fix/m2-2026-10` on 2026-11-26 | catalog CSV export on a large catalog (#196), delivery-estimate help text (#197), CI repair (#198), place label without a town (#200); no version, package or install yet, and #196, #197 and #200 are **not on `master`** |
| CI | the PHP 8.5 WordPress/WooCommerce job depends on an upstream archive and a hosted runner image that both move; repaired on both lines on 2026-11-26, and it can recur |

What this adds to the decisions below:

- **Prerequisite P1/P2:** an M3 base on `master` does not contain #196, #197 and #200 until they are forward-ported, as m2.5 was.
- **Candidate S1 (promised dates):** staff testing found that the staging theme prints its own calendar delivery dates on the cart and order pages beside the plugin's durations. Promised dates from the plugin would sit next to those unless the theme feature is switched off; that is a site decision outside this repository (section 7).
- **Candidate S1 (one text on every surface):** testers asked for each Delivery Option to show its own estimate. Today the estimate saved in Site-wide Defaults is shown for every option, by the M2 precedence rule. Whether a promise is per option belongs in section 2 item 2.
- **Still open from staff testing, not an M3 item:** one Cash on Delivery order on staging never appeared in Needs Attention although it met every recorded condition; a fresh test order is needed to tell a settings-timing cause from a plugin fault.

## 1. Authorisation (to be approved by Frank — blank until then)

**Development.** Claude may, once this section is approved:
- create a contributor branch from the approved `master` SHA named below and implement only the scope ticked in section 3, with tests, in small PRs into that branch (or into `master` if Frank prefers one PR per bounded task);
- push commits, open and update pull requests, trigger CI and `release-package.yml`;
- write documentation, the M3 acceptance ledger under `scripts/qualification/m3/`, and registry notes.

Frank merges every PR. Claude never approves or merges a pull request and never uses a ruleset bypass.

**Training site.** Read-only inspection and backups at any time. Installs, configuration changes and rollbacks only with packages built from CI-green commits, after a backup, after announcing the action in chat, through the `training` environment of training-ops, which Frank approves. **The first install of the reunified tree is a schema 6 → 12 migration and is its own authorisation (section 4, prerequisite P1).**

**Business site.** No access and no change. Each go-live needs a separate written approval naming the ZIP hash, the maintenance window and the rollback package.

Never rewrite git history or move tags. Platform freeze stays in force.

| Approval | Frank's entry |
|---|---|
| M3 base SHA on `master` | `08422f06fc9db81f43c5370412978bad04dd9096` (M3–M8 authorization §3 and §8). `fix/m2-2026-10` `ef02a220` (`1.0.0-m2.6`) is merged on top in phase 1, keeping the `master` identity |
| M3 branch name | `batch/m3-m8-2026-11`, a temporary `batch/**` integration branch owned by the integration editor (`AGENTS.md`). Lane work arrives as `fix/**` pull requests |
| Version line | Development builds `1.0.0-m3.0-dev.N`. Gate packages `1.0.0-m3.N`, `1.0.0-m7.N` and `1.0.0-m8.N`, named after the highest milestone completed. The allowance is added to `scripts/verify-production-package-autoload.php` (authorization §4 item 8) |
| Approved on | 2026-11-28, by Frank (M3–M8 authorization) |
| Management co-signature | Not given in the authorization. Frank alone declares production readiness. Naming a release "Stable 1.0" needs a management scope decision, because M5 and M6 are excluded |

**What the 28 November authorization changes in the paragraphs above:**

- **Merging.** Claude may merge a pull request into `batch/m3-m8-2026-11` when its CI is green, the verifier agent has reviewed it and recorded that on the pull request, and no stop condition applies. Claude never merges into `master`, never approves a pull request into `master`, and never uses a ruleset bypass. Frank merges the batch branch into `master` at gates A, B and C, one cumulative pull request per gate. Tags and GitHub releases come only after Frank's acceptance in chat.
- **Test site.** Staging (`staging.cetechbpa.com`) replaces training. Claude may install packages and change plugin configuration there under three conditions: the package was built by `release-package.yml` from a CI-green commit and its hash was checked on the server; a database and plugin-folder backup was taken first; and caches are purged afterwards, with an install record written. The schema 6 → 12 upgrade on staging is approved. Further migrations on staging must be additive, keep old readers working, and follow a backup.
- **Training site.** Read-only. It stays on `1.0.0-m2.4`, schema 6. Any install there needs Frank first.
- **Workstreams.** Frank authorises work across the WS1 and WS2 file areas for this batch. The leases are recorded in `CURRENT-WORK.md`.

## 2. Inputs Frank and management must supply (nothing here can be invented by Claude)

1. **Named owner of the delivery-promise rules** (M2 authorisation §2 item 9): who signs the rules and answers questions about them.
2. **The written delivery-promise rules**, as the owner wants customers to see them:
   - order cut-off time(s) per working day, and the site timezone to use (`Africa/Accra` unless the owner says otherwise);
   - working days, and the public-holiday list or its source (fixed list per year, or a maintained calendar);
   - lead time per delivery area and per fulfilment profile (in-store vs in-warehouse) — the figures, or the rule for deriving them from today's durations such as "3–6 business days";
   - what a promise says when the order is paid after the cut-off, on a non-working day, or when capacity is unknown;
   - whether a promise is a single date, a date range, or a duration plus an "order by" time, and the exact wording for the product page, cart, checkout, emails and the staff order view.
3. **D5 emergency-pause operating rule**: who may pause (administrator only is already decided), whether a pause needs a reason or expiry, who may resume, and where staff see that checkout is paused. The customer message is fixed: "Delivery checkout is temporarily paused; please try again shortly."
4. **Parallel-engines decision** (M2 authorisation §3, D6: "decided before M3, not by you"): for each engine listed in section 0 that M2 never used — in M3 scope, kept switched off, or removed. Reunification resolved *where* they live, not *whether* they ship.
5. **Promise exposure**: do promised dates show to every customer at once (D10: Frank switches features on), to signed-in customers only, or per product/profile?
6. **Acceptance signatories** for M3, as for M2 (Frank and a named management representative).

**State of these inputs on 2026-11-28** (M3–M8 authorization §4 and §5). A default is what Claude builds until Frank changes it. Each default is built to be easy to adjust.

| Item | State |
|---|---|
| 1. Rule owner | **Open (Frank).** Asked once at the end of phase 0 |
| 2. Written rules | **Open (Frank)** for the figures: cut-off, working days, holidays, lead times per area and fulfilment profile. Decided: rules are data, not code. Claude builds the rules screen and the calculation and invents no figures. Staging uses rules clearly named as test rules. When no rule applies, the customer sees the M2 duration, never an invented date. **Default wording:** a date range, such as "Delivery between Tue 2 Dec and Thu 4 Dec", in the WordPress site time zone. A rule may be scoped to a Delivery Option; without one, the site-wide rule applies. Frank is asked to confirm |
| 3. D5 pause rule | **Default, Frank to confirm.** Administrators only may pause and resume. A reason is required; an end time is optional. Staff see the state on every Delivery Engine screen, and every action is recorded. The customer message is fixed: "Delivery checkout is temporarily paused; please try again shortly." The store is portable: no MariaDB-only SQL and no raw database handle |
| 4. Parallel engines | **Decided.** M5 and M6 are out of scope. Promotion, economics, currency, return, refund and label code stays in the tree, switched off and untouched. `ServicePromise` and `EmergencyControl` are M3. `DeliveryQuote` is M4, re-scoped rather than widened. Rule lifecycle (dry-run and overrides) and data lifecycle (reset, Issue #50) are M8 |
| 5. Exposure | **Decided.** Features ship switched off. Claude switches them on only on staging, for testing. Frank switches them on anywhere else |
| 6. Signatories | Frank declares production readiness; Claude reports "ready for Frank's acceptance". A management co-signature is not named. A management decision is needed before anything is called "Stable 1.0" |
| Order freeze point (COR-029) | **Default, Frank to confirm.** The delivery snapshot freezes when the order is placed (`woocommerce_checkout_order_processed` and the Store API equivalent). Draft orders may still update |

## 3. Candidate scope (tick what is in M3; everything unticked is out)

Each item is bounded, has an owner by workstream, and names the existing `master` component it would build on. Proposed, not approved.

| # | Candidate | Builds on | Workstream | Tick |
|---|---|---|---|---|
| S1 | Promised dates replacing durations on the product page, cart, checkout and emails, under the rules from section 2 item 2, same text on every surface (the M2 ETA-precedence rule carries over) | `ServicePromise` calendar arithmetic and presentation | WS1 (customer surfaces), WS3 (rules and calendar persistence) | ☐ |
| S2 | Promise rule and calendar administration for the named owner: working days, holidays, cut-offs, lead times, with effective-value preview and the inactive/invalid rules of D3 | `ServicePromise` policy and calendar persistence, scoped configuration | WS3 | ☐ |
| S3 | Promise on the order: the promised date frozen at placement into the order snapshot and shown on the staff order view and the customer emails; shipment estimates reconciled to it | `ServicePromise` native handoff, order snapshot v3, shipment promise table | WS2 | ☐ |
| S4 | D5 emergency pause, option a: administrator pauses and resumes delivery checkout; customers see the fixed message; staff see the pause state; auditable | `EmergencyControl` admission and pause command (writer currently disabled by constant) | WS3, WS2 | ☐ |
| S5 | Training-ops support for M3: nothing new unless S1–S4 need a configuration step; the 6 → 12 migration itself is prerequisite P1, not M3 scope | training-ops workflow | WS3 | ☐ |
| S6 | Anything on promotions, economics, currency, data lifecycle or rule lifecycle | the remaining `master` engines | — | ☐ (only if section 2 item 4 says "in scope") |

Out of M3 whatever is ticked: Air/Sea pricing (D9), refund/return terms (D7), the business-number checkout alert (D11), any production go-live.

**Ticks from the 28 November authorization (§1 and §7):**

| # | Tick | Note |
|---|---|---|
| S1 | ☑ | Promised dates on the product page, cart, checkout, thank-you page, My Account and both email formats, with the same text everywhere (`DE-PROMISE-*`) |
| S2 | ☑ | Rules screen: working days, holidays, cut-offs, lead times, effective dates, a preview that tests a date, and versions. It reuses the workflow on `ws3/admin-promise-staff-workflow` (PR #114) where that is sound, with fixtures rewritten so GitGuardian no longer flags them |
| S3 | ☑ | The promise is frozen on the order at placement, shown on the staff order view and in emails, and shipment estimates are reconciled to it |
| S4 | ☑ | Emergency pause with the section 2 item 3 default (`DE-FLAG-*`) |
| S5 | ☐ | Replaced: staging is the test site and training stays read-only. The 6 → 12 upgrade on staging is phase 1 of the batch |
| S6 | ☐ promotions, economics, currency (M5/M6, out) · ☑ rule lifecycle dry-run and overrides, data lifecycle and reset | The last two are M8 (`DE-RULE-*`, `DE-DATA-*`) |

The batch also carries M4 (`DE-QUOTE-*`, COR-023, COR-029), M7 (`DE-EMBED`, `DE-CTX-007`, `DE-UX-009`, `DE-PICKUP-*`, `DE-PERF-*`) and M8 (`DE-API-*`, `DE-CLI-*`, `DE-EVENT-*`, `DE-RULE-*`, `DE-DATA-*`, `DE-COMPAT-*`, `DE-REL-*`). The lane plan is `docs/m3-m8/LANE-PLAN.md`. The acceptance ledgers live under `scripts/qualification/m3/`, `m4/`, `m7/` and `m8/`.

## 4. Prerequisites before any M3 code (in order)

- **P1 — Install the reunified tree on training.** Build `1.0.0-m3.0-dev.N` from a CI-green `master` SHA with `release-package.yml`, verify the ZIP, take a training-ops backup, announce, then install through training-ops with Frank's explicit approval **of the schema 6 → 12 migration**. Stop and ask if the post-install inspect shows anything but `cetech_de_db_version` `12` and the expected plugin version, or if the migration reports an error. Rollback package: the backup taken by the run.
- **P2 — Re-run the M2 acceptance ledger** (`scripts/qualification/m2/acceptance-cases.md`, customer and staff cases) on the installed tree. Only a green re-run makes that SHA the M3 base (section 1 table). Any FAIL returns to the owning workstream before M3 starts.
- **P3 — Announcement to Ben (WS1) and Emmanuel (WS2)** that `master` now carries the accepted M2 surfaces and that M3 will touch their areas, with the branch and file ownership recorded in `CURRENT-WORK.md` (AGENTS.md: cross-workstream reassignment needs explicit human authorisation recorded there).
- **P4 — This document approved** (sections 1–3 filled) and committed.

## 5. Acceptance case list (skeleton; filled once section 2 arrives)

Shape as in M2: every case recorded PASS or FAIL with evidence so Frank and management can sign.

| Case | Proves | Depends on |
|---|---|---|
| R1–R26 | the M2 ledger's customer and staff cases still pass on the M3 package (regression definition) | P1–P2 |
| P1–Pn | promised date computed per the rules: before/after cut-off, across a weekend, across a listed holiday, per area and per profile, unknown capacity refuses rather than invents | S1, S2, section 2 item 2 |
| P-text | identical promise text on product page, cart, checkout, order emails and the staff order view | S1, S3 |
| P-freeze | the promise on a placed order never changes when rules or calendars change afterwards | S3 |
| E1–E4 | pause blocks delivery checkout with the fixed message, resume restores it, non-administrators cannot pause, every pause and resume is recorded | S4, section 2 item 3 |
| D-mig | schema 6 → 12 on training completes, data from M2 (rules, rate cards, scopes, orders, shipments) is intact, rollback restores the backup | P1 |

## 6. Working rules carried over unchanged from the M2 authorisation

Leave Frank's folder alone and work in worktrees (`../cetech-m3` exists on `master` for this intake); stage explicit paths only; never commit `.env*`, ZIPs, project-package folders, design references, `node_modules`, `vendor`, `dist`; never print credentials; PRs from short branches, every PR says what changed, which decision it implements and its tests; `gh auth refresh -s workflow` is Frank's to run; read-only SSH only, server changes only through training-ops, never `p06-physical`, `/root`, `/var/backups`; nothing sensitive in Actions logs or artifacts; browser acceptance by Claude in Frank's Cowork project or in the built-in browser with Frank signing in himself; Claude never handles the `claude-qa` / `claude-sm` credentials.

## 7. Stop-and-ask conditions for M3

- any schema step beyond 6 → 12, or any new migration;
- any change outside this repository (theme, site code, server configuration);
- any M2 ledger case that fails on the merged tree;
- a promise rule that cannot be expressed from section 2 without inventing a figure;
- anything touching the business site;
- a request to switch a customer-facing feature on (D10: Frank does this).

## 8. What happens next

1. Frank fills section 2 and ticks section 3, or tells Claude in chat and Claude records it here in a PR.
2. P1 is authorised and run; P2 re-run recorded under `docs/training/m3-base/`.
3. Section 1 table filled; this document loses its DRAFT status in a PR Frank merges.
4. Only then: the M3 branch and the first bounded task.
