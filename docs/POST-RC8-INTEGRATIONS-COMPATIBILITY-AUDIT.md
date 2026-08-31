# POST-RC.8 — Integrations & Checkout Compatibility Audit

**Document status:** Audit only. No implementation. No package.  
**Protected baseline:** tagged `1.0.0-rc.8` / schema `5` / `v1.0.0-rc.8` — **immutable; do not retag**  
**Audit branch / worktree:** `feat/post-rc8-integrations` from `v1.0.0-rc.8` (`6d166227998d4b0f5047fea91944ff024b389810`)  
**Date:** 2026-08-31 (Settings / feature-flag honesty addendum same day)  
**FLAIROC:** not modified  
**RC.9 / schema 6 / Stage 15 / per-item locations / Return-Refund / carrier APIs / Bulk:** out of scope  

This audit answers whether Settings checkboxes currently mean working compatibility. They do not.

The **Experimental and future features** panel still uses stale RC.3–RC.5 classifications. Site-wide Defaults and variable ECR are the accepted RC.8 production path. Bulk Tools are shipped and owner-qualified. Several checkboxes are dead. See **§15**.

---

## 1. Verdict

**None of the six Settings switches is evidence of a working optional integration.**

| Integration | Runtime qualification |
|-------------|------------------------|
| WooCommerce Cart & Checkout Blocks | **NOT IMPLEMENTED** |
| WPML | **STUB ONLY** — physical WPML-present testing **BLOCKED BY TEST ENVIRONMENT** on training |
| WCML / multicurrency | **STUB ONLY** — physical WCML-present testing **BLOCKED BY TEST ENVIRONMENT** on training |
| WoodMart dedicated adapter | **STUB ONLY** |
| WoodMart via generic WooCommerce hooks (Classic) | **PARTIAL / WORKING** for the RC.8-proven Classic path; remaining WoodMart-only surfaces are **not proven** |
| WCFM Marketplace | **STUB ONLY** — physical WCFM-present testing **BLOCKED BY TEST ENVIRONMENT** on training |
| VitePOS | **STUB ONLY** — plugin **is present** on training; POS behaviour is **not proven** |

Do not label any of these “supported.”

**Settings honesty (Experimental panel):** Site-wide Defaults at checkout and for variations are **supported production behaviour**, not experimental deployment switches. Bulk Tools are **implemented** and must not be labelled unavailable. Category-rule and site-fallback flags are **dead**. Demo data has **no seeder**. Customer timeline remains **future/unavailable**. See **§15**.

Architecture (`docs/ARCHITECTURE-PLAN.md` §§26–31) and Stage 1 (`docs/POST-RC-ARCHITECTURE-GAP-ANALYSIS.md` §17) still describe real WPML / WCML / WoodMart / WCFM / VitePOS adapters as future work and Blocks as future-only. RC.8 source matches that description. The Settings UI does not.

---

## 2. Method and sources

**Implementation truth:** RC.8 tree at `v1.0.0-rc.8` — `src/Integrations/`, `src/Bootstrap/FeatureFlags.php`, `src/Presentation/Admin/DeliverySettingsPage.php`, cart/checkout/shipping/order classes, frontend JS, tests, `wpml-config.xml` (absent).

**Intended contract:** latest Design and Expectations specification; `docs/DELIVERY-ENGINE-GOVERNING-RULES.md`; `docs/ARCHITECTURE-PLAN.md`; `docs/AI-HANDOFF.md` current-status block.

**Physical evidence already accepted:** Stage 6B / Stage 7 WoodMart decision on FLAIROC; RC.8 owner physical QA of Fulfilment.4 on training.cetechbpa.com (`docs/RC8-FINALIZATION.md`).

**Training environment (this audit, read-only public HTTP, 2026-08-31):** `https://training.cetechbpa.com`. No plugin was installed. No FLAIROC change. No wp-admin writes.

**Current upstream APIs consulted (2026-08-31):**

- WooCommerce developer docs: Cart/Checkout Blocks compatibility declaration; Store API extension (`ExtendSchema` / `woocommerce_blocks_loaded`); checkout/order Store API actions.
- WPML: `wpml-config.xml` custom-field actions (`copy` / `translate` / `copy-once` / `ignore`).
- WCML hooks reference: `wcml_raw_price_amount`, `wcml_price_currency`, `wcml_client_currency`, `wcml_rounded_price`, `wcml_exchange_rates`, `translate_shipping_methods_in_package`.

No fake third-party classes were used as proof.

---

## 3. Compatibility matrix

