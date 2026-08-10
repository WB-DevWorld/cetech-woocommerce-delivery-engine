# CETECH WooCommerce Delivery Engine — Post-RC Baseline Verification

## Date

2026-08-10

## Repository baseline

| Item | Value |
|------|-------|
| Repository root | `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-woocommerce-delivery-engine` |
| Branch | `master` |
| HEAD | `d68d2af64bca5ee42dd3e2cd2fed28e93a208a54` — *V1 RC: document current implementation status in AI handoff* |
| Runtime / code baseline commit | `8951adb677919e39a5143d31aeae1bcd9673e04a` — *V1 RC: add guarded admin delete actions* |
| Diff `8951adb..HEAD` (runtime) | **None** — only `docs/AI-HANDOFF.md` changed |
| Remote | `origin` → tracks `origin/master` |
| Ahead / behind | `0 / 0` (in sync) |
| Working tree at verification start | Dirty with **known untracked docs/governance only** (no unknown runtime code edits): `.cursor/rules/project-governance.mdc`, `docs/PROJECT-GOVERNANCE.md`, `docs/Delivery Shipping Plugin Up-To-Date Design and Expectations.md` |
| `vendor/` | Present (gitignored); Composer PSR-4 maps `CetechDeliveryEngine\` → `src/` |
| `dist/` | Present (gitignored); contains `cetech-woocommerce-delivery-engine-v1.0.0-rc.1.zip` (+ sha256) dated 2026-07-06 |

**Material runtime drift from historical `1.0.0-rc.1` / `8951adb`:** None. HEAD is documentation-only beyond that commit.

## Plugin identity

| Property | Actual value |
|----------|--------------|
| Plugin name | CETECH WooCommerce Delivery Engine |
| Version | `1.0.0-rc.1` (`CETECH_DE_VERSION` + plugin header + `readme.txt` Stable tag) |
| Slug / text domain | `cetech-woocommerce-delivery-engine` |
| Namespace | `CetechDeliveryEngine\` |
| PHP minimum | 8.1 |
| WooCommerce | Hard dependency; header `WC requires at least: 8.0`, `WC tested up to: 10.9` |
| HPOS | Declared via `FeaturesCompatibility::declare_compatibility('custom_order_tables', …, true)` |
| Schema option | `cetech_de_db_version` |
| Schema target | `2` (`SchemaVersion::TARGET`) |
| Composer | `composer.json` valid; optimized autoload generated (132 classes) |

## Current implemented scope

V1 RC supports a **feature-flagged** simple-product path:

```text
Product delivery rule
→ eligible Delivery Offers
→ product-page selector (public-safe)
→ Add to Cart capture + cart/session persistence
→ cart revalidation (warn; no silent replace)
→ classic checkout validation
→ destination zone + rate-card quote
→ WC shipping method `delivery_engine_selected_offer` (label “Delivery”)
→ protected order/item delivery snapshots (HPOS CRUD)
→ admin order snapshot meta box
→ customer Thank You / My Account summary
→ customer email summary
```

Admin configuration CRUD for offers, zones/rules, logistics profiles, suppliers, origins, pickup locations, rate cards, audit log, and product delivery rules is implemented.

**Not implemented:** shipment tables/runtime, tracking, customer timeline, Blocks adapter wiring, variable-product capture, real optional-integration adapters (WPML/WCML/WoodMart/WCFM/VitePOS), `EffectiveConfigurationResolver`, inheritance redesign.

## Current database tables

Option key: `cetech_de_db_version` → target **`2`**.

Migrations (idempotent `dbDelta` + `VerifiableMigrationInterface`; retry-safe skip when `version <= current`; failed migrations do not bump version):

| Migration | Version | Tables |
|-----------|---------|--------|
| `20260705160000_create_configuration_tables` | `1` | `delivery_offers`, `destination_zones`, `destination_rules`, `logistics_profiles`, `suppliers`, `origins`, `pickup_locations`, `rate_cards`, `rate_card_rules`, `audit_log` |
| `20260705170000_create_product_delivery_rules_table` | `2` | `product_delivery_rules` |

All use prefix `{$wpdb->prefix}delivery_engine_*` via `TableNames`.

**Confirmed absent from schema code:** `shipments`, `shipment_items`, `shipment_events`.

**Activation:** capabilities + feature-flag defaults + migrations (`Activator`).  
**Deactivation:** does **not** delete data (`Deactivator` flushes rewrites / notice transient only).  
**Uninstall:** data retained unless `cetech_de_delete_data_on_uninstall` is explicitly enabled.

Indexes: primary keys, unique `internal_code` where applicable, status/route/zone/target lookup keys as defined in migration SQL (verified in migration files).

## Feature flags

Source: `src/Bootstrap/FeatureFlags.php` (`cetech_de_<flag>` in `wp_options`).

| Flag | Default | Runtime implemented? | Notes |
|------|---------|----------------------|-------|
| `enable_product_delivery_selector` | false | Yes | Product-page selector |
| `enable_cart_delivery_selection_capture` | false | Yes | Requires selector; simple products |
| `enable_checkout_delivery_selection_validation` | false | Yes | Classic `woocommerce_after_checkout_validation` |
| `enable_woocommerce_shipping_rate_calculation` | false | Yes | Requires upstream selector+capture+checkout flags |
| `enable_order_delivery_snapshot_persistence` | false | Yes | Requires shipping runtime chain |
| `enable_customer_order_delivery_summary` | false | Yes | Read-only thank-you / My Account |
| `enable_customer_email_delivery_summary` | false | Yes | Customer emails only |
| `enable_shipment_records` | false | No (reserved) | No runtime wiring |
| `enable_tracking_links` | false | No (reserved) | No runtime wiring |
| `enable_customer_timeline` | false | No (reserved) | No runtime wiring |
| `enable_blocks_adapter` | false | No | Flag only; no Blocks adapter classes |
| `enable_classic_checkout_adapter` | **true** | Placeholder | Settings/display only; classic path is de facto |
| `enable_wpml_adapter` | false | Detection/stub only | `NullIntegration` |
| `enable_wcml_adapter` | false | Detection/stub only | |
| `enable_woodmart_adapter` | false | Detection/stub only | |
| `enable_wcfm_adapter` | false | Detection/stub only | |
| `enable_vitepos_adapter` | false | Detection/stub only | |
| `enable_bulk_import` | false | Not a storefront takeover | |
| `enable_category_rules` | false | Partial / settings | Resolver still walks categories; flag not a hard gate in resolver |
| `enable_site_fallback_rule` | false | Not fully wired as resolver fallback | |
| `demo_data_on_activation` | false | Off | No demo seeding observed on activation |

**Storefront/runtime-impacting flags default OFF** (except the non-operative classic-checkout placeholder default **true**). Activation calls `ensure_defaults()` and does not enable customer runtime flags.

### Drift vs `docs/V1-RC-FLAG-MATRIX.md`

- Flag matrix header still says **Version: 0.1.0**; plugin is **`1.0.0-rc.1`**.
- Matrix flag list otherwise matches code defaults for V1 runtime/reserved flags.

## Runtime pipeline map (code)

| Step | Principal class(es) | Hooks / filters | Persistence | Flag |
|------|---------------------|-----------------|-------------|------|
| Product rule | `ProductDeliveryRuleResolver` | (called by consumers) | `delivery_engine_product_delivery_rules` | — |
| Eligible offers | `ProductDeliveryOptionsBuilder` | — | `delivery_engine_delivery_offers` | via selector |
| Product selector | `ProductDeliverySelectorRenderer` | `woocommerce_single_product_summary` / before ATC | none | `enable_product_delivery_selector` |
| ATC capture | `CartDeliverySelectionCapture`, `ProductDeliverySelectionValidator` | `woocommerce_add_to_cart_validation` | POST key only | capture + selector |
| Cart/session | same + fingerprint/session helpers | `woocommerce_add_cart_item_data`, `get_cart_item_from_session`, `get_item_data` | WC cart/session keys `cetech_de_delivery_selection*` | capture + selector |
| Cart revalidation | `CartDeliverySelectionRevalidator` | `woocommerce_before_cart` | read-only | capture + selector |
| Checkout validation | `CheckoutDeliverySelectionValidator` | `woocommerce_after_checkout_validation` | none | checkout + upstream |
| Destination/rate | `PackageDestinationZoneResolver`, `RateQuoteEngine` | via shipping | zones/rules/rate cards | via shipping |
| WC shipping | `SelectedOfferShippingMethod`, `SelectedOfferShippingRateCalculator`, `ShippingRateCalculationGate` | `woocommerce_shipping_methods`, method `calculate_shipping` | WC rates | `enable_woocommerce_shipping_rate_calculation` + upstream |
| Order snapshot | `OrderDeliverySnapshotPersister`, `OrderDeliverySnapshotBuilder`, `OrderDeliverySnapshotGate` | `woocommerce_checkout_create_order_line_item`, `woocommerce_checkout_order_created` | `_cetech_de_*` meta | snapshot + shipping chain |
| Admin order | `OrderDeliverySnapshotAdminDisplay` | `add_meta_boxes` | read meta | always registered in admin when WC active |
| Thank You / My Account | `CustomerOrderDeliverySummaryBuilder` / `Renderer` | `woocommerce_order_details_after_order_table` | read meta | `enable_customer_order_delivery_summary` |
| Email | `CustomerOrderDeliveryEmailSummaryRenderer` | `woocommerce_email_after_order_table` | read meta | `enable_customer_email_delivery_summary` |

**Automated tests covering pipeline:** none in repository.

## Automated verification

| Test / check | Command | Result |
|--------------|---------|--------|
| Composer validate | `composer validate --no-check-publish` | **PASS** (exit 0; `composer.json` valid) |
| Composer dump-autoload | `composer dump-autoload -o` | **PASS** (exit 0; 132 classes) |
| PHP syntax lint (root) | `php -l` on bootstrap, uninstall, FeatureFlags | **PASS** |
| PHP syntax lint (tree) | `php -l` on all `src/**/*.php` + `database/**/*.php` | **PASS** (133 OK / 0 FAIL) |
| PHPUnit | (none configured) | **NOT APPLICABLE** — no `tests/`, no `phpunit.xml`, no Composer test scripts |
| PHPCS / PHPStan / Psalm | (none configured) | **NOT APPLICABLE** |
| CI workflows | (none) | **NOT APPLICABLE** — no `.github/` workflows |
| JS lint/build | (none) | **NOT APPLICABLE** — no `package.json` frontend test suite |
| Packaging script version default | `scripts/build-v1-rc-package.ps1` | **PASS** — default `-Version 1.0.0-rc.1` |

## Staging environment

| Item | Result |
|------|--------|
| Documented staging URL in tracked docs | **Not present** (generic “staging” only) |
| Local `.env.local` (gitignored) | Present; keys include `WP_SITE_URL`, admin user, app password, WC API keys (**secrets not recorded here**) |
| Resolved site URL from local env | `https://training.cetechbpa.com` |
| Production distinction | Hostname `training.*` is consistent with historical staging naming; **live site identity could not be confirmed** because HTTPS failed |
| Reachability | DNS resolves (Cloudflare). HTTPS returns **HTTP 525** from Cloudflare (origin SSL handshake failure) for both normal and insecure client probes |
| Plugin version / WC / HPOS / theme / flags / DB version on staging | **NOT VERIFIED** — site unreachable |
| Test product `#30118` | Referenced historically in agent notes only; **not in tracked docs**; unreachable |

