# Generate Stage 12C narration scripts + VTT stubs (run once from repo root).
# Training docs only — no plugin runtime impact.

from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
SCRIPTS = ROOT / "docs" / "training" / "video-scripts"
VTT = ROOT / "docs" / "training" / "assets" / "videos" / "transcripts"
SCRIPTS.mkdir(parents=True, exist_ok=True)
VTT.mkdir(parents=True, exist_ok=True)

VIDEOS = [
    {
        "id": "01",
        "slug": "getting-started-overview",
        "title": "Getting started overview",
        "audience": "New staff, trainers",
        "objective": "Find Delivery Engine in WordPress, know Delivery Settings as the everyday home, and name Default / Product / Variation, Preview, supporting config pages, and order Delivery information — without technical jargon.",
        "duration": "5–8 minutes",
        "related": "00-START-HERE.md, 01-QUICK-START.md, 05-STAFF-TRAINING-MANUAL.md Module 1–2",
        "practice": "Open Delivery Engine → Delivery Settings and Preview; do not edit Legacy Rules.",
        "scenes": [
            {
                "n": 1,
                "name": "Title / purpose",
                "visible": "WordPress admin desktop; Delivery Engine menu visible in the left sidebar.",
                "narration": "Welcome to the CETECH Delivery Engine staff walkthrough for release candidate 1.0.0-rc.2. In this short overview you will learn where everyday delivery work happens, and what customers and staff each see.",
                "action": "Pause on wp-admin home or Delivery Engine dashboard for several seconds.",
                "teach": "This is operational training, not developer architecture.",
            },
            {
                "n": 2,
                "name": "Open Delivery Engine",
                "visible": "Left menu: Delivery Engine.",
                "narration": "In WordPress admin, open Delivery Engine. This is the home for delivery configuration for your store.",
                "action": "Click Delivery Engine; show the dashboard briefly.",
                "teach": "Staff start from Delivery Engine, not random WooCommerce screens.",
            },
            {
                "n": 3,
                "name": "Delivery Settings is everyday",
                "visible": "Delivery Settings with tabs Default / Product-Specific / Variation-Specific.",
                "narration": "Your everyday starting point is Delivery Settings. Most day-to-day work happens here — not in Legacy Delivery Rules.",
                "action": "Open Delivery Settings; hover or pause on the three tabs.",
                "teach": "Delivery Settings = normal current workflow.",
            },
            {
                "n": 4,
                "name": "Three levels",
                "visible": "Default Settings tab content.",
                "narration": "Think of three layers. Default Settings apply store-wide. Product-Specific Settings can change one product. Variation-Specific Settings can change one variation. Prefer inheritance unless something truly needs to differ.",
                "action": "Click through Default, then Product, then Variation tabs without saving changes.",
                "teach": "Default → Product → Variation.",
            },
            {
                "n": 5,
                "name": "Preview",
                "visible": "Delivery Settings Preview page.",
                "narration": "Delivery Settings Preview is read-only. It shows what will actually apply for a product or variation, including Ready or Needs configuration.",
                "action": "Open Preview; pause on Ready / Needs configuration language if visible.",
                "teach": "Always Preview after important changes.",
            },
            {
                "n": 6,
                "name": "Offers, zones, rate cards",
                "visible": "Delivery Offers, Destination Zones, and Rate Cards list screens.",
                "narration": "At a high level: Delivery Offers are the choices customers see. Destination Zones describe where a choice applies. Rate Cards connect an offer and zone to a delivery charge. You configure products in Delivery Settings; these pages support that setup.",
                "action": "Open each page briefly, read-only.",
                "teach": "Supporting config pages work together with Delivery Settings.",
            },
            {
                "n": 7,
                "name": "Order Delivery information",
                "visible": "Existing QA order with Delivery information panel (blur personal fields).",
                "narration": "After a customer pays, staff open the order and find Delivery information — fulfilment, delivery method, delivery option, estimated delivery, and charge. You do not need technical metadata for normal work.",
                "action": "Open QA order #39721 or #39724; focus Delivery information; redact PII.",
                "teach": "Orders keep what the customer paid for; do not rewrite history casually.",
            },
            {
                "n": 8,
                "name": "Close",
                "visible": "Back on Delivery Settings home.",
                "narration": "Remember: Delivery Settings for everyday work, Preview to confirm, and Delivery information on orders after purchase. Next videos go deeper into each step.",
                "action": "Return to Delivery Settings; end card / pause.",
                "teach": "Path for new staff is clear and repeatable.",
            },
        ],
    },
    {
        "id": "02",
        "slug": "default-delivery-settings",
        "title": "Default delivery settings",
        "audience": "Staff who configure store-wide defaults",
        "objective": "Open Default Settings, explain inheritance, review fulfilment and delivery choices, save safely, and confirm with Preview.",
        "duration": "4–6 minutes",
        "related": "02-COMPLETE-ADMIN-GUIDE.md, 05 Module 3",
        "practice": "Read Default Settings on the training site without changing live catalogue policy unless authorised.",
        "scenes": [
            {
                "n": 1,
                "name": "Open Default Settings",
                "visible": "Delivery Settings → Default Settings.",
                "narration": "Default Settings are the store-wide values products can inherit. When a product has no special override, it follows these defaults.",
                "action": "Open Delivery Settings, Default tab; pause.",
                "teach": "Defaults reduce repetitive product edits.",
            },
            {
                "n": 2,
                "name": "What defaults mean",
                "visible": "Fulfilment availability / fulfilment choice fields.",
                "narration": "Here you set fulfilment availability and how fulfilment is chosen. These labels should match what your store actually offers customers.",
                "action": "Slow scroll; highlight fulfilment-related fields without editing unless authorised.",
                "teach": "Use clear operational language — Fulfilment, not internal jargon.",
            },
            {
                "n": 3,
                "name": "Delivery method and offers",
                "visible": "Delivery method / delivery offers controls.",
                "narration": "Defaults also cover delivery method and which delivery offers products can use. Incomplete offers or pricing must never silently become free shipping.",
                "action": "Show offer-related controls; pause.",
                "teach": "Missing configuration is not free shipping.",
            },
            {
                "n": 4,
                "name": "Recommended workflow",
                "visible": "Save control and link toward Preview.",
                "narration": "Recommended workflow: set clear defaults, save, then open Delivery Settings Preview for a sample product. Prefer fixing defaults instead of overriding every product.",
                "action": "Point to Save; do not save experimental changes on production without approval.",
                "teach": "Save then Preview.",
            },
            {
                "n": 5,
                "name": "Preview result",
                "visible": "Preview for a QA product.",
                "narration": "In Preview, confirm the product is Currently using Default when no product override exists, and that status is Ready when configuration is complete.",
                "action": "Open Preview for QA #39705 if available.",
                "teach": "Preview proves inheritance.",
            },
        ],
    },
    {
        "id": "03",
        "slug": "configure-simple-product",
        "title": "Configure a simple product",
        "audience": "Everyday staff",
        "objective": "Configure simple QA product #39705 using inheritance or a deliberate product override, then verify Preview and the customer Delivery options.",
        "duration": "5–8 minutes",
        "related": "03-USE-CASE-PLAYBOOK.md cases 1–2, 05 Module 4",
        "practice": "On #39705 only: open Product-Specific Settings, prefer Use inherited setting, Preview, then check storefront Delivery options.",
        "scenes": [
            {
                "n": 1,
                "name": "Find product settings",
                "visible": "Product-Specific Settings; product ID 39705.",
                "narration": "We will practise on the dedicated simple QA product, number 39705. Never practise by changing a real customer catalogue product.",
                "action": "Open Product-Specific Settings for #39705.",
                "teach": "QA products only for training.",
            },
            {
                "n": 2,
                "name": "Inherit defaults",
                "visible": "Fields set to Use inherited setting.",
                "narration": "For most fields, leave Use inherited setting. That means this simple product follows Default Settings.",
                "action": "Scroll fields; highlight inherited modes.",
                "teach": "Inheritance is the default good practice.",
            },
            {
                "n": 3,
                "name": "Optional product-specific value",
                "visible": "One field with Set a different value here (demo only if authorised).",
                "narration": "Only when this product truly needs something different, choose Set a different value here. Otherwise keep inheritance.",
                "action": "Show the mode control; avoid permanent production changes.",
                "teach": "Overrides must be intentional.",
            },
            {
                "n": 4,
                "name": "Save and Preview",
                "visible": "Save, then Preview Ready.",
                "narration": "Save when you have made an authorised change. Then open Preview and confirm Ready, or understand any Needs configuration message.",
                "action": "Save only if trainer-authorised; open Preview.",
                "teach": "Preview after Save.",
            },
            {
                "n": 5,
                "name": "Customer-facing result",
                "visible": "Storefront product page Delivery options for #39705.",
                "narration": "On the shop product page, customers see Delivery options in plain language. Staff configure in admin; customers choose on the product.",
                "action": "Open QA simple product storefront; focus Delivery options; no payment.",
                "teach": "Customer journey starts on the product page.",
            },
        ],
    },
    {
        "id": "04",
        "slug": "configure-variable-product",
        "title": "Configure a variable product",
        "audience": "Everyday staff",
        "objective": "Configure variable parent #39717 and variations #39718 / #39719, verify Preview, and confirm the storefront selector after a variation is chosen.",
        "duration": "7–10 minutes",
        "related": "03-USE-CASE-PLAYBOOK.md variable cases, 05 Module 5",
        "practice": "Open parent #39717 and variations #39718/#39719 read-only; Preview each; open storefront and select a variation.",
        "scenes": [
            {
                "n": 1,
                "name": "Parent settings",
                "visible": "Product-Specific Settings for #39717.",
                "narration": "Variable products have a parent and variations. Start with parent product 39717. Parent settings apply when a variation inherits.",
                "action": "Open product settings for #39717.",
                "teach": "Parent first, then variations.",
            },
            {
                "n": 2,
                "name": "Variation A inherit",
                "visible": "Variation-Specific Settings for #39718.",
                "narration": "Open Variation A, number 39718. When fields say Use inherited setting, this variation follows the parent — or Default through the parent.",
                "action": "Open #39718; highlight inherited fields.",
                "teach": "Inherited variation follows the chain.",
            },
            {
                "n": 3,
                "name": "Variation B override path",
                "visible": "Variation-Specific Settings for #39719.",
                "narration": "Variation B, number 39719, is useful to demonstrate an override. Only set a different value when this variation must differ.",
                "action": "Open #39719; show override vs inherit modes without breaking live QA unless restoring.",
                "teach": "Override only the variation that needs it.",
            },
            {
                "n": 4,
                "name": "Save and Preview",
                "visible": "Preview for parent and a variation.",
                "narration": "Save authorised changes, then Preview for the exact variation ID you care about. Confirm Currently using and Ready.",
                "action": "Open Preview; select variation IDs carefully.",
                "teach": "Wrong ID = wrong Preview.",
            },
            {
                "n": 5,
                "name": "Storefront selector",
                "visible": "Variable product page; variation chosen; Delivery options.",
                "narration": "On the storefront, the customer must select a variation. Delivery options then refresh for that variation. Do not complete payment just for this video.",
                "action": "Select a variation; show Delivery options; stop before payment.",
                "teach": "Variation selection drives delivery choices.",
            },
        ],
    },
    {
        "id": "05",
        "slug": "variation-inheritance-overrides",
        "title": "Variation inheritance and overrides",
        "audience": "Staff and trainers",
        "objective": "Explain Default → Product → Variation and the modes Use inherited setting, Set a different value here, and Turn off, with real UI examples including offer collections where applicable.",
        "duration": "6–9 minutes",
        "related": "01-QUICK-START.md inheritance table, 05 Module 3",
        "practice": "On #39705 and #39718, identify each mode label without saving unwanted changes.",
        "scenes": [
            {
                "n": 1,
                "name": "The chain",
                "visible": "Diagram-like walk: Default tab → Product → Variation.",
                "narration": "Inheritance always runs Default, then Product, then Variation. The most specific intentional value wins.",
                "action": "Click through the three tabs in order.",
                "teach": "Variation wins when it sets a value.",
            },
            {
                "n": 2,
                "name": "Use inherited setting",
                "visible": "Mode control set to Use inherited setting.",
                "narration": "Use inherited setting means keep the value from the level above. This is the safest everyday choice.",
                "action": "Highlight the label on a QA field.",
                "teach": "Inherit by default.",
            },
            {
                "n": 3,
                "name": "Set a different value here",
                "visible": "Mode Set a different value here with an editor.",
                "narration": "Set a different value here replaces the inherited scalar for this product or variation only.",
                "action": "Show the mode without leaving a bad permanent override.",
                "teach": "Override is explicit.",
            },
            {
                "n": 4,
                "name": "Turn off",
                "visible": "Turn off mode where supported.",
                "narration": "Turn off means intentionally none for this item where the field supports it. It is not the same as forgetting to configure.",
                "action": "Show Turn off if present; explain verbally if not on screen.",
                "teach": "Turn off is intentional absence.",
            },
            {
                "n": 5,
                "name": "Collections",
                "visible": "Delivery offers collection controls (add / remove / replace) if present.",
                "narration": "For delivery offers, you may add, remove, or replace inherited options depending on the controls your screen shows. Empty lists are not always inherit — read the labels carefully.",
                "action": "Hover collection controls on QA product.",
                "teach": "Collections have explicit semantics — do not guess.",
            },
        ],
    },
    {
        "id": "06",
        "slug": "delivery-settings-preview",
        "title": "Delivery Settings Preview",
        "audience": "Everyday staff",
        "objective": "Use Preview to read Ready, Needs configuration, and Currently using — and know what to do when configuration is incomplete without breaking live production settings for the camera.",
        "duration": "4–6 minutes",
        "related": "04-VISUAL-WALKTHROUGH.md Preview section, 07-TROUBLESHOOTING-FAQ.md",
        "practice": "Preview QA #39705 and one variation; write down Currently using.",
        "scenes": [
            {
                "n": 1,
                "name": "Open Preview",
                "visible": "Delivery Settings Preview.",
                "narration": "Preview is read-only. Use it after changes, and whenever a customer or colleague reports a delivery problem.",
                "action": "Open Preview.",
                "teach": "Preview before escalating.",
            },
            {
                "n": 2,
                "name": "Ready",
                "visible": "Ready status on a complete QA example.",
                "narration": "Ready means required delivery values are present for this view. Staff can expect the customer journey to have a fair chance of showing options.",
                "action": "Show Ready on QA fixture.",
                "teach": "Ready is the goal state.",
            },
            {
                "n": 3,
                "name": "Needs configuration",
                "visible": "Needs configuration language if a safe QA example exists; otherwise explain with documentation wording on screen.",
                "narration": "Needs configuration means something required is missing or incomplete. Do not invent free shipping. Fix Default or the correct product or variation layer, then Preview again. Do not break live production configuration just to film this state.",
                "action": "Use existing QA-safe incomplete example or on-screen FAQ text — never sabotage production.",
                "teach": "Incomplete ≠ free.",
            },
            {
                "n": 4,
                "name": "Currently using",
                "visible": "Currently using Default / Product / Variation.",
                "narration": "Currently using tells you which level is supplying the effective values. If inheritance looks wrong, fix the level Preview points to.",
                "action": "Highlight Currently using.",
                "teach": "Fix the level that is actually in force.",
            },
        ],
    },
    {
        "id": "07",
        "slug": "customer-cart-checkout",
        "title": "Customer product, cart, and checkout",
        "audience": "Staff who support the customer journey",
        "objective": "Walk product → delivery selection → cart → reload → checkout Delivery charge using RC.2 labels, without placing a payment.",
        "duration": "6–9 minutes",
        "related": "05 Modules 8–9, 04 storefront sections",
        "practice": "On QA #39705: select a delivery option, open cart, refresh, open checkout, confirm Delivery charge line; do not pay.",
        "scenes": [
            {
                "n": 1,
                "name": "Product page",
                "visible": "QA simple product with Delivery options.",
                "narration": "The customer starts on the product page. They choose a delivery option before adding to cart when the store requires it.",
                "action": "Open #39705 storefront; show Delivery options.",
                "teach": "Selection happens on the product.",
            },
            {
                "n": 2,
                "name": "RC.2 labels",
                "visible": "Fulfilment / Delivery method / Delivery option / Estimated delivery where shown.",
                "narration": "Release candidate labels use Fulfilment, Delivery method, Delivery option, and Estimated delivery. Teach these words — not internal field names.",
                "action": "Pause on each visible label.",
                "teach": "Operational language only.",
            },
            {
                "n": 3,
                "name": "Cart and reload",
                "visible": "Cart with retained delivery choice.",
                "narration": "After adding to cart, the delivery choice should remain. Reload the cart to show that the selection is remembered for the session.",
                "action": "Add to cart; open cart; reload once.",
                "teach": "Cart keeps the choice.",
            },
            {
                "n": 4,
                "name": "Checkout Delivery charge",
                "visible": "Checkout with Delivery shipping line.",
                "narration": "At checkout, the customer should see a Delivery charge that matches their selection and destination. Stop before payment. No order creation is required for this video.",
                "action": "Open checkout; highlight Delivery charge; do not pay.",
                "teach": "Server-calculated charge — never invent a fee in the browser.",
            },
        ],
    },
    {
        "id": "08",
        "slug": "multi-product-shipping",
        "title": "Multi-product shipping",
        "audience": "Staff handling multi-line carts",
        "objective": "Show two compatible QA products sharing one delivery charge (expected 25.00 fixed-per-shipment in the verified QA story) and explain that incompatible fulfilment paths are separated.",
        "duration": "5–8 minutes",
        "related": "05 Module on multi-product / Stage 8 notes in FAQ, order #39724",
        "practice": "Build a cart with two compatible QA products; confirm one shared delivery charge; do not place an order.",
        "scenes": [
            {
                "n": 1,
                "name": "Two compatible products",
                "visible": "Cart with two QA lines that can travel together.",
                "narration": "When two products can travel together, they can share one delivery charge. We use safe QA products only.",
                "action": "Add two compatible QA products with delivery selected.",
                "teach": "Compatible products can group.",
            },
            {
                "n": 2,
                "name": "One shared charge",
                "visible": "Cart or checkout showing a single 25.00 delivery charge when that is the QA expectation.",
                "narration": "These products can travel together, so they share one delivery charge. In the verified QA story that shared charge is twenty-five point zero zero.",
                "action": "Highlight the single Delivery charge.",
                "teach": "Shared shipment → one charge in this QA case.",
            },
            {
                "n": 3,
                "name": "Incompatible paths",
                "visible": "Optional second illustration or verbal explanation if a safe incompatible pair is not on screen.",
                "narration": "If fulfilment paths are incompatible, the store separates them. Customers may see more than one delivery charge. That is expected when items cannot travel together.",
                "action": "Explain clearly; do not create unnecessary orders.",
                "teach": "Separation protects correct charging.",
            },
        ],
    },
    {
        "id": "09",
        "slug": "order-delivery-information",
        "title": "Order Delivery information",
        "audience": "Everyday staff",
        "objective": "Read Delivery information on existing QA orders #39721 or #39724 and know which fields matter for normal work.",
        "duration": "4–6 minutes",
        "related": "04 order section, 05 Module 10",
        "practice": "Open #39721 or #39724 read-only; list Fulfilment, Delivery method, Delivery option, Estimated delivery, charge, status.",
        "scenes": [
            {
                "n": 1,
                "name": "Open existing QA order",
                "visible": "WooCommerce order edit for #39721 or #39724; personal fields blurred.",
                "narration": "Use an existing QA order. Do not create a new paid order only for filming. Open Delivery information on the order screen.",
                "action": "Open order; redact PII; focus Delivery information.",
                "teach": "Read-only QA orders preferred.",
            },
            {
                "n": 2,
                "name": "Operational fields",
                "visible": "Product, Fulfilment, Delivery method, Delivery option, Estimated delivery, Delivery charge, Status.",
                "narration": "Normal staff need Product, Fulfilment, Delivery method, Delivery option, Estimated delivery, Delivery charge, and Status. These describe what the customer bought and how delivery was recorded.",
                "action": "Pause on each field.",
                "teach": "Operational fields only.",
            },
            {
                "n": 3,
                "name": "No technical overload",
                "visible": "Order screen without emphasising technical meta.",
                "narration": "Normal staff do not need technical metadata. If you only see confusing technical details, stop and ask an administrator or technical support.",
                "action": "Avoid expanding advanced diagnostics.",
                "teach": "Escalate technical detail — do not guess.",
            },
        ],
    },
    {
        "id": "10",
        "slug": "staff-troubleshooting",
        "title": "Staff troubleshooting",
        "audience": "Everyday staff",
        "objective": "Work through common delivery problems safely and know when to escalate — without SSH, SQL, PHP, Redis flush, Nginx, or Code Snippets.",
        "duration": "7–10 minutes",
        "related": "07-TROUBLESHOOTING-FAQ.md",
        "practice": "Pick one FAQ scenario and walk Preview → Delivery Settings → escalate rule.",
        "scenes": [
            {
                "n": 1,
                "name": "Needs configuration",
                "visible": "Preview or FAQ on screen.",
                "narration": "If Preview says Needs configuration, open Delivery Settings for the same product or variation, complete missing values, prefer inheritance when the parent is correct, save, and Preview again.",
                "action": "Show Preview and Delivery Settings side path.",
                "teach": "Fix config, do not invent fees.",
            },
            {
                "n": 2,
                "name": "No delivery choice / wrong inheritance",
                "visible": "Product page or Preview.",
                "narration": "If no delivery choice shows, confirm the correct product or variation, Preview Ready, and that offers are assigned. If the wrong inherited setting appears, change the level Preview says is Currently using.",
                "action": "Narrate checklist; optional live QA screens.",
                "teach": "Correct level, then Preview.",
            },
            {
                "n": 3,
                "name": "Charges and multi-product surprises",
                "visible": "Cart/checkout or order totals.",
                "narration": "No delivery charge usually means missing selection, zone, or rate card — escalate to an administrator if the selection is present but the fee is missing or unexpectedly zero. Unexpected multi-product charges may mean products cannot travel together.",
                "action": "Point at Delivery charge area on a safe screen.",
                "teach": "Zero fee is a warning, not a shortcut.",
            },
            {
                "n": 4,
                "name": "Missing order Delivery information",
                "visible": "Order screen.",
                "narration": "If Delivery information is missing on an order, confirm you are on a Delivery Engine order from the enabled period, then escalate. Do not edit historical delivery details to fix future settings.",
                "action": "Show where the panel should appear on a good QA order.",
                "teach": "History is immutable for normal staff.",
            },
            {
                "n": 5,
                "name": "When to stop",
                "visible": "End card listing escalate rules.",
                "narration": "Stop and ask an administrator or technical support when Preview and Delivery Settings look complete but the shop still fails, or when you are asked to use SSH, SQL, PHP, Redis flush, Nginx, or Code Snippets. Those are not normal staff tools.",
                "action": "Hold on escalate message.",
                "teach": "Escalate technical work.",
            },
        ],
    },
    {
        "id": "11",
        "slug": "legacy-rules-explained",
        "title": "Legacy Delivery Rules explained",
        "audience": "All staff (boundary training)",
        "objective": "Treat Delivery Settings as the normal workflow and Legacy Delivery Rules as older compatibility or migration only.",
        "duration": "3–5 minutes",
        "related": "05 Module 12, 00-START-HERE surface map",
        "practice": "Open Legacy Delivery Rules once with a trainer, read any warning copy, leave without editing.",
        "scenes": [
            {
                "n": 1,
                "name": "Normal workflow",
                "visible": "Delivery Settings.",
                "narration": "Delivery Settings is the normal current workflow for RC.2. That is where everyday staff work.",
                "action": "Show Delivery Settings home.",
                "teach": "One everyday system.",
            },
            {
                "n": 2,
                "name": "Legacy area",
                "visible": "Legacy Delivery Rules screen and any warning/copy.",
                "narration": "Legacy Delivery Rules is an older compatibility and migration area. It is not a second everyday delivery system. Normal staff should not edit it unless an administrator authorises a migration task.",
                "action": "Open Legacy Rules; highlight warning if present; do not edit.",
                "teach": "Legacy = leave alone unless authorised.",
            },
            {
                "n": 3,
                "name": "Boundary",
                "visible": "Return to Delivery Settings.",
                "narration": "If you are unsure which screen to use, choose Delivery Settings and ask. Do not maintain the same rule in two places.",
                "action": "Navigate back to Delivery Settings.",
                "teach": "No dual systems for daily work.",
            },
        ],
    },
    {
        "id": "12",
        "slug": "complete-staff-walkthrough",
        "title": "Complete staff walkthrough",
        "audience": "New staff onboarding",
        "objective": "Watch one end-to-end onboarding path from overview through customer journey, orders, troubleshooting boundaries, and Legacy Rules — suitable to watch beginning to end.",
        "duration": "15–25 minutes",
        "related": "05-STAFF-TRAINING-MANUAL.md (all modules), 10-VIDEO-TRAINING-LIBRARY.md",
        "practice": "After watching, complete Module 1 practical test and one simple-product Preview on #39705.",
        "scenes": [
            {
                "n": 1,
                "name": "Overview",
                "visible": "Delivery Engine dashboard.",
                "narration": "This complete walkthrough is your full onboarding video for the CETECH Delivery Engine on RC.2. We will move slowly from admin configuration to the customer journey and back to the order.",
                "action": "Title pause on dashboard.",
                "teach": "Full path in one sitting.",
            },
            {
                "n": 2,
                "name": "Delivery Settings and Defaults",
                "visible": "Delivery Settings Default tab.",
                "narration": "Open Delivery Settings. Defaults are the store-wide baseline. Prefer strong defaults over dozens of one-off product edits.",
                "action": "Open Default Settings; pause on key fields.",
                "teach": "Defaults first.",
            },
            {
                "n": 3,
                "name": "Simple product",
                "visible": "Product settings #39705.",
                "narration": "On simple QA product 39705, prefer Use inherited setting. Override only when needed. Save authorised changes.",
                "action": "Show #39705 product settings.",
                "teach": "Simple product path.",
            },
            {
                "n": 4,
                "name": "Variable product and override",
                "visible": "#39717 parent; #39718 inherit; #39719 override discussion.",
                "narration": "For variable products, configure the parent, then each variation. Variation 39718 can demonstrate inheritance; 39719 can demonstrate an intentional override.",
                "action": "Open parent and both variations briefly.",
                "teach": "Parent then variation.",
            },
            {
                "n": 5,
                "name": "Preview",
                "visible": "Preview Ready / Currently using.",
                "narration": "Always Preview. Confirm Ready and Currently using before you trust the shop page.",
                "action": "Open Preview.",
                "teach": "Preview is mandatory habit.",
            },
            {
                "n": 6,
                "name": "Customer selection, cart, checkout",
                "visible": "Product → cart → checkout Delivery charge.",
                "narration": "Customer selects delivery on the product, cart keeps it, checkout shows the Delivery charge. We stop before payment.",
                "action": "Walk storefront path without paying.",
                "teach": "No payment required for training video.",
            },
            {
                "n": 7,
                "name": "Multi-product delivery",
                "visible": "Multi-line cart with shared charge when compatible.",
                "narration": "Compatible products can share one delivery charge. Incompatible fulfilment paths are separated. Explain simply to customers when more than one charge appears.",
                "action": "Show multi-product cart if safe.",
                "teach": "Grouping vs separation.",
            },
            {
                "n": 8,
                "name": "Order Delivery information",
                "visible": "QA order #39721 or #39724.",
                "narration": "On the order, read Delivery information using operational labels. Do not rewrite historical delivery details to fix future settings.",
                "action": "Open QA order; blur PII.",
                "teach": "Immutable history.",
            },
            {
                "n": 9,
                "name": "Troubleshooting and Legacy",
                "visible": "FAQ headlines; Legacy Rules warning.",
                "narration": "For problems, use Preview and Delivery Settings first. Escalate technical work. Legacy Delivery Rules are not everyday. Never change Settings switches, suppliers and origins, or Legacy Rules without approval.",
                "action": "Show escalate boundaries; open Legacy briefly; return to Delivery Settings.",
                "teach": "Boundaries protect the store.",
            },
            {
                "n": 10,
                "name": "Close",
                "visible": "Delivery Settings home.",
                "narration": "You now have the full staff path. Practise on QA products, watch the shorter topic videos as needed, and ask a trainer to sign off your practical tests.",
                "action": "End on Delivery Settings.",
                "teach": "Read + watch + practise.",
            },
        ],
    },
]


