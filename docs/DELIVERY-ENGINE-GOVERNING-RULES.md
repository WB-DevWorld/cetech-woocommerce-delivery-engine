# CETECH Delivery Engine — Governing Rules

**Document status:** Canonical, mandatory, maintained rulebook  
**Applies to:** All human developers, Cursor agents, AI coding agents, reviewers, and maintainers  
**Plugin:** CETECH WooCommerce Delivery Engine  
**Current protected runtime baseline:** tagged `1.0.0-rc.5` (schema `4`)  
**Previous protected published tag:** `1.0.0-rc.4` (schema `3` at tag time; **do not retag**)  
**Current master schema target:** `4`  
**Text domain:** `cetech-woocommerce-delivery-engine`

This file is the **canonical maintained rulebook**. It consolidates hard invariants from `docs/PROJECT-GOVERNANCE.md`, `docs/PROJECT-RULES.md`, owner-accepted RC.4 behaviour, and the Stage 14A architecture decisions.

It does **not** replace:

- `docs/PROJECT-GOVERNANCE.md` — process, source-of-truth hierarchy, Definition of Done
- `docs/AI-HANDOFF.md` current-status block — what is implemented today
- latest Design and Expectations specification — intended product/end-state
- repository code + latest completed stage docs — current implementation truth

Where this rulebook and an older rules file conflict on a **hard invariant**, this file plus current implementation docs win after explicit reconciliation. Do not silently ignore either document.

---

## 1. Authority and release baseline

1. **RC.4 is a protected completed baseline.** Do not treat it as disposable scaffolding.
2. **Never amend or move a released tag** (`v1.0.0-rc.2`, `v1.0.0-rc.3`, `v1.0.0-rc.4`, `v1.0.0-rc.5`, or any later release tag).
3. **Never silently replace a released package.** QA/release ZIPs must come from identified committed source.
4. **New work is additive** unless an explicitly proven defect requires modification of existing behaviour.
5. **Do not rewrite stable RC.4 architecture casually** (selector, cart capture, checkout validation, genuine WooCommerce shipping, grouping, snapshots, compact customer presentation, Administrator recovery).
6. **Do not discard owner-tested behaviour** without explicit owner/authorisation.
7. **Current implementation truth** = repository code + latest completed stage documentation. Visionary handoff sections are not proof that a feature exists.
8. **Intended product/end-state** = latest `Delivery Shipping Plugin Up-To-Date Design and Expectations.md`. Do not implement a future vision merely because it appears there.
9. Tagged **`1.0.0-rc.5`** is the protected published Stage 14 baseline (schema **`4`**). Tagged **`1.0.0-rc.4`** remains a protected historical baseline (schema **3** at tag time). Stage 14 flags default **OFF**. Do not retag RC.4 or earlier. Do not start Stage 15 without explicit owner authorisation.

Testable: a commit that retags RC.4, changes `CETECH_DE_VERSION` without authorisation, or rewrites checkout grouping “to prepare for shipments” violates this section.

---

## 2. Scope control

**Stage 14 currently means:** Shipment & Tracking Operations V1 only.

Stage 14 does **not** authorise:

- Checkout Blocks
- carrier APIs
- live carrier quotes
- automatic tracking synchronisation
- driver accounts/apps
- GPS
- OTP
- QR verification
- proof-of-delivery photos
- signatures
- recipient-ID verification
- buyer receipt-confirmation workflows
- bulk import
- unrelated redesign
- Stage 15 or later roadmap work

Do not start another major feature because the code would be convenient to add now.

If a request expands beyond the named stage, **STOP** and report the conflict.

---

## 3. Native WooCommerce / data ownership

### Native-first order

For every capability:

```text
WooCommerce core
→ supported WooCommerce APIs/hooks
→ Delivery Engine extension only where WooCommerce does not correctly own the business concept
```

Do not recreate products, variations, stock, weight/dimensions, addresses, cart, checkout, payments, taxes, refunds, orders, shipping lines, customer accounts, or other WooCommerce-owned commerce data without a genuine domain reason.