**No staging configuration flags were changed** (site unreachable).

## Smoke-test results

Authoritative checklist: `docs/V1-RC-SMOKE-TEST-CHECKLIST.md`.

All live storefront/admin/order/email rows are **BLOCKED** by staging HTTPS 525. Code-only rows that do not require staging are noted.

| Checklist section | Result | Notes / evidence |
|-------------------|--------|------------------|
| Baseline — all flags off (activate, no storefront takeover, no rates/snapshots/summaries) | **BLOCKED** | Cannot reach staging admin/storefront |
| Selector only | **BLOCKED** | |
| Selector + capture | **BLOCKED** | |
| Cart restore / revalidation | **BLOCKED** | |
| Checkout validation | **BLOCKED** | |
| Shipping method + rate card | **BLOCKED** | |
| Missing rate card → no free shipping | **BLOCKED** (runtime); code path reviewed → calculator returns `blocked`, method does not `add_rate` on failure | Code supports expected behaviour; not live-proven |
| Unresolved destination → no rate | **BLOCKED** (runtime); code: `BLOCK_DESTINATION_UNRESOLVED` | Same |
| Order snapshot persistence | **BLOCKED** | |
| Admin order snapshot display | **BLOCKED** | |
| Customer order summary | **BLOCKED** | |
| Customer email summary | **BLOCKED** | |
| HPOS smoke test | **BLOCKED** | Code declares HPOS compatibility |
| Privacy scan | **BLOCKED** | Customer renderers filter private fields in code |
| System Status / diagnostics | **BLOCKED** | |
| Sign-off | **Fail / incomplete** | Staging unavailable |

