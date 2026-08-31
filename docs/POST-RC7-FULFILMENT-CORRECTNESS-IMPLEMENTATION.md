# POST-RC.7 Fulfilment Correctness Implementation

**Document status:** Implementation record. Packaging not started.  
**Date:** 2026-08-31  
**Branch:** `feat/post-rc7-fulfilment-correctness`  
**Starting source:** tagged `1.0.0-rc.7` / `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6`  
**Development identity:** `1.0.0-dev.fulfilment.1`  
**Schema target:** `5` (unchanged; no schema 6)  
**Protected tag:** `v1.0.0-rc.7` still peels to `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6`  
**FLAIROC:** not modified  
**Package:** not built  
**Audit accepted:** `docs/POST-RC7-FULFILMENT-CORRECTNESS-AUDIT.md`

```text
READ → AUDIT → PLAN → IMPLEMENT → TEST → DOCUMENT → REVIEW → STOP
```

This stream repairs In Store Delivery + Store Pickup as concurrent customer choices, International single-offer auto-selection, and fail-closed native WooCommerce rate filtering on Delivery Engine-managed packages. RC.7 itself remains immutable.

---

## Exact root causes repaired

### 1. In Store treated Store Pickup as an exclusive lock

`fulfilment_choice` is still stored as `delivery` or `store_pickup`. That value is now the **default/preselected customer choice**, not a path lock.

RC.7 runtime blocked concurrent In Store alternatives in two places:

- `HardFulfilmentConstraintService` allowed In Store pickup as a *choice* but only `local_delivery` *routes*, so configured Store Pickup Delivery Options were stripped before the builder ran.
- `ProductDeliveryOptionsBuilder` stopped once Store Pickup was available/selected and never expanded local Delivery offers.

Repair:

- In Store hard-constraint routes are now `local_delivery` and `store_pickup` only.
- `FulfilmentProfileRegistry` In Store allowed routes match that policy.
- The builder emits Delivery radios from local/air/sea offers **and** a single synthetic Store Pickup option when In Store pickup is configured, without stopping because pickup is present.
- International and In Warehouse constraint policies are unchanged.

### 2. Product/PDP builder and switcher

When both alternatives are configured, the builder now emits both:

- **Delivery** — eligible local Delivery Offers, estimate text (price remains server-quoted later).
- **Store Pickup** — catalog pickup location, readiness, instructions; `delivery_offer_id = null` so checkout quoting stays `$0` and grouping stays `…|store_pickup|pickup`.

Delivery is the default/preselected choice unless the resolved rule explicitly defaults to Store Pickup. The PDP switcher hides delivery offer selection, delivery charge/route/carrier copy, and doorstep ETA when Pickup is selected, and restores Delivery radios when switched back.

### 3. Pickup operational behaviour (preserved, not redesigned)

Pickup still quotes `0.0000`, pickup groups still create no delivery shipment, and cart/checkout/order snapshots now carry public pickup location/address/instructions. Shipment architecture was not redesigned.

### 4. International (constraints not weakened)

International remains Delivery Only, Air and/or Sea only, no Store Pickup, no Local Delivery. A catalog row labelled “Air Shipping” whose route is `local_delivery` is still filtered out. Exactly one eligible Air/Sea offer auto-selects; multiple eligible offers remain a customer choice; no eligible offer stays fail-closed.

### 5. WooCommerce native-rate fallback

`SelectedOfferShippingIntegration::filter_managed_package_rates` now returns **only** Delivery Engine rates on DE-managed packages when storefront shipping runtime is active. An empty DE result fails closed (no Flat Rate / Local Pickup / leftover native methods). Packages that are not DE-managed are unchanged. Flags-off behaviour still preserves native methods.

### 6. In Warehouse (preserved)

Delivery Only, local delivery services, no Store Pickup, no Air/Sea under normal configuration.

---

## Changed files

### Identity (schema still 5)

- `cetech-woocommerce-delivery-engine.php` — `1.0.0-dev.fulfilment.1`
- `readme.txt`
- `scripts/verify-production-package-autoload.php` — schema-5 checks apply to `1.0.0-dev.fulfilment`

### Runtime

