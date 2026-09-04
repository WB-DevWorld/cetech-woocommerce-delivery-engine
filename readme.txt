=== CETECH WooCommerce Delivery Engine ===
Contributors: cetech
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0-dev.integrated.2
License: Proprietary
WC requires at least: 8.0
WC tested up to: 10.9

Delivery, fulfilment-choice, delivery-pricing, shipment-status, and tracking engine for WooCommerce.

== Description ==

CETECH WooCommerce Delivery Engine is a reusable commercial-style WooCommerce plugin that adds a structured delivery and fulfilment layer on top of WooCommerce.

**Hard dependency:** WooCommerce only.

**Optional integrations (not required):** WoodMart, WPML, WCML, WCFM, VitePOS, Redis, WP Rocket, WooCommerce Blocks, tracking plugins, and future carrier APIs.

This 0.1.0 release is the **Phase 1A core foundation skeleton**. It does not yet change product, cart, checkout, shipping, order, or customer-facing delivery behaviour.

== Version 1 exclusions ==

This plugin will not include in Version 1:

* Proof of delivery, buyer confirmation, OTP, QR, or GPS
* Driver accounts or driver apps
* Live carrier quotes, carrier API dispatch, or automatic tracking sync
* Automatic order completion from delivery events
* Warehouse scanning, returns automation, supplier portal, or courier marketplace

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/cetech-woocommerce-delivery-engine/`
2. Run `composer install` inside the plugin directory to generate the autoloader
3. Activate the plugin through the Plugins screen
4. Ensure WooCommerce is installed and active

== Frequently Asked Questions ==

= Does this plugin require WPML or WoodMart? =

No. WooCommerce is the only required dependency.

= Does this version change checkout shipping? =

No. Phase 1A is a safe core skeleton only.

== Changelog ==

= 1.0.0-dev.integrated.2 =
* Customer storefront presentation cleanup: one delivery summary, progressive Change editor, compact checkout confirmation, and simpler incomplete-address copy. Schema remains 5. Business logic, rates, snapshots, and tagged 1.0.0-rc.9 are unchanged. Not RC.10. Does not rebuild 1.0.0-dev.integrated.1.

= 1.0.0-dev.integrated.1 =
* Combined candidate: qualified per-item customer context plus WCFM vendor administrative isolation. Restricted vendors cannot POST admin actions even with stale Delivery Engine capabilities. Schema remains 5. Tagged 1.0.0-rc.9 is unchanged. Not RC.10.

= 1.0.0-dev.peritem.1 =
* Per-item customer context foundation on cartstate.1 plus blocks-snapshot.1. Schema remains 5. Tagged 1.0.0-rc.9 is unchanged. Not RC.10. No storefront UI.

= 1.0.0-dev.blocks-snapshot.1 =
* Native Blocks/Store API checkout now persists the same immutable per-line Delivery Engine snapshot as Classic after the order address is copied. Schema remains 5. Tagged 1.0.0-rc.9 is unchanged.

= 1.0.0-dev.cartstate.1 =
* Post-RC.9 cart-state reconciliation: existing cart lines refresh to live Delivery Engine configuration, stale admin fingerprints no longer force duplicate shipping groups, and unavailable choices require in-cart reselection instead of remove-and-re-add. Schema remains 5. Tagged 1.0.0-rc.9 is unchanged.

= 1.0.0-rc.9 =
* Promotes the frozen 1.0.0-dev.blocks.4 Cart/Checkout Blocks runtime to the tagged release candidate. Schema remains 5. No behaviour change versus Blocks.4. Not Stage 15. Historical 1.0.0-dev.blocks.1–blocks.4 packages and tagged 1.0.0-rc.8 remain unchanged.

= 1.0.0-dev.blocks.4 =
* Constrained Delivery Area fallback: a Fallback area with location rules stays inside those rules. Only a ruleless fallback is Everywhere else. Test an address uses the WooCommerce country selector. Schema remains 5. Tagged 1.0.0-rc.8 is unchanged.

= 1.0.0-dev.blocks.3 =
* Matched Delivery Area pricing fallback: a selected Delivery Option can use a broader matching area when the more-specific area has no rate card. Invalid specific-area rates stay fail-closed. Schema remains 5.

= 1.0.0-dev.blocks.2 =
* Blocks mixed Delivery + Pickup: a valid GHS 0 Store Pickup quote is no longer treated as a missing Delivery Engine rate. Fail-closed remains for unquoted managed Delivery packages. Schema remains 5.

= 1.0.0-dev.blocks.1 =
* Settings honesty: Site-wide Defaults and variation inheritance are no longer experimental checkboxes. Dead Bulk/category/fallback/demo checkboxes removed. Integrations report detection status instead of blind toggles. Real WooCommerce Cart and Checkout Blocks adapter (Store API, validation, snapshots) with Classic checkout unchanged. Schema remains 5. Tagged 1.0.0-rc.8 is unchanged.

= 1.0.0-rc.8 =
* Promotes the owner-accepted 1.0.0-dev.fulfilment.4 Fulfilment Correctness runtime to the tagged release candidate. Schema remains 5. No new features. Not Stage 15. Historical 1.0.0-dev.fulfilment.1–fulfilment.4 packages and tagged 1.0.0-rc.7 remain unchanged.

= 1.0.0-dev.fulfilment.4 =
* Post-RC.7 Scenario 1 mixed-cart repair: Store Pickup packages no longer render WooCommerce “Shipping to” customer destination or Change address. Delivery packages keep normal shipping destination copy. Schema remains 5. Tagged 1.0.0-rc.7 is unchanged.

= 1.0.0-dev.fulfilment.3 =
* Post-RC.7 Scenario 1 cart presentation repair: human-readable Pickup Location address (never serialized JSON) and Store Pickup groups labelled as pickup at the store, not shipping to the customer address. Schema remains 5. Tagged 1.0.0-rc.7 is unchanged.

= 1.0.0-dev.fulfilment.2 =
* Post-RC.7 Scenario 1 admin repair: In Store available methods vs default customer choice, ECR Pickup Location, Pickup Locations R1 (reference code + country selector), and pickup readiness wired to the existing column. Schema remains 5. Tagged 1.0.0-rc.7 is unchanged.

= 1.0.0-dev.fulfilment.1 =
* Post-RC.7 fulfilment correctness: In Store Delivery + Store Pickup as concurrent customer choices, International single-offer auto-select, and fail-closed native-rate filtering on Delivery Engine-managed packages. Schema remains 5. Tagged 1.0.0-rc.7 is unchanged.

= 1.0.0-rc.7 =
* Promotes the owner-accepted 1.0.0-dev.bulk.9 Bulk Tools + R1 training-site runtime to the tagged release candidate. Schema remains 5. No new features. Not Stage 15. Historical 1.0.0-dev.bulk.7–bulk.9 packages and tagged 1.0.0-rc.6 remain unchanged.

= 1.0.0-dev.bulk.9 =
* Consolidated Bulk.8 physical-QA repair: real read-only validation scans, catalog safety for the last valid Delivery Option, Rate Card rollback fingerprinting, entity presentation, coherent async status, and explicit charge increase/decrease operations. Schema remains 5. Not RC.6, not RC.7, not Stage 15. Historical 1.0.0-dev.bulk.7 and 1.0.0-dev.bulk.8 packages are unchanged.

= 1.0.0-dev.bulk.8 =
* Bulk Tools background-execution portability: async Action Scheduler enqueue, bounded admin continue, waiting/stale job states, and Needs Attention for stalled jobs. Schema remains 5. Not RC.6, not RC.7, not Stage 15. Historical 1.0.0-dev.bulk.7 is unchanged.

= 1.0.0-dev.bulk.7 =
* Combined post-RC.6 development stream: Bulk Tools schema 5 plus approved R1 admin/setup repairs, R1 QA.1 request-path repairs, and a single primary Create/Save action on Delivery Option and Delivery Area forms. Not RC.6, not RC.7, not Stage 15.

= 1.0.0-rc.6 =
* Final post-RC.5 defect-fix release candidate. Delivery is listed in WooCommerce Add shipping method while the plugin is active; rates stay flag-gated. Delivery Area Region rules match WooCommerce state codes or that country’s labels (Ghana AA / Greater Accra) without migrating stored area data. Schema target remains 4. Owner-accepted from 1.0.0-rc.6-qa.2. Not a rewrite of tagged v1.0.0-rc.5.

= 1.0.0-rc.6-qa.2 =
* Defect-fix QA after 1.0.0-rc.6-qa.1. Delivery Area Region rules match either the WooCommerce state code or the human-readable state label for that country (for example Ghana AA and Greater Accra). Existing code-saved rules still work. No Delivery Area data migration. Schema target remains 4. Keeps the QA.1 shipping-method registry repair. Not a replacement for tagged v1.0.0-rc.5.

= 1.0.0-rc.6-qa.1 =
* Defect-fix QA after protected 1.0.0-rc.5. Delivery is listed in WooCommerce Add shipping method while the plugin is active, even before checkout is activated. Rates stay flag-gated. Add Delivery only to the WooCommerce zones where this plugin should operate; Rest of the World is optional. Schema target remains 4. Not a replacement for tagged v1.0.0-rc.5.

= 1.0.0-rc.5 =
* Final Stage 14 release candidate. Shipment records, staff Shipments workspace, customer shipment cards, COD action-required queue, and quantity-aware refund review. Schema target 4. Feature flags remain off until an Administrator enables them in Settings. Owner-accepted from 1.0.0-rc.5-qa.6.

= 1.0.0-rc.5-qa.6 =
* Repair candidate after QA.5 owner FAIL. Amount-only WooCommerce refunds (no refund line items) keep shipment status and appear in Needs Attention as refund review. Explicit full item-quantity refunds before dispatch still auto-cancel. Schema target remains 4. Not the final RC.5 release.

= 1.0.0-rc.5-qa.5 =
* Owner QA.5 candidate. Cash on Delivery orders that need delivery wait in Needs Attention for staff to create the shipment from the historical order record. Automatic COD creation remains off. Later payment confirmation stays idempotent. Staff History shows a unique name plus WordPress user ID. Customer View Order shows one contained card per shipment. Schema target remains 4. Not the final RC.5 release.

= 1.0.0-rc.5-qa.4 =
* Owner QA polish candidate. Staff Shipment History shows the WordPress account name. Customer Track shipment is a button-style control. Delivery Engine menu badges show unresolved Needs Attention and per-user unreviewed shipment activity. Includes the QA.3 Site-wide inheritance repair. Schema target remains 4. Not the final RC.5 release.

= 1.0.0-rc.5-qa.3 =
* Repair candidate after QA.2 owner FAIL. Untouched products inherit Site-wide required delivery fields even when optional supplier/origin/logistics/priority values are absent. Schema target remains 4. Not the final RC.5 release.

= 1.0.0-rc.5-qa.2 =
* Repair candidate after QA.1 owner FAIL. Checkout/email/View Order no longer fatal on customer delivery summary. Shipment creation requires payment-complete or a persisted paid date, not WooCommerce is_paid() status. Failed owner QA on Site-wide inheritance; superseded by 1.0.0-rc.5-qa.3. Not the final RC.5 release.

= 1.0.0-rc.5-qa.1 =
* Owner QA candidate for Stage 14 shipment records. Schema target 4. Shipment tables pin InnoDB. Feature flags remain off until enabled in Settings. Failed owner QA; superseded by 1.0.0-rc.5-qa.2. Not the final RC.5 release.

= 1.0.0-rc.4 =
* Release candidate after Stage 13F customer-facing delivery presentation polish. Owner physical QA.1 (`1.0.0-rc.4-qa.1`) accepted on FLAIROC; no additional QA build required.
* Compact public Delivery option + Estimated delivery on product, thank-you, My Account, and customer emails; WooCommerce shipping rate label uses the selected public Delivery Option label.
* Schema target remains 3; no migration. Tagged v1.0.0-rc.3 is untouched. Existing configuration, flags, exceptions, and order snapshots are not reset on folder replace.

= 1.0.0-rc.3 =
* Release candidate after Stage 13 / 13B / 13C / 13D / 13D-R1: site-wide defaults, WordPress-native admin UX, Setup Guide, Overview, legacy retirement from the normal menu, real role Access controls, and Administrator access recovery hardening.
* Owner physical QA.4 and QA.5 acceptance passed on FLAIROC prior to this tagged package.
* Schema target remains 3; no migration for the version bump.
* Administrator remains a protected full-access role; subordinate WordPress roles remain configurable; recovery uses manage_options independently of Technical Diagnostics.
* Existing RC.2 configuration, flags, exceptions, and order snapshots are not reset on folder replace.
* Shipments, tracking, Blocks checkout, bulk import, and carrier APIs remain out of scope.

= 1.0.0-rc.3-qa.5 =
* Owner physical retest QA package after Stage 13D-R1 administrator access / recovery hardening. Superseded by tagged 1.0.0-rc.3.
* Administrator is a protected full-access role; Access matrix edits subordinate WordPress roles only.
* Independent Restore Administrator Access recovery uses manage_options + nonce and does not depend on Technical Diagnostics.
* Existing RC.2 / QA.4 configuration, flags, exceptions, and order snapshots are not reset on folder replace.

= 1.0.0-rc.3-qa.3 =
* Owner physical retest QA package after Stage 13C-R3 local repair. Not a tagged RC.3 release.
* Includes Setup Guide Step 3 validation, International Air/Sea guided UX, Preview variation loading, and variable-parent readiness repairs.
* Existing RC.2 / QA.2 configuration, flags, exceptions, and order snapshots are not reset on folder replace.

= 1.0.0-rc.3-qa.2 =
* Owner physical retest QA package after Stage 13C-R1 local repair. Not a tagged RC.3 release.
* Includes wizard routing, capability self-heal, authoritative operational state, Preview/Needs Attention readiness, and staff UI privacy/formatting repairs.
* Existing RC.2 / QA.1 configuration, flags, exceptions, and order snapshots are not reset on folder replace.

= 1.0.0-rc.3-qa.1 =
* Owner-review QA package of Stage 13 / 13B-R2. Not a tagged RC.3 release.
* Adds site-wide fulfilment defaults, Setup Guide, Overview, and staff-facing admin UX on schema 3.
* Existing RC.2 configuration, flags, exceptions, and order snapshots are not reset on activation.

= 1.0.0-rc.2 =
* Classic Checkout release candidate through Stage 8C.
* Includes Stage 6 variable support, Stage 6C presentation cleanup, Stage 8 multi-product grouping, and Stage 8C order-admin technical meta cleanup.
* Delivery Settings is the primary admin workflow; Legacy Delivery Rules remain for migration/compatibility.
* Shipment records, tracking, Blocks checkout, and carrier APIs remain out of scope.

= 1.0.0-rc.1 =
* Release-candidate build for CETECH WooCommerce Delivery Engine V1.
* Adds delivery configuration, delivery offers, destination zones/rules, rate cards, and product delivery rules.
* Adds feature-flagged product delivery selector, cart selection capture, checkout validation, selected-offer shipping method, protected order snapshots, admin order snapshot display, customer order summary, and customer email summary.
* Shipment records, tracking timelines, carrier APIs, driver workflows, OTP/QR/GPS/POD, WooCommerce Blocks checkout support, and automatic order completion are not included in this RC.

= 0.1.0 =
* Phase 1A core foundation skeleton
* Bootstrap, feature flags, capabilities, HPOS compatibility declaration, integration registry placeholder
