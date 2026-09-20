# Delivery Areas and Coverage Groups

**Audience:** Administrators and authorised configuration staff
**Plugin:** CETECH WooCommerce Delivery Engine **1.0.0-rc.12** (schema **6**)
**Open:** WordPress admin → **Delivery Engine → Delivery Areas**

Companion: [14 — Location Packs and geography](14-LOCATION-PACKS-AND-GEOGRAPHY.md).

---

## One-sentence answers

A **Delivery Area** is a business grouping of destinations that should share the same intended delivery treatment.

A **Coverage Group** is the geography rule set **inside** that area: which canonical places qualify.

A Delivery Area is **not** a Location Pack, **not** a Delivery Option, and **not** a GH₵30 fee.

---

## The teaching example

**Delivery Area name:** Greater Accra Selected Cities

**Coverage group 1**

- Country: Ghana
- Region: Greater Accra
- Coverage: **Selected locations**
- Include locations: Accra, Tema, Madina, Adenta

**Delivery Option:** Standard Delivery
**Delivery Charge:** GH₵30

You do **not** create four Delivery Areas (one per city) for one shared price.

---

## Delivery Area — what is it?

A Delivery Area answers: “Which destinations do we treat together for this service and price?”

Examples:

- Greater Accra Selected Cities
- All of Greater Accra
- Ghana except a few remote towns
- Accra special service (higher priority than the general Greater Accra area)
- A true country-wide or “everywhere else” fallback

Open: **Delivery Engine → Delivery Areas**. Buttons: **Add Delivery Area**, then **Create Delivery Area** or **Save Delivery Area**.

### Fields on the editor

Under **Delivery area details**:

- **Delivery area name** — staff name (required)
- **Reference code** — generated from the name if left blank
- **Customer-facing label** — optional public label
- **Status** — Inactive areas are kept for reference but are not used for new orders

Under **Locations & delivery options** you build **Coverage groups** (RC.12 live configuration).

Under **Advanced details**:

- **Priority** — “Lower numbers are checked first when more than one delivery area could match.”
- **Use as fallback for unmatched addresses**
- **Remote area** — staff marker for hard-to-reach areas

### Fallback (plain English)

- Tick fallback **and leave geography empty** only for a true **Everywhere else** area: used only when no other Delivery Area matches.
- Tick fallback **and keep geography** for a fallback **inside those places only**. A Greater Accra fallback with Ghana + Greater Accra never matches the United States.
- Native WooCommerce shipping is never used as a Delivery Engine fallback.
- If nothing matches, the destination stays unmatched. That is fail-closed, not free shipping.

---

## Coverage Group — what is it?

The live help text on the editor:

> Coverage groups describe the destinations that share this Delivery Area. Different levels inside a group combine with AND. Multiple places at the same level combine with OR. Additional groups combine with OR.

You add groups with **+ Add another coverage group**. Remove one with **Remove coverage group**.

Each group has:

- **Country**
- Administrative area (for Ghana: **Region**)
- **Coverage** mode
- optional included or excluded localities
- optional **Postcode constraints**

A **Coverage member** is one included or excluded place inside the group.

If an area has no coverage group yet, the screen says you should add one to use canonical geography, and that legacy location conditions stay available only until coverage is active.

When canonical coverage is active, leftover old conditions appear under **Legacy location conditions (compatibility evidence only)**. Do not treat that disclosure as the live editor.

Do not silently fall back to hidden legacy conditions. If you remove every coverage group, you must tick:

**If I remove every coverage group, stop using canonical coverage for this Delivery Area. Do not silently fall back to hidden legacy conditions.**

---

## Boolean semantics (plain English)

### Different hierarchy levels = AND

The destination must match **all** chosen levels in that group.

Example: Ghana **AND** Greater Accra **AND** the selected localities.

A customer in Kumasi (Ashanti) does not match a Greater Accra selected-cities group.

### Several places at the same level = OR

Example: Accra **OR** Tema **OR** Madina **OR** Adenta.

Any one of those localities is enough, once Country and Region also match.

### Several Coverage Groups = OR

Example:

- Group 1: Greater Accra selected cities
  **OR**
- Group 2: Ashanti selected cities

The Delivery Area matches if **either** group matches.

