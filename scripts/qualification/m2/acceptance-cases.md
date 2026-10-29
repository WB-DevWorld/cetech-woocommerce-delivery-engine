# M2 acceptance ledger

| Field | Value |
|---|---|
| Package under test | `1.0.0-m2.N` (fill in) |
| ZIP SHA-256 | (fill in from the release-package run) |
| Packaged commit | (fill in) |
| Site | training.cetechbpa.com on a restore of the business database, then the business site after the maintenance-window install |
| Part A receipt | `m2-automated-evidence.json` from `run-automated-evidence.sh` at the packaged commit |
| Part B receipt | `m2-site-preflight.json` from `m2-site-preflight.php` on the site |
| Tester | Claude (Cowork), signed in as `claude-qa` |
| Signatories | Frank; named management representative |

Every row ends as PASS or FAIL with evidence. Evidence is a screenshot or receipt
name that contains no real customer data. Where a row says "automated", Part A
proves the rule and the browser row confirms the visible result.

Rate cards the customer cases assume (D9): Greater Accra Selected Cities / Standard
₵30, Accra / Standard ₵50, Accra / Store Pickup ₵0. Durations, never dates (M3 owns
promised dates).

## Customer cases

| Case | Covers | Setup | Steps | Expected | Automated evidence | Result | Evidence |
|---|---|---|---|---|---|---|---|
| C1 | Delivery order, COD and Paystack test | A simple product with Delivery; `claude-qa` address in Accra | Choose Delivery on the product page, enter Accra, add to cart, check out with COD; repeat with Paystack test | One rate (Accra / Standard ₵50) on cart and checkout; the same duration on product page, cart, checkout, thank-you and both customer emails; order has the delivery summary; a shipment exists after payment (Paystack) or sits in the COD queue (COD) | `C1-durations`, `C1-email-wording`, `S3-S4-shipment-atomicity` | | |
| C2 | Pickup order GHS 0 with location recorded | A product with an assigned active pickup location | Choose Store Pickup, add to cart, check out | Cart and checkout show Store Pickup at ₵0 with the location name; the order summary and email name the location; no delivery shipment is created | `C2-pickup-source-independent` | | |
| C3 | Mixed cart | One Delivery line, one Pickup line | Add both, check out | Two packages: Delivery to Accra with its rate, Store Pickup at ₵0; the checkout plan lists both; order and emails show both | `C9-compact-line` | | |
| C4 | Outside coverage refused | A location with no rate card (not Greater Accra) | Choose Delivery, enter that location | "Delivery is not available to this location"; add-to-cart refused; no silent fallback to a native method | — | | |
| C5 | Variable product | A variable product with Delivery on its variations | Pick a variation, choose Delivery, change the variation | Options load per variation; the earlier Delivery/Pickup choice survives the variation change; the compact line names the chosen variation's option | `C8-pdp-gate` | | |
| C6 | WoodMart mobile usable | Phone viewport (375 px) | Walk C1 on the phone layout | Switch, cards, compact line and Change are reachable without horizontal scroll; the location fields stack | — | | |
| C7 | Two-line cart totals equal (D1) | Two products in the same group; one with quantity 2 | Add A then B; clear; add B then A; change A's quantity | Both add orders give the same total; the quantity change keeps one package and one charge | `C7-mixed-origin-totals` | | |
| C8 | PDP gate behaviour (D2) | Products with: both choices; Delivery only; Pickup only | Open each product page | Both valid: nothing pre-selected, both panels hidden until a choice; one valid: it is pre-selected and the other shows its reason ("Store pickup is not available for this product" / "Delivery is not available for this product") | `C8-pdp-gate` | | |
| C9 | Compact line with Change (D2) | Product with both choices | Choose Delivery, enter Accra, pick a card; then Change; then choose Pickup | One line "Standard Delivery · Accra · 3–6 business days · ₵50.00" with one Change; Change reopens the chooser; the same line appears on the cart line and the checkout plan, each with one Change; Pickup reads "Store Pickup — <location> · Free" | `C9-compact-line` | | |
| C10 | Pickup location deactivated → choice invalidated with notice | A Pickup line in the cart | Staff deactivate that location; reload the cart | The line is marked for reselection with the notice; the old location is not silently replaced; the summary is hidden until the customer chooses again | `C10-pickup-endpoint-invalidation` | | |
| C11 | Mixed managed/native cart keeps the Woo address | Managed Delivery line to Accra plus a product without a delivery option (native shipping) | Enter Kumasi as the checkout shipping address; place the order | The managed package ships to Accra; the native package and the order's shipping address stay Kumasi; billing untouched; the note "Items without a delivery option ship to your checkout shipping address" shows | `C11-mixed-cart-native-address` | | |

### Customer cases added from the correction registers (the intake list fell short here)

