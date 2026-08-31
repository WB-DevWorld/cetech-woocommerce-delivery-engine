# POST-RC.7 Fulfilment Correctness — Scenario 1 Cart Presentation Repair

**Document status:** Implementation/repair note. Replacement QA ZIP packaged; not deployed.  
**Date:** 2026-08-31  
**Branch:** `feat/post-rc7-fulfilment-correctness`  
**Starting source:** tagged `1.0.0-rc.7` / `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6`  
**Previous packaged identity:** `1.0.0-dev.fulfilment.3` (Pickup-only cart accepted; mixed cart still printed WooCommerce destination copy)  
**Development identity:** `1.0.0-dev.fulfilment.4`  
**Schema target:** `5` (unchanged; no schema 6)  
**Protected tag:** `v1.0.0-rc.7` still peels to `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6`  
**FLAIROC:** not modified  
**Package:** `cetech-woocommerce-delivery-engine-1.0.0-dev.fulfilment.4.zip` — see `docs/POST-RC7-FULFILMENT-CORRECTNESS-QA.md`. Not deployed. Not RC.8.

```text
READ → AUDIT → PLAN → IMPLEMENT → TEST → DOCUMENT → REVIEW → STOP
```

Physical QA of `1.0.0-dev.fulfilment.2` confirmed In Store Delivery + Store Pickup architecture: both methods enabled, Delivery default, Pickup Location through ECR, both options on PDP, separate cart persistence, pickup readiness/location/instructions persist, Store Pickup adds zero delivery charge.

Two customer-facing cart defects remained. This repair is presentation only. It does not redesign grouping, quoting, ECR Pickup Location, or shipment persistence.

---

## Exact defects repaired

### 1. Pickup address was serialized JSON

Stored `pickup_locations.public_address` remains structured JSON (`line1`, `city`, `region`, `country_code`, …). After Pickup Locations R1, that JSON was copied into the customer cart summary and printed as-is.

Repair:

- `PickupLocationAddressFormatter` turns the stored structured value into human-readable copy, e.g. `No. 31 Papafio Hills Road, Ashaley Botwe, School Junction, Accra, Greater Accra, Ghana`.
- Country ISO-2 (`GH`) becomes the catalog label (`Ghana`). Parenthetical catalog noise is stripped.
- Legacy plain-text addresses pass through.
- Values that look like JSON but cannot be decoded are hidden, never shown raw.
- Display-time formatting also repairs cart sessions that already stored JSON.
- Internal structured storage is unchanged.

### 2. Store Pickup groups said “Shipping to [customer shipping address]”

WooCommerce `cart/cart-shipping.php` always prints the customer shipping destination. Pickup packages cloned that destination for quoting, so the pickup group looked like a delivery shipment.

Repair (presentation filters only):

- Pickup heading: `Pickup at {location name}` (fallback `Store Pickup`). Neutral fulfilment-group label instead of `Shipment for Store Pickup` / implying a delivery shipment.
- Pickup formatted destination: the effective Pickup Location address, never the customer shipping address.
- Scoped gettext while a pickup package is rendering: `Shipping to %s.` → `Pickup address: %s.`
- Delivery packages keep normal `Shipping to …` / WooCommerce package-name copy.
- Mixed carts render each group independently.
- Pickup charge remains explicit `0.0000`. Package destination used for quoting is not rewritten. Shipment persistence is unchanged.

WooCommerce’s `woocommerce_shipping_formatted_destination` second argument is the raw destination address, not the package. `cart/cart-shipping.php` also formats `$formatted_destination` **before** the package-name filter, so mixed carts still printed `Shipping to [customer address]` plus `Change address`.

Follow-up (`1.0.0-dev.fulfilment.4`):

- Stash the pickup package on `woocommerce_before_template_part` for `cart/cart-shipping.php` before destination copy is built.
- Hide the shipping calculator for pickup packages (`woocommerce_shipping_show_shipping_calculator`).
- Rewrite pickup package HTML so it cannot keep `Shipping to …` or `Change address`.
- Delivery packages are not rewritten and keep `Shipping to …` / `Change address`.

---

## Intentionally unchanged

- Delivery + Pickup availability; Delivery as default
- ECR `pickup_location_id`
- Zero pickup charge; no delivery shipment for Pickup
- Cart-line separation by fulfilment choice
- International / In Warehouse hard constraints
- Schema `5`
- Pickup Locations admin / PDP configuration from fulfilment.2
- Shipment persistence architecture
- No RC.8, FLAIROC, Bulk, per-item locations, Return/Refund, Checkout Blocks, International expansion

---

## Files

- `src/Application/Pickup/PickupLocationAddressFormatter.php` — customer address copy
- `src/Presentation/Frontend/CartFulfilmentPackagePresentation.php` — package heading / destination
- `src/Application/Selector/ProductDeliveryOptionsBuilder.php` — format address when building Store Pickup
- `src/Presentation/Shared/DeliveryPresentationLabels.php` — format address in public summary rows
- `src/Application/Shipping/ShippingPackageBuilder.php` — stash formatted pickup meta on managed packages
- `src/Presentation/Frontend/ProductDeliverySelectorRenderer.php` — PDP pickup address uses the formatter
- `src/Bootstrap/Plugin.php` — register presentation filters after package split
- `tests/Unit/Selector/PostRc7CartPickupPresentationTest.php`

---

## Tests

Focused coverage:

- structured pickup address formatted; never serialized JSON
- JSON already in a cart summary is repaired at display time
- pickup cart group does not use the customer shipping destination
- Delivery group still shows the normal shipping destination
- mixed Delivery + Pickup cart renders each group correctly
- WooCommerce cart-shipping filter argument order (raw address ≠ package)
- Pickup-only package quote is `0.0000`

---

## Owner recheck

Do **not** redo Pickup Location admin, PDP configuration, or the Pickup-only cart.

Confirm only **one mixed Delivery + Pickup cart screenshot**:

- Pickup group: `Pickup at CETECH Accra Store` and human-readable pickup address
- Pickup group does **not** say `Shipping to …` and has no `Change address`
- Delivery group still shows Standard Delivery, the delivery charge, `Shipping to …`, and `Change address`