def scene_block(s):
    return f"""## Scene {s['n']} — {s['name']}

**What is visible:** {s['visible']}

**Narration:** {s['narration']}

**Action being performed:** {s['action']}

**Key teaching point:** {s['teach']}
"""


def transcript(scenes):
    return "\n\n".join(f"[Scene {s['n']}] {s['narration']}" for s in scenes)


def chapters(scenes, minutes_hint):
    # Even chapter placeholders until final recording timestamps exist.
    lines = ["00:00 Title / start"]
    for i, s in enumerate(scenes, start=1):
        lines.append(f"(planned) Scene {s['n']}: {s['name']}")
    lines.append(f"(planned total) {minutes_hint}")
    return "\n".join(f"- {x}" for x in lines)


def vtt_body(scenes):
    # Approximate cue windows for trainer import; refine after final cut.
    cues = ["WEBVTT", ""]
    t = 0
    for s in scenes:
        start = t
        end = t + 20
        def ts(sec):
            h = sec // 3600
            m = (sec % 3600) // 60
            s2 = sec % 60
            return f"{h:02d}:{m:02d}:{s2:02d}.000"
        cues.append(str(s["n"]))
        cues.append(f"{ts(start)} --> {ts(end)}")
        cues.append(s["narration"])
        cues.append("")
        t = end
    return "\n".join(cues)