**Counts:** PASS `0` · FAIL `0` · BLOCKED `all executable staging rows` · NOT APPLICABLE `automated PHPUnit suite (absent by design of current repo)`

## Hard-invariant verification

| Invariant | Verdict | Evidence |
|-----------|---------|----------|
| **A. Server authority** | **PASS** | Capture/validator re-resolve options server-side; shipping uses `RateQuoteEngine`; no trusted client price path found |
| **B. No silent Delivery Offer replacement** | **PASS** | Revalidator warns; checkout blocks; shipping/snapshot skip/block stale — no auto-swap to another offer |
| **C. No accidental free shipping** | **PASS** | Missing rate / unresolved zone / quote failure → `SelectedOfferShippingRateResult::blocked` → shipping method returns without `add_rate`. Note: admin may intentionally configure `base_amount >= 0` (including $0); that is configured free shipping, not silent fallback on missing config |
| **D. Customer privacy** | **PARTIAL** | Customer summary/email/shipping label omit supplier/origin/LP/rate-card IDs/hashes. Residual: product selector display keys encode offer ID suffix in HTML/POST; protected cart intent + snapshot JSON retain internal IDs for ops (underscore meta / session) |
| **E. HPOS** | **PASS** | `FeaturesCompatibility` declares `custom_order_tables`; snapshot read/write use WC order CRUD |
| **F. Protected snapshot metadata** | **PARTIAL** | Keys use `_cetech_de_*` convention (`OrderDeliverySnapshot` constants). No plugin registration of `is_protected_meta` / `woocommerce_hidden_order_itemmeta` found |
| **G. Feature-flag safety** | **PASS** | Runtime customer flags default false; activation ensures defaults; shipping/snapshot gated on upstream chain |

