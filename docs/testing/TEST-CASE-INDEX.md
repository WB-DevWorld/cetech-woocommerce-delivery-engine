# Delivery Engine Test Case Index

Use this page to find stable test IDs.

The detailed steps live in the linked module guides.

| Test ID family | Area | Guide |
| --- | --- | --- |
| DE-QA-BASE-* | Baseline / clean state | [01](./01-CLEAN-BASELINE.md) |
| DE-QA-ADMIN-* | Admin / setup | [02](./02-ADMIN-AND-SETUP.md) |
| DE-QA-GEO-* | Location Packs / geography | [03](./03-LOCATION-PACKS.md) |
| DE-QA-AREA-* | Delivery Areas | [04](./04-DELIVERY-AREAS.md) |
| DE-QA-OPTION-* | Delivery Options | [05](./05-DELIVERY-OPTIONS.md) |
| DE-QA-RATE-* | Delivery Charges | [06](./06-DELIVERY-CHARGES.md) |
| DE-QA-INHERIT-* | Global/Product/Variation inheritance | [07](./07-INHERITANCE-AND-EXCEPTIONS.md) |
| DE-QA-PDP-* | Product page / storefront | [08](./08-STOREFRONT.md) |
| DE-QA-CART-* | Cart/revalidation | [09](./09-CART-AND-CHECKOUT.md) |
| DE-QA-CHECKOUT-* | Checkout/shipping | [09](./09-CART-AND-CHECKOUT.md) |
| DE-QA-MULTI-* | Multi-item/multi-destination | [10](./10-MULTI-DESTINATION.md) |
| DE-QA-ORDER-* | Orders/snapshots | [11](./11-ORDERS-AND-SNAPSHOTS.md) |
| DE-QA-SHIP-* | Shipments/tracking | [12](./12-SHIPMENTS-AND-TRACKING.md) |
| DE-QA-ATTN-* | Needs Attention | [13](./13-NEEDS-ATTENTION.md) |
| DE-QA-BULK-* | Bulk Tools | [14](./14-BULK-TOOLS.md) |
| DE-QA-ROLE-* | Roles/security | [15](./15-ROLES-AND-SECURITY.md) |
| DE-QA-COMPAT-* | Compatibility | [16](./16-COMPATIBILITY.md) |
| DE-QA-FAIL-* | Failure/recovery | [17](./17-FAILURE-AND-RECOVERY.md) |
| DE-QA-REG-* | Final regression | [18](./18-FINAL-REGRESSION.md) |

## Core minimum IDs

- DE-QA-BASE-001 — Release identity
- DE-QA-BASE-002 — Clean baseline truth
- DE-QA-ADMIN-001 — Main admin navigation
- DE-QA-ADMIN-002 — Setup Guide
- DE-QA-GEO-001 — Ghana pack Ready
- DE-QA-GEO-002 — Ghana hierarchy
- DE-QA-AREA-001 — Create/save Delivery Area
- DE-QA-AREA-002 — Include/exclude coverage
- DE-QA-OPTION-001 — Create/edit Delivery Option
- DE-QA-RATE-001 — Create/edit Delivery Charge
- DE-QA-INHERIT-001 — Site-wide → Product
- DE-QA-INHERIT-002 — Product → Variation
- DE-QA-PDP-001 — Simple-product quote
- DE-QA-PDP-002 — Variable-product recalculation
- DE-QA-CART-001 — Persistence
- DE-QA-CART-002 — Revalidation
- DE-QA-CHECKOUT-001 — Genuine WooCommerce shipping
- DE-QA-CHECKOUT-002 — Checkout failure handling
- DE-QA-MULTI-001 — Multi-item grouping
- DE-QA-MULTI-002 — Multi-destination
- DE-QA-ORDER-001 — Purchase-time snapshot
- DE-QA-SHIP-001 — Shipment creation/idempotency
- DE-QA-SHIP-002 — Customer-safe tracking
- DE-QA-ATTN-001 — Catalog attention
- DE-QA-BULK-001 — Dry run
- DE-QA-ROLE-001 — Administrator
- DE-QA-ROLE-002 — Shop Manager
- DE-QA-COMPAT-001 — Storefront
- DE-QA-COMPAT-002 — WoodMart
- DE-QA-FAIL-001 — Unsupported destination
- DE-QA-FAIL-002 — Missing/malformed rate
- DE-QA-REG-001 — Full end-to-end sale
