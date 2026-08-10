

I’ll separate what the earlier advice gets wrong from a checkout-safe solution, then give you one coherent setup path for per-product Air or Sea choices.

The key correction is clear: selecting Air or Sea per product is **not** standard WooCommerce shipping. Standard checkout chooses a shipping method per package, while your requirement binds a route and price to each cart line. The “product-options plugin \+ generic split-packages plugin” plan does not automatically keep those choices synchronized.

I have verified the implementation boundary: WooCommerce supports custom shipping methods and package splitting through its shipping API, and those package/rate hooks are supported in block-based carts and checkout. That makes a small dedicated extension the clean production solution—not a fragile collection of unrelated plugins.

Thought for 1m 49s

I will treat this as an independent, standalone matter. I have used only the attached brief as the requirement source.

## **The correct final solution**

Build a small custom WooCommerce extension for your store: **Per-Item Delivery Routes**.

Do **not** make Air and Sea ordinary product variations. Do **not** rn plus a generic package-splitting plugin as the production solution.

Your requirement is unusual but valid:

* A customer chooses an existing product variation, such as Size or Colour.  
* The same customer chooses a delivery route for that specific product before adding it to cart.  
* Product A may use Sea while Product B uses Air in the same order.  
* Each selected route has its own processing range, transit range, price, availability, and fulfilment instructions.  
* Checkout must preserve the choices, charge them correctly, and create a usable fulfilment record.

WooCommerce normally selects shipping per checkout package, not per product line. A custom shipping method and package split are therefore needed to make each selected route a real shipping charge rather than a disguised product surcharge. WooCommerce provides a Shipping Method API for custom rates, and supports changing shipping packages and package rates in both classic and block-based Cart/Checkout.

## **Why the earlier proposed plans are not sufficient**

Do **not** use “Shipping Mode” as a new variable-product attribute.

That would create combinations such as:

`Colour × Size × Shipping Mode`

It complicates stock, variations, pricing, imports, and administration. Shipping route is a fulfilment choice, not a product characteristic.

Do **not** use WooCommerce Per-Product Shipping alone.

It can set product- or variation-level shipping costs, but its standalone mode adds all product costs into one shipping rate. It does not give a customer a binding Air-or-Sea selection for each product before Add to Cart. \*\* install a product-options plugin plus a shipping-packages plugin and assume they will synchronize.

A product option can add money to an item, while a shipping-packages plugin can split the cart. But they do not automatically understand that “Sea chosen on Product A” must create a Sea shipment for Product A and that “Air chosen on Product B” must create an Air shipment for Product B. That integration must be deliberately built. WooCommerce’s package extensions split using configured product/cart conditions; they do not natively bind themselves to a customer-selected product-option value. \*\* create two $0 placeholder methods called Air and Sea.

That risks showing misleading $0 shipping at checkout, duplicating charges, or allowing the customer to choose a different route at checkout from the one selected on the product page.

---

## **The recommended architecture**

### **1\. Keep current products and variations unchanged**

Your existing variable products remain variable products.

For example:

* Product A  
  * Colour: Red, Black  
  * Size: Small, Large  
* Product B  
  * Colour: Blue, White  
  * Size: Medium, Large

Air and Sea are **not** added as product attributes or variations.

### **2\. Add a “Delivery Routes” panel to every product**

Your custom plugin adds a tab under Product Data:

**Product Data → Delivery Routes**

Each product, and optionally each variation, gets a delivery-route profile.

Example for Product A:

| Route | Processing | Transit | Shipping fee | Charge basis |
| ----- | ----- | ----- | ----- | ----- |
| Sea Shipping | 3–6 business days | 20–30 days | $25 | Per item / per line |
| Air Shipping | 2–4 business days | 7–15 days | $95 | Per item / per line |

The admin fields should include:

* Route: Air or Sea  
* Enabled or disabled  
* Customer label  
* Processing minimum and maximum  
* Transit minimum and maximum  
* Business days or calendar days  
* Delivery price  
* Charge rule: per item, per cart line, or per shipment  
* Eligible countries or destination zones  
* Tax class  
* Fulfilment note for staff  
* Product-level default profile  
* Variation-level override, where needed

A variation should inherit the parent product’s delivery profile unless the variation has an explicit override.

This matters because the official Product Add-Ons extension applies the same add-ons to all variations and cannot create different add-ons per variation. The customer experience

On the product page, after the customer chooses Size/Colour, show required radio-card choices.

**Choose delivery route**

**Sea Shipping — $25**  
 Processing: 3–6 business days  
 Transit: 20–30 days  
 Estimated delivery is shown after processing.

**Air Shipping — $95**  
 Processing: 2–4 business days  
 Transit: 7–15 days  
 Estimated delivery is shown after processing.

Then show:

* Product: $49  
* Selected delivery route: Sea Shipping $25  
* **Item total: $74**

The Add to Cart button stays disabled until a valid delivery route is chosen.

The customer should not choose the route again at checkout.

---

## **What happens with your exact example**

The customer adds:

* Product A: $49  
* Sea Shipping: $25  
* Product A total: $74

Then adds:

* Product B: $70  
* Air Shipping: $95  
* Product B total: $165

At checkout, WooCommerce should show:

| Item | Product total | Delivery route |
| ----- | ----- | ----- |
| Product A | $49 | Sea Shipping — $25 |
| Product B | $70 | Air Shipping — $95 |

**Products:** $119  
 **Shipping:** $120  
 **Grand total before tax:** $239

The order contains two fulfilment packages:

1. **Sea shipment — Product A** — $25  
2. **Air shipment — Product B** — $95

The customer pays once, receives one WooCommerce order, and your staff can fulfil the two shipments separately.

---

## **How the custom plugin must work technically**

The custom plugin should do the following.

1. Save the customer’s selected route into the cart item data when the product is added.  
2. Keep the route selection in the cart item key. This ensures the same product can exist twice in the cart with different delivery routes.

    Example:

   * Product A \+ Sea  
   * Product A \+ Air  
3. These must remain separate cart lines.

4. Revalidate price, route availability, destination, and product/variation eligibility on every cart and checkout recalculation.  
5. Split the WooCommerce cart into delivery packages using the selected route stored on each cart line.  
6. Register one custom WooCommerce shipping method, such as:

    `Selected Delivery Route`

7. Return exactly one valid shipping rate for each package:  
   * “Sea Shipping — Product A” → $25  
   * “Air Shipping — Product B” → $95  
8. Hide conflicting Flat Rate, Free Shipping, or carrier methods from those packages so there is no double charge.  
9. Save route details onto:  
   * the order item;  
   * the shipping line;  
   * staff fulfilment metadata;  
   * customer order emails and order-history pages.  
10. Support both classic checkout and Cart/Checkout Blocks. WooCommerce documents that `woocommerce_cart_shipping_packages`, `woocommerce_package_rates`, and custom shipping method registration are supported in blocks. kout can also display processing/transit information directly beneath each shipping rate. WooCommerce supports rate descriptions and delivery-time text in Cart and Checkout blocks. Exact WordPress/WooCommerce setup

### **A. Install only these components**

* WooCommerce  
* Your custom **Per-Item Delivery Routes** plugin

Do not make Product Add-Ons, Per-Product Shipping, or Advanced Shipping Packages mandatory dependencies for the production version.

### **B. Configure shipping zones**

Go to:

`WooCommerce → Settings → Shipping → Shipping zones`

Create your delivery zones, such as:

* Ghana  
* United States  
* United Kingdom  
* Canada  
* Germany  
* Rest of world

In each relevant zone, add the custom shipping method:

**Selected Delivery Route**

Do not also leave a general Flat Rate active for the same products unless it is deliberately intended as an additional charge. WooCommerce shipping methods are zone-based, and the customer sees methods from their matched zone. onfigure each product

For Product A:

* Base product price: $49  
* Sea route:  
  * Processing: 3–6 business days  
  * Transit: 20–30 days  
  * Fee: $25  
* Air route:  
  * Add only if this product genuinely supports Air  
  * Enter its own time and fee

For Product B:

* Base product price: $70  
* Air route:  
  * Processing: 2–4 business days  
  * Transit: 7–15 days  
  * Fee: $95  
* Sea route:  
  * Add only if genuinely supported

### **D. Decide the quantity rule explicitly**

This must be a deliberate business rule.

Choose one:

* **Per item:** Quantity 2 of Product A means two $25 Sea charges.  
* **Per cart line:** Quantity 2 of Product A means one $25 Sea charge.  
* **Per shipment:** A custom rule determines the consolidated shipment charge.

For most individual imported goods, “per item” is the safer default. For cartons, bundles, or consolidated freight, “per shipment” may be correct.

### **E. Destination first, where prices vary by country**

Exact pricing cannot be truthful before the customer’s destination is known if Air/Sea cost changes by country.

Use a delivery-country selector in the header or product page. Then show only routes valid for that destination, with their correct times and prices.

When destination is unknown, show:

> Select your delivery country to see exact Air and Sea delivery options.

Do not show a supposedly exact $25 or $95 price when it can change for the customer’s country.

---

## **The no-code shortcut, and its limitation**

For a quick MVP, use the official **WooCommerce Product Add-Ons** extension.

Create a required multiple-choice radio field on each product:

* Sea Shipping — Processing 3–6 business days; Transit 20–30 days — \+$25  
* Air Shipping — Processing 2–4 business days; Transit 7–15 days — \+$95

The extension supports required single-choice radio options, fixed or quantity-based pricing, product-level configuration, and cart/order display of selected options. this shortcut treats the delivery amount as an **item add-on**, not a true WooCommerce shipping charge. It is acceptable only when:

* you only need the customer total to be correct;  
* shipping accounting can be treated as item-option revenue;  
* taxes on shipping are not handled separately;  
* variation-specific route rules are unnecessary;  
* your staff can fulfil from line-item metadata.

For a business that must split fulfilment into Sea and Air shipments, keep shipping revenue separate, produce accurate packing/fulfilment records, and grow safely, use the custom shipping-method solution.

---

## **Minimum acceptance tests before launch**

Test all of these in staging:

