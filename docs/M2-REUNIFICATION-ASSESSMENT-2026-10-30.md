# M2 → `master` reunification assessment — 2026-10-30

Written after management's M2 sign-off, at Frank's request ("suggest if there should be a master reunification at this stage"). Measured read-only from `origin/master` `31b2b90` and `origin/fix/m2-2026-10` `19e00a0d`. The decision is the owner's; the M2 authorisation says what happens to master's parallel engines is decided before M3.

## What the two branches are

| | `master` | `fix/m2-2026-10` |
|---|---|---|
| Head | `31b2b90` (training-ops merges on the 27 Oct reconciliation) | `19e00a0d` (PR #188) |
| Version string | `1.0.0-dev.promotion-preparation.1` | `1.0.0-m2.4` (tagged, prerelease, accepted) |
| Schema target | `12` (`SchemaVersion::TARGET`) | `6` (unchanged through M2) |
| Commits since the common base `d38b704b` | 153 incl. merges, 115 non-merge | 99 incl. merges |
| Files changed since the base | 966 (+84,044 / −1,295) | 158 (+11,139 / −1,340) |
| Unit test files | 376 | 196 |
| Installed anywhere | no (its last qualified runtime is CI) | training since 2026-10-30, accepted |

`master` carries the W1/W2/E01–E09 engines that do not exist on the M2 branch: `DeliveryQuote`, `DeliveryEconomics`, `DeliveryCurrency`, `DeliveryPromotion` (schema 11/12 promotion budgets), `ServicePromise` (deterministic calendar, immutable promise policy, handoff fences; mounted with new writes off), `EmergencyControl` (checkout admission and pause), `DataLifecycle`, `RuleLifecycle`, `Operation`, `Contracts`. The M2 branch carries the accepted customer and staff journey: D1 option A shipment groups, the D2 product gate and single Change editor, source-independent pickup, COR-024–034, Site-wide Defaults validation, the cart editor cascade, Bulk Tools access, the M2 acceptance ledger and training evidence.

## Dry-run merge (`git merge-tree origin/master origin/fix/m2-2026-10`)

47 files were changed on both sides. Ten conflict outright:

- `.github/workflows/ci.yml`
- `CURRENT-WORK.md`
- `assets/frontend/cart-customer-context-editor.css`
- `cetech-woocommerce-delivery-engine.php` (version string and bootstrap)
- `scripts/ci-opening-http-qualification.sh`
- `src/Application/Cart/CartDeliveryReselectionService.php`
- `src/Presentation/Admin/BulkToolsPage.php`
- `src/Presentation/Admin/StaffDeliveryCustomizeView.php`
- `tests/Unit/Persistence/SchemaV6InspectionTest.php`
- `tests/Unit/Shipment/SchemaV4InspectionTest.php`

The other 37 auto-merge textually but include `Plugin.php`, `ShipmentService.php`, `SelectedOfferShippingRateCalculator.php`, the selector builder and validator, the order summary builder and renderers, `DeliverySettingsPage.php`, `ScopedConfigurationPage.php`, `tests/bootstrap.php` and the order stub: every one needs a semantic review and the full suite, not just a clean textual merge. A merged tree would also move an installed M2 site from schema 6 to schema 12, which is a real database migration; the M2 engagement never ran one and treats any migration as a stop-and-ask event.

## The M3 topics already live on `master`

The M2 authorisation names three M3 topics: promised dates instead of durations, delivery-promise rules with a named owner, and the D5 emergency pause. `master` already holds a `ServicePromise` engine (calendar arithmetic, promise policy and calendar persistence, shipment estimates, native handoff) and an `EmergencyControl` engine (checkout admission, pause command, ownership latch). Building M3 on the M2 branch would re-implement both and widen the divergence; building M3 on `master` without reunifying would ship a promise engine on top of a customer journey that was never accepted.

## Options

1. **Reunify now, before any M3 code (recommended).** One bounded integration spike on a temporary `batch/**` branch owned by the integration editor (WS3): start from `master`, merge `fix/m2-2026-10`, resolve the ten conflicts with the accepted M2 behaviour winning on every customer- and staff-facing surface, keep the M2 acceptance ledger as the regression definition, run the full suite and CI, build a development package, install it on training through training-ops with a backup and watch the 6 → 12 migration, then re-run the ledger's customer and staff cases. Only a green re-run makes the merged tree the M3 base. Cost: days, not hours; it needs Frank's authorisation of the migration on training and an announcement to Ben and Emmanuel, because the batch touches files all three workstreams own.
2. **Reunify later, M3 on the M2 branch.** Fastest start, but M3 would duplicate `ServicePromise` and `EmergencyControl`, the conflict set grows with every M3 commit, and the eventual merge becomes a rewrite of one side.
3. **Retire the M2 branch, M3 on `master`.** Throws away the accepted, trained journey and restarts acceptance from zero on a codebase no human has used; not credible after the sign-off.

## Guard rails for option 1

- `v1.0.0-m2.4` stays the frozen M2 deliverable; the spike produces `1.0.0-m3.0-dev.N` builds, never a moved tag.
- No production install from the spike; production, if it ever takes M2 alone, takes `v1.0.0-m2.4` after the upgrade rehearsal and a dated go-live approval.
- The spike is a merge, not a cherry-pick festival: history of both sides is kept.
- Stop-and-ask points: any conflict that cannot be resolved by "M2 behaviour wins on accepted surfaces", any schema step beyond 6 → 12, any test from the M2 ledger that fails after the merge.

## Inputs still missing for M3 regardless of the option

- A named owner and the written delivery-promise rules (cut-off times, working days and public holidays, lead time per area and fulfilment profile).
- The D5 emergency-pause operating rule (who may pause, message text already fixed).
- An M3 intake and authorisation document in the shape of the M2 one, with its acceptance case list.