- `src/Application/Configuration/HardFulfilmentConstraintService.php`
- `src/Domain/FulfilmentProfile/FulfilmentProfileRegistry.php`
- `src/Application/Selector/ProductDeliveryOptionsBuilder.php`
- `src/Application/Selector/ProductDeliveryOption.php`
- `src/Bootstrap/Plugin.php` — injects `PickupLocationRepositoryInterface`
- `src/Application/Shipping/SelectedOfferShippingIntegration.php`
- `src/Application/Cart/CartDeliverySelectionCapture.php`
- `src/Application/Cart/CartDeliverySelectionSessionData.php`
- `src/Application/Order/OrderDeliverySnapshot.php`
- `src/Application/Order/OrderDeliverySnapshotBuilder.php`
- `src/Application/Order/OrderDeliverySnapshotReader.php`
- `src/Application/Order/CustomerOrderDeliverySummaryBuilder.php`

### Storefront UI

- `src/Presentation/Frontend/ProductDeliverySelectorRenderer.php`
- `src/Presentation/Frontend/VariableDeliverySelectorAssets.php`
- `assets/frontend/product-delivery-selector.js` *(new)*
- `assets/frontend/product-delivery-selector.css`
- `assets/frontend/variable-delivery-selector.js`

### Tests

- `tests/Unit/Selector/PostRc7FulfilmentCorrectnessTest.php` *(new)*
- `tests/Unit/Runtime/InMemoryPickupLocationRepository.php` *(new)*
- `tests/Unit/Runtime/HardFulfilmentConstraintServiceTest.php`
- `tests/Unit/Shipping/SelectedOfferShippingRegistrationTest.php`
- `tests/Unit/Shipment/SchemaV4InspectionTest.php`
- `tests/Unit/Frontend/VariableDeliverySelectorAssetsTest.php`
- `tests/Unit/Presentation/Admin/Stage13BR1AdminUxTest.php`
- `tests/js/product-delivery-selector.test.js` *(new)*
- `tests/js/variable-delivery-selector.test.js`

### Documentation

- `docs/POST-RC7-FULFILMENT-CORRECTNESS-IMPLEMENTATION.md` *(this file)*
- `docs/AI-HANDOFF.md` — current-status block only

---

## Test coverage and counts

Acceptance matrix coverage:

| Case | Proof |
|------|--------|
| In Store + Delivery only | `PostRc7FulfilmentCorrectnessTest::test_in_store_delivery_only_exposes_local_and_not_pickup` |
| In Store + Delivery + Pickup | `…test_in_store_delivery_plus_pickup_exposes_both_and_defaults_to_delivery` |
| Delivery default/preselected | same + `defaultDisplayKey` / `is_default` |
| Switch Delivery → Pickup removes delivery rate/ETA | JS `product-delivery-selector.test.js` + variable selector switcher test |
| Pickup creates no delivery shipment | pickup `display_key` → `in_store\|store_pickup\|pickup`; existing shipment planner tests remain green |
| Switch Pickup → Delivery restores delivery calculation | JS restore test + builder still emits the Delivery display key |
| International cannot expose Store Pickup | constraint + builder tests |
| International cannot expose Local Delivery | including mis-routed `airshipping` `local_delivery` row |
| International Air only / Sea only / Air + Sea | `test_international_air_only_sea_only_and_air_plus_sea` |
| International exactly-one-offer auto-selection | single Air or Sea option is `is_default`; Air+Sea leaves choice unselected |
| In Warehouse remains Delivery Only/local | constraint + builder |
| Invalid hard-constraint combinations fail closed | International pickup, In Store Air/Sea, In Warehouse pickup |
| DE-managed package with no DE rate does not expose native WC methods | PostRc7 + `SelectedOfferShippingRegistrationTest` (previous fail-open assertion **replaced**, not weakened) |
| Non-DE-managed package remains unaffected | PostRc7 + registration test |
| Cart/checkout/order snapshot preserves final customer choice | public summary + pickup fields; snapshot builder still skips quote on pickup |

Results (2026-08-31):

