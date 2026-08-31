# POST-RC.8 Settings Honesty + Cart/Checkout Blocks Implementation

**Document status:** Implementation + packaged owner-QA ZIP. Physical QA pending.  
**Date:** 2026-08-31  
**Branch:** `feat/post-rc8-integrations`  
**Protected baseline:** tagged `1.0.0-rc.8` / schema `5` / `v1.0.0-rc.8` — **immutable; do not retag**  
**Development identity:** `1.0.0-dev.blocks.1`  
**Schema target:** `5` (unchanged; schema 6 was not required)  
**FLAIROC:** not modified  
**Package:** `cetech-woocommerce-delivery-engine-1.0.0-dev.blocks.1.zip` — see `docs/POST-RC8-BLOCKS-QA.md`. Not RC.9. Not deployed to FLAIROC.  
**Audit accepted:** `docs/POST-RC8-INTEGRATIONS-COMPATIBILITY-AUDIT.md` §15  

```text
READ → AUDIT → PLAN → IMPLEMENT → TEST → DOCUMENT → REVIEW → STOP
```

This stream makes Settings tell the truth about RC.8 production behaviour, replaces blind integration checkboxes with detection/status, and adds a real WooCommerce Cart & Checkout Blocks adapter on current Store API APIs. Classic checkout is unchanged. RC.8 / tag / FLAIROC remain immutable.

---

## Settings changes

Normal Settings checkboxes are only:

- `enable_customer_order_delivery_summary`
- `enable_customer_email_delivery_summary`
- `enable_order_delivery_snapshot_persistence`
- `enable_shipment_records`
- `enable_tracking_links`

Saving Settings writes **only** that list. Posted values for internal runtime flags are ignored, so an ordinary administrator cannot revert RC.8 to the legacy resolver from Settings.

### Checkout and storefront (status, not toggles)

| Capability | Operator view |
|------------|----------------|
| Classic WooCommerce checkout | **Supported** / **Automatically available** |
| Site-wide Defaults at checkout | **Active** after Activate Delivery Engine (internal ECR flag); not a checkbox |
| Product variation inheritance | Inherent when Site-wide Defaults are active |
| WooCommerce Cart & Checkout Blocks | **Supported** (or **Active** when the assigned Cart/Checkout pages use Blocks) / **Currently in use: Yes / No** |

### Optional integrations (status, not toggles)

WPML, WCML, WoodMart, WCFM, and VitePOS report installed/active/version/adapter/in-use. Blocks is shown on the storefront table, not as an experimental switch.

### Advanced

- Customer delivery timeline remains **Future / unavailable** (disabled informational checkbox).
- Demo data is non-interactive copy: this release does not seed catalog data.
- Classic is not an on/off checkbox.

Activate Delivery Engine still turns on the existing Classic runtime chain, including ECR + variation ECR, without exposing those flags as ordinary toggles.

---

## Deleted / deprecated dead flags

Removed from **normal Settings UI** (keys remain in `FeatureFlags` for Activate, diagnostics, uninstall, and controlled support/rollback):

| Stored key | Previous Settings meaning | Truth |
|------------|---------------------------|--------|
| `enable_effective_configuration_runtime` | Experimental caution / leave off unless support | Accepted production ECR. Internal only. |
| `enable_variable_product_ecr_runtime` | Independent experimental toggle | Inherent when ECR runtime is active. Internal only. |
| `enable_classic_checkout_adapter` | Experimental Classic checkbox | Classic is automatically available. Internal compatibility only. |
| `enable_blocks_adapter` | Experimental Blocks checkbox | Unused for adapter registration. Auto-detect + auto-register. |
| `enable_product_delivery_selector` | Pipeline checkbox | Activation-chain only. |
| `enable_cart_delivery_selection_capture` | Pipeline checkbox | Activation-chain only. |
| `enable_checkout_delivery_selection_validation` | Pipeline checkbox | Activation-chain only. |
| `enable_woocommerce_shipping_rate_calculation` | Pipeline checkbox | Activation-chain only. |
| `enable_bulk_import` | “Unavailable in this release” | Dead. Bulk Tools already exist and are capability-gated. No new kill switch. |
| `enable_category_rules` | Stub checkbox | No ECR-native category inheritance. Not implemented here. Legacy category compatibility route unchanged. |
| `enable_site_fallback_rule` | Stub checkbox | Not implemented. Not confused with Site-wide Defaults or Delivery Area fallback zones. |
| `demo_data_on_activation` | Functional-looking checkbox | No seeder. Informational copy only. |

