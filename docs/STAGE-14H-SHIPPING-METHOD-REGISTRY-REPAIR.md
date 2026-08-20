# Shipping-method registry listing repair (post-RC.5)

**Status:** Included in owner QA `1.0.0-rc.6-qa.2` (first packaged in `1.0.0-rc.6-qa.1`) — not a replacement for tagged `v1.0.0-rc.5`; FLAIROC not deployed  
**Plugin version in tree:** `1.0.0-rc.6-qa.2`  
**Schema:** `4` (unchanged)  
**Date:** 2026-08-20  
**Package record:** `docs/STAGE-14H-RC6-QA1-OWNER-QA-PACKAGE.md`

## Defect

`delivery_engine_selected_offer` / **Delivery** was omitted from WooCommerce’s shipping-method registry unless storefront runtime flags were on. Clean installs therefore could not add Delivery in **Add shipping method** before **Activate Delivery Engine**. FLAIROC showed the method because production flags were already on.

## Repair

- Register `SelectedOfferShippingMethod` on `woocommerce_shipping_methods` whenever WooCommerce and the Delivery Engine are active.
- Keep `ShippingRateCalculationGate` on rate calculation and managed-package exclusivity.
- Do not auto-insert the method into any shipping zone.
- Setup/test wording: add Delivery only to the WooCommerce zones where Delivery Engine shipping should operate. Rest of the World is optional unless leftover addresses are intentionally supported.

## Intentionally unchanged

FLAIROC, schema 4, RC.5 tags/packages, Air/Sea architecture, inheritance, pricing, checkout grouping, shipments, tracking, Stage 15.

## Tests actually run

- PHPUnit focused: `SelectedOfferShippingRegistrationTest`, `WooCommerceShippingReadinessTest`, `ShippingPackageGroupingTest`, `ClassicCheckoutRuntimeActivationTest`, `Stage13FCustomerPresentationTest`, `RateQuoteSafetyTest`, `PluginBootServiceGraphTest`, `ForbiddenPrimaryUiTermsTest`, `AdminLanguagePresentationTest` — **56 tests, 475 assertions, OK** (2 pre-existing deprecations).
- PHPUnit shipping/cart/quote: `tests/Unit/Shipping`, `tests/Unit/Cart/VariableCartLifecycleTest.php`, `RateQuoteSafetyTest`, `ClassicCheckoutRuntimeActivationTest` — **45 tests, 122 assertions, OK**.
- Clean-install physical: WordPress + WooCommerce **11.0.1** + this plugin, all four runtime flags **0**. Registry listed `delivery_engine_selected_offer` / Delivery once; Rest of the World had no auto-assignment; adding the method to that zone succeeded; calculator returned `runtime_inactive`; native `flat_rate` package rates were preserved. **PHYSICAL_CLEAN_INSTALL_PASS**. Disposable Docker fixtures removed. FLAIROC not touched.