AND/OR here is **geography matching**. It is **not** the Global → Product → Variation inheritance model. Do not mix those two ideas.

---

## Coverage modes (exact RC.12 labels)

The **Coverage** dropdown:

| Screen label | When to use | Teaching example |
|--------------|-------------|------------------|
| **Entire selected area** | Every place under the chosen country or region | All of Greater Accra |
| **Selected locations** | Only the towns/cities you pick | Accra, Tema, Madina, Adenta |
| **Entire selected area except…** | The whole chosen area, minus named places | All Greater Accra except Ada Foah and Prampram |

Staff sometimes say “Entire Area / Selected Locations / Entire Area Except.” On the RC.12 screen the labels are the three in the table above.

Country or region **Entire selected area** can use WooCommerce-provided administrative geography without a GeoNames locality pack. A pack is needed when the included or excluded places are deeper cities/towns WooCommerce does not already list, or when old locality text needs reconciliation. The mode name itself does not require a pack.

### Entire selected area

1. Country: Ghana
2. Region: Greater Accra (or **Entire Ghana** if the root is the country)
3. Coverage: **Entire selected area**
4. Do not pick individual towns

Use this for “we serve the whole region.”

### Selected locations

1. Country: Ghana
2. Region: Greater Accra
3. Coverage: **Selected locations**
4. Search under **Include locations**
5. Add Accra, Tema, Madina, Adenta as chips
6. Optional **Select all** / **Clear**

The count reads “N locations included.”

Use this for “one price for these cities” without creating one area per city.

### Entire selected area except…

1. Country: Ghana
2. Region: Greater Accra
3. Coverage: **Entire selected area except…**
4. Search under **Excluded locations**
5. Add Ada Foah and Prampram

The count reads “N locations excluded.”

**Exclusions override inherited inclusion inside that group.** A town on the exclude list does not qualify for **this** group even though it sits inside Greater Accra.

That town might still match **another** Delivery Area (for example a remote-area service). Exclusion does not ban the destination from the whole store.

---

## Postcode constraints (optional)

Under **Postcode constraints**:

- Optional. Leave empty for no postcode restriction.
- Multiple values are **OR**.
- Match mode **Exact** or **Prefix**.
- **Add postcode** adds a row.

Postcode is only shown or required to customers where it is relevant. Hidden postcode on the shop is often correct, not a defect.

---

## Priority and overlaps

**Priority** is a business ordering number. **Lower numbers are checked first.**

It does **not** mean “the smallest map shape automatically wins.”

When several active Delivery Areas can legitimately match the same destination, the engine uses a **deterministic** order:

1. Configured **priority** (lower number first)
2. Then geographic specificity (more precise geography before broader geography when priority is equal)
3. Then a stable name/code tie-break

### Teaching example

| Priority | Area | Intent |
|----------|------|--------|
| **8** | Accra special service | Preferred business rule for Accra |
| **25** | Greater Accra general service | Broader backup treatment |

Give the more preferred business rule the **lower** priority number.

### What customers and prices do

**Test an address** (on the Delivery Areas list) shows:

- **Primary match:** the first area in that deterministic order
- **Also matches:** other active areas that also cover the address

Pricing can use a charge from a broader matching area when the selected **Delivery Option** has **no charge** in the more-specific area. A **different** Delivery Option is never substituted.

If the more-specific area has an **invalid** charge for that option, checkout fails closed. It does not silently inherit the broader fee.

### Overlap diagnostics

On the list you may see:

- **Some delivery areas overlap.** Nested city-in-region is normal.
- **Some delivery-area overlap could not be fully proven.** Test specific addresses instead of treating those areas as uncovered.
- **Some delivery areas have no delivery charges.** Customers may not see prices until you add matching charges.

Do not treat overlap itself as an error.

---

## Review required

**Review required does not mean data was destroyed.**

It means the upgrade could not **safely prove** the mapping from old text-based geography (for example the word `"Accra"`) to the new canonical directory. The plugin **refuses to guess**.

The live warning:

> Review required: this group was converted from legacy rules or could not be mapped with full confidence. Saving other fields will not clear this warning.

### Training-site example

Training upgraded **RC.11 / schema 5 → RC.12 / schema 6**. Migration converted five Delivery Areas. Accra and Kumasi became review-required / unmapped city because **no Ghana Location Pack** was installed. That is exactly why Location Packs exist.

