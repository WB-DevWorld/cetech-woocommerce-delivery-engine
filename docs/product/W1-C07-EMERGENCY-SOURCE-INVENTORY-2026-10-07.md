# C07 emergency-control source inventory

Inspected actual C06 merge `9cbdc6b5f0b9a509cad137d7379d96809f6d240e`, tree `66536ad4e5d47d820baa18569c729357248972ed`. Design-only, no C07 source/runtime changes or executed future cases. Proposed contract: [W1-C07-EMERGENCY-CONTROL-1](W1-C07-EMERGENCY-CONTROL-DESIGN-2026-10-07.md).

## Exact23 existing flags

All defaults false except enable_classic_checkout_adapter=true. `src/Bootstrap/FeatureFlags.php` uses individual get_option reads and an instance-memoized all() array. Neither is proposed as final emergency authority.

| Flag | Inspected role; proposed disposition |
|---|---|
| enable_product_delivery_selector | Product/variation/location choices; suspension override at application boundary |
| enable_cart_delivery_selection_capture | Capture/reconcile/revalidate/reselect/context paths; keep ownership/admission live |
| enable_checkout_delivery_selection_validation | Classic/Blocks delegates; independent emergency guard cannot depend on this gate |
| enable_woocommerce_shipping_rate_calculation | Shipping gate/calculator; suppress managed choices/rates without native fallback |
| enable_order_delivery_snapshot_persistence | Existing snapshot gate/writer; preserve flags and V1/V2 semantics |
| enable_customer_order_delivery_summary | Existing historical summary remains available |
| enable_customer_email_delivery_summary | Existing historical email summary remains available |
| enable_shipment_records | Paid/manual/history services remain under own authority |
| enable_customer_timeline | Future/unavailable; no new adopter |
| enable_tracking_links | Existing shipment presentation remains available |
| enable_wpml_adapter | Stored/reserved; no new adopter |
| enable_wcml_adapter | Stored/reserved; no new adopter |
| enable_woodmart_adapter | Stored/reserved; existing generic hooks unchanged |
| enable_wcfm_adapter | Stored/reserved; admin vendor isolation stays independent |
| enable_vitepos_adapter | Stored/reserved; no new adopter |
| enable_bulk_import | Stored unused; Bulk Tools capability behavior unchanged |
| enable_classic_checkout_adapter | Existing default/readiness/activation role; not a standalone hook switch |
| enable_blocks_adapter | Stored but Blocks registers by availability; do not silently reinterpret |
| enable_category_rules | Label without runtime flag reader; current category resolver unchanged |
| enable_site_fallback_rule | Label without runtime flag reader; current resolver unchanged |
| enable_effective_configuration_runtime | Current ECR/legacy cutover; classifier must distinguish no-match from refusal |
| enable_variable_product_ecr_runtime | Variation cutover; exact parent identity still required |
| demo_data_on_activation | Stored unused; no new seeding |

`src/Application/Configuration/ClassicCheckoutRuntimeActivation.php::CHAIN` sequentially enables selector/capture/validation/shipping/snapshot/Classic/ECR/variationECR. It is not an atomic audited emergency transition. `src/Presentation/Admin/DeliverySettingsPage.php` ordinary save writes five flags and current uninstall/access settings; C07 needs separate nonce/revision/replay handling.

## Exact adoption boundaries