**WooCommerce is the only hard plugin dependency.** Absence of WPML, WCML, WoodMart, WCFM, VitePOS, Redis, WP Rocket, Blocks, tracking plugins, or carrier APIs must not fatal the plugin.

### One authoritative owner per piece of data

Never maintain two competing authoritative values.

**WooCommerce owns:** order, payment, refund, customer address, charged shipping line, product, variation, SKU, dimensions, stock, customer accounts, standard commerce emails, HPOS order persistence.

**Delivery Engine owns:** Fulfilment Availability, Delivery Option, delivery eligibility, configured ETA logic, private supplier/origin relationships, operational shipment status, shipment event history, Delivery Engine tracking data where Stage 14 owns it, delivery-domain configuration and inheritance.

Charged money stays on the WooCommerce shipping line / historical group snapshot. Delivery Engine may **copy** that amount onto a shipment record. It may not become a second editable price.

---

## 4. WooCommerce Fulfillments boundary

Native WooCommerce Fulfillments remains behind an abstraction. It is optional, currently beta, and **not** a V1 hard dependency.

Approved Stage 14 architecture:

```text
ShipmentService
    → ShipmentRepositoryInterface
        → WpdbShipmentRepository                 (Stage 14 V1 canonical)
        → future WooCommerceFulfillmentsAdapter  (only if deliberately implemented)
```

Rules:

- Do **not** dual-write in V1.
- Do **not** make WooCommerce Fulfillments a hidden hard dependency.
- Do **not** require `woocommerce_feature_fulfillments_enabled`.
- Do not duplicate native information later if WooCommerce can safely become its owner through an **explicit** migration/integration stage.
- Do not map CETECH’s seven operational states 1:1 onto native `unfulfilled`/`fulfilled` as if they were equivalent.

---

## 5. Global → Product → Variation

Foundational hierarchy:

```text
GLOBAL
→ PRODUCT
→ VARIATION
```

Variation overrides Product. Product overrides Global. Everything not explicitly overridden continues inheriting.

Do not flatten inherited values into every record. Do not duplicate site-wide defaults onto every product or variation.

### Field-by-field inheritance

Changing one property must not disconnect a Product or Variation from all other inherited values.

Overridable scalar fields must distinguish:

- `INHERIT`
- `OVERRIDE`
- `DISABLE` / `NONE`

Collections where applicable must distinguish:

- `INHERIT`
- `ADD`
- `REMOVE`
- `REPLACE`

A blank/empty/null database value must never be treated as equivalent to an inherited value or to an explicit disable.

---

## 6. Effective configuration / hard constraints

### One resolver

There must not be separate inheritance, eligibility, or rate-resolution engines implemented independently across product, variation, frontend, cart, checkout, order, shipment, API, or admin preview.

Use the authoritative `EffectiveConfigurationResolver` / runtime contracts.

**Stage 14 must consume historical resolved checkout snapshots.** It must not invent a second resolver or a second grouping engine to reinterpret paid orders.

### Hard constraints override preferences

Hard safety/business constraints cannot be weakened by more-specific configuration.

Examples:

- A Logistics Profile forbidding Air overrides a Product attempting to enable Air.
- A separate-shipment restriction overrides an ordinary “may consolidate” preference.

Configuration flexibility does not outrank domain integrity.

### Fulfilment domain rules

| Availability | Allowed | Forbidden |
|--------------|---------|-----------|
| International Fulfilment | Delivery Only; Air and/or Sea | Store Pickup; ordinary local delivery presented as if locally stocked |
| In Store | Local Delivery and/or Store Pickup | Air; Sea |
| In Warehouse | Delivery Only; local delivery | Store Pickup; Air; Sea |

Never create these combinations through UI, inheritance, import, API, shipment logic, or fallback:

- International + Store Pickup
- In Store + Air
- In Store + Sea
- In Warehouse + Air
- In Warehouse + Sea

---

## 7. Server authority / pricing integrity

Frontend/browser state is **never** authoritative for price, Delivery Option eligibility, route, supplier, origin, grouping, shipment state, private information, or security decisions.

Client submissions may carry identifiers and requested actions. The server validates and decides.

### Delivery price integrity

