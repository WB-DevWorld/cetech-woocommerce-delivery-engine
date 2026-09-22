# CETECH WooCommerce Delivery Engine — Architecture Plan

> **Current implementation reconciliation (2026-09-22):** this architecture document contains early V1 planning language as well as durable architecture. Current protected master is post-RC.12/schema 6 and supports both Classic and Blocks checkout. When an old planning statement conflicts with current code/status, `CURRENT-WORK.md`, `docs/STATUS_CURRENT.md`, and the implemented contracts win.

**Phase:** 0B — Productized Architecture Plan  
**Sources:** `docs/AI-HANDOFF.md`, `docs/PROJECT-RULES.md`  
**Status:** Planning only — no plugin code in this phase

| Property | Value |
|----------|-------|
| Plugin name | CETECH WooCommerce Delivery Engine |
| Plugin slug | `cetech-woocommerce-delivery-engine` |
| Root file (future) | `cetech-woocommerce-delivery-engine.php` |
| Root namespace (future) | `CetechDeliveryEngine\` |
| Minimum PHP (recommended) | **8.3** |

---

## 1. Executive summary

CETECH WooCommerce Delivery Engine is a **productized, modular-monolith WordPress plugin** that adds a delivery-and-fulfilment domain layer on top of WooCommerce. WooCommerce remains the commerce owner (cart, checkout, payments, orders, taxes, refunds). The engine owns fulfilment rules, delivery offers, manual pricing, shipment grouping, shipping-package construction, order delivery snapshots, shipment records, and customer-safe shipment visibility.

Version 1 is deliberately narrow: **manually priced**, **manually tracked**, **classic checkout first**, **HPOS-compatible**, and **integration-optional**. The canonical runtime pipeline is:

```text
WooCommerce Product / Variation
→ Fulfilment Rule Resolver
→ Delivery Offer Resolver
→ Server-side Price / Estimate Engine
→ Cart-Line Delivery Selection
→ Shipment Package Builder
→ WooCommerce Custom Shipping Method
→ Order Delivery Snapshot
→ Shipment Records
→ Staff Shipment Updates
→ Customer Shipment Timeline
```

Architecture priorities: server-side authority, per-line delivery selection, immutable paid-order snapshots, supplier/origin privacy, no silent offer replacement, no free shipping on missing rate cards, and feature-flagged rollout so the plugin does not immediately take over every product on activation.

---

## 2. Productized plugin assumptions

| Assumption | Implication |
|------------|-------------|
| Multiple independent WooCommerce sites | No shared DB, suppliers, zones, or policies; no hardcoded brand/country/carrier |
| Unknown plugin/theme stack at install time | Runtime integration detection; Null adapters; no fatal errors when optional plugins absent |
| Sites differ in HPOS, checkout type, cache, multilingual state | Declare HPOS compatibility; Classic + Blocks checkout support; cache-safe dynamic endpoints |
| Operators configure per site | Onboarding wizard optional; no auto-seeding of offers/rate cards unless demo mode |
| Commercial maintainability | Composer autoload, namespaced PHP, versioned migrations, semantic versioning |
| Pilot-first rollout | Feature flags default off for customer-facing takeover; expand catalog deliberately |
| V1 operational simplicity | Prefer fixed per-shipment / fixed per-item rate cards; complex formulas deferred |

The plugin must not assume WoodMart, WPML, WCML, WCFM, VitePOS, Redis, WP Rocket, Blocks, or carrier APIs.

---

## 3. Hard dependency and optional integration strategy

### Hard dependency

| Dependency | Activation behaviour |
|------------|---------------------|
| **WooCommerce** | Required. If inactive: admin notice, stop bootstrapping delivery features, no fatal error. |

### Optional integrations (adapter pattern)

| Integration | Adapter class (future) | Null fallback | V1 expectation |
|-------------|------------------------|---------------|----------------|
| WoodMart | `WoodMartAdapter` | `NullWoodMartAdapter` | Hook/event styling compatibility when flag + theme detected |
| WPML | `WpmlAdapter` | `NullTranslationAdapter` | String registration + copy operational fields to translations |
| WCML | `WcmlAdapter` | `NullCurrencyAdapter` | Base currency + checkout currency snapshot |
| WCFM | `WcfmAdapter` | `NullWcfmAdapter` | **Contract + stub**; deny vendor access by default |
| VitePOS | `VitePosAdapter` | `NullVitePosAdapter` | **Contract + stub**; POS flow tested separately if enabled |
| Redis | `RedisCacheStore` | `InMemoryCacheStore` / WP transients | Config cache only; never cross-customer quotes |
| WP Rocket | `WpRocketCompatibility` | no-op | Document cache exclusions |
| WooCommerce Blocks | `BlocksCheckoutAdapter` | not loaded | **Future-only** in V1 |
| Tracking plugins | `TrackingPluginBridge` | no-op | **Future-only** handoff hooks |
| Carrier APIs | `CarrierApiGateway` | not loaded | **Excluded V1** |

**Rule:** Core and Application layers depend only on **interfaces** (`TranslationAdapterInterface`, `CurrencyAdapterInterface`, `ThemePresentationAdapterInterface`, etc.). `IntegrationRegistry` selects concrete or Null implementation at boot based on `isAvailable()` checks.

---

## 4. Defensive loading strategy

### Boot phases

```text
1. Plugin file loaded
   → define constants (slug, version, paths)
   → require Composer autoloader (when present)
   → register activation/deactivation hooks only

2. plugins_loaded (early)
   → if ! class_exists('WooCommerce'): admin notice + return
   → declare HPOS compatibility hook
   → build ServiceContainer (lazy)

3. woocommerce_loaded
   → run IntegrationRegistry::detect()
   → register capabilities, custom tables check, migrations
   → if schema incomplete: admin-only mode + notice
   → register modules whose feature flags are on

4. init / wp_loaded
   → frontend presentation (if product selector enabled)
   → admin console (if user has caps)

5. woocommerce_init
   → shipping method registration
   → cart/checkout hooks