| Integration | Current implementation | Real dependency detected | Working scope | Missing scope | Risk | Recommended repair |
|-------------|------------------------|--------------------------|---------------|---------------|------|--------------------|
| **WooCommerce Cart & Checkout Blocks** | Flag `enable_blocks_adapter` (default off) + Settings checkbox labelled experimental. Detection key `wc_blocks` = `class_exists('\Automattic\WooCommerce\Blocks\Package')`. No `FeaturesUtil::declare_compatibility('cart_checkout_blocks', …)`. No Blocks Integration, no Store API schema, no Blocks JS, no Store API validation/persistence. Classic hooks only. | Training: Store API `/wc/store/v1` exists (WooCommerce core). Customer Cart = Classic. Customer Checkout = Classic (`/checkout-2/`, `form.checkout.woocommerce-checkout`). | None as a Blocks experience. Classic Cart/Checkout remains the only proven path. Shipping-method registration alone is **not** Blocks support. | Compatibility declaration; dedicated adapter; Store API cart/item/checkout extensions; client SlotFill/Inner Block/filter JS; Store API add-to-cart + selection update; address-driven re-resolution through Store API; Place Order validation via `RouteException`; snapshot persistence on Store API order creation; mixed Delivery/Pickup, Air/Sea, In Warehouse, fail-closed empty DE rates in the Blocks UI. | **P0 for any store that uses Cart/Checkout Blocks.** Classic validation (`woocommerce_after_checkout_validation`) and snapshot (`woocommerce_checkout_create_order_line_item` / `woocommerce_checkout_order_created`) do not run on Store API checkout. Enabling the checkbox changes only `wp_options`. | Build a real Blocks adapter behind the flag; declare compatibility only after Cart **and** Checkout parity; keep Classic untouched. |
| **WPML** | Flag `enable_wpml_adapter`. Detection: `defined('ICL_SITEPRESS_VERSION')`. `NullIntegration` only. No `wpml-config.xml`. No string registration. No translated-product operational copy. No `load_plugin_textdomain()`. Customer labels live in DE tables, not Gettext. | Training: **not installed** (plugin path 404; no WPML REST namespace; `lang="en-US"` only). FLAIROC historically had WPML (Stage 0B) but is not this workstream’s site and must not be modified. No approved WPML package in this repository. | Site works without WPML (required). Detection does not fatal when absent. | Operational copy of fulfilment/logistics/supplier/origin/offer/rate-card IDs onto translated products; translate public labels/descriptions/pickup/status copy only; current-language rendering; wpml-config for any WP meta; no private logistics leakage; version gating. | **P1 when WPML is present.** Translated products are different WooCommerce IDs. ECR/scopes key by product ID, so translations can silently use Site-wide Defaults as a second logistics truth. Ticking the checkbox does nothing. | Implement a real optional WPML adapter. Do not physical-qualify until a licensed WPML stack exists on a designated site. |
| **WCML / multicurrency** | Flag `enable_wcml_adapter`. Detection: `defined('WCML_VERSION') \|\| class_exists('woocommerce_wpml')`. No currency adapter. Quotes match `get_woocommerce_currency()` to rate-card `base_currency` exactly. Snapshot stores one `currency_code` only. Column `manual_currency_override_data` exists in schema 5 and is **unused**. | Training: **not installed** (plugin path 404). FLAIROC historically had WCML 5.5.7 (Stage 0B). No approved WCML package in this repository. | Single-currency stores using WooCommerce base currency (training = GHS). Currency mismatch fail-closes (not free shipping). | WCML conversion of canonical base amounts; manual per-currency DE overrides; WCML rounding; snapshot of base amount/currency + charged amount/currency + conversion context; prevent double conversion; eligibility independent of currency. | **P1 when WCML is present.** If WCML changes `get_woocommerce_currency()` to the display currency, DE quoting fails closed (no matching rate card). If a later naive conversion also rides `woocommerce_package_rates`, amounts can double-convert. Historical orders have no conversion context to freeze. | Quote in canonical base currency; convert only through WCML-supported APIs; persist both sides on the snapshot. Physical test blocked until WCML is available. |
| **WoodMart** | Flag `enable_woodmart_adapter`. Detection: `wp_get_theme()` template/stylesheet `woodmart`. No `WoodMartAdapter`. Frontend JS listens only to standard `found_variation` / `reset_data` / `hide_variation` on `.variations_form`. Selector hook: `woocommerce_before_add_to_cart_button`. No parent-theme edits. | Training: **Woodmart Child 1.0.0 / Woodmart 8.5.7**, `woodmart-ajax-shop-on`. FLAIROC Stage 6B used WoodMart 8.5.7. | RC.8-proven Classic path on WoodMart: simple PDP, variable products using standard WC variation events, classic cart, classic checkout, mixed Delivery/Pickup grouping, International Air auto-select, In Warehouse local Delivery, fail-closed native-rate suppression. Stage 7 conclusion: **no dedicated adapter required** for that path. | Dedicated adapter (intentionally none). Unproven on WoodMart: variation swatches if they drop WC events; Quick View; Buy Now; catalog/AJAX Add to Cart without PDP POST field; mini-cart DE summary; Blocks if WoodMart ever hosts them. Settings copy currently implies an adapter is needed. | **P2.** Checkbox is misleading. Building a duplicate WoodMart layer would violate “prefer standard WooCommerce hooks.” Residual risk is WoodMart-only entry points that skip the PDP selector. | Do **not** create a WoodMart adapter unless a proven WoodMart-only gap appears. Fix Settings/health copy. Qualify remaining WoodMart surfaces on training during Blocks/Classic regression. Never edit the parent theme. |
| **WCFM Marketplace** | Flag `enable_wcfm_adapter`. Detection: `class_exists('WCFM') \|\| defined('WCFM_VERSION')`. No WCFM adapter. Product Delivery tab requires `manage_product_delivery_rules`. Default cap grant is Administrator + Shop Manager only. Access matrix lists **every** existing WP role, including a future `wcfm_vendor`. | Training: **not installed** (plugin path 404; no WCFM assets). FLAIROC historically had WCFM (Stage 0B). No approved WCFM package in this repository. | Core DE works without WCFM. Vendors do not receive DE caps by default. Product panel is capability-gated. | Explicit vendor deny-list / health UI; hide private sources, internal costs, Rate Cards, Delivery Areas from vendor roles even if Access is mis-clicked; no vendor shipment cross-view. Any vendor fulfilment-management UI (not in this workstream). | **P2 privacy.** An administrator can grant DE capabilities to a WCFM vendor role via Settings → Access because the matrix is not filtered. Ticking the WCFM checkbox does not enable or restrict anything. | Keep vendor fulfilment **out of scope**. Optionally harden Access so marketplace vendor roles cannot be granted private/global DE caps. Physical WCFM UI testing blocked until WCFM is available. |
| **VitePOS** | Flag `enable_vitepos_adapter`. Detection: `defined('VITEPOS_VERSION') \|\| class_exists('Vitepos\Apps\Apps')`. No POS adapter. Capture uses classic `$_POST['cetech_de_delivery_option_key']` + WC cart item/session. No POS session isolation. Checkout validation/snapshots hook Classic checkout only. | Training: **present / active enough to register `vitepos/v1`**. Direct `vitepos.php` probe returned HTTP 500 (file exists). Also `wc/pos/v1/catalog`. | Online Classic customer path is independent of VitePOS being installed. Absence does not fatal. | POS Delivery vs Pickup eligibility UI; POS staff cannot bypass hard constraints; persist offer/charge onto the WC order; shipment on Delivery only; no pickup shipping charge/shipment; isolate POS cart/session/localStorage/request from the online shopper cart. | **P1 if POS is used for DE products.** POS orders typically skip Classic checkout hooks, so validation and snapshots may never run. Shared WC session could contaminate online browsing. The Settings checkbox does not isolate POS. | Design a POS adapter that uses WooCommerce order CRUD + DE server contracts, not the PDP UI. Training can host physical POS tests **after** an adapter exists. Do not treat REST namespace presence as compatibility. |
| **Settings → Integrations UI** | Six/five blind checkboxes (WPML, WCML, WoodMart, WCFM, VitePOS) plus Blocks under Advanced → Checkout runtime. Any flag can be enabled without the dependency. Detection is Yes/No on Technical Diagnostics only, without version or health. Enabling adapters increments “advanced mode” count only. | N/A (first-party UI). | Administrators can store flag values. Diagnostics can log detection booleans. | Installed / version / active / enabled / verified / unsupported / inactive / configuration-required states; disable toggle when dependency absent; explain why; safe fallback copy. | **P1 operational.** Owners can believe a ticked box means support. Blocks checkbox even says “Support WooCommerce Blocks checkout” while implementation is absent. | Replace blind checkboxes with dependency-aware health. Do not allow Blocks to declare compatibility in WooCommerce until parity exists. |

---

## 4. Cross-cutting implementation facts

### 4.1 Feature flags

From `src/Bootstrap/FeatureFlags.php` (defaults):

| Flag | Default | Settings location | Runtime `is_enabled()` consumers |
|------|---------|-------------------|----------------------------------|
| `enable_wpml_adapter` | false | Advanced → Integrations | **None** |
| `enable_wcml_adapter` | false | Advanced → Integrations | **None** |
| `enable_woodmart_adapter` | false | Advanced → Integrations | **None** |
| `enable_wcfm_adapter` | false | Advanced → Integrations | **None** |
| `enable_vitepos_adapter` | false | Advanced → Integrations | **None** |
| `enable_blocks_adapter` | false | Advanced → Checkout runtime | **None** |
| `enable_classic_checkout_adapter` | **true** | Advanced → Checkout runtime | Activation chain + setup wizard only — **not** cart/checkout hook registration |

Toggling the six integration/Blocks flags writes `cetech_de_<flag>` and can flip the Settings “advanced mode” summary. It does not register adapters, change quoting, change checkout, or change privacy.

`ClassicCheckoutRuntimeActivation::CHAIN` turns **on** `enable_classic_checkout_adapter` with the real Classic runtime flags. It never touches Blocks. Unchecking Classic support does **not** unregister Classic hooks (`woocommerce_add_to_cart_validation`, `woocommerce_after_checkout_validation`, `woocommerce_cart_shipping_packages`, etc.). Those hooks register from the selector/capture/validation/shipping flags.

The Experimental panel flags (`enable_effective_configuration_runtime`, `enable_variable_product_ecr_runtime`, `enable_bulk_import`, `enable_category_rules`, `enable_site_fallback_rule`, `demo_data_on_activation`, `enable_customer_timeline`) are inventoried in **§15**. Several are stale relative to RC.8.

### 4.2 Integration registry

`src/Integrations/` contains only:

- `IntegrationInterface`
- `NullIntegration` (`isAvailable() = false`, empty `register()`)
- `IntegrationRegistry`

On boot (`Plugin.php`), the registry is constructed with **only** `NullIntegration('null')`. `detect()` logs booleans, then skips Null. **No real adapter is ever `register()`’d.**

Detection map (`get_detection_statuses()`):

| Key | Condition | Version captured? | Inactive plugin? | Incompatible version? |
|-----|-----------|-------------------|------------------|------------------------|
| `wpml` | `defined('ICL_SITEPRESS_VERSION')` | No | Treated as missing (constants not loaded) | Not distinguished |
| `wcml` | `WCML_VERSION` or class `woocommerce_wpml` | No | Treated as missing | Not distinguished |
| `woodmart` | theme template/stylesheet `woodmart` | No | Child of WoodMart still detects | Not distinguished |
| `wcfm` | class `WCFM` or `WCFM_VERSION` | No | Treated as missing | Not distinguished |
| `vitepos` | `VITEPOS_VERSION` or `Vitepos\Apps\Apps` | No | Treated as missing | Not distinguished |
| `wc_blocks` | `Automattic\WooCommerce\Blocks\Package` | No | WooCommerce core class — true whenever WC Blocks package exists | Not distinguished |
| `woocommerce` | class `WooCommerce` | No | Required dependency | Not distinguished |
| `redis` / `wp_rocket` | constants/classes | No | Not shown in Settings Integrations | Not in this workstream |

