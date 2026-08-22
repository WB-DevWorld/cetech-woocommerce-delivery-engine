# POST-RC.6 Tester Observations Audit

**Document status:** Read-only current-runtime defect audit. No repair authorised.  
**Date:** 2026-08-21  
**Audited identity:** tagged `1.0.0-rc.6` / schema `4` / `v1.0.0-rc.6`  
**FLAIROC:** not modified  
**Training site:** not modified  
**Bulk Tools branch:** not modified, not mixed into this audit  
**Stage 15:** not started  
**RC.6 tag:** not moved, not rebuilt, not retagged

This stage exists solely to establish facts from tester observations on the training environment against the **protected RC.6 implementation**.

---

## 0. Repository / release identity

| Item | Value |
|------|--------|
| Cursor working branch | `feat/post-rc6-bulk-tools` |
| Cursor working HEAD | `7fad1b9dff50672093b898e99f64fcf5e42d0f96` (`1.0.0-dev.bulk.2`, schema `5`) |
| Working-tree state | Clean of tracked/untracked source. Gitignored `build/` extract only. **Not reset, stashed, or discarded.** |
| Uncommitted Bulk Tools work | **No uncommitted Bulk Tools source.** Bulk Tools exists as committed branch work. Left untouched. |
| Annotated tag `v1.0.0-rc.6` | `1d3252199f45371ec4229808155f6017ee3da9ea` |
| Peeled tag commit | `8f37fe826e23406c9035312e279699b65c1e72e4` (docs: package checksum) |
| Package source commit | `7e52525cc126f0c4c1841c9bf4b3d7ea9b0bb03f` |
| Runtime delta tag vs package | **None.** `7e52525..8f37fe8` is two documentation files only (`docs/AI-HANDOFF.md`, `docs/STAGE-14H-RC6-FINAL.md`). |
| Plugin version audited | `1.0.0-rc.6` |
| Schema target audited | `4` |
| How RC.6 was audited | Disposable git worktree **outside** the working directory: `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-rc6-tester-audit-worktree` at `v1.0.0-rc.6` (detached `8f37fe8`). |
| Does audited source exactly represent tagged RC.6? | **Yes** for runtime/product code. Identical to package source `7e52525`. |

The current Cursor tree is **not** RC.6. All code conclusions below are from the disposable RC.6 worktree, not from Bulk Tools.

---

## Method

```text
READ → AUDIT → (no implement) → TEST (disposable evidence) → DOCUMENT → STOP
```

- Governance, RC.6 final, lifecycle qualification, Stage 13C International/Air-Sea, Stage 3/4/13, and current code were read first.
- No production/plugin runtime file in tagged RC.6 or on `feat/post-rc6-bulk-tools` was repaired.
- Focused evidence tests were added **only** in the disposable worktree (`tests/Unit/Audit/PostRc6TesterObservationsEvidenceTest.php`) and run there.
- Live training.cetechbpa.com / FLAIROC browsers were **not** used.

Evidence PHPUnit (RC.6 worktree):

| Suite | Result |
|-------|--------|
| `PostRc6TesterObservationsEvidenceTest` | **20 tests, 36 assertions, OK** |
| Existing `HardFulfilmentConstraintServiceTest` | **6 tests, 14 assertions, OK** |

Those evidence tests document **current RC.6 behaviour**, including defective validator order and Store Pickup XOR. They are not a claim that the intended domain contract is already implemented.

---

## Domain terms (as RC.6 actually implements them)

| Term | Storage | Meaning in RC.6 |
|------|---------|-----------------|
| **Fulfilment Availability** | Scalar `fulfilment_availability` | Profile: `in_warehouse` / `in_store` / `international_fulfilment`. Selects which site-wide default slice a product inherits. |
| **Fulfilment Choice** (“Customer fulfilment” / “Delivery method”) | Scalar `fulfilment_choice` | **XOR:** `delivery` **or** `store_pickup`. Not “default plus optional alternative.” |
| **Delivery Route** | Per Delivery Option row `route` | `local_delivery` / `store_pickup` / `air` / `sea`. |
| **Delivery Option** | Catalog row + collection `delivery_offer_ids` | Customer-selectable offer (Standard, Express, Air, Sea, …). Collection modes: INHERIT / ADD / REMOVE / REPLACE. |
| **Store Pickup** | Overloaded | (1) fulfilment **choice** value, (2) offer **route**, (3) pickup **location** entity in the wizard. |

Governing rule and design spec both say: **In Store → Delivery and/or Store Pickup**. RC.6 does **not** implement that as two concurrent customer choices.

---

# ISSUE 1 — International product showing local delivery

## 1. Tester observation

A product was configured as Fulfilment Availability = International, with an international country. The customer-facing product page still showed **local** shipping/delivery rather than Air/Sea.

## 2. Intended behaviour

International Fulfilment → Delivery only → Air and/or Sea.  
Must not silently resolve to an ordinary local-delivery option.

## 3. Actual code path

```text
Admin classify / customize
  → configuration_scopes (product, DEFAULT slice '')
  → configuration_fields.fulfilment_availability = override:international_fulfilment
  → ECR determine_profile_key() uses that override
  → inherit global/0/international_fulfilment (offers, choice, ETA)
  → HardFulfilmentConstraintService keeps route ∈ {air, sea} only
  → EcrToRuntimeConfigurationAdapter → chosen_rules['international_fulfilment']
  → ProductDeliveryOptionsBuilder (one radio per active offer)
  → ProductDeliverySelectorRenderer shows delivery_offers.public_label only
       (Stage 13F hides fulfilment/route labels)

Destination / Delivery Area matching is NOT used on the product page.
It is used later at checkout quote:
  package destination → DestinationZoneMatcher → RateQuoteEngine
```

