# Stage 13B — WordPress-native Delivery Engine UX

**Document status:** Stage 13B implementation record  
**Plugin version:** `1.0.0-rc.2` (public version unchanged; do not package RC.3 yet)  
**Target candidate:** `1.0.0-rc.3`  
**Schema target:** `3` (unchanged)  
**Branch:** `feat/site-wide-delivery-defaults`  
**RC.2 tag:** `v1.0.0-rc.2` — **untouched**  
**Date:** 2026-08-13

---

## 1. Verdict

Stage 13B-R2 owner UX corrections are implemented locally. Final owner UI acceptance of the regenerated 01–31 fixture pack is the remaining gate. Do **not** package RC.3 yet. Do **not** commit solely because tests pass.

Incomplete first-time setup uses the **Setup Guide** submenu. Completed setup activates the supported Classic Checkout runtime as one operation, or shows a single **Activate Delivery Engine** action when WooCommerce shipping still needs assignment. Needs Attention and Delivery Preview share `OperationalReadinessAssessor`, including field-level Delivery Option readiness.

## 2. What changed

- First-time setup is a real 6-step wizard with saved progress (`cetech_de_setup_wizard`).
- Normal returning users land on Overview inside wp-admin.
- Primary menu: Overview → Site-wide Defaults → Delivery Options → Delivery Areas → Delivery Charges → Pickup Locations → Product Exceptions → Needs Attention → Settings.
- Secondary: Legacy Delivery Rules, Technical Diagnostic Tools, Preview, private sources.
- Contextual create for missing Delivery Options, Charges, Areas, and Pickup Locations.
- WooCommerce product/variation Delivery panel is a summary, not a dump of every override.
- Stage 13 inheritance/apply behavior is unchanged.

## 2b. Stage 13B-R1 corrections

- Wizard status is **Setup complete** only on Step 6 after successful apply/readiness.
- Incomplete setup uses the **Setup Guide** submenu; completed setup returns to **Overview**.
- Wizard actions use WordPress `button` / `button-secondary` / `button-primary` footer layout.
- Fulfilment cards use radio vs checkbox semantics with a visible selected checkmark.
- Contextual create forms stay collapsed when a usable entity exists.
- Hard-constraint filtering hides impossible delivery options per fulfilment type.
- Normal UI uses Delivery Option / Delivery Area / Delivery Charge. Rate Card and Offer/Zone labels are not used in staff-facing copy.
- Reference codes sit under Advanced and are generated from the name.
- Delivery Charges reuse the WooCommerce store currency.
- Settings primary view is General / Customer experience / Orders / Setup Guide / Access. Runtime chain is under Advanced.
- Completed setup activates the supported Classic Checkout chain as one operation, or shows **Activate Delivery Engine** when WooCommerce shipping still needs assignment.
- Needs Attention and Delivery Preview share `OperationalReadinessAssessor`.
- International processing/transit split fields are **not** shown. Use the supported Estimated Delivery value. Separate processing/transit/total estimate is a future enhancement only if the current model can persist them correctly.

## 2c. Stage 13B-R2 corrections

- Wizard action footer is a dedicated block outside content cards; desktop left/right, mobile wrap/stack. Fulfilment cards use `auto-fit` / `minmax`.
- Wizard charge labels use `StaffChargeSummary` (no internal codes). In Store shows pickup as no delivery charge. International shows distinct Air/Sea rows. Areas table column is **Actions**.
- Site-wide Defaults uses the same `DeliveryOptionCompatibility` source as the wizard. In Warehouse lists only local delivery. Charge help points to Delivery Charges; no second pricing source.
- Delivery Area editor is a condition builder; match mode/priority stay under **Advanced matching**. List uses singular/plural option counts and human-readable geography.
- Product Exceptions show Product / Fulfilment / Exception / Customized / Status / Actions with Ready / Needs Attention badges.
- Settings has one **Save Changes** action. Estimated delivery is read-only informational copy.
- Legacy page is migration/compatibility guidance only. Diagnostics require `view_delivery_diagnostics`.
- Product/variation customize is a focused business form mapping to inherit/override/replace. Variation inherit copy is **Use Product Setting**. Compatibility filtering is shared.
- Preview uses product/variation selectors, Information | Result | Source, hides supplier/origin/logistics/priority from the ordinary table, and field-level Needs Attention matches the list.
- Product Delivery panel has scoped 782px responsive layout. Fixture chrome stacks product tabs at the same breakpoint.

## 3. What did not change

- Public plugin version remains `1.0.0-rc.2`.
- Schema remains `3`.
- EffectiveConfigurationResolver and customer runtime were not rewritten.
- FLAIROC was not modified.
- Stage 12 training material was not rewritten.

## 4. Companion docs

- `docs/STAGE-13B-DESIGN-REFERENCE-MAP.md`
- `docs/STAGE-13B-ADMIN-SCREEN-INVENTORY.md`