Health check message is literal: `'Optional integration detection placeholder.'`

Technical Diagnostics shows Yes/No for woodmart/wpml/wcml/wcfm/vitepos only. It does **not** show `wc_blocks`.

### 4.3 Adapter classes named in architecture — not in RC.8 source

These do **not** exist in the RC.8 tree:

`WpmlAdapter`, `NullTranslationAdapter`, `TranslationAdapterInterface`, `WcmlAdapter`, `NullCurrencyAdapter`, `CurrencyAdapterInterface`, `WoodMartAdapter`, `NullWoodMartAdapter`, `ThemePresentationAdapterInterface`, `WcfmAdapter`, `NullWcfmAdapter`, `VitePosAdapter`, `NullVitePosAdapter`, `BlocksCheckoutAdapter`.

No core class calls WPML/WCML/WCFM/VitePOS/WoodMart APIs. That boundary is currently kept by **absence**, not by adapters.

### 4.4 Automated tests

| Area | What exists | What it proves |
|------|-------------|----------------|
| `PluginBootServiceGraphTest` | Container has `IntegrationRegistry` | Class is wired |
| `CompatibilityMatrixQualificationTest` | Fail-closed quotes, currency mismatch, native-method preservation, HPOS declaration | Classic shipping integrity — **not** third-party compatibility |
| Checkout validator | Instantiation in the service graph only | Not Classic vs Blocks behaviour |
| WPML / WCML / WCFM / VitePOS / Blocks | **No tests** | Nothing |
| WoodMart | Stage 6A JS tests use standard WC variation events | Generic WC script, not WoodMart |

No test uses a real third-party plugin. Class existence is not compatibility.

---

## 5. WooCommerce Cart & Checkout Blocks (highest priority)

**Qualification: NOT IMPLEMENTED**

### 5.1 Settings switch

- Flag: `enable_blocks_adapter` (`cetech_de_enable_blocks_adapter`).
- UI: Advanced → **Checkout runtime**, not the Integrations panel. Label: “Support WooCommerce Blocks checkout.” Caution: “Experimental. Test thoroughly before using on a live store.”
- Enabling it does **not** change runtime behaviour.
- It can be enabled when Cart/Checkout Blocks are not used.
- The UI over-claims: it describes support, not a missing adapter.

### 5.2 Detection

`wc_blocks` is true whenever WooCommerce’s Blocks package class exists. On modern WooCommerce that is **almost always true**, including stores that still use Classic Cart/Checkout (training). Detection is not “this store’s cart/checkout pages are Blocks.”

### 5.3 Required Blocks surface vs RC.8

| Required item | RC.8 evidence |
|---------------|---------------|
| `FeaturesUtil::declare_compatibility('cart_checkout_blocks', $file, true\|false)` | **Absent.** Only `custom_order_tables` (HPOS) is declared. |
| Dedicated Blocks integration registration (`woocommerce_blocks_loaded` / Blocks `IntegrationInterface`) | **Absent** |
| Store API extension/schema (`ExtendSchema::register_endpoint_data`) | **Absent** |
| Safe DE metadata on Store API (no supplier/origin/cost) | **Absent** |
| Client Blocks JS (SlotFill / Inner Block / `registerCheckoutFilters`) | **Absent** — frontend JS is PDP selector only |
| Delivery selection updates via Store API (`register_update_callback` / cart extensions) | **Absent** |
| Address change → authoritative re-resolution | Classic: WC shipping recalc via `woocommerce_cart_shipping_packages` + rate calculator. Blocks: Store API `woocommerce_store_api_cart_update_customer_from_request` **not hooked**. Package filter *might* still run if Store API uses `WC()->shipping()`, but this is **unproven** and is not a Blocks adapter. |
| Selected Delivery Offer persistence | Classic cart item key `cetech_de_delivery_selection`. Store API `/cart/add-item` args observed: `id`, `quantity`, `variation` only — **no DE field**. |
| Store Pickup persistence | Same Classic cart-item path; no Store API extension |
| Validation before Place Order | Classic: `woocommerce_after_checkout_validation`. Blocks current API: throw `Automattic\WooCommerce\StoreApi\Exceptions\RouteException` from `woocommerce_store_api_checkout_update_order_from_request` (and add-to-cart `woocommerce_store_api_validate_add_to_cart`). **Not hooked.** |
| Shipping-rate recalculation | `WC_Shipping_Method` `delivery_engine_selected_offer` + `woocommerce_package_rates` fail-closed filter. This can feed Store API *rates* if packages still go through WC shipping. **That is not Blocks parity.** |
| Order-item / shipping-line snapshots | `woocommerce_checkout_create_order_line_item` + `woocommerce_checkout_order_created`. Store API uses `woocommerce_store_api_checkout_order_processed` / `woocommerce_store_api_checkout_update_order_from_request`. **Not hooked.** |
| Mixed Delivery/Pickup groups | Classic `ShippingPackageBuilder` on `woocommerce_cart_shipping_packages` | Unproven in Cart/Checkout Blocks UI |
| International Air/Sea | Server ECR + fail-closed native-rate filter | Unproven in Blocks UI |
| In Warehouse local Delivery | Server ECR | Unproven in Blocks UI |
| Fail-closed when a DE-managed package has no DE rate | `SelectedOfferShippingIntegration::filter_managed_package_rates` returns only DE rates (empty = no leftover Flat Rate/Local pickup) | Unproven whether Blocks UI presents this as a hard block vs an empty shipping list |

### 5.4 Classic must not break

Classic remains the RC.8-qualified path:

- Cart capture: `woocommerce_add_to_cart_validation`, `woocommerce_add_cart_item_data`, `woocommerce_get_cart_item_from_session`
- Cart warnings: `woocommerce_before_cart` (Classic cart template only — Cart Block does not use that template)
- Checkout validation: `woocommerce_after_checkout_validation`
- Packages/rates/snapshots: Classic WC hooks above

Any Blocks work must be additive, flag-gated, and must leave this chain intact.

### 5.5 Current official mechanisms to use (do not build on obsolete APIs)

Documented 2026 WooCommerce path:

1. `before_woocommerce_init` → `FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true)` **only after** Cart and Checkout parity. Until then, either undeclared or explicit `false`.
2. `woocommerce_blocks_loaded` → Store API `ExtendSchema` for cart / cart item / checkout namespaces. Public-safe data only.
3. `ExtendSchema::register_update_callback` for selection changes from Blocks JS.
4. `woocommerce_store_api_validate_add_to_cart` for add-to-cart eligibility.
5. `woocommerce_store_api_cart_update_customer_from_request` for address-driven re-resolution.
6. `woocommerce_store_api_checkout_update_order_from_request` + `RouteException` (HTTP 400) for Place Order validation.
7. `woocommerce_store_api_checkout_order_processed` (and/or update-order-from-request) for snapshots — do not assume Classic `woocommerce_checkout_order_created` fires.
8. Client: `@woocommerce/blocks-checkout` `registerCheckoutBlock` / documented SlotFills / checkout filters. Prefer `wc/store/...` data stores. Do not scrape Classic checkout DOM.

Server remains authoritative. Browser-submitted prices stay untrusted.

### 5.6 Tests

Absent. Existing shipping tests prove Classic `WC_Shipping_Method` behaviour only.

---

## 6. WPML

**Qualification: STUB ONLY**  
**Physical WPML-present: BLOCKED BY TEST ENVIRONMENT** on training (plugin not installed). No licensed WPML package in this repository. FLAIROC historically had WPML CMS 4.9.6 + String Translation 3.5.3 (Stage 0B, 2026-08-10) but must not be modified for this workstream.

### 6.1 Settings switch

- Flag: `enable_wpml_adapter`.
- Enabling it does not change runtime.
- It can be enabled when WPML is absent.
- Copy: “Enable only when WPML is installed and CETECH support has confirmed compatibility” — still a tickable box with no detection gate.

### 6.2 Detection

`ICL_SITEPRESS_VERSION` only. No version display. Missing = false. Inactive = false. Unsupported version = still true if the constant exists.