```

### Defensive rules

- Never `require` optional plugin files directly.
- Never call `icl_*`, WCML, WCFM, WoodMart, or VitePOS functions from Core.
- Wrap module registration in try/catch with logged error boundary; one module failure must not white-screen the site.
- AJAX/REST endpoints: nonce + capability or WC session auth; always recalculate server-side.
- Autoload failures: admin notice with safe deactivate path.

---

## 5. Plugin folder structure

Future repository layout (no files created in Phase 0B):

```text
cetech-woocommerce-delivery-engine/
├── cetech-woocommerce-delivery-engine.php    # bootstrap only
├── uninstall.php
├── composer.json                               # Phase 1+
├── readme.txt
├── languages/
├── assets/
│   ├── admin/
│   ├── frontend/
│   └── build/
├── templates/                                  # overridable via theme
│   ├── product/
│   ├── cart/
│   ├── checkout/
│   ├── myaccount/
│   └── emails/
├── database/
│   ├── migrations/
│   └── schema/
├── src/
│   ├── Bootstrap/
│   │   ├── Plugin.php
│   │   ├── ServiceContainer.php
│   │   └── FeatureFlags.php
│   ├── Core/
│   │   ├── Capabilities/
│   │   ├── Health/
│   │   └── Versioning/
│   ├── Domain/
│   │   ├── DeliveryOffer/
│   │   ├── ProductRule/
│   │   ├── Shipment/
│   │   ├── RateCard/
│   │   ├── Zone/
│   │   ├── LogisticsProfile/
│   │   ├── Supplier/
│   │   └── Estimate/
│   ├── Application/
│   │   ├── Resolver/
│   │   ├── Calculator/
│   │   ├── Planner/
│   │   └── Service/
│   ├── Infrastructure/
│   │   ├── Persistence/
│   │   ├── WooCommerce/
│   │   ├── WordPress/
│   │   ├── Cache/
│   │   └── Logging/
│   ├── Presentation/
│   │   ├── Admin/
│   │   ├── Frontend/
│   │   └── ClassicCheckout/
│   ├── Integrations/
│   │   ├── Registry/
│   │   ├── WoodMart/
│   │   ├── WPML/
│   │   ├── WCML/
│   │   ├── WCFM/
│   │   ├── VitePOS/
│   │   └── Blocks/                            # future stub directory
│   └── Support/
└── tests/
    ├── Unit/
    ├── Integration/
    ├── WooCommerce/
    └── E2E/
```

Business rules must not live in `functions.php`, theme templates, or JS-only logic.

---

## 6. Namespace strategy

| Layer | Namespace prefix |
|-------|------------------|
| Bootstrap | `CetechDeliveryEngine\Bootstrap\` |
| Core | `CetechDeliveryEngine\Core\` |
| Domain | `CetechDeliveryEngine\Domain\` |
| Application | `CetechDeliveryEngine\Application\` |
| Infrastructure | `CetechDeliveryEngine\Infrastructure\` |
| Presentation | `CetechDeliveryEngine\Presentation\` |
| Integrations | `CetechDeliveryEngine\Integrations\` |
| Support | `CetechDeliveryEngine\Support\` |

PSR-4 autoload root: `src/`. No global functions except minimal bootstrap shim in root PHP file if WordPress convention requires it.

---

## 7. Bootstrap strategy

The root plugin file (`cetech-woocommerce-delivery-engine.php`) will **only**:

1. Define plugin header metadata and constants.
2. Load Composer autoloader.
3. Instantiate `CetechDeliveryEngine\Bootstrap\Plugin` and call `->boot()`.
4. Register activation/deactivation/uninstall hooks delegating to `Activator`, `Deactivator`, `Uninstaller`.

`Plugin::boot()` responsibilities:

- WooCommerce dependency gate.
- `FeaturesCompatibility::declare_hpos_compatibility()`.
- Build `ServiceContainer` with interface → implementation bindings.
- Run `IntegrationRegistry::register()`.
- Schedule `MigrationRunner` if DB version behind.
- Register hooks via dedicated `HookRegistrar` classes per module (not scattered in bootstrap).

Activation creates tables, indexes, default statuses, capabilities, feature-flag defaults, audit channel — **does not** seed production offers/rate cards unless `demo_data` flag enabled.

---

## 8. Module map

```text
Core
Product Rules
Delivery Offers
Destination Zones
Logistics Profiles
Supplier and Origin Registry
Rate Cards and Consolidation
Delivery-Date Estimator
Cart and Checkout Adapter
WooCommerce Shipping Adapter
Shipment Management
Tracking
Customer Account Adapter
Admin Console
Audit and Diagnostics
Import/Export
Integration Registry
  ├── WoodMart (optional)
  ├── WPML (optional)
  ├── WCML (optional)
  ├── WCFM (optional, stub V1)
  ├── VitePOS (optional, stub V1)
  └── Blocks (future)
