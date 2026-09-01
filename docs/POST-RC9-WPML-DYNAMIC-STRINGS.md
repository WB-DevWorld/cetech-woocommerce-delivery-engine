# Post-RC.9 WPML dynamic content — `1.0.0-dev.wpml.1`

**Document status:** Canonical implementation record for this development stream  
**Identity:** `1.0.0-dev.wpml.1`  
**Branch:** `feat/post-rc9-wpml`  
**Root:** immutable tagged `v1.0.0-rc.9` (schema `5`)  
**Schema:** remains `5` (no migration)  
**Not:** Stage 15, WCML currency conversion, cart-state, WCFM isolation, FLAIROC deploy  
**Date:** 2026-09-01  
**FLAIROC:** **NOT MODIFIED**

---

## 1. Product policy

The administrator configures **one** Delivery Engine entity (offer, pickup location, destination area, rate card, product rule).

WPML supplies translations of **customer-facing text** belonging to that entity. Routing, pricing, IDs, fulfilment, grouping, eligibility, and checkout validity are language-neutral.

Example: Delivery Option ID 4, `route = air`, rate card USD 50 remains ID 4 in every language. Only `public_label` / `public_description` may render as different copy.

Canonical DE tables continue to store the administrator’s source/default-language text. Translation happens at the presentation/read boundary. Rendering another language **must not** rewrite DE rows.

---

## 2. Integration design

Narrow WPML seam under `src/Integrations/WPML/`:

| Class | Responsibility |
|-------|----------------|
| `WpmlStringTranslationApi` | Interface over WPML public hooks. Tests inject a fake. |
| `WpmlWordPressStringTranslationApi` | Production adapter. Never writes `wp_icl_strings`. |
| `WpmlDynamicStringTranslator` | Register + translate one named string. Fallback to source. Never fatals. |
| `WpmlPublicStringNames` | Deterministic names + context `CETECH Delivery Engine`. |
| `WpmlPublicCopyCatalog` | Field-level register/translate for supported DE entities. |
| `WpmlPublicCopySync` | Idempotent existing-content registration from admin only. |
| `WpmlLivePublicCopyPresenter` | Live pre-order overlay from canonical rows + current language. |

Correctness does **not** depend on `enable_wpml_adapter`. That flag is retained for compatibility only.

Live overlay always translates from **canonical DB source**, not from already-translated cart text, so a language switch updates PDP, cart, Classic, and Blocks consistently without mutating cart identity or hashes.

Historical order snapshots **must not** use the live overlay when reading persisted text.

---

## 3. WPML public APIs / hooks used

Inspected WPML String Translation public contracts (no direct table writes):

| Action | Hook | Arguments used |
|--------|------|----------------|
| Register one dynamic string | `do_action( 'wpml_register_single_string', $context, $name, $value )` | context, name, source value |
| Translate one dynamic string | `apply_filters( 'wpml_translate_single_string', $original, $context, $name )` | original, context, name (current WPML language) |

Availability:

- WPML present: `defined( 'ICL_SITEPRESS_VERSION' )`
- String Translation available: `defined( 'WPML_ST_VERSION' )` **or** `wpml_register_single_string` / `wpml_translate_single_string` is hooked

When WPML is absent, String Translation is unavailable, the source is blank, no translation exists, or the hook throws: return the canonical source (blank stays blank). Never block checkout. Never substitute another Delivery Option. Never change price, destination, or shipping calculation.

---

## 4. Stable string identities

Context: `CETECH Delivery Engine`

Stable identity is the **persistent database entity ID**, not `internal_code`. Administrators may rename `internal_code`; WPML names must not break.

| Field | WPML name |
|-------|-----------|
| Delivery offer label | `delivery_offer.{id}.public_label` |
| Delivery offer description | `delivery_offer.{id}.public_description` |
| Pickup location name | `pickup_location.{id}.location_name` |
| Pickup opening hours | `pickup_location.{id}.opening_hours` |
| Pickup instructions | `pickup_location.{id}.pickup_instructions` |
| Pickup readiness estimate | `pickup_location.{id}.readiness_estimate` |
| Destination area label | `destination_zone.{id}.public_label` |

Rename of `internal_code` / `internal_name`: WPML identity unchanged.  
Delete / deactivate: leftover WPML strings may remain; frontend reads canonical DE rows first and never fatals on an orphan WPML entry. Deleted-entity live overlay leaves previously stored cart summary text in place rather than blanking it.

---

## 5. Dynamic fields supported (class B)

Administrator-entered public copy registered with WPML String Translation:

- `wp_delivery_engine_delivery_offers.public_label`
- `wp_delivery_engine_delivery_offers.public_description`
- `wp_delivery_engine_pickup_locations.location_name`
- `wp_delivery_engine_pickup_locations.public_opening_hours`
- `wp_delivery_engine_pickup_locations.public_pickup_instructions`
- `wp_delivery_engine_pickup_locations.readiness_estimate`
- `wp_delivery_engine_destination_zones.public_label`