## Confirmed limitations

| Limitation | Status |
|------------|--------|
| 1. Variable-product delivery capture incomplete | **Confirmed** — selector notice; capture excludes variable |
| 2. Mixed-cart line quotes may diverge from WC shipping-line totals | **Confirmed** — per-line quotes summed into package rate; known RC note |
| 3. Classic checkout is supported path | **Confirmed** |
| 4. Blocks adapter not fully wired | **Confirmed** — flag off, no adapter classes |
| 5. Shipment tables/runtime absent | **Confirmed** |
| 6. Tracking / customer timeline absent | **Confirmed** |
| 7. Optional real WPML/WCML/WCFM/VitePOS/WoodMart adapters not implemented | **Confirmed** — detection / Null stubs |
| 8. Simple products are primary V1 test case | **Confirmed** |

## Documentation/code drift

1. **`docs/V1-RC-FLAG-MATRIX.md`** header version still `0.1.0` vs plugin `1.0.0-rc.1`.
2. **Phase implementation docs** (e.g. 1A, 2H4) still show **Plugin version: 0.1.0** in headers while RC release notes / plugin header are `1.0.0-rc.1`.
3. **`readme.txt`** Stable tag is `1.0.0-rc.1`, but earlier description prose still mentions Phase 1A / `0.1.0` wording.
4. **`.cursor/rules/project-governance.mdc` frontmatter is invalid for Cursor YAML rules:** file uses `## alwaysApply: true` inside an opened `---` block instead of YAML `alwaysApply: true` closed by `---`. Rule **does** reference `docs/PROJECT-GOVERNANCE.md`. Reported only — not rewritten in Stage 0.
5. **Staging URL / test product IDs** are absent from tracked docs (live only in local `.env.local` / historical notes).
6. **ARCHITECTURE-PLAN.md** still describes future shipment pipeline; AI handoff RC status correctly limits current scope — no code contradiction for shipments (absent).
7. **No automated test suite** exists despite handoff/testing doctrine describing desired unit/integration tests — documented gap, not a silent claim of green PHPUnit.
8. Untracked at verification: governance files + Design Expectations doc (intended project docs; not runtime drift).

## Governance files check

| File | Status |
|------|--------|
| `docs/PROJECT-GOVERNANCE.md` | Exists, readable |
| `.cursor/rules/project-governance.mdc` | Exists, readable; references governance doc; **`alwaysApply` not valid YAML frontmatter** (see drift #4) |

## Baseline verdict

**BASELINE NOT VERIFIED — POST-RC DEVELOPMENT BLOCKED**

**Reason:** Repository identity, schema, flags, pipeline mapping, Composer/PHP integrity, and code-level invariant review are consistent with the documented `1.0.0-rc.1` / schema `2` RC at runtime commit `8951adb`. However, the mandatory V1 RC staging smoke checklist could not be executed: `https://training.cetechbpa.com` returns Cloudflare **HTTP 525** (origin SSL failure). Without a completed staging smoke pass, the baseline is not indisputable.

## Required next action

Restore staging HTTPS reachability for `https://training.cetechbpa.com` (fix origin SSL / Cloudflare 525), then re-run `docs/V1-RC-SMOKE-TEST-CHECKLIST.md` end-to-end and update this report to VERIFIED before Stage 1.

Do not begin Stage 1 architecture gap analysis until smoke sign-off passes.