Persistence:

- Site-wide International defaults live at `global / 0 / international_fulfilment`.
- Product classification writes **only** `fulfilment_availability` override on the product DEFAULT slice (`SiteWideDefaultsService::classify_product`). Offer IDs are inherited, not copied.
- Variation can override the same scalar independently.

Hard filter (authoritative):

```125:131:src/Application/Configuration/HardFulfilmentConstraintService.php
			FulfilmentAvailability::InternationalFulfilment->value => [
				'pickup_allowed' => false,
				'allowed_routes' => [
					DeliveryRoute::Air->value,
					DeliveryRoute::Sea->value,
				],
			],
```

Product-page builder does **not** re-filter by route. It trusts constrained offer IDs.

**Fallback that can still show local:**

| Condition | Result |
|-----------|--------|
| Product **not** classified International (still primary, often In Warehouse) | Local offers inherit. **Reproduced in evidence tests.** |
| International site-wide profile configured, but this product still uses primary | Same as above. Configuring the International **card** does not classify every product. |
| Leftover product slices `in_warehouse` / `in_store` plus International DEFAULT | `resolveAll()` emits multiple slices; adapter can put local **and** Air/Sea into `chosen_rules`. |
| ECR runtime flag off | Legacy `product_delivery_rules` path can ignore scoped International config. |
| Offer `public_label` looks local (e.g. “Standard Delivery”) on an `air` row | Renderer shows that label; route is hidden. |
| WooCommerce shipping-zone methods (Flat Rate / Local Pickup) leftover after delete-data uninstall | Theme/WC can show local shipping independently of Delivery Engine International config. **Lifecycle qualification already recorded this residual.** |

There is **no** product-page destination matcher that swaps International → Ghana/local.

Evidence tests (RC.6 worktree):

- Classified International + Air/Sea site-wide → ECR members `[Air, Sea]`, local ID stripped.
- Unclassified product with both International and In Warehouse site-wide configured → inherits **primary local**, not International.

## 4. Reproduction status

**CONFIGURATION-DEPENDENT** (source-proven paths exist; live training product was not opened).

Not reproduced as “ECR rewrites a classified International product into Local Delivery.” That path is contradicted by unit evidence.

## 5. Root cause

Most likely a **stack of configuration/UX causes**, not a silent International→Local resolver bug:

1. **Classification vs profile configuration.** Setting the International site-wide card, or creating a UK Delivery Area, does not make an unclassified product International. Ordinary products inherit the **primary** profile (typically In Warehouse / local).
2. **Country is not a product-page input.** An “international country” on a Delivery Area affects checkout matching, not which offers the product selector lists.
3. **Presentation.** Selector shows `public_label` only. Air/Sea can look “local” if named that way.
4. **Possible leftover WooCommerce shipping methods** after uninstall+reinstall (see §I).
5. **Possible leftover product slices** if the product was previously In Warehouse/In Store.

## 6. Classification

**configuration misunderstanding + admin UX gap**, with **needs live reproduction** on the exact training product (Effective Preview + offer routes + WC zones).

Not currently classifiable as an RC.6 resolver defect.

## 7. Severity

**P1** operational (staff can believe International is on when the product still inherits primary local).  
Not P0: no evidence of $0 shipping or a hard-constraint bypass when classification is actually stored.

## 8. Affected files / classes

- `src/Application/Configuration/EffectiveConfigurationResolver.php` (`determine_profile_key`, `select_profile_global`, `resolveAll`)
- `src/Application/Configuration/SiteWideDefaultsService.php` (`classify_product`)
- `src/Application/Configuration/HardFulfilmentConstraintService.php`
- `src/Application/Runtime/EcrToRuntimeConfigurationAdapter.php`
- `src/Application/Selector/ProductDeliveryOptionsBuilder.php`
- `src/Presentation/Frontend/ProductDeliverySelectorRenderer.php`
- `src/Presentation/Shared/DeliveryPresentationLabels.php`
- `src/Presentation/Admin/StaffDeliveryCustomizeView.php`
- `src/Presentation/Admin/DeliverySettingsHomePage.php`
- `src/Domain/FulfilmentProfile/FulfilmentProfileRegistry.php`

## 9. Fix type

Code + admin copy only for classification UX. No schema change required to make classified International products resolve Air/Sea (that already works). Live data inspection may show leftover WC zone methods (WooCommerce data, not a Delivery Engine migration).

## 10. Regression risks

A naive “always show Air/Sea when any International profile exists” would break primary inheritance. Do not auto-classify the catalog.

## 11. Smallest safe repair (recommendation only)

Do **not** patch ECR first. On the training product:

1. Open Product → Delivery / Product Exceptions Effective Preview.
2. Confirm `fulfilment_availability` is `international_fulfilment`.
3. Confirm inherited offer IDs have `route` = `air`/`sea`.
4. Confirm the visible PDP string is the Delivery Engine selector, not WoodMart/WC estimate.
5. Confirm WC zone methods on the relevant zone.

If Preview shows International + Air/Sea but PDP shows Standard Delivery, then investigate leftover slices / legacy flag / label. If Preview shows In Warehouse, this is classification UX, not a resolver defect.

