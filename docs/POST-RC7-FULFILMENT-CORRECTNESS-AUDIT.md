# POST-RC.7 Fulfilment Correctness Audit

**Document status:** Read-only root-cause audit + smallest safe repair plan. **Accepted.** Implementation record: `docs/POST-RC7-FULFILMENT-CORRECTNESS-IMPLEMENTATION.md`.  
**Date:** 2026-08-31  
**Audited identity:** tagged `1.0.0-rc.7` / schema `5` / `v1.0.0-rc.7`  
**Package source:** `ad3feebfd1aa92d078caaa557c0c0d11c090a0c6`  
**Development branch:** `feat/post-rc7-fulfilment-correctness` (new worktree; RC.7 tag not moved)  
**FLAIROC:** not modified  
**Training site:** not modified  
**Stage 15:** not started  
**Schema 6:** not proposed  
**Package:** not built

This workstream is authorised to repair **In Store Delivery + Store Pickup** and to prove **International** failures before touching resolver code. In Warehouse must not regress.

```text
READ → AUDIT → PLAN → STOP (await implementation authorisation)
```

---

## 0. Repository identity

| Item | Value |
|------|--------|
| Protected tag | `v1.0.0-rc.7` (annotated; peeled `ad3feeb`) |
| Plugin version audited | `1.0.0-rc.7` |
| Schema target audited | `5` |
| Worktree | `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-post-rc7-fulfilment-correctness` |
| Branch HEAD | `ad3feeb` — identical to tagged RC.7 runtime |
| RC.7 tag mutated? | **No** |
| Prior related audit | `docs/POST-RC6-TESTER-OBSERVATIONS-AUDIT.md` Issue 3 (In Store XOR) — still true on RC.7 |
| RC.7 freeze explicitly excluded | In Store redesign, International ECR redesign (`docs/RC7-FINALIZATION.md` §8) |

RC.7 did **not** repair fulfilment correctness. Bulk Tools + R1 admin/setup repairs are in this tree; the In Store XOR model is unchanged from RC.6.

---

## Method

- Governance, RC.7 finalization, governing fulfilment table, Stage 3 ECR, Stage 13 site-wide defaults, Stage 13F presentation, Stage 14C shipment creation, and the post-RC.6 tester audit were read first.
- Runtime files in this worktree were inspected. No production/plugin file was edited.
- Live training.cetechbpa.com / FLAIROC browsers were **not** used.
- Training configuration evidence cited by the owner (`airshipping` labelled “Air Shipping” with `route = local_delivery`; `airshipping-2` as the real air route) is treated as **data**, not as a reason to weaken hard constraints.

---

## Domain terms (as RC.7 actually implements them)

| Term | Storage | RC.7 meaning |
|------|---------|--------------|
| **Fulfilment Availability** | Scalar `fulfilment_availability` | Profile: `in_warehouse` / `in_store` / `international_fulfilment`. Selects which site-wide default slice a product inherits. |
| **Fulfilment Choice** | Scalar `fulfilment_choice` | **XOR:** `delivery` **or** `store_pickup`. Treated as an exclusive customer path, not as “default plus optional alternative.” |
| **Delivery Route** | Delivery Option row `route` | `local_delivery` / `store_pickup` / `air` / `sea`. |
| **Delivery Option** | Catalog row + collection `delivery_offer_ids` | Customer-selectable offer. Collection modes: INHERIT / ADD / REMOVE / REPLACE. |
| **Store Pickup** | Overloaded three ways | (1) fulfilment **choice** value, (2) offer **route**, (3) pickup **location** entity. Same string `store_pickup` is used for (1) and (2). |
| **Pickup location** | `pickup_locations` table | Address, instructions, readiness. Wizard draft only — **not** an ECR field. |

Intended product rule (governing rules + design spec §8):

| Availability | Allowed | Forbidden |
|--------------|---------|-----------|
| International | Delivery only; Air and/or Sea | Store Pickup; ordinary local delivery presented as locally stocked |
| In Store | Local Delivery **and/or** Store Pickup | Air; Sea |
| In Warehouse | Delivery only; local delivery | Store Pickup; Air; Sea |

RC.7 implements the International and In Warehouse **route** filters. It does **not** implement In Store as concurrent Delivery + Pickup.

---

## Authoritative pipeline (unchanged; keep it)

