# CETECH Delivery Engine — Administrator Language Guide

**Document status:** Authoritative presentation-language standard for Stages 6–11  
**Audience:** Normal administrators and staff, not developers  
**Persistence rule:** Internal keys, enums, database columns, option names, and class names stay unchanged. This guide maps them to operational wording.

---

## Audience

A staff member with no software-development knowledge must be able to understand menus, pages, tabs, settings, options, inheritance, warnings, and empty states without learning plugin architecture.

---

## Principles

1. Primary administrator UI uses **business / operational** language.
2. Internal implementation terms belong only under **Technical details** (or Advanced / Developer information).
3. Labels must stay **accurate**. Friendly wording must not hide default vs product vs variation, inherited vs explicitly set, disabled vs missing, or empty list vs inherited list.
4. A good description answers: what is this, when should I use it, and what happens if I leave it unchanged.
5. Do not replace clear text with icons only. Keep labels associated with inputs.

---

## Menu Naming

| Menu | Purpose |
|------|---------|
| Delivery Engine | Top-level plugin menu |
| Dashboard | Operations overview and readiness |
| Settings | Checkout features and advanced switches |
| Delivery Settings | Default, product, and variation delivery values |
| Delivery Settings Preview | Read-only view of the values that will actually apply |
| Legacy Delivery Rules | Older product-rule system still used by shoppers until the new system is turned on |
| Logistics Profiles | Private fulfilment-planning profiles |
| Delivery Offers | Customer-facing delivery services |
| Destination Zones | Where the store delivers |
| Pickup Locations | Store pickup points |
| Suppliers & Origins | Private supply sources |
| Rate Cards | Delivery fees for zone + offer combinations |

Do not rename legitimate business terms such as Supplier, Origin, Rate Card, or Delivery Offer.

---

## Page Naming

| Page | What it is | Editable? |
|------|------------|-----------|
| Dashboard | Readiness, checklist, common tasks | Informational, with links |
| Settings | Checkout and advanced switches | Editable |
| Delivery Settings | Inherited delivery values | Editable |
| Delivery Settings Preview | What will actually apply | Read-only |
| Legacy Delivery Rules | Previous product-rule editor | Editable (legacy path) |
| System Status / Advanced system details | Diagnostics | Informational / developer |

---

## Setting Naming

Use operational names for administrator-controllable switches. Do not show raw option keys as the primary label.

Examples:

| Internal key | User-facing label |
|--------------|-------------------|
| `enable_effective_configuration_runtime` | Use Site-wide Defaults at checkout |
| `enable_variable_product_ecr_runtime` | Use Site-wide Defaults for product variations |

If a switch is a deployment/cutover control, keep it under **Advanced settings** and warn staff not to change it unless CETECH support asked them to.

---

## Inheritance Language

Hierarchy, in ordinary language:

**Default Settings → Product-Specific Settings → Variation-Specific Settings**

- If you do not set a different value on a product, the product uses the Default Settings.
- If you do not set a different value on a variation, the variation uses the product’s delivery setting.
- Prefer “Currently using: Product Settings” over “Provenance: product”.

---

## Configuration Modes

Internal enum values stay in storage. User-facing labels:

| Internal | User-facing | Meaning |
|----------|-------------|---------|
| INHERIT | Use inherited setting | Keep the value from the level above |
| OVERRIDE | Set a different value here | Replace the inherited scalar |
| DISABLE | Turn off | Intentionally none for this item |
| ADD | Add to inherited options | Keep inherited options and add more |
| REMOVE | Remove from inherited options | Keep inherited options except these |
| REPLACE | Use only these options | Ignore inherited options |
| REPLACE [] | No delivery options for this setup | Explicitly empty list, not “inherit nothing” |

Never show `REPLACE []` as mathematical/programming syntax in primary UI.

---

## Status Language

| Internal | User-facing |
|----------|-------------|
| VALID | Ready |
| UNRESOLVED | Needs configuration |
| INVALID | Configuration problem |
| DISABLED | Disabled / Turned off |
| UNRESOLVED_GLOBAL_VALUE | No default value has been set, and this product does not provide its own value. |

Do not expose raw reason codes as the main message. Codes may appear under Technical details.

---

## Warnings

Explain consequence and action.

Bad: “Legacy category dependency detected.”

Good: “This product still gets its delivery settings from a legacy category rule. The new delivery settings system will not take control of this product until that legacy dependency is resolved.”

---

## Empty States

Every unconfigured screen should say what happens now and what to do next.

- Product: “No product-specific delivery settings have been added. This product will continue using the Default Settings until you make a change here.”
- Variation: “No variation-specific delivery settings have been added. This variation currently uses its parent product's delivery settings.”
- Default: “No default delivery settings have been saved yet. Add the values your products should use unless a product or variation sets its own.”