Missing, malformed, or nonnumeric **required** rates **FAIL CLOSED**. They must never silently become free delivery.

An **explicitly configured valid zero** is different and is valid.

Never silently replace an unavailable Delivery Option (Air→Sea, Delivery→Pickup, named carrier→another carrier, selected service→another service).

Shipping remains a **genuine WooCommerce shipping charge**. Never reintroduce disguised product delivery surcharges, cart fees-as-shipping, or $0 placeholder methods plus a hidden extra charge.

---

## 8. Historical snapshots / shipment creation

### Snapshot immutability

Paid/completed historical delivery truth must never change because current configuration changed later.

Never recompute historical Delivery Option, customer-paid amount, grouping, ETA, public label, or historical destination context from today’s Product/Variation settings when an order snapshot already exists.

Stage 14 shipments **must consume** the saved RC.4 order/group snapshots (`delivery_group_id`, package `groups[]`, line snapshots, shipping-line group meta as corroboration).

### Stage 14 shipment creation

- Create operational shipments from historical Delivery Engine groups.
- Do **not** create a second grouping engine.
- Approved idempotency: `order_id + delivery_group_id`.
- Use stable codes/identifiers. Never use translated text for idempotency.
- Retries must not create duplicate shipments.
- Checkout must never depend on successful operational shipment creation after payment.
- Primary trigger for **prepaid / online payment** is paid-order: `woocommerce_payment_complete` (the event is confirmation), plus `processing`/`completed` fallback only when persisted paid-date evidence exists. Do not treat `WC_Order::is_paid()` status as payment confirmation. Not order-created.
- **Cash on Delivery** does not auto-create a shipment while `date_paid` is empty. A legitimate COD delivery order is an operational **action required** task: staff create the shipment from the historical order snapshot. Later `woocommerce_payment_complete` must remain idempotent. Do not fake `date_paid` to create a shipment.

If post-payment shipment creation fails:

- preserve the order
- preserve the payment
- log diagnostic detail (no secrets)
- surface Needs Attention
- permit safe retry

### Store Pickup

Store Pickup does **not** create a delivery shipment in Stage 14 V1.

- Pickup-only order: **zero** delivery shipments is valid (not a failure).
- Mixed order: skip the pickup group when creating delivery shipments.
- Do not invent carrier tracking for Store Pickup.
- No QR/OTP pickup proof in Stage 14.

---

## 9. Shipment & tracking V1 rules

### Status model

Stable machine states (never stored as translated labels):

```text
awaiting_fulfilment
processing
dispatched
in_transit
delayed
delivered
cancelled
```

Human labels are presentation only. Initial status: `awaiting_fulfilment`.

`processing` is a **shipment** code. Never compare it to WooCommerce order status `processing` as if they were the same thing.

Do **not** automatically complete the whole WooCommerce order because one shipment becomes `delivered`.

Stage 14F normal transition matrix (corrections are a separate authorised path):

```text
awaiting_fulfilment → processing | cancelled
processing → dispatched | delayed | cancelled
dispatched → in_transit | delayed
in_transit → delivered | delayed
delayed → processing | dispatched | in_transit | delivered | cancelled
delivered → (none)
cancelled → (none)
```

`delivered` and `cancelled` are terminal for ordinary actions. Authorised staff may apply a **corrective** status change with a mandatory internal reason. Correction reasons are not customer-visible.

Marking a shipment `dispatched` does **not** invent `dispatch_at`. A shipment may be dispatched with no dispatch date.

WooCommerce remains the money authority. Shipment status never refunds, requotes, or edits paid shipping snapshots.

Conservative order sync:

- WooCommerce order `cancelled` + shipment `awaiting_fulfilment` or `processing` → automatic shipment `cancelled`
- WooCommerce order `cancelled` + shipment already `dispatched` / `in_transit` / `delayed` / `delivered` → keep operational status; Needs Attention
- Full refund of every quantity on one not-yet-dispatched shipment → automatic shipment `cancelled`
- Refund exists but physical quantity cannot be proven (amount-only / no refund line items, partial quantity, mixed/ambiguous) → keep operational status; Needs Attention `refund_requires_review`
- Partial refund, or any refund after physical progress → keep operational status; Needs Attention
- Never infer physical quantity from refund amount or from WooCommerce status `refunded` alone
- Sibling shipments are not rewritten