Optional small UX later: on International customize, state clearly that **this product** must be classified International; site-wide International defaults do not apply until then.

## 12. Tests that must accompany a future repair

Keep the evidence tests: International cannot keep `local_delivery`; Air only; Sea only; Air+Sea; classified International does not fall back to primary local; unclassified still inherits primary.

---

# ISSUE 2 — Delivery Area country selection too limited

## 1. Tester observation

While creating Delivery Areas, only options similar to **Everywhere / Africa / Ghana** were available. Individual international countries (UK, US, Nigeria, Germany, China) could not be selected.

## 2. Intended behaviour

Reusable/multi-country architecture: administrators can define Delivery Areas for ordinary WooCommerce countries.

## 3. Actual code path

**CETECH Delivery Areas do not present Everywhere / Africa / Ghana.** That triad is the WooCommerce **Shipping zones → Zone regions** picker. Training doc `docs/training/12-SETUP-CONFIGURE-AND-TEST.md` describes both screens and uses “Everywhere” only for WooCommerce zones.

Delivery Area editor (`DestinationZonesPage`):

- Location column: hardcoded `Country` / `State / Region` / `City` / `Postcode`.
- Value column: **free-text** ISO code (`GB`, `US`, …), not a country-name dropdown.
- `WC()->countries->get_countries()` is used only to **label** a stored code, not to populate a picker.
- No `get_continents()`. No continent rule type.

Engine matcher: exact ISO-2 country match. Validator accepts any `[A-Z]{2}` — including `GB`, `US`, `NG`, `DE`, `CN`. Evidence test passed.

Setup wizard **does** lock new areas to `store_country()` (WooCommerce base location, fallback `GH`) and has no country field.

## 4. Reproduction status

**CONFIGURATION-DEPENDENT** for “Everywhere / Africa / Ghana” (wrong screen: WooCommerce zones).  
**REPRODUCED** as a CETECH admin UX gap: no country-name picker; wizard forces store country.

## 5. Root cause

1. If the picker was Everywhere / Africa / Ghana: **WooCommerce shipping-zone regions**, not Delivery Areas.
2. If they were on Delivery Areas: **no WooCommerce country dropdown**; staff must type ISO codes. Easy to read as “cannot select countries.”
3. Wizard create-area path **cannot** choose UK/US/etc.

Underlying destination engine **does** support those countries.

## 6. Classification

**missing capability** (country-name selector) + **admin UX defect** (ISO-only + wizard lock) + possible **configuration misunderstanding** (WC zones vs Delivery Areas).

Not an engine allow-list defect.

## 7. Severity

**P1** for international operations (staff cannot discover countries by name; wizard blocks them).  
Not P0: full editor can already save `GB`/`US`/`NG`/`DE`/`CN` as text.

## 8. Affected files / classes

- `src/Presentation/Admin/DestinationZonesPage.php` (`render_condition_row`, `rule_type_options`, `country_label`)
- `src/Presentation/Admin/Validation/DestinationRuleValidator.php`
- `src/Application/Destination/DestinationZoneMatcher.php`
- `src/Presentation/Admin/SetupWizardPage.php` (`handle_create_area`)
- `src/Application/Configuration/ContextualEntityService.php` (`store_country`, `create_delivery_area`)
- `assets/admin/delivery-engine-admin.js` (row clone only)

## 9. Fix type

Admin UI only for a WC_Countries picker. No schema change. Wizard would need a country field (code only).

## 10. Regression risks

A continent option in Delivery Areas would **not** match the destination engine (no continent rule type). Do not copy WooCommerce’s Everywhere/Africa picker into Delivery Areas without engine support.

## 11. Smallest safe repair

Keep matcher as ISO-2. Add a WooCommerce country `<select>` for Country rows; keep free-text for region/city/postcode. Unlock wizard country from store-only. Do not add continents.

## 12. Tests

Country validator accepts GB/US/NG/DE/CN (already passing). Future: picker options sourced from `WC_Countries` include those names; wizard can persist a non-store country.

---

# ISSUE 3 — In Store / Store Pickup hides Standard Delivery

## 1. Tester observation

Store Pickup was configured for a product. The product page then showed **only** Store Pickup. Standard Delivery disappeared.

## 2. Intended behaviour

Fulfilment Availability = In Store.  
Default fulfilment choice = Delivery.  
Optional alternative = Store Pickup.  
If Delivery is selected, applicable local Delivery Options remain available.  
Enabling pickup must not destroy legitimate Delivery choices.

Design spec §8 and governing rules: **In Store → Delivery and/or Store Pickup**.

## 3. Actual code path

`fulfilment_choice` is a **single** enum (`delivery` | `store_pickup`). Site-wide In Store editor is a `<select>`. Wizard shows radios including **“Delivery + Store Pickup” (`both`)**, then saves:

```895:898:src/Presentation/Admin/SetupWizardPage.php
		$choice = FulfilmentChoice::StorePickup->value === $mode
			? FulfilmentChoice::StorePickup->value
			: FulfilmentChoice::Delivery->value;
```

`both` is stored as **`delivery`**.

Hard constraints for In Store allow pickup **choice**, but allowed **offer routes** are **`local_delivery` only**. A Store Pickup **offer** checked in admin is stripped at resolve time.

Then the product-page builder:

```51:54:src/Application/Selector/ProductDeliveryOptionsBuilder.php
			if ( FulfilmentChoice::StorePickup->value === $choice_slug ) {
				$options[] = $this->store_pickup_option( ... );
				continue;
			}
```