```text
SiteWideDefaultsService::classify_product
  → product DEFAULT slice: fulfilment_availability Override
EffectiveConfigurationResolver
  → determine_profile_key (variation/product override → request slice → primary)
  → select_profile_global (global/0/{profile_key})
  → GLOBAL → PRODUCT → VARIATION field inheritance
HardFulfilmentConstraintService::apply
  → invalidate prohibited pickup choice
  → strip offer IDs whose route ∉ allowed_routes
EcrToRuntimeConfigurationAdapter
  → one ResolvedProductDeliveryRule per valid availability slice
ProductDeliveryOptionsBuilder
  → customer radios {availability}:{choice}:{suffix}
CartDeliverySelectionCapture (server-validated display_key)
  → CheckoutDeliverySelectionValidator
  → RateQuoteEngine (offer_id + destination_zone_id + currency)
  → OrderDeliverySnapshot (immutable)
  → HistoricalShipmentPlanner (skips pickup groups)
```

There is **one** ECR. Destination matching is **not** part of ECR. Product-page options are **not** filtered by country. Rates are quoted later from the **already selected** offer ID.

Do not add a second resolver, a frontend eligibility engine, or destination-based reclassification.

---

# OBJECTIVE 1 — In Store Delivery / Pickup conflict

## Intended business rule

In Store = local fulfilment only.

- Delivery must be available where configured.
- Store Pickup may also be available.
- Delivery is the default / preselected customer choice.
- Customer may switch Delivery → Store Pickup.

If **Delivery** is selected: eligible local Delivery Offers, delivery charge, delivery ETA, normal delivery shipment.

If **Store Pickup** is selected: delivery options disappear; delivery charge zero/absent; route/carrier information disappears; doorstep ETA disappears; pickup location appears; pickup readiness/instructions appear; **no delivery shipment** for that pickup item.

## Actual RC.7 behaviour

`fulfilment_choice` is a two-value enum. The product-page builder treats it as a path lock:

```51:54:src/Application/Selector/ProductDeliveryOptionsBuilder.php
			if ( FulfilmentChoice::StorePickup->value === $choice_slug ) {
				$options[] = $this->store_pickup_option( ... );
				continue;
			}
```

If choice is `store_pickup`, `delivery_offer_ids` are **never expanded**. The customer sees one stub: “Store pickup available.” Standard / Express local offers disappear.

The Setup Wizard shows a third radio **“Delivery + Store Pickup” (`both`)**, then saves:

```895:898:src/Presentation/Admin/SetupWizardPage.php
		$choice = FulfilmentChoice::StorePickup->value === $mode
			? FulfilmentChoice::StorePickup->value
			: FulfilmentChoice::Delivery->value;
```

`both` is stored as **`delivery`**. Dual eligibility is UI-only.

Hard constraints for In Store allow pickup **as a choice**, but allowed **offer routes** are **`local_delivery` only**:

```132:137:src/Application/Configuration/HardFulfilmentConstraintService.php
			FulfilmentAvailability::InStore->value => [
				'pickup_allowed' => true,
				'allowed_routes' => [
					DeliveryRoute::LocalDelivery->value,
				],
			],
```

Admin `DeliveryOptionCompatibility` **adds** `store_pickup` to In Store choice lists, so staff can tick a Store Pickup Delivery Option. Runtime then **strips** those IDs.

Site-wide In Store editor is a single `<select>` of Delivery vs Store Pickup (`DeliverySettingsHomePage.php` ~L430–437). Overview copy can still say “Delivery + Store Pickup” (`SiteWideDefaultSummary.php` L89–97) even when runtime is XOR.

Pickup location IDs are stored only in wizard **draft**, not in `configuration_fields`. The customer selector has no location, readiness, or instructions to show.

PDP radios are a **flat** list. There is no Delivery vs Store Pickup grouping, no default-checked Delivery, and no JS that hides delivery offers when Pickup is selected. Delivery **charge** is not shown on PDP; it is quoted at checkout from the captured selection.

Cart/checkout **cannot** switch fulfilment on an existing line. Change requires remove + re-add. That path is fail-closed (no silent offer replacement). Pickup packages already quote **`0.0000`**. Pickup groups already skip shipment creation.

## Exact root cause

**RC.7 models Store Pickup as an exclusive fulfilment state, not as an optional alternative alongside Delivery.**

