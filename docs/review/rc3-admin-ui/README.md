# RC.3 Admin UI Review Pack

**Purpose:** Owner visual review of the actual Stage 13B admin UI before RC.3 packaging.  
**Not:** a design proposal, mockup pack, staff-training screenshot set, or live FLAIROC capture.

| | |
|---|---|
| Capture date | 13 August 2026 (Stage 13B-R2 full pack); **14 August 2026 Stage 13C-R1 subset** |
| Branch | `feat/site-wide-delivery-defaults` (uncommitted Stage 13C-R1 work) |
| Public version shown | `1.0.0-rc.2` (unchanged) |
| Capture method | **FIXTURE RENDER — NOT LIVE WORDPRESS** |
| Live WordPress | Not available locally |
| FLAIROC | Not used / not modified |
| Viewport (primary) | 1440 × 1000, full-page screenshot |
| Plugin chrome | WordPress admin bar + left nav + Delivery Engine selected + page content |

## Capture method

No local WordPress admin instance was available. FLAIROC was not used.

Each screen is a **fixture render** of the real Stage 13B PHP page classes, CSS, and copy:

1. CLI harness (`docs/review/rc3-admin-ui/harness/render-screens.php`) boots WordPress/WooCommerce stubs, in-memory repositories, and a sample catalog.
2. Actual presentation classes (`SetupWizardPage`, `OverviewPage`, list/editor pages, `ProductDeliveryPanel`, `ScopedConfigurationPage`, `EffectiveConfigurationPreviewPage`, `SystemStatusPage`, etc.) render HTML.
3. Playwright (Chrome channel) captures one PNG per HTML file.

Every screenshot includes a yellow banner:

> Fixture render — sample catalog data — not a live WordPress screenshot

Do not treat these as live WordPress screenshots.

Sample catalog used throughout:

| ID | Label | Role |
|---|---|---|
| 101 | Simple Lamp | inherits In Warehouse site-wide defaults |
| 201 / 202 | Variable Chair / Oak | variation inherits product settings |
| 201 / 203 | Variable Chair / Walnut | variation-specific estimated delivery |
| 301 | International Radio | product-specific estimated delivery |
| 501 | Legacy Sofa | legacy compatibility product |
| 601 | Incomplete Desk | no usable delivery option |

## Design Reference compared against

Source folder: `Design Reference/`  
Map: `docs/STAGE-13B-DESIGN-REFERENCE-MAP.md`

| Design Reference | Compared screens |
|---|---|
| `Dashboard.png` | 09 |
| `Delivery Settings.png` | 10 |
| `Delivery Options.png` | 11, 12 |
| `Delivery Areas.png` | 13, 14 |
| `Delivery Charges UI.png` | 15, 16 |
| `Pickup Locations.png` | 17 |
| `Settings.png` | 20 |
| `Legacy Delivery Rules.png` | 21 |
| `Delivery Settings Preview.png` | 27, 28 |
| `Suppliers & Origins.png` | not a primary Stage 13B screen (kept secondary) |
| No Design Reference file | 01–08 wizard, 18 exceptions, 19 needs attention, 22 diagnostics, 23–26 product/variation |

## Screenshot index

### Wizard (8)

| Filename | Page / state | Capture method | Actual implementation or fixture | Design Reference | Known limitations |
|---|---|---|---|---|---|
| `01-wizard-store-setup.png` | Wizard step 1 — Store Setup | Fixture render | `SetupWizardPage` store step | None (wizard) | Heading is **Set up Delivery**. Store country/currency are WooCommerce read-only. |
| `02-wizard-fulfilment-types.png` | Wizard step 2 — Fulfilment Types | Fixture render | Active In Warehouse / In Store / International cards | None | Later wizard headers say **Continue Setup**, not “Set up Delivery”. |
| `03-wizard-warehouse-defaults.png` | Wizard step 3a — In Warehouse 1/3 | Fixture render | Delivery only, options, charges, ETA, contextual create | None | Primary button is **Save In Warehouse Default & Continue**. Charge rows use staff summaries (Standard Delivery — GHS 60.00). |
| `04-wizard-in-store-defaults.png` | Wizard step 3b — In Store 2/3 | Fixture render | Delivery + Store Pickup, pickup location, Add Pickup Location | None | No Air/Sea options shown (correct). Pickup is labelled **Store Pickup — No delivery charge**. |
| `05-wizard-international-defaults.png` | Wizard step 3c — International 3/3 | Fixture render | Delivery only, Air Shipping, Sea Shipping, ETA, charges | None | **Processing / transit fields are not in the implemented wizard.** Distinct Air/Sea charge rows. |
| `06-wizard-areas-charges.png` | Wizard step 4 — Areas & Charges | Fixture render | Area summary table + Add Area / Add Charge | None | Delivery Charge column uses staff summaries. Final column is **Actions**. |
| `07-wizard-apply-products.png` | Wizard step 5 — Apply Site-wide Defaults | Fixture render | Fixture buckets: 3 / 2 / 1 / 1 / 0 | None | Counts are **sample/fixture data**. Copy states exceptions will not be overwritten. |
| `08-wizard-finish.png` | Wizard step 6 — Finish | Fixture render | Ready summary + Overview / Needs Attention actions | None | Header remains **Continue Setup**. Counts are fixture data. |