def main():
    index_rows = []
    for v in VIDEOS:
        fname = f"{v['id']}-{v['slug']}"
        script_path = SCRIPTS / f"{fname}.md"
        body = f"""# VIDEO TITLE: {v['title']}

**Canonical file:** `{fname}.webm` (optional `{fname}.mp4`)  
**Audience:** {v['audience']}  
**Learning objective:** {v['objective']}  
**Estimated duration:** {v['duration']}  
**Capture method (fill after recording):** PLAYWRIGHT / HUMAN / MIXED — _pending_  
**Related written guide:** {v['related']}  
**Practice exercise:** {v['practice']}  
**QA fixtures:** products `#39705`, `#39717` / `#39718` / `#39719`; orders `#39721` / `#39724` (read-only preferred)

---

{chr(10).join(scene_block(s) for s in v['scenes'])}

---

## Full transcript

{transcript(v['scenes'])}

---

## Chapter timestamps

Refine after final recording:

{chapters(v['scenes'], v['duration'])}

---

## Privacy notes for this video

- Use QA data only; blur customer PII on order screens.
- Never show passwords, cookies, nonces, payment details, or auth state.
- Prefer read-only walks; restore any temporary QA config changes.
"""
        script_path.write_text(body, encoding="utf-8")
        vtt_path = VTT / f"{fname}.vtt"
        vtt_path.write_text(vtt_body(v["scenes"]), encoding="utf-8")
        index_rows.append(v)
        print("wrote", script_path.name, "and", vtt_path.name)

    # Human shot-list companion
    shot = SCRIPTS / "HUMAN-RECORDING-SHOT-LIST.md"
    shot.write_text(
        """# Human recording shot list (Stage 12C)

Use when Playwright video capture is blocked (for example by Cloudflare). Follow the same scene lists as `01`–`12` narration scripts.

## Setup

1. Clean desktop; hide personal notifications.
2. Browser zoom consistent; target readable WordPress UI (≈1080p capture where feasible).
3. Log in manually; do not record password entry if avoidable (cut that segment).
4. Prefer QA products `#39705`, `#39717`–`#39719` and orders `#39721` / `#39724`.
5. Narrate live or record voice later from the matching `video-scripts/*.md` transcript.

## Per-video checklist

For each canonical filename in `docs/training/assets/videos/README.md`:

- [ ] Follow scenes in order from the matching script
- [ ] Deliberate pauses before important clicks
- [ ] No private data on screen
- [ ] Label capture method **HUMAN** (or **MIXED**) in `10-VIDEO-TRAINING-LIBRARY.md`
- [ ] Export `.webm` or `.mp4` to local/Release/Drive per storage policy — do not auto-bloat Git

## Privacy gate before distribution

Review the full cut once. Reject any take that shows credentials, PII, payment data, or secrets.
""",
        encoding="utf-8",
    )
    print("wrote", shot.name)


if __name__ == "__main__":
    main()