Three cooperating defects, one model:

1. **Storage XOR.** `fulfilment_choice` can be only `delivery` or `store_pickup`. Wizard `both` collapses to `delivery`. There is no persisted dual-eligibility flag other than a Store Pickup **route** offer in `delivery_offer_ids`.
2. **Runtime lock.** `ProductDeliveryOptionsBuilder` emits pickup **or** delivery offers, never both, based on that XOR scalar.
3. **Constraint mismatch.** In Store hard `allowed_routes` omit `store_pickup`, so the only durable dual signal staff can tick (a Store Pickup Delivery Option) is stripped at resolve time. Admin compatibility and runtime constraints disagree.

This is **not** an ECR inheritance bug. Classified In Store products inherit the In Store profile correctly. The ECR then applies a constraint policy that cannot represent “local offers + pickup alternative,” and the builder refuses to show both.

## Classification

| Layer | Verdict |
|-------|---------|
| ECR inheritance / profile selection | **Not defective** for In Store classification |
| Hard constraint In Store route list | **Defect relative to governing In Store rule** (strips pickup-route offers) |
| ProductDeliveryOptionsBuilder | **Defect** (XOR lock) |
| Wizard / Site-wide “Customer fulfilment” | **Admin UX defect** (`both` is false; select looks exclusive) |
| Pickup location on PDP/checkout/order | **Missing capability** (entity exists; not bound into customer contract) |
| Checkout $0 for pickup | **Already correct** |
| Pickup shipment suppression | **Already correct** |
| Schema | **No schema 6 required** |

Severity: **P1**. Blocks the canonical In Store customer model. Not P0: no $0 shipping leak; behaviour is deterministic.

## What already works (preserve)

- In Store + `fulfilment_choice = delivery` + local offer IDs → local Delivery Offers render.
- Pickup captured as `{availability}:store_pickup:pickup` → checkout quotes `0.0000`.
- `HistoricalShipmentPlanner` skips `is_pickup` groups; `ShipmentService` returns `ZeroShipmentsPickupOnly`.
- In Store Air/Sea offers are stripped. Keep that.
- International/In Warehouse still reject pickup choice. Keep that.

---

# OBJECTIVE 2 — International fulfilment

## Intended business rule

International = Delivery only.

- Store Pickup unavailable.
- Local Delivery unavailable.
- Customer sees only eligible Air and/or Sea Delivery Offers.
- One eligible offer may auto-select.
- Multiple eligible offers allow customer selection.

## Does International **logic** leak local delivery?

**No — not when the product is actually classified International and constraints are wired (production).**

Hard policy:

```125:131:src/Application/Configuration/HardFulfilmentConstraintService.php
			FulfilmentAvailability::InternationalFulfilment->value => [
				'pickup_allowed' => false,
				'allowed_routes' => [
					DeliveryRoute::Air->value,
					DeliveryRoute::Sea->value,
				],
			],
```

Proven by existing tests:

- `HardFulfilmentConstraintServiceTest::test_international_rejects_pickup_and_keeps_air_sea` — pickup choice Invalid; local ID stripped; air+sea kept.
- `SiteWideOptionalFieldValidityTest::test_international_cannot_expose_local_or_pickup` — same fail-closed pattern.
- `Stage13CR3RepairTest::test_international_compatibility_offers_only_air_and_sea` — admin list excludes Store Pickup.

ECR inheritance does **not** filter routes. Filtering is entirely in `HardFulfilmentConstraintService` after build. That is the correct architecture. Do not duplicate it in the builder “to be safe” as a second policy engine.

Destination matching **does not reclassify** an International air/sea `delivery_offer_id` into `local_delivery`. `RateQuoteEngine` quotes the **selected offer ID** + matched zone + currency. Ghana as destination can only price that same air/sea offer, or fail `no_matching_rate_card`. It cannot swap in Standard Delivery.

## Training configuration evidence (do not compensate)

Owner evidence: `airshipping` labelled “Air Shipping” but `route = local_delivery`; `airshipping-2` was the actual air route.

On a **correctly classified International** product:

| Offer | Route | Hard-constraint result |
|-------|-------|------------------------|
| `airshipping` | `local_delivery` | **Stripped** |
| `airshipping-2` | `air` | **Kept** |

The mis-routed row will **not** appear as International local delivery. The customer sees whatever `public_label` `airshipping-2` has — or “Delivery unavailable” if no air/sea IDs remain.