Security and Capabilities (cross-cutting in Core)
```

Modules communicate through **Application services** and **domain events**, not direct cross-module DB access except via repositories.

---

## 9. Core module responsibilities

| Responsibility | Owner |
|----------------|-------|
| Plugin bootstrap wiring | `Bootstrap\Plugin`, `ServiceContainer` |
| WooCommerce / PHP version checks | `Core\Requirements` |
| HPOS compatibility declaration | `Core\FeaturesCompatibility` |
| Feature flags read/write | `Core\FeatureFlags` |
| Capability registration | `Core\Capabilities` |
| Schema version + migration runner | `Core\Versioning\MigrationRunner` |
| Shared value object factories | `Core\Factory` (thin) |
| Logging channels | `Infrastructure\Logging` |
| Error boundaries / admin notices | `Core\ErrorBoundary` |
| Config cache invalidation hooks | `Infrastructure\Cache\CacheInvalidator` |
| Health check aggregator | `Core\Health\HealthCheckRegistry` |
| Safe deactivation (flush rewrite, no data delete) | `Bootstrap\Deactivator` |

Core **must not** contain fulfilment business rules or theme-specific rendering.

---

## 10. Product Rules module

**Purpose:** Determine what a customer may select for a product/variation.

**Stores:** `delivery_engine_product_rules` (+ lightweight WC product meta pointer ` _cetech_de_rule_id` optional).

**Key fields:** `product_id`, `variation_id` (nullable), `fulfilment_availability`, `logistics_profile_id`, `supplier_id`, `origin_id`, `store_pickup_allowed`, `pickup_location_id`, `eligible_offer_ids` (JSON), `default_offer_id`, overrides, `version`, `active`.

**Services:**

- `ProductRuleResolver` — merge inheritance chain (see §39).
- `ProductRuleValidator` — admin warnings for incomplete rules.

**Hooks (Presentation):** product edit panel, bulk editor, import target.

**V1 scope:** Product + variation rules required. Category/default rules **optional behind feature flag** (see Decision C).

---

## 11. Delivery Offers module

**Purpose:** Reusable customer-selectable delivery services.

**Stores:** `delivery_engine_delivery_offers`.

**Key concepts:** route, service level, carrier visibility, time ranges, eligibility (fulfilment types, logistics profiles, zones), display priority, tax class, enabled flag, stable `internal_code`.

**Services:**

- `DeliveryOfferRepository`
- `DeliveryOfferValidator` — ensure international products have Air/Sea offers when configured

Offers are **reused** across products; product rules reference offer IDs.

---

## 12. Destination Zones module

**Purpose:** Geographic eligibility and pricing context (not price itself).

**Stores:** `delivery_engine_destination_zones`, `delivery_engine_destination_rules`.

**Matcher:** `DestinationZoneMatcher` — postcode → city → region → country → site fallback zone.

**Admin:** zone test tool (product + address → matched zone).

If no zone matches: use configured fallback zone **or** treat as uncovered (block checkout for affected offers).

---

## 13. Logistics Profiles module

**Purpose:** Internal transport/handling classification.

**Stores:** `delivery_engine_logistics_profiles`.

**Not** WooCommerce Shipping Classes. Customer does not see profile name by default.

Used by: offer eligibility, rate-card matching, consolidation compatibility, route restrictions.

---

## 14. Supplier and Origin Registry module

**Purpose:** Private operational sources.

**Stores:** `delivery_engine_suppliers`, `delivery_engine_origins`.

**Access:** capabilities `manage_private_sources`, `view_private_origins`.

Soft-delete (inactive) only; never hard-delete referenced records.

Used internally for: dispatch timing, grouping, internal cost, staff workflow. **Never** exposed in customer DTOs.

---

## 15. Rate Cards and Consolidation module

**Purpose:** Manual customer-facing price resolution and shipment grouping keys.

**Stores:** `delivery_engine_rate_cards`, `delivery_engine_rate_card_rules`.

**V1 charge types (implement first):**

| Type | Code |
|------|------|
| Fixed per shipment | `fixed_per_shipment` |
| Fixed per item | `fixed_per_item` |

**Deferred (schema may reserve, UI hidden V1):** weight bands, volumetric, base+increment, free threshold, highest-item fee.

**Services:**

- `RateCardResolver` — match offer + zone + profile + supplier/origin + overrides + priority + effective dates
- `RateCalculator` — returns `RateQuote` or `RateNotFound`
- `ConsolidationKeyBuilder` — builds grouping key for package builder

**Decision B:** `RateNotFound` → never price = 0; triggers validation failure (see §48).

---

## 16. Delivery-Date Estimator module

**Purpose:** Customer-facing “Estimated delivery to your address”.

**Inputs:** payment/reference date, processing/transit/final-mile ranges, buffers, business-day calendar, zone adjustments.

**Output:** `DeliveryEstimate` immutable value object; snapshot at checkout.

**Services:** `EstimateCalculator`, `BusinessDayCalendar`.

Same-day offers may use hour-range manual text instead of full business-day stack.

---

## 17. Cart and Checkout Adapter

**Purpose:** Persist and validate cart-line delivery selections; classic checkout integration.

**WooCommerce hooks:**

| Hook | Use |
|------|-----|
| `woocommerce_add_to_cart_validation` | Block invalid selections |
| `woocommerce_add_cart_item_data` | Attach normalized `DeliverySelection` |
| `woocommerce_get_cart_item_from_session` | Restore + revalidate |
| `woocommerce_cart_item_key` | Fingerprint uniqueness |
| `woocommerce_get_item_data` | Customer-safe line display |
| `woocommerce_checkout_create_order_line_item` | Order item meta snapshot |
| `woocommerce_checkout_process` | Final server validation |

**Server authority:** browser sends `offer_id` + fulfilment choice; never authoritative price.

**Revalidation triggers:** variation, qty, destination, cart change, coupon, stock, config change.

**Silent replacement:** forbidden — invalidate line and show notice.

**Current baseline:** Classic and Cart/Checkout Blocks are both supported surfaces using shared server-authoritative Delivery Engine rules. Do not maintain a Classic-only business-logic fork.

---

## 18. WooCommerce Shipping Adapter

**Purpose:** Convert cart selections → WC shipping packages → rates via custom method.

**Components:**

- `ShippingPackageBuilder` — `woocommerce_cart_shipping_packages`
- `ManagedPackageMarker` — flags packages owned by engine
- `ShippingMethodRegistrar` — registers single custom method
- `ExclusiveRateFilter` — removes conflicting methods on managed packages (Decision A)

Non-managed cart lines (products without engine rules or flag off) pass through unchanged — native WC methods may apply.

---

## 19. Shipment Management module

**Purpose:** Post-order operational fulfilment units.

**Stores:** `delivery_engine_shipments`, `delivery_engine_shipment_items`, `delivery_engine_shipment_events`.

**Services:**

- `ShipmentPlanner` — order items → planned groups (same logic as cart consolidation)
- `ShipmentService::createFromOrder()` — idempotent creation
- `ShipmentStatusService` — transitions + audit

**Statuses (V1):** `awaiting_fulfilment`, `processing`, `dispatched`, `in_transit`, `delayed`, `delivered`, `cancelled`.

Does **not** auto-complete parent WC order on single shipment delivered.

---

## 20. Tracking module

**Purpose:** Manual tracking storage and customer-safe display.

**V1:** staff-entered carrier name, number, URL, dispatch date, public note.

**Excluded V1:** carrier API sync, automatic polling, webhooks.

Validate URLs; hide empty track links.

---

## 21. Customer Account Adapter

**Purpose:** Order confirmation + My Account shipment timeline.

**Locations:**

- Order details template / hook `woocommerce_order_details_after_order_table`
- Optional endpoint `my-account/deliveries` (feature flag)

**DTO:** `CustomerShipmentView` — only safe fields (see §55).

Emails: extend WC emails via hooks with safe shipment summary blocks — no supplier/origin.

---

## 22. Admin Console

**Menu:** top-level **Delivery Engine** with capability-gated submenus (see §53).

**Pattern:** list tables + editors + validation warnings + “test quote” tools.

**Not in V1:** heavy analytics, carrier performance dashboards.

Onboarding wizard: optional, non-blocking.

---

## 23. Audit and Diagnostics

**Store:** `delivery_engine_audit_log`.

**Log:** configuration changes, status changes, tracking updates, failed shipment creation, rate-card edits.

**Diagnostics page:** health checks, integration status, queue failures, missing rate cards, incomplete rules.

Checkout-critical errors → admin diagnostic + customer-safe message.

---

## 24. Import/Export module

**Purpose:** Bulk product rule assignment and configuration portability.

**V1:** CSV import for product rules (product SKU/ID, fulfilment availability, profile, offers, overrides).

**Feature flag:** `enable_bulk_import` default **off** until validated.

Export: product rules + offer/zone references for staging → production migration (IDs remapped by code).

**Future:** full config export bundle.

---

## 25. Integration Registry

```text
IntegrationRegistry
  ├── detect(): void
  ├── get( string $key ): IntegrationInterface
  └── register( IntegrationInterface ): void