1. Product A Sea \+ Product B Air produces $239 before taxes.  
2. Same product added twice with two different routes remains as two lines.  
3. Existing Size/Colour variations still work.  
4. A route unavailable for the customer’s country cannot be selected.  
5. Changing address recalculates or blocks invalid routes.  
6. Quantity rule charges correctly.  
7. Cart, checkout, confirmation page, email, invoice, and admin order all show the selected route and time ranges.  
8. Staff can identify Sea versus Air shipments without reading free text.  
9. Refunds can refund product value and shipping value independently.  
10. No secondary Flat Rate, Free Shipping, or carrier method double-charges the order.

This is the production-safe way to achieve the exact Product A Sea \+ Product B Air workflow.

Sat, Jul 4 at 7:45 PM  
Pasted text(194).txt  
Document  
Pasted text (2)(8).txt  
Document  
also how will the buyer easily confirm that they have received the product; also accounting for the fact that they may forget or be lazy to confirm. how may the delivery person confirm that they have delivered it without being able to lie? also a product outside the country can have Air Shipping and/or Sea Shipping; and is labeled Delivery Only and its the pre-selected choice for the buyer. And a product in the country cannot have Air Shipping and/or Sea Shipping. It can be labeled In Store hence it can have Store Pickup or/and Delivery. Delivery is pre-selected choice for the buyer but the buyer can change it to Store Pickup hence the delivery details and calculations disappears. or In Warehouse hence it has Delivery Only which is the pre-selected choice for the buyer. Also we dont have these: 3\. Live quote or approval-required quote Use when Uber, Bolt, Yango, a courier, or a transport company must provide the actual current price. Same-Day Express Courier Estimated arrival: today Price: calculated from carrier quote If you have a live carrier integration, show the exact quote before payment. If you do not have a reliable live quote, show: Same-Day Express Courier Price confirmed before payment Estimated arrival: today The customer submits a quote request or provisional order, your staff confirms the price, and the customer pays only after approving it. Do not allow a customer to finish a paid order with an unknown delivery charge. Therefore for products having this Same-Day Express Courier as an available option, we will manually input the best estimated price when setting up the product so that the customer can checkout normally. Also record suppliers and origins but do not show origins or suppliers to the customers. Attached to this chat are information about the status, plugins and other information about the site to ensure you have as much info as possible to avoid errors and confilicts.  
Pasted text(195).txt  
Document  
Pasted text (2)(9).txt  
Document  
The plugin will be used in multiple identical site in some WPML/WCML is installed and active and in some its not. This is complicated in our current operation. do not implement it. 1\. Buyer confirmation vs proof of delivery Do not depend on buyers clicking “I received it.” Many will forget. Use two separate concepts: Delivered — verified proof: operational delivery status. Buyer confirmed receipt: optional customer action. A shipment can become Delivered — verified proof when the delivery person completes a proof-of-delivery flow. The buyer may later click “Confirm receipt,” but their silence should not block operations. Required delivery proof A delivery person should not be able to simply press “Delivered.” Require at least: Driver is assigned to that shipment. Driver scans the shipment/package QR code. GPS location, timestamp, and accuracy are captured. Customer receives a one-time delivery code by SMS, WhatsApp, email, or push notification. Customer gives the code only after receiving the item, or scans the driver’s QR code and confirms on their own phone. Driver uploads a delivery photo where appropriate. Receiver name and relationship are recorded. Every action is audit logged and cannot be edited silently. The strongest normal flow is: Driver arrives → customer receives OTP → customer sees the package → customer gives OTP or confirms through QR → system marks shipment Delivered — recipient verified A driver cannot truthfully complete this without access to the customer’s phone/code. It does not make fraud mathematically impossible, but it makes false delivery claims difficult, traceable, and contestable. For higher-value products, require: OTP \+ package scan \+ GPS \+ photo \+ signature or ID check For store pickup: Customer opens pickup QR/OTP in My Account → staff scans package → customer gives OTP or scans pickup code → pickup is marked collected What happens when buyers forget? After verified delivery: immediately send: “Did you receive your order?” show two buttons: Confirm receipt and Report a problem remind after 24 hours and 3 days after a configurable period, such as 7 days, retain: Delivered — recipient verified No issue reported Do not falsely label it “buyer confirmed” merely because they stayed silent. The order can become completed when every shipment is delivered and no delivery dispute is open. How do I fix this? WoodMart overrides the variable-product add-to-cart template, and the report marks that override as outdated against the installed WooCommerce core version. Attached is the file of plugins and status of one of the sites in which WOML and WMCL is activated.  
Worked for 36s

I’m pulling together the additions made after the last specification so the final addendum reflects the newer decisions without reopening settled architecture.

Reviewed WooCommerce updates, fulfillment requirements, and final specification decisions

Yes. The final specification should now add the following as **binding architectural rules**, not optional implementation ideas.

# **Final Addendum — Configuration Inheritance, Native-First WooCommerce Reuse, and Effective Rule Resolution**

## **1\. Native-first remains the governing engineering principle**

For every feature, the plugin must first determine whether WordPress or WooCommerce already provides the underlying capability.

The rule is:

Use WooCommerce core directly  
→ extend WooCommerce through supported APIs/hooks where necessary  
→ create custom domain functionality only where WooCommerce cannot correctly represent the requirement

The plugin must not create parallel implementations of capabilities WooCommerce already owns.

Reuse WooCommerce for:

* products;  
* variations;  
* product weight and dimensions;  
* native shipping classes where useful;  
* customer records;  
* addresses;  
* cart;  
* WooCommerce sessions;  
* shipping packages;  
* shipping methods;  
* shipping rates;  
* checkout;  
* taxes;  
* coupons;  
* payments;  
* order items;  
* shipping lines;  
* refunds;  
* order statuses;  
* My Account;  
* customer emails;  
* HPOS/order CRUD;  
* logging;  
* REST/Store API extension mechanisms;  
* capabilities and permissions where appropriate;  
* background scheduling only where genuinely needed.

Custom logic should exist only for the project's distinct fulfilment domain.

---

# **2\. Global configuration is the starting point for everything**

The plugin must allow administrators to configure comprehensive global defaults.

The basic inheritance model is:

GLOBAL  
   ↓  
PRODUCT  
   ↓  
VARIATION

The most specific explicitly configured value wins.

Therefore:

Variation override  
\> Product override  
\> Global default

When a product has no override, it automatically uses the current global value.

When a variation has no override, it automatically uses the product's effective value.

There must be no requirement to copy global values manually into every product or variation.

---

# **3\. Inheritance is field-by-field, not whole-record replacement**

A product changing one property must not disconnect itself from all global defaults.

Example:

GLOBAL

Fulfilment:  
International Fulfilment

Offers:  
Air Economy  
Sea Consolidated

Processing:  
2–4 business days

Logistics Profile:  
Standard Parcel

Supplier:  
Supplier A

Product A overrides only:

Processing:  
5–7 business days

Its effective configuration becomes:

Fulfilment:  
International Fulfilment  
\[global\]

Offers:  
Air Economy  
Sea Consolidated  
\[global\]

Processing:  
5–7 business days  
\[product override\]

Logistics Profile:  
Standard Parcel  
\[global\]

Supplier:  
Supplier A  
\[global\]

A variation may then override only:

Logistics Profile:  
Bulky Item

Offers:  
Sea Consolidated only

Everything else continues to inherit.

This field-level inheritance model applies throughout the system.

---

# **4\. Every applicable field must have an explicit inheritance state**

Do not infer inheritance merely from an empty database value.

Every overridable property must support explicit states such as:

INHERIT  
OVERRIDE  
DISABLE / NONE

Where collections are involved, also support:

INHERIT  
REPLACE  
ADD  
REMOVE

This distinction is essential.

For example:

Global:  
Store Pickup \= enabled

Product:  
Store Pickup \= disabled

The product must remain disabled.

It must not see the empty/false value and incorrectly fall back to the global `enabled` setting.

---

# **5\. Global settings should be comprehensive**

Where logically applicable, global configuration must exist for the following domains.

## **Fulfilment**

Global defaults for:

* Fulfilment Availability;  
* Delivery enabled;  
* Delivery Only behavior;  
* Store Pickup eligibility;  
* default fulfilment choice;  
* required customer selection;  
* default pickup location;  
* pickup readiness;  
* delivery routes;  
* eligible Delivery Offers;  
* default Delivery Offer.

## **Delivery Offers**

Global definitions for:

* offer identity;  
* public label;  
* route;  
* service level;  
* carrier visibility;  
* named carrier;  
* public description;  
* availability;  
* eligible fulfilment states;  
* destination eligibility;  
* Logistics Profile eligibility;  
* default processing range;  
* transit range;  
* final-mile range;  
* display priority;  
* tax behavior;  
* selection/default behavior.

## **Rates and pricing**

Global support for:

* base currency;  
* fixed per shipment;  
* fixed per item;  
* fixed per cart line;  
* base \+ incremental item pricing;  
* actual-weight pricing;  
* volumetric-weight pricing;  
* weight bands;  
* highest-item pricing;  
* zone surcharges;  
* remote-area surcharges;  
* handling charges;  
* insurance where used;  
* free-shipping thresholds;  
* manual currency prices;  
* rounding;  
* tax behavior.

## **Time and ETA**

Global defaults for:

* processing minimum/maximum;  
* transit minimum/maximum;  
* final-mile minimum/maximum;  
* same-day hour ranges;  
* business/calendar day behavior;  
* operating weekdays;  
* cutoff times;  
* timezone;  
* holidays;  
* customs buffers;  
* dispatch buffers;  
* destination buffers;  
* preorder delays;  
* made-to-order delays;  
* customer ETA formatting.

## **Logistics**

Global defaults for:

* Logistics Profile;  
* size classification;  
* handling requirement;  
* route eligibility;  
* charge basis;  
* consolidation behavior;  
* dispatch type;  
* special packing;  
* high-value handling;  
* restrictions.

## **Suppliers and origins**

Optional global defaults for:

* supplier;  
* origin;  
* dispatch source;  
* supplier processing time;  
* dispatch calendar;  
* consolidation policy.

For multi-source catalogs, `No global supplier/origin` may deliberately be the safer default.

## **Destination rules**

Global configuration for:

* countries served;  
* default country;  
* destination-selection requirements;  
* state/region requirements;  
* cities;  
* postcodes;  
* service zones;  
* remote areas;  
* unknown-destination behavior;  
* fallback behavior.

## **Store Pickup**