### Everyday plugin pages (14)

| Filename | Page / state | Capture method | Actual implementation or fixture | Design Reference | Known limitations |
|---|---|---|---|---|---|
| `09-overview.png` | Overview (post-setup) | Fixture render | `OverviewPage` | `Dashboard.png` | Status banner, summary cards, fulfilment cards, next-step cards. Not a settings form. |
| `10-site-wide-defaults.png` | Site-wide Defaults — In Warehouse | Fixture render | `DeliverySettingsHomePage` | `Delivery Settings.png` | In Warehouse lists only compatible local delivery. Charge help text + Manage Delivery Charges. No Store Pickup / Air / Sea. |
| `11-delivery-options.png` | Delivery Options list | Fixture render | `DeliveryOffersPage` | `Delivery Options.png` | WordPress `widefat` table. Summary still says “offers”. |
| `12-delivery-option-editor.png` | Edit Delivery Option | Fixture render | Offer editor | `Delivery Options.png` | Page title is **Edit Delivery Offer**. Business fields first. **Timing and carrier details** collapsed. Save button is **Save Offer**. |
| `13-delivery-areas.png` | Delivery Areas list | Fixture render | `DestinationZonesPage` | `Delivery Areas.png` | List title is Delivery Areas; internal copy still says **zones** (“Total zones”, “All delivery zones”). |
| `14-delivery-area-editor.png` | Edit Delivery Area | Fixture render | Condition builder | `Delivery Areas.png` | Location conditions only. Match mode / priority under **Advanced matching**. |
| `15-delivery-charges.png` | Delivery Charges list | Fixture render | `RateCardsPage` | `Delivery Charges UI.png` | Plain language: Flat amount per delivery / Amount per item. Charge names still include area/option codes. **Deactivate permanently** wraps in the actions column. |
| `16-delivery-charge-editor.png` | Edit Delivery Charge | Fixture render | Rate card editor | `Delivery Charges UI.png` | Edit preloads saved area (Greater Accra) and option (Standard Delivery). |
| `17-pickup-locations.png` | Pickup Locations list | Fixture render | `PickupLocationsPage` | `Pickup Locations.png` | One fixture location (Accra Showroom). |
| `18-product-exceptions.png` | Product Exceptions | Fixture render | `ProductExceptionsPage` | None | Columns: Product / Fulfilment / Exception / Customized / Status / Actions. Ready / Needs Attention badges. |
| `19-needs-attention.png` | Needs Attention | Fixture render | `NeedsAttentionPage` | None | Incomplete Desk: “No usable delivery option is configured.” + **Fix Now**. No separate “why it matters” column. |
| `20-settings.png` | Settings | Fixture render | `DeliverySettingsPage` | `Settings.png` | Groups are **General / Checkout behavior / Orders / Setup Guide / Access**. Not labelled “Customer Experience”. Advanced collapsed. **Run Setup Guide Again** present. Runtime flags are not the primary screen. |
| `21-legacy-delivery-rules.png` | Legacy Delivery Rules | Fixture render | `ProductDeliveryRulesPage` | `Legacy Delivery Rules.png` | Migration guidance only. Does not teach building new legacy rules. |
| `22-technical-diagnostics.png` | Technical Diagnostic Tools | Fixture render | `SystemStatusPage` + operations dashboard | None | Health/readiness first; **Advanced system details** collapsed. **Copy Diagnostic Report** present. Capability-restricted (`view_delivery_diagnostics`). |