If choice is Store Pickup, `delivery_offer_ids` are **never expanded**. Customer sees one stub: “Store pickup available.”

Admin `DeliveryOptionCompatibility` **adds** `store_pickup` to In Store choice lists (`pickup_allowed`), so staff can select a pickup offer that runtime then removes.

## 4. Reproduction status

**REPRODUCED** in source and evidence tests.

`test_storefront_builder_drops_delivery_options_when_choice_is_store_pickup` — Standard + Express IDs present, choice = store_pickup → labels `['Store pickup available']` only.

`test_in_store_strips_store_pickup_route_offers_from_collection` — In Store + Delivery choice + pickup offer ID → collection keeps local only.

## 5. Root cause

RC.6 models Store Pickup as an **exclusive fulfilment choice**, not as an optional alternative alongside Delivery. Wizard “both” is UI-only. Pickup **route** offers are incompatible with In Store hard constraints.

Selecting Store Pickup does **not** silently REPLACE the offer collection. It overrides `fulfilment_choice`, which is enough to hide Delivery Options.

## 6. Classification

**RC.6 runtime defect relative to the governing In Store rule** + **admin UX defect** (labels blur Fulfilment Choice with Delivery Options; wizard `both` is false).

This is also why Issue 4 can look like “I can only assign one option.”

## 7. Severity

**P1.** Blocks the canonical In Store customer model. Not P0: no $0 shipping; behaviour is deterministic.

## 8. Affected files / classes

- `src/Domain/Enum/FulfilmentChoice.php` (XOR enum)
- `src/Domain/Configuration/ConfigurationFieldRegistry.php`
- `src/Application/Configuration/Admin/ConfigurationFieldCatalog.php`
- `src/Application/Selector/ProductDeliveryOptionsBuilder.php`
- `src/Application/Configuration/HardFulfilmentConstraintService.php`
- `src/Application/Configuration/DeliveryOptionCompatibility.php`
- `src/Domain/FulfilmentProfile/FulfilmentProfileRegistry.php` (In Store `allowed_routes` = local only)
- `src/Presentation/Admin/SetupWizardPage.php`
- `src/Presentation/Admin/DeliverySettingsHomePage.php`
- `src/Presentation/Admin/StaffDeliveryCustomizeView.php`

## 9. Fix type

Code + admin UX. **No schema change strictly required** if pickup remains a choice plus local offers, and the builder emits pickup **and** delivery radios when In Store + pickup enabled. A cleaner model (choice = default; pickup = eligibility flag or offer in the collection) is a small architecture tightening, still schema-4 compatible if encoded in existing scalars/collections.

## 10. Regression risks

RC.5 QA.6 explicitly passed **pickup suppression**. Any repair must keep: International/In Warehouse cannot offer pickup; In Store + Air/Sea still forbidden; cart must bind the one selected radio.

## 11. Smallest safe repair

Keep `fulfilment_choice` as the **default** selection, not a path lock:

- In Store + pickup allowed → builder emits local Delivery Options **and** a Store Pickup option.
- Default selected = Delivery.
- Continue stripping Air/Sea on In Store.
- Either allow `store_pickup` route offers in the In Store collection **or** synthesize pickup from choice/location without requiring that route in `delivery_offer_ids`.
- Make wizard `both` persist real dual eligibility.
- Relabel “Customer fulfilment / Delivery method” so it is not confused with Delivery Options.

## 12. Tests

In Store can expose Delivery + Store Pickup; In Store Delivery can expose multiple local options; In Warehouse/International still cannot expose pickup; cart captures the selected radio only.

---

# ISSUE 4 — Cannot assign multiple Delivery Options

## 1. Tester observation

Attempted to assign two Delivery Options to the same product; could only configure one.

## 2. Intended behaviour

Multiple eligible Delivery Options at Global / Product / Variation, with INHERIT / ADD / REMOVE / REPLACE.

## 3. Actual code path

Storage **does** represent multiple IDs (`configuration_collections.members_json`).

| Layer | Multi-select? |
|-------|----------------|
| Site-wide Defaults offers | Checkboxes `fields[delivery_offer_ids][members][]`, hidden `mode=replace` of the **eligible set** |
| Product/variation customize | Mode radios inherit/replace/add/remove + **checkboxes** |
| Fulfilment choice | Single `<select>` / radio (XOR) |
| Delivery Options catalog screen | One entity per form (expected) |

ECR merge: REPLACE = list as-is; ADD = append missing; REMOVE = subtract. Evidence: `test_product_add_preserves_inherited_local_offers` → `[Standard, Express]`.  
Builder + selector: multiple radios when choice = Delivery. Evidence: `test_storefront_builder_renders_multiple_local_delivery_options`.  
Cart captures the one posted radio.

Site-wide is **not** a single default-offer field. Profile **cards** sometimes label it “Delivery option” (singular) for non-Air/Sea profiles.

## 4. Reproduction status

**NOT REPRODUCED** as a storage/ECR/selector hard limit.  
**CONFIGURATION-DEPENDENT** as UX: XOR Store Pickup, REPLACE with one checkbox, wizard `both`, or creating offers one-at-a-time.

## 5. Root cause

No single-select field where the **offer collection** was intended — except **`fulfilment_choice`**, which staff can mistake for “the delivery option.” Combined with Issue 3, enabling pickup looks like replacing Standard Delivery.