Global defaults for:

* pickup availability;  
* pickup price;  
* tax behavior;  
* pickup location;  
* readiness time;  
* opening hours;  
* instructions;  
* stock requirements;  
* customer display.

## **Carriers**

Global configuration for:

* carrier records;  
* carrier visibility;  
* assigned-by-store behavior;  
* public name;  
* routes;  
* zones;  
* service levels;  
* tracking URL formats;  
* priority.

## **Consolidation**

Global rules for:

* whether consolidation is allowed;  
* supplier matching;  
* origin matching;  
* route matching;  
* service matching;  
* carrier matching;  
* destination matching;  
* dispatch-window matching;  
* Logistics Profile compatibility;  
* maximum package weight;  
* volume;  
* item count;  
* mandatory separate-shipment conditions.

## **Customer presentation**

Global defaults for:

* fulfilment labels;  
* offer labels;  
* processing display;  
* transit display;  
* final-mile display;  
* doorstep ETA;  
* carrier visibility;  
* cart details;  
* mini-cart details;  
* checkout details;  
* order confirmation;  
* emails;  
* My Account shipment cards;  
* loading messages;  
* unavailable messages;  
* icons/layout.

## **Shipment behavior**

Global defaults for:

* automatic shipment creation trigger;  
* initial shipment status;  
* shipment number format;  
* public status labels;  
* tracking display;  
* public notes;  
* completion policy;  
* email behavior;  
* My Account visibility.

---

# **6\. Products must be able to override applicable global settings**

Product configuration should not be a second unrelated configuration system.

Each product screen should show the inherited global value and allow:

Use global setting  
Override for this product  
Disable / None

For collection fields:

Use global offers  
Add offers  
Remove offers  
Replace offers

Example:

Global International Offers:

Air Economy  
Air Express  
Sea Consolidated

Product A:

Remove:  
Air Express

Effective Product A:

Air Economy  
Sea Consolidated

Product B:

Replace with:  
Sea Consolidated

Effective Product B:

Sea Consolidated only  
---

# **7\. Variations must inherit from the effective parent product**

Default variation state:

Use parent product configuration

This means the variation inherits the parent's **effective** configuration, which already includes global inheritance.

A variation then overrides only fields that genuinely differ.

Example:

GLOBAL

Processing:  
2–4 days

Offers:  
Air  
Sea

Profile:  
Standard Parcel

Product:

Processing:  
5–7 days

Large variation:

Profile:  
Bulky Item

Remove:  
Air

Effective large variation:

Processing:  
5–7 days

Offers:  
Sea only

Profile:  
Bulky Item

No unnecessary duplication should occur.

---

# **8\. Strict business rules override configuration flexibility**

Inheritance must never create invalid fulfilment combinations.

The domain rules remain:

International Fulfilment  
→ Delivery Only  
→ Air and/or Sea

In Store  
→ Delivery and/or Store Pickup  
→ local fulfilment only

In Warehouse  
→ Delivery Only  
→ local delivery services

Therefore global, product, variation, CSV, REST, bulk-edit, or administrative configuration must never produce:

International \+ Store Pickup

In Store \+ Sea

In Store \+ Air

In Warehouse \+ Air

In Warehouse \+ Sea

The Effective Configuration Resolver must reject these states.

Configurability never outranks domain integrity.

---

# **9\. The plugin needs one authoritative Effective Configuration Resolver**

This is now one of the most important architectural components.

Create:

EffectiveConfigurationResolver

Every part of the plugin must use it.

The resolver performs:

Load applicable global defaults  
        ↓  
Apply product overrides  
        ↓  
Apply variation overrides  
        ↓  
Resolve explicit disable/none states  
        ↓  
Resolve add/remove/replace collections  
        ↓  
Apply fulfilment-domain validation  
        ↓  
Apply destination rules  
        ↓  
Apply Logistics Profile constraints  
        ↓  
Apply supplier/origin constraints  
        ↓  
Resolve eligible Delivery Offers  
        ↓  
Resolve Rate Card  
        ↓  
Resolve currency  
        ↓  
Calculate customer delivery price  
        ↓  
Calculate ETA  
        ↓  
Calculate consolidation behavior  
        ↓  
Return final effective configuration

There must not be separate inheritance logic in different frontend or backend components.

The exact same resolver must feed:

* product page;  
* variable product changes;  
* Quick View;  
* cart;  
* mini-cart;  
* checkout;  
* shipping packages;  
* order creation;  
* shipment planning;  
* admin preview;  
* imports;  
* API;  
* Store API;  
* WPML adapter;  
* WCML adapter;  
* WCFM adapter;  
* VitePOS adapter.

---

# **10\. Effective values must expose their source internally**

Administrators need to understand *why* a product has a particular value.

The resolver should internally return:

VALUE  
\+  
SOURCE

Example:

Fulfilment:  
International Fulfilment  
Source: Global

Processing:  
5–7 business days  
Source: Product

Logistics Profile:  
Bulky Item  
Source: Variation

Sea Shipping:  
Enabled  
Source: Product/global combination

This source information powers:

* admin diagnostics;  
* configuration previews;  
* bulk-edit previews;  
* audit records;  
* troubleshooting.

It is not necessarily exposed to customers.

---

# **11\. Product and variation admin UI must show inherited values**

Do not make administrators guess what “Use default” means.

Example product field:

Processing Time

● Use global setting  
  Current global value: 2–4 business days

○ Override

Variation:

Processing Time

● Use product setting  
  Effective parent value: 5–7 business days

○ Override variation

The product editor should contain:

Effective Configuration Preview

The variation editor should similarly show what is inherited and from where.

---

# **12\. Global changes need impact visibility**

Because one global change may affect thousands of products, the system should support an impact preview where practical.

Example:

Changing Sea Shipping transit from:

20–30 business days

to:

25–35 business days

will currently affect:

1,245 products  
3,880 variations

Products/variations with explicit overrides remain unaffected.

A dry-run or preview is especially important for:

* Delivery Offer changes;  
* Rate Cards;  
* route eligibility;  
* destination zones;  
* supplier defaults;  
* Logistics Profiles;  
* timing rules;  
* currency overrides.

---

# **13\. Historical orders must never inherit future changes**

Inheritance applies only while determining the current effective configuration.

At successful checkout/payment, snapshot the resolved values.

Snapshot:

* Fulfilment Availability;  
* Fulfilment Choice;  
* Delivery Offer;  
* route;  
* service level;  
* customer-visible carrier;  
* rate;  
* currency;  
* Rate Card/version;  
* processing estimate;  
* transit estimate;  
* final-mile estimate;  
* estimated delivery to address;  
* destination/service zone;  
* Logistics Profile;  
* private supplier;  
* private origin;  
* consolidation rule;  
* relevant configuration versions.

Future global/product/variation changes must never alter historical paid orders.

Example:

Order placed:  
Sea transit \= 20–30 days

Global setting later changes:  
Sea transit \= 25–35 days

Old order:

remains 20–30 days

New carts/orders:

use 25–35 days unless overridden  
---

# **14\. Configuration versioning must be built in**

Use versions for cache invalidation and auditability.

Examples:

global\_configuration\_version  
delivery\_offer\_version  
rate\_card\_version  
zone\_version  
product\_rule\_version  
variation\_rule\_version

A global configuration update should normally increment an applicable version rather than attempt to individually purge every inherited product cache.

The effective-configuration cache key may include:

Product ID  
Variation ID  
Global config version  
Product rule version  
Variation rule version  
Delivery Offer/Rate Card version  
Destination/service zone  
Currency  
Relevant cart context  
---

# **15\. Bulk operations must respect inheritance**

Bulk editing must support:

Set override  
Clear override  
Reset to global  
Reset variation to parent  
Add offers  
Remove offers  
Replace offers  
Disable capability  
Preserve existing variation overrides  
Reset variation overrides

Bulk actions must never silently flatten global values into every product.

That would destroy inheritance.

For example, changing 1,000 products to “Use Global Processing Time” should store the inheritance state—not copy today's global value into all 1,000 product records.

---

# **16\. Import/export must preserve override semantics**

CSV/API representation must distinguish:

inherit  
override  
disable  
add  
remove  
replace

Example:

processing\_mode \= override  
processing\_min \= 5  
processing\_max \= 7

offers\_mode \= remove  
offer\_codes \= air\_express

supplier\_mode \= inherit

A blank field must not have ambiguous meaning.

---

# **17\. Native-first applies to the inheritance system too**

Do not reinvent native WooCommerce data simply because global overrides are being introduced.

Examples:

### **Product weight**

Use:

WooCommerce product/variation weight

Do not create:

delivery\_engine\_weight

unless a genuinely different logistics concept exists.

### **Dimensions**

Use WooCommerce dimensions.

### **Customer shipping address**

Use WooCommerce customer/order shipping address.

Do not create a second authoritative address record.

### **Currency**

Use WooCommerce base/checkout currency plus WCML when present.

### **Product SKU**

Use WooCommerce SKU.

### **Shipping class**

Use native Shipping Class where useful as one input.

Do not duplicate it under another name.

### **Logistics Profile**

Custom field remains justified because it represents much more than a native shipping class.

The principle is:

Reuse the native field whenever it means the same thing.

Create a custom field only when the business concept is genuinely different.  
---

# **18\. One authoritative owner for every piece of data**

The implementation must explicitly document data ownership.

Examples:

Product price  
Owner: WooCommerce

Product dimensions  
Owner: WooCommerce

Product stock  
Owner: WooCommerce

Customer address  
Owner: WooCommerce

Order  
Owner: WooCommerce

Payment  
Owner: WooCommerce/payment gateway

Fulfilment Availability  
Owner: Delivery Engine

Delivery Offer  
Owner: Delivery Engine

Supplier/origin  
Owner: Delivery Engine

Rate Card  
Owner: Delivery Engine

Detailed ETA calculation  
Owner: Delivery Engine

Shipment-specific operational state  
Owner: Delivery Engine / WooCommerce Fulfillments adapter where deliberately integrated

Do not maintain two competing authoritative values.

---

# **19\. WooCommerce Fulfillments remains adapter-based**

WooCommerce's native Fulfillments capability should be reused as much as practical when it is enabled and tested.

However, the plugin-specific shipment domain must remain behind an abstraction so a beta or evolving native feature is not hardwired throughout the application.