| Case | Covers | Setup | Steps | Expected | Automated evidence | Result | Evidence |
|---|---|---|---|---|---|---|---|
| C12 | COR-025 coherent reselection (Classic and Blocks) | A Delivery line with a complete address | In the cart change the offer; change Delivery to Pickup; change Pickup location 1 to 2 | After each change the line summary, the package, the total and the order snapshot agree; the address survives the offer change; the Pickup change moves the endpoint in one step | `C12-coherent-reselection` | | |
| C13 | COR-024 replaced location (not only deactivated) | A product whose only pickup location is replaced by another; a product with two locations where one is removed | Reload the cart after each staff change | Both cases require reselection; the newly sole location is never substituted; a label or readiness edit on the same location refreshes without reselection | `C10-pickup-endpoint-invalidation` | | |
| C14 | COR-034 order routes and email wording | `claude-qa` order; a second test customer; a guest order | Open the order as the owner, as the other customer, as a guest with and without the order key; read the HTML and plain-text emails in the `claude-qa` mailbox | Only the owner, staff or the guest with the right key see the delivery summary and shipment cards; both email formats show Delivery or Store Pickup wording from the stored facts | `C14-order-surface-authority`, `C1-email-wording` | | |

## Staff cases

| Case | Covers | Setup | Steps | Expected | Automated evidence | Result | Evidence |
|---|---|---|---|---|---|---|---|
| S1 | Area, option, charge, pickup CRUD and test tools | Admin | Create, edit, deactivate an area, an option, a rate card and a pickup location; use the rate tester | Each save persists and lists; the tester quotes the expected card; deactivation does not delete history | — | | |
| S2 | Save & Apply saves; validation error keeps input | Delivery Settings | Save & Apply a valid form; submit an invalid form | Valid: saved and the wizard opens; invalid: the typed values are kept with the warning | `S2-save-and-apply` | | |
| S3 | Paid → shipment; COD → queue → create | C1 orders | Pay the Paystack order; open Needs Attention for the COD order; create its shipment | The paid order has one complete shipment with a Created event; the COD order sits in the queue until staff create, then leaves it | `S3-S4-shipment-atomicity` | | |
| S4 | Status/tracking atomic and visible to customer | A shipment | Change status, add tracking, update the current ETA; reload the customer's View Order | Each change shows with its history event; the original ETA is unchanged; the customer sees the status, tracking and updated estimate | `S3-S4-shipment-atomicity` | | |
| S5 | Snapshot immutable after a rate edit | A placed order | Change the rate card amount; reopen the order and resend the email | The order's delivery charge, option and duration are unchanged | — | | |
| S6 | Needs Attention badge equals the queue; no caps | Several open items | Compare the badge count with the page sections | The badge equals the sum of the sections; a section with more rows than its page says "Showing the first N of M" | `S6-attention-truthful` | | |
| S7 | Upgrade preserves config/orders; rollback rehearsed | The restored database | Install the package in place; inspect; roll back with training-ops; reinstall | Settings, areas, options, rate cards, pickup locations, orders and shipments survive the upgrade and the rollback; the Settings page shows the version and schema 6 | `S7-version-identity` | | |
| S8 | Unauthorised shop manager cannot bulk-rollback | A Shop Manager without the site-wide capability | Open Bulk Tools and try a rollback | The action is not offered or is refused | — | | |
| S9 | CSV export/import round-trip with omission report (D4) | Products with scoped configuration | Export; import the same file; import a file with a bad row | The import restores the same instructions; the bad row is reported with its line and skipped | `S9-csv-round-trip` | | |
| S10 | Inherited/readiness display honest | A product with an inactive scope and one with an invalid list | Open the product editor and the Delivery Settings Preview | The source reads "inactive and ignored"; the invalid list needs an explicit Replace with its reasons shown | `S10-inherited-readiness` | | |

### Staff cases added from the correction registers

| Case | Covers | Setup | Steps | Expected | Automated evidence | Result | Evidence |
|---|---|---|---|---|---|---|---|
| S11 | COR-033 beyond the caps | Seeded fixture or the real-database CI group | More than 50 operations issues; an order with more than 100 groups | Counts stay truthful and no later group or task is omitted (browser evidence is not expected to reach these numbers on training) | `S6-attention-truthful` | | |
| S12 | COR-034 invalid later group member visible | An order with one valid and one malformed line snapshot (fixture) | Open the order's Delivery information box | The malformed line is listed under Needs review with its status; no raw meta shown | `C14-order-surface-authority` | | |

## Registry rows

The registry rows for the IDs M2 claims (DE-CART-003/004/005/006/007, DE-CTX-004,
DE-GROUP-006, DE-SHIP-002/003/004/005/006/007/008/009, DE-PERF-003/006, DE-DIAG-005,
DE-SEC-001/004) are reclassified with the evidence above once the rows carry a result.
DE-NOTIFY-002/004 remain post-1.0.

## Known limits recorded for signing

- A product set to International has no delivery option at checkout until Air/Sea
  prices exist; staff must not set products to International before then (D9).
- COR-029 stays open with M4; COR-031 to COR-034 are accepted as invariants.
- Concurrency, fault injection and count caps (S11) are proven in the unit suites
  and the real-database CI group, not in the browser.