## 6. Classification

**admin UX defect** + consequence of Issue 3. **Not** a missing multi-offer storage capability.

## 7. Severity

**P2** by itself; **P1** when combined with Issue 3.

## 8. Affected files / classes

- `src/Domain/Configuration/ConfigurationFieldRegistry.php`
- `src/Application/Configuration/EffectiveConfigurationResolver.php` (collection merge)
- `src/Presentation/Admin/DeliverySettingsHomePage.php`
- `src/Presentation/Admin/StaffDeliveryCustomizeView.php`
- `src/Presentation/Admin/ScopedConfigurationPage.php`
- `src/Application/Selector/ProductDeliveryOptionsBuilder.php`
- `src/Presentation/Frontend/ProductDeliverySelectorRenderer.php`
- `src/Application/Cart/CartDeliverySelectionCapture.php`

## 9. Fix type

Mostly UX copy + Issue 3 repair. No schema change.

## 10. Regression risks

Changing Site-wide from REPLACE-of-set to something else would alter inheritance. Leave collection semantics.

## 11. Smallest safe repair

Do not invent a second collection. Fix Issue 3; pluralise “Delivery options”; make ADD/REPLACE labels plainer (“Add these options to the inherited list” vs “Use only these options”).

## 12. Tests / reproduction matrix (current RC.6)

| Setup | Expected (canonical) | Actual RC.6 |
|-------|----------------------|-------------|
| Global Standard + Express; product inherit | Both | **Both** if choice=Delivery |
| Product ADD a second **local** option | Inherited + added | **Both** |
| Product REPLACE with two local options | Both | **Both** |
| Product/Site-wide choice = Store pickup | Delivery default + pickup alternative | **Pickup only** (Issue 3) |
| Wizard “Delivery + Store Pickup” | Both | Persists **choice=delivery**; pickup **route** offers stripped |
| Product ADD Store Pickup **offer** (route=`store_pickup`) | Inherited locals + pickup | Pickup ID **stripped** by hard constraints |

---

# ISSUE 5 — Reference code auto-generation does not match UI

## 1. Tester observation

Advanced details says Reference code is generated from the name if left blank. Leaving it blank causes validation failure. Same for Delivery Areas.

## 2. Intended behaviour / UX contract

Staff enters Name → leave Reference Code blank → system generates a stable unique code → validates uniqueness → saves.

Training `docs/training/12-SETUP-CONFIGURE-AND-TEST.md` step 9: “Leave Advanced details / reference code blank” then Create Delivery Area.

## 3. Actual code path

Help text and HTML `required=false` promise generation. Validators run **first** and require code:

- `DeliveryOfferValidator`: empty → “Code is required.”
- `DestinationZoneValidator`: same.

`DeliveryOffersPage::handle_save` / `DestinationZonesPage::handle_save` only call `AdminFormHelper::generate_code_from_name()` **after** a non-empty `$errors` already redirected.

Rate Cards already generate **before** validate (`RateCardsPage.php`). Wizard `ContextualEntityService::create_delivery_option` / `create_delivery_area` also generate first — wizard path works; catalog editors do not.

Sanitiser exists (`sanitize_code`, `generate_code_from_name`, uniqueness suffix `-2`…`-99`). Duplicate **names** are allowed. Existing filled codes stay stable on rename.

Evidence tests: blank code fails both validators; generator produces `air-shipping` / `united-kingdom` and `air-shipping-2` on collision.

## 4. Reproduction status

**REPRODUCED.**

## 5. Root cause

Validate-before-generate in Delivery Option and Delivery Area catalog save paths. Shared faulty order, not a missing generator. UI promise is false.

## 6. Classification

**RC.6 runtime / admin contract defect.** Do not merely change the help text; generation is the intended architecture (Rate Cards + wizard already do it).

## 7. Severity

**P1.** Blocks ordinary create. Training instructions fail.

## 8. Affected files / classes

- `src/Presentation/Admin/DeliveryOffersPage.php`
- `src/Presentation/Admin/DestinationZonesPage.php`
- `src/Presentation/Admin/Validation/DeliveryOfferValidator.php`
- `src/Presentation/Admin/Validation/DestinationZoneValidator.php`
- `src/Presentation/Admin/AdminFormHelper.php` (generator; not at fault)
- Contrast: `src/Presentation/Admin/RateCardsPage.php`, `src/Application/Configuration/ContextualEntityService.php`

## 9. Fix type

Code only. No schema change.

## 10. Regression risks

Generated codes must remain unique and stable after later name edits (do not regenerate when a code already exists).

## 11. Smallest safe repair

Mirror Rate Cards: if sanitised code is blank, generate from name, then validate. Keep uniqueness check. Do not make staff invent codes.

## 12. Tests

Blank code + valid name saves with generated unique code for Option and Area; duplicate names get suffix; existing code not rewritten on rename; uniqueness conflict still errors.

---

# ISSUE 6 — Create button disappears after validation error

## 1. Tester observation

After the Reference Code error, **Create delivery option** disappears. Similar possible for Delivery Area.

## 2. Intended behaviour

Show field/global error, preserve values, **keep Create/Save visible**, allow resubmit without a full manual recovery reload.

## 3. Actual code path

On validation error: stash draft → flash error → redirect to `action=add`.  
`render_form()` **always** outputs `.cetech-de-form-actions` + `submit_button( 'Create Delivery Option' )` after Advanced details. Draft values are restored. No PHP/CSS/JS hides the submit control.