Absence does not fatal (required). RC.8 has zero direct WPML function calls.

### 6.3 Adapter / mapping

| Intended | RC.8 |
|----------|------|
| `wpml-config.xml` | **File does not exist** |
| String registration for public labels | **None.** Offer labels/descriptions/pickup instructions are database fields. Plugin PHP uses `__()` / `_x()` with text domain `cetech-woocommerce-delivery-engine` but does not call `load_plugin_textdomain()` and has no `languages/` catalog. |
| Translated products inherit/copy operational rules | **None.** Scoped configuration and product rules key by WooCommerce product/variation ID. A WPML translation is a different ID. |
| Copy operational values (availability, logistics profile, supplier/origin IDs, offer IDs, default offer, route restrictions, rate-card linkage) | **Not implemented** |
| Translate public content only | **Not implemented** |
| No supplier/origin leakage | Core presentation already hides private fields from customers; no WPML-specific audit of translation editors exposing private IDs |
| Current language renders translated public copy | **Not implemented** |

`wpml-config.xml` alone cannot copy DE operational rows: they are not post meta. A real adapter must hook WPML translation-created/updated events and copy `configuration_scopes` / fields / collections (and any remaining legacy product rules) from the source product ID to the translated product ID, then register **public** strings (labels, descriptions, pickup instructions, status copy) — never private logistics.

### 6.4 Tests

None.

---

## 7. WCML / multicurrency

**Qualification: STUB ONLY**  
**Physical WCML-present: BLOCKED BY TEST ENVIRONMENT** on training (plugin not installed). WCML normally requires WPML. FLAIROC historically had WCML 5.5.7. No approved package in this repository.

### 7.1 Settings switch

- Flag: `enable_wcml_adapter`.
- Enabling it does not convert prices or change snapshots.
- Can be enabled when WCML is absent.

### 7.2 Detection

`WCML_VERSION` or class `woocommerce_wpml`. No version. Missing/inactive = false.

### 7.3 Pricing truth today

1. Rate cards store `base_currency` + `base_amount`.
2. `SelectedOfferShippingRateCalculator::currency_code()` uses `get_woocommerce_currency()`.
3. `WpdbRateCardRepository::listActiveForQuoteMatch()` requires `base_currency = :currency`.
4. Mismatch → `ERROR_NO_MATCHING_RATE_CARD` → no $0 fallback (`CompatibilityMatrixQualificationTest` covers this).
5. Order snapshot / line snapshot store a single `currency_code` and quoted amount. **No** base amount, charged amount, checkout currency, or conversion rate/context.
6. `manual_currency_override_data` is a schema-5 column only. No reader, writer, or admin UI.

Without WCML, this is correct for a one-currency shop (training charges GHS).

With WCML:

- Eligibility must stay address-based (already true; currency is not used in zone matching). Good.
- If WCML filters `get_woocommerce_currency()` to the customer currency, quoting fail-closes unless a USD/EUR/… rate card exists. That is **not** “convert through WCML.”
- If someone later converts the already-display-currency shipping rate again (WCML shipping conversion / `woocommerce_package_rates`), **double conversion** is the failure mode to prevent.
- Historical paid orders cannot freeze an exchange rate they never stored.

### 7.4 Current official WCML mechanisms to use

Use WCML’s documented APIs rather than reimplementing FX:

- `apply_filters('wcml_raw_price_amount', $base_amount, $currency)` for conversion of DE **base** amounts
- `wcml_client_currency` / `wcml_price_currency` for current currency
- `wcml_rounded_price` to respect WCML rounding
- `wcml_exchange_rates` only to **record** snapshot context, not to invent a second converter
- Manual DE per-currency overrides win only where explicitly stored (`manual_currency_override_data` or equivalent)

Do not convert eligibility. Do not convert already-converted WC shipping totals.

### 7.5 Tests

Currency **mismatch fail-closed** is covered. WCML conversion, overrides, rounding, snapshot context, and double-conversion guards are **absent**.

---

## 8. WoodMart

**Adapter qualification: STUB ONLY**  
**Classic storefront on WoodMart: PARTIAL / WORKING** for the RC.8-proven path  
**Remaining WoodMart-only surfaces: not proven**

### 8.1 Settings switch

- Flag: `enable_woodmart_adapter`.
- Enabling it does not add CSS/JS or bind WoodMart events.
- Can be enabled on non-WoodMart themes.
- Copy: “Optional adapter for Woodmart theme compatibility” **implies a special adapter is required**. Stage 7 and RC.8 physical QA say the opposite for Classic.

### 8.2 Detection

Theme template/stylesheet equals `woodmart` (child theme detects via template). No version. Training: Woodmart 8.5.7 + Woodmart Child 1.0.0.

### 8.3 Is a dedicated adapter required after RC.8?

**Not for the already-qualified Classic path.** Evidence:

- Stage 6B FLAIROC: standard `found_variation` / `reset_data` worked; **Stage 7 adapter not required** (`docs/STAGE-6B-FLAIROC-VARIABLE-ECR-VERIFICATION.md`).
- RC.8 owner physical QA on training (WoodMart child, Classic cart/checkout): In Store Delivery+Pickup, International Air, In Warehouse local Delivery — **PASS** (`docs/RC8-FINALIZATION.md`). Adapter flag was not a feature of that QA.

Generic hooks already used:

- `woocommerce_before_add_to_cart_button` / `woocommerce_single_product_summary`
- `found_variation` / `reset_data` / `hide_variation` on `form.variations_form`
- Classic cart/checkout/shipping hooks

**Never modify the WoodMart parent theme.** RC.8 does not.

### 8.4 Surfaces still requiring proof (not a reason to invent an adapter first)

| Surface | RC.8 code | Evidence |
|---------|-----------|----------|
| Simple PDP | Generic hook | RC.8 training PASS |
| Variable products | Generic WC events | Stage 6B PASS; RC.8 International/Warehouse used configured products |
| Variation swatches | No WoodMart swatch events | **Not separately proven** |
| AJAX variation changes | Standard WC events only | Proven where WoodMart still fires them |
| AJAX Add to Cart (catalog / `woodmart-ajax-shop-on`) | Requires POST field `cetech_de_delivery_option_key` from PDP form | Catalog ATC **can skip** the selector — **not proven** |
| Mini-cart | `woocommerce_get_item_data` for line summary | Generic; WoodMart mini-cart styling **not** adapter-specific |
| Buy Now | No WoodMart Buy Now hook | **Not proven**; WoodMart Buy Now often bypasses normal ATC |
| Quick View | Selector binds on `DOMContentLoaded` to the first `.variations_form`; no Quick View reopen bind | **Not proven** |
| Classic cart/checkout | Generic WC + WoodMart `wd-checkout-form` class on Classic form | RC.8 PASS |
| Blocks | None | Training Cart/Checkout are Classic; WoodMart does not currently host Blocks checkout |

Recommendation: **do not build a WoodMart adapter in this workstream unless one of the unproven surfaces fails on generic hooks.** Fix the Settings lie. Add targeted QA for Quick View, Buy Now, and AJAX shop ATC on training.

### 8.5 Tests

JS unit tests for the variable selector use jQuery WC events. They do not load WoodMart.

---

## 9. WCFM Marketplace

**Qualification: STUB ONLY**  
**Physical WCFM-present: BLOCKED BY TEST ENVIRONMENT** on training (not installed). FLAIROC historically had WCFM + marketplace + membership. No approved package in this repository.

### 9.1 Settings switch

- Flag: `enable_wcfm_adapter`.
- Enabling it does not expose or hide vendor UI.
- Can be enabled when WCFM is absent.

### 9.2 Intended minimum vs RC.8

| Intended minimum | RC.8 |
|------------------|------|
| Optional; works when WCFM absent | **Yes** (no WCFM calls) |
| Vendors cannot see supplier/origin records | **Default yes** — caps `manage_private_sources` / `view_private_origins` granted only to Administrator and Shop Manager |
| Vendors cannot see internal costs | **Default yes** — `view_private_delivery_costs` same |
| Vendors cannot alter global Rate Cards / Delivery Areas | **Default yes** — those caps are not on vendor roles unless Access grants them |
| Vendors cannot view another vendor’s shipment/private logistics | **Default yes** — `manage_shipments` not granted; there is no vendor-scoped shipment query because there is no vendor UI |
| Do not automatically expose Product Delivery configuration to vendors | **Yes** — `ProductDeliveryPanel` requires `manage_product_delivery_rules` |
| Future vendor fulfilment-management | **Not present** (correct — do not invent it here) |