### Tracking V1

Tracking remains **manual**. Allowed fields: public carrier display name, tracking number, tracking URL, dispatch date, public shipment note.

Forbidden in V1: carrier APIs, automatic polling, automatic tracking synchronisation, label purchasing, webhook tracking sync.

Do **not** show a Track Shipment control unless the tracking URL is valid and usable (`http`/`https` only).

### Original ETA vs current ETA

The original checkout ETA (`estimate_text` snapshot) is immutable historical truth.

Stage 14 may maintain a later operational/current ETA. Changing current ETA must preserve the original. Customer-visible ETA changes should have a reason/audit history where required.

### Customer-paid shipping

Shipment screens must **never** edit the amount the customer already paid.

WooCommerce shipping line / RC.4 historical group snapshot remains the monetary authority.

Internal shipment cost, if added later, is private and independent.

---

## 10. Privacy / public vs private data

Customers may see only customer-safe information, such as:

- public Delivery Option
- shipment items (product name/qty)
- public shipment status label
- estimated delivery
- public carrier (when shown)
- tracking (when valid)
- public note

Customers must **never** see:

- supplier
- private origin
- Logistics Profile
- rate-card internals
- internal cost / margin
- consolidation/group key
- internal priority
- private note
- technical IDs not explicitly intended for customers

Public and internal notes must remain separate.

Do not leak private logistics through product pages, cart, checkout, mini-cart, emails, Thank You, My Account, public tracking pages, public REST/Store API, structured data, JSON-LD, SEO metadata, feeds, or customer-visible order notes.

---

## 11. Roles / permissions / Administrator recovery

Use granular WordPress capabilities. Do not rely solely on role-name checks.

Shipment viewing, status updates, tracking updates, private-source visibility, and private-cost visibility must be capability-controlled.

Users should normally not see controls they cannot use. Hidden UI is not authorisation; enforce server-side.

Existing capabilities to reuse (do not invent duplicates without cause):

- `manage_shipments`
- `update_shipment_status`
- `view_private_origins`
- `view_private_delivery_costs`

### Administrator protection

Administrator full Delivery Engine authority is **protected**. Administrator must not be treated as an ordinary configurable subordinate role.

Independent recovery remains based on native **`manage_options`**.

Do not make Administrator recovery depend on a Delivery Engine capability that may itself require repair (`view_delivery_diagnostics`, `manage_shipments`, etc.).

Do not break `AdministratorAccessRecovery`.

---

## 12. WordPress admin UX

Delivery Engine is a WordPress/WooCommerce plugin, not a standalone app.

Admin screens must feel native: familiar navigation, clear headings, sensible tables, filters, search, progressive disclosure, permission-aware actions, actionable empty/error states.

Do not build an isolated SaaS-style application inside wp-admin.

### Stage 14 admin menu

Stage 14 may add **Shipments** after Product Exceptions and before Needs Attention.

Do **not** resurrect normal **Legacy Delivery Rules** or **Technical Diagnostics**.

Normal RC.4 staff navigation stays:

Overview → Site-wide Defaults → Delivery Options → Delivery Areas → Delivery Charges → Pickup Locations → Product Exceptions → **Shipments** (when implemented) → Needs Attention → Settings

### Daily staff UX

Common shipment workflow must stay short:

```text
Open shipment
→ verify items/service
→ update status
→ add tracking/note if necessary
→ save
```

Avoid unnecessary page hopping.

### Needs Attention

Surface genuine operational shipment problems and expected operational tasks:

- Cash on Delivery order awaiting staff shipment creation (action required, not an error)
- paid order but shipment creation failed
- broken shipment/order linkage
- invalid tracking URL
- missing required historical shipment data
- delayed / issue shipments
- WooCommerce order cancelled after the shipment has already progressed
- refund that requires physical fulfilment review
- failed automatic order-state / refund status sync

Do **not** flag harmless normal states:

- newly created shipment without tracking
- pickup-only order with zero delivery shipments
- ordinary `awaiting_fulfilment` or `processing` shipments

---

## 13. Theme independence / WoodMart

Delivery Engine is architected against **WooCommerce**.

WoodMart is a first-class integration/testing target, **not** an architectural dependency.

Core business logic must not depend on WoodMart classes, XTemos private APIs, WoodMart DOM structure, or WoodMart template files.

Theme-specific compatibility belongs in adapters.

**Never modify WoodMart parent files** or another theme’s parent files to implement Delivery Engine functionality.

Prefer: WooCommerce API/hook → generic Delivery Engine behaviour → optional compatibility adapter.

---

## 14. HPOS / persistence / database

HPOS support is mandatory.

Use WooCommerce CRUD for order business logic (`wc_get_order()`, `$order->get_items()`, `$order->update_meta_data()`, `$order->save()`, shipping-item APIs).

Do not hardcode business logic against `wp_posts`, `wp_postmeta`, or WooCommerce HPOS physical order-table names.

Custom Delivery Engine tables may use `$wpdb` with the **dynamic** WordPress table prefix and prepared statements.

### Schema

New migrations must be versioned, idempotent, retry-safe, non-destructive by default, indexed for real query patterns, and testable.

Stage 14A approved a **proposed** schema 4 for shipment persistence. Schema must **not** change until the approved implementation stage actually applies the migration.

Shipment tables must support efficient lookups and pagination. Do not FK custom tables to WooCommerce tables.

Historical table-name convention remains: `{prefix}delivery_engine_shipments`, `_shipment_items`, `_shipment_events`.

---

## 15. Performance / caching

Performance is a release gate.

Avoid N+1 queries, unbounded list queries, full-table scans for ordinary dashboard counters, site-wide shipment bootstrapping, unnecessary REST/AJAX chatter, unnecessary background jobs, and duplicated expensive resolution.

Use batch loading and appropriate indexes.

### Contextual asset loading

Do not enqueue shipment CSS/JS across the whole website.

Load only on relevant shipment admin pages, order admin surfaces, and relevant My Account/order pages.

Do not add Stage 14 assets to ordinary product/cart/checkout requests unless specifically required.

### Caching

Never publicly cache customer-specific cart, checkout, My Account, order details, shipment data, or tracking/status information that requires authentication.

Reusable configuration may be cached only with correct invalidation/versioning. Never cache a customer-specific quote without a key that includes cart, product, destination, and currency context.

### Background jobs

Do not use Action Scheduler for ordinary checkout, single-order shipment creation where synchronous post-payment handling is appropriate, shipment viewing, or ordinary status changes.

Use asynchronous work only where genuinely beneficial and non-critical.

---

## 16. Security

Every privileged action requires the appropriate capability check, nonce/authentication, sanitisation, validation, escaping, prepared SQL, and order/customer ownership check where applicable.

Tracking URLs must be validated.

Never trust browser-supplied private identifiers or monetary values.

### Logging

Logs must be useful for debugging without leaking secrets.

Do not log passwords, API secrets, payment tokens, unnecessary PII, or private supplier information into public logs.

Log relevant IDs/context for troubleshooting (order id, shipment id, offer id, correlation id).

### No Code Snippets / helper plugins

Do not use Code Snippets for Delivery Engine architecture.

Do not create disposable/helper plugins as hidden runtime dependencies.

Core Stage 14 must live in this repository/package.

---

## 17. Accessibility / mobile / SEO

### Accessibility

Accessibility is a release requirement.

New UI must support keyboard operation, visible focus, semantic HTML, correctly associated labels, accessible dynamic messages, sufficient contrast, touch-friendly controls, and no colour-only meaning.

Accessibility strings are translatable.

### Mobile

Customer shipment/order presentation must work on mobile: no horizontal overflow, no hover-only critical information, touch-friendly tracking/actions.

### SEO

Shipment/order information belongs primarily inside authenticated customer surfaces.

Do not create unnecessary indexable shipment URLs.

Do not expose private logistics through structured data, page source, feeds, sitemaps, Open Graph, or public APIs.