IntegrationInterface
  ├── isAvailable(): bool
  ├── register(): void
  └── getKey(): string
```

Detection examples:

| Key | `isAvailable()` condition |
|-----|---------------------------|
| `wpml` | `defined('ICL_SITEPRESS_VERSION')` |
| `wcml` | WCML class/function exists |
| `woodmart` | theme name / `woodmart` constant |
| `wcfm` | WCFM plugin active |
| `vitepos` | VitePOS plugin active |
| `blocks` | flag on AND block checkout detected |

Container binds:

- `TranslationAdapterInterface` → `WpmlAdapter` or `NullTranslationAdapter`
- `CurrencyAdapterInterface` → `WcmlAdapter` or `NullCurrencyAdapter`
- `ThemePresentationAdapterInterface` → `WoodMartAdapter` or `NullWoodMartAdapter`

---

## 26. Optional WoodMart adapter

**When:** WoodMart detected AND `enable_woodmart_adapter` flag on.

**Does:**

- Add WoodMart-compatible CSS classes to delivery selector markup
- Re-bind JS on `woodmart-theme` variation/swatches/quick-view events (tested list)
- Mini-cart line delivery summary styling

**Does not:**

- Edit parent theme templates
- Depend on `variable.php`
- Change business rules

**Staging requirement:** validate outdated WoodMart variable template before enabling selector on variable products.

---

## 27. Optional WPML adapter

**When:** WPML detected AND `enable_wpml_adapter` flag on.

**Does:**

- Register strings (Delivery Only, routes, statuses, etc.)
- `wpml-config.xml` for product rule meta copy behaviour
- Copy operational IDs to translations; translate customer labels only

**Does not:** require WPML for activation.

---

## 28. Optional WCML adapter

**When:** WCML detected AND `enable_wcml_adapter` flag on.

**Does:**

- Convert display/checkout amounts via WCML
- Support manual per-currency overrides on rate cards
- Produce `CurrencySnapshot` at checkout (base + charged + rate context)

**Does not:** use currency for route eligibility (address decides eligibility).

---

## 29. Optional WCFM adapter

**V1 status:** **Interface + Null adapter + stub module directory.** No vendor UI in first code milestone unless explicitly scheduled.

**Default:** vendors denied all delivery-engine caps.

**Future optional caps:** `view_own_product_delivery_rules`, `edit_own_product_delivery_rules`, `view_own_shipments`, `update_own_shipment_status` — never global rate cards or private sources by default.

---

## 30. Optional VitePOS adapter

**V1 status:** **Interface + Null adapter + stub.** Implementation when POS plugin present and flag on.

**Contract:**

- POS order stores normalized `DeliverySelection`
- Delivery lines create shipment records; pickup lines do not
- Staff sees offer + charge; no bypass of rate calculator

Separate QA from online product-page flow.

---

## 31. Future WooCommerce Blocks adapter

**Status:** **Future-only for V1.** Documented here; not loaded in default build.

**When built:**

- Extend Store API with safe cart item extensions
- Server-authoritative checkout updates
- Do not claim Blocks support until E2E matrix passes

Classic checkout release must not depend on Blocks adapter.

---

## 32. Domain entities

| Entity | Description |
|--------|-------------|
| `DeliveryOffer` | Reusable offer aggregate |
| `ProductFulfilmentRule` | Product-level rule |
| `VariationFulfilmentOverride` | Partial override on variation |
| `DestinationZone` | Zone aggregate with rules |
| `DestinationMatch` | Result of zone matcher |
| `LogisticsProfile` | Internal profile |
| `Supplier` | Private supplier |
| `Origin` | Private origin |
| `RateCard` | Pricing rule aggregate |
| `RateQuote` | Resolved price for one selection |
| `DeliveryEstimate` | Resolved estimate |
| `DeliverySelection` | Buyer choice on a line |
| `ShipmentPlan` | Planned groups pre-persistence |
| `Shipment` | Persisted fulfilment unit |
| `ShipmentItem` | Line(s) in shipment |
| `ShipmentEvent` | Timeline event |
| `PickupLocation` | Store pickup location |
| `CurrencySnapshot` | Frozen checkout money context |

Entities live in `Domain/`; no WordPress calls inside entity methods.

---

## 33. Value objects

| Value object | Role |
|--------------|------|
| `Money` | Amount + currency, immutable |
| `CurrencyCode` | ISO code wrapper |
| `DateRange` / `DurationRange` / `BusinessDayRange` | Time windows |
| `AddressSnapshot` | Frozen checkout address |
| `DestinationContext` | Resolved zone + address hash version |
| `FulfilmentAvailability` | Enum |
| `FulfilmentChoice` | Enum |
| `DeliveryRoute` | Enum |
| `ServiceLevel` | String code VO |
| `CarrierVisibility` | Enum |
| `ShipmentStatus` | Enum |
| `ConsolidationKey` | Hashable grouping key |
| `RateCalculationResult` | Quote or failure reason |
| `EstimateCalculationResult` | Estimate or failure |
| `DeliverySelectionFingerprint` | Cart uniqueness input |

All value objects immutable; equality by value.

---

## 34. Internal enums/constants

### Decision F — PHP enum strategy

**Minimum PHP: 8.3** — WordPress and WooCommerce recommended floor as of 21 September 2026; native backed enums and `readonly` properties remain available. **CETECH production / currently qualified latest stable PHP is 8.5.x**; see `docs/PHP-RUNTIME-POLICY.md`. PHP 8.1 and 8.2 are not supported.

Use **native backed enums** in `Domain\Enum\`:

```php
enum FulfilmentAvailability: string {
    case InternationalFulfilment = 'international_fulfilment';
    case InStore = 'in_store';
    case InWarehouse = 'in_warehouse';
}
```

Same pattern for `FulfilmentChoice`, `DeliveryRoute`, `CarrierVisibility`, `ShipmentStatus`, `RateCardChargeType`, `ManagedPackageMode`.

**Constants** (not enums): plugin version, DB schema version, feature-flag keys, hook priorities, cache key prefixes.

Never persist translated labels as enum values.

---

## 35. Application service interfaces

| Interface | Responsibility |
|-----------|----------------|
| `ProductRuleResolverInterface` | Resolve effective rule for product/variation |
| `DeliveryEligibilityResolverInterface` | Fulfilment choices allowed |
| `DeliveryOfferResolverInterface` | Eligible offers for context |
| `DestinationZoneMatcherInterface` | Address → `DestinationMatch` |
| `RateCalculatorInterface` | Selection → `RateQuote` or not found |
| `EstimateCalculatorInterface` | Selection → `DeliveryEstimate` |
| `DeliverySelectionValidatorInterface` | Validate selection at ATC/cart/checkout |
| `ShippingPackageBuilderInterface` | `WC_Cart` → packages array |
| `ShipmentPlannerInterface` | Order context → `ShipmentPlan` |
| `ShipmentServiceInterface` | Create/update shipments |
| `OrderSnapshotServiceInterface` | Write immutable snapshots to order |
| `TranslationAdapterInterface` | Translate presentation strings |
| `CurrencyAdapterInterface` | Convert money for display/checkout |
| `ThemePresentationAdapterInterface` | Theme-specific markup classes |
| `CacheStoreInterface` | Get/set/invalidate config cache |

All implementations injected via `ServiceContainer`.

---

## 36. Repository/storage layer

| Repository | Table(s) | Notes |
|------------|----------|-------|
| `DeliveryOfferRepository` | `delivery_offers` | |
| `DestinationZoneRepository` | `destination_zones`, `destination_rules` | |
| `LogisticsProfileRepository` | `logistics_profiles` | |
| `SupplierRepository` | `suppliers` | admin-only |
| `OriginRepository` | `origins` | admin-only |
| `RateCardRepository` | `rate_cards`, `rate_card_rules` | |
| `ProductRuleRepository` | `product_rules` | |
| `ShipmentRepository` | `shipments`, `shipment_items`, `shipment_events` | |
| `AuditLogRepository` | `audit_log` | append-only |
| `PickupLocationRepository` | `pickup_locations` | V1 table |
| `FeatureFlagRepository` | `wp_options` | `cetech_de_*` keys |

**WooCommerce gateways:**

- `WcOrderGateway` — CRUD only for orders
- `WcOrderItemMetaGateway` — customer-safe meta
- `WcSessionGateway` — cart session read/write

No repository returns supplier/origin fields on `CustomerSafe*` DTO paths.

---

## 37. Custom database table plan

Prefix: `{$wpdb->prefix}delivery_engine_*`

| Table | Primary purpose |
|-------|-----------------|
| `delivery_engine_delivery_offers` | Offer definitions |
| `delivery_engine_destination_zones` | Zone headers |
| `delivery_engine_destination_rules` | Zone match rules |
| `delivery_engine_logistics_profiles` | Profiles |
| `delivery_engine_suppliers` | Private suppliers |
| `delivery_engine_origins` | Private origins |
| `delivery_engine_pickup_locations` | Pickup locations |
| `delivery_engine_rate_cards` | Rate card headers |
| `delivery_engine_rate_card_rules` | Charge rule rows |
| `delivery_engine_product_rules` | Product/variation rules |
| `delivery_engine_shipments` | Shipments |
| `delivery_engine_shipment_items` | Shipment line links |
| `delivery_engine_shipment_events` | Status/tracking events |
| `delivery_engine_audit_log` | Audit trail |

**Indexes (minimum):** `product_id`, `variation_id`, `order_id`, `shipment_id`, `status`, `delivery_offer_id`, `destination_zone_id`, `created_at`.

**Unique constraints:** `internal_code` per entity type per site; one shipment number per site; shipment item uniqueness per shipment + order_item_id.

**Integrity:** foreign-key-like validation in PHP; soft-delete suppliers/origins/offers.

---

## 38. WooCommerce data ownership boundaries

| Data | Owner | Storage |
|------|-------|---------|
| Product catalogue | WooCommerce | `wp_posts` / product meta |
| Cart session | WooCommerce | session |
| Shipping lines | WooCommerce | order shipping items |
| Order totals / payment | WooCommerce | order |
| Fulfilment rules | Engine | custom tables |
| Offers, zones, rate cards | Engine | custom tables |
| Suppliers/origins | Engine | custom tables |
| Shipments | Engine | custom tables |
| Customer-safe delivery label on line | Engine → WC | order item meta |
| Private shipment cost | Engine | custom tables / protected meta |

Engine **reads** WC product/stock; **writes** shipping packages and one custom method rate; **writes** order item meta snapshots; **never** writes directly to HPOS tables via raw SQL.

---

## 39. Product-rule inheritance model

**Precedence (deterministic):**

```text
1. Variation override fields (if variation_id set and field present)
2. Parent product rule
3. Category rule (if feature flag enable_category_rules)
4. Site default rule (if feature flag enable_site_fallback_rule)
5. Unconfigured → invalid
```

**Merge:** variation overrides only specified fields; inherit remainder from parent.

**Decision C:** Steps 3–4 **optional in V1**. Default flags **off**. Unconfigured product → admin warning + Add to Cart blocked when selector enabled for that product.

---

## 40. Delivery-offer resolution model

**Algorithm:**

```text
1. Resolve effective ProductFulfilmentRule
2. Build DestinationContext from address / default country
3. Filter offers by:
   - enabled flag
   - fulfilment_availability match
   - logistics_profile eligibility
   - product eligible_offer_ids
   - variation exclusions
   - destination zone eligibility
   - route restrictions (e.g. no Air on variation)
   - supplier/origin internal constraints (not exposed)