Header CTA on the add form is **“Back to Delivery Options”**, not Create. Create exists only as the footer WordPress `submit_button`. After redirect, scroll is at the **top** error notice. Advanced `<details>` is collapsed. Staff looking at the header do not see Create.

## 4. Reproduction status

**NOT REPRODUCED** as a missing/non-rendered button.  
**REPRODUCED** as a UX failure mode after Issue 5’s error reload.

## 5. Root cause

Issue 5 forces the error path. Then header CTA ≠ Create, viewport starts at the notice, footer submit is easy to miss. PHP still renders the button.

## 6. Classification

**admin UX defect** (perceived missing button), secondary to Issue 5.

## 7. Severity

**P2** (P1 pressure while Issue 5 remains).

## 8. Affected files / classes

- `src/Presentation/Admin/DeliveryOffersPage.php` (`render_form`, `handle_save`)
- `src/Presentation/Admin/DestinationZonesPage.php`
- `src/Presentation/Admin/AdminPageLayout.php` (`render_page_header`, `open_advanced`)
- `src/Presentation/Admin/AdminNoticeService.php` (draft + flash)

## 9. Fix type

Code/UX only. Fixing Issue 5 removes the usual trigger. Optional: keep a primary Create in the header; scroll to form-actions on error; leave Advanced closed but keep actions above the fold.

## 10. Regression risks

Low.

## 11. Smallest safe repair

Repair Issue 5 first. Then put Create/Save in the visible header/actions row so a future validation error cannot hide the only submit affordance below the fold.

## 12. Tests

Error redirect still contains the submit input in rendered HTML; draft fields preserved; button usable without a second navigation.

---

# ISSUE 7 — Responsive admin card overlap

## 1. Tester observation

Fulfilment Availability cards (In Warehouse, In Store, International) overlap at a smaller admin viewport. A screenshot was referenced; it was **not available as an image in this audit session**, so layout was audited from CSS/markup.

## 2. Intended behaviour

Wide: up to 3 columns. Medium: 2. Narrow: 1. No overlap, no covering inputs, no escaped parent, no horizontal scroll for normal content. Keyboard/touch targets remain usable.

## 3. Actual code path

Wizard fulfilment-type cards use `.cetech-de-choice-grid`:

```css
grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
```

plus `min-width: 0` on `.cetech-de-choice-card`. Radios are visually clipped (`position: absolute; clip`). Marks are absolutely positioned inside the card body.

Site-wide Defaults home and Overview use `.cetech-de-profile-card-grid` **without** that hardening:

- `assets/admin/delivery-engine-admin.css`: `minmax(220px, 1fr)`
- `assets/admin/scoped-configuration.css`: `minmax(240px, 1fr)` (more specific)

`@media (max-width: 782px)` does **not** retarget profile-card-grid. Product Exceptions has no such cards.

Evidence test asserts choice-grid has `min(100%, …)` and profile-card-grid does not.

## 4. Reproduction status

**REPRODUCED** as a source-level CSS defect on profile-card grids.  
**NEEDS LIVE REPRODUCTION** for exact pixel overlap at the tester’s viewport (wp-admin sidebar expanded vs folded). Wizard choice-grid was already partially hardened; overlap can still occur from intrinsic grid minimums + absolute marks if the container cannot shrink.

Representative widths (source reasoning, not browsered):

| Width | Expected | Risk |
|-------|----------|------|
| Wide desktop | 3 columns | Low |
| wp-admin + expanded sidebar (~700–900px content) | 2 columns | Profile grid may overflow instead of wrapping cleanly |
| Tablet ~782px | 2 → 1 | Profile grid unhardened |
| Small/mobile | 1 column | Choice-grid safer; profile-grid can overflow |

Touch targets on choice cards are ≥ the card body (~44px+). Hidden radios rely on the label; focus-visible outline exists.

## 5. Root cause

Profile-card CSS uses rigid `minmax(220/240px, 1fr)` without `min(100%, …)` and without `min-width: 0` on cards. Choice-grid already has the known fix; profile-grid does not. No 782px collapse for those cards.

## 6. Classification

**admin UX / CSS defect.**

## 7. Severity

**P2.** Does not affect storefront money. Blocks comfortable admin use on laptop/tablet.

## 8. Affected files / classes

- `assets/admin/delivery-engine-admin.css`
- `assets/admin/scoped-configuration.css`
- `src/Presentation/Admin/DeliverySettingsHomePage.php`
- `src/Presentation/Admin/OverviewPage.php`
- `src/Presentation/Admin/AdminPageLayout.php` (`render_choice_cards`)
- `src/Presentation/Admin/SetupWizardPage.php`

## 9. Fix type

CSS only.

## 10. Regression risks

Low if the choice-grid pattern is reused. Avoid `repeat(3, 1fr)`.

## 11. Smallest safe repair

Apply `minmax(min(100%, 220px), 1fr)` + `min-width: 0` to `.cetech-de-profile-card-grid` / `.cetech-de-profile-card`, matching `.cetech-de-choice-grid`. Verify 3/2/1 wrap at 1200 / 900 / 600 px content widths.

## 12. Tests

CSS contract test (already in evidence suite) plus owner visual QA at those widths. Keyboard: Tab moves card-to-card; focus ring visible.

---

# I. Cache / stale-configuration