### 9.3 Access-matrix gap

`RoleAccessService::roles()` lists **all** WordPress roles. If WCFM is later installed, `wcfm_vendor` (and related roles) appear as editable Access rows. An administrator could grant Rate Cards, private sources, or product delivery rules to vendors **without any WCFM adapter**. The WCFM checkbox is unrelated.

That is the real privacy repair if WCFM will be used: capability policy, not a vendor settings screen.

### 9.4 Tests

No WCFM tests. Capability tests cover Administrator protection and product-panel caps in generic WP roles.

---

## 10. VitePOS

**Qualification: STUB ONLY**  
**Plugin present on training; POS compatibility not proven (BLOCKED until an adapter exists and is tested in VitePOS, not inferred from online PDP).**

### 10.1 Settings switch

- Flag: `enable_vitepos_adapter`.
- Enabling it does not change POS or online behaviour.
- Can be enabled when VitePOS is absent (and can be left off when VitePOS is present — current training likely this).

### 10.2 Detection

`VITEPOS_VERSION` or `Vitepos\Apps\Apps`. Training: REST namespace `vitepos/v1` is registered; `wp-content/plugins/vitepos/vitepos.php` exists (HTTP 500 on direct request, consistent with a PHP bootstrap that expects WordPress). Version **not** captured.

### 10.3 Intended minimum vs RC.8

| Intended | RC.8 |
|----------|------|
| Optional; safe if VitePOS absent | **Yes** |
| POS orders do not bypass hard constraints | **Unimplemented.** Constraints run in selector/capture/checkout/shipping. POS typically creates orders outside Classic checkout. |
| Staff choose Delivery or Store Pickup only when eligible | **No POS UI** |
| Delivery carries valid offer + customer shipping charge | Only if POS goes through WC cart + DE POST field + shipping method — **not demonstrated** |
| Normalized selection survives onto the WC order | Snapshots hook Classic checkout create-order actions, not generic `wc_create_order` |
| Delivery may create the normal shipment record | `PaidOrderShipmentSubscriber` on `woocommerce_payment_complete` / paid statuses **can** fire for any paid WC order **if** snapshots/packages exist. POS orders without snapshots will not plan correctly. |
| Store Pickup: no delivery shipping charge; no delivery shipment | Classic pickup package quotes `0.0000` and shipment planner suppresses pickup. POS must reproduce that contract explicitly. |
| Online browsing state must not contaminate POS | **No isolation.** Capture stores DE keys on `WC()->cart` / session. No VitePOS request detection. No separate POS cart namespace. Frontend selector does not use `localStorage`; WooCommerce cart session **can** still be shared depending on how VitePOS authenticates. |
| Plugin remains safe if VitePOS absent | **Yes** |

### 10.4 Session / request boundary (explicit)

| Channel | Used by DE today | POS isolation |
|---------|------------------|---------------|
| `$_POST['cetech_de_delivery_option_key']` | Classic ATC | POS will not send this unless adapted |
| WC cart item data / session | Classic cart | Shared WC session is the contamination risk |
| `localStorage` / `sessionStorage` | **Not used** by DE frontend | N/A |
| Store API cart | Unused by DE | VitePOS may use REST (`vitepos/v1`, `wc/pos/v1/catalog`) — unhooked |
| Classic checkout validation | `woocommerce_after_checkout_validation` | POS bypass |
| Order snapshots | Classic checkout create hooks | POS bypass |

Do not assume the online PDP UI applies to POS.

### 10.5 Tests

None. REST namespace presence is not a test.

---

## 11. Settings UI / integration health

**Current UI (RC.8):** Settings → Advanced contains:

1. **Checkout runtime:** Classic support + Blocks support (experimental caution).
2. **Integrations:** five checkboxes (WPML, WCML, WoodMart, WCFM, VitePOS).
3. Technical Diagnostics (capability-gated): detection Yes/No, every flag on/off.

Blocks is **not** in the Integrations panel even though operators think of it as an integration.

**Problems:**

- Any integration flag can be ticked without the dependency.
- Ticked ≠ working. There is no “Compatibility not yet verified” state.
- WoodMart copy implies an adapter is required after Stage 7 proved it is not (for Classic).
- Blocks copy claims support.
- Diagnostics detection is a boolean, not a versioned health record.
- `wc_blocks` is detected in code and omitted from the diagnostics integration table.
- Unavailable experimental flags (timeline, bulk import) are the only disabled checkboxes; stub integrations are fully enabled.

**Proposed status model (implementation later):**

| Status | When |
|--------|------|
| Not installed | Detection false |
| Detected — version X | Detection true and version readable |
| Dependency inactive | Files present / version known but not active |
| Available | Detected + version in a documented support range + adapter implemented |
| Enabled | Flag on **and** adapter actually registered |
| Compatibility verified | Documented physical/automated evidence for this version |
| Compatibility not yet verified | Adapter exists; evidence missing |
| Unsupported version | Detected version outside support range |
| Configuration required | e.g. WCML currencies, WPML languages, POS register |
| Safe fallback | Flag off or dependency missing — core Classic WooCommerce path |

**Rules for a later UI repair:**

- Disable the Blocks toggle’s “supported” language until parity exists; prefer “not implemented” / disable, or keep it off and explain.
- Disable WPML/WCML/WCFM toggles when the plugin is absent; explain why.
- WoodMart: show “theme detected — generic WooCommerce path; dedicated adapter not required” rather than a magic checkbox, unless a proven adapter is later added.
- VitePOS: show detected version; do not imply online checkout equals POS.
- WooCommerce remains the only mandatory dependency.

---

## 12. Test environment — training.cetechbpa.com

Read-only public inspection, 2026-08-31. **No plugins were installed. No wp-admin writes. FLAIROC not used.**

| Item | Recorded value | How | Confidence |
|------|----------------|-----|------------|
| Site | `https://training.cetechbpa.com` | HTTP | High |
| WordPress | **7.1** | `<meta name="generator">` | High |
| WooCommerce | **11.0.1** | `woocommerce/.../wc-blocks.css?ver=wc-11.0.1`, `select2.css?ver=11.0.1` | High |
| PHP | **Not exposed** on public HTTP (`X-Powered-By` absent) | Would require wp-admin System Status / hosting panel | **BLOCKED** for this audit |
| Active theme | **Woodmart Child 1.0.0** | `style.css`, `body.wp-child-theme-woodmart-child` | High |
| Parent theme | **Woodmart 8.5.7** | `woodmart/style.css` Version header; CSS `?ver=8.5.7` | High |
| Cart page | **Classic** — `woocommerce-cart-form`, page-id `12`, `/cart/` | HTML | High |
| Checkout page | **Classic** — `/checkout-2/` page-id `4431`, `body.woocommerce-checkout`, `<form name="checkout" class="checkout woocommerce-checkout wd-checkout-form">`. **No** `wp-block-woocommerce-checkout`. Empty `/checkout/` redirects to the Cart page. | HTML | High |
| Store API | Present (`/wp-json/wc/store/v1`, cart currency GHS) | REST | High — presence ≠ Blocks pages |
| WPML | **Not installed** | `sitepress-multilingual-cms/sitepress.php` **404**; no WPML namespace; `html lang="en-US"`; no hreflang | High |
| WCML | **Not installed** | `woocommerce-multilingual` path **404** | High |
| WCFM | **Not installed** | `wc-frontend-manager` and `wc-multivendor-marketplace` **404** | High |
| VitePOS | **Installed / REST-active** | Namespace `vitepos/v1`; plugin file exists (direct PHP 500). Version not publicly readable | High presence; version **BLOCKED** |
| Other POS surface | `wc/pos/v1/catalog` namespace | REST | Present; not identified as DE-compatible |

**Physical compatibility testing status on training:**