4. Sort by display_priority
5. Determine default_offer (rule default or offer flag)
6. Return EligibleOffersResult { offers[], default_id, requires_choice }
```

If zero offers: block ATC with customer-safe message; log admin diagnostic.

If one offer: auto-select; no redundant radio UI.

---

## 41. Destination-zone resolution model

**Match order (most specific wins):**

```text
postcode exact / pattern
→ city rule
→ region/state rule
→ country rule
→ site_fallback_zone (if configured)
→ UNMATCHED
```

**Output:** `DestinationMatch { zone_id, zone_code, specificity_score, remote_area_flag }`

**Tie-break:** higher `priority` field on zone; then higher specificity.

**UNMATCHED behaviour:** rate lookup fails → Decision B failure path; do not guess nearest zone unless admin configures explicit fallback mapping.

---

## 42. Rate-card calculation model

**Lookup key:**

```text
delivery_offer_id
+ destination_zone_id
+ logistics_profile_id
+ supplier_id (nullable)
+ origin_id (nullable)
+ effective_date window
+ priority (highest wins)
```

**V1 calculators:**

| `charge_type` | Formula |
|---------------|---------|
| `fixed_per_shipment` | `base_amount` per package group |
| `fixed_per_item` | `base_amount * quantity` per line (summed per group) |

Apply product `price_override` if present (fixed override or disallow).

**WCML:** resolve in base currency first → convert via `CurrencyAdapter` → snapshot both.

**Failure:** `RateCalculationResult::notFound(reason)` — **never** `amount = 0` unless offer is genuinely free (e.g. Store Pickup) by fulfilment choice, not missing config.

---

## 43. Delivery-estimate model

**Reference time:** `payment_confirmed_at` at checkout; preview uses `now()` on product/cart.

**Calculation:**

```text
start = reference_time
+ processing_min/max (offer or overrides)
+ transit_min/max
+ final_mile_min/max (if applicable)
+ customs_buffer + zone_buffer
→ business-day calendar adjustment
→ DeliveryEstimate { processing_label, transit_label, doorstep_range }
```

Snapshot full estimate object on order line and shipment record.

Historical estimates immutable after payment.

---

## 44. Cart-line delivery-selection model

**Normalized `DeliverySelection` stored in cart item data** under key `_cetech_de_selection` (array serialized):

| Field | Purpose |
|-------|---------|
| `fulfilment_availability` | enum string |
| `fulfilment_choice` | delivery \| store_pickup |
| `delivery_offer_id` | int |
| `delivery_route` | enum string |
| `service_level` | code |
| `carrier_visibility` | enum |
| `destination_context_hash` | zone + address version |
| `price_snapshot` | Money array |
| `estimate_snapshot` | serialized estimate |
| `rule_version` | int |
| `offer_code` | stable code copy |

Validated on write; refreshed on revalidation (price/estimate may update; offer_id must not change silently).

---

## 45. Cart-item uniqueness/fingerprint strategy

**Filter:** `woocommerce_cart_item_key`

**Fingerprint hash inputs (ordered, stable):**

```text
product_id
variation_id
fulfilment_choice
delivery_offer_id
destination_context_hash
rule_version
```

**Exclude:** translated labels, formatted prices, supplier/origin IDs.

**Result:** `Product A + Sea` and `Product A + Air` → different keys guaranteed.

---

## 46. Shipping-package grouping strategy

**Builder algorithm:**

```text
foreach cart item with active delivery selection:
  if store_pickup: assign to pickup group (no shipping package)
  else:
    consolidation_key = hash(
      supplier_id, origin_id, route, service_level,
      carrier_rule, destination_zone_id, dispatch_window,
      logistics_profile_id, separate_shipment_flag
    )
    add to group[consolidation_key]