### Product / variation (4)

| Filename | Page / state | Capture method | Actual implementation or fixture | Design Reference | Known limitations |
|---|---|---|---|---|---|
| `23-product-using-defaults.png` | WooCommerce product editor — inheriting defaults | Fixture render of `ProductDeliveryPanel` inside WP/Woo product chrome | Simple Lamp | None | Products menu selected (correct for product editor). Currently using In Warehouse Site-wide Defaults. Preview + Customize. |
| `24-product-customized.png` | Customize delivery for International Radio | Fixture render of `StaffDeliveryCustomizeView` | International Radio | None | Business controls only. No INHERIT/OVERRIDE enum labels. |
| `25-variation-inherited.png` | Variation inheriting product settings | Fixture render of `ProductDeliveryPanel` | Variable Chair — Oak | None | Currently using Product Settings. Customize This Variation. |
| `26-variation-customized.png` | Customize delivery for Walnut variation | Fixture render of `StaffDeliveryCustomizeView` | Chair / Walnut | None | Inherit copy is **Use Product Setting**. Reset to Product Settings. |

### Preview (2)

| Filename | Page / state | Capture method | Actual implementation or fixture | Design Reference | Known limitations |
|---|---|---|---|---|---|
| `27-delivery-preview-ready.png` | Delivery Settings Preview — Ready | Fixture render | Simple Lamp (101) | `Delivery Settings Preview.png` | Product selector (no raw IDs). Information / Result / Source. Supplier/origin/logistics under Technical details. |
| `28-delivery-preview-needs-attention.png` | Delivery Settings Preview — Incomplete Desk | Fixture render | Incomplete Desk (601) | `Delivery Settings Preview.png` | Overall **Needs Attention**. Delivery option row is also Needs Attention. Same cause as Needs Attention list. |

### Responsive (optional, 3)

| Filename | Page / state | Capture method | Actual implementation or fixture | Design Reference | Known limitations |
|---|---|---|---|---|---|
| `29-wizard-tablet.png` | Wizard step 1 at 768 × 1024 | Fixture render | Same as 01 | None | Simulated tablet viewport of fixture chrome, not a device lab capture. |
| `30-overview-tablet.png` | Overview at 768 × 1024 | Fixture render | Same as 09 | `Dashboard.png` | Same limitation. |
| `31-product-panel-mobile-admin.png` | Product panel at 390 × 844 | Fixture render | Same as 23 | None | Fixture chrome stacks product tabs at 782px so the Delivery panel is full-width. Not a live wp-admin capture. |

## Known visual limitations (pack-wide)

1. **FIXTURE RENDER, not live WordPress.** Admin bar, left nav, and Woo product chrome are harness approximations of wp-admin, using real plugin CSS plus a local WP-like chrome stylesheet.
2. Yellow fixture banner is intentionally visible so these cannot be mistaken for live screenshots.
3. Catalog counts and labels are sample/fixture data.
4. Primary captures are full-page, so tall pages exceed 1000px height while width stays 1440.
5. Wizard contextual create panels stay collapsed when a usable entity exists.
6. International processing/transit fields requested for screen 05 are not implemented.
7. Fixture top navigation is harness chrome, not live WordPress behavior. Do not treat leftover chrome quirks as plugin defects.

## What this pack does not include

- Live FLAIROC or production screenshots
- Customer storefront / checkout screens
- RC.3 package build
- Staff training screenshots (`docs/training/`)

## Regenerating

From the repository root:

```bash
php docs/review/rc3-admin-ui/harness/render-screens.php
node docs/review/rc3-admin-ui/harness/capture.mjs
# Optional: capture a subset (PNG names without extension)
node docs/review/rc3-admin-ui/harness/capture.mjs 09-overview 20-settings
```

Harness files are review-only. They are not plugin runtime.

Stage 13C-R1 recaptured a subset only (see `docs/STAGE-13C-R1-OWNER-REVIEW-REPAIR.md` §6). Screens `02`–`08`, `10`, `12`, `17`, and `22` were left on the 13 August pack.