Opening hours: registered and translatable via the catalog. Current Stage 13F compact pickup extras render location / address / instructions (and readiness when present). Opening hours are **not newly rendered** on the storefront in wpml.1.

Destination-area `public_label`: registered and translated when `WpmlPublicCopyCatalog::translate_destination_zone_label()` is used. Current PDP / cart / checkout / Blocks surfaces do not render destination-area labels to customers (matching remains language-neutral). Admin screens continue to edit the source value.

---

## 6. Fields intentionally excluded

**Internal / private / routing (class C) — never registered:**

- entity IDs (`delivery_offer_id`, `pickup_location_id`, `destination_zone_id`, `rate_card_id`)
- `internal_code`, `internal_name`
- `route`, `service_level` (not rendered as customer copy in this architecture)
- `tax_class`, `price_basis`
- numeric processing / transit / final-mile values
- `duration_unit` internal enum
- `status`
- carrier routing / operational data
- matching rules, priority, fallback semantics, region/country/postcode matching data
- rate-card names used as operational identifiers, currency, amount
- private notes, supplier, origin, internal cost
- contact phone / email on pickup locations

**Canonical pickup `public_address`:** untranslated in wpml.1. It is structured address data used for display **and** location identity. Translating country codes, postcodes, or routing geography would change matching semantics. Leave canonical address fields untranslated.

**Computed estimate text** from processing/transit numbers remains WordPress gettext (`format_estimate_text`), not WPML String Translation.

**Static plugin UI (class A)** remains `__()` / text domain. Do not duplicate into the dynamic-string adapter.

**WCML currency conversion:** out of scope.

---

## 7. Registration / sync design

Not a schema migration.

**On save:** Delivery Offers, Pickup Locations, Destination Areas, and `ContextualEntityService` re-register supported fields after a successful save. Changing source text updates the WPML source string via `wpml_register_single_string`.

**Existing installations:** `WpmlPublicCopySync::maybe_sync()` on `admin_init` only.

- Option marker: `cetech_de_wpml_string_sync_version` = `1`
- No frontend full-table scan
- Safe if WPML / String Translation is absent (no-op, marker not written)
- Safe to run more than once (`sync_all()` is idempotent; `maybe_sync()` skips when the marker matches)
- Uses `page_after()` when present; otherwise `list(['limit' => 500])`
- Uninstall deletes the option (`uninstall.php`)

No user-facing security/runtime toggle. Integration Status distinguishes:

- WPML not installed
- installed but inactive
- detected, String Translation unavailable
- supported/available (does **not** claim translations have been entered)

---

## 8. Presentation boundary

One catalog / presenter used by existing builders:

| Surface | Path |
|---------|------|
| PDP | `ProductDeliveryOptionsBuilder` |
| Cart item summary | `CartDeliverySelectionCapture::display_cart_item_data` via presenter |
| Classic checkout package labels | `ShippingPackageBuilder` |
| Blocks / Store API | `BlocksPublicPayload` / `BlocksStoreApiExtension` |
| Order create | `OrderDeliverySnapshotBuilder` (translated text persisted) |
| Thank-you / My Account / customer email | `CustomerOrderDeliverySummaryBuilder` — **historical snapshot only** |
| Customer shipment cards | historical shipment plan copied from the order snapshot |

Checkout validation, quote calculation, shipping grouping, International Air/Sea constraints, In Warehouse local-only, and Store Pickup zero charge are unchanged.

---

## 9. Order snapshot language semantics

- Cart = live state (current WPML language).
- Order = historical immutable snapshot.

At checkout/order creation the protected snapshot captures **public text as presented in that order’s language**, plus the canonical business IDs required by current DE architecture.

Changing a WPML translation later must not rewrite historical orders, thank-you, My Account, customer emails, or shipment customer cards.

Do not store only a WPML lookup key.

---

## 10. Package identity

| Item | Value |
|------|-------|
| Version | `1.0.0-dev.wpml.1` |
| Schema | `5` |
| Branch | `feat/post-rc9-wpml` |
| Source SHA | `e85b44d256852666a9c46b673c7903c0f56388ce` |
| ZIP | `cetech-woocommerce-delivery-engine-1.0.0-dev.wpml.1.zip` |
| Bytes | `1396670` |
| ZIP SHA-256 | `98d29a132b008e3ef7f382c0f80368b196ac4bcf0f9d9f1c1ee5e283de344444` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.wpml.1.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.wpml.1.zip` |
| RC.9 tag | `v1.0.0-rc.9` still peels to `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5` |
| FLAIROC | not modified |