Recommended boundary:

ShipmentService  
        ↓  
FulfilmentRepositoryInterface  
        ↓  
Custom Shipment Repository  
or  
WooCommerce Fulfillments Adapter

The plugin must not duplicate native fulfilment information unnecessarily where WooCommerce can safely own it.

It must retain the custom operational information WooCommerce does not represent.

---

# **20\. Rate resolution needs explicit precedence**

The final pricing precedence should be deterministic.

For example:

Variation explicit price/rate override  
        ↓  
Product explicit price/rate override  
        ↓  
Most-specific applicable global Rate Card  
        ↓  
More-general global Rate Card  
        ↓  
Configured global fallback  
        ↓  
No valid price

`No valid price` must never silently become free shipping.

The customer must instead be prevented from selecting that offer.

---

# **21\. Eligibility resolution also needs deterministic precedence**

For eligible Delivery Offers:

Start with global applicable offers  
        ↓  
Apply Fulfilment Availability restrictions  
        ↓  
Apply destination restrictions  
        ↓  
Apply Logistics Profile restrictions  
        ↓  
Apply supplier/origin restrictions  
        ↓  
Apply product add/remove/replace rules  
        ↓  
Apply variation add/remove/replace rules  
        ↓  
Validate resulting set

This prevents arbitrary differences between product page, cart and checkout.

---

# **22\. Safety restrictions cannot be weakened casually by inheritance**

Not all settings should obey “most specific always wins.”

Safety and operational restrictions should combine conservatively.

Example:

Global:  
May consolidate

Logistics Profile:  
Must ship separately

Variation:  
May consolidate

Effective result:

Must ship separately

Likewise:

Global:  
Air allowed

Logistics Profile:  
Air prohibited because of restriction

Product:  
Air enabled

Effective result:

Air prohibited

Therefore the resolver must distinguish between:

ordinary preference overrides

and:

hard constraints

Hard safety or operational constraints always win.

---

# **23\. Global-only settings remain global-only**

The request for maximum global configuration with product/variation overrides does not mean *literally every technical setting* becomes product-specific.

These must remain global/system level:

* plugin activation;  
* database schema;  
* table definitions;  
* security model;  
* REST authentication;  
* nonce rules;  
* permissions/capabilities;  
* audit policy;  
* encryption;  
* HPOS compatibility;  
* integration detection;  
* migration logic;  
* background scheduler implementation;  
* plugin update mechanism;  
* emergency kill switch;  
* log-retention architecture;  
* cache backend;  
* data-retention/privacy policy;  
* private-data exposure rules.

Products and variations must never override platform security or integrity.

---

# **24\. Global configuration itself should be layered where useful**

“Global” may include reusable scopes rather than one huge flat settings object.

Recommended architecture:

Store-wide defaults  
        \+  
Fulfilment-state defaults  
        \+  
Delivery Offer defaults  
        \+  
Destination/service-zone rules  
        \+  
Rate Cards  
        \+  
Logistics Profile constraints  
        \+  
Carrier rules  
        ↓  
Product overrides  
        ↓  
Variation overrides

The Effective Configuration Resolver combines them into one final result.

This avoids building giant product-by-country-by-carrier configuration matrices.

---

# **25\. Final effective architecture**

The configuration and transaction flow is therefore:

WOOCOMMERCE NATIVE DATA  
Products  
Variations  
Weight  
Dimensions  
Shipping classes  
Customer/address  
Cart  
Currency  
Orders  
Taxes  
Payments  
        \+  
DELIVERY ENGINE GLOBAL CONFIGURATION  
Fulfilment defaults  
Delivery Offers  
Zones  
Rate Cards  
Profiles  
Suppliers/origins  
Carriers  
ETA rules  
Consolidation rules  
Presentation defaults  
        ↓  
PRODUCT OVERRIDES  
        ↓  
VARIATION OVERRIDES  
        ↓  
HARD CONSTRAINTS / DOMAIN VALIDATION  
        ↓  
EFFECTIVE CONFIGURATION RESOLVER  
        ↓  
DELIVERY PRICE \+ ETA \+ OFFER ELIGIBILITY  
        ↓  
PRODUCT PAGE  
        ↓  
CART-LINE DELIVERY SELECTION  
        ↓  
WOOCOMMERCE SHIPPING PACKAGES  
        ↓  
WOOCOMMERCE SHIPPING RATES  
        ↓  
CHECKOUT  
        ↓  
IMMUTABLE ORDER SNAPSHOT  
        ↓  
AUTOMATIC SHIPMENT/FULFILMENT CREATION  
        ↓  
MANUAL STATUS/TRACKING OPERATIONS  
        ↓  
CUSTOMER MY ACCOUNT / EMAILS  
---

# **26\. Final development principle**

Every developer and coding agent must follow this rule:

> **Configure globally once, inherit everywhere by default, override only where necessary, preserve hard restrictions, use WooCommerce core whenever it already solves the underlying problem, and snapshot the final resolved state when a transaction becomes historical.**

This prevents:

* thousands of duplicated product settings;  
* inconsistent variation configuration;  
* contradictory shipping rules;  
* hard-to-maintain catalogs;  
* global changes requiring manual edits to every product;  
* WooCommerce features being unnecessarily recreated;  
* historical orders changing after configuration updates.

The result should be a system in which the simple case is very simple:

Configure globally  
→ product inherits  
→ variation inherits  
→ done

while the exceptional case remains fully supported:

Global defaults  
→ Product overrides what differs  
→ Variation overrides what differs further  
→ all other fields continue inheriting

That inheritance model is now a foundational requirement of the Delivery & Fulfilment Engine, not a later enhancement.

This addendum should be treated together with the previously finalized delivery/fulfilment architecture as the current implementation specification.

Make the plugin is well designed and coded. So as not the affect the site negatively or/and degrade performance, seo, ux and ui. Also ensure that the ui and ux both for admin, staff, logged in and logout customers at every relevant page both in backend and frontend are great.  
Worked for 14s

Yes. This should be added as a **non-functional quality specification that is just as binding as the business requirements**. The plugin is not considered successful merely because its features work; it must also preserve or improve the site's performance, SEO, accessibility, stability, and usability.

# **Final Quality Addendum — Engineering, Performance, SEO, Accessibility, UI/UX, and Site-Safety Requirements**

## **1\. Fundamental quality rule**

The Delivery & Fulfilment Engine must be designed as a **good WooCommerce citizen**.

Its governing rule is:

> **Add the required delivery capabilities without making the underlying WooCommerce store slower, less reliable, harder to use, harder to maintain, less accessible, or worse for search engines.**

A feature is not complete merely because it technically works.

Every feature must satisfy:

Functional correctness  
\+  
Performance  
\+  
Security  
\+  
Reliability  
\+  
Accessibility  
\+  
SEO safety  
\+  
Admin usability  
\+  
Staff usability  
\+  
Customer usability  
\+  
Mobile usability  
\+  
Upgrade compatibility  
\+  
Maintainability

WooCommerce recommends extensions be tested in multi-plugin environments and built for interoperability rather than assuming an isolated installation.

---

# **2\. Native-first also means performance-first**

Continue applying the previously established rule:

Use WooCommerce core  
→ use supported WooCommerce extension APIs  
→ add the smallest custom capability necessary

Do not recreate:

* cart;  
* checkout;  
* order processing;  
* taxes;  
* shipping totals;  
* payments;  
* customer accounts;  
* addresses;  
* emails;  
* product records;  
* variation records;  
* refunds;  
* sessions;  
* logging;  
* permissions;

when WooCommerce already owns them.

This reduces code, compatibility problems, database duplication, performance overhead, and long-term maintenance.

---

# **3\. No unnecessary site-wide execution**

The plugin must not run expensive delivery calculations on every WordPress request.

For example, it should do virtually nothing beyond lightweight bootstrap/detection on:

* blog articles;  
* static pages;  
* unrelated admin pages;  
* login screens;  
* media pages;  
* frontend pages containing no managed products;  
* cron requests unrelated to the plugin;  
* unrelated REST requests.

Heavy modules should initialize only when required.

Example:

Single product page  
→ product delivery presentation module

Cart  
→ cart delivery module

Checkout  
→ checkout/rate module

Order page  
→ shipment module

Delivery Engine admin page  
→ admin module

Do not load the entire application unnecessarily on every request.

---

# **4\. Load CSS and JavaScript only where needed**

Do not globally enqueue Delivery Engine frontend assets across the whole website.

Frontend scripts/styles should normally load only on relevant surfaces such as:

* managed single-product pages;  
* cart;  
* checkout;  
* relevant My Account pages;  
* relevant tracking/order pages.

Admin assets should load only on:

* Delivery Engine admin pages;  
* WooCommerce product edit pages where Delivery Engine controls are required;  
* relevant order/shipment screens.

Do not load large admin React bundles on every WordPress admin page.

Do not load frontend CSS in wp-admin or admin CSS on the frontend.

Use WordPress/WooCommerce bundled libraries where appropriate instead of shipping duplicate framework/library copies. WordPress plugin guidance explicitly favors using WordPress-provided libraries rather than bundling duplicates.

---

# **5\. Keep frontend payloads small**

Do not send every:

* Delivery Offer;  
* supplier;  
* origin;  
* destination;  
* Rate Card;  
* Logistics Profile;  
* carrier;  
* currency rule;

to the browser.

For a product page, return only the information needed for that product and current context.

Example safe payload:

Product/variation  
Eligible offers  
Public prices  
Processing ranges  
Transit ranges  
Final ETA  
Public carrier labels  
Pickup information

Never expose private data merely because it is easier to send everything and filter it in JavaScript.

Server-side filtering is mandatory.

---

# **6\. Avoid N+1 database queries**

The plugin must be designed to batch-load related configuration.

Bad:

For each of 50 cart items:  
    query product rule  
    query Delivery Offer  
    query supplier  
    query origin  
    query Rate Card  
    query Logistics Profile

Better:

Collect all required IDs  
→ load records in batches  
→ resolve from request-local collections/cache

Repeated resolution within one request should reuse the same effective configuration results.

---

# **7\. Database design must support scale**

Custom tables must have appropriate indexes based on actual query patterns.

Likely indexed dimensions include:

* shipment ID;  
* order ID;  
* order-item ID;  
* product ID;  
* variation ID;  
* offer ID;  
* supplier ID;  
* origin ID;  
* zone ID;  
* shipment status;  
* created date;  
* updated date;  
* active status.

Avoid:

SELECT \* FROM huge\_table

when only a subset is required.

Admin lists must:

* paginate;  
* filter server-side;  
* search efficiently;  
* avoid loading thousands of records into browser memory.

Do not build dashboard counters by repeatedly scanning entire shipment tables.

Use purpose-designed indexed queries or safely cached aggregate counts.

---

# **8\. Use WooCommerce CRUD and HPOS correctly**

Order-related code must use WooCommerce order APIs and CRUD objects rather than querying historical order storage directly.

WooCommerce's HPOS guidance specifically recommends auditing extensions for direct database accesses and inappropriate assumptions about order storage.

The plugin must therefore remain compatible with:

* HPOS;  
* future WooCommerce datastore changes;  
* synchronized/compatibility modes where applicable.

The existing sites already use HPOS, so this is a release-blocking requirement.

---

# **9\. Cache only what is safe to cache**

Cache reusable configuration such as:

* global settings;  
* Delivery Offers;  
* Rate Cards;  
* Destination Zones;  
* Logistics Profiles;  
* carrier configuration;  
* resolved static inheritance where context permits.

Use versioned invalidation as previously specified.

Never globally cache customer-specific:

* cart contents;  
* shipping destination;  
* selected Delivery Offers;  
* checkout totals;  
* customer currency;  
* session-specific rate quotes.

A cached result must include every variable capable of changing that result.

---

# **10\. WP Rocket/Redis compatibility**

The plugin must coexist safely with:

* page caching;  
* Redis object caching;  
* WooCommerce session exclusions;  
* CDN/proxy caching.

Cart, checkout, customer account, and dynamic delivery requests must remain customer-specific.

Never let:

Customer A — Accra delivery price

be served to:

Customer B — Kumasi

because of an incorrectly cached endpoint or fragment.

Dynamic REST/AJAX responses must have appropriate cache semantics.

---

# **11\. Minimize AJAX and network chatter**

Do not make an AJAX request for every click, keystroke, or minor UI change.

Example:

When a user selects:

Red  
→ Large  
→ Air Shipping

do not unnecessarily issue multiple overlapping calculations if one final request can resolve the current state.

Use:

* debounce where appropriate;  
* request cancellation/stale-response protection;  
* request deduplication;  
* cached request-local/product configuration;  
* optimistic UI only where safe;  
* server authority for final price.

Address fields should not recalculate expensive shipping logic on every character typed.

---

# **12\. Prevent race conditions**

A customer may change:

* variation;  
* destination;  
* quantity;  
* fulfilment choice;  
* Delivery Offer;

quickly.

If Request A starts before Request B but finishes afterward, Request A must not overwrite the newer state.

Frontend logic needs:

request/version identifier  
or  
AbortController/cancellation  
or  
equivalent stale-response protection

The cart and checkout server remain authoritative.

---

# **13\. Do not introduce layout shifts**

Delivery information should have stable placement.

Avoid:

* suddenly pushing Add to Cart far down after asynchronous load;  
* buttons jumping position;  
* offer cards changing dimensions unpredictably;  
* loading overlays moving checkout fields.

Reserve appropriate space or use stable skeleton/loading states.

WooCommerce has explicitly invested in reducing layout shifts and unnecessary Cart/Checkout rendering as part of performance work, so the extension should follow the same direction.

---

# **14\. No unnecessary background jobs**

Version 1 is intentionally mostly event-driven.

Do not schedule recurring jobs for things that can happen synchronously at the correct event.

Use Action Scheduler for genuine asynchronous tasks such as:

* large imports;  
* migration batches;  
* optional indexing;  
* optional cleanup;  
* eventual future carrier synchronization.

Do not use it for:

* every cart recalculation;  
* every shipment view;  
* ordinary delivery selection;  
* routine status display.

This is especially important given the Action Scheduler issues already found on the target sites.

---

# **15\. Performance must be measured before release**

Every release must undergo performance regression testing.

Measure representative pages before and after enabling the plugin:

* homepage;  
* category/archive;  
* unmanaged product;  
* managed simple product;  
* managed variable product;  
* cart;  
* checkout;  
* My Account;  
* order view;  
* Delivery Engine admin dashboard;  
* shipment list;  
* product editor.

Measure:

* PHP execution time;  
* database query count;  
* slow queries;  
* memory consumption;  
* response size;  
* frontend JS size;  
* frontend CSS size;  
* REST/AJAX calls;  
* Core Web Vitals where applicable;  
* checkout calculation latency.

WordPress explicitly identifies Query Monitor as a developer tool for inspecting queries, hooks, HTTP requests, AJAX behavior, and other performance information.

A material unexplained regression is a release blocker.

---

# **16\. SEO must remain protected**

The plugin is principally transactional, not an SEO-content system.

It must therefore avoid unnecessary interference with:

* canonical URLs;  
* titles;  
* descriptions;  
* robots directives;  
* product schema;  
* breadcrumbs;  
* XML sitemaps;  
* product feeds;  
* Open Graph metadata;  
* product permalinks;  
* taxonomy URLs.

Do not create different indexable URLs for every:

Air  
Sea  
Carrier  
Pickup  
Destination  
Delivery Offer

unless there is a deliberate SEO strategy for doing so.

Delivery options are fulfilment choices, not separate products or SEO landing pages.

---

# **17\. Do not create duplicate product pages**

Never turn:

/product/laptop/

into separately indexable:

/product/laptop/?shipping=air  
/product/laptop/?shipping=sea  
/product/laptop/?carrier=dhl

versions that search engines could treat as independent content.

Delivery state may appear in browser/session state without producing SEO duplicates.

---

# **18\. Keep private logistics out of SEO surfaces**

Never expose:

* supplier;  
* private origin;  
* warehouse source;  
* internal carrier route;  
* internal cost;  
* margin;  
* consolidation key;  
* procurement notes;

through:

* JSON-LD;  
* structured data;  
* page source unnecessarily;  
* meta tags;  
* feeds;  
* XML sitemaps;  
* Open Graph;  
* public REST endpoints;  
* crawler-visible hidden HTML.

This is both a privacy and SEO cleanliness requirement.

---

# **19\. Preserve WooCommerce structured data ownership**

Do not recreate Product schema if WooCommerce/SEO tooling already outputs it.

The plugin may augment delivery-related information only where:

* standards support it;  
* the data is accurate;  
* it does not duplicate or conflict with existing schema;  
* the store explicitly enables it.

Otherwise, leave WooCommerce/Yoast/etc. as the authoritative structured-data provider.

---

# **20\. Dynamic delivery information must not damage crawlability**

Core product information must remain server-rendered and usable even if Delivery Engine JavaScript fails.

The plugin's dynamic delivery selector may rely on JavaScript for interaction, but:

* product title;  
* product description;  
* normal product price;  
* images;  
* existing product variations;  
* core product content;

must not become dependent on the Delivery Engine JavaScript.

A Delivery Engine frontend failure should affect delivery functionality—not destroy the product page.

---

# **21\. Customer shipment pages should not create SEO clutter**

Customer-specific order/shipment information belongs inside authenticated My Account surfaces.

Do not create publicly indexable shipment/order pages containing customer transaction information.

If future public tracking URLs are introduced, they must be intentionally designed with:

* unguessable identifiers;  
* limited exposed information;  
* appropriate robots/indexing policy;  
* rate limiting;  
* privacy safeguards.

---

# **22\. Admin UX is a first-class product surface**

The backend must not feel like a developer configuration dump.

Administrators and staff need a polished operational product.

Use:

* clear navigation;  
* human-readable terminology;  
* sensible defaults;  
* progressive disclosure;  
* search;  
* filters;  
* bulk operations;  
* inline validation;  
* previews;  
* contextual help;  
* breadcrumbs/back navigation where useful;  
* consistent buttons/actions;  
* confirmation for destructive changes.

WordPress's newer admin UI packages explicitly aim for cohesive and predictable page layouts, including standardized pages, navigation, sidebars and headers.

The plugin should visually respect WordPress/WooCommerce conventions instead of inventing an unrelated dashboard design.

---

# **23\. Do not hijack WordPress admin**

Do not:

* fill every admin screen with banners;  
* show persistent promotional notices;  
* create unrelated dashboard widgets;  
* display configuration errors to users who cannot fix them;  
* redirect administrators unexpectedly after updates.

WordPress plugin guidance specifically warns against hijacking the admin dashboard and excessive notices.

Notices should be:

Relevant  
Actionable  
Permission-aware  
Dismissible where appropriate  
---

# **24\. Progressive disclosure in admin**

Simple products should be easy to configure.

Example initial product panel:

Delivery & Fulfilment

Fulfilment:  
Use global setting

Eligible Offers:  
Use global setting

Logistics Profile:  
Use global setting

Advanced fields remain under:

Advanced Delivery Settings

Do not present 80 fields to a staff member who only needs to select:

International Fulfilment

The inheritance system makes this possible.

---

# **25\. Always show effective values**

Staff must not need to mentally calculate inheritance.

Example:

Processing time

● Inherit  
Effective value:  
3–5 business days

Source:  
Global → Air Economy

For a variation:

Effective value:  
6–8 business days

Source:  
Parent product override

This substantially improves operational usability and prevents configuration mistakes.

---

# **26\. Admin screens need great empty states**

Example:

Instead of:

No records found.

use:

No Delivery Offers have been created yet.

Delivery Offers define the services customers can choose,  
such as Air Shipping, Sea Shipping, or Same-Day Delivery.

\[Create Delivery Offer\]

Similarly for:

* Rate Cards;  
* Zones;  
* Suppliers;  
* Origins;  
* Shipments;  
* Pickup Locations.

Empty states should teach users what to do next.

---

# **27\. Admin errors must explain how to fix problems**

Bad:

Invalid configuration.

Good:

This product is set to International Fulfilment, but no Air or Sea Delivery Offer is currently available.

Add an eligible Delivery Offer or change the product's fulfilment setting.

Where possible, include:

\[Open Delivery Offers\]

Errors should identify:

* what is wrong;  
* why it matters;  
* how to fix it.

---