Stale RC.3–RC.5 copy removed, including “Leave off unless CETECH support…” and “Unavailable in this release. Reserved for a future Delivery Engine version.”

---

## Blocks architecture

The adapter is a first-class integration (`IntegrationInterface` key `blocks`). It registers when WooCommerce is active and Store API / Blocks Package is present. It does **not** require `enable_blocks_adapter`.

```text
BlocksCheckoutAdapter
  ├── BlocksStoreApiExtension     cart-item / cart / checkout schema + public data
  ├── BlocksCheckoutValidation    Store API Place Order + address update
  ├── BlocksAddToCartBridge       Store API add-to-cart → existing cart capture
  ├── BlocksUsageDetector         CartCheckoutUtils or assigned page has_block
  └── BlocksScriptIntegration     customer JS/CSS (pickup presentation only)
```

RC.8 core/domain remains authoritative:

- Eligibility, rates, ETA, and fail-closed quoting stay in existing shipping/checkout classes.
- Cart line selection stays on the existing normalized capture (`CartDeliverySelectionCapture`).
- Order snapshots stay on `OrderDeliverySnapshotPersister` (same builder/gate).
- Shipments stay on the existing paid-order / historical planner path.

A returned `WC_Shipping_Method` rate is **not** treated as Blocks compatibility. Store API extension + validation + snapshots + customer-safe payloads are the adapter.

---

## Store API extension contract

Namespace: `cetech-delivery-engine`  
Registration: `woocommerce_store_api_register_endpoint_data` on `cart-item`, `cart`, and `checkout`.

### `cart-item` (readonly)

| Field | Meaning |
|-------|---------|
| `fulfilment_choice` | Public choice (`delivery` / `store_pickup`) |
| `fulfilment_availability` | Public availability (`in_store` / `international` / `in_warehouse`) |
| `delivery_option_label` | Public Delivery Option label |
| `estimate_text` | Public ETA / readiness |
| `pickup_location_label` | Public pickup location name |
| `pickup_address` | Public pickup address |
| `pickup_instructions` | Public pickup instructions |
| `is_pickup` | Line is Store Pickup |
| `requires_selection` | DE selection required |
| `selection_valid` | Captured selection still valid |

### `cart` / `checkout` (readonly)

| Field | Meaning |
|-------|---------|
| `packages[]` | Customer-safe package presentation (managed, pickup vs destination, charge-is-zero, public labels) |
| `has_managed_packages` | Cart contains DE-managed packages |
| `runtime_active` | DE shipping runtime is active |

Forbidden key fragments are stripped: supplier, origin, internal/private cost, rate_card, group_id, rule_id, logistics_profile, internal/staff notes.

Add-to-cart extensions accepted from Store API request namespace:

- `delivery_option_key`
- `variation_id`

These map onto the existing Classic capture filters `cetech_de_submitted_delivery_option_key` and `cetech_de_submitted_delivery_variation_id`. Classic `$_POST` capture is unchanged when those filters are empty.

---

## Client integration

`assets/frontend/blocks-checkout.js` (+ CSS) reads `wc/store/cart` extensions under the same namespace.

Responsibilities:

- Identify pickup packages.
- Replace “Shipping to {customer address}” with the public pickup address.
- Hide “Change address” on pickup packages.
- Show public location / address / readiness notes via `ExperimentalOrderShippingPackages` when WooCommerce exposes it.

The client does **not** calculate prices or invent eligibility. Prices remain Store API shipping rates from the Delivery Engine method.

---

## Checkout validation path

Classic: `woocommerce_after_checkout_validation` — unchanged.

Blocks / Store API:

1. Address/customer update: `woocommerce_store_api_cart_update_customer_from_request` → `WC()->cart->calculate_shipping()` so destination changes re-resolve DE rates.
2. Cart errors: `woocommerce_store_api_cart_errors` appends Classic `validate_cart()` messages and fail-closed managed-package errors.
3. Place Order: `woocommerce_store_api_checkout_update_order_from_request` revalidates fulfilment, Delivery Option, and destination/rate before finalisation.

Reject path: WooCommerce `RouteException` when present, otherwise `RuntimeException`. Stale/invalid selections cannot silently survive. A DE-managed package with no Delivery Engine rate fails closed; native WooCommerce methods are not accepted as fallback on that package. Unmanaged packages are unaffected.

---

## Snapshot path

Classic hooks remain:

- `woocommerce_checkout_create_order_line_item`
- `woocommerce_checkout_order_created`

Store API also uses the **same** persister methods:

- `woocommerce_store_api_checkout_order_processed` → `handle_order_created`
- `woocommerce_store_api_checkout_update_order_from_request` → `handle_store_api_order_update` → `handle_order_created`

Snapshots retain RC.8 normalized semantics (fulfilment, Delivery Option, route, fee/currency, ETA/readiness, pickup details, destination context). Historical order data remains immutable. The Blocks adapter does not create or rewrite shipments.

Pickup still quotes zero delivery shipping charge and still creates **no** delivery shipment, via existing `HistoricalShipmentPlanner` rules.

---

## Compatibility declaration

`FeaturesCompatibility::declare_blocks_compatibility()` calls:

```php
FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $plugin_file, true );
```

on the same `before_woocommerce_init` hook as HPOS, **because** the adapter is implemented and the automated parity suite passed. This is not a warning-silence-only declaration.

---

## Classic regression evidence

Classic hooks, PDP capture, cart capture, checkout validation, mixed Delivery/Pickup, International, In Warehouse, snapshots, and managed-package fail-closed behaviour remain in the existing PHPUnit suite. Blocks wiring is additive:

- Capture reads Store API filters only when Classic POST is empty.
- Snapshot Store API hooks call the same `handle_order_created` as Classic.
- Classic checkout is not gated off. Sites using Classic pages do not migrate.

`Stage13BR1AdminUxTest` now asserts the internal activation chain is **not** an ordinary Settings checkbox (`enable_product_delivery_selector` absent from Settings source; `runtime_settings()` empty). That matches Settings honesty; it does not remove Activate Delivery Engine.

---

## Security / privacy review

- Store API payloads are allow-listed public fields.
- `BlocksPublicPayload::strip_forbidden()` / `contains_forbidden()` reject supplier, origin, costs, group ids, rule ids, and notes.
- Pickup is never presented as shipping to the customer address.
- Server remains authoritative for rates and Place Order.
- Browser-submitted prices are not trusted.
- Private logistics remain off Store API schema.
- No WPML/WCML/WCFM/VitePOS adapters were added, so those flags still do not imply compatibility.

---

## Test counts

Run 2026-08-31 on this worktree. No assertions were weakened. Five PHPUnit deprecations are the same pre-existing class of notices seen on RC.8; they were not introduced to pass this suite.

| Suite | Result |
|-------|--------|
| Focused Blocks / Store API / integrations / Settings honesty / identity | **38 tests, 373 assertions, OK** |
| Full PHPUnit | **806 tests, 4705 assertions, OK** (5 pre-existing deprecations) |
| Full JS (`npm run test:js`) | **32 tests, 32 passed** (6 files) |
| Production PHP lint (`src/`, `database/`, bootstrap, uninstall) | **398 OK / 0 FAIL** |
| `composer validate --no-check-publish` | **valid** |
| `scripts/verify-production-package-autoload.php` against this source tree | **Expected FAIL** (PHPUnit in vendor, `/tests`, `phpunit.xml` present). Not a packaged ZIP. Do not treat as a packaging pass. |

### Part F acceptance matrix (automated)

