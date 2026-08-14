# Stage 13B — Admin Screen Inventory

**Document status:** RC.3 UI source of truth  
**Shell:** WordPress Dashboard → left admin menu → Delivery Engine → normal wp-admin content area  
**Menu icon:** `dashicons-location-alt`

| Page | Menu location | Primary audience | Business purpose | Primary action | Secondary actions | Advanced/technical content | Design Reference image |
|------|---------------|------------------|------------------|----------------|-------------------|----------------------------|------------------------|
| Overview | Delivery Engine → Overview (parent) | Daily staff | Is delivery configured? What needs attention? | Edit defaults / review exceptions | Preview, charges, options | System status link | `Dashboard.png` |
| Setup Wizard | Hidden; opens on first incomplete setup or Settings → Run Setup Guide Again | First-time installer / review | Guided canonical setup | Continue / Save & Apply Site-wide | Save & finish later; contextual create | None | None (brief) |
| Site-wide Defaults | Delivery Engine → Site-wide Defaults | Daily staff | Set normal delivery rules once | Save Changes | Open wizard Apply if setup incomplete | Private IDs not shown | `Delivery Settings.png` |
| Delivery Options | Delivery Engine → Delivery Options | Daily staff | Reusable customer delivery choices | Add Delivery Option | Edit, Deactivate | Reference code / timing under editor Advanced | `Delivery Options.png` |
| Delivery Option editor | Delivery Options → Add/Edit | Daily staff | Create/edit one option | Save | Cancel, permanent delete | Timing and carrier details collapsed | None (brief) |
| Delivery Areas | Delivery Engine → Delivery Areas | Daily staff | Where the business delivers | Add Delivery Area | Edit, Test an Address | Match rules / test tool | `Delivery Areas.png` |
| Delivery Area editor | Delivery Areas → Add/Edit | Daily staff | Define an area and options | Save | Cancel | Postcode/priority progressive disclosure | None (brief) |
| Delivery Charges | Delivery Engine → Delivery Charges | Daily staff | How much customers pay | Add Delivery Charge | Edit, Deactivate | Internal charge type mapped to plain language | `Delivery Charges UI.png` |
| Delivery Charge editor | Delivery Charges → Add/Edit | Daily staff | Flat / per item / advanced | Save | Test this charge | Advanced pricing after Advanced is chosen | None (brief) |
| Pickup Locations | Delivery Engine → Pickup Locations | Daily staff | Customer collection points | Add Pickup Location | Edit, Deactivate | Not Origins | `Pickup Locations.png` |
| Product Exceptions | Delivery Engine → Product Exceptions | Daily staff | Which products differ from defaults | View/Edit | Reset to Site-wide / Product Settings | No hashes/modes | None (brief) |
| Needs Attention | Delivery Engine → Needs Attention | Daily staff | Operational to-do list | Fix Now | Open product | No resolver stack | None (brief) |
| Settings | Delivery Engine → Settings | Admins | Customer/order behaviour + setup guide | Save Changes | Run Setup Guide Again | Feature flags collapsed as Advanced | `Settings.png` |
| Legacy Delivery Rules | Secondary | Support / migration | Older compatibility settings | Review Legacy Products | Existing edit/deactivate | Technical diagnostic tools on this page | `Legacy Delivery Rules.png` |
| Technical Diagnostic Tools | Secondary | Support | Health + copyable diagnostics | Copy/resync as existing | — | Full diagnostic tables | None (brief) |
| Delivery Settings Preview | Secondary | Staff checking a product | What will this product use? | Show Preview | Open Site-wide Defaults | Technical details collapsed | `Delivery Settings Preview.png` |
| Product Delivery panel | WooCommerce product editor | Catalog staff | Default vs customized product delivery | Customize This Product | Preview Delivery | Advanced fields on scoped editor | None (brief) |
| Variation Delivery panel | WooCommerce variation editor | Catalog staff | Default vs customized variation delivery | Customize This Variation | Reset to Product Settings | No copied parent dump | None (brief) |
| Product/Variation scoped editor | Hidden deep link | Catalog staff | Field-level inheritance | Save delivery settings | Reset | Supplier/origin/logistics/priority under Advanced Details | None (brief) |
| Suppliers & Origins | Secondary, capability-gated | Private ops | Private sources | Edit | — | Private by design | `Suppliers & Origins.png` |
| Logistics Profiles | Secondary, capability-gated | Private ops | Internal logistics grouping | Edit | — | Private by design | None |

## First-time vs returning

- Incomplete setup: entering Delivery Engine opens the wizard and resumes the saved step.
- Setup complete: entering Delivery Engine opens Overview. The wizard does not auto-reopen.
- Settings → Run Setup Guide Again: review mode, current configuration, exceptions protected.
