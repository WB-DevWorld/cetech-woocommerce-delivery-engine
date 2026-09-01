# Post-RC.9 WPML dynamic content — physical QA plan (`1.0.0-dev.wpml.1`)

**Document status:** Narrow owner physical QA plan  
**Package:** `cetech-woocommerce-delivery-engine-1.0.0-dev.wpml.1.zip`  
**Schema:** `5`  
**Baseline:** immutable `1.0.0-rc.9` (`e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`)  
**FLAIROC:** this stream does **not** install or mutate FLAIROC from Cursor. Owner may later install the ZIP on a WPML-capable site (for example FLAIROC `/intl`) after explicit authorisation.

Do not treat this document as permission to deploy.

---

## Source and ZIP

| Item | Value |
|------|-------|
| Filename | `cetech-woocommerce-delivery-engine-1.0.0-dev.wpml.1.zip` |
| Version | `1.0.0-dev.wpml.1` |
| Schema | `5` |
| Branch | `feat/post-rc9-wpml` |
| Package-source commit | `e85b44d256852666a9c46b673c7903c0f56388ce` |
| Bytes | `1396670` |
| SHA-256 | `98d29a132b008e3ef7f382c0f80368b196ac4bcf0f9d9f1c1ee5e283de344444` |
| Dist path | `dist/cetech-woocommerce-delivery-engine-1.0.0-dev.wpml.1.zip` |
| Desktop copy | `C:\Users\Jane\Desktop\cetech-woocommerce-delivery-engine-1.0.0-dev.wpml.1.zip` |
| RC.9 tag | `v1.0.0-rc.9` still peels to `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5` |
| FLAIROC | not modified |

---

## 1. Purpose

Prove presentation-only WPML support for Delivery Engine public copy:

- one entity per offer / pickup / destination area
- translated labels/copy by language
- unchanged IDs, route, rates, currency, grouping, constraints, checkout validity
- historical order snapshots stay in the checkout language

---

## 2. Preconditions

- WPML Multilingual CMS + String Translation active
- WooCommerce Multilingual may be present; **do not** expect this package to convert currency
- Delivery Engine `1.0.0-dev.wpml.1` installed over RC.9 (schema remains 5; no migration prompt)
- Known entities already used on RC.9 (for example Delivery Option ID 4 if that is International Air)
- WPML languages matching the live set (en-us default plus en-gb / en-au / en-ca / en-de / en-gh as configured)

After first admin load, WPML → String Translation should list Delivery Engine strings under context **CETECH Delivery Engine**. If the list is empty, open Delivery Engine admin (any offers/pickup/areas screen) once to trigger `admin_init` sync, then reload String Translation.

---

## 3. Narrow checks

### 3.1 Status honesty

| Check | Pass |
|-------|------|
| Integrations status does not claim translations exist merely because WPML is installed | |
| Status is Supported when String Translation is active | |
| Copy states rules/routing/pricing remain shared | |

### 3.2 Register and translate one offer

1. In Delivery Engine, note Delivery Option **ID** and source `public_label` / `public_description` (en-us).
2. In WPML String Translation, translate those two strings for **en-de** (or another non-default language).
3. Do **not** create a second Delivery Option for German.

| Surface (same session language) | Expect |
|---------------------------------|--------|
| PDP | Translated label and description |
| Cart | Same translated label |
| Classic checkout | Same translated label on the package |
| Blocks cart/checkout (if used) | Same translated label in Store API extension data |
| IDs / route / amount | Unchanged vs en-us |

Switch back to en-us: live PDP/cart/checkout show the source label again. Existing orders must not change.

### 3.3 Pickup (if Store Pickup is in the cart)

Translate `location_name`, instructions, and readiness (and opening hours in String Translation even if compact extras do not show hours).

| Check | Pass |
|-------|------|
| Pickup name/instructions/readiness follow the current language | |
| Canonical address is **not** translated | |
| Store Pickup remains GHS/USD **0** | |
| Pickup location ID unchanged | |

### 3.4 Destination area

Translate `public_label` in String Translation. Matching an Accra vs Greater Accra vs fallback address must still select the **same area ID** and **same rate** after a language switch. Customer storefront may not show the area label; that is expected in wpml.1.

### 3.5 Missing translation / fallback

Leave one offer untranslated in a secondary language. PDP/cart/checkout must show the **source** label, not blank, and must not block checkout.

### 3.6 Order snapshot immutability

1. Check out in **en-de** with a translated offer label.
2. Confirm thank-you, My Account order, and customer email show the **German** snapshot text plus the same offer ID / amount / currency as RC.9 would store.
3. Edit the WPML translation to different German copy.
4. Reload the same order: snapshot text must **not** change.
5. New PDP visit in en-de may show the new translation (live only).

### 3.7 Constraints unchanged

| Scenario | Expect |
|----------|--------|
| International Air/Sea | Same hard constraints as RC.9 |
| In Warehouse | Local-only unchanged |
| Mixed Delivery + Pickup | Grouping unchanged; pickup still zero |

### 3.8 WPML absent control (optional second site)

On a site without WPML, RC.9 customer behaviour is unchanged. No fatals. Schema 5.

---

## 4. Fail immediately

- Blank label because a translation is missing
- Checkout blocked because String Translation is missing
- A different Delivery Option substituted by language
- Price, currency, route, or destination changed by language
- Historical order rewritten after a WPML edit
- Schema migration to 6
- Duplicate Delivery Options created per language

---

## 5. Out of scope for this QA

- WCML multi-currency
- Translating `internal_code`, `internal_name`, route, addresses as matching keys
- Building a second translation UI inside Delivery Engine
- Stage 15
- Cart-state or WCFM packages
- Cursor deploying to FLAIROC