Do not interfere unnecessarily with WooCommerce/SEO-plugin structured-data ownership.

---

## 18. Feature flags / rollback

Stage 14 must stay feature-gated until its required gates pass.

Reuse existing reserved flags. Current Stage 14A proposal:

| Flag | V1 policy |
|------|-----------|
| `enable_shipment_records` | Master switch for records + staff UI. Default **OFF** |
| `enable_tracking_links` | Customer Track control. Default **OFF** |
| `enable_customer_timeline` | Remains reserved/off for V1 (compact order-view cards, not a separate timeline) |

Defaults remain **OFF**. Stage 14H exposes `enable_shipment_records` and `enable_tracking_links` through protected Delivery Engine Settings (`manage_delivery_settings`, nonce-protected). `enable_customer_timeline` remains reserved and is not administratively activatable in V1.

Activation/update must not silently take over storefront behaviour. An RC.4 → schema-4 upgrade must leave Stage 14 flags **OFF** until an Administrator turns them on.

### Rollback

Prefer feature-flag rollback over destructive data rollback.

Disabling Stage 14 functionality must **not** delete historical shipment data.

Deactivation never destroys shipment history.

Uninstall/data destruction requires explicit deliberate policy.

---

## 19. Git / release / package discipline

### Repository audit before every stage

Before edits, inspect current branch, HEAD, remote relationship, working tree, tags, plugin version, and schema target.

If unexplained changes exist: **STOP** and investigate. Never silently discard work.

### Git / release history

Do not rebase published history casually, amend released commits, move release tags, force-push without explicit extraordinary authorisation, or overwrite historical release packages.

Keep commits narrow and comprehensible. Do not mix unrelated architecture changes into one enormous commit.

### Package discipline

QA/release packages must come from known source state.

Final release packages must be built from **committed clean** source.

Verify the **extracted ZIP**, not merely the source tree.

Verify version identity, schema identity, root structure, autoload, Linux filename/class casing, production dependencies, expected runtime files, and absence of dev-only artifacts/secrets.

---

## 20. Testing / QA

### Test honesty

Never claim a test that did not run.

“Not available” is not **PASS**.

If Playwright is unavailable, state it.

If WPML is not installed in the test environment, do not claim a WPML-present pass.

### Regression protection

New Stage 14 work must protect proven RC.4 behaviour including:

- simple selector
- variable selector
- cart persistence
- checkout validation
- genuine WooCommerce shipping
- configured charge correctness
- public Delivery Option shipping label
- HPOS snapshot persistence
- snapshot immutability
- explicit zero behaviour
- fail-closed invalid/missing rates
- no silent Delivery Option replacement
- customer privacy
- Administrator access/recovery
- compact customer delivery presentation

### Physical QA

Automated tests do not replace owner/staff physical testing for customer-facing or operational workflows.

Owner physical QA remains authoritative for actual UX acceptance.

Do not run enormous unrelated QA cycles after every narrow change. Test proportionately to risk.

---

## 21. Documentation / training

Documentation is part of implementation.

Each completed stage updates the authoritative handoff/status documentation.

Do not allow AI agents to reconstruct current implementation truth from old chats when code/latest stage docs can state it directly.

Update training documentation after the relevant operational UI/workflow **stabilises**.

Do not repeatedly rewrite staff training during rapidly changing intermediate implementation.

Final training must match the actual released interface.

---

## 22. Language / translation / terminology

### General

The plugin must be localisation-ready.

All human-readable admin/staff/customer strings use WordPress internationalisation functions and the plugin text domain `cetech-woocommerce-delivery-engine`.

Do not hardcode visible English text inside business logic. English is the source/default language, not the architecture.

### WPML / WCML are optional

Without WPML: use site language; no error.

Without WCML: use WooCommerce base currency; no error.

Neither may become a hard dependency. Core business logic must not call WPML/WCML functions directly. Detect optional integration safely. If absent: Null adapter / normal WordPress localisation.

### Stable internal codes are never translated

Never translate as authority: shipment status code, fulfilment code, route code, Delivery Option ID, supplier ID, origin ID, Logistics Profile ID, Rate Card ID, order ID, shipment identity, tracking number, idempotency key.