| Required coverage | Test evidence |
|-------------------|---------------|
| Blocks adapter registration | `BlocksCheckoutAdapterTest::test_adapter_is_implemented_with_store_api_namespace`, `test_plugin_registers_blocks_adapter`, `PluginBootServiceGraphTest` |
| Store API schema/data exposure | `test_store_api_extension_registers_cart_and_checkout_endpoints`, `test_store_api_schema_has_no_private_fields` |
| No private supplier/origin leakage | `BlocksPublicPayloadTest` (cart item, pickup, strip, mixed, Air/Sea, In Warehouse) |
| Standard Delivery package | `test_cart_item_payload_exposes_public_selection_only`, mixed-package delivery half |
| Store Pickup package | `test_pickup_package_does_not_use_customer_shipping_destination` |
| Pickup zero charge | pickup `charge_is_zero` |
| Pickup public location/readiness | pickup payload + JS `blocks-checkout.test.js` |
| Mixed Delivery + Pickup | `test_mixed_delivery_and_pickup_packages_remain_distinct` + JS mixed filter |
| International Air | `test_international_air_and_sea_choice_labels_are_public_only` |
| International Air + Sea choice preservation | public labels distinct; `test_add_to_cart_bridge_preserves_air_sea_display_key` |
| In Warehouse Standard Delivery | `test_in_warehouse_standard_delivery_is_not_pickup` |
| Address change triggers re-resolution | `test_address_change_triggers_shipping_recalculation` |
| Stale choice rejected | `test_store_api_validation_rejects_stale_classic_result` |
| Managed package with no DE rate fails closed | `test_managed_package_without_de_rate_fails_closed_unmanaged_unaffected` |
| Unmanaged WooCommerce package unaffected | same test |
| Store API checkout validation | `BlocksCheckoutValidation` hooks + `enforce_selection_result` |
| Order snapshot parity with Classic | `test_store_api_snapshot_reuses_classic_persister`, `test_classic_checkout_hooks_remain_registered_in_classic_classes` |
| Pickup order creates no delivery shipment | `test_blocks_adapter_does_not_create_shipments` + existing `HistoricalShipmentPlannerTest` |
| Classic regression | full PHPUnit Classic/fulfilment/cart/checkout/snapshot/shipment tests |
| Compatibility declaration | `test_compatibility_declaration_uses_cart_checkout_blocks`, `CompatibilityMatrixQualificationTest` |
| Settings honesty | `SettingsHonestyTest` |

---

## Training QA instructions

Do **not** permanently replace the training site’s Classic Cart/Checkout during development or owner QA.

### Before any temporary switch

Record:

1. WordPress option `woocommerce_cart_page_id`
2. WordPress option `woocommerce_checkout_page_id`
3. Whether those pages currently contain Classic shortcodes (`[woocommerce_cart]` / `[woocommerce_checkout]`) or Blocks (`woocommerce/cart` / `woocommerce/checkout`)

Keep those IDs until restore is confirmed.

### Safe temporary Blocks pages

1. Create two **new** pages (do not edit the live Classic pages in place).
2. Insert the WooCommerce **Cart** block on one page and the **Checkout** block on the other.
3. Assign those page IDs in WooCommerce → Settings → Advanced (Cart page / Checkout page) **only for the three owner checks**.
4. After QA, restore the recorded Classic page IDs.

Owner physical QA after a **later authorised packaged QA build** is only three checks. Do not expand unless one fails.

### Check 1 — Blocks In Store

One cart: one Delivery line + one Store Pickup line. Blocks Checkout → one test order.

Confirm:

- correct package presentation
- Delivery charged
- Pickup free
- Pickup location/readiness correct
- order snapshot correct
- Pickup does not create a delivery shipment

### Check 2 — Blocks International

Correctly classified International product: valid Air only; no Standard Delivery; no Pickup; valid rate; address change/recalculation works.

### Check 3 — Blocks In Warehouse

Standard/local Delivery; no Pickup; no Air/Sea.

Cursor must not install the plugin on FLAIROC or training. Packaging is not authorised in this pass.

---

## Exact remaining limitations