---

## Error Messages

Tell the staff member what is wrong and what to do. Do not name internal classes or reason-code identifiers in the primary sentence.

---

## Technical Details Boundary

Collapsed **Technical details** (or Advanced / Developer information) may show:

- internal source
- internal IDs
- slice key
- config version
- reason codes
- fingerprints where privacy allows
- migration metadata

Normal operation must not depend on reading that section.

---

## Technical diagnostic tools

**Technical diagnostic tools are not part of normal staff workflow.**

They appear in a collapsed section on Legacy Delivery Rules (and Rate Cards). Ordinary staff do not need them for daily delivery setup.

Heading: **Technical diagnostic tools**

Intro: “These tools are intended for technical support and troubleshooting. You do not need them for normal delivery setup or daily operations.”

| Old wording | Normal-user wording |
|-------------|---------------------|
| Staff testing tools | Technical diagnostic tools |
| Test product rule resolution | Check which legacy delivery rule applies |
| Run resolution test | Check applicable rule |
| Test delivery selection validation | Check a delivery choice |
| Display key | Delivery choice identifier |
| `enable_product_delivery_selector` | Show delivery choices on product pages |
| Target type / Target ID | Item type / Item ID |
| product / variation / category | Product / Product variation / Product category |

Raw internal keys, identifier syntax (`availability:choice:suffix`), and examples such as `in_store:delivery:12` belong only under **Developer information**.

---

## Business-term help text

Keep the business terms. Always explain what they control:

| Term | Meaning for staff |
|------|-------------------|
| Fulfilment availability | Choose where this item is fulfilled from (In Store, In Warehouse, or International). This affects which delivery methods can be offered. |
| Logistics profile | Groups the delivery handling rules used to fulfil an item, such as how it is dispatched or which delivery services can be used. |
| Priority | Decides which delivery setup takes precedence if more than one setup could apply. A **lower number is considered first**. Most products can leave this unchanged (default 100). |
| Rate Card | Contains the delivery prices used to calculate shipping. Each card connects a delivery zone and a delivery offer to a fee. |

---

## Customer Language Boundary

Customer-facing strings stay operational. Stage 6 wording:

| State | String |
|-------|--------|
| No variation | Select your product options to see delivery choices. |
| Loading | Loading delivery choices… |
| No options | Delivery options are not available for this variation. |
| Request failure | Delivery options are temporarily unavailable. Please try again. |
| Stale | Your selected delivery option is no longer available. Please choose again. |

No developer terminology on customer surfaces.

---

## Approved Business Terms

These may remain when defined with concise help text:

- Delivery Offer
- Supplier
- Origin
- Logistics Profile
- Pickup Location
- Rate Card
- Destination Zone
- Fulfilment
- Same-Day Express
- In Warehouse
- In Store
- International

---

## Internal Terms Not Allowed in Primary UI

Do not use these as primary labels, headings, notices, or empty states:

- `enable_product_delivery_selector` and other raw `enable_*` option keys
- `display_key`
- `availability:choice:suffix`
- Staff testing tools
- feature flag
- EffectiveConfigurationResolver
- ECR (except inside Advanced / Technical details)
- Scoped Configuration / scope / `scope_id` / `scope_type`
- `slice_key` / native root slice
- configuration fingerprint / `config_version`
- provenance / resolver / runtime route
- `LEGACY_CATEGORY_COMPATIBILITY`
- `UNRESOLVED_GLOBAL_VALUE`
- `REPLACE []`
- Stage 2–Stage 6 as operator-facing labels
- internal class or service names
- migration traceability jargon

Allowlist: the terms may appear in Technical details, Advanced system details, developer documentation, and the forbidden-term registry used by tests (`AdminLanguage::forbidden_primary_terms()`).

---

## Examples: Before → After

| Before | After |
|--------|-------|
| Scoped Configuration | Delivery Settings |
| Effective Configuration Preview | Delivery Settings Preview |
| Global | Default Settings |
| Product override | Product Settings |
| Valid | Ready |
| Unresolved | Needs configuration |
| Provenance: product | Currently using: Product Settings |
| REPLACE [] | No delivery options for this setup |
| enable_effective_configuration_runtime | Use Site-wide Defaults at checkout |
| enable_product_delivery_selector | Show delivery choices on product pages |
| Staff testing tools | Technical diagnostic tools |
| Display key | Delivery choice identifier |
| No scoped records found | No product-specific delivery settings have been added… |
| Legacy category dependency detected | This product still gets its delivery settings from a legacy category rule… |