Translate presentation labels only.

Business logic compares codes/IDs, **never** translated text.

### Operational data is shared, not translated

Operational truth remains common across languages: Fulfilment Availability, Logistics Profile ID, supplier/origin IDs, Delivery Option ID, Rate Card linkage, eligibility, consolidation/group identity, shipment identity, machine status, paid amount, currency snapshot, tracking number/URL, dispatch date, internal/audit identifiers.

Language switching must never alter operational truth.

### Customer-facing text is translatable

Translate Delivery Option labels/descriptions, fulfilment labels, Store Pickup instructions, shipment status labels, tracking wording, ETA wording, My Account text, customer email strings, and public shipment notes **where multilingual authoring is deliberately supported**.

### Public notes

Do not automatically invent translations for staff-authored public shipment notes.

If multilingual public-note authoring exists: render the correct authored language.

If only one authored note exists: do not pretend a translated version exists.

### Canonical terminology

Use these terms consistently:

| Term | Meaning |
|------|---------|
| Fulfilment Availability | International Fulfilment / In Store / In Warehouse |
| Fulfilment Choice | Delivery or Store Pickup |
| Delivery Route / Mode | Air, Sea, Local Delivery, Store Pickup |
| Delivery Option | Complete customer-selectable service |
| Service Level | Economy, Standard, Express, etc. |
| Processing / Dispatch | Time before dispatch |
| Main Transit | Primary transport period |
| Final-mile Delivery | Local movement to the address |
| Estimated delivery to your address | Canonical customer ETA |
| Logistics Profile | Internal transport/handling class (not “Shipping Class”) |
| Shipment | Distinct fulfilment unit within one WooCommerce order |
| Supplier / Origin | Private operational source |

Do not casually rename the same concept on different screens.

### Customer language

Customer language must remain plain and nontechnical.

Never expose Rate Card, Logistics Profile, consolidation key, resolver, internal source rule, configuration inheritance, or database identifier unless the term is genuinely customer-relevant.

Prefer **Estimated delivery to your address**. Do not use vague “Arrival” as the primary final ETA term.

Configured **public Delivery Option labels** remain authoritative for customer presentation. Do not expose internal shipping-method labels where a public Delivery Option label exists.

Where a named carrier is guaranteed, show the public carrier name. Where it is not: **Carrier assigned by store**. Do not promise a named carrier the business cannot guarantee.

### Identifiers and locale

Do not translate tracking numbers, shipment numbers/identifiers, order numbers, SKUs, or supplier/origin codes. Display formatting may be localised; stored identifiers remain unchanged.

Dates, numbers, and currencies use WordPress/WooCommerce locale conventions. Stored values remain canonical.

Use proper WordPress pluralisation (`_n()`). Do not hardcode English constructions such as `count + " shipments"`.

Do not build translated sentences from fragments. Use complete translatable strings and placeholders so languages can change word order.

ARIA labels, screen-reader notices, loading text, validation errors, empty states, and button labels are also localisation-ready.

### Email language

Where the multilingual stack supports an order/customer language context, shipment/tracking email content should respect that language. Do not randomly fall back to another language inside an otherwise translated WooCommerce email.

### WPML configuration

Where Stage 14 introduces WP metadata that requires copy/translate behaviour, configure it through the supported WPML mechanism (`wpml-config.xml`).

Do not require administrators to manually duplicate operational configuration across translated products.

Custom-table translation behaviour must be deliberately designed rather than assumed. `wpml-config.xml` does not automatically translate `{prefix}delivery_engine_*` rows.

### Language testing

Where environments permit, Stage 14 testing should cover WPML absent, WPML present, language switch, public shipment status translation, My Account shipment text, tracking text, customer email output, unchanged underlying shipment identity/state, and no private-data leakage.

Never claim WPML-present testing if no WPML environment was actually tested.

Historical paid shipping must not be reconverted because exchange rates changed. Currency never determines route eligibility.

---

## 23. Stage 14 current scope and architecture decisions

Stage 14A currently recommends (change only through an explicit reviewed architecture decision):