# **28\. Staff workflows must require minimal clicks**

Common operational actions should be fast.

For shipment staff:

Open shipment  
→ update status  
→ add tracking  
→ save

Do not force:

Order  
→ hidden submenu  
→ settings  
→ separate plugin record  
→ second page  
→ tracking page

for routine tasks.

Shipment lists should support quick filters such as:

* Awaiting fulfilment;  
* Processing;  
* Dispatched;  
* In transit;  
* Delayed;  
* Missing tracking.

---

# **29\. Bulk operations must be safe and pleasant**

Bulk editing is essential for large catalogs.

Provide:

* preview;  
* dry run where appropriate;  
* affected-record count;  
* validation;  
* progress state;  
* error report;  
* partial failure handling;  
* resumability for large jobs.

Never freeze an admin request while synchronously processing thousands of products.

Use batches/background processing for genuinely large operations.

---

# **30\. Logged-out customer UX must be excellent**

A guest customer must be able to understand delivery without needing an account.

They should be able to:

* select product variation;  
* see fulfilment availability;  
* select eligible Delivery Offer;  
* provide/select destination when necessary;  
* see shipping price;  
* see processing time;  
* see transit time;  
* see doorstep ETA;  
* Add to Cart;  
* maintain the selection through checkout.

Do not require login just to calculate ordinary delivery.

---

# **31\. Logged-in customer UX should be better, not different**

When a customer is logged in:

* reuse their WooCommerce shipping address when appropriate;  
* use saved destination context for preliminary prices;  
* still permit checkout address changes;  
* preserve cart selections;  
* show orders and shipment information through My Account.

Do not maintain a separate Delivery Engine customer profile when WooCommerce already owns the required data.

---

# **32\. Returning customers should not repeat unnecessary work**

Where safe:

Saved shipping destination  
→ preliminary delivery options automatically resolved

If the customer later changes address:

Revalidate  
→ explain any changed rate/ETA

Do not silently alter a material delivery selection.

---

# **33\. Product page UX must remain clean**

Do not overwhelm the product page.

Recommended hierarchy:

Product information  
↓  
Existing WooCommerce variation selection  
↓  
Delivery availability/status  
↓  
Eligible Delivery Offers  
↓  
Selected price \+ ETA  
↓  
Add to Cart

Do not present customers with:

* supplier information;  
* Rate Card terminology;  
* Logistics Profile terminology;  
* internal zone names;  
* configuration inheritance;  
* consolidation logic.

Those are operational concepts.

---

# **34\. Delivery cards should be decision-oriented**

Each customer-facing offer should communicate the important differences immediately.

Example:

○ Sea Shipping  
  GHS 350  
  Processing: 3–6 business days  
  Estimated delivery: 25–41 business days

● Air Shipping  
  GHS 900  
  Processing: 2–4 business days  
  Estimated delivery: 10–22 business days

Do not require customers to open multiple dialogs merely to compare price and time.

---

# **35\. Mobile product UX is mandatory**

Delivery functionality must be designed mobile-first.

On narrow screens:

* cards stack cleanly;  
* no horizontal scrolling;  
* prices remain visible;  
* radio controls have large touch targets;  
* Add to Cart remains easy to reach;  
* loading does not block the entire page unnecessarily;  
* text remains legible;  
* no hover-only information.

Test real widths and not merely desktop browser resizing.

---

# **36\. Mini-cart UX**

The mini-cart should show concise delivery information where useful:

Product A  
Sea Shipping  
GHS 350

Do not place detailed processing/transit tables inside a tiny mini-cart.

Provide detailed information in cart/checkout.

---

# **37\. Cart UX**

Cart items should clearly retain the choice made on the product page.

Example:

Delivery:  
Sea Shipping — GHS 350

Estimated delivery:  
25–41 business days

\[Change delivery option\]

Changing it should be possible where policy permits without forcing the customer to return to the product page.

---

# **38\. Checkout UX must minimize surprises**

Checkout must clearly show:

* shipment grouping;  
* products within each shipment;  
* chosen Delivery Offer;  
* shipping charge;  
* ETA;  
* pickup location where applicable.

If the checkout address changes the price:

Your delivery price changed from GHS 60 to GHS 85  
because the selected address is in a different delivery area.

Please review the updated total.

Never silently alter totals and expect the customer to notice.

---

# **39\. Mixed shipments must remain understandable**

Example:

Delivery 1  
Sea Shipping  
Product A  
GHS 350  
Estimated: 25–41 days

Delivery 2  
Air Shipping  
Product B  
GHS 900  
Estimated: 10–22 days

Pickup  
Product C  
Accra Branch  
Free

The customer should understand immediately that the order may arrive separately.

---

# **40\. My Account UX must be customer-oriented**

Do not expose internal shipment tables directly.

Use customer-friendly shipment cards:

Shipment 1

Air Shipping  
Status: In transit  
Estimated delivery: 12–20 August

Laptop  
Qty 1

\[Track shipment\]

No:

* supplier IDs;  
* origin codes;  
* internal shipment IDs unless customer-friendly;  
* internal cost;  
* private notes;  
* staff terminology.

---

# **41\. All important UI states must be deliberately designed**

Every component needs:

Initial state  
Loading state  
Success state  
Empty state  
Error state  
Disabled state  
Unavailable state  
Permission-denied state  
Offline/network-failure state where relevant

Do not leave default browser errors or blank spaces.

---

# **42\. Graceful degradation**

If a non-critical integration fails:

WPML  
WCML  
WoodMart adapter  
WCFM adapter  
VitePOS adapter

the plugin should isolate the failure where possible.

Examples:

WCML unavailable  
→ use WooCommerce base currency

WPML unavailable  
→ use site language

WoodMart adapter unavailable  
→ use standard WooCommerce presentation

A non-essential integration must not take the entire storefront down.

---

# **43\. Fail safely on transactional errors**

Different rule for shipping integrity:

If the plugin cannot reliably determine a required delivery charge:

Do not guess.  
Do not return GHS 0\.  
Do not silently ignore the error.

Instead:

Block the affected purchase  
\+  
show a customer-safe message  
\+  
log detailed staff diagnostics

Correctness is more important than completing a checkout with a wrong charge.

---

# **44\. Accessibility is mandatory**

All UI must aim to meet WordPress/WooCommerce accessibility expectations.

WordPress maintains explicit accessibility development guidance.

Requirements include:

* keyboard navigation;  
* visible focus;  
* proper labels;  
* semantic HTML;  
* radio groups correctly identified;  
* ARIA only where necessary;  
* live-region announcements for dynamic price/ETA changes;  
* meaningful error association;  
* sufficient contrast;  
* large touch targets;  
* no color-only communication;  
* screen-reader-friendly tables/forms;  
* reduced-motion respect where animation exists.

---

# **45\. Dynamic changes need assistive announcements**

Example:

Customer selects Air.

Visible UI:

Air Shipping selected  
GHS 900  
Estimated delivery: 10–22 business days

Screen reader should also receive a meaningful announcement such as:

Air Shipping selected.  
Delivery price GHS 900\.  
Estimated delivery 10 to 22 business days.

Do not rely solely on visual changes.

---

# **46\. Security is part of UX and performance**

Validate server-side.

Never trust:

* price;  
* Delivery Offer;  
* carrier;  
* supplier;  
* origin;  
* destination zone;  
* currency conversion;  
* eligibility;

merely because the browser submitted it.

WordPress recommends explicit validation, sanitization, nonces, capability checks, and output escaping.

WordPress capabilities should control privileged operations.

---

# **47\. Permission-aware admin UX**

A customer-service agent should not see controls they cannot use.

A Product Manager should not be shown private supplier cost controls if they lack permission.

A WCFM vendor should not see global Rate Cards.

A user lacking permission should see either:

* read-only information appropriate to their role; or  
* no control at all.

Do not show dozens of disabled admin buttons as the primary permission strategy.

---

# **48\. Code architecture must remain maintainable**

Required principles:

Small focused classes/modules  
Clear domain boundaries  
Dependency injection/service registration  
Interfaces around replaceable integrations  
No giant God class  
No giant functions.php implementation  
No business logic in templates  
No duplicated calculation logic  
No duplicated inheritance logic  
No duplicated pricing logic

Presentation code calls application/domain services.

It does not calculate business rules itself.

---

# **49\. JavaScript must not become the source of truth**

Frontend JavaScript handles:

* interaction;  
* display;  
* immediate UX;  
* sending selected identifiers;  
* rendering returned safe results.

PHP/server-side application logic owns:

* eligibility;  
* rate calculation;  
* validation;  
* final totals;  
* order snapshots;  
* shipment creation.

A customer modifying JavaScript or HTTP requests cannot change authoritative business outcomes.

---

# **50\. CSS must be isolated**

Use namespaced classes such as:

delivery-engine-\*

or equivalent.

Avoid dangerous selectors such as:

button { ... }  
input { ... }  
.woocommerce div { ... }

that unexpectedly restyle the entire website.

Use:

* scoped selectors;  
* CSS custom properties;  
* theme-compatible defaults;  
* minimal specificity;  
* responsive layouts.

Do not use `!important` widely to fight WoodMart.

---

# **51\. JavaScript must also be isolated**

Do not:

* pollute `window` with many globals;  
* monkey-patch WooCommerce core;  
* replace WoodMart functions;  
* overwrite jQuery plugins;  
* intercept unrelated AJAX calls.

Use supported events and APIs.

Cart and Checkout Blocks have dedicated extensibility mechanisms; WooCommerce documents them separately from classic checkout.

---

# **52\. Backward/forward compatibility must be deliberate**

The plugin should maintain a compatibility matrix covering:

* WordPress;  
* WooCommerce;  
* PHP;  
* WoodMart;  
* WPML;  
* WCML;  
* WCFM;  
* VitePOS;  
* HPOS;  
* classic checkout;  
* Blocks when supported.

WooCommerce recommends testing extensions across multi-plugin environments rather than assuming they operate alone.

---

# **53\. Testing must cover UI as well as calculations**

Automated tests should include:

Unit  
Integration  
WooCommerce  
REST/API  
Permissions  
Database migrations  
E2E/browser  
Visual regression where practical  
Accessibility  
Performance regression

Browser tests need both:

Logged out customer  
Logged in customer

plus:

Administrator  
Shop Manager  
Logistics staff  
Customer support  
Product staff  
Vendor where relevant  
---

# **54\. Test real site configurations**

Do not test only on an empty Storefront installation.

Maintain representative staging scenarios using:

WoodMart  
WoodMart Child  
WP Rocket  
Redis  
HPOS  
Variable products  
WPML/WCML deployment  
Non-WPML deployment  
WCFM deployment  
VitePOS where applicable  
Real payment gateways in sandbox/test mode

WooCommerce's HPOS guidance specifically recommends testing critical checkout flows and refunds with every relevant payment method before rollout.

---

# **55\. Performance and UX are release gates**

A release should not ship if it:

* significantly slows product pages;  
* adds excessive queries;  
* causes checkout delays;  
* creates layout shifts;  
* breaks Quick View;  
* breaks variation selection;  
* causes duplicate shipping methods;  
* creates mobile overflow;  
* introduces accessibility regressions;  
* exposes private metadata;  
* creates duplicate indexable URLs;  
* adds visible JS errors;  
* creates PHP warnings/notices;  
* produces significant log spam;  
* causes cache/session leakage.

The development process must treat these as defects, not cosmetic backlog.

---

# **56\. Diagnostics should help without harming users**

Admin diagnostics may show:

* integration versions;  
* HPOS status;  
* missing Rate Cards;  
* invalid product rules;  
* cache warnings;  
* template compatibility;  
* migration status;  
* recent plugin errors.

Customer-facing pages must not expose stack traces or configuration details.

Production logs should contain enough information to debug without unnecessarily storing sensitive customer data.

---

# **57\. Feature flags and emergency controls**

Major components should have controlled enablement.

Examples:

Product delivery selector  
Shipment generation  
My Account shipment UI  
WPML integration  
WCML integration  
WCFM adapter  
VitePOS adapter  
Blocks adapter

Provide an emergency global disable mechanism capable of stopping Delivery Engine-controlled checkout behavior safely without deleting configuration or shipment history.

This improves incident recovery.

---

# **58\. Updates must be non-destructive**

Plugin updates must preserve:

* settings;  
* inheritance;  
* Rate Cards;  
* product overrides;  
* variation overrides;  
* shipment records;  
* order snapshots;  
* audit history.

Database migrations must be:

* versioned;  
* idempotent;  
* retry-safe;  
* testable;  
* batchable where large.

Never make administrators recreate configuration after normal updates.

---

# **59\. Uninstallation must be deliberate**

Deactivation:

Never delete business data.

Uninstallation:

By default, do not automatically destroy historical shipment/order data.

If a permanent-data-removal option exists, require explicit administrator action and clear warnings.

Historical WooCommerce order snapshots should remain understandable even if the plugin is later removed.

---

# **60\. Overall UI/UX standard**

Every Delivery Engine interface should feel:

Clear  
Fast  
Predictable  
Responsive  
Accessible  
Consistent  
Forgiving  
Professional  
Context-aware  
Permission-aware

Administrators should feel that they are using a mature WooCommerce operational tool.

Customers should feel that delivery is simply an integrated part of buying the product—not that they have entered a separate logistics application.

---

# **61\. Final engineering quality doctrine**

The development team and AI coding agents must follow:

Correctness before cleverness

Native WooCommerce before custom reinvention

Global inheritance before duplicated configuration

Server authority before frontend trust

Indexed queries before table scans

Batch loading before N+1 queries

Contextual loading before global assets

Caching before repeated expensive computation  
—but never unsafe customer-specific caching

Progressive disclosure before giant forms

Clear customer language before logistics jargon

Accessible interaction before visual-only interaction

Responsive design before desktop-only assumptions

Graceful integration failure before whole-site failure

Staging validation before production changes

Performance measurement before declaring success

## **Definition of Done**

The plugin is not complete merely when:

Air/Sea selection works.

It is complete when:

The feature works correctly  
AND  
WooCommerce remains authoritative  
AND  
checkout remains reliable  
AND  
the website remains performant  
AND  
SEO remains clean  
AND  
mobile works well  
AND  
accessibility works  
AND  
admins can configure it easily  
AND  
staff can operate it efficiently  
AND  
logged-out customers understand it  
AND  
logged-in customers get a better experience  
AND  
private information remains private  
AND  
upgrades remain safe  
AND  
the site's other important functionality is not degraded.

This quality standard applies to **every existing and future module of the Delivery & Fulfilment Engine** and is a release-blocking requirement, not optional polish.

This should now sit alongside the business rules, native-first policy, and global → product → variation inheritance model as one of the project's foundational specifications.

In as much as the site that it is mainly for and being built on (with login credential and cli and api acess to ai coding agents) is using woodmart xtemos woocommerce theme, it should be able to work fine with any other woocomerce theme on other sites too.

Yes. This should be added as a **hard portability requirement**: WoodMart is the primary integration target, but the plugin must never become a WoodMart-dependent plugin.

# **Final Addendum — Theme Independence and WoodMart Compatibility**

## **1\. Core rule**

The Delivery & Fulfilment Engine is a **WooCommerce plugin**, not a WoodMart plugin.

The main development and production site currently uses:

WordPress  
→ WooCommerce  
→ WoodMart by XTemos  
→ WoodMart Child Theme

and AI coding agents may have direct CLI, API, filesystem, WordPress, WooCommerce, and administrative access to that development environment.

That environment should be used extensively for:

* development;  
* integration testing;  
* real product testing;  
* variation testing;  
* Quick View testing;  
* AJAX cart testing;  
* cart/checkout testing;  
* responsive testing;  
* production-like performance testing.

However:

> **No core business capability of the Delivery Engine may depend on WoodMart being installed.**

The plugin must work correctly with other properly coded WooCommerce themes.

---

# **2\. Architectural separation**

The architecture must be:

Delivery Engine Core  
        ↓  
WooCommerce Integration Layer  
        ↓  
Generic WooCommerce Presentation  
        ↓  
Optional Theme Compatibility Adapters  
            ├── WoodMart Adapter  
            ├── Storefront Adapter if ever needed  
            ├── Block Theme Adapter if needed  
            └── Other theme-specific adapters only when necessary

WoodMart-specific code must never be mixed into:

* rate calculation;  
* fulfilment rules;  
* inheritance;  
* destination resolution;  
* shipment grouping;  
* ETA calculation;  
* order creation;  
* shipping package calculation;  
* shipment records;  
* pricing;  
* security.

Those belong to theme-independent core services.

---

# **3\. Standard WooCommerce hooks come first**

Whenever possible, frontend integration must use standard WooCommerce hooks, filters, APIs, templates, events, and extension points.

Examples include appropriate hooks around:

* variation forms;  
* Add to Cart;  
* cart item data;  
* cart display;  
* checkout;  
* order details;  
* My Account;  
* emails.

Theme-specific hooks should be used only when standard WooCommerce hooks cannot provide the required experience.

The priority is:

Standard WooCommerce API/hook  
        ↓  
Generic fallback implementation  
        ↓  
Theme-specific adapter only where necessary

Never reverse this order.

---

# **4\. WoodMart is an enhancement layer**

The WoodMart adapter may improve integration with features such as:

* variation swatches;  
* Quick View;  
* Quick Shop;  
* AJAX Add to Cart;  
* Buy Now;  
* sticky Add to Cart;  
* mini-cart;  
* WoodMart product layouts;  
* theme-specific responsive behavior;  
* WoodMart frontend styles.

But these integrations are enhancements.

If WoodMart is not installed:

WoodMart Adapter  
→ does not load

Delivery Engine  
→ continues working using standard WooCommerce behavior

No fatal error, broken selector, missing rate calculation, or checkout failure should result from WoodMart being absent.

---

# **5\. Theme detection must be isolated**

Theme detection should occur through a dedicated compatibility layer.

For example:

ThemeCompatibilityManager

It may determine:

Active theme  
Parent theme  
Known theme capabilities  
Available hooks  
Known frontend behaviors

Then register only the relevant adapter.

Example:

if WoodMart detected:  
    register WoodMartCompatibilityAdapter  
else:  
    register GenericWooCommerceAdapter

The core application should not contain scattered checks such as:

if ( $theme \=== 'woodmart' ) { ... }

throughout dozens of files.

---

# **6\. Generic WooCommerce UI must exist**

The plugin needs its own clean, theme-neutral customer interface that works independently of theme-specific markup.

The generic delivery selector should use:

* semantic HTML;  
* properly labelled radio buttons;  
* accessible controls;  
* minimal scoped CSS;  
* standard WooCommerce-compatible markup;  
* responsive layouts.

For example:

Delivery Only

Choose delivery option

○ Sea Shipping  
  GHS 350  
  Processing: 3–6 business days  
  Estimated delivery: 25–41 business days

● Air Shipping  
  GHS 900  
  Processing: 2–4 business days  
  Estimated delivery: 10–22 business days

This must render acceptably even on a completely different WooCommerce theme.

---

# **7\. Do not copy WoodMart design into the core plugin**

The generic UI should not hardcode:

* WoodMart button classes;  
* WoodMart grid classes;  
* WoodMart typography classes;  
* WoodMart CSS variables;  
* XTemos-specific DOM structure;  
* WoodMart icons;  
* WoodMart JavaScript globals.

The WoodMart adapter can optionally map generic components to WoodMart-compatible styling.

For example:

Generic Delivery Card  
        ↓  
WoodMart adapter adds compatible classes/style adjustments

The underlying HTML behavior remains valid without the adapter.

---

# **8\. CSS must be theme-safe**

Use scoped CSS.

Example naming:

.wc-delivery-engine  
.wc-delivery-engine\_\_offer  
.wc-delivery-engine\_\_price  
.wc-delivery-engine\_\_eta

or an equivalent unique prefix.

Avoid broad selectors such as:

button {}  
input {}  
label {}  
.woocommerce div {}  
.product form {}

that could alter unrelated theme elements.

Use theme-neutral defaults and inherit where sensible:

font-family: inherit;

Avoid hardcoded global typography that fights the active theme.

Do not rely heavily on `!important`.

---

# **9\. Allow theme styling naturally**

The plugin should inherit sensible aspects of the active theme:

* typography;  
* text colors;  
* spacing context;  
* button appearance where appropriate;  
* form-control appearance where safe.