On an **unclassified** product (still primary, typically In Warehouse):

- Site-wide International card does **not** classify the catalog.
- `airshipping` with `route = local_delivery` **will** appear, labelled “Air Shipping,” as a **local** option.
- That is **configuration / classification**, not an International resolver rewrite.

Do **not** weaken International `allowed_routes` so a `local_delivery` row named “Air Shipping” can survive. Fix the catalog row’s route, or classify the product, or both. Production semantics stay generic: route is the constraint key, not the public label.

## How International can still **look** local

| Mechanism | Class | Repair in this workstream? |
|-----------|--------|----------------------------|
| Product not classified International; inherits primary local | **configuration / admin UX** | Copy only if cheap; do not auto-classify catalog |
| Air/Sea offer `public_label` sounds local (“Standard Delivery”) | **configuration / label** | No resolver change |
| `airshipping` route=`local_delivery` on an unclassified product | **configuration / rate-card catalog** | Do not weaken constraints |
| Leftover product slices (`in_warehouse` + International) via `resolveAll` | **legacy multi-slice** | Do not flatten inheritance |
| PDP shows `public_label` only (Stage 13F hides route) | **presentation by design** | Optional Air/Sea wording is not required if labels are honest |
| One Air/Sea offer not auto-selected | **frontend presentation gap** vs intended | **Yes — small PDP default** |
| DE quote fails for offer+zone; `filter_managed_package_rates` then **leaves native WC methods** | **checkout integration defect** | **Yes — fail-closed on managed packages** |
| Destination remaps International → `local_delivery` | **not found** | No change |

The leftover-WooCommerce-method leak is the only International **code** defect that can still expose “Standard / local Delivery” at checkout for a correctly classified International line. `SelectedOfferShippingIntegration::filter_managed_package_rates` (L78–79): if DE did not add a rate, `$rates` is returned unchanged. Flat Rate / Local pickup / leftover methods remain visible on a DE-managed package.

That is **not** an ECR defect. It is fail-open against native methods when DE quoting fails. Hard rule: missing configuration must not become free/$0 **and** must not silently offer a forbidden local method on an International package.

## Classification

**International resolver logic is not defective.** Observed training failures are primarily **configuration** (wrong offer route, unclassified products, misleading labels), with one **checkout presentation/integration** leak when DE cannot quote.

---

# IN WAREHOUSE — preserve

RC.7 already matches the intended rule:

- `pickup_allowed = false`
- `allowed_routes = [local_delivery]`
- Tests: `test_in_warehouse_rejects_pickup_and_air_sea`, `test_in_warehouse_cannot_expose_air_or_sea`

Do not redesign. Regression tests must stay green. Do not add pickup or Air/Sea to this profile.

---

# Layer-by-layer audit

## 1. How `fulfilment_availability` resolves

`SiteWideDefaultsService::classify_product` writes **only** `fulfilment_availability` Override on the product DEFAULT slice (`''`). Offers/choice/ETA inherit from `global/0/{profile_key}`.

`EffectiveConfigurationResolver::determine_profile_key` (priority): variation override → product override → request slice → product slice_key → site-wide primary.

**Working as designed.** Unclassified products inherit primary (often In Warehouse / local). That is why configuring the International **card** does not make a product International.

## 2. How `fulfilment_choice` resolves

XOR enum. Inherited like any other scalar. Hard constraints invalidate `store_pickup` on International and In Warehouse (`CONSTRAINT_CHOICE_PROHIBITED`). In Store allows it.

`EcrToRuntimeConfigurationAdapter::map_slice` requires a **valid** choice. Invalid International pickup → slice dropped (fail-closed, no customer options). Correct.

Defect is using this scalar as **exclusive availability** instead of **default preselection**.

## 3. Delivery Option route eligibility

Authoritative filter: `HardFulfilmentConstraintService::filter_offer_ids`. Unknown offer IDs are left for downstream active-offer checks. Production always injects the offer repository.

Admin lists: `DeliveryOptionCompatibility::allowed_routes` (In Store appends `store_pickup`).

**International / In Warehouse:** admin and runtime agree.  
**In Store:** they disagree. That is the dual-eligibility hole.

## 4. Store Pickup eligibility

Not a first-class ECR field. Inferred from:

