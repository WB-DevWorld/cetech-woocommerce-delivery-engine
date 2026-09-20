# Glossary (plain language)

**Audience:** All staff  
**Version:** 1.0.0-rc.12 (schema 6)

Terms match what you see in the Delivery Engine screens. Technical implementation words are avoided unless they appear in the UI.

---

**Canonical Location** — One official place in the Delivery Engine directory (a country, region, or locality) with a stable identity. Matching uses this identity, not free spelling. Staff see names; the plugin keeps the identity behind the scenes.

**Location Pack** — Geography data / directory of places for one country. Tells the engine which towns exist. **Not a price.** Menu: **Delivery Engine → Location Packs**.

**Provider** — Who published the place list (on RC.12: GeoNames). Shown on the installed-packs table.

**Administrative Area** — The level under country (Ghana: **Region**; other countries may say State, Province, County, Prefecture).

**Locality** — City or town in the directory (Accra, Tema, Madina, Adenta). Search needs pack-backed locality data **where WooCommerce does not already provide those places**. Deeper city/town selection normally requires a Location Pack. Country/region coverage does not.

**Delivery Area** — Business grouping of destinations that share intended delivery treatment (example: Greater Accra Selected Cities).

**Coverage Group** — Geography rules inside a Delivery Area. Different levels = AND. Same level = OR. Extra groups = OR.

**Coverage Member** — One included or excluded place inside a Coverage Group.

**Coverage Mode** — How the group uses the selected area: Entire selected area / Selected locations / Entire selected area except….

**Entire Area** — Informal name for screen label **Entire selected area** (all of Greater Accra).

**Selected Locations** — Informal name for **Selected locations** (only named towns).

**Entire Area Except** — Informal name for **Entire selected area except…**.

**Inclusion** — Places that qualify in Selected locations mode.

**Exclusion** — Places removed from Entire selected area except…. Exclusions override inclusion **inside that group**.

**Priority** — Number on a Delivery Area. **Lower numbers are checked first** when more than one area could match. Then geographic specificity. Not “smallest automatically wins.”

**Review Required** — Upgrade could not safely prove old text geography against the directory. Data was not destroyed. Warning stays until a person confirms.

**Legacy Rule** — Older text-based country/region/city condition kept as evidence after canonical coverage is active.

**Safe Reconciliation** — Button **Run safe legacy reconciliation** on Location Packs. Revisits migration leftovers after a pack is ready. Does not reset all areas. Does not overwrite valid manual canonical coverage.

**Delivery Option** — Service customers choose (Standard Delivery, Same Day, Pickup).

**Delivery Charge / Rate Card** — Money/rule for a Delivery Option in the relevant Delivery Area. Everyday menu name is **Delivery Charges**. Teaching example GH₵30.

**Fallback** — Area used when more specific areas do not match. Constrained if it still has geography. True Everywhere else only if geography is empty. Native WooCommerce shipping is never the fallback.

**Postcode Exact** — Postcode constraint that must match the whole value.

**Postcode Prefix** — Postcode constraint that matches the starting characters.

**PDP** — Product detail page (the shop product screen).

**HPOS** — WooCommerce High-Performance Order Storage. Staff still open WooCommerce orders as usual. Support cares that the plugin is compatible.

**Action Scheduler** — WordPress/WooCommerce background job runner. Large Location Pack imports continue in batches here. Staff wait; they do not edit jobs by hand.

**Overview** — The everyday Delivery Engine home. Shows whether delivery is ready and what needs attention.

**Setup Guide** — First-time guided setup (Store Setup → Fulfilment Types → Site-wide Defaults → Areas & Charges → Apply to Products → Finish). After setup is complete, reopen from **Settings → Run Setup Guide Again**.

**Site-wide Defaults** — The normal delivery rules for each fulfilment type. Eligible products inherit them automatically.

**Fulfilment** — Where/how the item is available (In Warehouse, In Store, International). Staff use this to choose the right default set. Customers do **not** see this label on the product page.

**In Warehouse** — Fulfilled from warehouse stock / warehouse path. Site-wide Defaults for this type list local delivery options.

**In Store** — Fulfilled from store stock / store path. May include delivery and/or store pickup.

**International** — International path. Delivery Options here are **Air Shipping and/or Sea Shipping only**.

**Delivery method** — How the item reaches the customer: **Delivery** or **Store pickup**. Internal for staff; customers see the public Delivery option instead.

**Delivery Option** — See the RC.12 definition above. Public name customers select.

**Delivery Area** — See the RC.12 definition above. Do not configure live geography with the old free-text condition table when Coverage Groups are active.

**Primary match** — The Delivery Area **Test an address** considers first (priority, then specificity). Not automatically “smallest.”

**Also matches** — Other active Delivery Areas that also cover that address. The selected Delivery Option can use a charge from a broader match when the more-specific area has none for that option. A different option is never substituted.

**Delivery Charge** — See **Delivery Charge / Rate Card** above.

**Ready** — Two uses: (1) Location Pack **ready** = usable geography dataset. (2) Preview Delivery **Ready** = that product’s delivery setup is complete enough to apply. Context tells them apart.

**Pickup Location** — A store collection point used when Store pickup is offered.

**Product Exception** — A product that differs from Site-wide Defaults on one or more fields.

**Variation exception** — A variation that differs from its parent product on one or more fields.

**Inherit** / **Use Site-wide Default** / **Use Product Setting** — Keep the value from the level above.

**Set a different value** — Replace only that field at this product or variation. Other fields still inherit.

**Reset to Site-wide Defaults** / **Reset to Product Settings** — Remove exceptions for this item only.

**Estimated delivery** — Customer-facing timing text. Pickup uses **Ready for pickup** when that applies.

**Needs Attention** — To-do list for products missing a usable delivery setup, and (when shipment records are on) delivery jobs that need a person. Opening the list does not clear a task.

**Preview Delivery** — Read-only view of the values that will actually apply for a product or variation.

**Delivery information** — Staff panel on a WooCommerce order showing the delivery details saved for that order.

**Delivery details** — Compact customer block on thank-you / My Account / email: Delivery option + Estimated delivery.

**Primary default** — The fulfilment type products use when they have no special classification of their own.

**Shipment** — The staff work record for fulfilling a **delivery** group already saved on a WooCommerce order. It is not a new order and not a live carrier feed. Menu: **Delivery Engine → Shipments** (only after an Administrator turns shipment records on).

**Original estimated delivery** — The timing the customer was given at checkout. It stays on the shipment.

**Current estimated delivery** — Later operational timing. Updating it does not overwrite the original.

**Track shipment** — Customer button that appears only when shipment records are on, tracking links are on, and a safe `http` or `https` tracking address is saved.

**Classic Checkout** — WooCommerce shortcode cart/checkout. Still supported.

**Cart/Checkout Blocks** — WooCommerce Blocks cart/checkout. Supported. Settings shows this as status, not an experimental checkbox.

**Bulk Tools** — Administrator screen to preview large catalog or charge changes, then apply them in the background. Tabs: Catalog, Import / Export, Validation & Cleanup, Jobs / History, Charges. Not everyday work.

**Technical Diagnostics** — Hidden support destination. Not for everyday staff.
