# POST-RC.7 Fulfilment Correctness — Scenario 1 Admin Blocker Repair

**Document status:** Implementation/repair note. Replacement QA ZIP packaged; not deployed.  
**Date:** 2026-08-31  
**Branch:** `feat/post-rc7-fulfilment-correctness`  
**Starting source:** tagged `1.0.0-rc.7` / `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6`  
**Previous packaged identity:** `1.0.0-dev.fulfilment.1` (runtime dual Delivery + Pickup; admin configuration blocked Scenario 1)  
**Development identity:** `1.0.0-dev.fulfilment.2`  
**Schema target:** `5` (unchanged; no schema 6)  
**Protected tag:** `v1.0.0-rc.7` still peels to `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6`  
**FLAIROC:** not modified  
**Package:** `cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.2.zip` — see `docs/POST-RC7-FULFILMENT-CORRECTNESS-QA.md`. Not deployed. Not RC.8.

```text
READ → AUDIT → PLAN → IMPLEMENT → TEST → DOCUMENT → REVIEW → STOP
```

Physical QA of `1.0.0-dev.fulfilment.1` showed the PDP switcher was not the blocker. Staff could not configure the intended In Store model cleanly. This repair is admin/configuration only, on top of the fulfilment.1 runtime meaning of `fulfilment_choice` as default/preselection.

---

## Exact blockers repaired

### 1. In Store looked like Delivery XOR Store Pickup

Site-wide Defaults, Setup Guide, and product/variation customize presented Customer fulfilment as a mutually exclusive choice. Availability (which methods customers may use) was collapsed into the same control as the default.

Repair:

- **Available fulfilment methods** — checkboxes: Delivery, Store Pickup. One or both may be enabled.
- **Default customer choice** — Delivery or Store Pickup, and it must be one of the enabled methods.
- Delivery Options remain local doorstep services (Standard Delivery, etc.).
- Store Pickup is not listed as a Delivery Option. It is its own fulfilment alternative, selected via Pickup Location.
- In Store still rejects Air and Sea.

Encoding uses the existing ECR, not a parallel resolver:

- Delivery on ⇔ local Delivery Option IDs present
- Pickup on ⇔ valid `pickup_location_id` override (or inherit)
- Pickup off ⇔ `disable` on `pickup_location_id`
- Pickup-only ⇔ empty local offers + location + default `store_pickup`
- Both ⇔ local offers + location + default usually `delivery`

Helper: `src/Application/Configuration/InStoreMethodSelection.php`.

### 2. Pickup Location was not a first-class ECR field

Store Pickup previously depended on leftover `store_pickup` Delivery Option rows and a first-catalog-row fallback. That made Pickup look like a Delivery Option and could silently offer an unusable location.

Repair:

- New optional ECR scalar `pickup_location_id` in the existing EAV `configuration_fields` table (schema remains 5).
- Inherit / Override / Disable, same pattern as `origin_id`.
- Site-wide Defaults can set a default Pickup Location.
- Product/variation exceptions inherit or override or disable it.
- Customer-facing Pickup uses the resolved active location (name, address, instructions, readiness).
- Pickup enabled without a valid active location fails closed for Pickup and warns in operational readiness. Delivery remains usable when local Delivery Options are still valid.
- Pickup-only with no valid location fails the whole configuration.

### 3. Pickup Locations admin missed R1

Physical QA showed raw country-code text, no blank Reference Code generation, and no obvious Pickup Readiness control.

Repair:

- Blank Reference Code generates from Location Name. A manual valid code is kept.
- Duplicate/invalid code retains entered values and shows a useful error.
- Renaming a location does not change an established Reference Code.
- Country uses the WooCommerce country catalog/selector. Staff select Ghana; stored value is `GH`. Labels are not posted into the ISO-2 validator.
- One header Create/Save; entity form ownership; no nested form; Cancel remains secondary; danger zone stays outside the entity form.
- Pickup Readiness is the existing `pickup_locations.readiness_estimate` column. No schema 6. Customer copy remains “Ready for pickup: …”.

### 4. Training-data / catalog cleanup (not done here)

The known bad row `airshipping` (label Air Shipping, `route = local_delivery`) is still filtered by route. Hard constraints were not weakened. Catalog cleanup remains an owner action.

---

## Pickup readiness audit (before the admin field)

Customer-facing pickup readiness already came from `pickup_locations.readiness_estimate`. The builder already mapped that column onto the Store Pickup option’s estimate text. The gap was admin: the location form exposed instructions but not readiness, and the wizard previously wrote a ready-time value into instructions.

This repair wires the existing column into Pickup Locations admin and the wizard create path. No new table. No schema 6.

---

## Terminology (In Store)

| Staff wording | Meaning |
|---|---|
| Delivery | Doorstep / local delivery |
| Store Pickup | Customer collection |
| Delivery Options | Services available under Delivery |
| Default customer choice | Which enabled method is initially selected |
| Pickup Location | The store/warehouse customers collect from |
| Pickup readiness | “Ready for pickup” text shown to customers |

Do not label the availability control “Customer fulfilment” if that makes Delivery/Pickup look mutually exclusive.

---

## Changed files

### Identity (schema still 5)

- `cetech-woocommerce-delivery-engine.php` — `1.0.0-dev.fulfilment.2`
- `readme.txt`