- profile `pickup_allowed` (In Store true; others false)
- XOR `fulfilment_choice`
- optional `store_pickup` **route** offers (stripped on In Store)

Pickup **location** is a separate catalog. Wizard binds an id in draft only (`SetupWizardProgress.pickup_location_ids`). `ConfigurationFieldKey` has no `pickup_location_id`. Customer summary DTO has location fields but `CustomerOrderDeliverySummaryBuilder` explicitly leaves them null.

Per-item location architecture is **out of scope**. Smallest display path: read the existing active pickup-location catalog when rendering a pickup option (single-store / default location), and copy public label + instructions + readiness into cart summary / snapshot JSON. No new table. No schema 6.

## 5. PDP rendering

`ProductDeliverySelectorRenderer` shows `public_label` + estimate only. Radios are required; **none are checked** unless POST replay matches. Variable JS never sets `checked`.

No two-level Delivery vs Pickup UI. No pickup location/readiness. No delivery **price** on PDP (quoted later). Compact Stage 13F contract is otherwise correct (no supplier/origin).

## 6. Cart persistence

Capture stores server-validated intent (`display_key`, availability, choice, offer_id, fingerprint). Hash mismatch strips selection (fail-closed). Revalidator does not mutate the line; cart asks the customer to remove and re-add.

Switching Delivery ↔ Pickup after add-to-cart is **not** implemented. Smallest repair: dual options and hide/show on **PDP** (and variable AJAX). Checkout remains bound to the captured radio. Switching back to Delivery is a new add-to-cart with a delivery `display_key`, which already restores quote calculation **if** the builder emits that option.

A live checkout switcher is **not** required for the three owner scenarios if PDP switching + two add-to-cart passes are accepted. It is extra scope.

## 7. Checkout validation / rates

Pickup package → explicit `0.0000` (`SelectedOfferShippingRateCalculator` L63–89). Delivery package → `RateQuoteEngine` on selected offer + zone. No match → no DE rate (not $0).

International can still **display** native local methods when DE quoting fails (see Objective 2). Recommended fail-closed: on a DE-managed package, if no DE rate exists, return **no** native rates for that package (block checkout), rather than leftover Flat Rate / Local pickup.

## 8. Order snapshot

Line snapshot stores availability, choice, offer id/label, estimate, zone, quoted amount, group id. Immutable after create.

Gaps vs intended pickup details: no `pickup_location_id` / address / instructions / readiness in the protected JSON. Presentation can already format those rows **if supplied**. Extending the snapshot JSON is not schema 6 (order meta). Historical rows without the keys remain valid.

## 9. Shipment creation

Pickup groups skipped. Mixed carts plan delivery groups only. Tests exist and must remain:

- `HistoricalShipmentPlannerTest::test_pickup_group_is_skipped`
- `HistoricalShipmentPlannerTest::test_pickup_only_is_success_with_zero_plans`
- `ShipmentServiceCreationTest::test_pickup_only_paid_order_is_success_with_zero_shipments`
- `ManualShipmentCreationTest` skip-pickup cases

Do not create a delivery shipment for a pickup item. Do not invent a pickup shipment type in this workstream.

## 10. Current automated tests

| Area | Coverage vs intended |
|------|----------------------|
| International cannot keep local / pickup (ECR) | **Present** |
| In Warehouse local only (ECR) | **Present** |
| In Store strips Air/Sea (ECR) | **Present** |
| In Store **keeps** pickup-route offers | **Missing** (today they are stripped; current tests encode that) |
| Builder emits Delivery **and** Pickup together | **Missing** (post-RC.6 evidence names are documentation-only, not PHPUnit in this tree) |
| Delivery default-checked | **Missing** |
| Switch Pickup hides delivery rate/ETA | **Missing** |
| Switch back restores delivery quote | **Missing** |
| International Air only / Sea only / Air+Sea at **builder** | **Partial** (constraints yes; customer options matrix no) |
| International auto-select single offer | **Missing** |
| Pickup → no delivery shipment | **Present** |
| Invalid hard combinations fail closed | **Present** at ECR |
| Choice survives cart/checkout/snapshot | **Partial** (delivery fixtures; no dual switch) |
| Managed package leftover WC methods | **Missing** |

RC.5 QA.6 owner physical “pickup suppression” is shipment suppression, **not** proof that In Store dual choice already works.

---