At the same time, critical functional elements must remain usable even if the theme provides poor defaults.

The goal is:

Looks native to the current store  
without depending on that store's theme.  
---

# **10\. JavaScript must be theme-independent**

Core frontend JavaScript should interact with WooCommerce state and the plugin's own DOM.

It must not require:

WoodMart JavaScript objects  
XTemos private APIs  
theme-specific AJAX implementation  
theme-specific variation engine

for basic operation.

The WoodMart adapter can listen for or bridge WoodMart-specific events where needed.

Core JS must remain usable with ordinary WooCommerce variation events and APIs.

---

# **11\. Theme-specific behavior must be encapsulated**

Example:

Core VariationIntegrationService

    GenericWooCommerceVariationAdapter  
    WoodMartVariationAdapter

WoodMart-specific responsibilities may include:

* detecting Quick View modal lifecycle;  
* reacting to WoodMart swatch refreshes;  
* initializing the delivery selector after AJAX-loaded product content;  
* reinitializing after WoodMart replaces DOM fragments.

Those concerns must not enter the Rate Engine or Product Rule Resolver.

---

# **12\. Quick View must degrade safely**

Some WooCommerce themes have:

* Quick View;  
* Quick Shop;  
* popup product views;  
* AJAX product cards.

Others do not.

The plugin should support a capability-based approach.

If Quick View can safely support delivery selection:

initialize Delivery Engine selector

If a theme's Quick View cannot provide enough product/destination context:

require customer to open full product page before Add to Cart

Do not allow a Quick View implementation to bypass a mandatory delivery selection.

Safety is more important than preserving a theme shortcut.

---

# **13\. AJAX Add to Cart must never bypass fulfilment validation**

Themes commonly implement custom AJAX Add to Cart.

Regardless of theme:

customer submits Add to Cart  
        ↓  
server-side WooCommerce validation  
        ↓  
Delivery Engine validates required delivery selection  
        ↓  
only valid item enters cart

Theme JavaScript must never be the only enforcement mechanism.

If a theme bypasses expected WooCommerce frontend behavior, the plugin should still reject the invalid server request.

---

# **14\. Buy Now compatibility**

Themes may provide custom:

Buy Now  
Buy It Now  
Instant Checkout  
Quick Buy

buttons.

For a Delivery Engine-managed product, these shortcuts may proceed only if:

* required variation is selected;  
* required fulfilment choice is resolved;  
* valid Delivery Offer exists;  
* customer selection exists where required;  
* destination/rate requirements are satisfied.

Otherwise the shortcut must:

* open the required selection UI; or  
* send the customer through the standard product flow.

Never bypass fulfilment validation merely to retain a theme feature.

---

# **15\. Mini-cart portability**

WooCommerce themes may render mini-cart content differently.

Core order/cart data should contain enough customer-safe delivery metadata that any compatible mini-cart can retrieve it.

The plugin may provide:

Generic mini-cart delivery summary

and adapters may refine positioning.

Example:

Laptop  
Qty 1

Sea Shipping  
GHS 350

The core cart item itself—not WoodMart DOM state—must own the selected Delivery Offer.

---

# **16\. Checkout independence**

Checkout functionality must be completely theme-independent.

The calculation chain:

Cart item  
→ effective configuration  
→ shipment package  
→ WC\_Shipping\_Rate  
→ WooCommerce totals

must work irrespective of the active frontend theme.

A theme may change how checkout looks.

It must not determine:

* delivery eligibility;  
* rate;  
* package grouping;  
* fulfilment choice;  
* order snapshot.

---

# **17\. My Account independence**

Shipment/customer delivery data must integrate using WooCommerce account/order extension points.

The plugin may render generic shipment cards.

Themes may style the surrounding My Account page, but the underlying shipment information must not rely on WoodMart templates.

---

# **18\. Email independence**

Delivery and shipment information in transactional emails must use WooCommerce email hooks/templates.

It must not depend on frontend theme templates.

The same order should have valid delivery information even if:

* the active theme changes;  
* WoodMart is removed;  
* Storefront is activated temporarily.

---

# **19\. Admin UI must also be theme-independent**

WordPress admin is not controlled by the storefront theme.

All Delivery Engine administration must therefore be built against:

* WordPress admin conventions;  
* WooCommerce admin conventions where appropriate.

Do not use WoodMart admin UI libraries as dependencies.

Admin screens must remain unchanged when the storefront theme changes.

---

# **20\. Theme change survival is an acceptance requirement**

One explicit compatibility test should be:

Configure Delivery Engine under WoodMart  
→ place test order  
→ switch staging site to another WooCommerce-compatible theme  
→ repeat product/cart/checkout/My Account tests

Expected result:

Business logic unchanged  
Rates unchanged  
Cart selections unchanged  
Orders valid  
Shipment creation valid  
Customer UI functional

Only visual presentation may differ slightly according to the theme.

---

# **21\. Test with at least one neutral WooCommerce theme**

WoodMart should receive the deepest compatibility testing because it is the primary production environment.

But every release should also be tested against at least one neutral/reference WooCommerce environment.

For example:

Primary:  
WoodMart \+ WoodMart Child

Reference:  
A standard WooCommerce-compatible theme

Also test a modern block-theme environment when Blocks support becomes officially supported by the plugin.

This catches accidental WoodMart coupling.

---

# **22\. Theme compatibility tiers**

The plugin may formally define:

## **Tier 1 — Primary certified**

WoodMart

Deep testing includes:

* swatches;  
* Quick View;  
* AJAX Add to Cart;  
* mini-cart;  
* Buy Now;  
* mobile;  
* sticky cart;  
* product layouts.

## **Tier 2 — Generic WooCommerce compatible**

Any correctly implemented WooCommerce theme using standard hooks/APIs should receive:

* product delivery selector;  
* variations;  
* Add to Cart validation;  
* cart;  
* shipping;  
* checkout;  
* order pages;  
* My Account;  
* emails.

## **Tier 3 — Explicit adapters**

If another major theme needs special handling, add an adapter without modifying the core.

Examples might eventually include:

Flatsome  
Astra  
Kadence  
Block themes  
other heavily customized WooCommerce themes

Only add these when actual compatibility work is required.

---

# **23\. Do not maintain theme forks**

There should not be:

Delivery Engine for WoodMart  
Delivery Engine for Theme B  
Delivery Engine for Theme C

The correct model is:

One Delivery Engine  
\+  
optional compatibility adapters

This ensures:

* one codebase;  
* one business-rule engine;  
* one migration path;  
* one security model;  
* one testing framework;  
* easier maintenance.

---

# **24\. No parent-theme modifications**

Never edit:

/wp-content/themes/woodmart/

or another theme's parent files to make Delivery Engine work.

If a rare template compatibility override becomes unavoidable:

1. prefer plugin hooks first;  
2. use an adapter second;  
3. only then consider a child-theme override;  
4. document it explicitly;  
5. test it independently;  
6. ensure the plugin still has a generic fallback.

A child-theme override must not become a hidden hard dependency.

---

# **25\. Theme update resilience**

A WoodMart update should ideally require no Delivery Engine code change.

The plugin should depend primarily on stable WooCommerce APIs.

Theme adapters should:

* feature-detect where practical;  
* avoid private/internal theme APIs when alternatives exist;  
* fail gracefully;  
* be isolated and replaceable.

If a theme update breaks only a compatibility enhancement:

disable/fallback the adapter enhancement  
→ retain generic WooCommerce functionality

Checkout and pricing must continue operating safely.

---

# **26\. Development access must not create architectural coupling**

AI coding agents may have extensive access to the primary WoodMart site through:

* login credentials;  
* WP-CLI;  
* SSH/CLI;  
* REST APIs;  
* filesystem;  
* database inspection;  
* browser automation;  
* WordPress admin.

That access should be used to understand and test the real environment thoroughly.

But agents must not infer:

This site's current HTML structure  
\= universal WooCommerce architecture

They must distinguish:

WooCommerce core behavior  
vs  
WoodMart behavior  
vs  
site-specific customizations

before implementing any integration.

---

# **27\. Compatibility diagnostics**

The plugin's diagnostics page should report:

Active theme  
Parent theme  
Theme version  
Detected theme adapter  
Generic WooCommerce fallback status  
Known compatibility warnings  
WooCommerce template override warnings  
Classic/Blocks checkout mode

Example:

Theme:  
WoodMart Child

Parent:  
WoodMart 8.x

Compatibility:  
WoodMart adapter active

Generic WooCommerce fallback:  
Available

On another site:

Theme:  
Example Theme

Known adapter:  
None

Compatibility mode:  
Standard WooCommerce  
---

# **28\. Final portability acceptance criteria**

The plugin cannot be declared production-quality until all of the following are true:

Core Delivery Engine has no WoodMart dependency.

Plugin activates without WoodMart.

Generic product delivery UI renders without WoodMart.

Simple products work on another WooCommerce theme.

Variable products work on another WooCommerce theme.

Delivery selection survives Add to Cart.

Shipping packages calculate correctly.

WooCommerce shipping rates calculate correctly.

Mixed Air/Sea carts work.

Store Pickup works.

Checkout works.

Orders and snapshots work.

Automatic shipment creation works.

My Account shipment display works.

Emails work.

Admin UI works identically.

Removing/changing WoodMart does not corrupt Delivery Engine data.

No WoodMart class/function is referenced by core modules.

WoodMart-specific code lives only inside its compatibility adapter.

Theme-specific failures cannot silently alter prices or fulfilment rules.  
---

# **29\. Final doctrine**

The implementation rule is:

> **Develop deeply against WoodMart because it is the primary real-world environment, but architect against WooCommerce because WooCommerce—not WoodMart—is the platform dependency.**

In simplified form:

WooCommerce  
\= platform contract

Delivery Engine  
\= business/domain extension

WoodMart  
\= primary presentation integration

Other WooCommerce themes  
\= supported through the same core

Therefore the target is not merely:

Works perfectly on WoodMart

It is:

Works perfectly on WooCommerce

\+  
is exceptionally well integrated and tested on WoodMart

\+  
gracefully works with other correctly implemented WooCommerce themes.

This theme-independence requirement is now a release-blocking part of the Delivery & Fulfilment Engine specification.

This is the right boundary: **WoodMart gets first-class compatibility, but never architectural ownership of the plugin.**

