# CETECH WooCommerce Delivery Engine — Project Rules

**Source of truth for current implementation:** `docs/AI-HANDOFF.md` current-status block plus repository code.  
**Canonical maintained hard-rule authority:** `docs/DELIVERY-ENGINE-GOVERNING-RULES.md`.

This document is preserved detailed engineering rules derived from the original handoff. It is **not** deleted. When this file and `docs/DELIVERY-ENGINE-GOVERNING-RULES.md` appear to conflict, stop and reconcile explicitly — do not silently ignore either file.

**Plugin name:** CETECH WooCommerce Delivery Engine  
**Plugin slug:** `cetech-woocommerce-delivery-engine`  
**Future root plugin file:** `cetech-woocommerce-delivery-engine.php`  
**Future root namespace:** `CetechDeliveryEngine\`

This document is the disciplined rulebook derived from the handoff. When this file and the handoff diverge, reconcile explicitly against `docs/AI-HANDOFF.md`.

---

## 1. Project identity

Build a reusable, production-grade WordPress/WooCommerce plugin installable on multiple similar but independently operated WooCommerce sites.

Each installation manages its own site-level settings, delivery zones, delivery offers, carriers, suppliers, products, and orders. Deployments do not share a database, supplier network, country, currency, or logistics policy.

The plugin must not hardcode any store brand, supplier, country, currency, carrier, city, or delivery policy.

The project scope is limited to this delivery-and-fulfilment engine. Do not merge it with unrelated apps, platforms, or business systems.

---

## 2. Operating boundary

The plugin adds a structured delivery-and-fulfilment layer to WooCommerce. It is a **modular monolith**: one plugin package, clear module boundaries, limited cross-module coupling.

The plugin must:

- Activate and operate with **WooCommerce only** as a hard dependency.
- Work on WoodMart and non-WoodMart stores.
- Work with ordinary WooCommerce variable products.
- Work with or without WPML, WCML, WCFM, VitePOS, Redis, WP Rocket, WooCommerce Blocks, tracking plugins, and carrier APIs.
- Detect optional integrations at runtime; never fatal-error when they are absent.
- Remain independently installable on each site.

The plugin must not assume identical plugin stacks, themes, currencies, countries, or logistics partners across sites.

---

## 3. What the plugin is

A **delivery, fulfilment-choice, delivery-pricing, shipment-status, and tracking engine** for WooCommerce.

It determines and enforces:

- Where a product is operationally fulfilled from (internally).
- What fulfilment and delivery choices a buyer may make.
- Which delivery offers are valid for a product, variation, and destination.
- Customer-facing delivery prices and estimates (manual in V1).
- How cart lines retain per-line delivery selections.
- How WooCommerce shipping packages and shipping charges are built.
- How orders snapshot delivery data at payment time.
- How shipment records, statuses, and tracking are managed by staff.
- How customers view shipment progress and tracking.

The customer pays once at checkout; the order may create **multiple internal shipments**.

---

## 4. What the plugin is not

The plugin is **not**:

- A product add-ons or product-options plugin.
- A flat-rate-only shipping plugin.
- A driver app or driver-management system.
- A courier marketplace or dispatch system.
- A proof-of-delivery platform.
- A warehouse-management or warehouse-scanning system.
- A supplier marketplace or supplier portal.
- A carrier-API or live-freight-quote engine (in V1).
- A replacement for WooCommerce cart, checkout, payments, taxes, inventory, refunds, or accounting.

Do not reduce the plugin to theme edits, add-on hacks, or dependence on unrelated shipping plugins for core logic.

---

## 5. WooCommerce ownership boundaries

**WooCommerce remains authoritative for:**

| Domain | WooCommerce owns |
|--------|------------------|
| Catalogue | Products, variations, stock |
| Commerce flow | Cart, checkout, payments, coupons, taxes |
| Orders | Order records, order items, order status (parent order), refunds |
| Customers | Customer accounts, standard order emails |
| Shipping infrastructure | Native shipping zones/methods for non-managed packages |

**Rules:**

- Use native WooCommerce APIs, hooks, filters, and the Shipping Method API.
- Use WooCommerce CRUD for all order access (HPOS-compatible).
- Do not bypass WooCommerce for refunds, payments, or order totals.
- Do not invent a parallel cart, checkout, or payment system.
- Delivery charges must appear as **genuine WooCommerce shipping charges**, not disguised product surcharges.

---

## 6. Delivery Engine ownership boundaries

**The Delivery Engine is authoritative for:**

| Domain | Engine owns |
|--------|-------------|
| Fulfilment rules | Fulfilment Availability, fulfilment choices, product/variation rules |
| Commercial delivery config | Delivery Offers, Destination Zones, Rate Cards, Logistics Profiles |
| Private operations | Suppliers, origins, internal costs, consolidation keys |
| Pricing & estimates | Server-side delivery price calculation, delivery-date estimation |
| Cart behaviour | Cart-line delivery selection, cart-item uniqueness fingerprint |
| Shipping integration | Shipment package building, one custom shipping method for managed packages |
| Post-order | Order delivery snapshots, shipment records, shipment events |
| Operations | Manual shipment status updates, manual tracking entry |
| Customer visibility | Customer-safe shipment timeline and tracking display |

Keep all private operational data behind the delivery domain boundary. Customer-facing presentation must use only safe resolved data.

---

## 7. Hard dependency

| Dependency | Requirement |
|------------|-------------|
| **WooCommerce** | Required. Plugin must not bootstrap delivery features when WooCommerce is inactive. Show admin notice; do not fatal-error. |

**WooCommerce is the only hard dependency.** No other plugin, theme, or external service is required for activation or core operation.

---

## 8. Optional integrations

All of the following are **optional adapters**. The plugin must work correctly when each is absent.

| Integration | Role |
|-------------|------|
| **WoodMart** | Theme layout/styling compatibility for product page, swatches, quick view, mini-cart |
| **WPML** | Multilingual customer-facing strings; copy operational config to translations |
| **WCML** | Multicurrency display/payment; snapshot checkout currency and amounts |
| **WCFM** | Marketplace/vendor context; opt-in, restricted by default |
| **VitePOS** | POS order fulfilment selection and shipment creation |
| **Redis** | Optional object cache for configuration data |
| **WP Rocket** | Page-cache compatibility; cart/checkout/My Account exclusions |
| **WooCommerce Blocks** | Block cart/checkout adapter (separate from V1 classic baseline) |
| **Tracking plugins** | Optional display or handoff; no V1 auto-sync requirement |
| **Carrier APIs** | Future connectivity only; excluded from V1 live quotes/dispatch |

**Adapter rules:**

- Register integrations through an `IntegrationRegistry`; Core must not call WPML/WCML/WCFM/WoodMart functions directly.
- Never require WPML, WCML, WoodMart, WCFM, or VitePOS for activation.
- Never assume every site has the same optional plugins or theme.

---

## 9. Version 1 required scope

Version 1 must deliver the full **product → cart → checkout → order snapshot → shipment → customer timeline** loop.

**Fulfilment and modes:**

- Fulfilment Availability rules: International Fulfilment, In Store, In Warehouse
- Delivery Only, Store Pickup, Air Shipping, Sea Shipping, Local Delivery, Same-Day Express Courier

**Configuration:**

- Manual delivery prices
- Delivery Offers (reusable, not per-product duplication by default)
- Destination Zones (layered geography)
- Rate Cards
- Logistics Profiles
- Private Suppliers and Origins
- Pickup Locations

**Product and cart:**

- Product-level rules with variation overrides and inheritance
- Cart-line delivery selections
- Shipment grouping and consolidation rules
- WooCommerce shipping packages
- **One** custom WooCommerce shipping method (`delivery_engine_selected_offer` / “Delivery Engine Selected Offer”)

**Orders and shipments:**

- Shipment records, shipment items, shipment events
- Manual shipment status updates
- Manual tracking number/link entry
- Customer shipment timeline
- Immutable order delivery snapshots at payment
- HPOS-compatible order handling

**Platform and admin:**

- Classic WooCommerce cart/checkout support (full V1 baseline)
- Optional WPML/WCML adapters
- Optional WoodMart adapter (hooks-based)
- Optional WCFM/VitePOS adapters
- Admin logs, diagnostics, health checks
- Bulk product assignment/import
- Feature flags for controlled rollout

---

## 10. Version 1 explicit exclusions

The following are **out of scope for Version 1**. Do not design core flows that require them.

| Category | Excluded |
|----------|----------|
| Delivery confirmation | Buyer receipt-confirmation buttons, OTP, QR confirmation, GPS capture, automatic delivery confirmation, buyer reminder campaigns, automatic order completion from delivery events |
| Field / driver | Driver applications/accounts, driver app, delivery photos, signatures, proof-of-delivery workflows |
| Carrier automation | Live carrier quotes, carrier API dispatching, automatic tracking synchronization, route optimization, post-payment quote approval, unknown-price delivery flows |
| Warehouse / returns / marketplace | Warehouse scanning, returns automation, supplier portal, courier marketplace |

Version 1 uses staff-managed shipment statuses and **manually entered** tracking links. Customers may view progress; they are not required to confirm receipt.

---

## 11. Non-negotiable design rules

These rules override convenience, theme habits, and shortcuts. Any AI, developer, or agency must preserve them.

**Product and pricing model**

1. **Do not** make Air Shipping or Sea Shipping WooCommerce product variations.
2. **Do not** hide delivery charges inside product prices.
3. **Do not** use product add-ons as the delivery authority.
4. **Do not** create zero-cost Air/Sea placeholder shipping methods.
5. Delivery charges must remain **genuine WooCommerce shipping charges**.

**Privacy and customer trust**

6. **Do not** expose suppliers or origins to customers.
7. **Do not** reveal private supplier/origin data in product pages, cart, checkout, emails, My Account, tracking links, public order notes, SEO/schema, feeds, or public REST/Store API responses.
8. **Do not** silently replace a customer’s selected delivery option.
9. **Do not** expose one cart-wide delivery estimate when products may ship separately.

**Shipping calculation integrity**

10. **Do not** return free shipping because a rate card is missing.
11. **Do not** merge cart lines with different delivery selections.
12. **Do not** allow delivery offers invalid for the product, variation, or address.
13. **Do not** recalculate historical paid-order delivery prices or estimates because settings, rate cards, or exchange rates later change.

**Platform and ecosystem**

14. **Do not** require WPML/WCML for plugin activation.
15. **Do not** assume all sites use WPML/WCML or the same plugin stack.
16. **Do not** edit WoodMart **parent** theme files.
17. **Do not** depend on WoodMart `variable.php` or make the plugin depend on WoodMart template files.
18. **Do not** depend on one unrelated shipping plugin for core logic.
19. **Do not** bypass WooCommerce HPOS APIs for order business logic.
20. **Do not** assume cart/checkout cache behavior is safe without verification.
21. **Do not** deploy directly to production without staging and pilot validation.

**Version 1 scope guard**

22. **Do not** add proof-of-delivery, buyer confirmation, OTP, QR, GPS, driver accounts, live quotes, carrier API dispatch, or automatic completion in Version 1.

---

## 12. Canonical terminology

Use these terms consistently across customer UI, admin, reports, and code. Store stable internal codes; translate labels only at presentation time.

| Term | Meaning |
|------|---------|
| **Fulfilment Availability** | Product state: `International Fulfilment`, `In Store`, or `In Warehouse` |
| **Fulfilment Choice** | Buyer choice: `Delivery` or `Store Pickup` |
| **Delivery Route / Mode** | `Air Shipping`, `Sea Shipping`, `Local Delivery`, `Store Pickup` |
| **Delivery Offer** | Complete customer-selectable delivery service (route + service level + price + times + eligibility) |
| **Service Level** | Economy, Standard, Express, Same-Day, Consolidated, Priority, etc. |
| **Carrier** | Named provider when publicly shown |
| **Carrier Assigned by Store** | Store chooses operational provider later |
| **Processing / Dispatch** | Time before item is prepared and dispatched |
| **Main Transit** | Primary transport period (air, sea, road, courier) |
| **Final-mile Delivery** | Local movement to buyer’s address |
| **Estimated Delivery to Your Address** | Customer-facing promised date/range at the buyer’s address |
| **Logistics Profile** | Internal operational transport/handling classification |
| **Shipment** | Distinct fulfilment unit within one WooCommerce order |
| **Supplier / Origin** | Private operational source data; never customer-facing |
| **Rate Card** | Price rule for a delivery offer in a destination/logistics context |

**Label rule:** Do not use “Arrival” as the primary customer-facing promise. Use **“Estimated delivery to your address.”**

**Naming rule:** Do not call Logistics Profile “Shipping Class” — WooCommerce already has native Shipping Classes.

---

## 13. Fulfilment Availability model

Every product or variation must have exactly one **Fulfilment Availability** setting.

### International Fulfilment

- **Customer label:** Delivery Only
- **Fulfilment choice:** Delivery, locked and preselected
- **Routes:** Air Shipping and/or Sea Shipping (per configuration)
- **Unavailable:** Store Pickup, Local Delivery (unless product later reaches local warehouse as a separate stage)
- If only one valid offer exists, preselect automatically
- If multiple valid offers exist, show all; administrator may define default; buyer chooses before Add to Cart
- Final chosen Air/Sea offer must appear in cart, checkout, order confirmation, My Account, staff views, and shipment records

### In Store

- **Customer label:** In Store
- **Default fulfilment choice:** Delivery
- **Alternative:** Store Pickup
- When Store Pickup is selected: remove delivery offers, delivery price (zero), route/carrier choice, delivery times and doorstep estimate; show pickup location and readiness; **do not** create a delivery shipment for that line
- Staff may mark pickup ready/collected through normal operational status — **no** OTP/POD in V1

### In Warehouse

- **Customer label:** In Warehouse
- **Fulfilment choice:** Delivery Only, locked
- **Unavailable:** Store Pickup; Air/Sea normally unavailable (product already in local market)
- **Allowed offers:** Local delivery offers (e.g. Standard Delivery, Scheduled Delivery, Same-Day Express Courier)

---

## 14. Delivery Offer model

A buyer selects a complete **Delivery Offer**, not merely a carrier name.

A Delivery Offer comprises:

```text
Route / mode
+ service level
+ carrier visibility rule
+ customer price (from rate card / override)
+ processing / dispatch range
+ transit range
+ final-mile range (where relevant)
+ estimated doorstep delivery range
+ destination eligibility
+ product/variation eligibility
```

**Carrier visibility** (required per offer):

1. **Named carrier visible to buyer** — only when the business can reliably honour that carrier.
2. **Carrier assigned by the store** — when provider assignment may vary.

Delivery Offers must be **reusable** across products. Product-specific overrides are for exceptions only.

---

## 15. Manual pricing policy

Version 1 uses **manually configured** customer-facing delivery prices. No live carrier quotes, carrier APIs, quote-approval flows, or provisional/unknown-price orders.

For each delivery offer (including Same-Day Express Courier), staff configure in advance:

- Customer checkout price
- Internal estimated carrier cost (optional, private)
- Internal margin/buffer (optional, private)
- Destination eligibility and time estimates

The customer sees and pays the configured checkout price during ordinary WooCommerce checkout.

If actual later carrier cost differs, the business absorbs gain or loss. **Do not** charge customers an unknown delivery amount after payment.

Same-Day Express Courier uses the same manual pricing model — no Uber/Bolt/Yango/courier API required in V1.

For initial deployment, prefer simple rate-card charge types: **fixed per shipment** and **fixed per item**. Avoid complex formulas until operations are stable.

---

## 16. Destination-zone strategy

Do **not** manually configure every `Product × country × city × currency` combination.

Use **layered Destination Zones** resolved by rate cards:

```text
Country
→ state/region
→ city or metro area
→ postcode/area group
→ remote-area rule (where needed)
```

Only add geographical detail where it materially changes price, eligibility, or delivery time.

**Zone evaluation order** (most specific wins):

```text
Exact postcode rule
→ city rule
→ region rule
→ country rule
→ fallback global zone
```

A zone provides location context; it does not alone determine price — the **Rate Card** does.

If no valid zone matches: use a configured fallback delivery offer **or** block checkout with a clear message. **Never** silently apply a wrong rate.

**Authoritative destination:** Shipping address entered at cart/checkout is authoritative. IP geolocation may assist initial display only; it must never be final pricing truth.

---

## 17. Logistics Profile strategy

Use the internal field **Logistics Profile** (or site-branded equivalent, e.g. CETECH Logistics Profile). Do not use electronics-specific labels as the primary classification.

Profiles classify how products are transported, handled, consolidated, priced, and restricted. Customer does not see profile name unless a product-specific disclosure is explicitly required.

**Profile dimensions may include:**

- Parcel size class (Document, Small parcel, Standard parcel, Large parcel, Bulky, Oversized freight)
- Charge basis (fixed, per item, per cart line, weight, volumetric, weight band, manual)
- Handling (Standard, Fragile, High-value, Liquid, Temperature-sensitive, Restricted, Special packing)
- Route eligibility (Air, Sea, Local delivery, Pickup)
- Consolidation (may consolidate, must ship separately, limited consolidation)
- Dispatch type (In stock, Supplier fulfilled, Made to order, Preorder)

Route restrictions such as “air-only” or “sea-only” are **handling/route restrictions**, not profile names.

**Do not** name this field “Shipping Class.”

---

## 18. Private Supplier and Origin rules

Suppliers and origins are recorded internally for dispatch timing, routes, shipment grouping, internal freight cost, fulfilment instructions, accountability, staff workflow, and tracking assignment.

**They must never be shown to customers.**

Do not expose supplier/origin data in:

- Product pages, cart, checkout
- Order emails, My Account, tracking links
- Public order notes, structured data/SEO, Google feeds
- Public REST/Store API responses
- Customer-facing exports

Customers see only delivery-relevant information (route, service, price, estimates, tracking when provided).

**Data lifecycle:** Do not hard-delete suppliers/origins referenced by historical shipments; mark inactive instead.

Staff with `manage_private_sources` / `view_private_origins` capabilities access supplier/origin admin. WCFM vendors have **no** access by default.

---

## 19. Product-rule inheritance rules

**Resolution precedence:**

```text
Variation override
→ Parent product rule
→ Optional category/default rule
→ Site fallback
→ Invalid/unconfigured
```

**Default:** Variation inherits parent delivery rule.

**Variation may override** only fields that genuinely differ, e.g.:

- Fulfilment availability, logistics profile, supplier/source
- Route or offer eligibility, processing/transit/final-mile overrides
- Product-specific price override, handling restriction, consolidation restriction

**Do not** duplicate every parent setting into each variation unnecessarily.

Every product/variation rule is based on:

```text
Fulfilment Availability
+ Logistics Profile
+ Fulfilment Source (supplier/origin where relevant)
+ eligible delivery offers
+ optional variation override
```

---

## 20. Cart-line delivery-selection rules

Delivery selection is chosen **before Add to Cart** (where required) and stored in **cart-item data**.

**Product-page selection order:**

1. Product variation (where applicable)
2. Fulfilment choice (where a choice exists)
3. Delivery Offer (where delivery is selected and multiple offers exist)

**Stored fingerprint must include stable values:**

```text
fulfilment availability
fulfilment choice
delivery offer ID
delivery route
service level
carrier visibility
destination context
delivery price snapshot
estimate snapshot
product/variation rule version
```

**Revalidation triggers** (must revalidate; never silent replacement):

- Variation, quantity, fulfilment choice, delivery offer, or destination change
- Cart contents or coupon change
- Return to checkout
- Stock or offer configuration change before payment

If selected offer is no longer valid, show a clear customer message and require explicit reselection.

**Cart change:** “Change delivery option” in cart is allowed only where product remains eligible; must revalidate, recalculate server-side, update packages/totals, and never expose private data.

---

## 21. Cart item uniqueness rules

The delivery-selection fingerprint **must participate in the cart-item key**.

```text
Product A + Sea Shipping
Product A + Air Shipping
```

These **must** remain separate cart lines.

Construct fingerprint from stable values:

```text
product_id
variation_id
fulfilment_choice
delivery_offer_id
destination-context version
relevant rule version
```

**Do not** include unstable values (translated labels, display price strings) in the fingerprint.

On session restoration: load saved selection → revalidate → refresh quote if needed → retain if valid → mark invalid with cart notice if not → **never** silently swap Sea/Air, Delivery/Pickup, or carriers.

---

## 22. WooCommerce shipping package rules

Split WooCommerce shipping packages by **actual shipment groups** (consolidation keys). One package = one real delivery grouping.

**Package builder must:**

- Read cart items and normalized delivery selections
- Resolve private supplier/origin internally
- Build consolidation key
- Group items by consolidation key
- Create one WooCommerce package per shipment group
- Attach private metadata to package only (not auto-leaked to customer output)

Use the `woocommerce_cart_shipping_packages` filter.

**Consolidation key (default) includes:**

```text
Same supplier/source
Same private origin
Same delivery route
Same service level
Same carrier/partner rule
Same destination zone
Same dispatch window
Compatible logistics profile
No separate-shipment restriction
```

**Never consolidate:**

- Air and Sea
- Store Pickup and Delivery
- Different suppliers or origins (by default)
- Items with incompatible dispatch windows or handling restrictions
- Local delivery with international shipment (unless explicitly configured)

**Same route ≠ same shipment:** Two Air items may require separate shipments when supplier, origin, dispatch window, carrier/service, logistics profile, or handling differs.

Calculate shipping per **actual shipment group**, not merely per full cart.

---

## 23. Custom shipping method rules

Register **exactly one** custom WooCommerce shipping method.

- **Suggested method ID:** `delivery_engine_selected_offer`
- **Suggested title:** Delivery Engine Selected Offer

The method must:

- Return **only** the rate matching the selected delivery offer for each managed package
- Expose cost as a genuine WooCommerce shipping charge with customer-safe estimate metadata where supported
- **Not** create generic zero-cost placeholder methods (e.g. “Air Shipping — GHS 0”, “Sea Shipping — GHS 0”)
- **Not** add delivery price as a product add-on while also charging shipping

**Managed package behavior (recommended default: Exclusive):**

- Remove conflicting Flat Rate, Free Shipping (unless deliberately included by rate rule), and legacy plugin rates for delivery-engine-managed packages
- Do not show duplicate Air/Sea placeholders
- Non-managed products/packages may continue using native WooCommerce shipping methods per site configuration

Settings must support: Exclusive / Coexist with native rates / Fallback to native rates on configuration failure.

---

## 24. Checkout validation rules

Checkout is **server-authoritative**. Never trust browser-submitted delivery prices or supplier/origin IDs.

**Server must validate at Add to Cart, Cart, Checkout, and Order creation:**

- Product has fulfilment rule configured
- Required delivery choice exists
- Selected offer belongs to product/variation and destination
- Offer eligible for current quantity
- Pickup allowed only for In Store products
- Air/Sea allowed only for International Fulfilment
- Local offers allowed only for configured availability
- Prices and estimates calculated server-side; client values replaced with trusted server values

**Checkout display rules:**

- Show each shipment/package separately with service label, items, shipping cost, and estimated delivery to address
- Do not show one generic delivery estimate for the entire cart
- Do not show unrelated default Flat Rate methods for managed packages
- Pickup items must not contribute delivery shipping cost; no shipping methods for pickup-only packages
- Recalculate and clearly display updated price/estimate if shipping address changes zone
- Private supplier/origin details remain hidden

**Failure modes:**

| Condition | Required behaviour |
|-----------|-------------------|
| No eligible delivery offer | Block Add to Cart with clear message |
| No rate card for valid offer | **Do not** return zero cost; block checkout or show customer-safe unavailability; log staff configuration error |
| Address not covered | Block checkout or use configured fallback — never wrong silent rate |
| Invalid variation override | Fall back to parent only if explicitly configured; otherwise report error |

---

## 25. Order snapshot rules

When checkout succeeds, create:

- Normal WooCommerce order, order items, and shipping lines
- Delivery-engine shipment records, shipment items, and events
- **Immutable** delivery/price/estimate snapshots

**Snapshot must capture:**

```text
Customer-selected offer
Price
Currency (and WCML conversion context when active)
Estimate
Address/zone context
Rule version
```

**Order item metadata (customer-safe only):**

```text
Fulfilment: Delivery | Store Pickup
Delivery service: [route/offer label]
Estimated delivery: [range]
Pickup location: [where applicable]
```

Private data (`supplier_id`, `origin_id`, internal cost, internal notes) goes to custom tables or protected metadata only.

**Immutability:** After payment, **do not** recalculate historical paid-order delivery charges, estimates, or snapshots because rate cards, rules, currencies, or exchange rates later change.

Staff must **not** change customer-paid delivery price through the shipment workspace after payment. Internal cost corrections remain private.

WCML: snapshot base amount, checkout amount, checkout currency, and conversion context. Historical orders must not recalculate when exchange rates change.

---

## 26. Shipment lifecycle rules

**V1 shipment statuses:**

```text
Awaiting fulfilment
Processing
Dispatched
In transit
Delayed / issue
Delivered
Cancelled
```

**Creation flow:**

```text
Paid WooCommerce order
→ validate delivery metadata
→ shipment planner groups items
→ create shipment records (idempotent)
→ initial status: Awaiting fulfilment
→ staff manual status updates
→ staff adds tracking when available
→ customer sees timeline
```

**Idempotency:** If shipment already exists for order item + shipment group key, do not create duplicate.

**Order relation:**

- One order → zero, one, or many shipments (zero delivery shipments if all lines are Store Pickup)
- One shipment → one or several compatible order items (V1: keep one order-item quantity together unless partial-quantity support explicitly enabled)

**Parent order status:** Do **not** automatically complete the WooCommerce order when one shipment is marked Delivered. Use Processing / configurable Partially Shipped until staff policy permits Completed when all applicable shipments are delivered or collected.

**Status updates:** Require capability, allowed transition, sanitized public note, audit event, shipment event. Internal notes never render customer-side. V1 permits corrective transitions with required internal note.

**Refunds:** WooCommerce remains authoritative. Cancelling/refunding one line or shipment must not automatically affect unrelated shipments (e.g. Sea vs Air in mixed orders).

---

## 27. Tracking rules

Version 1 tracking is **manual entry only**. No carrier API or automatic tracking synchronization.

Staff may add per shipment:

```text
Carrier display name
Tracking number
Tracking URL
Dispatch date
Public note
```

**Customer rules:**

- Show tracking only for the relevant shipment
- Do not show empty, invalid, or unusable “Track shipment” controls
- Customer sees only customer-relevant tracking information

---

## 28. Customer-facing UX rules

**Principles:**

- Show customers only what they need to decide and pay
- Use plain language; **“Estimated delivery to your address”** not vague “Arrival”
- Keep product choices separate from delivery choices
- Never make the customer choose the same delivery method twice
- Show delivery price before Add to Cart and again before payment
- Show different shipments separately after purchase
- Support desktop and mobile; meet accessibility requirements from handoff (keyboard, labels, contrast, screen readers)

**Product page:** Theme-neutral WooCommerce hooks; after variation selection, before Add to Cart. React to variation events (`found_variation`, `reset_data`, etc.). Disable Add to Cart only when required selection is incomplete. Show loading state during recalculation.

**Cart:** Per-line delivery summary; optional “Change delivery option” where safe.

**Checkout:** Shipment-grouped delivery information; no cart-wide generic estimate.

**My Account / order confirmation:** Per-shipment cards with status, estimate, tracking when available. Optionally `My Account → Deliveries`.

**Never show:** Supplier, origin, internal warehouse, internal margin, internal cost, private notes, consolidation keys.

**Frontend payload:** Server exposes only safe resolved data. Browser may send offer identifier; **never** authoritative price.

---

## 29. Admin UX rules

Top-level menu: **Delivery Engine** with capability-gated submenus (Dashboard, Shipments, Products & Rules, Delivery Offers, Rate Cards, Destination Zones, Logistics Profiles, Suppliers & Origins, Pickup Locations, Import/Export, Integrations, Logs & Diagnostics, Settings).

**Dashboard:** Operational, not decorative. Actionable cards (awaiting fulfilment, delayed, no tracking, incomplete rules, missing rate cards). Alerts for configuration gaps. No private supplier cost totals without permission.

**Configuration screens:** Progressive disclosure; simple fixed offers must not require staff to understand full shipping formulas. Rate Cards include “Test this rate” tool. Zone editor shows overlap/priority resolution.

**Shipment workspace:** Customer-facing delivery summary, shipment items, internal operations (capability-gated), status timeline, tracking. Staff actions: update status, add tracking, add public/internal notes. No POD UI in V1.

**Product Rules:** Single/bulk edit, variation overrides, validation warnings, links to WooCommerce product edit.

Do not overwhelm new staff; show advanced areas only to users with required capabilities.

---

## 30. Roles and capability rules

Use **granular WordPress capabilities**, not broad `administrator` checks alone.

**Capabilities:**

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

**Suggested role policies:**

| Role | Access |
|------|--------|
| Administrator | All capabilities |
| Shop Manager | Most configuration and shipments; private supplier/cost may be restricted |
| Logistics Manager | Shipments, suppliers/origins, rate cards, zones, offers, estimates |
| Product Manager | Product delivery rules, offers, logistics profiles; not private supplier cost by default |
| Customer Service | View shipment status/tracking; limited public notes; no rate-card changes |
| Store Staff | Pickup and assigned shipment workflow only |
| Vendor / WCFM Seller | **No access by default** to private sources, internal costs, or global rate cards |

WCFM integration is **opt-in**. Future vendor capabilities (`view_own_product_delivery_rules`, etc.) require explicit adapter and permission model.

---

## 31. WPML/WCML rules

### WPML (optional adapter)

- Detect at runtime; if absent, use site language with `NullTranslationAdapter`
- Register customer-facing strings for translation
- **Copy** (do not translate): fulfilment codes, logistics profile IDs, supplier/origin IDs, offer IDs, rate-card linkage, eligibility rules, internal notes
- **Translate:** customer labels, offer descriptions, pickup instructions, public shipment notes (when authored per language)
- Canonical product’s operational config copied/inherited by translations; one source of truth
- Use `wpml-config.xml` for custom-field translation behavior
- Do not require WPML for activation

### WCML (optional adapter)

- Without WCML: use WooCommerce base currency
- With WCML: store canonical amount in base currency; convert for display/payment; allow manual per-currency overrides; snapshot base amount, checkout amount, checkout currency, and conversion context at checkout
- Historical orders must not recalculate when exchange rates change
- Destination address decides eligibility; currency affects display/payment only — not route eligibility

---

## 32. WoodMart rules

WoodMart integration is **optional** but supported through a dedicated adapter.

**Required:**

- Use standard WooCommerce hooks and JavaScript events
- Theme-neutral HTML first; WoodMart classes only through adapter
- Support product page layouts, variation swatches, quick view (after testing), mini-cart, AJAX add-to-cart, Buy Now, mobile layouts

**Forbidden:**

- Edit WoodMart **parent** theme files
- Depend on WoodMart `variable.php` or make delivery selector depend on WoodMart template files
- Modify parent templates for core business logic

**Staging requirement:** WoodMart overrides WooCommerce variable add-to-cart template; supplied sites report outdated override vs WooCommerce core. Validate or repair in **child theme on staging** only if strictly necessary — never blind copy of core template over WoodMart override. Delivery selector must survive WoodMart template updates via hooks/events.

If WoodMart unavailable, render through generic WooCommerce hooks.

---

## 33. WCFM/VitePOS rules

### WCFM (optional, opt-in)

**Default deny:**

- Vendors cannot see private supplier/origin data, internal costs, global rate cards, destination zones, or other vendors’ shipments
- Vendors cannot alter site-wide delivery logic

Future vendor access only through explicit capabilities and dedicated WCFM adapter. Do not automatically expose delivery configuration to marketplace vendors.

### VitePOS (optional)

- POS orders must not bypass delivery rules
- Staff can choose Delivery or Store Pickup where product allows
- POS stores normalized delivery selection; creates shipment record for delivery; pickup creates no delivery shipping rate
- Do not assume online product-page UI appears identically in POS; dedicated POS flow with separate testing

---

## 34. Cache rules

**Never** serve one customer’s dynamic delivery results to another.

**Exclude from full-page cache:**

```text
Cart
Checkout
My Account
Delivery selection AJAX endpoints
Delivery estimate endpoints
Authenticated tracking/status pages
```

**Redis/object cache** may cache reusable configuration (offers, rate cards, zones, logistics profiles) with **immediate invalidation** when staff changes those entities.

**Never** cache a customer-specific quote without a key including cart, product, destination, and currency context.

After deployment: purge page cache and plugin object-cache keys; verify cart/checkout exclusions and AJAX endpoint cache behaviour.

Compatible with WP Rocket and Redis when present; must work without them.

---

## 35. HPOS rules

Declare HPOS compatibility during plugin initialization.

**Required:**

- Use WooCommerce CRUD: `wc_get_order()`, `$order->get_items()`, `$order->get_shipping_methods()`, `$order->update_meta_data()`, `$order->save()`
- Use WooCommerce order APIs for all order business logic

**Forbidden for order business logic:**

- Direct queries to `wp_posts`, `wp_postmeta`, `wp_wc_orders`, `wp_wc_orders_meta`

Custom delivery tables may use `$wpdb` with prepared statements and dynamic table prefix (`{$wpdb->prefix}delivery_engine_*`).

Do not overload `wp_postmeta` with large shipment histories or complex rate-card structures.

---

## 36. Security rules

- WordPress nonces for admin and AJAX actions
- Capability checks on every sensitive action
- Sanitize and validate all inputs; escape all output
- Never expose private source data in REST/Store API responses
- Never trust frontend-submitted delivery prices — recalculate server-side
- Never trust frontend-submitted supplier/origin IDs
- Validate selected offers belong to actual product/variation and destination
- Maintain immutable order/shipment snapshots
- Log sensitive configuration changes
- Do not log full payment details, passwords, tokens, unnecessary PII, or private supplier data in publicly accessible logs

Delivery pricing endpoints must enforce server-side authority (see handoff §64).

---

## 37. Logging and diagnostics rules

**Log operational errors** with context: order ID, shipment ID, product/variation ID, offer ID, zone ID, currency, rate-card ID, error type, timestamp, correlation ID where possible.

**Do not log:** payment details, passwords, sensitive tokens, unnecessary customer PII, private supplier data in public logs.

**Admin health checks** must cover: WooCommerce active, HPOS status, cart/checkout mode, integration statuses, missing rate cards, incomplete product rules, broken offers, shipment linkage gaps, cache warnings.

**Operational alerts** for: paid order but shipment creation failed, offer selected but no rate found, rate calculation failed, shipping total mismatch, missing customer estimate, invalid tracking URL, adapter errors.

Checkout-critical errors create readable admin diagnostics without exposing internal data to customers.

---

## 38. Testing and acceptance rules

No deployment relying on developer unit tests alone.

**Required test layers:** unit, integration, WooCommerce integration, migration, rate-calculation, shipment-grouping, WPML/WCML adapter, security/permission, regression, E2E where feasible.

**Key automated scenarios:**

- International Fulfilment cannot show Store Pickup
- In Store shows Delivery and Store Pickup; pickup removes delivery calculations
- In Warehouse is Delivery Only; Air/Sea hidden unless explicitly configured
- Air and Sea cart lines do not merge; same product + different offers = separate lines
- Rate card selects most specific zone; fallback only when no more specific match
- Variation override wins; parent applies when override absent
- Private supplier data excluded from customer output
- Consolidation: same supplier + same Air + same zone = one shipment; Sea + Air = two; different suppliers = separate by default

**E2E matrix must include:** simple/variable products, Air/Sea combinations, pickup, Same-Day Express, multi-supplier carts, consolidation, zones, coupons, taxes, refunds, classic checkout, WPML/WCML, WoodMart swatches/quick view/mini-cart, mobile/desktop, guest/logged-in, HPOS on.

**Visual QA:** no duplicate selectors, no leaked private data, correct per-shipment checkout display.

**Version 1 complete only when** all handoff §24 / Master §26 acceptance criteria pass in **staging**, including: genuine WC shipping charges, immutable snapshots, idempotent shipment creation, no accidental POD/OTP/driver/live-quote dependency, cache isolation, WoodMart flows tested.

Test on staging that resembles production — not blank WordPress only.

---

## 39. Deployment and rollback rules

**Release sequence:**

```text
Development → automated tests → staging → manual QA → pilot (10–25 products) → monitored production → controlled catalog expansion
```

**Never deploy directly to production** without staging clone, backup, pilot validation, and rollback plan.

**Before production release:** database and files backup, plugin version recorded, feature flags documented, affected product IDs documented, rollback ZIP ready, cache purge plan, staff communication.

**Rollback preference:** feature-flag disable (e.g. disable delivery selector) while retaining shipment data — **do not** delete delivery records during rollback.

**Packaging:** semantic versioning (MAJOR.MINOR.PATCH); release notes, migration notes, upgrade/rollback notes, compatibility matrix, known limitations.

**Migrations:** versioned, idempotent, logged, non-destructive by default, retry-safe, batch-capable; no long blocking migrations during checkout peaks.

**Pilot monitor:** add-to-cart errors, checkout failures, shipping mismatches, cart duplication, missing shipments, private data leakage, theme conflicts, currency issues.

Do not update WooCommerce, WoodMart, and delivery plugin simultaneously without staging validation.

---

## 40. Future-scope boundaries

Valid **future** capabilities (not V1): carrier API integrations, live courier quotes, automatic tracking sync, driver portal, customer notification automation, pickup QR, delivery OTP, POD photos, GPS/geofence, signature capture, buyer receipt confirmation, partial-refund automation, returns-shipment integration, warehouse scanning, carrier/SLA analytics, automated exception workflows, address validation APIs, predictive ETA from real shipment data.

**Conditions before proof-of-delivery:** defined driver procedures, dispute/privacy/retention policies, staff roles, notification process, incident resolution, technical ownership.

**Conditions before live carrier rates:** reliable API access, contract terms, API outage fallback, margin policy, service eligibility mapping, address validation, customer-visible error handling, operational ability to honour selected service.

Do not add complexity before core product-to-cart-to-checkout-to-shipment workflow is proven in live operations.

---

## 41. Cursor/developer discipline rules

### Phase discipline (current: Phase 0A — Project Rule Locking)

Until architecture is approved:

- **Do not** create plugin bootstrap, PHP classes, database migrations, admin screens, JavaScript, CSS, or the root plugin file unless explicitly tasked
- **Do** read `docs/AI-HANDOFF.md` and this file at the start of each major task

### Implementation order (from handoff)

```text
Foundation and compatibility
→ Delivery configuration model
→ Product and variation rules
→ Product-page selection
→ Cart and checkout shipping integration
→ Shipment records and customer status display
→ Optional WPML/WCML integration
→ Optional WCFM/VitePOS integration
→ Pilot launch
→ Broader rollout
```

### Architecture pipeline (canonical)

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

### Code discipline

- Build a **delivery domain layer**, not a visual add-on
- Composer-capable structure; `CetechDeliveryEngine\` namespace; no global function soup
- Business rules in application services and domain objects — not in `functions.php`, WoodMart templates, JS-only logic, or `wp_postmeta`-only storage
- Core module must not contain customer-facing delivery rules or theme-specific rendering
- Optional integrations behind adapter interfaces only
- Feature flags required for controlled rollout
- Do not auto-create offers/rate cards/suppliers on activation unless demo mode explicitly enabled
- Scope changes require explicit update to this file and `docs/AI-HANDOFF.md`
- Keep Version 1 operationally simple: manually priced, manually tracked, shipment-aware, checkout-safe

### Reference environment (handoff supplied sites)

WooCommerce 10.9.3, HPOS enabled, WoodMart 8.5.4 child themes, Redis, WP Rocket, classic cart/checkout; one site with WPML/WCML, one without. Plugin must tolerate variation across deployments.

---

*Derived from `docs/AI-HANDOFF.md`. Phase 0A — Project Rule Locking.*