# Failure taxonomy (summary)

| Observation | Class | Action |
|-------------|--------|--------|
| In Store + Pickup hides Standard Delivery | **runtime defect** (XOR choice + builder lock + In Store route strip) | Repair |
| Wizard “Delivery + Store Pickup” does not persist dual | **admin UX defect** on top of the same model | Persist dual via surviving pickup-route offers; relabel default choice |
| International product shows local on PDP | Usually **configuration** (unclassified, or local-route offer labelled Air) | Do not weaken constraints; optional classification copy |
| International checkout shows Standard/local | **checkout integration** if DE quote missing + leftover WC methods; else labels/config | Fail-closed managed packages |
| `airshipping` route=`local_delivery` | **catalog configuration error** | Clean test product later; no code compensation |
| One Air/Sea offer not preselected | **frontend presentation gap** | Small PDP default |
| In Warehouse still Delivery only / local | **correct** | Regression only |
| Pickup item creates a delivery shipment | **not found** | Preserve tests |
| Pickup location/readiness missing | **missing presentation binding** (no per-item architecture) | Catalog default + snapshot copy |

---

# Smallest safe implementation plan

Do **not** add a second resolver. Do **not** add schema 6. Do **not** add `store_pickup_eligible` unless B proves insufficient. Do **not** auto-classify products. Do **not** treat public labels as routes.

## Repair A — In Store dual eligibility (authoritative, still ECR)

Keep `fulfilment_choice` as the **default preselection**, not a path lock.

1. **Align In Store hard constraints with admin compatibility**  
   In Store `allowed_routes` = `[local_delivery, store_pickup]`.  
   Continue stripping `air` / `sea`.  
   International / In Warehouse unchanged.  
   Update `FulfilmentProfileRegistry` In Store `allowed_routes` to match so compatibility append is not the only source of truth.

2. **Builder emits both when both are configured**  
   For an In Store rule:
   - `local_delivery` active offers → Delivery radios (`{availability}:delivery:{offer_id}`).
   - `store_pickup` active offers → Pickup option(s) (`{availability}:store_pickup:{offer_id}` or existing `:pickup` suffix if a single synthetic pickup remains necessary).
   - If only local offers exist → Delivery only.
   - If only pickup-route offers exist → Pickup only (governing “and/or”; not in owner’s three scenarios).
   - **Never** `continue` past delivery offers merely because default choice is `store_pickup`.

   Invariant: a `store_pickup`-route offer must never be quoted as a delivery shipment. Grouping is by **route**, then choice on the option DTO. Rate calculator already zeros `is_pickup` packages.

3. **Default selection**  
   Preselect Delivery when local offers exist, unless staff default `fulfilment_choice = store_pickup` **and** pickup is actually eligible. Intended default is Delivery.

4. **Wizard `both`**  
   Keep saving `fulfilment_choice = delivery` (default). Stop stripping checked Store Pickup offer IDs (Repair A.1 does this). Require at least one local offer when Delivery is enabled; require a pickup-route offer (or existing pickup location + synthetic pickup) when Pickup is enabled. Relabel Site-wide “Customer fulfilment” to **default** customer fulfilment (Delivery recommended).

## Repair B — PDP two-level presentation (same options, no second engine)

Group builder output by `fulfilment_choice`:

- If both Delivery and Pickup options exist: show **Delivery | Store Pickup**. Delivery selected by default.
- If Delivery selected: show eligible local Delivery Offers + delivery ETA. Do not show pickup location.
- If Store Pickup selected: **hide** delivery offer radios, delivery ETA, and any delivery-charge copy; show pickup location + readiness/instructions from the active pickup-location catalog (not a new ECR field).
- Variable AJAX renderer must follow the same grouping.

Delivery **price** remains checkout-authoritative. After add-to-cart, pickup → `0.0000`; delivery → quoted amount. Owner physical test is PDP switch + cart/checkout totals, not a new checkout switcher.

Pickup location binding: use existing `pickup_locations` (active default / single location). Do **not** start per-item location architecture. Copy public location label, instructions, and readiness into cart summary and order snapshot JSON when the captured choice is pickup.

## Repair C — International presentation only (no resolver rewrite)