| Suite | Result |
|-------|--------|
| Focused fulfilment PHPUnit | **39 tests, 189 assertions, OK** (1 pre-existing PHP 8.5 deprecation in that slice) |
| Full PHPUnit | **758 tests, 4282 assertions, OK** (5 pre-existing deprecations; Bulk.9/RC.7 freeze was 745 / 4223) |
| Full JS (`npm run test:js`) | **28 / 28 OK** (previously 24 / 24) |
| PHP lint on changed PHP files | **0 errors** |
| Package-sensitive shipping/shipment tests | included in the full PHPUnit run; **OK** |

Existing assertions were not weakened. The previous managed-package fail-open test was changed to assert fail-closed behaviour. Identity tests now require `1.0.0-dev.fulfilment.1` and forbid live `1.0.0-rc.7`.

---

## Confirmations

| Item | Result |
|------|--------|
| Schema | `SchemaVersion::TARGET` remains **`5`**. No migration / schema 6. |
| RC.7 commit | `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6` still exists and is this branch’s HEAD commit (work is uncommitted on top). |
| Tag `v1.0.0-rc.7` | Annotated tag object unchanged; peeled commit still `ad3feeb`. **Not retagged.** |
| FLAIROC | **Not modified.** |
| Training site | **Not modified.** |
| Stage 15 | **Not started.** |
| Bulk / per-item locations / Return-Refund / Checkout Blocks / carriers | **Not in this stream.** |
| Bulk “Ready to apply” wording | **Not repaired** (known remaining limitation). |

---

## Scope intentionally excluded

- Packaging / ZIP / tag / FLAIROC install
- Schema 6 or pickup-location ECR fields
- Live checkout fulfilment switcher (PDP switch + recapture remains the V1 path)
- Per-item pickup location architecture (catalog default location only)
- Repairing mis-routed training catalog rows such as `airshipping` with `route=local_delivery`
- Bulk Tools “Ready to apply” presentation
- Unrelated admin UI cleanup

---

## Runtime behaviour

- In Store products with local Delivery and Store Pickup offers show both customer alternatives. Delivery is preselected unless staff default is Store Pickup.
- Switching to Store Pickup submits the pickup display key, hides delivery radios/ETA, quotes `0.0000`, and creates no delivery shipment.
- Switching back submits the Delivery display key; server quoting is unchanged and authoritative.
- International products never receive Store Pickup or `local_delivery` offers from hard constraints. One eligible Air/Sea offer is auto-selected; two remain a choice.
- A DE-managed checkout package with no DE rate shows the existing customer-safe/configuration-error path instead of native WooCommerce methods.
- Unmanaged WooCommerce packages keep native methods.

---

## Security / privacy / shipping-integrity / compatibility / performance

- Pickup location fields are public catalog copy only (name, address, instructions, readiness). Supplier/origin/internal cost remain private.
- Browser-submitted prices are still not trusted. Snapshot version remains `1`; pickup fields are additive JSON.
- Historical snapshots stay immutable; this stream only writes the new public pickup fields on **new** snapshots.
- WooCommerce remains authoritative for commerce data. Native methods are suppressed only for DE-managed packages while shipping runtime flags are on.
- No new schema, no extra resolver reads beyond one bounded active pickup-location list on PDP build.
- WoodMart is unused. Core still does not depend on optional theme integrations.

---

## Known remaining limitations

- There is no live cart/checkout fulfilment switcher; the customer changes choice on the product page and recaptures.
- Pickup location is the first active catalog location, not a per-item/ECR field.
- Training catalog cleanup (Air-labelled local routes) remains owner/data work. The resolver was not weakened to accommodate those rows.
- The known Bulk Catalog “Ready to apply” wording issue remains backlog.
- Owner physical QA has not been run. It remains the three authorised scenarios only, after packaging is explicitly approved.

---

## Staging / owner QA (after packaging is authorised)

Do **not** expand owner QA unless one of these three fails:

1. **In Store** — Delivery + Store Pickup and switch between them.
2. **International** — valid Air/Sea only, never Standard Delivery/Store Pickup.
3. **In Warehouse** — quick Standard Delivery regression.

---

## Recommended next phase

**STOP.** Do not package until the owner authorises packaging. Do not retag RC.7. Do not modify FLAIROC. Do not start Stage 15.
