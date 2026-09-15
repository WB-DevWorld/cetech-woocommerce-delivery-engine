# Ownership

## WS1 — Customer Experience & Frontend Compatibility — Ben
Primary domain:
- PDP delivery/pickup presentation;
- Classic cart/checkout customer presentation;
- Cart/Checkout Blocks client presentation;
- mini-cart/browser interaction;
- per-item destination UX;
- responsive/accessibility/customer wording;
- WoodMart customer-surface compatibility evidence.

WS1 does not independently change schema, migrations, core pricing/rate authority, shipment persistence, capability/security architecture or release packaging.

## WS2 — WooCommerce Runtime, Fulfilment & Integrations — Emmanuel
Primary domain:
- WooCommerce runtime hooks;
- shipping packages/rates integration;
- Store API / Blocks server-side integration;
- cart/session runtime integration;
- HPOS order snapshots;
- shipment creation/idempotency/lifecycle;
- customer post-order Woo surfaces where server/runtime owned;
- WCFM/WPML/WCML/VitePOS adapters when explicitly assigned;
- Woo runtime/integration tests.

WS2 does not independently redesign core resolver/inheritance semantics, schema, security model or customer UX.

## WS3 — Core, Security, Data, Architecture & Release — wbdevworld
Primary domain:
- architecture/domain contracts;
- Effective Configuration Resolver and canonical semantics;
- persistence/repositories/migrations/schema;
- capabilities/security/privacy invariants;
- diagnostics and core infrastructure;
- CI, packaging, release engineering;
- governance/control plane;
- neutral batch integration.

WS3 integration authority does not grant silent takeover of WS1/WS2 work.

## Central leases
One active editor at a time for:
- plugin bootstrap/version identity;
- feature flags;
- Effective Configuration Resolver/shared domain contracts;
- migrations/schema version;
- dependency manifests/lockfiles;
- CI/CODEOWNERS/AGENTS/authority files;
- package/release scripts;
- shared cart/session identity;
- shared order snapshot and shipment grouping structures;
- translation/currency contracts crossing multiple surfaces.

The current lease holder must be recorded in `CURRENT-WORK.md`.