The plugin keeps the legacy information (you may see previous scope such as Ghana > Greater Accra > Accra) and asks a person to confirm.

If mapping the old city onto **Entire selected area** would **widen** coverage, the screen says so and asks you to tick **Confirm replacement with entire selected area**. Do not tick that unless you really intend broader coverage.

### Staff workflow

1. Install / verify the Location Pack for that country ([14](14-LOCATION-PACKS-AND-GEOGRAPHY.md)). Wait until status is **ready**.
2. Stay on **Location Packs**.
3. Click **Run safe legacy reconciliation** where appropriate.
4. Open the affected **Delivery Area**.
5. Confirm the intended canonical geography (Selected locations vs entire area vs except).
6. Tick **I reviewed this migrated coverage** when the group is correct. Activate or correct deliberately.
7. **Test an address**, then test a QA product page, cart, and checkout.

Saving unrelated fields **will not** clear the warning. You must review.

Do **not** delete and recreate business data just because a group says review required.

---

## Safe legacy reconciliation (from Delivery Areas’ point of view)

The button lives on **Location Packs**, not on each area:

**Run safe legacy reconciliation**

Use it after a pack is **ready** so old city names can be mapped with confidence.

It will **not**:

- reset every Delivery Area
- overwrite valid manually created canonical coverage
- guarantee that every review-required group becomes active

Ambiguous leftovers still need the workflow above.

---

## Test an address

On the Delivery Areas list, open **Test an address**.

Fill **Country** (pick the name, for example Ghana), **Region**, **City**, **Postcode** as needed. Click **Run test**.

Read **Primary match** and **Also matches**.

This is the safest way to see which area the engine considers first **before** customers use it.

---

## What can go wrong?

| Symptom | Likely cause | What to do |
|---------|--------------|------------|
| Review required | Pack missing or city text unmapped | Install pack, reconcile, confirm geography |
| Broader area “winning” | Its priority number is lower, or the special area is inactive/review-required | Check **Priority** and whether the special area is usable |
| Excluded town still gets a service | Another Delivery Area still matches that town | Inspect **Also matches**; exclusions apply inside that group only |
| Two services both available | Two options are assigned and both have charges | That can be correct (Standard and Same Day) |
| Locality search empty in the coverage builder | Pack not **ready** | Install/wait for the pack |
| No fee at checkout | Coverage matches but no Delivery Charge for that option | Add the charge; never assume GH₵0 |
| Postcode hidden | Not relevant for that destination | Expected |

---

## What should I not do?

- Do not create one Delivery Area per city when one **Selected locations** group is the business rule.
- Do not invent “smallest area always wins.” Set **Priority** for the preferred rule.
- Do not delete review-required areas to “clean up” an upgrade.
- Do not tick **Confirm replacement with entire selected area** unless wider coverage is intended.
- Do not confuse Coverage Groups with Site-wide → Product → Variation inheritance.
- Do not edit coverage tables in the database.

---

## How do I test it?

Using the teaching example:

1. Location Pack for Ghana is **ready**.
2. Delivery Area **Greater Accra Selected Cities** exists and is **Active**.
3. Coverage group uses **Selected locations**: Accra, Tema, Madina, Adenta.
4. Standard Delivery + GH₵30 Delivery Charge exist.
5. **Test an address** for Accra → Primary match is this area.
6. **Test an address** for a Greater Accra town you did **not** include → this area should not be the match (unless another group/area covers it).
7. QA product page: select Accra, see Standard Delivery GH₵30.
8. Cart and checkout show the same fee.
9. Place a **safe test order** only if authorised. Review the order **Delivery information** snapshot. Do not rewrite it later.

---

## Related guides

- [14 — Location Packs](14-LOCATION-PACKS-AND-GEOGRAPHY.md)
- [02 — Complete Administrator Guide](02-COMPLETE-ADMIN-GUIDE.md)
- [12 — Setup, configure, and test](12-SETUP-CONFIGURE-AND-TEST.md)
- [03 — Use-Case Playbook](03-USE-CASE-PLAYBOOK.md)
- [07 — Troubleshooting FAQ](07-TROUBLESHOOTING-FAQ.md)
- [08 — Glossary](08-GLOSSARY.md)