- schema 4 for shipment persistence (applied in source; live RC.4/FLAIROC remain schema 3 until authorised deploy)
- shipment tables pin `ENGINE=InnoDB` so aggregate writes cannot silently lose transactions on a MyISAM/host-default engine
- Delivery Engine custom tables as V1 canonical shipment repository
- repository abstraction
- no WooCommerce Fulfillments dual-write in V1
- historical RC.4 delivery groups as shipment source of truth
- no reconstruction from current product settings
- Store Pickup creates no delivery shipment in V1
- paid-order-triggered, idempotent creation
- manual tracking
- seven Delivery Engine operational states
- compact My Account order-view extension
- no separate Deliveries endpoint for V1
- Needs Attention for genuine operational failures
- existing shipment capabilities reused/activated appropriately
- Administrator recovery remains independent through `manage_options`

Approved implementation sequence after this rulebook:

```text
14B persistence foundation
→ 14C planner + idempotent creation
→ 14D staff workspace
→ 14E tracking + customer cards
→ 14F status/refunds/audit/Access
→ 14G automated gates
→ 14H package + physical QA + training
```

Do not implement 14E until explicitly instructed. Stage 14D staff Shipments list/detail is complete and remains feature-gated OFF.

---

## 24. Mandatory pre-task compliance procedure

Before every implementation stage, Cursor/AI **MUST**:

1. Read `docs/PROJECT-GOVERNANCE.md`.
2. Read `docs/DELIVERY-ENGINE-GOVERNING-RULES.md` (this file).
3. Read the current status block in `docs/AI-HANDOFF.md`.
4. Read the immediately preceding stage report.
5. Audit branch / HEAD / remote / working tree / version / schema.
6. Identify the exact requested scope.
7. State whether the task conflicts with any governing rule.
8. If conflict exists, **STOP** and report it instead of silently violating the rule.
9. Make only the smallest necessary changes.
10. Run the required proportional regression tests.
11. Verify no private/runtime/release invariant was broken.
12. Update stage documentation and handoff accurately.
13. Never claim unperformed tests.
14. Stop at the requested stage boundary.

Substantial tasks still follow:

```text
READ → AUDIT → PLAN → IMPLEMENT → TEST → DOCUMENT → REVIEW → STOP
```

---

## 25. Final governing doctrine

Preserve proven releases.

Use WooCommerce first.

Own only Delivery Engine-specific domain truth.

Configure globally once.

Inherit by default.

Override only what genuinely differs.

Hard constraints win.

Keep the server authoritative.

Fail closed rather than silently undercharge.

Snapshot transactional truth permanently.

Create operational shipments from historical snapshots.

Keep shipment creation idempotent.

Keep V1 tracking manual.

Protect customer privacy.

Protect Administrator recovery.

Keep the plugin WordPress-native.

Keep it fast.

Keep it accessible.

Keep it theme-independent.

Keep it localisation-ready.

Test the actual package.

Document every stage accurately.

Do not silently expand scope.

---

## 26. Related documents (do not fork)

| Document | Role after this rulebook |
|----------|--------------------------|
| `docs/DELIVERY-ENGINE-GOVERNING-RULES.md` | **Canonical maintained rulebook** (this file) |
| `docs/PROJECT-GOVERNANCE.md` | Process, source-of-truth hierarchy, Definition of Done |
| `docs/PROJECT-RULES.md` | Preserved detailed engineering rules; defer to this file on conflict after explicit reconciliation |
| `docs/AI-HANDOFF.md` | Product vision + **current implementation status** |
| `docs/STAGE-14A-SHIPMENT-ARCHITECTURE-AUDIT.md` | Current Stage 14 architecture decision record |
| `docs/ARCHITECTURE-PLAN.md` | Modular-monolith layout; visionary sections are not current implementation |
| Latest Design and Expectations | Intended product/end-state |
| `.cursor/rules/000-delivery-engine-governance.mdc` | Always-apply Cursor enforcement wrapper |

`AGENTS.md` is **not** present and is **not** required. If broader non-Cursor agent compatibility is later desired, add a short pointer to this file — not a second rulebook.
