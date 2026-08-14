# Stage 13B — Design Reference Map

**Document status:** Stage 13B implementation record  
**Folder:** `Design Reference/`  
**Rule:** Images are design references only. They are not runtime plugin assets.

| Filename | Target WordPress admin page | Adopted | Deliberately changed | Why | Implementation file(s) | Status |
|----------|-----------------------------|---------|----------------------|-----|------------------------|--------|
| `Dashboard.png` | Delivery Engine → Overview | Status banner, 3 summary cards, fulfilment default cards, next-step cards, wp-admin shell | Menu items follow the approved Stage 13B IA, not the screenshot’s shorter menu. “View system status” goes to Technical Diagnostic Tools. | Screenshot menus are inconsistent across files. Spec navigation is authoritative. | `src/Presentation/Admin/OverviewPage.php` | Adopted |
| `Delivery Settings.png` | Delivery Engine → Site-wide Defaults | Profile tabs, info notice, Save Changes, impact footnote, side-style status language | Did not copy day-of-week checkboxes, `$` charge input, or availability radios. Uses canonical Delivery Options / ETA / fulfilment fields. Button is Save Changes after setup. | Those screenshot fields are not the Stage 13 domain model. Charges live on Delivery Charges. | `src/Presentation/Admin/DeliverySettingsHomePage.php` | Adopted with domain mapping |
| `Delivery Options.png` | Delivery Engine → Delivery Options | Page title/subtitle, Add button, empty-state teaching copy, option/route/ETA/status columns | Did not ship decorative circular type icons or Duplicate as a primary action. Deactivate remains the safe status action. | Dashicons + WordPress table conventions. Duplicate is not a current domain operation. | `src/Presentation/Admin/DeliveryOffersPage.php` | Adopted |
| `Delivery Areas.png` | Delivery Engine → Delivery Areas | Title, Add Delivery Area, area/location/options/charge/status columns | Internal match-rule details stay in the editor/advanced test tool, not the list. | Spec: do not expose match-rule internals on the main list. | `src/Presentation/Admin/DestinationZonesPage.php` | Adopted |
| `Delivery Charges UI.png` | Delivery Engine → Delivery Charges | Title, Add Delivery Charge, name / how calculated / amount / used in / status | Internal `fixed_per_shipment` is mapped to “Flat amount per delivery”. Advanced tables stay in the editor. | First-time and everyday UI must use normal language. | `src/Presentation/Admin/RateCardsPage.php` | Adopted |
| `Pickup Locations.png` | Delivery Engine → Pickup Locations | Title, Add Pickup Location, location/address/readiness/status | Contact column moved behind editor; readiness is the list concern. | Matches the approved pickup list purpose. Origins remain private. | `src/Presentation/Admin/PickupLocationsPage.php` | Adopted |
| `Settings.png` | Delivery Engine → Settings | General / customer / orders / access / collapsed Advanced, Save Changes | Role-permission grid is explanatory, not a fake unsaved matrix. Setup Guide section added. Feature flags remain internal. | Spec: do not show raw runtime keys as the staff experience; do not invent unsupported permission storage. | `src/Presentation/Admin/DeliverySettingsPage.php` | Adopted with capability honesty |
| `Legacy Delivery Rules.png` | Delivery Engine → Legacy Delivery Rules | Warning that this is compatibility-only, review action, secondary placement | Did not promote “Add new rule” as a primary everyday action. | Spec: do not encourage creating new legacy configurations. | `src/Presentation/Admin/ProductDeliveryRulesPage.php` | Adopted |
| `Delivery Settings Preview.png` | Delivery Engine → Delivery Settings Preview | Product/variation lookup, Ready/Needs Attention, field provenance table, plain-language summary | Preview remains a dedicated admin page (not a SaaS drawer). Technical resolver terms stay collapsed. | Fits wp-admin and existing preview service. | `src/Presentation/Admin/EffectiveConfigurationPreviewPage.php` | Adopted |
| `Suppliers & Origins.png` | Delivery Engine → Suppliers & Origins (secondary) | Existing private page kept | Not added to primary navigation. | Spec: suppliers/origins remain private operational data. | `src/Presentation/Admin/SuppliersOriginsPage.php` | Kept secondary |

## Screens without a Design Reference file

Implemented from the Stage 13B brief, using the same WordPress-native card/table language:

- First-time setup wizard (steps 1–6)
- Product Exceptions
- Needs Attention
- Technical Diagnostic Tools
- WooCommerce product Delivery panel
- Variation delivery summary
- Contextual create option/charge/area/pickup panels