foreach group requiring delivery:
  create WC package {
    contents: [ cart item keys ],
    destination: WC customer shipping destination,
    meta: { managed: true, offer_id, consolidation_key, private... }
  }
```

**Never merge:** Air+Sea, pickup+delivery, different offers on same line (already separate lines), incompatible profiles.

**Default:** one package per consolidation group.

---

## 47. Custom WooCommerce shipping method design

| Property | Value |
|----------|-------|
| Method ID | `delivery_engine_selected_offer` |
| Admin title | Delivery Engine Selected Offer |
| Customer title | From selected offer public label (e.g. “Sea Shipping”) |
| Supports | `shipping-zones` (registered globally or via zone) |

**`calculate_shipping( $package )`:**

1. If `! $package['cetech_de_managed']` → return (other methods handle)
2. Read `delivery_offer_id` + precomputed `rate_amount` from package meta (calculated earlier by `RateCalculator` during package build)
3. Emit **single** `WC_Shipping_Rate` with cost, label, meta for estimate text
4. Do not emit zero-cost Air/Sea placeholders

**Decision A — Managed package mode (setting `managed_package_mode`):**

| Mode | Behaviour |
|------|-----------|
| `exclusive` (**default**) | Remove flat_rate, free_shipping, legacy methods from managed packages |
| `coexist` | Show engine rate alongside native methods (debug only) |
| `fallback_native` | On `RateNotFound`, allow native methods (still log error; **not** default) |

Native WC rates remain available for **non-managed** packages (products without engine rules or selector disabled).

---

## 48. Checkout validation strategy

**Stages:**

| Stage | Checks |
|-------|--------|
| Add to Cart | rule exists; choice complete; offer eligible; rate exists for preview destination |
| Cart update | revalidate all lines; notices on invalid |
| Checkout process | full address; rate recalc; all lines valid |
| Order create | snapshot written; totals match |

**Decision B — Missing rate card:**

| Surface | Behaviour |
|---------|-----------|
| Product page (if zone known) | Block ATC; message: “Delivery is not available for this product to your location.” |
| Cart (destination changed) | Mark line invalid; cart notice; block proceed to checkout |
| Checkout | `wc_add_notice` error; block order placement |
| Admin | Diagnostic log + dashboard alert “Offer X missing rate for zone Y” |
| Shipping calc | **No rate emitted**; never `0.00` fallback |

Staff-facing product config incomplete → admin warning even before customer hit.

---

## 49. Order snapshot strategy

**On `woocommerce_checkout_create_order` / line item hooks:**

Write to **order item meta** (customer-safe):

- `_cetech_fulfilment_choice`
- `_cetech_delivery_service_label`
- `_cetech_estimated_delivery`
- `_cetech_pickup_location` (if pickup)

Write to **private store** (custom table or protected meta keys prefixed `_cetech_de_private_`):

- supplier_id, origin_id, internal cost, consolidation_key, offer_id, rate_card_id, rule_version

Write **order-level** shipping snapshot via WC shipping line items (amounts authoritative in WC).

**`CurrencySnapshot` on order meta** when WCML active.

**Immutability:** snapshot services refuse updates after `order.is_paid()`. Filters that recalculate shipping on admin order edit must not touch locked snapshots without explicit staff override workflow (out of V1 scope — block by default).

---

## 50. Shipment creation strategy

### Decision G — Timing

**Default:** create shipments on `woocommerce_order_status_processing` or `payment_complete` (paid state), configurable:

| Setting `shipment_creation_trigger` | When |
|-------------------------------------|------|
| `payment_confirmed` (**default**) | `woocommerce_payment_complete` or status → processing |
| `order_created` | immediately after checkout order created (use only if site policy requires) |

**Idempotency key:** `order_id + order_item_id + consolidation_key` — if shipment exists, skip create.

**Flow:**

```text
trigger
→ load order + line item delivery snapshots
→ ShipmentPlanner.plan()
→ foreach planned group: ShipmentService.createIfNotExists()
→ initial status awaiting_fulfilment
→ audit event
```

**Failure:** log error, admin alert “paid order shipment creation failed”, order note for staff (no private data), do not lose delivery snapshots on order items.

Store Pickup lines: optional pickup fulfilment record or zero delivery shipments — no delivery shipping shipment.

---

## 51. Shipment status/tracking strategy

**Updates via** `ShipmentStatusService::transition()`:

- capability `update_shipment_status`
- validate allowed transition (flexible V1 with required internal note for corrections)
- write `shipment_events` row
- write `audit_log`
- public note sanitized; internal note never in customer templates

**Tracking via** `TrackingService::attach()`:

- validate URL
- customer sees link only when `tracking_url` non-empty and valid

**Excluded V1:** webhooks, carrier API, auto status from carrier.

---

## 52. Customer account display strategy

**Per-shipment card fields:**

| Field | Source |
|-------|--------|
| Shipment number | `shipments.shipment_number` |
| Service label | snapshot public label |
| Items list | product names + qty |
| Status | translated public status |
| Estimated delivery | snapshot range |
| Tracking link | if present |

**Hooks:** classic templates + optional shortcode block.

**Emails:** inject shipment summary after order table — safe fields only.

No supplier, origin, internal cost, margin, private notes, consolidation keys.

---

## 53. Admin menu and screens

| Submenu | Capability (min) | V1 |
|---------|------------------|-----|
| Dashboard | `manage_shipments` or `manage_delivery_settings` | Yes |
| Shipments | `manage_shipments` | Yes |
| Products & Rules | `manage_product_delivery_rules` | Yes |
| Delivery Offers | `manage_delivery_offers` | Yes |
| Rate Cards | `manage_delivery_rate_cards` | Yes |
| Destination Zones | `manage_delivery_zones` | Yes |
| Logistics Profiles | `manage_logistics_profiles` | Yes |
| Suppliers & Origins | `manage_private_sources` | Yes |
| Pickup Locations | `manage_delivery_settings` | Yes |
| Import / Export | `import_delivery_data` | Flag-gated |
| Integrations | `manage_delivery_integrations` | Yes |
| Logs & Diagnostics | `view_delivery_logs` | Yes |
| Settings | `manage_delivery_settings` | Yes |

Product edit: metabox on WC product screen cross-linked to Products & Rules editor.

---

## 54. Roles and capabilities

Capabilities (register on activation):

```text
manage_delivery_settings
manage_delivery_offers
manage_delivery_rate_cards
manage_delivery_zones
manage_logistics_profiles
manage_private_sources
manage_product_delivery_rules
manage_shipments
update_shipment_status
view_private_delivery_costs
view_private_origins
manage_delivery_integrations
view_delivery_logs
import_delivery_data
```

Administrator: all. Shop Manager: most. Logistics Manager: shipments + config. Product Manager: rules + offers. Customer Service: read shipments + public notes. Store Staff: pickup workflow. Vendor: **none** default.

Map to WP roles via activation defaults; customizable by admin plugins.

---

## 55. Supplier/origin privacy protection strategy

### Decision I — Data classification

| Class | Examples | Customer-facing | REST/Store API | Emails | My Account | SEO/schema |
|-------|----------|-----------------|----------------|--------|------------|------------|
| **Public delivery** | Route, service label, price, estimate, tracking URL, public status, pickup location name | Yes | Yes (sanitized) | Yes | Yes | Optional product-level fulfilment label only |
| **Operational private** | supplier_id, origin_id, supplier name, origin address, internal cost, margin, consolidation_key, rate_card_id, internal notes | **Never** | **Never** | **Never** | **Never** | **Never** |
| **Staff-only** | dispatch instructions, carrier cost, audit trail | Admin with cap | Admin REST only with cap | Never | Never | Never |

**Enforcement layers:**

1. **Domain:** no supplier/origin on `CustomerShipmentView`, `FrontendOfferDto`
2. **Application:** separate mappers `toCustomerDto()` vs `toAdminDto()`
3. **Infrastructure:** REST controllers check capabilities; strip private keys
4. **Presentation:** escape output; never `var_dump` package meta to frontend
5. **Logs:** mask supplier codes in customer-accessible log viewers

---

## 56. Cache compatibility strategy

| Cache type | Policy |
|------------|--------|
| Full-page (WP Rocket) | Exclude cart, checkout, my account, AJAX estimate routes |
| Object cache (Redis) | Cache offers/zones/rate cards/profiles by ID; key prefix `cetech_de:` |
| Customer quote | Key must include `session_id + product + variation + offer + dest_hash + currency` |
| Invalidation | On save of offer, zone, rate card, product rule, supplier dispatch rules |

Never serve cached quote across sessions.

Document recommended WP Rocket exclusions in admin Integrations screen.

---

## 57. Security strategy

- Nonces on all admin/AJAX mutations
- Capability checks per action
- `sanitize_*` / `esc_*` on all IO
- Server-side price authority
- Validate `offer_id` belongs to product + destination on every mutation
- Ignore client-submitted `supplier_id` / `price`
- HPOS CRUD only for orders
- Rate limiting on public estimate AJAX (optional V1.1)
- Prepared SQL for custom tables
- No private fields in `register_rest_route` public schema

---

## 58. Logging strategy

**Channels:** `delivery`, `checkout`, `shipment`, `migration`, `integration`

**Context fields:** order_id, shipment_id, product_id, offer_id, zone_id, rate_card_id, correlation_id

**Never log:** card data, passwords, full customer PII, private supplier details in front-end accessible logs

**Levels:** `error` for checkout blockers; `warning` for config gaps; `info` for shipment transitions; `debug` only when `WP_DEBUG` and admin cap

---

## 59. Diagnostics screen strategy

**Sections:**

1. Environment (WC version, HPOS, PHP, checkout type)
2. Integrations (detected vs enabled flags)
3. Configuration health (missing rate cards, incomplete rules)
4. Operational (failed shipment creations, Action Scheduler failures)
5. Cache warnings
6. Tools: “Test zone match”, “Test rate quote”, “Flush config cache”

Each issue links to remedial admin screen.

---

## 60. Feature-flag strategy

### Decision H — Safe defaults (all stored in `wp_options`)

| Flag | Default | Purpose |
|------|---------|---------|
| `enable_product_delivery_selector` | **off** | Product-page UI |
| `enable_shipment_records` | **on** | Backend shipments (low customer impact) |
| `enable_customer_timeline` | **off** | My Account shipment UI |
| `enable_tracking_links` | **on** | Show tracking when staff adds |
| `enable_wpml_adapter` | **on** if WPML detected else off | Auto-detect suggestion only |
| `enable_wcml_adapter` | **on** if WCML detected else off | |
| `enable_woodmart_adapter` | **on** if WoodMart detected else off | |
| `enable_wcfm_adapter` | **off** | |
| `enable_vitepos_adapter` | **off** | |
| `enable_bulk_import` | **off** | |
| `enable_classic_checkout_adapter` | **on** | Required for V1 |
| `enable_blocks_adapter` | **off** | Future |
| `enable_category_rules` | **off** | Decision C |
| `enable_site_fallback_rule` | **off** | Decision C |
| `demo_data_on_activation` | **off** | |

Per-product enablement: product rule `active` + global selector flag + optional allowlist mode during pilot (`pilot_product_ids`).

---

## 61. Migration/upgrade strategy

- Schema version option: `cetech_de_db_version` (current target: `6`; earlier schema-3 scoped-configuration history is documented in `docs/STAGE-2-SCOPED-CONFIGURATION-STORAGE.md`)
- Migrations in `database/migrations/` named `YYYYMMDDHHMMSS_description.php`
- Each migration: `up()`, `idempotent check`, logged result
- Properties: versioned, non-destructive default, batch-capable for large tables, no long locks during peak checkout
- Run on `admin_init` or async Action Scheduler job
- Forward-only; rollback notes documented per release

---

## 62. Rollback/uninstall strategy

**Deactivation:** flush caches, no data delete.

**Rollback (operational):** disable `enable_product_delivery_selector` → native shipping resumes; retain shipment data.

**Uninstall:** only when `cetech_de_delete_data_on_uninstall` explicitly true (default **false**); then drop tables and options per `uninstall.php`.

Never delete shipment history on routine deactivation.

---

## 63. Testing strategy

| Layer | Scope |
|-------|-------|
| Unit | Resolvers, fingerprint, consolidation key, enum rules, DTO redaction |
| Integration | Repositories, migrations |
| WooCommerce | Cart keys, packages, shipping method, order snapshots, HPOS |
| Adapter | WPML/WCML when extension loaded |
| E2E | Classic checkout pilot flows, WoodMart variable product |

**Mandatory scenarios:** separate Air/Sea lines, missing rate card blocks checkout, no supplier in HTML, immutable snapshot after paid, idempotent shipment create, exclusive managed packages hide flat rate.

CI target: PHP **8.5** CETECH production (blocking), PHP **8.3** minimum supported (blocking), and PHP **8.4** compatibility. PHP 8.1 and 8.2 are not supported. PHP 8.5 is not a permanent architectural maximum; a later stable PHP line is added only after qualification. See `docs/PHP-RUNTIME-POLICY.md`.

---

## 64. Known risks and open decisions

| Risk | Mitigation |
|------|------------|
| WoodMart outdated `variable.php` | Staging validation; hooks-first; child-theme fix only if required |
| Coexistence with legacy shipping plugins | Exclusive managed packages; document disable legacy for pilot products |
| HPOS plugin compatibility | Declare compatibility early; integration tests |
| WCML snapshot complexity | `CurrencySnapshot` value object + explicit tests |
| Partially Shipped WC order status | V1: optional custom status behind flag; default keep `processing` |
| Partial quantity split across shipments | **Future** — V1 keeps full line quantity in one shipment group |
| REST API exposure surface | Ship admin-only routes first; public routes redacted |
| Performance on large carts | Package builder O(n); cache config not quotes |

**Open (low urgency):**

- Exact email template design
- Whether pickup lines create `Shipment` rows with type `pickup` or separate entity (recommend: shipment with route `store_pickup` for unified timeline)
- Custom order status `partially-shipped` registration

---

## 65. Phase-by-phase implementation roadmap

| Phase | Name | Deliverables | Code? |
|-------|------|--------------|-------|
| **0A** | Project Rule Locking | `PROJECT-RULES.md` | Done |
| **0B** | Architecture Plan | `ARCHITECTURE-PLAN.md` | Done |
| **1** | Core foundation | Bootstrap, container, migrations shell, capabilities, flags, HPOS declare, health checks | Yes |
| **2** | Configuration domain | Tables + admin CRUD for zones, profiles, suppliers, origins, offers, rate cards, pickup locations | Yes |
| **3** | Product rules | Rule resolver, inheritance, product admin, bulk import (flag) | Yes |
| **4** | Frontend selection | Product-page selector (flag), ATC validation, cart display | Yes |
| **5** | Cart/checkout shipping | Fingerprint, packages, custom shipping method, checkout validation | Yes |
| **6** | Orders & shipments | Snapshots, shipment creation, staff workspace | Yes |
| **7** | Customer timeline | My Account + emails (flag) | Yes |
| **8** | Integrations | WPML, WCML, WoodMart adapters | Yes |
| **9** | Stubs & future | WCFM/VitePOS/Blocks contracts, carrier gateway interface | Partial |
| **10** | Pilot & hardening | E2E, staging pilot 10–25 products, production rollout | Yes |

**Explicit exclusions throughout Phases 1–10:** POD, OTP, QR, GPS, drivers, live quotes, carrier dispatch, auto tracking sync, auto order completion.

---

## Architecture decisions log (A–I)

| ID | Decision |
|----|----------|
| **A** | Managed packages default **`exclusive`**. Native WC rates remain for non-managed packages. Setting allows `coexist` / `fallback_native`. |
| **B** | Missing rate card → **never zero shipping**. Block ATC/checkout, customer-safe message, admin diagnostic. |
| **C** | Category/site fallback rules **optional**; flags default **off** in V1. Product + variation rules first. |
| **D** | **Classic and Blocks are current checkout surfaces.** Shared server-side business rules and snapshot semantics must remain aligned; neither surface may become a separate pricing authority. |
| **E** | WCFM/VitePOS: **adapter interfaces + Null + stub**; not required for V1 release. |
| **F** | **PHP 8.3** minimum supported; **PHP 8.5.x** currently qualified latest stable / CETECH production; use **backed enums** for domain statuses/routes. PHP 8.1 and 8.2 are not supported. |
| **G** | Shipment creation default **`payment_confirmed`**; idempotent; configurable to `order_created`. |
| **H** | Feature flags default **off** for customer takeover (selector, timeline); integrations auto-suggest but WCFM/VitePOS/Blocks off. |
| **I** | Privacy table §55 — strict DTO separation; private data never in customer surfaces. |

---

*Phase 0B complete. No plugin code created.*