### ECR / constraints / runtime

- `src/Domain/Configuration/ConfigurationFieldKey.php`
- `src/Domain/Configuration/ConfigurationFieldRegistry.php`
- `src/Domain/Configuration/ConfigurationReasonCode.php`
- `src/Application/Configuration/InStoreMethodSelection.php` (new)
- `src/Application/Configuration/HardFulfilmentConstraintService.php`
- `src/Application/Configuration/OperationalReadinessAssessor.php`
- `src/Application/Configuration/DeliveryOptionCompatibility.php`
- `src/Application/Configuration/SiteWideDefaultsService.php`
- `src/Application/Configuration/SiteWideDefaultSummary.php`
- `src/Application/Runtime/EcrToRuntimeConfigurationAdapter.php`
- `src/Application/Selector/ProductDeliveryOptionsBuilder.php`
- `src/Application/ProductRule/ResolvedProductDeliveryRule.php`
- `src/Bootstrap/Plugin.php`

### Admin

- `src/Presentation/Admin/DeliverySettingsHomePage.php`
- `src/Presentation/Admin/SetupWizardPage.php`
- `src/Presentation/Admin/StaffDeliveryCustomizeView.php`
- `src/Presentation/Admin/PickupLocationsPage.php`
- `src/Presentation/Admin/Validation/PickupLocationValidator.php`
- `src/Presentation/Admin/AdminFormHelper.php`
- `src/Application/Configuration/Admin/ConfigurationFieldCatalog.php`
- `src/Application/Configuration/Admin/EntityLabelResolver.php`
- `src/Application/Configuration/Admin/ReasonCodeLabelMapper.php`
- `src/Application/Configuration/ContextualEntityService.php`

### Tests

- `tests/Unit/Selector/PostRc7FulfilmentCorrectnessTest.php`
- `tests/Unit/Presentation/Admin/PostRc7PickupLocationAdminR1Test.php` (new)
- `tests/Unit/Presentation/Admin/PostRc6AdminSetupRepairR1RenderedFormOwnershipTest.php`
- `tests/Unit/Presentation/Admin/Stage13BR2AdminUxTest.php`
- `tests/Unit/Presentation/Admin/Stage13DAdminSimplificationTest.php`
- `tests/Unit/Configuration/SiteWideOptionalFieldValidityTest.php`
- `tests/Unit/Shipment/SchemaV4InspectionTest.php`

---

## Schema / migration impact

None. Schema remains **5**. `pickup_location_id` is an ECR field in existing `configuration_fields`. Pickup readiness uses existing `pickup_locations.readiness_estimate`.

---

## Runtime behaviour

Unchanged from fulfilment.1 except Pickup now prefers the resolved ECR location and fails closed when that location is missing/inactive instead of falling back to an arbitrary catalog row. Delivery ↔ Pickup switching, `$0` pickup quotes, and DE-managed fail-closed native-rate filtering remain.

---

## Security / privacy / shipping-integrity / compatibility / performance

- Server remains authoritative. Browser-submitted prices are still not trusted.
- Pickup location is a public customer-facing entity; suppliers/origins stay private.
- Historical order snapshots were not redesigned.
- WooCommerce country catalog is used for Pickup Location country; stored value is ISO-2.
- No WoodMart dependency. No Checkout Blocks. No carriers. No Bulk work. No per-item location architecture.
- No extra resolver pass; one more optional ECR scalar.

---

## Tests and results

| Suite | Result |
|---|---|
| Focused PHPUnit (fulfilment + pickup admin + form ownership + Stage 13 BR1/BR2/D + optional-field + hard constraints) | **83 tests, 890 assertions, OK** |
| Full PHPUnit | **774 tests, 4463 assertions, OK** (5 pre-existing deprecations) |
| Full JS (`npm run test:js`) | **28 / 28 OK** |
| Changed PHP lint | **0 errors** |

Assertions were not weakened.

---

## Scope intentionally excluded

- RC.7 mutation / RC.8 / schema 6
- FLAIROC
- Bulk Tools
- Per-item location architecture
- Return/Refund
- Checkout Blocks
- Carriers
- Catalog cleanup of `airshipping`
- International and In Warehouse owner QA (wait until Scenario 1 passes)

---

## Staging checks required (owner)

Scenario 1 only:

1. Configure In Store with Delivery + Store Pickup.
2. Select a valid active Pickup Location (create one if needed: Ghana → `GH`, readiness `1–2 business days`).
3. Enable Standard Delivery as the local Delivery Option.
4. Default customer choice = Delivery.
5. Confirm PDP: Delivery preselected, price/ETA visible; Pickup selectable; switching to Pickup removes delivery details and shows location/readiness; switching back restores Delivery.

STOP after Scenario 1. Do not continue to International or In Warehouse until Scenario 1 passes.

---

## Known limitations

- Leftover `store_pickup` Delivery Option IDs may still emit Pickup for already-saved In Store configurations until staff re-save with a Pickup Location. New admin lists no longer offer Store Pickup as a Delivery Option.
- Pickup enabled with a later-deactivated location warns and omits Pickup; it does not silently substitute another store.
- The verifier success banner may still print the historical “Schema target 4” line; the actual gate for this identity requires `TARGET === 5`.