| Integration | Physical test possible now? |
|-------------|-----------------------------|
| Classic Cart/Checkout on WoodMart | **Yes** — this is the RC.8-qualified path |
| Cart/Checkout Blocks | **Not the configured customer pages.** Store API exists. A Blocks test page would have to be created without turning production checkout into Blocks unintentionally. |
| WPML | **BLOCKED** — not installed. Do not install blindly. No approved copy in repo. |
| WCML | **BLOCKED** — not installed. |
| WCFM | **BLOCKED** — not installed. |
| VitePOS | Plugin present; **POS flow still BLOCKED** until an adapter exists and a POS test script is run inside VitePOS (not the online PDP). |
| WoodMart Quick View / Buy Now / AJAX shop ATC | **Available to qualify** on this theme without a new plugin |

Historical FLAIROC Stage 0B (2026-08-10) recorded WP 7.0.3, WC 11.0.0, PHP 8.5.5, WoodMart 8.5.7, WPML 4.9.6, WCML 5.5.7, WCFM present. That is a **different site**, older snapshot, and **must not be modified** for this workstream. It is not a substitute for training WPML/WCML/WCFM evidence.

Plugin header `WC tested up to: 10.9` is **behind** training’s WooCommerce **11.0.1**. That is a compatibility-declaration gap, not Blocks support.

---

## 13. Current API research (implementation planning constraints)

### 13.1 WooCommerce Blocks / Store API

Rely on current WooCommerce core docs, not the old standalone `woocommerce-gutenberg-products-block` plugin:

- Compatibility: `Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $file, bool )` on `before_woocommerce_init`. WooCommerce only evaluates this when `WC tested up to` is set (it is).
- Store API extension: `woocommerce_blocks_loaded` → container `ExtendSchema` → `register_endpoint_data` with a unique namespace. Data appears under `extensions`.
- On-demand cart updates: documented `register_update_callback`.
- Add-to-cart validation: `woocommerce_store_api_validate_add_to_cart`.
- Address updates: `woocommerce_store_api_cart_update_customer_from_request`.
- Checkout validation / meta: `woocommerce_store_api_checkout_update_order_from_request`; fail with `RouteException` status 400.
- After order ready for payment: `woocommerce_store_api_checkout_order_processed`.
- Client: documented checkout SlotFills / `registerCheckoutBlock` / `wc/store` data stores.

**Do not** treat `WC_Shipping_Method` rates in Store API JSON as completed Blocks support.

**Do not** hook only `woocommerce_after_checkout_validation` and claim Checkout Block coverage.

### 13.2 WCML

Current documented hooks for third-party money:

- `wcml_raw_price_amount`
- `wcml_price_currency` / `wcml_client_currency`
- `wcml_rounded_price`
- `wcml_exchange_rates` (snapshot context)
- `wcml_translate_shipping_method_in_package` / `translate_shipping_methods_in_package` for **titles**, not amounts

Canonical rate-card amount stays in base currency. Convert for charge/display only when WCML is active **and** the adapter is enabled. Persist both.

### 13.3 WPML

`wpml-config.xml` custom-field actions are still `translate` / `copy` / `copy-once` / `ignore`. That file is necessary for any **post meta** DE might add; it is **not sufficient** for table-backed operational configuration. Product translation mapping must use WPML’s documented product/translation APIs and must never create a second logistics truth.

### 13.4 WCFM / VitePOS / WoodMart

No RC.8 code depends on their private APIs. Future adapters must call documented public hooks only. WoodMart parent files stay untouched.

---

## 14. Smallest implementation sequence

The owner’s preferred order was Blocks → WoodMart → WPML → WCML → WCFM → VitePOS.

**The audit agrees that Blocks is first.** It adjusts the rest so we do not build a WoodMart adapter that RC.8 already showed is unnecessary, and so we do not fake WPML/WCML/WCFM passes on training.

| Step | Work | Why this order | Training gate |
|------|------|----------------|---------------|
| **0. Settings honesty (thin, with step 1)** | Integration health (detected/not installed; Blocks not claimed). Reclassify Experimental: ECR/variable ECR as inherent supported runtime; remove dead Bulk/category/fallback checkboxes; keep timeline unavailable; keep demo data testing-only. | Prevents operators enabling fiction or turning off the accepted RC.8 architecture. No schema change. | Can ship as docs+UI with Blocks work |
| **1. WooCommerce Cart & Checkout Blocks** | Real adapter: declare compatibility only at the end; Store API extensions; Classic-equivalent selection, address re-resolve, validation, snapshots, grouping, Air/Sea, In Warehouse, pickup, fail-closed. **Do not break Classic.** | Only unimplemented checkout channel; highest customer-money risk | Classic pages stay as-is. Add a **separate** Blocks cart/checkout test page or staging clone; do not silently convert training’s live Classic checkout |
| **2. WoodMart residual qualification** | No new adapter unless a failure is proven. QA Quick View, Buy Now, AJAX shop ATC, swatches, mini-cart on training’s WoodMart 8.5.7 while Blocks/Classic regression runs | Dedicated adapter would duplicate generic hooks | **Available now** |
| **3. WPML** | Real optional adapter: copy operational config to translated product IDs; translate public strings only; `wpml-config.xml` for any meta; never fatal when absent | Blocked on training today | **BLOCKED** until a licensed WPML stack is provided on a designated site. Do not install blindly. Do not use FLAIROC. |
| **4. WCML** | Convert via WCML APIs; manual overrides; snapshot base+charged+rate; no double conversion; eligibility ignores currency | Requires WPML in normal deployments | **BLOCKED** with WPML |
| **5. WCFM** | Privacy/Access hardening (deny marketplace roles global/private caps). **No vendor fulfilment UI.** | Can start as first-party capability policy without WCFM installed | Full vendor-dashboard proof **BLOCKED** until WCFM is available |
| **6. VitePOS** | POS-specific selection + order snapshot + shipment rules + session isolation | Plugin already on training, but online UI is the wrong test harness | After adapter: physical POS script on training VitePOS |

**Not in this sequence:** schema 6, RC.9 identity, Stage 15, per-item locations, Return/Refund, carrier APIs, Bulk, FLAIROC deploys, packaging.

**Do not code yet.** This document is the stop point.

---

## 15. Settings / feature-flag honesty

**Status:** Audit only. No runtime change.

Physical review of Settings → Advanced → **Experimental and future features** against RC.8 source and tests. There is no flag named `enable_bulk_import_tools`; the stored key is `enable_bulk_import`.

### 15.1 Exact status of every Settings flag