1. Single eligible Air **or** Sea offer → default-check that radio. Multiple → customer must choose (current required radios).
2. Do **not** show Store Pickup or `local_delivery` offers for International (already stripped). Add **builder-level** tests so a regression cannot re-introduce them via presentation.
3. **Managed-package fail-closed:** if a DE-managed package has no DE rate, do not return leftover WooCommerce methods. Checkout blocks rather than offering native local shipping on an International (or any DE) package.

Do **not** retarget `airshipping`-style bad rows in code. Training may later use a clean International test product (Air and/or Sea routes actually `air`/`sea`, product classified International, rate cards on the intended Delivery Areas).

## Repair D — tests first, then full regression

New focused PHPUnit (and JS if the selector script changes) covering the acceptance matrix below. Then full PHP + JS regression. Do not weaken existing International / In Warehouse / shipment-suppression assertions.

## Explicitly out of this workstream

- FLAIROC / training mutation from Cursor
- RC.7 retag or rebuild
- Schema 6
- Per-item location architecture
- Return/Refund policy
- Checkout Blocks
- Carrier APIs, driver/POD/GPS/OTP
- Bulk Tools expansion / Catalog “Ready to apply” wording (known backlog)
- Unrelated admin polish
- Checkout live fulfilment switcher (unless PDP+cart path fails owner QA)
- Auto-classifying the catalog as International
- Weakening hard constraints so mis-routed local offers can pose as Air

---

# Automated acceptance (must prove)

| # | Case | Must prove |
|---|------|------------|
| 1 | In Store + Delivery only | Local delivery options render; pickup absent |
| 2 | In Store + Delivery + Pickup | Both available |
| 3 | Delivery is default | Delivery preselected when both exist |
| 4 | Switch to Pickup | Delivery offers/ETA hidden; charge zero/absent |
| 5 | Pickup item | No delivery shipment |
| 6 | Switch back to Delivery | Valid delivery calculation restored |
| 7 | International | Cannot expose Store Pickup |
| 8 | International | Cannot expose Local Delivery (ECR **and** builder **and** managed-package rates) |
| 9 | International | Air only |
| 10 | International | Sea only |
| 11 | International | Air + Sea; customer selects |
| 12 | In Warehouse | Delivery only / local; no pickup; no Air/Sea |
| 13 | Invalid hard combinations | Fail closed (In Store+Air, International+Pickup, etc.) |
| 14 | Selected choice | Survives cart → checkout → order snapshot |

Mis-routed `local_delivery` labelled “Air Shipping” on International must still be **stripped**, not displayed.

---

# Owner QA (keep it short)

After implementation and package QA, physical testing is **three scenarios only**:

1. **In Store:** Delivery + Store Pickup configured. Switch between them. Confirm offers, ETA, pickup details, and pricing change correctly. Pickup must not create a delivery shipment.
2. **International:** clean test product shows only valid Air and/or Sea. Never Standard/local Delivery. Never Store Pickup.
3. **In Warehouse:** Standard Delivery still works. No Pickup. No Air/Sea.

Do not build another long exploratory matrix unless one of those three fails.

Training catalog cleanup (fix `airshipping` route, classify International products) is **owner/data** work, not a substitute for Repair A–C.

---

# Implementation notes (when authorised)

- Development identity on this branch must **not** remain `1.0.0-rc.7`. Use a post-RC.7 dev identity (for example `1.0.0-dev.fulfilment.1`) so RC.7 stays frozen. Schema stays **5**.
- Do not package until the focused matrix and full PHP/JS regression pass.
- Update `docs/AI-HANDOFF.md` current-status **next stage** only after implementation, not in this audit.

---

# STOP

Audit complete. RC.7 tag untouched. No runtime mutation. No package.

**Root cause of In Store Delivery/Pickup conflict:** Store Pickup is implemented as an exclusive XOR fulfilment state (choice lock + In Store strip of pickup-route offers + false wizard `both`), not as an optional alternative beside default Delivery.

**International:** resolver/hard-constraint logic is **not** the leak. Training configuration (wrong routes, unclassified products, labels) plus leftover WooCommerce methods when DE quoting fails explain observed local-looking results. Do not weaken Air/Sea constraints.

**Smallest repair:** keep the ECR; allow In Store `store_pickup` **routes** in the existing offer collection; teach the builder/renderer to emit Delivery + Pickup with Delivery default; bind pickup location from the existing catalog; fail-closed DE-managed packages; add the acceptance tests above.

Awaiting authorisation to implement this plan only.
