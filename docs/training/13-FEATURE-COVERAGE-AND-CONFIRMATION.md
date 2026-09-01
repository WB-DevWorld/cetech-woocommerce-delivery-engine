# Feature coverage and confirmation

**Audience:** Trainers and administrators  
**Plugin:** CETECH WooCommerce Delivery Engine **1.0.0-rc.9** (schema **5**). Includes Cart/Checkout Blocks, overlapping Delivery Area pricing, and constrained fallback. Tagged **1.0.0-rc.8** remains unchanged.  
**Everyday home:** WordPress admin → **Delivery Engine → Overview**

Every implemented staff-facing feature is either **trained** (staff can do the work from a written guide) or **confirmed** (a trainer or administrator ticks a live pass/fail check). Reading this page is not training. Use the linked guides, then tick the checks on the live site.

Do **not** train or confirm visionary features listed under [Not this release](#not-this-release). Do not invent free shipping. Do not complete paid orders unless an administrator authorised a test purchase.

QA products: simple **#39705**; variable **#39717** / A **#39718** / B **#39719**. Read-only orders: **#39721**, **#39724**.

---

## How to use this page

1. New staff complete [05 — Staff Training Manual](05-STAFF-TRAINING-MANUAL.md) and pass [06 — Trainer Guide](06-TRAINER-GUIDE.md).  
2. Administrators also complete [12 — How to use each menu](12-SETUP-CONFIGURE-AND-TEST.md) and the administrator checks below.  
3. A trainer walks the [Confirmation register](#confirmation-register) on the live screens and records Pass / Fail / N/A.  
4. Fail means the feature is not confirmed for that person or that site. Do not tick Pass from memory.

**Pass** = the person demonstrated the live screen, or the site behaved as written.  
**Fail** = missing, wrong, or the person could not do it.  
**N/A** = the store does not use that path (for example Shipments off, no International catalogue, Classic-only checkout). Write why.

---

## Confirmation register

Tick on the live training site. Keep one copy per trainee (or per site smoke).

### A. Everyday admin (all staff)

| # | Feature | Train in | Confirm on live | Pass |
|---|---------|----------|-----------------|------|
| A1 | Overview is the everyday home | [00](00-START-HERE.md), [05](05-STAFF-TRAINING-MANUAL.md) M1 | Open **Delivery Engine → Overview**. Name readiness, defaults, Needs Attention. | ☐ |
| A2 | Everyday menu (no hunting old names) | [00](00-START-HERE.md), [05](05-STAFF-TRAINING-MANUAL.md) M2 | Point to Overview, Site-wide Defaults, Options, Areas, Charges, Pickup Locations, Product Exceptions, Needs Attention, Settings. | ☐ |
| A3 | Site-wide Defaults per fulfilment type | [02](02-COMPLETE-ADMIN-GUIDE.md), [05](05-STAFF-TRAINING-MANUAL.md) M6 | Open Site-wide Defaults. Name In Warehouse, In Store, International, and the primary default. | ☐ |
| A4 | Field-by-field inheritance | [01](01-QUICK-START.md), [05](05-STAFF-TRAINING-MANUAL.md) M3 | On **#39705**, show **Currently using** Site-wide Default. Explain that changing Estimated delivery does not freeze Delivery Options. | ☐ |
| A5 | Delivery Options (public names) | [12](12-SETUP-CONFIGURE-AND-TEST.md) §2 | Open Delivery Options. Name one public option customers would see. | ☐ |
| A6 | Delivery Areas + region name and short code | [12](12-SETUP-CONFIGURE-AND-TEST.md) §4 | **Test an address** with `Greater Accra` **and** `AA` (or the store’s equivalent). Both name the same area. | ☐ |
| A7 | Test an address Primary match / Also matches | [12](12-SETUP-CONFIGURE-AND-TEST.md) §4, playbook 31 | For Accra city (or the store’s city-in-region pair): **Primary match** is the more-specific area; **Also matches** lists the broader area. Country is chosen by name (Ghana), not typed as `GH`. | ☐ |
| A7b | Constrained vs global fallback | [12](12-SETUP-CONFIGURE-AND-TEST.md) §4 | Greater Accra Fallback with Ghana + Greater Accra rules does **not** match United States / New York. A ruleless Fallback can catch leftover addresses. No match stays unmatched. | ☐ |
| A8 | Delivery Charges + Check a delivery price | [12](12-SETUP-CONFIGURE-AND-TEST.md) §5 | **Check a delivery price** for a known option + area shows the configured fee, not a silent 0. | ☐ |
| A9 | Pickup Locations (if pickup is offered) | [12](12-SETUP-CONFIGURE-AND-TEST.md) §6 | At least one active Pickup Location exists when Store pickup is offered. | ☐ |
| A10 | Product exception (single field) | playbook 2, [05](05-STAFF-TRAINING-MANUAL.md) M4 | On QA only: customize one field, Preview, then **Reset to Site-wide Defaults**. | ☐ |
| A11 | Variable inherit vs variation exception | playbook 7–8, [05](05-STAFF-TRAINING-MANUAL.md) M5 | **#39718** follows parent; **#39719** can differ. Preview each. | ☐ |
| A12 | Preview Delivery Ready | playbook 10 | Preview for **#39705** is **Ready**. Source is understandable. | ☐ |
| A13 | Needs Attention first checks | playbook 19, [07](07-TROUBLESHOOTING-FAQ.md) | Open Needs Attention. Explain that opening the list does not clear a task. | ☐ |
| A14 | Product Delivery tab | [01](01-QUICK-START.md) | WooCommerce → Products → **#39705** → **Delivery**. | ☐ |
| A15 | Boundaries | [05](05-STAFF-TRAINING-MANUAL.md) M12 | Staff name Settings / Access / Technical Diagnostics / private supply as administrator or support. | ☐ |

### B. WooCommerce shipping and Settings (administrators)

| # | Feature | Train in | Confirm on live | Pass |
|---|---------|----------|-----------------|------|
| B1 | Setup Guide path | [12](12-SETUP-CONFIGURE-AND-TEST.md) §1 | Setup complete, or **Settings → Run Setup Guide Again** opens review without wiping exceptions. | ☐ |
| B2 | WooCommerce **Delivery** shipping method | [12](12-SETUP-CONFIGURE-AND-TEST.md) WooCommerce shipping | **Delivery** is on the zones where this plugin should operate. Rest of the World only if leftover addresses are intended. | ☐ |
| B3 | Settings status (Active / Ready) | [12](12-SETUP-CONFIGURE-AND-TEST.md) Settings | General shows Active, setup Complete, WooCommerce shipping Ready. | ☐ |
| B4 | Customer order and email details | [12](12-SETUP-CONFIGURE-AND-TEST.md) Settings | Tick boxes for customer order pages and emails only if the store wants them. Confirm they are a choice, not a hidden experimental switch. | ☐ |
| B5 | Save delivery details on orders | [12](12-SETUP-CONFIGURE-AND-TEST.md) Settings | **Save delivery details on orders** stays on after Activate. | ☐ |
| B6 | Access matrix | [02](02-COMPLETE-ADMIN-GUIDE.md) Settings | Administrator row is locked. Shop Manager (or another real role) can be granted only the needed capabilities. | ☐ |
| B7 | Restore Administrator Access | [02](02-COMPLETE-ADMIN-GUIDE.md) | Staff know the notice is for users who can manage WordPress options, and it is **not** inside Technical Diagnostics. Do not practise lockout. | ☐ |
| B8 | Cart & Checkout Blocks status (not a toggle) | [02](02-COMPLETE-ADMIN-GUIDE.md) Settings | Settings shows **WooCommerce Cart & Checkout Blocks** as status. There is no experimental “turn on Blocks to fix shipping” checkbox. | ☐ |
| B9 | Optional integrations are status only | [02](02-COMPLETE-ADMIN-GUIDE.md) Settings | **Optional integrations** lists detected plugins. These are not compatibility switches. WoodMart / WPML / WCML / WCFM / VitePOS adapters are not required for core delivery. | ☐ |
| B10 | Advanced left collapsed | [12](12-SETUP-CONFIGURE-AND-TEST.md) Settings | Advanced stays collapsed. Do not flip leftover experimental switches. | ☐ |
| B11 | Native leftover methods fail closed | playbook 40 | For a Delivery Engine cart, WooCommerce **Flat rate** / **Local pickup** on the same zone must not quietly replace the Delivery Engine fee. Missing Delivery Engine pricing must not become silent free shipping. | ☐ |

### C. Customer journey (Classic and Blocks)

| # | Feature | Train in | Confirm on live | Pass |
|---|---------|----------|-----------------|------|
| C1 | Compact product selector | playbook 11, [05](05-STAFF-TRAINING-MANUAL.md) M8 | **#39705**: bold public option + **Estimated delivery**. No “In Warehouse”, supplier, or internal code. | ☐ |
| C2 | Variable selector and A→B refresh | playbook 12–13 | Select **#39718**, then switch to **#39719**. Options refresh. An invalid previous choice does not silently stay. | ☐ |
| C3 | Cart remembers the choice | playbook 11, [04](04-VISUAL-WALKTHROUGH.md) §15 | Add to cart. Cart still shows the selected Delivery option. | ☐ |
| C4 | Classic Checkout fee and label | playbook 38 | On Classic Checkout, shipping amount matches the Delivery Charge. Label prefers the public Delivery Option name. | ☐ |
| C5 | Cart/Checkout Blocks fee and label | playbook 39 | On Cart/Checkout **Blocks**, same selected option, same fee, same public label. Place Order is not completed unless authorised. | ☐ |
| C6 | Compatible items share one fee | playbook 14 | Read-only **#39724** (shipping **25.00**, not 50.00) or a QA cart without payment. | ☐ |
| C7 | Incompatible paths stay separated | playbook 15 | Mixed fulfilment does not collapse into one wrong charge. | ☐ |
| C8 | Quantity does not always multiply delivery | playbook 16 | Qty 2 of a compatible item still one group fee unless the charge is per-item. | ☐ |
| C9 | Mixed In Store Delivery + Pickup | playbook 34 | One Delivery item and one Pickup item in the same cart. Delivery shows the real fee (example GHS 50). Pickup shows FREE / 0. No false “Delivery pricing is not available”. | ☐ |
| C10 | Pickup is valid at 0 | playbook 35 | Store pickup shipping amount **0.00** is allowed. It is not treated as missing configuration. | ☐ |
| C11 | Pickup is not “shipping to” the customer address | playbook 34, [04](04-VISUAL-WALKTHROUGH.md) §15 | Pickup lines do not present as shipping the goods to the customer destination. | ☐ |
| C12 | International Air and/or Sea only | playbook 6, 36 | International products offer Air and/or Sea only. If only one of those is configured, checkout uses that one option. Do not add local delivery or pickup to International. | ☐ |
| C13 | In Warehouse is local delivery only | playbook 3, 37 | Warehouse products do not offer Air, Sea, or Store pickup. | ☐ |
| C14 | Broader-area pricing when the city has no charge | playbook 32 | Accra (city) + Greater Accra (region): Air (or the selected option) can use the broader area’s charge. Do **not** copy the same charge onto every city. | ☐ |
| C15 | Invalid more-specific charge fail-closed | playbook 33 | If the city charge for that option is invalid, checkout does **not** silently inherit the broader fee. It fails closed. | ☐ |
| C16 | Thank-you / My Account / email compact details | [04](04-VISUAL-WALKTHROUGH.md) §17 | Delivery option + Estimated delivery. Pickup extras only if present. No staff-only fields. | ☐ |
| C17 | Historical order Delivery information | playbook 17–18 | **#39721** / **#39724** still show what was purchased. Later Site-wide Default edits do not rewrite them. | ☐ |

### D. Shipments (only if an Administrator has turned records on)

Train in [11](11-STAGE-14-SHIPMENTS.md) and playbook 20–30. Confirm each that applies:

| # | Feature | Pass |
|---|----------|------|
| D1 | Enable shipment records (Administrator) — playbook 20 | ☐ |
| D2 | Find a shipment; it is not a new order — playbook 21 | ☐ |
| D3 | Happy-path statuses — playbook 22 | ☐ |
| D4 | Save tracking; customer **Track shipment** only with a safe URL — playbook 23 | ☐ |
| D5 | Create shipment from a COD (or similar) Order ID after preview — playbook 24 | ☐ |
| D6 | Air and Sea stay separate jobs — playbook 25 | ☐ |
| D7 | Update current estimate; original stays — playbook 26 | ☐ |
| D8 | Cancelled WooCommerce order behaviour — playbook 27 | ☐ |
| D9 | Refund needs fulfilment review — playbook 28 | ☐ |
| D10 | Customer View Order card (no staff names / private notes) — playbook 29 | ☐ |
| D11 | Store pickup is not a delivery shipment — playbook 30 | ☐ |

If **Shipments** is not in the menu, mark D1–D11 **N/A** and confirm staff know it stays off until Settings.

### E. Bulk Tools (authorised administrators only)

Train in [12](12-SETUP-CONFIGURE-AND-TEST.md) Bulk Tools and playbook 41–42. Do **not** run Apply on the live catalogue without a change window.

| # | Feature | Confirm on live | Pass |
|---|---------|-----------------|------|
| E1 | Menu **Delivery Engine → Bulk Tools** | Open Catalog, Import / Export, Validation & Cleanup, Jobs / History, Charges. | ☐ |
| E2 | Catalog Preview before Apply | Run a **Preview** on QA targets only. Read Would fail vs ready. Do not Apply a failed preview. | ☐ |
| E3 | Jobs / History | After an authorised Apply, the job appears. Wait for background batches. Do not click Apply repeatedly. | ☐ |
| E4 | Rollback | Authorised staff can roll back a completed job from Jobs / History when the screen offers it. | ☐ |
| E5 | Validation & Cleanup | Run a Validation Scan. It reports problems; it does not invent free shipping. | ☐ |
| E6 | Charges preview | Preview a Delivery Charge amount change on QA only. Apply and rollback only with authorisation. | ☐ |
| E7 | Import / Export | Staff know this is administrator-only. Do not import a package onto production without a change window. | ☐ |

If the trainee is not an administrator, mark E1–E7 **N/A** and confirm they will not open Bulk Tools without authorisation.

---

## Not this release

Train staff that these are **not** available. Do not demonstrate them as working features.

| Topic | What to say |
|-------|-------------|
| Customer timeline | Not in this plugin version. |
| Carrier APIs / automatic tracking updates | Staff type tracking by hand. The plugin does not log into carriers. |
| Shipment emails as a Delivery Engine product | WooCommerce order emails may include compact Delivery details when that Settings box is on. There is no separate carrier-email product. |
| WPML / WCML / WCFM / VitePOS adapters | Optional integrations show detection only. Core delivery does not depend on them. |
| WoodMart as a required integration | Core must work without WoodMart. Do not edit the WoodMart parent theme. |
| Stage 15 / per-item locations / Return-Refund as a Delivery Engine money workflow | Not this release. WooCommerce remains the owner of payments and refunds. |
| Stage 15 / later RC identities | Not this release. Protected published baseline is **1.0.0-rc.9**. Do not retag RC.8 or earlier. |

---

## Role paths

| Role | Must pass |
|------|-----------|
| Everyday catalog / order staff | A1–A15, C1–C3, C16–C17. C4 or C5 for the checkout type this store uses. |
| Checkout / storefront staff | Everyday set plus C4–C15 for every path the store actually uses (Classic, Blocks, pickup, International, overlapping areas). Mark unused paths N/A. |
| Shipment staff | Everyday set plus D1–D11 (or N/A if Shipments is off). |
| Administrator | All of the above that the store uses, plus B1–B11. Bulk Tools E1–E7 if that person will run bulk jobs. |

A trainee **fails** the course if they invent $0 shipping, change Settings without authorisation, rewrite a paid order’s delivery details, or propose PHP/SQL/SSH as a first check.

---

## Related guides

- [00 — Start here](00-START-HERE.md)  
- [06 — Trainer Guide](06-TRAINER-GUIDE.md)  
- [03 — Use-Case Playbook](03-USE-CASE-PLAYBOOK.md)  
- [12 — How to use each menu](12-SETUP-CONFIGURE-AND-TEST.md)  
- [11 — Stage 14 shipments](11-STAGE-14-SHIPMENTS.md)  