| Source | Existing behavior and required future bridge |
|---|---|
| src/Bootstrap/Plugin.php | Runtime wiring; keep emergency/ownership/final guards registered regardless of old checkout flags |
| src/Presentation/Frontend/ProductDeliverySelectorRenderer.php; src/Presentation/Frontend/VariableDeliverySelectorAssets.php | Paused new choices receive safe unavailability, not accepted hidden options |
| VariationDeliveryOptionsEndpoint; ProductDeliverySelectionValidator; MatchingLocationOptionsEndpoint | Existing selector/auth/server validation paths; explicitly inventory final implementation methods and rates |
| src/Application/Cart/CartDeliverySelectionCapture.php | assess_product_selection conflates failed/no/empty resolution into none; restoration can discard malformed owned markers; add tri-state ownership/latch before discard |
| CartDeliverySelectionReconciler; CartDeliverySelectionRevalidator; CartDeliveryReselectionService; CartCustomerContextEditorService | Preserve draft address editing; refuse new choice acceptance/quote while paused; re-enable current validation |
| src/Application/Shipping/ShippingRateCalculationGate.php | Requires selector/capture/validation/shipping flags; inactive result is not emergency proof |
| src/Application/Shipping/ShippingPackageBuilder.php::filter_packages | Inactive returns original packages; invalid grouped lines may become residual; retain owned refusal identity |
| src/Application/Shipping/SelectedOfferShippingIntegration.php::filter_managed_package_rates | Active owns rates, inactive preserves supplied native rates; emergency must be independent |
| SelectedOfferShippingRateCalculator; src/Infrastructure/WooCommerce/Shipping/SelectedOfferShippingMethod.php | Current quote/shipping path; genuine enabled pickup may be zero, suspension must not fabricate one |
| src/Application/Checkout/CheckoutDeliverySelectionValidator.php::register/validate_checkout | Classic after-checkout validation registration/handler gated; add always-live early/final control bridges |
| src/Integrations/Blocks/BlocksCheckoutValidation.php | update_order_from_request priority5 and cart hooks return early when Classic validation is off; never equate provisional update with admission |
| src/Integrations/Blocks/BlocksCheckoutAdapter.php; BlocksStoreApiExtension | Existing automatic registration/server extensions; native Blocks and direct API need same final contract |
| src/Application/Shipping/DeliveryGroupIdentity.php | Existing managed marker alone is not an unforgeable unmanaged classifier |
| src/Application/Order/OrderDeliverySnapshotPersister.php | Classic/StoreAPI can persist before final admission; keep provisional limitation and COR029 reservation explicit |
| src/Application/Shipment/PaidOrderShipmentSubscriber.php | payment_complete/status with persisted paid evidence; continue existing authorized work |
| src/Application/Shipment/CodAwaitingShipmentSubscriber.php | checkout_order_created20 can update a manual-action index before final admission; does not create shipments |
| src/Presentation/Admin/AdminActionHandler.php; AdminPageAccess; WcfmVendorIsolation | Current manage_delivery_settings, nonce/admin/site/current-principal/vendor boundaries |
| src/Presentation/Admin/SystemStatusPage.php; OperationalStateService; ConfigurationHealthChecker | Diagnostics view_delivery_diagnostics; introduce explicit current state/impact observation |
| src/Application/Operation/OperationCoordinator.php; Domain/Operation/OperationMaterialEvent.php/OperationSchema.php | Existing same-unit material event/completion, replay/reconcile; revisions begin1; finite typed facts/no arbitrary datetime strings |
| src/Infrastructure/Persistence/DataLifecycleOptionsStore.php | Finite C06 write namespace; must not become a generic C07 option writer |
| C02 DecisionContextAdapter/DecisionProjection/DecisionTarget | Internal privacy/projection patterns; no existing public emergency UI, product target inappropriate for site control |

## Pinned WooCommerce11.1.2 hook/cache facts

Primary sources inspected:

- [Classic checkout](https://github.com/woocommerce/woocommerce/blob/11.1.2/plugins/woocommerce/includes/class-wc-checkout.php): process_checkout calls checkout_order_processed before payment and without-payment branches. Classic can empty its cart before that hook; persisted order items/server latch are needed.
- [Store API checkout](https://github.com/woocommerce/woocommerce/blob/11.1.2/plugins/woocommerce/src/StoreApi/Routes/V1/Checkout.php): process_order's checkout_order_processed precedes payment/without-payment. Earlier order/draft updates and status callbacks can already persist.
- [Store API checkout trait](https://github.com/woocommerce/woocommerce/blob/11.1.2/plugins/woocommerce/src/StoreApi/Utilities/CheckoutTrait.php): update_order_from_request participates in provisional updates; cannot mint final admission.
- [Shipping](https://github.com/woocommerce/woocommerce/blob/11.1.2/plugins/woocommerce/includes/class-wc-shipping.php): package_rates filter runs only on recalculation; warmed session rates can bypass it. Managed package revision/state must affect package hash, with independent final verification.
- [Order payment](https://github.com/woocommerce/woocommerce/blob/11.1.2/plugins/woocommerce/includes/class-wc-form-handler.php): pay_action bypasses checkout processed hooks; before_pay_action follows Woo nonce/order-key checks and precedes gateway, but is outside the payment try/catch. The proposal explicitly includes unpaid managed payments and requires a controlled notice/redirect/termination adapter, not return or an uncaught generic exception.

These are source facts, not new live Woo executions. Supported callback ordering/paid/zero-total/nativeorder-pay behavior must be pinned and physically qualified during implementation; arbitrary third-party later callbacks are not a guaranteed universal gate.

## Persistent inventory and requirement mapping

Only proposed new storage is cetech_de_checkout_control_v1, preserved autoload-off authored operational control, plus accepted C03 profile-specific completion/events in already-preserved stores. No new table/order metadata/snapshot writer/file/queue ownership. All32 domain stores and existing flags/C06 coordinators/COR007 history/Woo order facts are preserved on ordinary cleanup and both uninstall paths.

Existing primary C07 traceability rows: DE-API-002, DE-API-012, DE-FLAG-001–005, DE-RULE-007. Cross-cutting: DE-FAM-005, DE-OWN-001/003, DE-SEC-001/003/007/010. Additional source boundaries cited without completion promotion: DE-COMPAT-001–003, DE-DIAG-005–007, DE-SEC-002/004–006, DE-PERF-001/006/007/009. Frozen FLAG rows remain PARTIAL_IMPLEMENTATION. T18/T19/T20/T21 map to the design's thirty **future, unexecuted** cases.

Current theme/multicurrency/persistent-cache and broader public API classifications stay as recorded. Current-site all-or-none qualification is not numerical cohort rollout or multisite routing certification. Owner-facing implementation approval is W1-C07-EMERGENCY-CONTROL-1, not blanket adoption of reserved flags/business contracts.
