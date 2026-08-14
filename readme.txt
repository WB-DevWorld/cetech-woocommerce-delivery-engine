=== CETECH WooCommerce Delivery Engine ===
Contributors: cetech
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0-rc.3
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