| Flag | Current Settings home | Default | Runtime consumed? | Classification | Proposed home | Proposed UI action |
|------|----------------------|---------|-------------------|----------------|---------------|-------------------|
| `enable_customer_order_delivery_summary` | Customer experience | false | Yes | **Supported** optional | Core behaviour | Keep as genuine choice |
| `enable_customer_email_delivery_summary` | Customer experience | false | Yes | **Supported** optional | Core behaviour | Keep as genuine choice |
| `enable_order_delivery_snapshot_persistence` | Orders | false | Yes; also in Activate chain | **Supported** (required for accepted Classic path once activated) | Core behaviour | Keep visible, but do not describe it as optional after Activate; turning it off makes `ClassicCheckoutRuntimeActivation::is_active()` false |
| `enable_shipment_records` | Shipments | false | Yes | **Supported** optional (Stage 14, default off) | Core behaviour | Keep. Honest optional. |
| `enable_tracking_links` | Shipments | false | Yes (requires shipment records) | **Supported** optional | Core behaviour | Keep. Copy is already honest. |
| `enable_product_delivery_selector` | Advanced → Checkout runtime | false | Yes; Activate chain | **Supported** (required once activated) | Not an ordinary toggle | Show as status of Activate, or Diagnostics rollback only |
| `enable_cart_delivery_selection_capture` | Advanced → Checkout runtime | false | Yes; Activate chain | **Supported** (required once activated) | Not an ordinary toggle | Same |
| `enable_checkout_delivery_selection_validation` | Advanced → Checkout runtime | false | Yes; Activate chain | **Supported** (required once activated) | Not an ordinary toggle | Same |
| `enable_woocommerce_shipping_rate_calculation` | Advanced → Checkout runtime | false | Yes; Activate chain | **Supported** (required once activated) | Not an ordinary toggle | Same |
| `enable_classic_checkout_adapter` | Advanced → Checkout runtime | **true** | Activate chain + wizard only. Cart/checkout hooks **do not** check it. | **Dead as a runtime gate** | Remove from Settings or show “Classic is the supported path” as status | Do not imply unchecking disables Classic |
| `enable_blocks_adapter` | Advanced → Checkout runtime | false | **No** | **Not implemented** | Optional integrations (health) | Disable/relabel until Blocks parity. See §5. |
| `enable_wpml_adapter` | Advanced → Integrations | false | **No** | **Stub** | Optional integrations | Health, not a blind checkbox. See §6. |
| `enable_wcml_adapter` | Advanced → Integrations | false | **No** | **Stub** | Optional integrations | Health, not a blind checkbox. See §7. |
| `enable_woodmart_adapter` | Advanced → Integrations | false | **No** | **Stub** (Classic WoodMart works without it) | Optional integrations | Status: theme detected / dedicated adapter not required. See §8. |
| `enable_wcfm_adapter` | Advanced → Integrations | false | **No** | **Stub** | Optional integrations | Health. See §9. |
| `enable_vitepos_adapter` | Advanced → Integrations | false | **No** | **Stub** | Optional integrations | Health. See §10. |
| `enable_effective_configuration_runtime` | **Experimental** + “Leave off unless CETECH support…” | false | **Yes — ECR cutover.** Off → all targets LEGACY. | **Supported production** (stale experimental copy) | Inherent / not an ordinary toggle | Remove experimental warning. Do not leave as a casual Settings checkbox. |
| `enable_variable_product_ecr_runtime` | **Experimental** + same support warning | false | **Yes — variation ECR.** Off → variations LEGACY even if main ECR is on. | **Supported production** (part of ECR contract) | Inherent with main ECR | Remove experimental warning. Do not leave as a separate ordinary toggle. |
| `enable_customer_timeline` | Experimental, `unavailable` | false | **No** | **Not implemented** / reserved | Advanced / experimental | Keep future/unavailable. Settings save already refuses to turn it on. |
| `enable_bulk_import` | Experimental, `unavailable` (“Reserved for a future…”) | false | **No** | **Dead flag.** Bulk Tools are live. | Remove from Settings | Do not convert to a real switch unless a product need is stated. Menu is capability-gated. |
| `enable_category_rules` | Experimental (tickable) | false | **No** | **Stub flag.** Legacy category targeting exists **ungated**. | Remove from Settings (or Diagnostics note) | Do not claim the checkbox enables category inheritance. |
| `enable_site_fallback_rule` | Experimental (tickable) | false | **No** | **Not implemented** | Remove from Settings | Do not confuse with Delivery Area fallback zones or ECR GLOBAL Site-wide Defaults. |
| `demo_data_on_activation` | Experimental + production caution | false | **No seeder.** Activator never reads it. | **Testing-only label; no runtime path** | Development / testing | Keep off by default. Copy may stay as a warning, but do not claim it currently creates demo Areas/Offers/Charges — it does not. |
| `delete_data_on_uninstall` | Maintenance (not a FeatureFlags key) | off | Yes (uninstall) | **Supported** | Development / testing or Maintenance | Keep. Genuine destructive choice. |

The table covers every `FeatureFlags::DEFAULTS` key plus `delete_data_on_uninstall`. There is no `enable_bulk_import_tools` key.

### 15.2 Use Site-wide Defaults at checkout (`enable_effective_configuration_runtime`)

**Current UI:** Experimental. Caution: “Deployment switch. Leave off unless CETECH support has asked you to turn it on for a controlled test.”

**Runtime:** Canonical cutover in `ProductDeliveryRuntimeConfigurationRouter`. If this flag is off, `decide()` returns `LEGACY` for every target. Legacy is `product_delivery_rules`, not Site-wide Defaults + Product Exceptions.

Consumers include the router, `ClassicCheckoutRuntimeActivation::CHAIN` (Activate Delivery Engine turns it **on**), `VariationDeliveryOptionsEndpoint`, `CartDeliverySelectionCapture` (with the variable flag), `VariableDeliverySelectorAssets`, and `OperationalStateService` (ECR-active vs legacy-serving).

**Tests:** `ProductDeliveryRuntimeConfigurationRouterTest`, `SimpleProductNonRegressionTest`, `VariableDeliverySelectorAssetsTest`. Package verifier requires the default to stay **false** on a fresh install (`scripts/verify-production-package-autoload.php`).

**RC.8 architecture:** Owner-accepted Fulfilment.4 / RC.8 Classic path is Site-wide Defaults + Product Exceptions. Activate Delivery Engine enables this flag. Disabling it after go-live would silently send checkout back to the retired legacy rule table.

**Verdict:** This is **no longer experimental**. It is the supported production resolver.

**Toggle usefulness:** It is still a real gate in code, so it is not a no-op. It is **not** a legitimate ordinary Settings choice. Turning it off would break the accepted RC.8 architecture. Keep the option internally for Activate + possible support rollback in Technical Diagnostics. Do **not** show the “leave off unless support asks” warning. Do **not** keep it in Experimental.

### 15.3 Use Site-wide Defaults for product variations (`enable_variable_product_ecr_runtime`)

**Current UI:** Experimental. Same support-only warning. Description correctly says it requires the main ECR flag.

**Runtime:** `ProductDeliveryRuntimeConfigurationRouter::decide_variation()` returns `LEGACY` when this flag is off, even if main ECR is on. Variable selector AJAX (`VariationDeliveryOptionsEndpoint`) refuses to run. Cart capture’s variation path requires both flags.

**Tests:** Router tests explicitly: main ECR on + variable flag off → variation LEGACY. `VariableDeliverySelectorAssetsTest` asserts assets enqueue only when both flags are on. RC.8 physical QA (variable inheritance/overrides, International/In Warehouse/In Store) ran with the Activate chain, which sets **both** flags.

**Verdict:** Variation inheritance is part of the normal ECR contract, not an optional extra. Experimental language is stale.

**Toggle usefulness:** Meaningful in code (variations can be forced to legacy while simple products stay on ECR). That split is a Stage 6 migration leftover, not a current administrator product. Make it **inherent** with the main ECR cutover: Activate should keep setting both; ordinary Settings should not offer a variation-only kill switch.

### 15.4 Bulk import tools (`enable_bulk_import`)

**Current UI:** Disabled checkbox. “Unavailable in this release. Reserved for a future Delivery Engine version.” `UNAVAILABLE_EXPERIMENTAL_FLAGS` includes this key, so Settings save **cannot** enable it (`ShipmentReleaseSettingsTest` asserts a posted `1` stays off).

**Runtime:** **No** `is_enabled('enable_bulk_import')` in `src/`. Bulk Tools menu is registered when the user has `manage_product_delivery_rules` or `import_delivery_data` (`AdminMenu.php`). `BulkToolsPage` authorises on those capabilities. RC.7/RC.8 include owner-qualified Bulk Tools (`docs/POST-RC6-BULK9-OWNER-QA.md`, `docs/RC8-FINALIZATION.md`).

**Verdict:** The Settings row is a **lie**. Bulk Tools are implemented, tested, and shipped. The flag is a dead checkbox.

**Proposed action:** **Remove** it from Settings. Do **not** invent an enable/disable switch unless the owner later requires a kill switch. Availability stays capability-gated. Leave the unused option key in `FeatureFlags` / uninstall until a dedicated cleanup is authorised (do not silently delete stored options in this audit).

### 15.5 Category-based product rules (`enable_category_rules`)

**Current UI:** Tickable Experimental checkbox. No unavailable lock.

**Does the flag do anything?** **No.** Zero `src/` consumers besides FeatureFlags, labels, Settings, and uninstall.

**Does category targeting exist anyway?** **Yes, ungated**, as a **legacy** path:

- `ProductDeliveryRuleResolver::build_candidate_hierarchy()` always walks Variation → Product → product categories. Specificity 3/2/1. There is no `enable_category_rules` check.
- `ProductTargetType` is only `product` | `variation` | `category`.
- With ECR on, `LegacyCategoryRuntimeCompatibilityGuard` diverts a product/variation to `LEGACY_CATEGORY_COMPATIBILITY` when the legacy winner is category-derived. Also ungated by this flag.
- Legacy Delivery Rules admin is **retired from the normal menu**. The page class still exists; it is not a current operator workflow.
- ECR native inheritance is GLOBAL → PRODUCT → VARIATION (Site-wide Defaults + Product Exceptions). That is **not** category inheritance.