- WPML, WCML, WCFM, and VitePOS adapters are **not implemented**. Settings now say so; detection is truthful.
- WoodMart remains generic WooCommerce Classic compatibility. No dedicated theme adapter. WoodMart-only surfaces (swatches, Quick View, Buy Now, mini-cart) are not newly qualified.
- No per-item destination/location editor.
- No ECR-native category inheritance.
- No site-wide fallback **product rule** layer (distinct from Site-wide Defaults and Delivery Area fallback zones).
- No demo seeder.
- Customer timeline remains future/unavailable.
- Internal FeatureFlags keys still exist for support/rollback; they are not ordinary Settings switches.
- `enable_blocks_adapter` remains stored default `false` and is not consumed for registration.
- Legacy resolver fallback is **not** removed; Activate still enables ECR.
- Blocks client pickup notes depend on Store API extensions; if WooCommerce removes `ExperimentalOrderShippingPackages`, notes may degrade while server rates/validation remain.
- Mini-cart / catalog AJAX add-to-cart without Store API extension data is not a new capture path.
- Schema remains 5. No schema 6. No RC.9. No Stage 15. No Return/Refund. No carrier APIs. No unrelated Bulk work.
- This identity is packaged as `1.0.0-dev.blocks.1` for owner QA. Physical QA on training is pending.

---

## Changed files

### Identity (schema still 5)

- `cetech-woocommerce-delivery-engine.php` — `1.0.0-dev.blocks.1`
- `readme.txt`
- `scripts/verify-production-package-autoload.php` — schema-5 checks apply to `1.0.0-dev.blocks`

### Settings / integrations UI

- `src/Presentation/Admin/DeliverySettingsPage.php`
- `src/Presentation/Admin/FeatureFlagLabels.php`
- `src/Presentation/Admin/SystemStatusPage.php`
- `src/Core/Health/HealthCheckRegistry.php`

### Blocks adapter

- `src/Integrations/Blocks/BlocksCheckoutAdapter.php`
- `src/Integrations/Blocks/BlocksStoreApiExtension.php`
- `src/Integrations/Blocks/BlocksPublicPayload.php`
- `src/Integrations/Blocks/BlocksCheckoutValidation.php`
- `src/Integrations/Blocks/BlocksAddToCartBridge.php`
- `src/Integrations/Blocks/BlocksUsageDetector.php`
- `src/Integrations/Blocks/BlocksScriptIntegration.php`
- `src/Integrations/Status/IntegrationStatus.php`
- `src/Integrations/Status/IntegrationStatusCatalog.php`
- `assets/frontend/blocks-checkout.js`
- `assets/frontend/blocks-checkout.css`

### Additive Classic-safe wiring

- `src/Bootstrap/Plugin.php`
- `src/Core/FeaturesCompatibility.php`
- `src/Application/Cart/CartDeliverySelectionCapture.php`
- `src/Application/Order/OrderDeliverySnapshotPersister.php`

### Tests

- `tests/Unit/Presentation/Admin/SettingsHonestyTest.php`
- `tests/Unit/Integrations/IntegrationStatusCatalogTest.php`
- `tests/Unit/Integrations/Blocks/BlocksPublicPayloadTest.php`
- `tests/Unit/Integrations/Blocks/BlocksCheckoutAdapterTest.php`
- `tests/js/blocks-checkout.test.js`
- `tests/Unit/Bootstrap/PluginBootServiceGraphTest.php`
- `tests/Integration/CompatibilityMatrixQualificationTest.php`
- `tests/Integration/LifecycleHarness.php`
- `tests/Unit/Presentation/Admin/Stage13BR1AdminUxTest.php`
- `tests/Unit/Shipment/SchemaV4InspectionTest.php`

### Documentation

- `docs/POST-RC8-BLOCKS-IMPLEMENTATION.md` (this file)
- `docs/AI-HANDOFF.md` current-status block

Audit document `docs/POST-RC8-INTEGRATIONS-COMPATIBILITY-AUDIT.md` is unchanged as the accepted pre-implementation audit.

---

## Intentionally excluded

- Packaging / QA ZIP / tag
- FLAIROC or training install
- RC.9
- Schema 6
- Stage 15
- WPML / WCML / WCFM / VitePOS adapters
- Per-item destination architecture
- Return/Refund
- Carrier APIs
- Unrelated Bulk Tools changes
- Removing the legacy resolver fallback
- Demo seeding
- Category inheritance

---

## STOP

Implementation, automated tests, and the owner-QA ZIP are complete. **Do not create RC.9 until physical QA is accepted.** Do not begin Stage 15. Do not modify FLAIROC. Do not retag `v1.0.0-rc.8`.