| Mechanism | Can it explain these observations after delete-data reinstall? |
|-----------|----------------------------------------------------------------|
| Object cache | Delivery Engine does not `wp_cache_*` configuration. ECR memoises **in-request only**. Unlikely across a fresh request. Persistent object-cache plugins could cache SQL; not DE-specific. |
| Transients | Admin notices/drafts only (`cetech_de_admin_notice_*`, `cetech_de_admin_draft_*`, activation notice). Lifecycle qualification: several DE options are **not** cleared on delete-data (`cetech_de_global_configuration_version`, notice transients, …). Not a storefront offer source. |
| Browser / localStorage | **None** in RC.6 `src/`. |
| WooCommerce sessions | Cart selection only after add-to-cart; not product-page offer lists. |
| Product meta | Scoped config is plugin tables, dropped on delete-data. Legacy `product_delivery_rules` also dropped. Protected `_cetech_de_*` **order** meta is retained (historical orders only). |
| Configuration scopes | Dropped on delete-data. Fresh International/local mix is new config, not stale rows — unless classification was never written. |
| **WooCommerce shipping-zone method instances** | **Retained after delete-data uninstall** (WooCommerce data). Can still show Flat Rate / Local Pickup / old Delivery instances. **Relevant to Issue 1 and Issue 2** if the tester is looking at WC zones or theme shipping estimates. |
| Generated configuration / fingerprint | Fingerprint is a cache-bust token for selection validation, not a second offer list. |

A fresh install does **not** guarantee WooCommerce-owned zone methods were removed. That is documented in `docs/RC6-COMPATIBILITY-LIFECYCLE-QUALIFICATION.md` and remains true.

---

# J. Domain-rule test results (RC.6, no production changes)

| # | Rule | Result on current RC.6 |
|---|------|-------------------------|
| 1 | International cannot resolve Local Delivery | **PASS** when the product is classified International (offer IDs filtered). Unclassified products still show primary local. |
| 2 | International can resolve Air only | **PASS** |
| 3 | International can resolve Sea only | **PASS** |
| 4 | International can resolve Air + Sea | **PASS** |
| 5 | In Store can expose Delivery + Store Pickup | **FAIL vs intended.** Choice XOR; pickup-only PDP. |
| 6 | In Store Delivery can expose multiple local options | **PASS** if choice=Delivery |
| 7 | In Warehouse cannot expose Air/Sea/Pickup | **PASS** (offers filtered) |
| 8 | Multiple eligible Delivery Options survive ECR | **PASS** (inherit/add/replace) |
| 9 | Blank Reference Code + valid name generates code | **FAIL** on catalog Option/Area validators. Generator exists but runs too late. Wizard/Rate Cards succeed. |
| 10 | Validation failure does not make resubmit logically impossible | PHP still renders Create. UX can hide it. |
| 11 | Country source includes normal WooCommerce countries | Engine **accepts** ISO codes. Admin **does not** present WC country names. Continents are WC zones, not DE. |

---

# Consolidated table