**Classification:**

| Layer | Status |
|-------|--------|
| Settings flag | **Stub** (dead toggle) |
| Legacy category targeting in the old resolver | **Partial / hidden compatibility** — exists, not flag-gated, not the RC.8 admin path |
| ECR-native category inheritance | **Not implemented** |

Architecture intent “Variation → Product → Category/default if enabled → Site fallback” is **not** what the flag does.

**Tests:** Router tests cover category-dependent legacy divert. No test asserts that ticking `enable_category_rules` changes resolution.

**Proposed action:** Remove the Settings checkbox. If category compatibility must be documented, put it in Technical Diagnostics as “legacy category rules still force the hidden compatibility route,” not as an enable switch.

### 15.6 Site-wide fallback product rule (`enable_site_fallback_rule`)

**Current UI:** Tickable Experimental checkbox.

**Does the flag do anything?** **No.** Zero `src/` consumers besides FeatureFlags, labels, Settings, uninstall.

**Does a fallback product rule exist?** **No.**

- Legacy hierarchy ends at category. No site-level `ProductTargetType`. No site-rule lookup.
- ECR “fallback” is **GLOBAL / Site-wide Defaults** (`configuration_scopes` `global/0/{profile_key}`), gated by `enable_effective_configuration_runtime`, not this flag.
- Delivery Area / destination-zone fallback is a different domain (`destination_zones` / rules). This Settings row is not that.

**Classification:** **Not implemented.**

Do not confuse this checkbox with Site-wide Defaults or Delivery Area leftover-address behaviour.

**Proposed action:** Remove from Settings.

### 15.7 Load demo data on plugin activation (`demo_data_on_activation`)

**Current UI:** Experimental. Caution: can create sample Delivery Areas, Options, and Charges. Testing-only.

**Runtime:** `Activator::activate()` registers capabilities, `FeatureFlags::ensure_defaults()`, schema, migrations, rewrite flush. It **does not read** `demo_data_on_activation`. No seeder class exists in RC.8 `src/`.

**Tests:** `PluginLifecycleQualificationTest::test_fresh_activation_is_idempotent_and_does_not_seed_demo_data`. RC.6 lifecycle qualification: default false, no seeder. Fresh default remains false, so an upgrade that writes defaults will **not** turn it on.

**Verdict:** Keep **testing-only** and **off by default**. The caution is directionally right (it must never seed production) but currently **over-claims**: enabling the box does **not** create demo records, because there is no path. Do not add a seeder in this workstream. If the checkbox stays, copy should say it is reserved and presently does nothing, or the control should move to Development / testing as a disabled reserved item. Prefer not to imply that ticking it on a live store will populate catalog data — it will not, until a seeder is deliberately built.

### 15.8 Customer delivery timeline (`enable_customer_timeline`)

**Runtime:** No `src/` consumer except FeatureFlags, labels, Settings (`unavailable`), uninstall. Stage 14 customer View Order cards are separate (`enable_shipment_records` / `enable_tracking_links`).

**Tests:** `Stage14BRuntimeFreezeTest`, `ShipmentReleaseSettingsTest` — default off; Settings cannot enable it; `UNAVAILABLE_EXPERIMENTAL_FLAGS` includes it.

**Verdict:** Keep **future / unavailable**. RC.8 does not contain an enabled customer timeline.

### 15.9 Proposed Settings grouping (implementation later)

**Core behaviour** — genuine administrator choices:

- Activate Delivery Engine (existing). This is the on-switch for the accepted Classic + Site-wide Defaults + variation ECR chain.
- Customer order-page summary
- Customer email summary
- Shipment records
- Customer tracking links
- Access matrix
- Optional: read-only status that Site-wide Defaults and variation inheritance are on because Activate ran

Do **not** put ECR/variable ECR, selector/capture/validation/shipping, or Classic adapter here as casual checkboxes.

**Optional integrations** — WPML, WCML, WoodMart, WCFM, VitePOS, Blocks, with dependency/status health from §11. WoodMart should report detection, not demand an adapter. Blocks must not say “support” until implemented.

**Advanced / experimental** — only unfinished or controlled features:

- Customer delivery timeline (unavailable)
- Blocks, until parity exists (or live only under Optional integrations as disabled)
- Support-only rollback of the Classic/ECR chain (Technical Diagnostics), not ordinary Settings

**Development / testing:**

- Demo data (off, no current seeder; never auto-on at upgrade)
- Technical Diagnostics link (already capability-gated)
- Delete data on uninstall

**Eliminate from Settings:**

- `enable_bulk_import` dead unavailable checkbox
- `enable_category_rules` dead tick
- `enable_site_fallback_rule` dead tick
- Experimental warnings on ECR / variable ECR
- Classic adapter presented as if unchecking it disables Classic checkout

Pipeline flags (selector → capture → validation → shipping → snapshot → ECR → variable ECR) should remain **internally** so Activate and tests stay deterministic. They should not be marketed as experimental, and disabling them after Activate should not be a one-click Settings action without a severe support warning.

### 15.10 Settings honesty vs remaining Checkout runtime flags

Related stale copy outside Experimental, already covered in §4–§5 and included here so Step 0 is complete:

- Checkout runtime still exposes the four pipeline flags as if they were optional low-level toys. After Activate they **are** the live RC.8 storefront.
- `enable_classic_checkout_adapter` default true does not register or unregister Classic hooks.
- `enable_blocks_adapter` is labelled support and is unimplemented.

---

## 16. Hard limits (observed)

- RC.8 tag `v1.0.0-rc.8` / `6d16622` was not modified.
- No RC.9, no schema 6, no Stage 15.
- No FLAIROC changes.
- No per-item location / Return-Refund / carrier / Bulk work.
- No package.

---

## 17. Completion report (audit task)

**Summary:** Post-RC.8 integrations are detection stubs plus Settings checkboxes. Classic WooCommerce on WoodMart is the only physically qualified customer path. Blocks is not implemented. WPML/WCML/WCFM cannot be physically qualified on training because those plugins are not installed. VitePOS is installed but unused by DE. The Experimental Settings panel is stale: ECR/variable ECR are the accepted production path; Bulk Tools are live behind capabilities; category/fallback flags are dead; demo data has no seeder; timeline remains reserved.

**Scope intentionally excluded:** all implementation, packaging, FLAIROC, plugin installation, Stage 15, schema 6.

**Files changed:** `docs/POST-RC8-INTEGRATIONS-COMPATIBILITY-AUDIT.md` only (this audit).

**Schema/migration impact:** none.

**Runtime behaviour:** unchanged.

**Security/privacy review:** no new exposure. Existing Access-matrix risk if WCFM vendor roles are later granted DE caps. Blocks absence is a checkout-integrity risk on Blocks stores, not on current training Classic pages.

**Shipping-integrity review:** Classic fail-closed quoting and managed-package native-rate suppression remain as in RC.8. They are not proven on Cart/Checkout Blocks.

**Compatibility review:** WooCommerce is the only hard dependency. Optional integrations do not fatal when absent. Settings UI currently over-claims compatibility.

**Performance implications:** none (docs only).

**Tests and results:** none run for this audit (no code change). Existing PHPUnit does not prove third-party compatibility.

**Documentation updated:** this file.

**Staging checks required before any later implementation claim:**

- Blocks: dedicated Cart Block + Checkout Block pages without converting training’s live Classic checkout until owner-approved.
- WPML/WCML/WCFM: licensed plugins on a designated site, or explicit owner approval to install — **not done**.
- VitePOS: in-register POS script after an adapter exists.
- PHP version on training: read from wp-admin System Status (not publicly visible).

**Known limitations:** PHP version on training not recorded; VitePOS version not recorded; FLAIROC historical plugin versions are stale and out of bounds.

**Recommended next phase:** owner review of this audit, then Step 0+1 (Settings honesty including Experimental cleanup + real Blocks adapter) only when explicitly authorised. **STOP.** No runtime yet.