| ISSUE | CONFIRMED? | ROOT CAUSE | CURRENT RC.6 BEHAVIOUR | EXPECTED BEHAVIOUR | SEVERITY | REPAIR SIZE | SAFE WITHOUT SCHEMA CHANGE? | OWNER PHYSICAL QA REQUIRED? |
|-------|------------|------------|------------------------|--------------------|----------|-------------|-----------------------------|------------------------------|
| 1 International shows local | CONFIGURATION-DEPENDENT | Classification vs primary inheritance; country unused on PDP; possible WC zone leftovers; labels hide route | Classified International → Air/Sea. Unclassified → primary local. PDP shows `public_label` | Classified International never shows ordinary local delivery | P1 | Small UX if classification; live data first | Yes | **Yes** — inspect the exact product + WC zones |
| 2 Country picker limited | PARTIAL | WC zone picker vs ISO text field; wizard store-country lock; no WC_Countries dropdown | Engine accepts GB/US/NG/DE/CN as text. UI has no country names. Wizard = store/`GH` | Staff can choose ordinary WC countries by name | P1 | Medium UI | Yes | Yes — confirm which screen they used |
| 3 Pickup hides Delivery | **YES** | `fulfilment_choice` XOR + builder short-circuit + In Store strips pickup **route** offers | Store Pickup choice → PDP pickup only | In Store: Delivery default + optional pickup; local options remain | P1 | Medium (builder + constraints + wizard) | Yes | Yes |
| 4 Cannot assign two options | NOT as storage limit | UX + Issue 3. Collection already multi-checkbox | Multiple local offers work end-to-end when choice=Delivery | Same, plus pickup must not wipe them | P2 (P1 w/ #3) | Small UX after #3 | Yes | Yes |
| 5 Blank reference code fails | **YES** | Validate before generate | “Code is required.” Generator never runs | Blank → generate → unique → save | P1 | Small (mirror Rate Cards) | Yes | Yes, short create-form retest |
| 6 Create button disappears | UX YES / render NO | Error redirect + header is Back, submit is footer | Button still in HTML | Button remains obvious after error | P2 | Small UX after #5 | Yes | Yes |
| 7 Card overlap | Source YES | Unhardened profile-card `minmax(220/240px)` | Choice-grid safer; profile-grid can overflow | 3/2/1 wrap, no overlap | P2 | CSS only | Yes | **Yes** — visual at 1200/900/600 |

---

# Stop-line answers

## 1. What is definitely broken

- **Issue 5:** UI/training promise that blank Reference Code auto-generates is false on Delivery Option and Delivery Area catalog forms.
- **Issue 3:** Store Pickup is an exclusive choice. It hides legitimate local Delivery Options. Wizard “Delivery + Store Pickup” does not persist dual eligibility. In Store hard constraints strip `store_pickup` **route** offers.
- **Issue 7:** Profile-card CSS can overflow/overlap at narrower wp-admin widths.
- **Issue 6 (UX):** After Issue 5’s error, Create is easy to lose even though PHP still outputs it.

## 2. What is merely confusing

- Fulfilment Availability vs Fulfilment Choice vs Delivery Option vs Store Pickup vs Delivery Area vs **WooCommerce shipping zones**.
- Site-wide International configuration vs **classifying a product** as International.
- Delivery Area **ISO code** field vs WooCommerce **Everywhere / Africa / Ghana** zone regions.
- Singular “Delivery option” labels; “Use only these options” (REPLACE) vs inherit.

## 3. What is actually missing

- WooCommerce country-name picker on Delivery Areas (capability gap, not matcher gap).
- Wizard country other than store/`GH`.
- A real In Store “Delivery **and** Store Pickup” customer model.
- Header-level Create/Save after validation errors.

## 4. What is already supported but incorrectly configured / misunderstood

- Multiple Delivery Options (checkboxes, ECR, PDP radios, cart).
- International Air/Sea resolution **when the product is classified International** and Air/Sea offers exist.
- Arbitrary WooCommerce countries as Delivery Area rules **if staff type ISO-2 codes** in the full editor.
- Hard constraints that **prevent** International from keeping `local_delivery` offer IDs.

## 5. Which defect should be repaired first

**Issue 5 (Reference Code generation order).** Smallest, fully proven, matches Rate Cards, unblocks Issue 6’s usual trigger, and matches training.

Then **Issue 3** (In Store Delivery + Pickup), because it is a governing-rule miss and explains much of Issue 4.

Do **not** start with an International ECR rewrite until the training product’s Effective Preview is inspected.

## 6. Do these observations undermine previous RC.6 qualification claims?

**They do not overturn RC.6 QA.2 (Ghana Accra/Kumasi Standard Delivery + genuine WC shipping) or RC.6 lifecycle qualification.**

They **do qualify** those claims:

| Prior claim | Still true? | Note |
|-------------|-------------|------|
| RC.6 QA.2 local Ghana checkout | Yes | Different path than International PDP |
| Hard constraints International = Air/Sea | Yes **at resolve time when classified** | Unclassified products still inherit primary local — this was always the Stage 13 model, but staff UX does not make that obvious |
| RC.5 QA.6 Air/Sea + pickup suppression | Pickup suppression is real — and **too strong** for In Store “and/or” | Qualification tested suppression, not concurrent Delivery+Pickup |
| Lifecycle: WC zone methods survive delete-data | Yes | Directly relevant to leftover local shipping and Everywhere/Africa/Ghana |
| Multi-offer architecture | Storage/ECR/PDP support it | Admin/choice model can still present one |

This is **not** evidence that RC.6 silently ships $0 or that tagged hard constraints are unhooked.

## 7. Exact smallest repair sequence (for owner approval later)

1. **Issue 5** — generate code before validate on Option + Area catalog saves (copy Rate Cards). Tests: blank name-derived unique codes.  
2. **Issue 6** — keep Create/Save in the visible actions row after error (often free once #5 is gone).  
3. **Issue 7** — CSS: profile-card-grid matches choice-grid `min(100%, …)` + `min-width: 0`. Owner visual QA.  
4. **Issue 2** — Country `<select>` from `WC_Countries` on Country rule rows; wizard country field. No continents.  
5. **Issue 3** (+ Issue 4 UX) — In Store presents Delivery options **and** Store Pickup; `both` becomes real; do not weaken International/In Warehouse constraints.  
6. **Issue 1** — only after live Preview of the training product. Likely copy/classification UX, leftover WC methods, or leftover slices — not an ECR rewrite.

Do **not**: retag RC.6; create RC.7 in this audit; mix Bulk Tools; touch FLAIROC; add schema 5; implement browsing location/localStorage; implement multi-address checkout; implement Return/Refund policy.

---

## Scope intentionally excluded

- Any runtime repair
- Bulk Tools / schema 5
- FLAIROC / training-site writes
- Stage 15
- Browsered wp-admin at live widths (CSS reasoned from source)
- Opening the exact training product in WooCommerce admin

## Files changed by this audit

- `docs/POST-RC6-TESTER-OBSERVATIONS-AUDIT.md` (this file, on the Cursor workspace)
- Disposable RC.6 worktree only: `tests/Unit/Audit/PostRc6TesterObservationsEvidenceTest.php` plus a local `vendor/` copy used to run PHPUnit. **Not** part of Bulk Tools. **Not** a release artifact. The worktree may be removed with `git worktree remove` when the owner no longer needs it.

## Schema / migration impact

None.

## Runtime behaviour

Unchanged.

## Security / privacy / shipping-integrity / compatibility / performance

No change. Issue 1 live check must still fail closed (no $0). Issue 3 repair must not reintroduce International pickup or In Store Air/Sea.

## Known limitations of this audit

- Training site was not queried.
- Screenshot for Issue 7 was not attached in-session.
- Evidence tests document current behaviour; intended In Store dual-choice and blank-code save are **not** green as product contracts.

## Recommended next phase

Owner reads this report and authorises a named repair sequence. **STOP.**
