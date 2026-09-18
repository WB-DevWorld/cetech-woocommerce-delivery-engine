# Reusable WooCommerce Delivery & Fulfilment Engine

## CURRENT IMPLEMENTATION STATUS — V1 RC (read first)

**Last updated:** 2026-09-17  
**Post-RC.11 issue #23:** active technical-correction candidate **`1.0.0-dev.geo.7`** on `feat/canonical-geography-coverage` / draft PR `#24`. Schema target **`6`**. Frozen rejected candidates: geo.1 / geo.2 / geo.3 / geo.4 / geo.5 / geo.6. Not merged. Not RC.12. Do not physically QA until independent review.  
**Staff training:** `docs/training/` covers RC.9 operational features inherited from Blocks.4 (Cart/Checkout Blocks, overlapping Delivery Area pricing, constrained vs global fallback). Coverage matrix: `docs/training/13-FEATURE-COVERAGE-AND-CONFIRMATION.md`. Videos remain deferred; trainers tick live Pass/Fail/N/A. FLAIROC not modified.  
**Plugin version:** Tagged protected runtime baseline **`1.0.0-rc.11`** (`v1.0.0-rc.11` → `384f564f64a2db766ae6907392e95fb366fb8533`, schema `5`). Do not retag or overwrite the RC.11 ZIP. Tagged protected **`1.0.0-rc.10`** (`v1.0.0-rc.10` → `d1409258caf1a90675b689ab105471460de4c713`, schema `5`) remains immutable. Post-RC.10 issue **#18** candidate identity on `fix/pdp-delivery-price-display` is **`1.0.0-dev.pdp-price.3`**. Not Stage 15. WPML/WCML and WP Rocket certification remain separate. Historical `1.0.0-dev.qual.1` ZIP remains immutable. Historical tagged/protected **`1.0.0-rc.9`** (schema `5`, tag `v1.0.0-rc.9`, source `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`) remains untouched. Do not retag RC.2–RC.11. Do not begin Stage 15. FLAIROC must not be modified. Historical tagged **`1.0.0-rc.8`** / **`1.0.0-rc.7`** (schema `5`) and **`1.0.0-rc.6`** (schema `4`) remain untouched.  
**Post-RC.9 cart-state reconciliation:** **INCLUDED** — live cart lines refresh to current Delivery Engine configuration; stale admin fingerprints no longer force duplicate shipping groups; unavailable choices require in-cart reselection. Canonical record: `docs/POST-RC9-CART-STATE-RECONCILIATION.md`.  
**Post-RC.9 Blocks line snapshot:** **INCLUDED** — native Blocks/Store API backfills Classic-equivalent line `_cetech_de_delivery_snapshot`. Artifact `docs/POST-RC9-BLOCKS-LINE-SNAPSHOT.md`.  
**Post-RC.9 per-item customer context:** **INCLUDED** from packaged `1.0.0-dev.peritem.1` source `72cb7ddfcb5ed85765ed167831736ba04a3cdf89`. Canonical record: `docs/POST-RC9-PER-ITEM-CONTEXT.md`.  
**Post-RC.9 WCFM administrative isolation:** **INCLUDED** via cherry-pick of `f09d0b6` / `b3333bf`. WCFM vendors are kept outside Delivery Engine administration; vendor fulfilment controls are not provided. Canonical record: `docs/POST-RC9-WCFM-ISOLATION.md`. Capability matrix version **`4`**. WPML is not merged.
**Post-RC.8 Settings honesty + Cart/Checkout Blocks:** **PROMOTED TO `1.0.0-rc.9`** — identity-only promotion of frozen `1.0.0-dev.blocks.4`. Canonical record: `docs/RC9-FINALIZATION.md`. Schema **`5`**. No runtime change versus Blocks.4. Classic WoodMart checkout remains the training-site storefront. FLAIROC not modified. RC.8 tag untouched.  
**Post-RC.7 fulfilment correctness:** **PROMOTED TO `1.0.0-rc.8`** — owner physical QA of `1.0.0-dev.fulfilment.4` on training.cetechbpa.com: **ALL THREE SCENARIOS PASSED**. Canonical record: `docs/RC8-FINALIZATION.md`. Schema **`5`**. Not Stage 15. FLAIROC not modified. Owner RC.8 confirmation is install/Active/version/schema plus one PDP and one admin page only.  
**Post-RC.6 combined stream:** Bulk Tools schema **`5`** plus approved R1 admin/setup runtime repairs, QA.1 request-path repairs, Bulk.8 background portability, and Bulk.9 physical-QA repairs, frozen as RC.7. Canonical record: `docs/POST-RC6-CONSOLIDATED-DEVELOPMENT-STREAM.md`. RC.7 finalization: `docs/RC7-FINALIZATION.md`. Bulk.9 owner QA: `docs/POST-RC6-BULK9-OWNER-QA.md`. Historical Bulk branch `feat/post-rc6-bulk-tools` @ `720b513` and R1 branch `fix/post-rc6-admin-setup-defects` remain preserved. Previous untagged owner-QA packages **`1.0.0-dev.bulk.2`**–**`1.0.0-dev.bulk.9`** remain immutable (`1.0.0-dev.bulk.9.zip`, `1256253` bytes, SHA-256 `af331732c1529427c85adc8cfaeb7dbf26ef996e2952b3918f15c25da4f100b2`, source `85194cb86ab3d906e6642a3f15b1027767a12da8`, schema `5`). **OWNER PHYSICAL QA of Bulk.9: ALL SIX ACCEPTANCE CHECKS PASSED** on training.cetechbpa.com (2026-08-29). R1 QA.1/QA.2 ZIPs remain immutable.  
**Schema target:** tagged RC.10 = `5`. Tagged RC.9 = `5`. Tagged RC.8 = `5`. Tagged RC.7 = `5`. Tagged RC.6 = `4`.  
**Git:** `release/rc10` is the RC.10 promotion surface. Historical `batch/pre-rc10-qualification` / PR #12 remains unmerged qualification provenance (`be586a4` / packaged `c0000ab`). Historical recovered branch `feat/post-rc9-customer-ux` remains immutable evidence. WPML is not merged; unique overlay remains `feat/post-rc9-wpml` @ `3b5b60d0d92b2edc00496d536774f8ac907cd6d2`. Historical `feat/post-rc8-integrations` promoted to `v1.0.0-rc.9`. Historical `feat/post-rc7-fulfilment-correctness` promoted to `v1.0.0-rc.8`. Historical `integration/post-rc6-bulk-r1`, `feat/post-rc6-bulk-tools`, and `fix/post-rc6-admin-setup-defects` not rewritten. Unrelated RC.6 security-audit leftovers preserved on `wip/rc6-adversarial-security-audit` @ `a88f28048afcc84456e6933351c71b01a9838c5a`. RC.6 tag `v1.0.0-rc.6`, RC.7 tag `v1.0.0-rc.7`, RC.8 tag `v1.0.0-rc.8`, and RC.9 tag `v1.0.0-rc.9` **untouched**.
**Hard dependency:** WooCommerce only (PHP 8.1+, HPOS-compatible)  
**Namespace / root file:** `CetechDeliveryEngine\` / `cetech-woocommerce-delivery-engine.php`  
**Canonical maintained rulebook:** `docs/DELIVERY-ENGINE-GOVERNING-RULES.md` (mandatory for all implementation work)

Leftover RC.6 adversarial security-audit artifacts were isolated on `wip/rc6-adversarial-security-audit` (`a88f280`) and are **not** part of Bulk Tools commits.

This handoff remains the **product vision and domain source of truth**. Much of the document describes the full intended engine (including shipment records and customer tracking). **Only the V1 RC scope below is implemented in code today.** Do not assume later sections are already built.

### Stage 0B / Stage 1 / Stage 2 / Stage 3 / Stage 4 / Stage 5A / Stage 5B / Stage 6A / Stage 6B / Stage 6C / Stage 7 / Stage 8 / Stage 8C status

| Item | Status |
|------|--------|
| Stage 0B FLAIROC RC baseline | **COMPLETE** |
| Stage 1 architecture gap analysis | **COMPLETE** — `docs/POST-RC-ARCHITECTURE-GAP-ANALYSIS.md` |
| Stage 2 scoped configuration storage | **COMPLETE** — schema target `3`; artifact `docs/STAGE-2-SCOPED-CONFIGURATION-STORAGE.md` |
| Stage 3 EffectiveConfigurationResolver | **COMPLETE** — artifact `docs/STAGE-3-EFFECTIVE-CONFIGURATION-RESOLVER.md` |
| Stage 4 Admin inheritance UX + preview | **COMPLETE** — artifact `docs/STAGE-4-ADMIN-INHERITANCE-UX.md` |
| Stage 5A Simple-product ECR runtime integration | **COMPLETE** — artifact `docs/STAGE-5A-SIMPLE-RUNTIME-ECR-INTEGRATION.md` |
| Stage 5B FLAIROC recovery / bootstrap / admin repairs | **COMPLETE** |
| Stage 5 overall | **COMPLETE** |
| Stage 6A Variable-product ECR support | **COMPLETE** |
| Stage 6B variable FLAIROC QA | **FUNCTIONALLY COMPLETE** — parent `#39717` / A `#39718` / B `#39719`; order **`#39721`** |
| Stage 6 | **FUNCTIONALLY COMPLETE** |
| Stage 6C presentation cleanup | **COMPLETE** — Fulfilment / Delivery method / Delivery option / Estimated delivery; hide technical meta |
| Stage 7 WoodMart adapter | **COMPLETE — NO WOODMART ADAPTER REQUIRED** |
| Stage 8 multi-product shipping grouping | **FUNCTIONALLY COMPLETE** — order **`#39724`** shipping **25.00** / total **64.98** |
| Stage 8C order-admin technical presentation cleanup | **COMPLETE** — live PASS |
| Classic Checkout release `1.0.0-rc.2` | **LIVE AND USABLE** — final live smoke **PASS**; readiness `docs/RELEASE-1.0.0-RC.2-READINESS.md`; **do not modify the tag** |
| Stage 13 site-wide delivery defaults | **COMPLETE** — artifact `docs/STAGE-13-SITE-WIDE-DELIVERY-DEFAULTS.md`; owner-accepted via QA.4 / QA.5 |
| Stage 13B WordPress-native admin UX | **COMPLETE** — Overview, Setup Guide, progressive product panel; artifacts `docs/STAGE-13B-*.md` |
| Stage 13C owner-review repairs | **COMPLETE** — wizard / International Air-Sea / Preview / readiness; artifacts `docs/STAGE-13C-R*.md` |
| Stage 13D final admin simplification | **COMPLETE** — legacy retired from normal menu; real WP role Access; RateCard optional-date repair. Artifact `docs/STAGE-13D-FINAL-ADMIN-SIMPLIFICATION.md` |
| Stage 13D-R1 admin access / recovery hardening | **COMPLETE** — Administrator protected full access; independent `manage_options` recovery. Artifact `docs/STAGE-13D-R1-ADMIN-ACCESS-RECOVERY-HARDENING.md` |
| Stage 13E RC.3 finalization | **COMPLETE** — version `1.0.0-rc.3`; artifact `docs/STAGE-13E-RC3-FINALIZATION.md`. Owner QA.4 + QA.5 accepted; FLAIROC final RC.3 install smoke **pending owner** |
| Stage 13F customer presentation polish | **COMPLETE** — compact public contract + WC rate label uses public option label; owner QA.1 **PASSED**. Artifact `docs/STAGE-13F-CUSTOMER-PRESENTATION-POLISH.md`. |
| Stage 13F-R1 owner QA package | **COMPLETE / ACCEPTED** — `1.0.0-rc.4-qa.1` owner physical FLAIROC retest **ALL PASS** (`docs/STAGE-13F-R1-OWNER-QA-PACKAGE.md`). No additional QA build required. |
| Stage 13G RC.4 finalization | **COMPLETE** — version `1.0.0-rc.4`; tag `v1.0.0-rc.4`; artifact `docs/STAGE-13G-RC4-FINALIZATION.md`. FLAIROC not modified in finalization; owner short confirmation pending. |
| Stage 14A shipment architecture audit | **COMPLETE** — documentation only. Artifact `docs/STAGE-14A-SHIPMENT-ARCHITECTURE-AUDIT.md`. Recommended V1: Delivery Engine schema-4 tables as canonical store behind `ShipmentRepositoryInterface`; WooCommerce Fulfillments adapter reserved, not canonical. **No runtime shipment functionality.** |
| Stage 14B shipment persistence foundation | **COMPLETE** — schema target `4`; domain + `WpdbShipmentRepository`. Artifact `docs/STAGE-14B-SHIPMENT-PERSISTENCE-FOUNDATION.md`. |
| Stage 14C shipment planning/creation runtime | **COMPLETE** — historical snapshot planner + idempotent paid-order creation; **feature-gated OFF** (`enable_shipment_records`). Artifact `docs/STAGE-14C-SHIPMENT-CREATION.md`. No tracking editing or customer timeline. |
| Stage 14D staff Shipments workspace | **COMPLETE** — flag-gated staff list + read-only detail. Artifact `docs/STAGE-14D-STAFF-SHIPMENTS-WORKSPACE.md`. Stage 14D-R1: unknown persisted event codes hydrate and present as “Shipment update” without rewriting rows. |
| Stage 14E tracking + customer shipment presentation | **COMPLETE** — manual staff tracking save + customer View Order shipment cards, both behind feature flags. Artifact `docs/STAGE-14E-TRACKING-CUSTOMER-SHIPMENTS.md`. |
| Stage 14F shipment operations workflow | **COMPLETE** — status transitions, current-ETA updates, conservative WooCommerce cancel/refund sync, operational Needs Attention, Access matrix shipment caps, audit/event hardening. Behind feature flags. Artifact `docs/STAGE-14F-SHIPMENT-OPERATIONS-WORKFLOW.md`. No carrier automation, customer timeline, shipment emails, package, tag, or FLAIROC deploy. |
| Stage 14G full Stage 14 qualification | **COMPLETE** — PHPUnit, lint, JS, source audits, and a Needs Attention lifecycle repair passed. Original real-DB gate was deferred. Artifact `docs/STAGE-14G-STAGE14-QUALIFICATION.md`. |
| Stage 14G-R1 real MariaDB schema 3→4 | **COMPLETE / PASS — STAGE 14 QUALIFIED FOR OWNER QA PACKAGE** — artifact `docs/STAGE-14G-R1-REAL-DATABASE-QUALIFICATION.md`. |
| Stage 14H RC.5 QA.1 package | **RC.5-QA.1 FAILED OWNER QA** — checkout/email/View Order fatal in `CustomerOrderDeliverySummaryBuilder`; COD processing created shipments without `date_paid`. Artifact `docs/STAGE-14H-RC5-OWNER-QA.md`. ZIP immutable. |
| Stage 14H-QA1-R1 QA.2 repair | **RC.5-QA.2 FAILED OWNER QA** — customer-summary fatal and COD payment confirmation repaired, but Site-wide inheritance failed for untouched products. ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.2.zip` immutable. |
| Stage 14H-QA2-R1 QA.3 repair | **RC.5-QA.3 PREPARED** — optional/defaultable ECR fields no longer fail-close an otherwise valid Site-wide inherited configuration. ZIP immutable. **Not** physically retested on FLAIROC. |
| Stage 14H-QA3-R1 QA.4 polish | **RC.5-QA.4 OWNER PHYSICAL PASS** — inheritance, badges, Track button, staff actor resolution, COD no premature shipment, and payment_complete exactly one shipment. ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.4.zip` **immutable**. FLAIROC was tested; this QA.5 task does **not** modify FLAIROC. |
| Stage 14H-QA4-R1 QA.5 operational completeness | **RC.5-QA.5 FAILED OWNER QA** — amount-only refund on order **39749** correctly left shipment **39749-D1** `awaiting_fulfilment` but did **not** create Needs Attention `refund_requires_review`. ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.5.zip` **immutable**. FLAIROC was tested; this QA.6 task does **not** modify FLAIROC. |
| Stage 14H-QA5-R1 QA.6 refund review repair | **RC.5-QA.6 OWNER PHYSICAL PASS** — amount-only / unproven refunds keep shipment status and appear in Needs Attention as refund review. Explicit full item-quantity refunds before dispatch auto-cancel. Refund after progress preserves shipment + review. ZIP `cetech-woocommerce-delivery-engine-1.0.0-rc.5-qa.6.zip` **immutable**. |
| Stage 14H-FINAL RC.5 | **COMPLETE** — version `1.0.0-rc.5`; tag `v1.0.0-rc.5`; artifact `docs/STAGE-14H-FINAL.md`. FLAIROC not modified in finalization; owner clean-folder install of the final ZIP is pending. |
| Post-RC.5 shipping-method registry listing | **INCLUDED IN TAGGED `1.0.0-rc.6`** (first packaged as `1.0.0-rc.6-qa.1`) — `delivery_engine_selected_offer` is listed for zone assignment whenever WooCommerce is active; storefront rates stay flag-gated. Add Delivery only to intended zones; Rest of the World is optional. Artifact `docs/STAGE-14H-SHIPPING-METHOD-REGISTRY-REPAIR.md`. QA.1 ZIP immutable. FLAIROC not modified. |
| Post-RC.5 region code/label matching | **OWNER PHYSICAL PASS on `1.0.0-rc.6-qa.2`; INCLUDED IN TAGGED `1.0.0-rc.6`** — Delivery Area Region rules match WooCommerce state code or that country’s state label. No data migration. Artifact `docs/STAGE-14H-REGION-CODE-LABEL-MATCH.md`. QA.2 ZIP immutable. FLAIROC not modified. |
| Stage 14H-RC6-FINAL | **COMPLETE** — version `1.0.0-rc.6`; tag `v1.0.0-rc.6`; artifact `docs/STAGE-14H-RC6-FINAL.md`. Owner-accepted from QA.2 on training.cetechbpa.com. FLAIROC not modified. |
| RC.6 compatibility & lifecycle qualification | **PASS** — artifact `docs/RC6-COMPATIBILITY-LIFECYCLE-QUALIFICATION.md`. Tag `v1.0.0-rc.6` not changed. FLAIROC not modified. |
| RC.6 adversarial security audit | **NOT ADVERSARIAL SECURITY QUALIFIED** — artifact `docs/RC6-ADVERSARIAL-SECURITY-AUDIT.md`. P0 AUTH-009/LIFE-015. No RC.6 runtime repair. Tag not changed. FLAIROC not modified. |
| Post-RC.6 tester-observations audit | **COMPLETE / READ-ONLY** — artifact `docs/POST-RC6-TESTER-OBSERVATIONS-AUDIT.md`. No RC.6 runtime repair in that stage. |
| Post-RC.6 Repair R1 | **IMPLEMENTED on historical branch `fix/post-rc6-admin-setup-defects`** — audit items 1–4. QA.1 **OWNER PHYSICAL FAIL**; QA.1 request-path repairs packaged as `1.0.0-rc.6-r1-qa.2` (schema `4`). Artifacts `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1*.md`. ZIPs immutable. Now layered into the combined stream; do not keep installing R1 ZIPs beside Bulk ZIPs. |
| Post-RC.6 combined Bulk + R1 stream | **PROMOTED TO `1.0.0-rc.7`** — `integration/post-rc6-bulk-r1`, schema `5`. Artifact `docs/POST-RC6-CONSOLIDATED-DEVELOPMENT-STREAM.md`. Bulk.9 owner physical QA: `docs/POST-RC6-BULK9-OWNER-QA.md` (**ALL SIX CHECKS PASSED**). RC.7 finalization: `docs/RC7-FINALIZATION.md`. Not Stage 15. Previous packaged identities `1.0.0-dev.bulk.7`–`1.0.0-dev.bulk.9` remain immutable. |
| Governing rules hardening | **COMPLETE** — canonical rulebook `docs/DELIVERY-ENGINE-GOVERNING-RULES.md`; Cursor always-apply wrapper `.cursor/rules/000-delivery-engine-governance.mdc`. Documentation/governance only. |
| New storage | `configuration_scopes` / `configuration_fields` / `configuration_collections` + domain/repos; profile defaults use `global/0/{profile_key}` |
| New resolver | GLOBAL→PRODUCT→VARIATION field inheritance; per-profile site-wide root; primary default fallback; provenance; fingerprint; hard constraints |
| New admin UX | **Overview** + first-time setup wizard + Site-wide Defaults + Product Exceptions + **Shipments** (behind `enable_shipment_records`) + Needs Attention. Product/variation customization is progressive. Legacy Delivery Rules is **retired from the normal menu**. Technical Diagnostics is a hidden support destination. Administrator is a protected full-access role in Settings → Access. |
| Runtime on FLAIROC | **Required production features ON**; deferred features **OFF** until chosen; **COD OFF** on production storefront policy. Schema **4**. Live plugin at owner QA was QA.6 (**PASS**). Final `1.0.0-rc.5` is prepared; Cursor did **not** install it. |
| Shipments / tracking / timeline | **Stage 14 V1 implemented and Settings-activatable** (`enable_shipment_records` / `enable_tracking_links`, default OFF). Timeline remains reserved. **RC.5-QA.6 OWNER PHYSICAL PASS. RC.5 FINALIZED.** No carrier automation, no shipment emails, no customer timeline. |
| Variable runtime capture | Verified live; WoodMart adapter **not required** |
| Hard constraints | **Implemented** for representable International / In Store / In Warehouse rules |
| Category legacy parity | Hidden compatibility route retained; Legacy management UI is not a normal workflow |
| Post-RC.6 RC.7 finalization | Tagged **`1.0.0-rc.7`** remains a protected historical baseline — version `1.0.0-rc.7`; schema `5`; artifact `docs/RC7-FINALIZATION.md`. Owner-accepted from `1.0.0-dev.bulk.9` on training.cetechbpa.com. FLAIROC not modified. RC.6 tag untouched. |
| Post-RC.7 fulfilment correctness | **PROMOTED TO TAGGED `1.0.0-rc.8`** — owner physical QA of `1.0.0-dev.fulfilment.4` on training.cetechbpa.com: **ALL THREE SCENARIOS PASSED**. Canonical record: `docs/RC8-FINALIZATION.md`. Schema **`5`**. RC.7 tag/FLAIROC untouched. Owner RC.8 confirmation: install, Active, version `1.0.0-rc.8`, schema `5`, one PDP + one admin page. Do not repeat the three scenarios. |
| Post-RC.8 Settings honesty + Blocks | **PROMOTED TO TAGGED `1.0.0-rc.9`** — identity-only promotion of frozen `1.0.0-dev.blocks.4` packaged source `796b9a52`. Canonical record: `docs/RC9-FINALIZATION.md`. Schema **`5`**. No runtime change versus Blocks.4. RC.8 tag/FLAIROC untouched. Training remains Classic WoodMart checkout (cart `12` / checkout `13`; layouts 1482 / 1496 / 1500 publish). |
| Post-RC.9 cart-state reconciliation | **INCLUDED via cherry-pick `39f61c1`** — `docs/POST-RC9-CART-STATE-RECONCILIATION.md`. Schema **`5`**. |
| Post-RC.9 Blocks line snapshot | **INCLUDED via cherry-pick `54c9894`** — native Blocks/Store API line snapshots. Artifact `docs/POST-RC9-BLOCKS-LINE-SNAPSHOT.md`. |
| Post-RC.9 per-item customer context | **INCLUDED** from `72cb7dd` / `1.0.0-dev.peritem.1`. Artifact: `docs/POST-RC9-PER-ITEM-CONTEXT.md`. |
| Post-RC.9 WCFM administrative isolation | **INCLUDED** via cherry-pick `f09d0b6` / `b3333bf`. Artifact: `docs/POST-RC9-WCFM-ISOLATION.md`. |
| Post-RC.9 combined candidate | **`1.0.0-dev.integrated.1`** — per-item + WCFM isolation. Schema **`5`**. Not RC.10. Not WPML. ZIP must not be rebuilt. |
| Post-RC.9 customer UX | **HISTORICAL `1.0.0-dev.integrated.2`** recovered on `feat/post-rc9-customer-ux` from `ac2bc94`. Presentation only. Schema **`5`**. Not RC.10. The historical ZIP is immutable. Artifact: `docs/POST-RC9-CUSTOMER-UX.md`. |
| Pre-RC.10 qualification baseline | **OWNER QA PASS** as untagged `1.0.0-dev.qual.1` on `batch/pre-rc10-qualification` / PR #12 (`be586a4`, packaged `c0000ab`). Historical qualification evidence only. **Do not merge PR #12.** Artifact: `docs/qualification/PRE-RC10-QUALIFICATION-BASELINE.md`. |
| RC.10 core collaboration baseline | **PROMOTION CANDIDATE** `1.0.0-rc.10` on `release/rc10`. Schema **`5`**. Canonical record: `docs/RC10-FINALIZATION.md`. WPML/WCML and WP Rocket certification remain separate. Not Stage 15. |
| Next stage | **STOP.** Do **not** begin Stage 15. Do **not** modify FLAIROC. Do **not** retag `v1.0.0-rc.9` or earlier. Do **not** merge PR #12. Independent review of the RC.10 release PR is required before protected merge and tag. |
| Final PHP log (RC.2 smoke) | Marker **546** → inspected through **548** — **PASS**; no new Delivery Engine fatals |
| Live QA orders | `#39721` (variable); `#39724` (multi-product grouping); owner-QA evidence **`#39733` / `#39734` / `#39735`** (preserve; do not modify remotely) |
| Package | Final `cetech-woocommerce-delivery-engine-1.0.0-rc.9.zip` (`1380751` bytes, SHA-256 `08862b8c048b92dd0ff42ded7eff29c3a5e42a42c984ac43e0a603cef50d96fd`, source `e6bc7fb`, tag `v1.0.0-rc.9`). Historical `1.0.0-dev.blocks.1.zip`–`blocks.4.zip` remain immutable QA artifacts. Final `cetech-woocommerce-delivery-engine-1.0.0-rc.8.zip` details in `docs/RC8-FINALIZATION.md`. Historical `cetech-woocommerce-delivery-engine-1.0.0-rc.7.zip` remains immutable. Historical `cetech-woocommerce-delivery-engine-1.0.0-rc.6.zip` (`1059918` bytes, SHA-256 `0d4adbef50462d798a4ff9bf802643bed92a35cdd332ceee13a985dbda2a689d`, source `7e52525`, tag `v1.0.0-rc.6`) remains immutable. Immutable `1.0.0-rc.6-qa.2.zip` / `1.0.0-rc.6-qa.1.zip` and protected historical `cetech-woocommerce-delivery-engine-1.0.0-rc.5.zip` remain unchanged. Historical `1.0.0-dev.bulk.7`–`1.0.0-dev.bulk.9` and `1.0.0-dev.fulfilment.1`–`fulfilment.4` ZIPs remain immutable. |

**RC.2 final live smoke: PASS.** Plugin is **live and usable** on the current FLAIROC Classic Checkout environment with required production features enabled.

**RC.3 package:** tagged and untouched. **RC.4 package:** locally finalized and tagged after owner QA.1 (`1.0.0-rc.4-qa.1`) acceptance of Stage 13F customer presentation polish. Do **not** claim FLAIROC final RC.4 runtime confirmation until the owner completes the short post-install check.

Stage 14C–14F implemented shipment planning, staff workspace, tracking, customer cards, and operations behind flags. Stage 14G / G-R1 qualified the work, including real MariaDB schema 3→4. Stage 14H QA.1 and QA.2 **FAILED** owner QA. QA.3 repaired inheritance (not physically retested alone). QA.4 **PASSED** physically (inheritance, badges, Track button, staff actor, COD payment gate). QA.5 **FAILED** on amount-only refund review. QA.6 **PASSED** owner physical QA (refund review, COD manual creation, Air/Sea, pickup suppression, cancellation, badges). Stage 14H-FINAL promotes that baseline to tagged **`1.0.0-rc.5`**. Stage 14H-RC6-FINAL promotes owner-accepted `1.0.0-rc.6-qa.2` (training.cetechbpa.com) to tagged **`1.0.0-rc.6`**. Training for shipments is `docs/training/11-STAGE-14-SHIPMENTS.md`. FLAIROC was **not** modified by RC.5 or RC.6 finalization. Artifacts `docs/STAGE-14H-FINAL.md` and `docs/STAGE-14H-RC6-FINAL.md`.

Administrator-language requirement: normal-user admin UI must use operational language throughout. Technical terminology belongs only in Technical details.



### Companion docs (implementation)

| Doc | Role |
|-----|------|
| `docs/PROJECT-GOVERNANCE.md` | Mandatory process / source-of-truth hierarchy |
| `docs/POST-RC6-BULK-TOOLS-ARCHITECTURE.md` | Post-RC.6 Bulk Tools + configuration portability architecture |
| `docs/POST-RC6-BULK-TOOLS-IMPLEMENTATION.md` | Post-RC.6 Bulk Tools implementation record |
| `docs/POST-RC6-CONSOLIDATED-DEVELOPMENT-STREAM.md` | Combined post-RC.6 Bulk Tools + R1 stream record (historical packaged identities `1.0.0-dev.bulk.7`–`1.0.0-dev.bulk.9`; promoted to `1.0.0-rc.7`) |
| `docs/POST-RC6-BULK-BACKGROUND-PORTABILITY.md` | Bulk Tools background-execution portability audit + repair (no schema 6) |
| `docs/POST-RC6-BULK-BACKGROUND-PORTABILITY-QA.md` | `1.0.0-dev.bulk.8` owner-QA package record (historical; physical QA stopped at Bulk.8) |
| `docs/POST-RC6-BULK8-PHYSICAL-QA-REPAIR.md` | Bulk.9 repair of confirmed Bulk.8 physical-QA defects |
| `docs/POST-RC6-BULK9-OWNER-QA.md` | `1.0.0-dev.bulk.9` owner-QA package record — **OWNER PHYSICAL QA: ALL SIX ACCEPTANCE CHECKS PASSED** |
| `docs/RC7-FINALIZATION.md` | RC.7 release record (promoted from accepted Bulk.9) |
| `docs/RC8-FINALIZATION.md` | RC.8 release record (promoted from accepted Fulfilment.4) |
| `docs/RC9-FINALIZATION.md` | RC.9 release record (identity-only promotion from frozen Blocks.4) |
| `docs/POST-RC9-BLOCKS-LINE-SNAPSHOT.md` | Post-RC.9 native Blocks line-snapshot repair (`1.0.0-dev.blocks-snapshot.1`) |
| `docs/POST-RC9-PER-ITEM-CONTEXT.md` | Post-RC.9 per-item customer context Stages 1–6 (`1.0.0-dev.peritem.1`) |
| `docs/POST-RC9-WCFM-ISOLATION.md` | Post-RC.9 WCFM administrative isolation (`1.0.0-dev.wcfm.1`) |
| `docs/POST-RC9-WCFM-ISOLATION-QA.md` | Owner QA package + narrow physical QA instructions for `1.0.0-dev.wcfm.1` |
| `docs/POST-RC9-INTEGRATED-CANDIDATE.md` | Combined per-item + WCFM candidate (`1.0.0-dev.integrated.1`) |
| `docs/POST-RC9-CUSTOMER-UX.md` | Presentation-only storefront cleanup (`1.0.0-dev.integrated.2`) |
| `docs/qualification/PRE-RC10-QUALIFICATION-BASELINE.md` | WS3 INTEG-01 reconciled qualification baseline (`1.0.0-dev.qual.1`; historical provenance; do not merge PR #12) |
| `docs/RC10-FINALIZATION.md` | RC.10 core collaboration baseline promotion record |
| `docs/POST-RC8-INTEGRATIONS-COMPATIBILITY-AUDIT.md` | Post-RC.8 integrations/Settings honesty audit (accepted; pre-implementation) |
| `docs/POST-RC8-BLOCKS-IMPLEMENTATION.md` | Post-RC.8 Settings honesty + real Cart/Checkout Blocks adapter (`1.0.0-dev.blocks.1`) |
| `docs/POST-RC9-CART-STATE-RECONCILIATION.md` | Post-RC.9 live cart-state reconciliation (`1.0.0-dev.cartstate.1`) |
| `docs/POST-RC9-CART-STATE-QA.md` | Owner QA package + narrow physical QA instructions for `1.0.0-dev.cartstate.1` |
| `docs/POST-RC8-BLOCKS-4-CONSTRAINED-FALLBACK.md` | Constrained vs global Delivery Area fallback (`1.0.0-dev.blocks.4`) |
| `docs/POST-RC8-BLOCKS-4-QA.md` | `1.0.0-dev.blocks.4` owner-QA package record — frozen packaged source promoted to `1.0.0-rc.9` |
| `docs/POST-RC8-BLOCKS-3-MATCHED-AREA-PRICING.md` | Ordered matched-area pricing fallback (`1.0.0-dev.blocks.3`) |
| `docs/POST-RC8-BLOCKS-3-QA.md` | `1.0.0-dev.blocks.3` owner-QA package record |
| `docs/POST-RC8-BLOCKS-2-PICKUP-RATE-REPAIR.md` | Mixed Pickup GHS 0 false fail-closed repair (`1.0.0-dev.blocks.2`) |
| `docs/POST-RC8-BLOCKS-2-QA.md` | `1.0.0-dev.blocks.2` owner-recheck package record |
| `docs/POST-RC8-BLOCKS-QA.md` | `1.0.0-dev.blocks.1` owner-QA package record (historical; mixed-cart warning found) |
| `docs/POST-RC7-FULFILMENT-CORRECTNESS-AUDIT.md` | Post-RC.7 In Store / International root-cause audit (accepted) |
| `docs/POST-RC7-FULFILMENT-CORRECTNESS-IMPLEMENTATION.md` | Post-RC.7 fulfilment correctness runtime record (`1.0.0-dev.fulfilment.1`) |
| `docs/POST-RC7-FULFILMENT-CORRECTNESS-ADMIN-REPAIR.md` | Scenario 1 admin blocker repair (`1.0.0-dev.fulfilment.2`) |
| `docs/POST-RC7-FULFILMENT-CORRECTNESS-CART-PRESENTATION.md` | Scenario 1 cart presentation repair (`1.0.0-dev.fulfilment.3` / `.4`) |
| `docs/POST-RC7-FULFILMENT-CORRECTNESS-QA.md` | `1.0.0-dev.fulfilment.4` owner-QA package record — **ALL THREE SCENARIOS PASSED**; promoted to `1.0.0-rc.8` |
| `docs/DELIVERY-ENGINE-GOVERNING-RULES.md` | **Canonical maintained rulebook** — mandatory hard invariants for architecture, privacy, shipments, language, release, and testing honesty |
| `docs/PROJECT-RULES.md` | Preserved detailed engineering rules; reconcile against the canonical rulebook on conflict |
| `docs/ARCHITECTURE-PLAN.md` | Modular-monolith architecture |
| `docs/PHASE-1A-IMPLEMENTATION.md` … `docs/PHASE-2H4-IMPLEMENTATION.md` | Phase completion notes |
| `docs/V1-RC-RELEASE-NOTES.md` | RC scope summary |
| `docs/V1-RC-FLAG-MATRIX.md` | Feature-flag defaults and enablement order |
| `docs/V1-RC-SMOKE-TEST-CHECKLIST.md` | Staging gate before promoting RC |
| `docs/V1-RC-PACKAGING-GUIDE.md` | Private ZIP build (`scripts/build-v1-rc-package.ps1`) |
| `docs/DEVELOPMENT-ENVIRONMENT.md` | Canonical development/staging target (non-secret) |
| `docs/POST-RC-BASELINE-VERIFICATION.md` | Stage 0 / 0A / 0B baseline verification report |
| `docs/POST-RC-ARCHITECTURE-GAP-ANALYSIS.md` | Stage 1 post-RC architecture gap analysis (authoritative) |
| `docs/STAGE-2-SCOPED-CONFIGURATION-STORAGE.md` | Stage 2 scoped configuration storage completion record |
| `docs/STAGE-3-EFFECTIVE-CONFIGURATION-RESOLVER.md` | Stage 3 effective configuration resolver completion record |
| `docs/STAGE-4-ADMIN-INHERITANCE-UX.md` | Stage 4 admin inheritance UX + effective preview completion record |
| `docs/STAGE-5A-SIMPLE-RUNTIME-ECR-INTEGRATION.md` | Stage 5A simple-product ECR runtime integration (local; flag default OFF) |
| `docs/STAGE-5B-FLAIROC-DEPLOYMENT-VERIFICATION.md` | Stage 5B FLAIROC deployment incident + verification record |
| `docs/STAGE-6A-VARIABLE-PRODUCT-ECR-SUPPORT.md` | Stage 6A variable-product ECR runtime (local; variable flag default OFF) |
| `docs/STAGE-6B-FLAIROC-VARIABLE-ECR-VERIFICATION.md` | Stage 6B packaging record + FLAIROC variable verification plan |
| `docs/STAGE-8A-MULTI-PRODUCT-SHIPPING-GROUPING.md` | Stage 8A cart delivery groups + WooCommerce shipping packages |
| `docs/STAGE-8C-ORDER-ADMIN-PRESENTATION-CLEANUP.md` | Stage 8C order-admin technical meta presentation cleanup |
| `docs/STAGE-13-ARCHITECTURE-NOTE.md` | Stage 13 pre-change audit of RC.2 default/inheritance behavior |
| `docs/STAGE-13-SITE-WIDE-DELIVERY-DEFAULTS.md` | Stage 13 site-wide defaults + guided admin experience |
| `docs/STAGE-13B-DESIGN-REFERENCE-MAP.md` | Stage 13B design-reference adoption / deviations |
| `docs/STAGE-13B-ADMIN-SCREEN-INVENTORY.md` | Stage 13B RC.3 admin screen inventory |
| `docs/STAGE-13B-WORDPRESS-NATIVE-UX.md` | Stage 13B WordPress-native UX implementation record |
| `docs/STAGE-13C-R1-OWNER-REVIEW-REPAIR.md` | Stage 13C-R1 live owner-review repair (local complete; FLAIROC retest pending) |
| `docs/STAGE-13C-R2-OWNER-RETEST-PACKAGE.md` | Stage 13C-R2 QA.2 packaging record |
| `docs/STAGE-13C-R3-OWNER-QA2-FOLLOW-UP-REPAIR.md` | Stage 13C-R3 live QA.2 follow-up repair (local complete; packaged in QA.3) |
| `docs/STAGE-13C-R4-OWNER-RETEST-PACKAGE.md` | Stage 13C-R4 QA.3 owner retest packaging record |
| `docs/STAGE-13D-FINAL-ADMIN-SIMPLIFICATION.md` | Stage 13D final admin simplification, legacy retirement, real role permissions, QA.4 package |
| `docs/STAGE-13D-R1-ADMIN-ACCESS-RECOVERY-HARDENING.md` | Stage 13D-R1 Administrator protected access + independent manage_options recovery |
| `docs/STAGE-13D-R1-QA5-OWNER-RETEST-PACKAGE.md` | Stage 13D-R1 QA.5 packaging + short owner retest list |
| `docs/STAGE-13E-RC3-FINALIZATION.md` | Stage 13E final RC.3 release record (owner QA.4 + QA.5 accepted; FLAIROC final smoke pending) |
| `docs/STAGE-13F-CUSTOMER-PRESENTATION-POLISH.md` | Stage 13F customer presentation polish + USD 250 investigation |
| `docs/STAGE-13F-R1-OWNER-QA-PACKAGE.md` | Stage 13F-R1 owner QA package `1.0.0-rc.4-qa.1` — owner **PASSED**; superseded by final RC.4 |
| `docs/STAGE-13G-RC4-FINALIZATION.md` | Stage 13G final RC.4 release record (owner QA.1 accepted; no extra QA build) |
| `docs/STAGE-14A-SHIPMENT-ARCHITECTURE-AUDIT.md` | Stage 14A shipment/tracking architecture audit and persistence recommendation (docs only; no runtime) |
| `docs/STAGE-14B-SHIPMENT-PERSISTENCE-FOUNDATION.md` | Stage 14B schema 4 + domain/repository |
| `docs/STAGE-14C-SHIPMENT-CREATION.md` | Stage 14C planner + idempotent paid-order creation (feature-gated OFF) |
| `docs/STAGE-14D-STAFF-SHIPMENTS-WORKSPACE.md` | Stage 14D staff Shipments list/detail workspace (feature-gated OFF) |
| `docs/STAGE-14E-TRACKING-CUSTOMER-SHIPMENTS.md` | Stage 14E manual tracking + customer View Order cards (feature-gated OFF) |
| `docs/STAGE-14F-SHIPMENT-OPERATIONS-WORKFLOW.md` | Stage 14F status/ETA/refund/access operations workflow (feature-gated OFF) |
| `docs/STAGE-14G-STAGE14-QUALIFICATION.md` | Stage 14G full Stage 14 qualification — PHPUnit/source audit; real-DB gate closed by 14G-R1 |
| `docs/STAGE-14G-R1-REAL-DATABASE-QUALIFICATION.md` | Stage 14G-R1 real MariaDB schema 3→4 — PASS — STAGE 14 QUALIFIED FOR OWNER QA PACKAGE |
| `docs/STAGE-14H-RC5-OWNER-QA.md` | Stage 14H RC.5 owner QA history — QA.1/QA.2/QA.5 FAIL; QA.4 PASS; **QA.6 OWNER PHYSICAL PASS** |
| `docs/STAGE-14H-FINAL.md` | Stage 14H-FINAL RC.5 release record |
| `docs/STAGE-14H-SHIPPING-METHOD-REGISTRY-REPAIR.md` | Post-RC.5 repair: Delivery listed in WooCommerce Add shipping method with flags off |
| `docs/STAGE-14H-RC6-QA1-OWNER-QA-PACKAGE.md` | Owner QA package `1.0.0-rc.6-qa.1` — registry listing repair; FLAIROC not deployed |
| `docs/STAGE-14H-REGION-CODE-LABEL-MATCH.md` | Post-RC.5 / post-QA.1 repair: Region rules match WooCommerce state code or country-scoped label |
| `docs/STAGE-14H-RC6-QA2-OWNER-QA-PACKAGE.md` | Owner QA package `1.0.0-rc.6-qa.2` — region code/label matching; owner **PASSED**; FLAIROC not deployed |
| `docs/STAGE-14H-RC6-FINAL.md` | Stage 14H-RC6-FINAL RC.6 release record |
| `docs/RC6-COMPATIBILITY-LIFECYCLE-QUALIFICATION.md` | Post-RC.6 lifecycle + compatibility qualification — **PASS**; tag not changed; FLAIROC not modified |
| `docs/RC6-ADVERSARIAL-SECURITY-AUDIT.md` | Master RC.6 adversarial security audit — **NOT ADVERSARIAL SECURITY QUALIFIED**; no RC.6 runtime repair; FLAIROC not modified |
| `docs/POST-RC6-TESTER-OBSERVATIONS-AUDIT.md` | Read-only training-site tester observations vs tagged RC.6 |
| `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1.md` | Historical R1 implementation (items 1–4) on `fix/post-rc6-admin-setup-defects` |
| `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA1.md` | Historical R1 QA.1 package — **OWNER PHYSICAL FAIL**; ZIP immutable |
| `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA1-FAILURE-REPAIR.md` | Historical R1 QA.1 request-path repair record |
| `docs/POST-RC6-ADMIN-SETUP-REPAIR-R1-QA2.md` | Historical R1 QA.2 package — schema 4; ZIP immutable; superseded for training installs by bulk.7 |
| `docs/CLASSIC-CHECKOUT-RELEASE-CANDIDATE-READINESS.md` | Pre-smoke Classic Checkout readiness (superseded for status by RC.2 readiness) |
| `docs/RELEASE-1.0.0-RC.2-READINESS.md` | **Authoritative** live-verified `1.0.0-rc.2` release readiness |
| `docs/ADMIN-UI-LANGUAGE-GUIDE.md` | Authoritative normal-administrator presentation language |
| `docs/Delivery Shipping Plugin Up-To-Date Design and Expectations.md` | Latest intended product / end-state design |

### What has been accomplished

V1 RC delivers a **feature-flagged** path from admin configuration through paid-order delivery visibility. All customer/runtime flags default **off**.

| Area | Status |
|------|--------|
| Core bootstrap, migrations, System Status, capabilities, uninstall policy | Done (Phases 1A–1B) |
| Config schema + admin CRUD (offers, zones/rules, logistics profiles, suppliers/origins, pickup locations, rate cards, audit log) | Done (Phases 2A–2B5) |
| Product delivery rules + resolver (variation → product → category) | Done (Phases 2C1–2C3); **legacy runtime path** |
| Stage 2 scoped Global→Product→Variation **storage** (schema 3) | Done; dormant foundation; not runtime-wired |
| Stage 3 EffectiveConfigurationResolver | Done; PHPUnit/harness only; not runtime-wired |
| Stage 4 Admin inheritance UX + effective preview | Done; admin-only; pre-cutover |
| Stage 5A simple-product ECR runtime source | Done locally; cutover flag **defaults OFF**; FLAIROC verified then restored OFF |
| Stage 6A variable-product ECR runtime | Done locally; `enable_variable_product_ecr_runtime` **defaults OFF**; requires main ECR flag; FLAIROC not cut over |
| Product-page delivery selector (public-safe; simple + variable shell) | Done (Phases 2D1–2D3 + Stage 6A AJAX variation refresh) |
| Cart selection capture + session/revalidation hardening | Done (Phases 2E1–2E2) |
| Checkout delivery selection validation | Done (Phase 2F1) |
| Rate quote engine + WC shipping method `delivery_engine_selected_offer` (label **Delivery**) | Done (Phases 2G1–2G2) |
| Protected order delivery snapshots + admin order display | Done (Phases 2H1–2H2) |
| Customer thank-you / My Account delivery summary | Done (Phase 2H3) |
| Customer order email delivery summary | Done (Phase 2H4) |
| V1 RC packaging, admin UX polish, guarded admin deletes | Done (post-2H4 RC commits) |

**Canonical V1 runtime pipeline (implemented; still legacy-config backed):**

```text
Product rule → Delivery offers → Product selector → Cart capture
→ Checkout validation → Rate quote + WC shipping method
→ Protected order snapshot → Admin display / customer summary / email
```

**Database tables present** (`{$wpdb->prefix}delivery_engine_*`):  
`delivery_offers`, `destination_zones`, `destination_rules`, `logistics_profiles`, `suppliers`, `origins`, `pickup_locations`, `rate_cards`, `rate_card_rules`, `audit_log`, `product_delivery_rules`, `configuration_scopes`, `configuration_fields`, `configuration_collections`.

**Not created yet:** `shipments`, `shipment_items`, `shipment_events`.

### Runtime feature flags (defaults)

Enable only after admin configuration is complete, in this order:

1. `enable_product_delivery_selector`
2. `enable_cart_delivery_selection_capture`
3. `enable_checkout_delivery_selection_validation`
4. `enable_woocommerce_shipping_rate_calculation`
5. `enable_order_delivery_snapshot_persistence`
6. `enable_customer_order_delivery_summary` (optional)
7. `enable_customer_email_delivery_summary` (optional)

Reserved flags with **no V1 runtime behaviour** (keep off): `enable_shipment_records`, `enable_tracking_links`, `enable_customer_timeline`.  
Blocks checkout adapter flag exists but is off / unwired.  
Stage 5A cutover flag (default **off**): `enable_effective_configuration_runtime` — when off, storefront uses legacy product rules; when on (tests/Stage 5B only), simple products without category dependency use ECR.  
Stage 6A variable cutover flag (default **off**): `enable_variable_product_ecr_runtime` — requires main ECR flag; enables variation AJAX selector + variable cart capture. Code: `src/Bootstrap/FeatureFlags.php`.

### Explicitly not implemented in V1 RC

- Shipment records, staff shipment workspace, tracking timelines / numbers
- Carrier APIs / live freight quotes
- Driver app, OTP, QR, GPS, proof-of-delivery
- Automatic order completion from delivery events
- Public REST / Store API exposure
- WooCommerce Blocks checkout support
- Variable-product delivery **FLAIROC cutover** (Stage 6A implemented locally; Stage 6B verification deferred)
- Real WPML / WCML / WoodMart / WCFM / VitePOS adapters (detection / Null stubs only; WoodMart adapter = Stage 7)
- FLAIROC Stage 6B variable ECR live verification (Stage 6A is local-only; both ECR flags default off)

### Hard invariants (already enforced in V1 code — preserve them)

- Server-side authority; no silent offer replacement
- Missing rate card or unresolved destination zone → **no free / $0 shipping**
- Customer surfaces show public labels and estimates only — never supplier, origin, logistics profile names, rate-card IDs, or selection hashes
- Snapshots use protected `_cetech_de_*` meta; HPOS via WooCommerce CRUD
- Flags default off; activation must not take over the storefront
- Deployments require Composer `vendor/` (or a packaged ZIP that includes it)

### Known V1 limitations

- Variable product FLAIROC cutover / WoodMart verification deferred (Stage 6B / 7)
- Mixed-cart line quotes may differ from WooCommerce shipping line total
- Classic checkout only
- Admin Stage 4 screens still contain technical wording (backlog before next RC)
- Do not promote RC beyond staging until `docs/V1-RC-SMOKE-TEST-CHECKLIST.md` passes

### Sensible next work after V1 RC

1. ~~Complete staging smoke checklist / Stage 0B~~ — **DONE (VERIFIED)**
2. ~~Post-RC architecture gap analysis (Stage 1)~~ — **DONE**
3. ~~Stage 2: Global configuration + scoped inheritance storage~~ — **DONE**
4. ~~Stage 3: EffectiveConfigurationResolver~~ — **DONE**
5. ~~Stage 4: Admin inheritance UX + effective configuration preview~~ — **DONE**
6. ~~Stage 5A/5B: Simple-product ECR runtime + FLAIROC verification~~ — **DONE**
7. ~~Stage 6A: Variable-product ECR support (local; flags OFF)~~ — **DONE**
8. ~~Stage 6B-1 package~~ — **READY**
9. ~~Stage 6B-2 flag-OFF FLAIROC install safety~~ — **PASS** (PHP-log window 28→33)
10. Stage 6B-2L final language package — human clean-install, then Stage 6B-3 variable QA live verification → WoodMart adapter → packages → shipments → integrations/Blocks → quality RC
9. Do not skip foundations to reach shipments early

### Agent orientation

- Prefer incremental, flag-gated changes matching existing `PHASE-*-IMPLEMENTATION.md` docs
- When this status block and later visionary sections diverge, **code + phase docs win for what exists**; this handoff still wins for product rules and V1 exclusions
- After coding: note changed files, privacy/shipping risks, and which smoke-checklist rows to re-run

---

## Complete AI Handoff — Part 1: Purpose, Scope, Principles, Terminology, and Customer Experience

## 1. Project identity and operating boundary

Build a reusable, production-grade WordPress/WooCommerce plugin for multiple similar but independently operated WooCommerce websites.

The plugin is a **delivery, fulfilment-choice, delivery-pricing, shipment-status, and tracking engine**. It is not merely a shipping-rate plugin and must not be reduced to a product-options add-on.

The same plugin package must work on:

* WooCommerce sites using WoodMart and a WoodMart child theme;
* sites with ordinary WooCommerce variable products;
* sites with or without WPML;
* sites with or without WPML Multilingual & Multicurrency for WooCommerce (WCML);
* sites that may use WCFM marketplace/vendor tooling;
* sites using caching and object caching such as WP Rocket and Redis;
* sites that may use POS tools such as VitePOS.

The plugin must remain independently installable and should not require WPML, WCML, WCFM, VitePOS, WoodMart, or any carrier API in order to activate. WooCommerce is the only hard dependency.

The plugin must not assume that all deployments share a database, supplier network, country, currency, or logistics policy. Each installation manages its own site-level settings, delivery zones, delivery offers, carriers, suppliers, products, and orders.

The project is intentionally limited to the discussion in this thread. Do not merge it with other apps, platforms, architectures, or unrelated business systems.

---

## 2. Core business problem

The business sells products that may be:

1. located outside the customer’s country and require international Air and/or Sea shipping;
2. physically available in a local store and eligible for delivery or store pickup;
3. located in a local warehouse and eligible for delivery only.

Each product or product variation may have different:

* delivery eligibility;
* available delivery route;
* carrier/service choices;
* delivery price;
* processing/dispatch time;
* transit time;
* final-mile delivery time;
* estimated doorstep delivery window;
* supplier;
* private origin;
* consolidation eligibility;
* shipment status;
* tracking link.

Customers must choose the applicable fulfilment method before adding each product to cart. The selected choice must remain attached to that specific cart line, remain correct after checkout, and become part of the resulting shipment record.

A customer may buy several products in one checkout and each product may have a different fulfilment path.

Example:

```text
Product A: international item
Customer selects Sea Shipping
Sea delivery fee: $25

Product B: international item
Customer selects Air Shipping
Air delivery fee: $95

Checkout:
Product subtotal: $119
Sea shipment: $25
Air shipment: $95
Total shipping: $120
Grand total before tax: $239
```

The customer pays once but the order may create multiple internal shipments.

---

## 3. Essential design decision

Do not model Air Shipping or Sea Shipping as WooCommerce product variations.

Shipping route is not a product characteristic such as colour, size, material, storage capacity, or style.

Do not create combinations such as:

```text
Colour × Size × Shipping Mode
```

That would multiply variations, complicate stock, confuse buyers, make imports difficult, and cause unnecessary maintenance.

Instead:

* existing product variations remain product variations;
* delivery is a separate fulfilment choice;
* the delivery choice is stored in cart-line metadata;
* the selected delivery choice determines the shipping package/rate;
* shipping remains a WooCommerce shipping charge, not a disguised product price increase.

The plugin must support product-level defaults and variation-level overrides.

A variation inherits its parent product’s delivery rules unless the variation has explicitly configured overrides.

---

## 4. Product fulfilment availability model

Every product or variation must have one **Fulfilment Availability** setting.

Use the exact customer-facing labels below where appropriate.

### 4.1 International fulfilment

Use when the product is outside the destination country or otherwise requires cross-border freight.

```text
Fulfilment Availability: International Fulfilment
Customer label: Delivery Only
Buyer fulfilment choice: Delivery, locked and preselected
Available delivery routes: Air Shipping and/or Sea Shipping
```

Rules:

* Store Pickup is unavailable.
* Local Delivery is unavailable unless the product later reaches a local warehouse and becomes a separate local delivery stage.
* The buyer must see only the Air and/or Sea offers valid for the product, variation, destination, and site configuration.
* If there is only one available offer, it is selected automatically.
* If there are multiple valid offers, the system may preselect an administrator-defined default, but the buyer can choose another eligible offer before Add to Cart.
* The final chosen Air or Sea offer must be visible in cart, checkout, order confirmation, My Account, staff order views, and shipment records.

Example:

```text
Delivery Only

Sea Shipping
Price: $25
Processing / dispatch: 3–6 business days
Main transit: 20–30 days
Estimated delivery to your address: 25–41 business days

Air Shipping
Price: $95
Processing / dispatch: 2–4 business days
Main transit: 7–15 days
Estimated delivery to your address: 9–22 business days
```

### 4.2 In Store

Use when the product is physically available in a store where the customer may collect it.

```text
Fulfilment Availability: In Store
Customer label: In Store
Default buyer choice: Delivery
Alternative buyer choice: Store Pickup
```

Rules:

* Delivery is selected by default.
* The buyer may switch to Store Pickup.
* When Store Pickup is selected:

  * delivery offers disappear;
  * delivery price becomes zero;
  * delivery route/carrier choice is cleared;
  * delivery times and doorstep estimate disappear;
  * the pickup store/location is shown;
  * the customer sees pickup readiness information instead of shipping information.
* The product must not create a delivery shipment when Pickup remains selected.
* Staff must be able to mark the pickup order as ready and then collected through normal operational status handling, without any proof-of-delivery/OTP requirement in Version 1.

Example:

```text
In Store — Accra Branch

● Delivery — GHS 60
○ Store Pickup — Free

When Store Pickup is selected:
Ready for pickup: within 1–2 business days
```

### 4.3 In Warehouse

Use when a product is physically in a warehouse but is not eligible for customer collection.

```text
Fulfilment Availability: In Warehouse
Customer label: In Warehouse
Buyer fulfilment choice: Delivery Only
```

Rules:

* Delivery is locked and preselected.
* Store Pickup is unavailable.
* Air and Sea are normally unavailable because the product is already in the local fulfilment country/market.
* The customer may see eligible local delivery offers, such as Standard Delivery, Scheduled Delivery, or Same-Day Express Courier.

Example:

```text
In Warehouse

Delivery Only

Standard Delivery
GHS 60
Estimated delivery: 1–3 business days

Same-Day Express Courier
GHS 120
Estimated delivery: today, approximately 2–6 hours
```

---

## 5. Delivery terminology: canonical vocabulary

Use consistent words across the customer interface, admin, reports, and code.

| Term                               | Meaning                                                                                                  |
| ---------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Fulfilment Availability            | Product’s broad fulfilment state: International Fulfilment, In Store, or In Warehouse                    |
| Fulfilment Choice                  | Customer’s top-level choice: Delivery or Store Pickup                                                    |
| Delivery Route / Mode              | Broad transport path: Air Shipping, Sea Shipping, Local Delivery, Store Pickup                           |
| Delivery Offer                     | The complete purchasable option presented to the buyer                                                   |
| Service Level                      | Standard, Economy, Express, Same-Day, Consolidated, Priority, etc.                                       |
| Carrier / Partner                  | The provider that performs all or part of transport, which may be named or CETECH-assigned               |
| Processing / Dispatch Time         | Time taken before the item is prepared and dispatched                                                    |
| Main Transit Time                  | Primary transport time, such as air, sea, road, or courier movement                                      |
| Final-mile Delivery Time           | Time between local arrival/dispatch and the buyer’s address                                              |
| Estimated Delivery to Your Address | The customer-facing promised date/range for arrival at the buyer’s address                               |
| Shipment                           | A distinct fulfilment unit within one WooCommerce order                                                  |
| Logistics Profile                  | Internal classification that determines handling, consolidation, route eligibility, and pricing behavior |
| Supplier / Origin                  | Private operational data; never displayed to customers                                                   |

Do not use “Arrival” as the main consumer-facing final estimate because customers may mistake it for airport arrival, port arrival, warehouse arrival, or arrival in the destination country.

The consumer-facing final label is:

```text
Estimated delivery to your address
```

---

## 6. Delivery offer model

A buyer must select a complete **Delivery Offer**, not merely a carrier name.

A Delivery Offer consists of:

```text
Route / mode
+ service level
+ carrier visibility rule
+ customer price
+ processing / dispatch range
+ transit range
+ final-mile range, where relevant
+ estimated doorstep delivery range
+ destination eligibility
+ product/variation eligibility
```

Examples:

```text
Air Economy
Carrier: CETECH-assigned freight partner
Price: USD 95
Processing: 2–4 business days
Transit: 7–15 business days
Estimated delivery to your address: 10–22 business days
```

```text
Sea Consolidated
Carrier: CETECH-assigned sea freight partner
Price: USD 25
Processing: 3–6 business days
Transit: 20–30 business days
Estimated delivery to your address: 25–41 business days
```

```text
Same-Day Express Courier
Carrier: CETECH-assigned courier
Price: GHS 120
Estimated delivery: today, approximately 2–6 hours
```

### Carrier visibility rule

Each delivery offer must support either:

1. **Named carrier visible to buyer**

```text
Uber Connect — GHS 120 — today, 2–5 hours
Bolt Express — GHS 105 — today, 2–6 hours
DHL Express — USD 95 — 7–12 business days
```

2. **Carrier assigned by the business**

```text
Same-Day Express Courier
Carrier assigned by the store
GHS 120
Estimated delivery: today
```

Use carrier-assigned wording when provider availability, item eligibility, routing, or actual operational assignment may vary.

Do not promise a named carrier when the business cannot reliably guarantee that carrier will perform the shipment.

---

## 7. Manual pricing policy

Version 1 does not require live carrier quotes, carrier APIs, quote requests, approval-before-payment flows, or provisional orders.

For any delivery offer, including Same-Day Express Courier, staff manually configure the estimated customer price in advance.

Example:

```text
Same-Day Express Courier
Customer checkout price: GHS 120
Estimated carrier cost: GHS 95
Operational margin/buffer: GHS 25
```

The customer sees and pays the configured checkout price normally.

If the actual later cost differs, the business bears the gain or loss. The customer must not receive an unknown delivery charge after payment.

This preserves normal WooCommerce checkout and avoids dependence on Uber, Bolt, Yango, courier, freight, or transport APIs in Version 1.

---

## 8. Delivery estimate rules

Every delivery offer should display three useful time concepts where applicable:

```text
Processing / dispatch
+ main transit
+ estimated delivery to your address
```

The estimated doorstep delivery range is calculated from:

```text
Payment confirmation date
+ processing / dispatch range
+ main transit range
+ final-mile delivery range
+ optional configured customs/operational buffer
```

The estimate should use configurable business-day/calendar-day logic and should account for site operating calendars where enabled.

The product page may show an estimate based on selected country or site-default destination.

Cart and checkout should refine it using the shipping address, destination zone, city, region, postcode, or other configured location rule.

Where a customer’s address changes the price or date, the system must recalculate before checkout payment and visibly explain the updated delivery offer.

---

## 9. Destination and pricing strategy

Do not manually configure every:

```text
Product × country × city × currency
```

combination.

Use a structured rate-card system.

The effective delivery offer is determined from:

```text
Product or variation
+ fulfilment availability
+ private supplier/origin where relevant
+ logistics profile
+ destination zone
+ delivery route
+ service level
+ carrier/partner rule
+ quantity/weight/volume or fixed-price plan
```

Destination structure should be layered:

```text
Country
→ state/region
→ city or metro area
→ postcode/area group
→ remote-area rule where needed
```

Only add geographical detail where it materially changes price, eligibility, or delivery time.

For example:

```text
Ghana
→ Greater Accra Central
→ Greater Accra Outer Areas
→ Ashanti / Kumasi
→ Other Regional Capitals
→ Remote Areas
```

or:

```text
United Kingdom
→ Mainland
→ Highlands / Islands
```

This is more maintainable than an individual rule for every address.

---

## 10. Logistics Profile: generic internal classification

Do not use electronics-specific labels as the primary delivery classification.

Use:

```text
CETECH Logistics Profile
```

or, on other deployments, a site-branded equivalent.

A Logistics Profile is internal operational data that determines how a product can be transported, handled, consolidated, priced, and restricted.

Possible profile dimensions:

```text
Parcel size:
Document
Small parcel
Standard parcel
Large parcel
Bulky item
Oversized item
Freight

Charge basis:
Fixed charge
Per item
Per cart line
Actual weight
Volumetric weight
Weight band
Manual rate

Handling:
Standard
Fragile
High-value
Liquid
Temperature-sensitive
Restricted goods
Special packing required

Route eligibility:
Air eligible
Sea eligible
Local delivery eligible
Pickup eligible

Consolidation:
May consolidate
Must ship separately
Limited consolidation

Dispatch type:
In stock
Supplier fulfilled
Made to order
Preorder
```

“Battery present,” “air-only,” or “sea-only” should be treated as route or handling restrictions, not as the main generic logistics-profile name.

Do not call this field “Shipping Class” because WooCommerce already has native Shipping Classes.

---

## 11. Private supplier and origin records

The plugin must record suppliers and origins internally because they are needed to determine:

* dispatch timing;
* available routes;
* shipment grouping;
* private internal freight cost;
* private fulfilment instructions;
* supplier accountability;
* staff workflow;
* tracking and shipment assignment.

However, suppliers and origins must never be shown to customers.

Do not expose supplier/origin data in:

* product pages;
* cart;
* checkout;
* order emails;
* customer My Account pages;
* tracking links;
* public order notes;
* structured-data/SEO output;
* Google feeds;
* public REST/Store API responses;
* product exports intended for customers.

Customers see only delivery-relevant information:

```text
Air Shipping
Estimated delivery to your address: 10–22 business days
Tracking available after dispatch
```

They do not see where the item originates, who supplies it, or the internal routing decision.

---

## 12. First-version exclusions

The following are explicitly excluded from Version 1:

```text
Buyer receipt-confirmation buttons
OTP delivery confirmation
QR delivery confirmation
GPS capture
Driver applications/accounts
Delivery photos
Signatures
Proof-of-delivery workflows
Automatic delivery confirmation
Buyer reminder campaigns
Automatic order completion from delivery events
Live carrier quotes
Carrier API dispatching
Carrier API tracking synchronization
Post-payment quote approval
Unknown-price delivery flows
```

Version 1 uses simple staff/carrier-managed shipment statuses and optional manually entered tracking links.

The customer can view shipment progress but is not required to confirm receipt.

**V1 release-candidate operations:** see [V1-RC-FLAG-MATRIX.md](V1-RC-FLAG-MATRIX.md) and [V1-RC-SMOKE-TEST-CHECKLIST.md](V1-RC-SMOKE-TEST-CHECKLIST.md) for feature-flag enablement order and staging smoke tests. Shipment records, tracking timelines, and carrier integrations are **not enabled** in V1 RC.

---

## 13. Site and implementation reality

The supplied examples show WooCommerce 10.9.3, WoodMart 8.5.4, child themes, Redis object caching, WP Rocket, classic WooCommerce cart/checkout/my-account pages, and HPOS enabled. One supplied site includes active WPML CMS, WPML String Translation, and WPML Multilingual & Multicurrency for WooCommerce; another does not.
The plugin must therefore use:

* native WooCommerce APIs;
* HPOS-compatible WooCommerce CRUD;
* standard hooks and filters;
* optional integrations detected at runtime;
* a no-WPML/no-WCML fallback;
* cache-safe cart, checkout, and My Account behavior.

It must not depend on editing WoodMart parent-theme files or on a chain of unrelated delivery plugins.

# Reusable WooCommerce Delivery & Fulfilment Engine

## Complete AI Handoff — Part 2: Architecture, Data Model, Administration, WooCommerce Integration, and Frontend Behavior

## 14. Architectural position

Build this as one modular WordPress plugin with WooCommerce as its transactional commerce owner.

The plugin is not a replacement cart, checkout, payment, order, tax, inventory, supplier marketplace, or accounting system. It adds a structured delivery-and-fulfilment layer to WooCommerce.

WooCommerce remains authoritative for:

* products and variations;
* cart totals;
* checkout;
* payments;
* coupons;
* taxes;
* customer accounts;
* order records;
* order items;
* stock;
* payment status;
* standard order emails.

The Delivery & Fulfilment Engine becomes authoritative for:

* fulfilment availability;
* delivery-only, in-store, and in-warehouse rules;
* delivery offers;
* Air, Sea, Local Delivery, and Store Pickup eligibility;
* private supplier and origin records;
* logistics profiles;
* destination service zones;
* rate cards and manual customer-facing delivery prices;
* delivery estimates;
* shipment grouping;
* shipment records;
* shipment status history;
* manual tracking links;
* customer-facing shipment timeline data;
* optional integrations with WPML, WCML, WCFM, VitePOS, WoodMart, and other supported tools.

The plugin must be built as a modular monolith. Each module must have a clear responsibility, public internal contract, and limited dependencies so that future integrations or larger replacement systems can be added without rewriting the core.

The plugin must be installable on several similar WooCommerce sites without assuming that every site has WPML, WCML, WoodMart, WCFM, VitePOS, the same currencies, the same countries, the same products, or the same logistics partners.

WooCommerce is the only hard dependency.

---

# 15. Module map

The plugin should be organized into the following modules.

```text
delivery-engine/
├── Core
├── Product Rules
├── Delivery Offers
├── Destination Zones
├── Logistics Profiles
├── Supplier and Origin Registry
├── Pricing and Consolidation
├── Delivery-Date Estimator
├── Cart and Checkout Adapter
├── WooCommerce Shipping Adapter
├── Shipment Management
├── Tracking
├── Customer Account Adapter
├── Admin Console
├── Integrations
│   ├── WoodMart
│   ├── WPML
│   ├── WCML
│   ├── WCFM
│   ├── VitePOS
│   └── WooCommerce Blocks
├── Security and Capabilities
├── Audit and Diagnostics
└── Migration and Import Tools
```

## 15.1 Core module

The Core module provides:

* plugin bootstrap and dependency checks;
* versioned schema installation and upgrades;
* feature flags;
* shared value objects;
* capability registration;
* service container or dependency wiring;
* logging;
* error boundaries;
* cache invalidation;
* safe deactivation behavior;
* plugin health checks.

The Core module must not contain customer-facing delivery rules or theme-specific rendering.

It should provide neutral contracts such as:

```text
DeliveryOfferResolver
DeliveryEligibilityResolver
RateCalculator
ShipmentPlanner
ShipmentRepository
EstimateCalculator
CurrencyAdapter
TranslationAdapter
ThemePresentationAdapter
```

---

## 15.2 Product Rules module

The Product Rules module determines what a customer can select for a product or variation.

Every product or variation must have a delivery rule based on:

```text
Fulfilment Availability
+ Logistics Profile
+ Fulfilment Source
+ eligible delivery offers
+ optional variation override
```

The product-level rule is the default.

A variation may override any field that genuinely differs, such as:

* fulfilment availability;
* logistics profile;
* supplier/source;
* route eligibility;
* delivery offer eligibility;
* product-specific processing range;
* product-specific delivery price override;
* product-specific handling restriction;
* consolidation restriction.

Do not duplicate every parent setting into each variation unnecessarily.

The system must use an inheritance model:

```text
Variation rule absent
→ inherit parent product rule

Variation rule present
→ override only the configured fields
→ inherit all other parent product rule values
```

---

## 15.3 Delivery Offers module

A Delivery Offer is the customer-selectable delivery service.

A Delivery Offer must support:

```text
Offer ID
Internal name
Customer label
Delivery route
Service level
Carrier visibility rule
Named carrier, if applicable
Customer-facing description
Enabled/disabled status
Eligible fulfilment availability types
Eligible logistics profiles
Destination-zone eligibility
Processing minimum/maximum
Transit minimum/maximum
Final-mile minimum/maximum
Business-day or calendar-day rules
Customer price plan
Tax behavior
Estimated delivery calculation rules
Default/preselected status
Display priority
Public translation strings
Private internal notes
```

Examples:

```text
Sea Consolidated
Route: Sea
Service level: Consolidated
Carrier: assigned by store
Price plan: fixed per shipment
Processing: 3–6 business days
Transit: 20–30 business days
Final-mile: 2–5 business days
```

```text
Air Express — DHL
Route: Air
Service level: Express
Named carrier: DHL
Price plan: fixed per item
Processing: 2–4 business days
Transit: 7–12 business days
Final-mile: 1–3 business days
```

```text
Same-Day Express Courier
Route: Local Delivery
Service level: Same Day
Carrier: assigned by store
Price plan: fixed per shipment
Estimated delivery: 2–6 hours
```

Delivery Offers must be reusable. A product should normally point to an eligible set of existing offers rather than require staff to recreate “Air Express” or “Sea Consolidated” for every product.

Product-specific overrides remain available for exceptions.

---

## 15.4 Destination Zones module

Destination Zones define where an offer is valid and how it is priced or estimated.

A zone may be determined by:

```text
Country
State / province / region
City
Postal code prefix/range
Specific neighbourhood/group
Remote-area rule
```

Each site may structure zones differently.

Examples:

```text
Ghana
├── Greater Accra Central
├── Greater Accra Outer Areas
├── Ashanti / Kumasi
├── Other Regional Capitals
└── Remote Areas
```

```text
United States
├── Mainland Standard
├── Major Metro
├── Alaska / Hawaii
└── Remote / Extended Areas
```

A destination zone does not itself determine the price. It provides the location context used by the Rate Card and Delivery Estimate modules.

The site administrator must be able to set:

* zone name;
* public label if shown;
* priority;
* country;
* region/state rules;
* city rules;
* postal-code patterns;
* fallback behavior;
* remote-area flags;
* enabled/disabled status.

Zones must be evaluated from most specific to least specific.

```text
Exact postcode rule
→ city rule
→ region rule
→ country rule
→ fallback global zone
```

If no valid zone is found, the product must not silently receive a wrong rate. The plugin must either:

* use a configured fallback delivery offer; or
* block checkout for that delivery offer with a clear message.

---

## 15.5 Logistics Profiles module

The Logistics Profiles module provides generic internal classification for transport and fulfilment.

The customer does not need to see the profile name unless a product-specific disclosure is required.

A profile may define:

```text
Parcel size classification
Actual weight behavior
Volumetric-weight behavior
Default charge basis
Handling requirements
Route restrictions
Consolidation behavior
Packing requirement
Dispatch type
High-value flag
Special-delivery requirement
```

Example profiles:

```text
Small Parcel
Standard Parcel
Large Parcel
Bulky Item
Oversized Freight
Fragile Goods
High-Value Goods
Restricted Goods
Temperature-Sensitive Goods
Made-to-Order Item
```

A Logistics Profile must not assume that every item is an electronic product.

It is a general operational rule set.

---

## 15.6 Supplier and Origin Registry module

Suppliers and origins are private internal records.

The plugin must allow staff to create supplier and origin records such as:

```text
Supplier
Supplier code
Internal contact details
Operational notes
Active/inactive status
Default origin
Default dispatch rules
Internal service restrictions
```

```text
Origin
Origin code
Country
City/region
Warehouse/source type
Dispatch calendar
Internal handling notes
```

The public product page must never display supplier or origin information.

The plugin must enforce this at several layers:

* do not render it in frontend templates;
* do not include it in public API responses;
* do not include it in product structured data;
* do not include it in customer emails;
* do not include it in customer-visible order notes;
* do not include it in export formats intended for customers;
* restrict visibility through user capabilities.

The system must use supplier/origin information to determine:

* eligible offers;
* private consolidation keys;
* dispatch windows;
* shipment planning;
* internal staff views;
* private cost calculations.

---

## 15.7 Pricing and Consolidation module

This module calculates customer-facing delivery charges.

It must support the following charge plans:

```text
Fixed fee per shipment
Fixed fee per item
Fixed fee per cart line
Base fee + additional-item fee
Base fee + actual-weight increments
Base fee + volumetric-weight increments
Weight-band pricing
Highest eligible item fee
Product/variation override
Zone surcharge
Remote-area surcharge
Free-delivery threshold
Manual fixed rate
```

A rate card should be selected based on:

```text
Delivery offer
+ destination zone
+ logistics profile
+ supplier/origin where needed
+ product/variation override
+ quantity
+ weight or volume where applicable
```

Example rate card:

```text
Offer: Air Economy
Destination: Greater Accra Central
Profile: Standard Parcel
Charge type: Base + 0.5 kg increments

Base fee: GHS 500
Included weight: 0.5 kg
Additional 0.5 kg: GHS 120
Remote surcharge: GHS 75
```

Example fixed rate:

```text
Offer: Same-Day Express Courier
Destination: Greater Accra Central
Profile: Small Parcel
Charge type: Fixed per shipment
Customer price: GHS 120
```

The customer price is entered manually in Version 1. There is no requirement to call Uber, Bolt, Yango, DHL, sea-freight, road-transport, or courier APIs for a live quote.

## Consolidation rules

Items may consolidate only when they meet a configured consolidation key.

A default consolidation key should include:

```text
Same supplier/source
Same private origin
Same delivery route
Same service level
Same carrier/partner rule
Same destination zone
Same dispatch window
Compatible logistics profile
No separate-shipment restriction
```

Items must not consolidate when they differ in a way that makes a combined shipment invalid or misleading.

Examples:

```text
Air and Sea must never consolidate.

Different suppliers may not consolidate by default.

Different private origins may not consolidate by default.

Items with separate dispatch windows may not consolidate.

Restricted, oversized, fragile, or special-handling items may require their own shipment.

Store Pickup must never combine with delivery.

A local-delivery product may not combine with an international shipment unless the business explicitly configures that behavior.
```

The system must calculate shipping per actual shipment group, not merely per full cart.

---

## 15.8 Delivery-Date Estimator module

This module calculates the customer-facing estimated delivery-to-address range.

The estimate is based on:

```text
Order/payment date
+ product or offer processing range
+ transit range
+ final-mile range
+ optional customs buffer
+ optional destination-zone buffer
+ business calendar rules
```

The estimator must support:

* business days;
* calendar days;
* public holiday exclusions where configured;
* operating-day exclusions;
* cutoff-time behavior;
* timezone-aware calculation;
* manual estimate text for offers such as same-day delivery;
* address-based recalculation.

The output should be stored as a snapshot at checkout.

Example:

```text
Processing: 2–4 business days
Transit: 7–15 business days
Final mile: 1–3 business days

Estimated delivery to your address:
10–22 business days after payment confirmation
```

After the order is paid, the system should save the original estimate into the shipment record. Future changes to product rules or rate cards must not rewrite historical shipment promises.

---

# 16. Data model

## 16.1 General storage principle

Use WooCommerce’s own entities for commerce data and dedicated custom plugin tables for delivery-domain data.

Do not overload `wp_postmeta` with large shipment histories, complex rate-card structures, or frequently queried operational records.

Do not write directly to legacy WooCommerce order post tables.

WooCommerce HPOS is enabled on the supplied sites, so all WooCommerce order access must use WooCommerce CRUD APIs.

Use the site-specific WordPress database prefix rather than assuming `wp_`.

Recommended plugin table naming pattern:

```text
{$wpdb->prefix}delivery_engine_*
```

Suggested tables:

```text
delivery_engine_delivery_offers
delivery_engine_destination_zones
delivery_engine_destination_rules
delivery_engine_logistics_profiles
delivery_engine_suppliers
delivery_engine_origins
delivery_engine_rate_cards
delivery_engine_rate_card_rules
delivery_engine_product_rules
delivery_engine_shipments
delivery_engine_shipment_items
delivery_engine_shipment_events
delivery_engine_audit_log
```

The plugin must create database indexes for frequently queried fields such as:

```text
product_id
variation_id
order_id
shipment_id
supplier_id
origin_id
delivery_offer_id
destination_zone_id
status
created_at
updated_at
```

## 16.2 Product rule record

A product or variation rule should include:

```text
product_id
variation_id nullable
fulfilment_availability
logistics_profile_id
supplier_id nullable
origin_id nullable
delivery_only flag
store_pickup_allowed flag
delivery_default_selected flag
pickup_location_id nullable
eligible_offer_ids
default_offer_id nullable
processing override nullable
transit override nullable
final_mile override nullable
price override rule nullable
consolidation override nullable
active flag
version
```

Use product/variation metadata only as a lightweight pointer or cache to the canonical rule record where useful.

## 16.3 Delivery offer record

```text
id
internal_code
internal_name
public_label
route
service_level
carrier_visibility
carrier_name nullable
public_description
tax_class
price_basis
default_processing_min
default_processing_max
default_transit_min
default_transit_max
default_final_mile_min
default_final_mile_max
duration_unit
display_priority
enabled
created_at
updated_at
```

## 16.4 Rate-card record

```text
id
delivery_offer_id
destination_zone_id
logistics_profile_id nullable
supplier_id nullable
origin_id nullable
charge_method
base_amount
included_weight
increment_weight
increment_amount
per_item_amount
per_line_amount
highest_fee_mode
remote_surcharge
free_shipping_threshold
base_currency
manual_currency_override_data nullable
enabled
priority
effective_from nullable
effective_to nullable
```

## 16.5 Shipment record

Each internal shipment must contain:

```text
id
order_id
shipment_number
status
supplier_id private
origin_id private
delivery_offer_id
route
service_level
carrier_name public_or_private
destination_snapshot
shipping_address_snapshot
estimate_snapshot
currency_snapshot
customer_shipping_charge_snapshot
internal_cost_snapshot nullable
tracking_number nullable
tracking_url nullable
dispatch_date nullable
delivered_date nullable
created_at
updated_at
```

A shipment may have multiple shipment items.

## 16.6 Shipment-item record

```text
shipment_id
order_id
order_item_id
product_id
variation_id nullable
quantity
product_name_snapshot
selected_delivery_offer_snapshot
logistics_profile_snapshot
fulfilment_availability_snapshot
```

Snapshots are essential. The system must preserve what the customer bought and what was promised, even if product data changes later.

## 16.7 Shipment-event record

Version 1 should support manual status/event history.

```text
id
shipment_id
event_type
public_label
internal_note nullable
public_note nullable
actor_user_id nullable
source
event_at
created_at
```

Example events:

```text
Shipment created
Awaiting fulfilment
Processing
Dispatched
In transit
Delayed / issue
Delivered
Cancelled
Tracking link added
Estimated delivery updated
```

No proof-of-delivery evidence fields are required in Version 1.

## 16.8 Audit log

The plugin must log important administrative changes.

Audit events should include:

```text
actor
action
entity type
entity ID
previous value summary
new value summary
timestamp
site context
```

Audit only the changes required for accountability, troubleshooting, and safe operations.

Examples:

```text
Rate card changed
Delivery offer disabled
Product rule changed
Supplier reassigned
Shipment manually marked dispatched
Tracking URL changed
Estimate changed after staff review
```

---

# 17. Order, cart, and shipment lifecycle

## 17.1 Product-page selection

The customer selects:

1. existing product variation, where applicable;
2. fulfilment choice where a choice exists;
3. delivery offer where delivery is selected and more than one offer is available.

For an international product:

```text
Delivery Only
→ buyer cannot choose pickup
→ buyer selects Air or Sea if both are available
```

For an in-store product:

```text
Default: Delivery
→ buyer may switch to Store Pickup
```

For an in-warehouse product:

```text
Delivery Only
→ buyer sees eligible local delivery offers
```

The selection must be validated before Add to Cart.

## 17.2 Cart-item data

When a customer adds a product to cart, store the delivery selection in cart-item data.

The cart line must include a stable delivery-selection fingerprint containing:

```text
fulfilment availability
fulfilment choice
delivery offer ID
delivery route
service level
carrier visibility
destination context
delivery price snapshot
estimate snapshot
product/variation rule version
```

The fingerprint must participate in the cart-item key.

This prevents WooCommerce from incorrectly merging two identical products with different fulfilment choices.

Example:

```text
Product A + Sea Shipping
Product A + Air Shipping
```

These must remain separate cart lines.

## 17.3 Revalidation

The delivery selection must be revalidated whenever relevant data changes:

```text
Customer changes product variation
Customer changes quantity
Customer changes fulfilment choice
Customer changes delivery offer
Customer changes destination
Customer changes cart contents
Customer applies an eligible/ineligible coupon
Customer returns to checkout
Product stock or offer configuration changes before payment
```

If a selected offer is no longer valid, the system must not silently replace it with a different offer.

It should show a clear message such as:

```text
The selected Air Express option is no longer available for this address.
Please choose one of the available delivery options before checkout.
```

## 17.4 Shipping packages

The plugin must split WooCommerce shipping packages according to actual shipment groups.

A package should represent one real delivery grouping.

Example:

```text
Cart line 1:
Product A
Sea Shipping
Supplier A
Shipment Group 1

Cart line 2:
Product B
Air Shipping
Supplier B
Shipment Group 2
```

The plugin creates two shipping packages.

WooCommerce then receives one correct custom shipping rate for each package.

The checkout must show the actual selected service without allowing unrelated flat-rate methods to appear for the same managed package.

## 17.5 Custom shipping method

Register one custom WooCommerce shipping method, for example:

```text
Delivery Engine Selected Offer
```

The custom shipping method should calculate and expose only the rate corresponding to the selected delivery offer for each package.

Do not create generic zero-cost placeholder methods such as:

```text
Air Shipping — GHS 0
Sea Shipping — GHS 0
```

Do not add a delivery price as an arbitrary product add-on while separately charging shipping again.

The delivery cost must appear as a genuine WooCommerce shipping charge.

## 17.6 Checkout

At checkout:

* the customer sees each shipment/package;
* the selected delivery route/service is preserved;
* the customer sees the corresponding shipping cost;
* the customer sees estimated delivery-to-address information;
* the total includes all selected shipment charges;
* the customer does not have to reselect the service unless a destination change invalidates it;
* private supplier/origin details remain hidden.

Example:

```text
Shipment 1
Sea Shipping
Product A
Shipping: USD 25
Estimated delivery to your address: 25–41 business days

Shipment 2
Air Shipping
Product B
Shipping: USD 95
Estimated delivery to your address: 10–22 business days
```

## 17.7 Order creation

When checkout succeeds, create:

* normal WooCommerce order;
* normal WooCommerce order items;
* normal WooCommerce shipping lines;
* delivery-engine shipment records;
* shipment-item records;
* immutable delivery/price/estimate snapshots.

The customer may pay once, while the order contains several internal shipments.

## 17.8 Post-order status

Version 1 shipment statuses:

```text
Awaiting fulfilment
Processing
Dispatched
In transit
Delivered
Delayed / issue
Cancelled
```

The parent WooCommerce order should not be automatically completed simply because one shipment is marked Delivered.

Recommended order status behavior:

```text
Paid order with no dispatch:
Processing

Some shipments dispatched or delivered, others pending:
Processing or a configurable custom Partially Shipped status

All active shipments delivered or collected:
Completed, if staff policy permits

Cancelled/refunded shipment:
Follow standard WooCommerce cancellation/refund policy
```

Do not implement buyer receipt confirmation, OTP, QR, GPS, signatures, delivery photos, driver applications, or automatic delivery completion in Version 1.

---

# 18. Administrative interface

The plugin should create a top-level WordPress admin menu:

```text
Delivery Engine
```

Suggested submenus:

```text
Dashboard
Shipments
Delivery Offers
Destination Zones
Rate Cards
Logistics Profiles
Suppliers & Origins
Product Rules
Pickup Locations
Integrations
Import / Export
Logs & Diagnostics
Settings
```

## 18.1 Delivery Engine dashboard

The dashboard should show operational summaries:

```text
Awaiting fulfilment shipments
Processing shipments
Dispatched shipments
Delayed/issue shipments
Delivered shipments
Shipments with no tracking link
Offers with missing rate cards
Products with incomplete delivery rules
Products with invalid variation overrides
Destination zones with no fallback rate
```

Avoid building a heavy analytics platform in Version 1.

The dashboard should be actionable, not decorative.

## 18.2 Delivery Offers admin

Staff can create and edit reusable offers.

Required fields:

```text
Internal name
Customer label
Route
Service level
Carrier visibility
Named carrier, if shown
Description
Default processing/transit/final-mile windows
Duration unit
Enabled/disabled
Display priority
Tax rule
Eligible fulfilment availability
```

## 18.3 Destination Zones admin

Staff can configure:

```text
Country
State/region
City
Postal-code patterns
Remote area flags
Priority
Fallback rule
Enabled/disabled
```

The interface must clearly show which rules win when multiple zones overlap.

## 18.4 Rate Cards admin

Staff can configure a rate card by selecting:

```text
Delivery offer
Destination zone
Logistics profile
Supplier/origin if needed
Charge method
Base amount
Additional charges
Currency
Currency overrides where WCML is active
Effective dates
Priority
```

The interface should include a “Test this rate” tool.

Example test:

```text
Product: Product A
Variation: Red / Large
Destination: Greater Accra Central
Quantity: 2
Selected offer: Air Economy

Expected shipping:
GHS 740

Expected estimated delivery:
10–22 business days
```

## 18.5 Supplier and Origin admin

These records must be private.

Only authorized roles should access:

```text
Supplier code
Supplier name
Private contact data
Origin code
Origin country/city
Fulfilment notes
Private costs
Internal restrictions
```

Do not expose supplier/origin information to vendor-facing tools by default.

## 18.6 Product edit panel

Add a **Delivery & Fulfilment** panel to WooCommerce product editing.

For parent products:

```text
Fulfilment Availability
Logistics Profile
Supplier
Origin
Delivery Only / Pickup eligibility
Eligible Delivery Offers
Default Delivery Offer
Processing override
Transit override
Final-mile override
Consolidation override
Internal notes
```

For variations:

```text
Use parent delivery rule toggle
Override availability
Override logistics profile
Override supplier/origin
Override eligible offers
Override price/estimate rule
Override restrictions
```

The interface must warn administrators when a product rule is incomplete.

Example:

```text
This product is marked International Fulfilment but has no eligible Air or Sea delivery offer.
Customers will be unable to add it to cart.
```

## 18.7 Bulk editing and import

Because stores may have thousands of products and variations, the plugin needs:

```text
Bulk edit by selected products
Bulk edit by category
Bulk assign logistics profile
Bulk assign fulfilment availability
Bulk assign delivery offer
Bulk assign supplier/origin
CSV import/export
Dry-run validation
Error report download
```

No bulk update should silently overwrite variation-specific overrides unless the administrator explicitly chooses that action.

## 18.8 Shipment workspace

The Shipment workspace should allow staff to:

```text
View shipment details
See grouped order items
View non-public supplier/origin data
View selected delivery offer
View customer-facing estimate
Set shipment status
Add public shipment notes
Add private staff notes
Add tracking number
Add tracking URL
Change delivery estimate with a reason
Split a shipment where operationally necessary
Merge shipments only where valid
Open the linked WooCommerce order
```

A staff member must not change the customer-paid delivery price after payment through the shipment workspace.

Any internal cost correction remains private and does not change what the customer paid.

---

# 19. User roles and permissions

Create granular WordPress capabilities rather than relying only on broad administrator permissions.

Suggested capabilities:

```text
manage_delivery_settings
manage_delivery_offers
manage_delivery_rate_cards
manage_delivery_zones
manage_logistics_profiles
manage_private_sources
manage_product_delivery_rules
manage_shipments
update_shipment_status
view_private_delivery_costs
view_private_origins
manage_delivery_integrations
view_delivery_logs
import_delivery_data
```

Example role policies:

```text
Administrator:
All capabilities

Shop Manager:
Most delivery configuration and shipments, except highly restricted private supplier/cost settings if desired

Logistics Manager:
Shipments, suppliers/origins, rate cards, estimates, delivery offers

Product Manager:
Product delivery rules, but not private supplier cost data

Customer Service:
View shipment status and tracking; add limited public customer notes; no rate-card changes

Vendor/WCFM Seller:
No access by default to private supplier/origin records, internal costs, or global rate cards
```

WCFM integration must be opt-in.

Do not automatically expose delivery configuration to marketplace vendors.

---

# 20. Frontend architecture and behavior

## 20.1 Rendering principle

The plugin must use standard WooCommerce hooks and JavaScript events.

It must not require modifications to WoodMart parent-theme templates.

Preferred hooks include:

```text
woocommerce_before_variations_form
woocommerce_before_add_to_cart_button
woocommerce_after_add_to_cart_button
woocommerce_get_item_data
woocommerce_cart_item_name
woocommerce_review_order_before_shipping
woocommerce_order_item_meta_end
```

The plugin should render through a presentation adapter.

The WoodMart adapter should only handle layout/styling compatibility where necessary.

The business rules must remain theme-independent.

## 20.2 Variable products

For variable products, the delivery selector must react to the currently selected variation.

Required behavior:

```text
Customer selects variation
→ plugin resolves inherited/overridden delivery rules
→ eligible delivery offers appear
→ invalid offers disappear
→ selected/default offer is validated
→ displayed delivery price and estimate update
→ Add to Cart is enabled only when all required choices are valid
```

The plugin must handle:

* variation swatches;
* dropdown selectors;
* unavailable variations;
* variation price changes;
* variation stock changes;
* quick view;
* AJAX add-to-cart where supported;
* mini-cart rendering.

## 20.3 Product-page customer flow

### International Fulfilment product

```text
Delivery Only

Choose delivery method

○ Sea Shipping
  GHS 350
  Processing: 3–6 business days
  Main transit: 20–30 business days
  Estimated delivery to your address: 25–41 business days

○ Air Shipping
  GHS 900
  Processing: 2–4 business days
  Main transit: 7–15 business days
  Estimated delivery to your address: 10–22 business days
```

If only one offer is valid:

```text
Delivery Only

Sea Shipping
GHS 350
Estimated delivery to your address: 25–41 business days
```

No unnecessary radio control is needed where no alternative exists.

### In Store product

```text
In Store — [Store Location]

● Delivery
  GHS 60
  Estimated delivery: 1–3 business days

○ Store Pickup
  Free
  Ready for pickup: within 1–2 business days
```

When Store Pickup is chosen:

* delivery price disappears;
* delivery offer cards disappear;
* shipping package metadata is removed;
* pickup instructions appear;
* the cart line records Pickup as the fulfilment choice.

### In Warehouse product

```text
In Warehouse

Delivery Only

○ Standard Delivery
  GHS 60
  Estimated delivery: 1–3 business days

○ Same-Day Express Courier
  GHS 120
  Estimated delivery: today, 2–6 hours
```

## 20.4 Destination selection

The customer may initially see delivery offers based on:

```text
Selected country
Saved account address
Current cart destination
Site default destination
```

The site should not rely on IP address as the authoritative delivery location.

The shipping address entered at cart or checkout is authoritative.

If the delivery price/estimate changes because the confirmed address belongs to another zone, the interface must state the change clearly before payment.

Example:

```text
Your delivery option has been updated for your address.

Previous estimate:
GHS 60, 1–3 business days

Updated estimate:
GHS 85, 2–4 business days
```

## 20.5 Cart

The cart should show delivery data below each relevant item:

```text
Delivery: Sea Shipping
Shipping charge: USD 25
Estimated delivery to your address: 25–41 business days
```

For pickup:

```text
Fulfilment: Store Pickup
Pickup location: Accra Branch
Pickup charge: Free
```

Customers should be able to use a **Change delivery option** control in cart only where the product remains eligible and the recalculation can be completed safely.

Changing an option must:

* revalidate product/variation rules;
* regenerate shipping packages;
* recalculate shipping;
* update estimates;
* preserve the choice in cart data;
* not expose private supplier/origin data.

## 20.6 Checkout

Checkout should show shipment-grouped delivery information.

The customer must see:

```text
Shipment/service label
Items in the shipment
Shipping cost
Estimated delivery to address
Tracking availability statement where relevant
```

No unrelated default WooCommerce flat-rate methods should appear for a package governed by the delivery engine.

If a product is pickup-only or pickup-selected, shipping methods for that package must not appear.

## 20.7 My Account

Add a customer-facing shipment view under:

```text
My Account → Orders → View Order
```

Optionally add:

```text
My Account → Deliveries
```

Each shipment card should show:

```text
Shipment number
Items in shipment
Delivery service
Status
Estimated delivery window
Tracking number/link when provided
Public delay/update notes when provided
```

Example:

```text
Shipment 1 — Sea Shipping
Status: In transit
Estimated delivery: 25 August–8 September
Tracking: Track shipment

Shipment 2 — Air Shipping
Status: Processing
Estimated delivery: 12–20 August
```

The customer must not see supplier, origin, internal margin, internal costs, or private fulfilment notes.

---

# 21. Optional integration architecture

## 21.1 WPML adapter

WPML integration must be optional.

At runtime, detect WPML safely. If absent, the plugin must continue with one site language.

When WPML is present:

* register customer-facing plugin strings for translation;
* configure appropriate custom-field behavior;
* copy canonical product delivery rules to translations;
* preserve one source of truth for operational data;
* render translated labels based on the current language.

Translate:

```text
Customer labels
Offer descriptions
Carrier/service descriptions
Delivery route labels
Pickup instructions
Public shipment status labels
Public delay notes
```

Copy rather than translate:

```text
Supplier ID
Origin ID
Logistics profile ID
Rate-card IDs
Price formulas
Eligibility rules
Internal notes
Consolidation rules
Operational flags
```

## 21.2 WCML adapter

WCML integration must also be optional.

Without WCML:

```text
Use WooCommerce base currency.
```

With WCML:

```text
Store canonical amount in base currency.
Convert for display/payment using WCML.
Allow manual per-currency overrides where configured.
Snapshot original base amount, checkout amount, checkout currency, and conversion context at checkout.
```

Example order snapshot:

```text
Base delivery price: USD 95
Checkout currency: GHS
Charged delivery amount: GHS [locked amount]
Delivery offer: Air Express
```

Historical orders must never recalculate later because exchange rates changed.

## 21.3 WoodMart adapter

WoodMart integration must be optional but supported.

The adapter should:

* apply WoodMart-compatible classes;
* support product page layouts;
* support variation swatches;
* support quick view after testing;
* support mini-cart rendering;
* avoid modifying WoodMart parent templates;
* use hooks and small scripts rather than template replacement.

Because WoodMart overrides WooCommerce’s variable-product template and the supplied status reports that override as outdated relative to WooCommerce core, template compatibility must be fixed or validated on staging before deploying complex variable-product delivery selectors.

The delivery engine must not itself depend on editing that template.

## 21.4 WCFM adapter

WCFM integration must be optional.

By default:

* do not expose private supplier/origin records;
* do not expose global rate cards;
* do not expose internal costs;
* do not allow vendors to alter site-wide delivery logic.

If the business later chooses to let vendors manage parts of fulfilment, add a separately designed permission model rather than exposing the full delivery engine automatically.

## 21.5 VitePOS adapter

VitePOS integration must be optional.

Potential Version 1 behavior:

```text
POS-created orders can mark products as Store Pickup, Delivery, or other supported fulfilment types.
POS staff can view delivery selection and shipment status.
POS must not create invalid shipping charges.
```

Do not assume that all online-only delivery selectors appear identically in POS without dedicated testing.

## 21.6 WooCommerce Blocks adapter

The current supplied sites use classic shortcode-based cart, checkout, and My Account pages.

Version 1 must fully support classic WooCommerce pages.

A separate Blocks adapter should be built and tested before declaring support for Cart/Checkout Blocks.

The core domain model must remain independent of either rendering model.

---

# 22. Caching, security, and performance rules

## 22.1 Caching

Dynamic delivery results must never be served from one customer’s session to another.

Exclude from full-page caching:

```text
Cart
Checkout
My Account
Delivery selection AJAX endpoints
Delivery estimate endpoints
Tracking/status pages requiring authentication
```

Use WooCommerce session/customer data correctly.

Redis may cache reusable configuration data such as:

```text
Delivery offers
Rate cards
Zones
Logistics profiles
```

But invalidate relevant cache keys immediately when staff changes:

```text
Rate cards
Product rules
Delivery offers
Zone definitions
Supplier/origin dispatch rules
Currency overrides
```

Never cache a customer-specific quote without a key that includes the relevant cart, product, destination, and currency context.

## 22.2 Security

The plugin must:

* use WordPress nonces for admin and AJAX actions;
* use capability checks for every sensitive action;
* sanitize and validate all fields;
* escape all output;
* avoid exposing private source data in REST responses;
* use WooCommerce CRUD for orders;
* support HPOS;
* maintain immutable order/shipment snapshots;
* log sensitive configuration changes;
* never trust frontend-submitted delivery prices;
* recalculate prices server-side;
* never trust frontend-submitted supplier/origin IDs;
* validate that selected delivery offers belong to the actual product/variation and destination.

## 22.3 Performance

Avoid calculating every possible product/zone/offer combination on page load.

Use progressive resolution:

```text
Initial page:
Resolve product + selected variation + known country/default zone.

Cart:
Resolve selected cart item + current destination.

Checkout:
Resolve selected cart item + full shipping address + current currency.
```

Use lazy loading for large admin lists and indexed queries for shipment/search screens.

---

# 23. Error handling and operational safeguards

The plugin must fail safely.

Examples:

```text
No eligible delivery offer:
Block Add to Cart with a clear message.

No rate card for valid offer:
Do not return a zero cost.
Show configuration error to staff and a customer-safe availability message.

Address not covered:
Block checkout or require a configured fallback option.

Invalid variation override:
Fall back to parent rule only if explicitly configured; otherwise report error.

WCML missing:
Use base currency; do not throw fatal errors.

WPML missing:
Use site language; do not throw fatal errors.

WoodMart unavailable:
Render through generic WooCommerce hooks.

Shipment creation fails after order creation:
Log error, flag order for staff review, avoid silently losing delivery data.
```

Every checkout-critical error should create a readable diagnostic log for administrators without exposing internal data to customers.

---

# 24. Version 1 acceptance criteria

The first version is complete only when all of the following work in staging.

```text
A simple international product can offer Sea only.

A simple international product can offer Air only.

An international product can offer both Air and Sea.

A variable product can show different eligible offers by variation.

An in-store product defaults to Delivery and allows Store Pickup.

Switching to Store Pickup removes delivery pricing and delivery estimate.

An in-warehouse product allows Delivery Only.

Same-Day Express Courier uses manually configured checkout pricing.

Two different products can use different delivery routes in one order.

The same product can exist twice in cart with Air on one line and Sea on another.

Different suppliers create separate internal shipments even when route is the same.

Compatible items can consolidate into one shipment.

Shipping charges remain genuine WooCommerce shipping charges.

Cart, checkout, confirmation page, My Account, and emails show customer-safe delivery details.

Supplier and origin records never appear to customers.

Shipment statuses and tracking links appear in customer order views.

The plugin works without WPML/WCML.

The plugin works with WPML/WCML when installed.

WCML conversion/manual override behavior is correct.

WooCommerce HPOS is supported.

WoodMart variable products, swatches, quick view, mini-cart, cart, and checkout are tested.

WP Rocket/Redis do not cache one customer’s delivery result for another.

No proof-of-delivery, buyer-confirmation, OTP, QR, driver, GPS, or live-carrier API feature is accidentally required.
```

# Reusable WooCommerce Delivery & Fulfilment Engine

## Complete AI Handoff — Part 3: Delivery Plan, Migration, SOPs, Testing, Deployment, Support, and Future Scope

# 25. Implementation strategy

This project must not be implemented as one large uncontrolled change on a live store.

It should be delivered in controlled phases, with each phase independently testable and reversible.

The implementation order is:

```text
Foundation and compatibility
→ Delivery configuration model
→ Product and variation rules
→ Product-page selection
→ Cart and checkout shipping integration
→ Shipment records and customer status display
→ Optional WPML/WCML integration
→ Optional WCFM/VitePOS integration
→ Pilot launch
→ Broader rollout
```

The first release must remain intentionally narrow.

Do not include:

```text
Proof of delivery
Buyer receipt confirmation
OTP
QR codes
GPS
Driver applications
Delivery photos
Signatures
Carrier API dispatching
Live carrier quotes
Automatic carrier tracking
Automatic delivery confirmation
Automatic order completion
```

The goal is a reliable delivery-selection and shipment-visibility system, not a complete logistics operating system.

---

# 26. Phase 0 — Site readiness and compatibility audit

Before coding or installing the plugin on any production site, create a deployment record for that site.

Each site record must capture:

```text
Site name
Site URL
WordPress version
WooCommerce version
WooCommerce database version
PHP version
Database engine/version
Active theme and child theme
WoodMart version, where applicable
WooCommerce template overrides
HPOS status
Cart/checkout type: classic or blocks
Active caching tools
Object cache tools
Payment plugins
Shipping plugins
Multilingual plugins
Currency plugins
Marketplace/vendor plugins
POS plugins
Order volume
Product and variation count
Action Scheduler health
Known custom code
Known child-theme modifications
```

The delivery plugin must not be installed blindly because two “identical” sites may differ in:

* active plugins;
* inactive but previously used plugins;
* theme overrides;
* custom snippets;
* WordPress/PHP versions;
* database versions;
* checkout design;
* WPML/WCML availability;
* marketplace configuration;
* cache behavior;
* fulfillment processes.

## 26.1 Required pre-development checks

Before implementation starts for a site:

```text
Create a staging clone.

Back up files and database.

Confirm WooCommerce database schema is current.

Check WooCommerce Status for template warnings.

Check Action Scheduler failures/pending queue.

Check whether HPOS is enabled.

Check whether classic checkout or Checkout Blocks are used.

Check whether WPML/WCML is active.

Check whether Cities Shipping Zones for WooCommerce or another shipping plugin currently controls rates.

Check whether old delivery plugins are active, inactive, or leaving residual data.

Check whether WoodMart quick view, swatches, AJAX add-to-cart, Buy Now, mini-cart, and variation selectors are used.

Check whether caching excludes cart, checkout, My Account, and WooCommerce session routes.
```

## 26.2 WoodMart template issue

WoodMart’s variable-product add-to-cart template must be reviewed before deploying a delivery selector that depends on variation changes.

The plugin must not edit the WoodMart parent theme.

The correct process is:

```text
Create staging clone
→ compare WoodMart override with current WooCommerce core template
→ identify actual differences
→ verify WoodMart behavior
→ create child-theme override only if strictly necessary
→ preserve only required WoodMart modifications
→ test variation behavior
→ deploy after regression testing
```

Do not hide the warning by changing template version comments.

Do not copy a WooCommerce core template over WoodMart’s override without a careful merge.

Do not make the delivery plugin depend on the WoodMart template file.

The delivery selector should use WooCommerce hooks and JavaScript events so the plugin survives future WoodMart template updates.

---

# 27. Phase 1 — Core plugin foundation

The first coded milestone is the plugin shell and its internal domain model.

The plugin should activate only when WooCommerce is active.

On activation:

```text
Verify WooCommerce dependency.
Verify supported PHP version.
Register plugin capabilities.
Create plugin tables.
Create required database indexes.
Create default statuses.
Create required configuration defaults.
Register logging channels.
Register upgrade/migration framework.
Run non-destructive health checks.
```

Do not automatically create delivery offers, rate cards, suppliers, zones, or product rules on activation unless sample/demo data is explicitly enabled.

The plugin should support an onboarding wizard, but the wizard must not force configuration before the administrator understands the model.

## 27.1 Feature flags

Feature flags are required.

Suggested flags:

```text
Enable product delivery selector
Enable shipment records
Enable customer delivery timeline
Enable tracking links
Enable WPML adapter
Enable WCML adapter
Enable WoodMart adapter
Enable WCFM adapter
Enable VitePOS adapter
Enable bulk product migration tools
Enable classic checkout adapter
Enable Checkout Blocks adapter
```

Feature flags make it possible to deploy the plugin safely without immediately changing every product or checkout flow.

---

# 28. Phase 2 — Configuration foundation

Before applying delivery rules to products, staff must configure the reusable building blocks.

The configuration order is:

```text
Destination Zones
→ Logistics Profiles
→ Suppliers and Origins
→ Delivery Offers
→ Rate Cards
→ Pickup Locations
→ Product Rules
```

## 28.1 Destination zone setup SOP

For each deployment:

1. List countries served.
2. Split countries into regions only where price, eligibility, or delivery time differs.
3. Add city/postcode rules only where necessary.
4. Add remote-area flags only where needed.
5. Create a fallback zone.
6. Test address matching.
7. Confirm that every active delivery offer has a valid rate card for all intended zones.

Example:

```text
Country: Ghana

Zones:
Greater Accra Central
Greater Accra Outer Areas
Ashanti / Kumasi
Other Regional Capitals
Remote Areas
Fallback Ghana Zone
```

## 28.2 Logistics profile setup SOP

Create generic logistics profiles first.

Do not begin by making profiles for every product category.

Suggested initial profiles:

```text
Small Parcel
Standard Parcel
Large Parcel
Bulky Item
Oversized Freight
Fragile Goods
High-Value Goods
Restricted Goods
Made-to-Order Item
```

Then assign more specific restrictions only when real operations require them.

## 28.3 Supplier and origin setup SOP

Create private supplier and origin records.

A supplier should receive an internal code.

Example:

```text
SUP-001
SUP-002
SUP-003
```

An origin should receive an internal code.

Example:

```text
ORG-CN-SZ-01
ORG-GH-ACC-WH-01
ORG-UK-LON-WH-01
```

Product-facing staff should use clear labels internally, but customers must never see supplier names or origin codes.

## 28.4 Delivery offer setup SOP

Create reusable delivery offers.

Examples:

```text
Sea Consolidated
Sea Priority
Air Economy
Air Express
Standard Delivery
Scheduled Delivery
Same-Day Express Courier
Store Pickup
```

For each offer, configure:

```text
Customer label
Route
Service level
Carrier visibility
Carrier name, if public
Processing range
Transit range
Final-mile range
Customer price plan
Eligible zones
Eligible logistics profiles
Eligible fulfilment availability types
Default/preselected status
Display order
```

## 28.5 Rate-card setup SOP

Create a rate card only after the delivery offer and destination zone exist.

Every rate card must answer:

```text
What delivery offer is this for?
Which destination zone is covered?
Which logistics profile is covered?
Does supplier/origin matter?
How is price calculated?
What is the customer-facing base currency?
What manual currency overrides exist?
What is the effective date?
What is the fallback behavior?
```

A rate card must never be left partially configured in a way that produces a zero-cost shipping rate.

---

# 29. Phase 3 — Product and variation migration

Existing stores may contain thousands of products and variations.

Do not manually open every product unless the catalog is small.

Use staged migration and bulk assignment.

## 29.1 Product migration sequence

```text
Export product catalog
→ classify products
→ assign fulfilment availability
→ assign logistics profile
→ assign supplier/origin privately
→ assign eligible delivery offers
→ assign default offer
→ review variation exceptions
→ import or bulk update
→ validate
→ activate on pilot products
```

## 29.2 Product classification rules

Each product or variation must be placed into one of these states:

```text
International Fulfilment
In Store
In Warehouse
```

Do not allow a product to remain unclassified once the delivery engine is enabled for it.

The plugin should show a validation error:

```text
This product has no fulfilment availability rule.
It cannot use the Delivery Engine until configured.
```

## 29.3 Variation strategy

Use parent product rules by default.

Create variation overrides only where the variation differs materially.

Examples of valid variation overrides:

```text
Large size is bulky; small size is standard parcel.
One colour is in store; another is supplier fulfilled.
One variation is Air eligible; another is Sea only.
One variation has a higher delivery fee.
One variation has a different processing period.
```

Examples of unnecessary overrides:

```text
Same physical product with different colour.
Same dimensions and same supplier.
Same route eligibility.
Same logistics profile.
Same delivery offers.
```

## 29.4 Bulk-import requirements

The import system should support CSV templates with columns such as:

```text
SKU
Product ID
Variation ID
Fulfilment Availability
Logistics Profile
Supplier Code
Origin Code
Eligible Offer Codes
Default Offer Code
Store Pickup Allowed
Pickup Location Code
Processing Override
Transit Override
Final-Mile Override
Consolidation Rule
Active
```

The import process must support:

```text
Dry-run mode
Validation report
Row-level error explanation
Duplicate detection
Rollback-safe batch processing
Import history
Downloadable error file
```

A bad import must not corrupt thousands of product rules.

---

# 30. Phase 4 — Frontend and checkout implementation

This phase introduces customer-facing selection.

It should begin with a small pilot group of products.

## 30.1 Pilot product selection

Pilot products should include:

```text
One simple International Fulfilment product with Sea only
One simple International Fulfilment product with Air only
One International Fulfilment product with Air and Sea
One variable product with inherited delivery rule
One variable product with variation override
One In Store product with Delivery and Store Pickup
One In Warehouse product with Delivery Only
One product with Same-Day Express Courier
One product with a manual carrier choice
```

Do not begin with the full catalog.

## 30.2 Product-page release criteria

Before moving to cart/checkout, verify:

```text
Delivery selector appears only on configured products.
Existing variation selectors still work.
Delivery price changes correctly.
Delivery estimate changes correctly.
Add to Cart is blocked when required delivery selection is missing.
Store Pickup removes delivery rate and delivery estimate.
Changing variation revalidates delivery options.
Quick View works.
WoodMart swatches work.
AJAX add-to-cart works if enabled.
Mini-cart displays selected delivery details.
```

## 30.3 Cart and checkout release criteria

Verify:

```text
Selected delivery offer remains attached to cart line.
Same product can exist twice with different delivery choices.
Shipping package grouping is correct.
Shipping prices are correct.
Cart shows customer-safe delivery details.
Checkout shows each shipment/service.
Destination changes trigger recalculation.
Invalid delivery offers are removed with explanation.
No duplicate Flat Rate or unrelated shipping method appears.
Pickup items do not show delivery rates.
Paid order includes correct shipping totals.
```

---

# 31. Phase 5 — Shipment management and tracking

Shipment management should be enabled only after checkout calculations are stable.

## 31.1 Shipment creation workflow

When a paid order is created:

```text
Read order items
→ inspect selected fulfilment choice
→ inspect selected delivery offer
→ resolve private supplier/origin
→ apply consolidation rules
→ create shipment records
→ assign shipment items
→ save estimate snapshot
→ save shipping-price snapshot
→ create initial status
```

Initial shipment status:

```text
Awaiting fulfilment
```

## 31.2 Staff shipment update SOP

For each shipment, staff should:

1. Open shipment record.
2. Verify item quantity and delivery service.
3. Confirm supplier/source internally.
4. Update status when fulfilment state changes.
5. Add public note only when useful to customer.
6. Add internal note for staff-only operational details.
7. Add tracking number and URL if available.
8. Update estimated delivery only when necessary.
9. Record reason for any customer-visible estimate change.

Suggested shipment statuses:

```text
Awaiting fulfilment
Processing
Dispatched
In transit
Delayed / issue
Delivered
Cancelled
```

## 31.3 Tracking policy

Version 1 supports manually entered tracking details.

Staff may add:

```text
Carrier name
Tracking number
Tracking URL
Dispatch date
Public note
```

The customer sees only the tracking information relevant to that shipment.

Do not show a tracking link if it is blank, invalid, or not usable.

Do not require carrier API integration.

---

# 32. Store Pickup SOP

Store Pickup is available only when product rule allows it.

## Customer flow

```text
Product marked In Store
→ Delivery selected by default
→ customer changes to Store Pickup
→ delivery charges disappear
→ pickup location shown
→ pickup readiness estimate shown
→ cart stores Store Pickup choice
→ checkout excludes delivery rate for that line/package
```

## Staff flow

```text
Order received
→ confirm stock at pickup location
→ prepare item
→ set pickup readiness status/note
→ customer sees pickup-ready notice
→ staff hands item over
→ staff marks order/pickup item collected using normal internal process
```

Do not implement QR pickup, OTP pickup, signature capture, or buyer confirmation in Version 1.

---

# 33. Customer service SOP

Customer support staff must not guess delivery status.

They should use the shipment workspace.

## Customer asks: “Where is my order?”

Support should:

1. Open WooCommerce order.
2. Open linked shipment record.
3. Confirm shipment status.
4. Check estimate snapshot.
5. Check tracking link if present.
6. Check public shipment notes.
7. Provide only customer-safe information.
8. Do not disclose supplier/origin/internal route data.
9. Add a public shipment note only where necessary.
10. Escalate operational issues to logistics staff.

## Customer asks: “Why did my order arrive separately?”

Support response basis:

```text
Your order may arrive in more than one shipment because items can be prepared, dispatched, or delivered at different times.
Each shipment has its own delivery status and tracking details where available.
```

Do not mention suppliers, origins, private warehouses, or internal fulfilment arrangements.

## Customer asks: “Why did delivery time change?”

Support should explain:

```text
The delivery estimate was updated based on the shipment’s current fulfilment or transit status.
```

Only explain more detail when customer-facing notes exist.

---

# 34. Refund and cancellation rules

The delivery engine must not invent a new refund system.

WooCommerce remains authoritative for refunds.

The plugin should preserve delivery and shipment context so staff can make informed decisions.

## Before dispatch

For a cancelled product line:

```text
Cancel or remove affected shipment item.
Recalculate internal shipment plan.
Refund product amount according to policy.
Refund shipping amount according to policy.
Do not alter unrelated shipment items.
```

## After dispatch

For an already dispatched shipment:

```text
Do not automatically delete shipment history.
Mark shipment as cancelled, returned, or exception only through staff workflow.
Use WooCommerce refund process for monetary changes.
Keep original delivery-price snapshot.
Record staff reason.
```

## Mixed orders

If one order contains multiple shipments:

```text
Refunding Product A must not automatically refund Product B.
Cancelling a Sea shipment must not cancel an Air shipment unless staff chooses that outcome.
```

---

# 35. Testing strategy

Testing must include automated, manual, visual, and operational testing.

No deployment should rely on only developer unit tests.

## 35.1 Automated tests

Required test layers:

```text
Unit tests
Integration tests
WooCommerce integration tests
Database migration tests
Rate-calculation tests
Shipment-grouping tests
WPML/WCML adapter tests
Security/permission tests
Regression tests
```

### Unit-test examples

```text
International Fulfilment product cannot show Store Pickup.
In Store product can show Delivery and Store Pickup.
In Warehouse product cannot show Air/Sea unless explicitly configured.
Air and Sea cart lines do not merge.
Same product with different delivery offers creates separate cart lines.
Rate card selects most specific destination zone.
Fallback zone is used only when no more specific zone matches.
Variation override wins over parent rule.
Parent rule applies when variation override absent.
Private supplier data is excluded from customer output.
```

### Shipment-grouping tests

```text
Same supplier + same Air offer + same zone + compatible profile = one shipment.
Same supplier + Sea and Air = two shipments.
Different supplier + same Air offer = separate shipments by default.
Store Pickup + Delivery = separate fulfilment groups.
Restricted item + standard item = separate shipment if profile requires it.
```

## 35.2 End-to-end test matrix

Every major deployment should test these combinations:

```text
Simple product
Variable product
In Stock
Out of Stock
Air only
Sea only
Air + Sea
Delivery only
Delivery + Store Pickup
Same-Day Express Courier
Manual named carrier
Assigned carrier
Single-item cart
Multiple-item cart
Same-product different delivery choices
Multiple suppliers
Multiple origins
Consolidated shipment
Separate shipments
Different destination zones
Remote-area surcharge
Coupon applied
Tax applied
Refund initiated
Cancelled item
Classic checkout
WPML language switch
WCML currency switch
WoodMart quick view
WoodMart variation swatches
Mini-cart
Mobile viewport
Desktop viewport
Logged-in customer
Guest checkout where enabled
```

## 35.3 Manual visual testing

Visual QA must check:

```text
No duplicate delivery selector.
No overlap with WoodMart variation swatches.
No broken spacing on mobile.
No clipped delivery cards.
No hidden required fields.
No stale delivery price after variation change.
No stale delivery estimate after address change.
No exposure of internal supplier/origin data.
No checkout total mismatch.
No theme style regression.
```

## 35.4 Security testing

Test:

```text
Customer cannot submit arbitrary delivery price.
Customer cannot choose an offer not eligible for product.
Customer cannot choose Store Pickup for Delivery Only item.
Customer cannot access another order’s shipment details.
Customer cannot see supplier/origin through REST response.
Vendor cannot see restricted internal cost/source data.
Unauthorized users cannot change shipment status.
Invalid nonce fails safely.
```

---

# 36. Staging and rollout process

Every release must follow this sequence:

```text
Development environment
→ automated tests
→ staging environment
→ manual QA
→ pilot-product release
→ monitored production release
→ controlled catalog expansion
```

## 36.1 Staging requirements

The staging site must resemble production in:

```text
WooCommerce version
WoodMart version
WoodMart child theme
WPML/WCML state
WCFM state
PHP version
database version
cache plugin configuration
Redis/object cache
checkout setup
payment gateway setup
shipping zone configuration
product catalog structure
variation behavior
```

Do not test only on a blank WordPress site.

## 36.2 Pilot launch

Begin with a limited group of manually reviewed products.

Suggested pilot size:

```text
10–25 products
including simple, variable, international, in-store, and in-warehouse cases
```

Monitor:

```text
Add-to-cart errors
Checkout failures
Shipping total mismatches
Unexpected rate availability
Cart item duplication
Missing shipment records
Private data leakage
Theme conflicts
Currency conversion issues
```

Only expand after pilot products pass real transactions and support review.

## 36.3 Rollback plan

Before each production release:

```text
Database backup complete
Files backup complete
Plugin version recorded
Feature flags documented
Affected product IDs documented
Rollback plugin ZIP ready
Rollback database plan ready
Cache purge plan ready
Staff communication prepared
```

For ordinary releases, prefer feature-flag rollback over database rollback.

Example:

```text
Disable delivery selector for all products
→ retain shipment data
→ restore existing shipping methods temporarily
→ investigate
```

Do not delete delivery records during rollback.

---

# 37. Deployment rules

## 37.1 Plugin packaging

Release the plugin as a versioned package.

Use semantic versioning:

```text
MAJOR.MINOR.PATCH
```

Example:

```text
1.0.0 = first production release
1.0.1 = bug fix
1.1.0 = backwards-compatible new capability
2.0.0 = breaking data/API behavior
```

Each release must contain:

```text
Version number
Release notes
Database migration notes
Upgrade instructions
Rollback notes
Compatibility matrix
Known limitations
Tested WooCommerce versions
Tested PHP versions
Tested WoodMart versions
Tested WPML/WCML versions where applicable
```

## 37.2 Database migration rules

Database migrations must be:

```text
Versioned
Idempotent
Logged
Non-destructive by default
Retry-safe
Compatible with large tables
Able to run in batches
```

Do not perform long blocking migrations during a customer checkout period.

## 37.3 Cache deployment rules

After deployment:

```text
Purge page cache where required.
Purge object-cache keys for plugin configuration.
Verify cart/checkout are excluded from page cache.
Verify delivery AJAX endpoints are not cached.
Verify My Account shipment pages are not cached publicly.
```

---

# 38. Monitoring and diagnostics

The plugin must provide useful diagnostics.

## 38.1 Admin health checks

Examples:

```text
WooCommerce active
HPOS status
WooCommerce database current
Cart/checkout mode detected
WPML status
WCML status
WoodMart status
WoodMart adapter status
Rate cards with no destination zone
Products with no fulfilment availability
International products with no Air/Sea offer
In Store products with no pickup location
Products with invalid variation override
Shipment records missing order linkage
Shipment with no items
Shipment with no customer estimate
Offer missing base currency amount
Cache integration warning
```

## 38.2 Operational alerts

The plugin should surface admin alerts for:

```text
Order paid but shipment creation failed
Product added to cart with incomplete rule
Offer selected but no rate found
Rate calculation failed
Unexpected shipping total mismatch
Customer-visible estimate missing
Tracking URL invalid
Shipment status update failed
WPML/WCML adapter error
```

## 38.3 Logging rules

Log operational errors with enough context to debug:

```text
Order ID
Shipment ID
Product ID
Variation ID
Delivery offer ID
Destination zone ID
Currency
Rate-card ID
Error type
Timestamp
Request correlation ID where possible
```

Do not log:

```text
Full payment details
Passwords
Sensitive payment tokens
Unnecessary customer personal information
Private supplier data in publicly accessible logs
```

---

# 39. Maintenance SOP

The plugin requires ongoing maintenance because WooCommerce, WordPress, WoodMart, WPML/WCML, PHP, and cache plugins evolve.

## 39.1 Before updating WooCommerce, WoodMart, WPML, WCML, or PHP

Use this process:

```text
Read release notes
→ clone production to staging
→ update only staging
→ run automated regression tests
→ test variable products
→ test cart and checkout
→ test delivery selector
→ test shipment view
→ test WPML/WCML where present
→ review template warnings
→ review logs
→ approve production update
```

Do not update WooCommerce, WoodMart, and the delivery plugin all at once without staging validation.

## 39.2 Monthly operational checks

```text
Review failed Action Scheduler jobs.
Review delivery-engine error logs.
Review incomplete product rules.
Review missing rate cards.
Review stale carrier/offer details.
Review delayed shipment statuses.
Review tracking links with errors.
Review customer-support delivery complaints.
Review WooCommerce template warnings.
Review plugin updates.
```

## 39.3 Rate-card review SOP

When pricing changes:

```text
Create new rate-card version or effective date.
Do not overwrite historical shipment snapshots.
Test representative products and destinations.
Confirm currency behavior.
Publish change.
Monitor checkout results.
```

Existing paid orders must retain their original shipping price and estimate snapshot.

---

# 40. Support and incident response

## 40.1 Severity levels

```text
Severity 1:
Checkout unavailable, widespread incorrect shipping charges, customer data exposure.

Severity 2:
Some products cannot add to cart, one destination zone broken, shipment records failing for subset of orders.

Severity 3:
Display issue, one invalid estimate, non-critical admin issue.

Severity 4:
Enhancement, reporting improvement, wording change.
```

## 40.2 Incident response SOP

For shipping/checkout incidents:

```text
Identify affected site.
Disable relevant feature flag if necessary.
Preserve logs.
Identify affected orders/cart sessions.
Confirm whether payments were affected.
Apply safe workaround.
Fix in staging.
Regression test.
Deploy controlled patch.
Review affected customers/orders.
Document root cause.
```

## 40.3 Customer communication rules

When an error affects delivery estimate or shipping charge:

```text
Be clear.
Do not blame supplier/carrier publicly.
Do not reveal internal origin/supplier data.
Do not promise dates that are not supported by shipment status.
Provide corrected information and next action.
```

---

# 41. Future-phase boundaries

These are valid future capabilities, but not Version 1 requirements.

## Phase 2 candidates

```text
Carrier API integrations
Live courier quotes
Automatic tracking synchronization
Delivery-driver portal
Customer notification automation
Pickup QR codes
Delivery OTP
Proof-of-delivery photos
GPS/geofence verification
Signature capture
Buyer receipt confirmation
Partial refund workflow automation
Returns-shipment integration
Warehouse scanning
Carrier performance analytics
Delivery SLA analytics
Automated exception workflows
Address validation APIs
Estimated arrival prediction from real shipment data
```

## Conditions before adding proof-of-delivery

Do not add proof-of-delivery until the business has:

```text
Defined driver or courier operating procedures.
Defined dispute policy.
Defined privacy policy for photos/GPS/signatures.
Defined staff roles.
Defined customer-notification process.
Defined retention policy.
Defined incident-resolution process.
Defined technical support ownership.
```

## Conditions before adding live carrier rates

Do not add live carrier quotes until the business has:

```text
Reliable carrier API access.
Clear contract/pricing terms.
Fallback behavior for API outage.
Clear margin policy.
Service eligibility mapping.
Address validation process.
Customer-visible error handling.
Operational capability to honor selected service.
```

---

# 42. Non-negotiable design rules

Any future AI, developer, or agency working on this project must preserve these rules.

```text
Do not make Air/Sea product variations.

Do not use a product-add-ons plugin as the authoritative delivery engine.

Do not calculate shipping as a hidden product surcharge.

Do not expose suppliers or origins to customers.

Do not require WPML/WCML for plugin activation.

Do not assume all sites use WPML/WCML.

Do not edit WoodMart parent-theme files.

Do not depend on one unrelated shipping plugin for core logic.

Do not expose one cart-wide delivery estimate when products may ship separately.

Do not merge cart items with different delivery selections.

Do not allow delivery offers that are invalid for the product, variation, or address.

Do not silently replace a customer’s selected delivery offer.

Do not return zero shipping because a rate card is missing.

Do not recalculate historical paid-order delivery charges due to later rate/currency changes.

Do not allow customer-facing pages, emails, APIs, or SEO data to reveal private supplier/origin data.

Do not add proof-of-delivery, buyer confirmation, OTP, QR, GPS, drivers, or live quotes in Version 1.

Do not bypass WooCommerce HPOS APIs.

Do not assume cart/checkout cache behavior is safe without verification.

Do not deploy directly to production without staging and pilot validation.
```

---

# 43. Final operational definition

The finished Version 1 system is a reusable WooCommerce delivery and fulfilment engine that allows each product or variation to correctly express:

```text
Where the item is available from operationally
→ whether buyer can choose Delivery, Store Pickup, or both
→ whether the item can ship by Air, Sea, Local Delivery, or another configured route
→ which service/carrier choices are available
→ what the buyer pays
→ how long dispatch, transit, and final delivery may take
→ when the buyer should expect delivery to their address
→ whether items can consolidate
→ how separate shipments are created
→ how staff update shipment status
→ how customers see delivery progress and tracking
```

It must work cleanly on multiple related WooCommerce websites, whether WPML/WCML is active or absent, while preserving WooCommerce as the owner of orders, checkout, payments, taxes, and commerce totals.

# Reusable WooCommerce Delivery & Fulfilment Engine

## Complete AI Handoff — Part 4: Developer Implementation Guidance, Contracts, Events, Hooks, Integrations, and Build Rules

# 44. Purpose of this implementation guide

This document tells the implementing AI or development team exactly how to build the reusable WooCommerce Delivery & Fulfilment Engine without turning it into a fragile group of theme edits, product-add-on hacks, or unrelated shipping-plugin dependencies.

The implementation must be:

```text id="7ct74o"
WooCommerce-first
HPOS-compatible
Theme-independent
WoodMart-compatible through adapters
Optional-WPML/WCML-compatible
Safe with or without WCFM/VitePOS
Cache-safe
Shipment-aware
Supplier/origin-private
Extensible without premature complexity
```

WooCommerce provides a Shipping Method API for extensions that need to register their own shipping methods and rates. Use that API rather than disguising delivery charges as product add-ons.

## The supplied sites use WooCommerce 10.9.3, HPOS, WoodMart child themes, Redis, and WP Rocket; one site has WPML/WCML enabled while another does not. The plugin must therefore detect optional integrations at runtime rather than hard-require them.

# 45. Required repository structure

Use a standard Composer-capable plugin structure.

```text id="hhm0rh"
woocommerce-delivery-engine/
├── woocommerce-delivery-engine.php
├── uninstall.php
├── composer.json
├── readme.txt
├── languages/
├── assets/
│   ├── admin/
│   ├── frontend/
│   └── build/
├── templates/
│   ├── product/
│   ├── cart/
│   ├── checkout/
│   ├── myaccount/
│   └── emails/
├── src/
│   ├── Bootstrap/
│   ├── Core/
│   ├── Domain/
│   │   ├── DeliveryOffer/
│   │   ├── ProductRule/
│   │   ├── Shipment/
│   │   ├── RateCard/
│   │   ├── Zone/
│   │   ├── LogisticsProfile/
│   │   ├── Supplier/
│   │   └── Estimate/
│   ├── Application/
│   ├── Infrastructure/
│   │   ├── Persistence/
│   │   ├── WooCommerce/
│   │   ├── WordPress/
│   │   ├── Cache/
│   │   └── Logging/
│   ├── Presentation/
│   │   ├── Admin/
│   │   ├── Frontend/
│   │   ├── ClassicCheckout/
│   │   └── Blocks/
│   ├── Integrations/
│   │   ├── WoodMart/
│   │   ├── WPML/
│   │   ├── WCML/
│   │   ├── WCFM/
│   │   └── VitePOS/
│   ├── Support/
│   └── Tests/
├── database/
│   ├── migrations/
│   └── schema/
└── tests/
    ├── Unit/
    ├── Integration/
    ├── WooCommerce/
    └── E2E/
```

Use namespaces. Avoid a global function/class collection.

Example root namespace:

```text id="d36z7m"
DeliveryEngine\
```

Suggested sub-namespaces:

```text id="22osza"
DeliveryEngine\Domain\
DeliveryEngine\Application\
DeliveryEngine\Infrastructure\
DeliveryEngine\Presentation\
DeliveryEngine\Integrations\
DeliveryEngine\Support\
```

Do not put business rules directly in:

```text id="gvbo7v"
functions.php
WoodMart template files
WooCommerce template overrides
JavaScript-only frontend logic
wp_postmeta-only storage
```

---

# 46. Core bootstrap contract

The main plugin file must only bootstrap the system.

It should:

```text id="a3jgbg"
Check PHP compatibility
Check WooCommerce availability
Load Composer autoloading
Load plugin text domain
Register activation/deactivation hooks
Initialize the container/service registry
Register WooCommerce hooks
Register admin modules
Register integration detectors
Register upgrade/migration checks
```

The plugin must fail safely if WooCommerce is unavailable.

Customer-facing checkout behavior must not break if an optional integration is absent.

Example state matrix:

| Dependency      |         Required? | Behavior when absent            |
| --------------- | ----------------: | ------------------------------- |
| WooCommerce     |               Yes | Plugin does not activate        |
| HPOS            | No, but supported | Use WooCommerce CRUD either way |
| WoodMart        |                No | Render generic WooCommerce UI   |
| WPML            |                No | Use site default language       |
| WCML            |                No | Use WooCommerce base currency   |
| WCFM            |                No | Hide vendor adapter             |
| VitePOS         |                No | Hide POS adapter                |
| Checkout Blocks |                No | Use classic checkout adapter    |
| Carrier API     |                No | Use manually configured offers  |

WooCommerce’s HPOS implementation is built around WooCommerce CRUD for orders; the plugin must use WooCommerce order objects and compatibility declarations rather than direct order-table assumptions.

---

# 47. Dependency detection and compatibility declarations

## 47.1 WooCommerce dependency

At initialization:

```text id="bvu9nc"
if WooCommerce is inactive:
    show admin notice
    stop bootstrapping delivery features
```

Do not cause a fatal error.

## 47.2 HPOS compatibility declaration

Declare HPOS compatibility during plugin initialization.

The implementation must use WooCommerce CRUD methods such as:

```text id="dgbp6r"
wc_get_order()
$order->get_items()
$order->get_shipping_methods()
$order->update_meta_data()
$order->save()
```

Do not directly query:

```text id="u3p2pv"
wp_posts
wp_postmeta
wp_wc_orders
wp_wc_orders_meta
```

for WooCommerce order business logic.

Custom delivery tables may be queried directly through `$wpdb`, using prepared statements and the dynamic database prefix.

## 47.3 Optional integration detector

Create one integration registry.

Example interface:

```php
interface IntegrationInterface {
    public function isAvailable(): bool;
    public function register(): void;
    public function getKey(): string;
}
```

Suggested adapters:

```text id="1vgmgc"
WoodMartIntegration
WpmlIntegration
WcmlIntegration
WcfmIntegration
VitePosIntegration
ClassicCheckoutIntegration
BlocksCheckoutIntegration
```

The Core module must not directly call WPML, WCML, WCFM, or WoodMart functions.

Instead:

```text id="z5drnz"
Core
→ IntegrationRegistry
→ checks available adapter
→ invokes adapter contract
```

This prevents fatal errors on installations where those plugins are absent.

---

# 48. Domain entities and immutable value objects

Use clear domain objects.

Avoid passing anonymous arrays through the whole system.

## 48.1 Required domain entities

```text id="u0gcds"
DeliveryOffer
ProductFulfilmentRule
VariationFulfilmentOverride
DestinationZone
DestinationMatch
LogisticsProfile
Supplier
Origin
RateCard
RateQuote
DeliveryEstimate
ShipmentPlan
Shipment
ShipmentItem
ShipmentEvent
PickupLocation
CurrencySnapshot
DeliverySelection
```

## 48.2 Required immutable value objects

```text id="rm3zqf"
Money
CurrencyCode
DateRange
DurationRange
BusinessDayRange
AddressSnapshot
DestinationContext
FulfilmentAvailability
FulfilmentChoice
DeliveryRoute
ServiceLevel
CarrierVisibility
ShipmentStatus
ConsolidationKey
RateCalculationResult
EstimateCalculationResult
```

## 48.3 Important enums

Use PHP enums where supported by the plugin’s minimum PHP version, or stable constants if not.

```text id="va5s4w"
FulfilmentAvailability:
INTERNATIONAL_FULFILMENT
IN_STORE
IN_WAREHOUSE

FulfilmentChoice:
DELIVERY
STORE_PICKUP

DeliveryRoute:
AIR
SEA
LOCAL_DELIVERY
STORE_PICKUP

CarrierVisibility:
NAMED
ASSIGNED_BY_STORE

ShipmentStatus:
AWAITING_FULFILMENT
PROCESSING
DISPATCHED
IN_TRANSIT
DELAYED
DELIVERED
CANCELLED
```

Never store customer-facing translated labels as the internal enum value.

Store stable codes internally and translate labels only at presentation time.

---

# 49. Application service contracts

Keep business decisions in application services.

Suggested interfaces:

```php
interface DeliveryEligibilityResolverInterface {
    public function resolve(
        ProductContext $product,
        DestinationContext $destination,
        CartContext $cart
    ): EligibilityResult;
}
```

```php
interface DeliveryOfferResolverInterface {
    public function resolveAvailableOffers(
        ProductContext $product,
        DestinationContext $destination,
        CartContext $cart
    ): array;
}
```

```php
interface RateCalculatorInterface {
    public function quote(
        DeliverySelection $selection,
        ProductContext $product,
        DestinationContext $destination,
        QuantityContext $quantity
    ): RateQuote;
}
```

```php
interface EstimateCalculatorInterface {
    public function estimate(
        DeliverySelection $selection,
        ProductContext $product,
        DestinationContext $destination,
        DateTimeImmutable $referenceTime
    ): DeliveryEstimate;
}
```

```php
interface ShipmentPlannerInterface {
    public function plan(
        OrderContext $order,
        array $deliverySelections
    ): ShipmentPlan;
}
```

```php
interface ShipmentServiceInterface {
    public function createFromOrder(WC_Order $order): array;
    public function updateStatus(ShipmentId $shipmentId, ShipmentStatus $status): Shipment;
    public function addTracking(ShipmentId $shipmentId, TrackingDetails $tracking): Shipment;
}
```

```php
interface CurrencyAdapterInterface {
    public function convert(
        Money $baseAmount,
        CurrencyContext $currencyContext
    ): Money;

    public function getDisplayCurrency(): CurrencyCode;

    public function supportsManualOverrides(): bool;
}
```

```php
interface TranslationAdapterInterface {
    public function translate(string $text, string $context): string;
    public function registerString(string $text, string $context): void;
}
```

The system must include Null Object adapters:

```text id="mndq0q"
NullCurrencyAdapter
NullTranslationAdapter
NullWoodMartAdapter
NullWcfmAdapter
NullVitePosAdapter
```

That makes optional integrations safe and predictable.

---

# 50. Product-rule resolution algorithm

The product-rule resolver must be deterministic.

Use this precedence:

```text id="4n4bd3"
Variation override
→ parent product rule
→ category/default rule if enabled
→ site fallback rule
→ no eligible delivery configuration
```

Recommended algorithm:

```text id="t0vb8q"
1. Load product.
2. Identify selected variation, if any.
3. Load parent product fulfilment rule.
4. Load variation override, if any.
5. Merge override onto parent rule.
6. Validate fulfilment availability.
7. Resolve destination context.
8. Resolve eligible delivery offers.
9. Apply product-specific exclusions.
10. Apply logistics restrictions.
11. Apply supplier/origin restrictions internally.
12. Apply destination-zone rules.
13. Return final eligible offers and default selection.
```

The resolver must never expose supplier/origin data to frontend output.

Example merge behavior:

```text id="mtjswm"
Parent:
International Fulfilment
Air + Sea enabled
Standard Parcel
Supplier A

Variation:
Air disabled
Bulky Item

Final effective rule:
International Fulfilment
Sea only
Bulky Item
Supplier A
```

---

# 51. Delivery-selection data contract

When a customer makes a delivery choice, store a normalized selection object.

Example serialized structure:

```json
{
  "version": 1,
  "fulfilment_availability": "international_fulfilment",
  "fulfilment_choice": "delivery",
  "delivery_offer_id": 22,
  "delivery_offer_code": "sea_consolidated",
  "route": "sea",
  "service_level": "consolidated",
  "carrier_visibility": "assigned_by_store",
  "carrier_label": null,
  "destination_context_hash": "safe-hash",
  "price_snapshot": {
    "base_amount": "25.00",
    "base_currency": "USD",
    "display_amount": "25.00",
    "display_currency": "USD"
  },
  "estimate_snapshot": {
    "processing_min": 3,
    "processing_max": 6,
    "transit_min": 20,
    "transit_max": 30,
    "final_mile_min": 2,
    "final_mile_max": 5,
    "unit": "business_days",
    "customer_label": "Estimated delivery to your address: 25–41 business days"
  },
  "rule_version": 7
}
```

Do not trust this value merely because it came from the browser.

On Add to Cart, Cart, Checkout, and Order creation:

```text id="nf50gm"
Re-resolve the product rule server-side.
Revalidate the delivery offer.
Recalculate the price server-side.
Recalculate the estimate server-side.
Replace client-supplied snapshots with server-approved snapshots.
```

The browser may send an offer identifier. It must never send the authoritative price.

---

# 52. Cart implementation

## 52.1 Add-to-cart validation

Use WooCommerce add-to-cart validation to ensure:

```text id="4nmmrn"
Configured product has fulfilment rule.
Required delivery choice exists.
Selected offer belongs to product/variation.
Selected offer is eligible for destination.
Selected offer is available for current quantity.
Pickup is allowed only for In Store product.
Air/Sea is allowed only for International Fulfilment product.
Local offers are allowed only for configured availability.
```

Relevant WooCommerce extension points for classic cart flows include add-to-cart validation and cart-item data filters.

Recommended hook use:

```text id="cn5pi9"
woocommerce_add_to_cart_validation
woocommerce_add_cart_item_data
woocommerce_get_cart_item_from_session
woocommerce_get_item_data
```

## 52.2 Cart item uniqueness

The selected delivery configuration must affect the cart-item key.

Example:

```text id="usnefw"
Product A + Sea
Product A + Air
```

These must create separate cart lines.

Construct a delivery fingerprint from stable values:

```text id="t90qzw"
product_id
variation_id
fulfilment_choice
delivery_offer_id
destination-context version
relevant rule version
```

Do not include unstable values such as translated labels or display price strings in the fingerprint.

## 52.3 Cart session restoration

When WooCommerce restores a cart from session:

```text id="a2qjtn"
Load saved selection
→ revalidate against current rule
→ refresh quote/estimate if required
→ retain selection if still valid
→ mark item invalid if no longer valid
→ present clear cart notice
```

Do not silently replace Sea with Air, Air with Sea, Delivery with Pickup, or one carrier with another.

---

# 53. Shipping-package implementation

The plugin must convert delivery selections into real WooCommerce shipping packages.

## 53.1 Package building

Use a package builder service.

```php
interface ShippingPackageBuilderInterface {
    public function buildFromCart(WC_Cart $cart): array;
}
```

The package builder should:

```text id="elipm8"
Read cart items.
Ignore non-shippable virtual items where appropriate.
Read normalized delivery selection.
Resolve private supplier/origin.
Build consolidation key.
Group items by consolidation key.
Create one WooCommerce package per shipment group.
Attach private internal metadata to package only.
```

Use the `woocommerce_cart_shipping_packages` filter to provide the package set.

## 53.2 Package metadata

A package may contain safe internal metadata such as:

```text id="fa5s4y"
delivery_engine_package_id
delivery_offer_id
delivery_offer_code
shipment_group_key
fulfilment_choice
supplier_id
origin_id
destination_zone_id
estimate_snapshot
```

Do not leak this package metadata to customer-facing output automatically.

## 53.3 Shipping rate generation

Register one custom shipping method.

Suggested method ID:

```text id="wwzab2"
delivery_engine_selected_offer
```

The custom method must return only the rate matching the selected delivery offer for that package.

Example rate object:

```text id="msl4s0"
Rate ID:
delivery_engine_selected_offer:package-abc

Method title:
Sea Shipping

Cost:
25.00

Meta:
Estimated delivery to your address: 25–41 business days
```

Use rate metadata or supported customer-facing descriptions for delivery estimate display where compatible.

WooCommerce’s Shipping Method API is intended for extensions that add custom rates; keep this rate logic inside the custom method rather than mixing it into product price calculations.

## 53.4 Preventing duplicate shipping methods

For delivery-engine-managed packages:

```text id="38z21p"
Remove conflicting Flat Rate methods.
Remove Free Shipping methods unless deliberately included by rate rule.
Remove legacy plugin shipping rates.
Do not show duplicate Air/Sea placeholders.
```

For non-managed products/packages, existing WooCommerce shipping methods may continue to apply if site configuration allows them.

This must be controllable by settings:

```text id="ybi5p6"
Managed package behavior:
Exclusive
Coexist with native rates
Fallback to native rates on configuration failure
```

The recommended default is:

```text id="oavt6p"
Exclusive for Delivery Engine packages.
```

WooCommerce normally displays shipping methods that match the customer and cart, including multiple methods where they qualify; the delivery engine must explicitly manage this for controlled package behavior.

---

# 54. Checkout and order-creation flow

## 54.1 Server-authoritative flow

The server must be authoritative.

```text id="s47yj0"
Product page selection
→ cart-item data
→ server revalidation
→ package creation
→ shipping quote
→ checkout totals
→ order creation
→ delivery snapshot
→ shipment creation
```

Do not rely on frontend state as truth.

## 54.2 Order item metadata

When WooCommerce creates order line items, save safe customer-facing metadata:

```text id="9tdvu5"
Fulfilment: Delivery
Delivery service: Sea Shipping
Estimated delivery: 25–41 business days
```

For Store Pickup:

```text id="0ue384"
Fulfilment: Store Pickup
Pickup location: Accra Branch
Pickup readiness: 1–2 business days
```

Do not save customer-visible supplier/origin details.

Use private metadata or custom tables for:

```text id="oyj4tt"
supplier_id
origin_id
internal cost
internal shipping plan
private notes
```

## 54.3 Shipment creation timing

Create shipment records after the WooCommerce order is successfully created and paid-state conditions are met according to site policy.

Recommended trigger point:

```text id="hze1ld"
Order created
→ delivery metadata validated
→ shipment records created
→ initial status = Awaiting fulfilment
```

If payment must be confirmed first, then create or activate shipments when the order enters the appropriate paid/processing status.

Use idempotency.

Shipment creation must be safe if WooCommerce retries a webhook, checkout request, payment callback, or background action.

Example idempotency rule:

```text id="hdoi42"
If shipment already exists for order item + shipment group key:
do not create duplicate shipment.
```

## 54.4 Order/shipment relation

One WooCommerce order can have:

```text id="qcvzdb"
One shipment
Several shipments
Zero delivery shipments if every item is Store Pickup
```

One shipment can contain:

```text id="3jp6am"
One order item
Several compatible order items
Partial quantity of an order item where later supported
```

Version 1 can initially keep one order-item quantity together unless partial-quantity shipment support is explicitly enabled.

---

# 55. Shipment status and tracking implementation

## 55.1 Status updates

Create a service method:

```php
public function updateShipmentStatus(
    ShipmentId $shipmentId,
    ShipmentStatus $newStatus,
    ?string $publicNote,
    ?string $internalNote,
    UserId $actor
): Shipment;
```

Validation rules:

```text id="b3p5bn"
Actor must have shipment-update capability.
Status transition must be allowed.
Public note must be sanitized.
Internal note must never render customer-side.
Update must create audit event.
Update must create shipment event.
Customer-visible status must use translated label at render time.
```

## 55.2 Status transition rules

Initial transition map:

```text id="ld9bq8"
Awaiting fulfilment
→ Processing
→ Dispatched
→ In transit
→ Delivered

Awaiting fulfilment
→ Cancelled

Processing
→ Delayed / issue
→ Cancelled

Dispatched
→ Delayed / issue
→ In transit

In transit
→ Delayed / issue
→ Delivered
```

Avoid enforcing overly rigid workflow in Version 1. Permit authorized staff to apply corrective transitions with a required internal note.

## 55.3 Tracking details

Tracking details should contain:

```text id="lyzbgc"
Carrier display name
Tracking number
Tracking URL
Public tracking note
Dispatch date
```

Validate URLs.

If tracking is not available, do not show an empty “Track shipment” control.

No carrier tracking API is required in Version 1.

---

# 56. Frontend presentation architecture

## 56.1 Classic WooCommerce product pages

Use WooCommerce hooks, not parent-theme file edits.

Preferred placement:

```text id="4727qw"
After variation selector, before Add to Cart
```

Typical hooks:

```text id="p42hdk"
woocommerce_before_add_to_cart_button
woocommerce_after_add_to_cart_button
```

For variable products, the JavaScript must listen to variation events.

Example frontend event requirements:

```text id="w3ygps"
found_variation
reset_data
hide_variation
show_variation
woocommerce_variation_has_changed
```

The selector must update when a variation is selected, changed, unavailable, or reset.

## 56.2 Frontend payload

The server should expose only safe frontend data:

```text id="twn4yb"
Fulfilment availability
Customer label
Eligible delivery offers
Public carrier label if applicable
Public price
Public processing/transit/final-mile ranges
Public estimated delivery text
Pickup location label where applicable
Whether selection is required
```

Never expose:

```text id="wtubgl"
Supplier ID
Supplier name
Origin ID
Origin location
Internal cost
Margin
Private dispatch notes
Consolidation key
Internal rate-card logic
```

## 56.3 UI behavior

The frontend must:

```text id="c5t7ep"
Show Delivery Only clearly.
Show In Store clearly.
Show In Warehouse clearly.
Default Delivery for In Store.
Allow Store Pickup only where configured.
Hide delivery cost and delivery estimate on Store Pickup.
Recalculate on variation change.
Recalculate on destination change.
Disable Add to Cart only when required selection remains incomplete.
Show clear loading state during recalculation.
Show customer-safe errors when no offer is valid.
```

## 56.4 WoodMart integration

WoodMart compatibility must be additive.

The plugin should:

```text id="a23tz0"
Use standard WooCommerce hooks.
Use theme-neutral HTML first.
Add WoodMart classes only through adapter.
Avoid modifying parent templates.
Test swatches, quick view, mini-cart, AJAX add-to-cart, Buy Now, and mobile layouts.
```

The supplied WoodMart installations override WooCommerce’s variable add-to-cart template, and the supplied status reports that override as outdated against WooCommerce core. Validate or repair that compatibility in staging before enabling complex variation-aware delivery UI.

---

# 57. Cart and checkout blocks support

The present supplied sites use classic shortcode Cart and Checkout pages, so Version 1 must fully support classic flows first.

Do not claim Checkout Block compatibility merely because the backend shipping method works.

Create a separate Blocks adapter.

Its responsibilities:

```text id="w5zlc0"
Extend Store API data safely.
Expose delivery-selection metadata to blocks.
Render delivery details in supported slots/inner blocks.
Submit updates through supported Store API mechanisms.
Keep server authoritative.
Handle checkout state changes.
```

WooCommerce’s Cart and Checkout Blocks use extensibility interfaces and Store API-driven state; the server remains authoritative for transactional and persistent data.

Do not make the blocks adapter a prerequisite for the classic checkout release.

---

# 58. WPML integration implementation

## 58.1 Detection

Check for WPML before calling its functions.

Example logic:

```text id="1u0ovf"
if defined('ICL_SITEPRESS_VERSION') or WPML service available:
    register WPML adapter
else:
    use NullTranslationAdapter
```

## 58.2 Product rule translation behavior

The canonical product’s operational delivery configuration should be copied/inherited by translations.

Translate only customer-facing fields.

Suggested field behavior:

| Field                               | WPML behavior                               |
| ----------------------------------- | ------------------------------------------- |
| Fulfilment availability code        | Copy                                        |
| Logistics profile ID                | Copy                                        |
| Supplier ID                         | Copy                                        |
| Origin ID                           | Copy                                        |
| Delivery offer IDs                  | Copy                                        |
| Default offer ID                    | Copy                                        |
| Route restrictions                  | Copy                                        |
| Rate-card linkage                   | Copy                                        |
| Internal notes                      | Copy or ignore                              |
| Customer-facing offer description   | Translate                                   |
| Customer-facing pickup instructions | Translate                                   |
| Public shipment note                | Translate if manually authored per language |

Use a `wpml-config.xml` file to declare custom-field translation behavior.

Do not manually reconfigure these fields on every translated product.

## 58.3 Translation strings

Register reusable strings for:

```text id="h6p01g"
Delivery Only
In Store
In Warehouse
Delivery
Store Pickup
Air Shipping
Sea Shipping
Same-Day Express Courier
Processing / dispatch
Main transit
Estimated delivery to your address
Tracking available after dispatch
```

The plugin must still work with plain `__()` and `esc_html__()` translations when WPML is absent.

---

# 59. WCML multi-currency implementation

## 59.1 Detection

```text id="ea4gip"
If WCML is present:
    register WcmlCurrencyAdapter
Else:
    register WooCommerceBaseCurrencyAdapter
```

## 59.2 Currency flow

Canonical configuration:

```text id="e3bqi6"
Rate card stores base amount in WooCommerce base currency.
```

When WCML is active:

```text id="7c6ssr"
Resolve selected display currency.
Convert base amount through WCML adapter.
Apply manual currency override if configured.
Apply WCML rounding behavior.
Store checkout currency snapshot.
Store paid delivery amount snapshot.
```

WCML provides documented hooks for raw price conversion, custom price fields, exchange rates, rounding, and other multi-currency behavior.

WCML also supports manual shipping rates in different currencies, which aligns with the requirement for optional manually configured delivery-offer prices per currency.

## 59.3 Price snapshot rule

At checkout save:

```text id="i0iqtu"
Base amount
Base currency
Displayed/charged amount
Checkout currency
Applied manual override, if any
Conversion source
Applied exchange rate where relevant
Applied rounding result
```

Never recompute old paid-order totals because exchange rates later change.

## 59.4 Manual override structure

Example:

```json
{
  "USD": "95.00",
  "GHS": "1450.00",
  "GBP": "78.00",
  "EUR": "90.00"
}
```

Use manual overrides only when configured.

Otherwise use WCML conversion.

Do not use customer currency to decide route eligibility. Destination address decides eligibility; currency only affects price display/payment.

---

# 60. WCFM integration rules

WCFM support is optional and must not expose private logistics data automatically.

Default WCFM behavior:

```text id="tvzuw2"
Vendors cannot see private supplier/origin data.
Vendors cannot see internal costs.
Vendors cannot alter global rate cards.
Vendors cannot alter destination zones.
Vendors cannot view other vendors’ shipments.
```

Future policy may allow limited vendor access, but only through explicit capabilities and a dedicated WCFM adapter.

Suggested optional capabilities:

```text id="r5x6ly"
view_own_product_delivery_rules
edit_own_product_delivery_rules
view_own_shipments
update_own_shipment_status
```

Do not allow vendors to edit route pricing or supplier/origin fields by default.

---

# 61. VitePOS integration rules

VitePOS support is optional.

The adapter must ensure POS-created orders do not bypass delivery rules.

Initial scope:

```text id="2pechj"
Staff can choose Delivery or Store Pickup where product allows it.
Staff can see delivery offer and customer shipping charge.
POS order stores same normalized delivery selection.
POS order creates shipment record where delivery applies.
Pickup order creates no delivery shipping rate.
```

Do not assume all online product-page UI appears in POS.

Use a dedicated POS flow and test separately.

---

# 62. Database implementation requirements

## 62.1 Custom tables

Use custom plugin tables for:

```text id="2zrhwf"
Delivery offers
Zones
Rate cards
Logistics profiles
Supplier/origin records
Shipment records
Shipment items
Shipment events
Audit events
```

Use WooCommerce order items/meta for customer-visible order snapshots and linkage.

## 62.2 Migration design

Each migration must include:

```text id="jbfeq4"
Migration ID
Schema version
Forward migration
Idempotency check
Rollback note
Batch support where needed
Error logging
```

Never drop business-critical data automatically.

## 62.3 Data integrity

Use:

```text id="uw44nx"
Foreign-key-like validation in code
Database indexes
Unique constraints where appropriate
Soft deletes for reference entities
Snapshots for historical transactions
```

Suggested unique constraints:

```text id="mue2gg"
One delivery offer code per site
One logistics profile code per site
One supplier code per site
One origin code per site
One shipment number per site
One shipment item row per shipment + order item + quantity group
```

Do not hard-delete suppliers/origins referenced by historical shipments.

Mark them inactive instead.

---

# 63. REST and internal API design

Version 1 may expose internal REST endpoints for authenticated admin UI and AJAX.

Do not expose private delivery internals publicly.

Suggested private endpoints:

```text id="q45cu3"
GET /delivery-engine/v1/offers
GET /delivery-engine/v1/zones
GET /delivery-engine/v1/rate-cards
GET /delivery-engine/v1/shipments
GET /delivery-engine/v1/shipments/{id}
POST /delivery-engine/v1/shipments/{id}/status
POST /delivery-engine/v1/shipments/{id}/tracking
POST /delivery-engine/v1/quote
POST /delivery-engine/v1/validate-selection
```

Customer-facing quote endpoints must:

```text id="hhxh4r"
Require cart/session context.
Return only safe fields.
Recalculate server-side.
Rate-limit if public.
Never return private supplier/origin/cost data.
```

Use WooCommerce session/customer context when resolving cart quotes.

---

# 64. Security rules for delivery pricing

The following attacks must be assumed:

```text id="llvtym"
Customer changes hidden delivery price in browser.
Customer changes delivery offer ID.
Customer sends Store Pickup for an international item.
Customer sends Air rate for a sea-only product.
Customer reuses another user’s cart data.
Customer attempts to reveal private supplier/origin data.
Customer manipulates currency conversion fields.
Customer changes tracking URL through unauthorized request.
```

Defenses:

```text id="tewhgg"
Server-side offer validation.
Server-side quote calculation.
Capability checks.
Nonces.
Order ownership checks.
Strict REST permission callbacks.
No client-provided authoritative price.
No client-provided private source IDs.
Escaped output.
Prepared SQL statements.
Audit logs for sensitive actions.
```

---

# 65. Action Scheduler and background jobs

Version 1 should remain mostly event-driven.

Use Action Scheduler only for optional non-critical tasks such as:

```text id="kfjk99"
Rebuilding indexes
Batch imports
Rate-card cache invalidation
Migration batches
Health scans
Optional delayed administrative notifications
```

Do not make checkout dependent on a background job.

Do not use scheduled actions for proof-of-delivery, buyer reminders, or automatic completion because those features are excluded from Version 1.

The supplied sites show meaningful Action Scheduler activity, including a large pending queue on one site. Do not add unnecessary recurring jobs before queue health is reviewed.

---

# 66. Logging and observability implementation

Create a dedicated WooCommerce/WordPress log channel:

```text id="1tvb1n"
delivery-engine
```

Use structured logging fields:

```text id="lgnqnd"
event
order_id
shipment_id
product_id
variation_id
offer_id
zone_id
currency
rate_card_id
exception_class
message
correlation_id
```

Important events:

```text id="diyqwb"
delivery_selection_validated
delivery_selection_rejected
rate_quote_created
rate_quote_failed
shipping_packages_built
shipment_created
shipment_status_updated
tracking_updated
currency_conversion_applied
currency_override_applied
wpml_adapter_loaded
wcml_adapter_loaded
cache_invalidated
migration_completed
```

Customer-facing errors must remain simple.

Example:

```text id="1j4aun"
This delivery option is unavailable for the selected address.
Please choose another available option.
```

Do not show internal rate-card/supplier/origin errors to customers.

---

# 67. Test architecture

## 67.1 Unit tests

Test pure domain logic without WordPress bootstrapping:

```text id="qhhxam"
Offer eligibility
Variation inheritance
Zone matching
Rate calculation
Estimate calculation
Consolidation key generation
Shipment planning
Currency snapshot rules
Status transition rules
```

## 67.2 WooCommerce integration tests

Test:

```text id="gcbrf0"
Cart item data
Cart key uniqueness
Shipping package generation
Custom shipping rates
Checkout totals
Order creation
Order metadata
Shipment record creation
Refund/cancellation behavior
HPOS compatibility
```

## 67.3 Optional integration tests

Separate test suites:

```text id="svf4bu"
WPML enabled
WPML absent
WCML enabled
WCML absent
WoodMart enabled
WoodMart absent
WCFM enabled
VitePOS enabled
Classic checkout
Blocks checkout
```

## 67.4 Browser/E2E tests

Use Playwright or Cypress for:

```text id="8a24h2"
Variation selection
Delivery offer changes
Store Pickup switch
Cart update
Checkout totals
Language switch
Currency switch
Quick View
Mini-cart
Mobile layout
Customer order view
Tracking link display
```

---

# 68. Definition of done for the implementing AI

The implementing AI or developer must not say “done” merely because a delivery selector appears on the product page.

The implementation is done only when:

```text id="1f1vca"
Product rules resolve correctly.
Variation overrides work.
Cart items do not merge incorrectly.
Shipping charges are genuine WooCommerce shipping charges.
Packages are split correctly.
Checkout totals match the selected offers.
Orders persist delivery snapshots.
Shipments are created idempotently.
Customer account shows only safe shipment data.
Supplier/origin information remains private.
Classic checkout works.
HPOS works.
WoodMart works through hooks.
WPML/WCML absence does not break the plugin.
WPML/WCML presence works through optional adapters.
Caching does not leak delivery rates across users.
Admin controls are permission-protected.
Tests pass.
Staging tests pass.
Pilot products pass.
```

---

# 69. Final engineering instruction

Build a **delivery domain layer**, not a visual add-on.

The correct architecture is:

```text id="d4x4wz"
WooCommerce Products and Variations
→ Delivery Rule Resolver
→ Delivery Offer Resolver
→ Server-side Quote and Estimate Engine
→ Cart-Line Delivery Selection
→ Shipment Package Builder
→ WooCommerce Custom Shipping Method
→ Order Snapshots
→ Shipment Records
→ Customer Shipment Timeline
```

Keep all private operational data behind the delivery domain boundary.

Keep all customer-facing presentation dependent on safe resolved data.

Keep optional integrations behind adapters.

Keep the first version operationally simple, manually priced, manually tracked, and fully checkout-safe.

# Reusable WooCommerce Delivery & Fulfilment Engine

## Complete AI Handoff — Part 5: Administrator Workflows, Screen-by-Screen UX, Customer Experience, Validation, SOPs, and First Deployment Configuration

# 70. UX principles

The plugin must feel like a natural part of WooCommerce and the active theme, not a separate logistics application pasted into WordPress.

The UX must follow these rules:

```text id="8yhrhl"
Show customers only what they need to decide and pay.

Keep supplier, origin, internal costs, consolidation rules, and internal notes private.

Use plain customer language.

Use “Estimated delivery to your address,” not vague “Arrival.”

Keep product choices separate from delivery choices.

Do not make Air or Sea a product variation.

Never make the customer choose the same delivery method twice.

Show delivery price before Add to Cart and again before payment.

Show different shipments separately after purchase.

Use progressive disclosure in admin screens.

Do not require staff to understand shipping formulas before they can configure a simple fixed delivery offer.

Make complex configuration available, but not mandatory for basic products.
```

The plugin must support desktop and mobile use.

It must remain usable for:

* store managers;
* product staff;
* logistics staff;
* customer support staff;
* warehouse/store staff;
* administrators;
* marketplace/vendor staff only where explicitly permitted.

---

# 71. Admin navigation

Add a top-level WordPress admin menu:

```text id="zsoxdb"
Delivery Engine
```

Suggested menu order:

```text id="b49krm"
Dashboard
Shipments
Products & Rules
Delivery Offers
Rate Cards
Destination Zones
Logistics Profiles
Suppliers & Origins
Pickup Locations
Import / Export
Integrations
Logs & Diagnostics
Settings
```

The menu should not overwhelm new staff.

Show advanced areas only to users with the needed capability.

For example:

| Role              | Main visible sections                                                |
| ----------------- | -------------------------------------------------------------------- |
| Product Manager   | Products & Rules, Delivery Offers, Logistics Profiles                |
| Logistics Manager | Dashboard, Shipments, Offers, Rate Cards, Zones, Suppliers & Origins |
| Customer Support  | Dashboard, Shipments                                                 |
| Store Staff       | Shipments, Pickup-related orders only                                |
| Administrator     | All sections                                                         |
| Vendor            | Nothing by default; optional restricted access later                 |

---

# 72. Delivery Engine dashboard

## Purpose

The dashboard is an operational control surface, not a marketing dashboard.

It should answer:

```text id="rx3g4n"
What shipments need attention?
Which orders have incomplete delivery data?
Which products cannot be purchased because delivery rules are missing?
Which delivery offers or rate cards are broken?
Which shipments are delayed?
```

## Dashboard cards

Show:

```text id="0bwj83"
Awaiting fulfilment
Processing
Dispatched
In transit
Delayed / issue
Delivered today
No tracking link
No estimated delivery range
Incomplete product rules
Missing rate cards
```

Each card should link to a filtered list.

## Dashboard alerts

Examples:

```text id="l8gsar"
12 products are marked International Fulfilment but have no eligible Air or Sea offer.

4 delivery offers have no rate card for Ghana — Greater Accra Central.

3 paid orders could not create shipment records.

7 shipments are marked Delayed / issue.

2 variable products have invalid delivery-rule overrides.
```

Do not show private supplier cost totals on the default dashboard unless a role has permission.

---

# 73. Product Rules screen

## Purpose

This screen helps staff configure delivery behavior for products without forcing them to understand all global logistics settings.

It should support:

```text id="t59hnd"
Single product editing
Variation overrides
Bulk editing
Search
Filters
Validation
Links to WooCommerce product edit screen
```

## List view columns

```text id="uttft1"
Product
SKU
Fulfilment Availability
Logistics Profile
Eligible Delivery Offers
Default Offer
Supplier / Origin indicator
Variation Overrides
Rule Status
Last Updated
```

Use simple rule-status badges:

```text id="yccsjw"
Complete
Needs setup
Warning
Invalid
Inherited
Variation override
```

## Filters

```text id="40zfn9"
International Fulfilment
In Store
In Warehouse
No rule
Air enabled
Sea enabled
Store Pickup enabled
No rate card
Has variation override
Supplier
Logistics profile
```

## Product rule editor

Use progressive disclosure.

### Section A — Fulfilment availability

```text id="r9uuv9"
Where is this product fulfilled from?

○ International Fulfilment
  Product is outside the local market and ships by Air and/or Sea.

○ In Store
  Product is available in a customer pickup location.

○ In Warehouse
  Product is in a warehouse and is delivered locally.
```

### Section B — Customer fulfilment choices

The available fields should change based on the selected availability.

#### International Fulfilment

```text id="nl3gdx"
Customer fulfilment:
Delivery Only

Available delivery offers:
☑ Sea Shipping
☑ Air Shipping

Default offer:
Sea Shipping
```

#### In Store

```text id="xq4isg"
Customer fulfilment:
☑ Delivery
☑ Store Pickup

Default customer choice:
Delivery

Pickup location:
Accra Branch
```

#### In Warehouse

```text id="j39ifd"
Customer fulfilment:
Delivery Only

Available local delivery offers:
☑ Standard Delivery
☑ Same-Day Express Courier
```

### Section C — Logistics setup

```text id="vx3s3a"
Logistics Profile
Supplier
Origin
Consolidation behavior
Dispatch override
Handling restriction
```

Supplier and origin fields must be visibly marked:

```text id="bgu8v6"
Internal only — never shown to customers
```

### Section D — Delivery overrides

```text id="mni7s4"
Use offer defaults
or
Override processing time
Override transit time
Override final-mile time
Override delivery price
Override consolidation rule
```

Do not show all advanced fields by default.

Use an “Advanced delivery settings” expandable panel.

## Product rule warnings

Examples:

```text id="ywz4l2"
This product is International Fulfilment but no Air or Sea delivery offer is enabled.

This product is In Store but no pickup location is selected.

This product allows Store Pickup but no pickup readiness estimate is configured.

This product has a delivery offer but no matching rate card for the selected destination zones.

This variation overrides the parent rule but has no eligible delivery offer.

This product is marked Delivery Only, but its default delivery offer is disabled.
```

---

# 74. Variation delivery-rule editor

Variation rules must be simple enough that staff do not accidentally duplicate parent settings.

Each variation should show:

```text id="ds1fct"
Use parent delivery rule
```

This must be enabled by default.

When disabled, show only the fields that can differ:

```text id="c4znbp"
Fulfilment availability
Logistics profile
Eligible delivery offers
Default offer
Supplier
Origin
Price override
Time override
Consolidation rule
```

Use clear inheritance text:

```text id="x5jg9p"
Currently inheriting:
International Fulfilment
Sea Shipping and Air Shipping
Standard Parcel
Supplier A
```

Do not show raw supplier ID to ordinary product staff unless their role permits it.

---

# 75. Delivery Offers screen

## Purpose

This screen manages reusable customer-facing services.

Examples:

```text id="yphche"
Sea Consolidated
Sea Priority
Air Economy
Air Express
Standard Delivery
Scheduled Delivery
Same-Day Express Courier
Store Pickup
```

## Offer list columns

```text id="gfzlpi"
Offer
Route
Service Level
Carrier Display
Price Strategy
Processing
Transit
Final Mile
Eligible Availability
Active
Used By
```

## Delivery offer editor

### Basic information

```text id="p4bihc"
Internal name
Customer label
Route
Service level
Description
Display priority
Enabled
```

### Carrier display

```text id="iqzfyp"
○ Carrier assigned by store
○ Show named carrier

Carrier name:
[ DHL Express / Bolt / Uber Connect / CETECH Express ]
```

### Time estimate

```text id="gvxkn8"
Processing / dispatch:
Minimum
Maximum
Business days / calendar days

Main transit:
Minimum
Maximum
Business days / calendar days

Final-mile delivery:
Minimum
Maximum
Business days / calendar days
```

For Same-Day Express Courier, allow hours:

```text id="nu1e43"
Estimated delivery:
2–6 hours
```

### Eligibility

```text id="299bs3"
Available for:
☑ International Fulfilment
☑ In Store
☑ In Warehouse

Available routes:
Air
Sea
Local Delivery
Store Pickup
```

Do not allow inconsistent selections.

Example:

```text id="wrz8ax"
An Air Shipping offer cannot be enabled for In Warehouse unless explicitly configured by the business.
```

---

# 76. Destination Zones screen

## Purpose

Enable staff to define geographical rules without creating unmanageable country-by-country product duplication.

## Zone list

```text id="s15kcv"
Zone
Country
Region/State
City
Postal-code rule
Remote-area flag
Priority
Fallback
Status
```

## Zone editor

Use a “match conditions” style interface.

```text id="xcr34m"
Country: Ghana

Optional conditions:
Region: Greater Accra
City: Accra
Postal-code prefix: GA-
Remote-area rule: No

Priority: 100
```

Explain priority clearly:

```text id="03zx1z"
More specific and higher-priority zones are matched before broad fallback zones.
```

## Zone test tool

Every zone screen must include:

```text id="ucgec2"
Test an address
```

Inputs:

```text id="00h616"
Country
Region
City
Postcode
```

Output:

```text id="njqpxu"
Matched zone:
Greater Accra Central

Fallback used:
No
```

This prevents staff from guessing which zone applies.

---

# 77. Rate Cards screen

## Purpose

Rate Cards define what the customer pays.

They should support simple configuration first and advanced formulas only where needed.

## Rate Card list columns

```text id="31kham"
Delivery Offer
Destination Zone
Logistics Profile
Supplier/Origin Scope
Charge Method
Base Currency
Base Amount
Manual Currency Overrides
Effective Dates
Priority
Status
```

## Rate Card editor

### Step 1 — Scope

```text id="x52h6p"
Delivery Offer
Destination Zone
Logistics Profile
Optional supplier/origin restriction
```

### Step 2 — Price method

```text id="o7pk0p"
○ Fixed per shipment
○ Fixed per item
○ Fixed per cart line
○ Base fee + additional item fee
○ Base fee + weight increments
○ Weight band
○ Highest eligible item fee
○ Product-specific override
```

### Step 3 — Customer price

For fixed per shipment:

```text id="okd8fh"
Customer price:
GHS 120
```

For base plus increments:

```text id="fmhmvk"
Base shipment fee:
GHS 500

Included weight:
0.5 kg

Additional 0.5 kg:
GHS 120
```

### Step 4 — Currency behavior

Without WCML:

```text id="8d0cl0"
Use WooCommerce store currency
```

With WCML:

```text id="oj4em1"
Use automatic conversion
or
Set manual prices by currency
```

### Step 5 — Test quote

```text id="vwuh2t"
Test this rate card
```

Inputs:

```text id="o6ceaf"
Product
Variation
Quantity
Weight
Destination
Selected offer
Currency
```

Output:

```text id="cbr414"
Shipping charge:
GHS 120

Estimated delivery:
Today, 2–6 hours
```

No rate card should be published until its test produces a valid result.

---

# 78. Suppliers and Origins screen

## Purpose

Maintain internal fulfilment data without exposing it to customers.

## Supplier editor

```text id="jhmofd"
Supplier code
Supplier name
Internal contact details
Default origin
Default processing rules
Internal notes
Active/inactive
```

## Origin editor

```text id="0btni2"
Origin code
Country
City/region
Source type
Dispatch calendar
Internal notes
Active/inactive
```

Supplier and Origin records must have strong warning text:

```text id="jek6q5"
Private operational information.
Never shown on product pages, checkout, emails, order history, or public APIs.
```

---

# 79. Pickup Locations screen

Pickup Locations are required only for products configured as In Store with Store Pickup enabled.

## Pickup location fields

```text id="sh2k6h"
Location name
Public address
Public opening hours
Public pickup instructions
Contact phone/email
Pickup readiness estimate
Active/inactive
```

Do not confuse Pickup Location with private warehouse origin.

A pickup location can be public.

An origin can remain private.

---

# 80. Shipment workspace

## Purpose

This is the central operational screen after payment.

It must show one shipment at a time, not merely an order summary.

## Shipment header

```text id="4x2jl7"
Shipment number
Order number
Current status
Delivery offer
Customer delivery estimate
Tracking state
Created date
Last updated
```

## Main shipment sections

### Customer-facing delivery summary

```text id="g3tlwy"
Delivery service
Estimated delivery to address
Tracking link
Public status
Public customer note
```

### Shipment items

```text id="p85z0i"
Product
Variation
SKU
Quantity
Customer fulfilment choice
Selected delivery offer
```

### Internal operations

```text id="adisro"
Supplier
Origin
Logistics profile
Private cost
Consolidation group
Internal notes
```

### Status timeline

```text id="1zjlxq"
Awaiting fulfilment
Processing
Dispatched
In transit
Delayed / issue
Delivered
Cancelled
```

### Tracking

```text id="wd38it"
Carrier name
Tracking number
Tracking URL
Dispatch date
```

## Staff actions

```text id="oh4ekw"
Update status
Add tracking
Add public note
Add internal note
Change estimated delivery
Split shipment
Open linked order
View audit history
```

For Version 1, do not show:

```text id="7tllnp"
Driver assignment
GPS
OTP
QR
Proof-of-delivery
Photo upload
Signature
Buyer confirmation
```

---

# 81. Customer-facing product page UX

## 81.1 International Fulfilment product

Use a compact, clear delivery card after variation selection and before Add to Cart.

Example:

```text id="glkas7"
Delivery Only

Choose delivery option

Sea Shipping
GHS 350
Processing: 3–6 business days
Main transit: 20–30 business days
Estimated delivery to your address: 25–41 business days

Air Shipping
GHS 900
Processing: 2–4 business days
Main transit: 7–15 business days
Estimated delivery to your address: 10–22 business days
```

Customer experience rules:

```text id="w4qf9k"
Do not show supplier/origin.
Do not show private freight calculations.
Do not make the customer select Delivery itself; Delivery is locked.
Show one offer automatically if only one is available.
Show a default offer if multiple are available.
Allow customer to choose another valid offer.
Update item total visibly when delivery offer changes.
```

Example total display:

```text id="9ppe5w"
Product: GHS 1,200
Selected delivery: Sea Shipping — GHS 350
Item total before tax: GHS 1,550
```

## 81.2 In Store product

```text id="g4hs36"
In Store — Accra Branch

● Delivery
GHS 60
Estimated delivery: 1–3 business days

○ Store Pickup
Free
Ready for pickup: 1–2 business days
```

When Store Pickup is selected:

```text id="y9sakq"
Hide delivery rate.
Hide courier/carrier options.
Hide doorstep delivery estimate.
Show pickup location.
Show pickup readiness estimate.
Show pickup instructions.
```

## 81.3 In Warehouse product

```text id="ba8h7t"
In Warehouse

Delivery Only

Standard Delivery
GHS 60
Estimated delivery: 1–3 business days

Same-Day Express Courier
GHS 120
Estimated delivery: today, approximately 2–6 hours
```

The customer cannot select Store Pickup unless the product is configured as In Store.

---

# 82. Customer-facing cart UX

Every cart line should show the customer’s selected fulfilment information.

Example international product:

```text id="2c0hk1"
Product A

Delivery: Sea Shipping
Shipping charge: GHS 350
Estimated delivery to your address: 25–41 business days

Change delivery option
```

Example pickup product:

```text id="24oh2n"
Product B

Fulfilment: Store Pickup
Pickup location: Accra Branch
Pickup charge: Free
Ready for pickup: 1–2 business days

Change fulfilment option
```

## Cart change rules

A “Change delivery option” action must:

```text id="a7bzhk"
Open only eligible choices.
Revalidate destination.
Recalculate price server-side.
Recalculate delivery estimate.
Update cart totals.
Retain separate cart lines where selections differ.
Never expose internal supplier/origin data.
```

Do not allow a cart-side change to bypass product eligibility rules.

---

# 83. Customer-facing checkout UX

Checkout must be shipment-aware.

Example:

```text id="ki3f9r"
Your deliveries

Shipment 1
Sea Shipping
Product A
Shipping: GHS 350
Estimated delivery to your address: 25–41 business days

Shipment 2
Air Shipping
Product B
Shipping: GHS 900
Estimated delivery to your address: 10–22 business days
```

For store pickup:

```text id="e1z7os"
Pickup item
Store Pickup — Accra Branch
Ready for pickup: 1–2 business days
Pickup charge: Free
```

Rules:

```text id="6bkmov"
Do not show one generic delivery estimate for the entire cart.
Do not show unrelated default Flat Rate methods.
Do not let pickup items contribute delivery shipping cost.
Do not let a customer switch to an invalid service after price calculation.
Recalculate if shipping address changes.
Clearly display updated price/estimate before payment.
```

---

# 84. Customer order confirmation and My Account UX

## Order confirmation page

Immediately after payment, show a shipment summary.

```text id="d38pvi"
Thank you. Your order has been received.

Your deliveries

Shipment 1 — Sea Shipping
Status: Awaiting fulfilment
Estimated delivery: 25 August–8 September

Shipment 2 — Air Shipping
Status: Awaiting fulfilment
Estimated delivery: 12–20 August
```

## My Account order view

Each shipment should appear as a separate card.

```text id="uktfdu"
Shipment 1
Sea Shipping
Status: In transit
Estimated delivery: 25 August–8 September
Track shipment

Shipment 2
Air Shipping
Status: Processing
Estimated delivery: 12–20 August
```

Customer status labels should remain simple:

```text id="wsz5r2"
Awaiting fulfilment
Processing
Dispatched
In transit
Delayed
Delivered
Cancelled
```

Do not show:

```text id="umqufu"
Supplier
Origin
Internal warehouse
Internal carrier margin
Internal cost
Private notes
```

---

# 85. Customer-facing copy library

Use these exact or closely equivalent labels.

## Availability labels

```text id="qtaf8h"
Delivery Only
In Store
In Warehouse
```

## Fulfilment labels

```text id="buccxc"
Delivery
Store Pickup
```

## Delivery detail labels

```text id="lqjibk"
Processing / dispatch
Main transit
Estimated delivery to your address
Shipping charge
Pickup location
Ready for pickup
Tracking available after dispatch
```

## Customer notices

### No valid delivery option

```text id="rluq2u"
This item is currently unavailable for delivery to the selected address.
Please change your delivery address or choose another available item.
```

### Delivery option updated

```text id="q3xs94"
Your delivery option has been updated for the selected address.
Please review the new delivery price and estimated delivery time before checkout.
```

### Tracking unavailable

```text id="juih2s"
Tracking details will appear here after your shipment has been dispatched.
```

### Multiple shipments

```text id="0ttykm"
Items in your order may arrive separately because they can be prepared and delivered at different times.
```

### Delayed shipment

```text id="f8qdg6"
Your delivery is taking longer than originally estimated. We are working to update you as soon as possible.
```

---

# 86. Validation and error-message library

Validation messages must be specific, calm, and actionable.

## Product configuration errors for staff

```text id="b1a35z"
This product is International Fulfilment but has no eligible Air or Sea delivery offer.

This product is In Store but has no pickup location.

This product allows Store Pickup but no readiness estimate is configured.

This variation has a delivery override but no valid delivery offer.

This delivery offer has no rate card for the selected destination zone.

This rate card is active but does not have a customer price.

This shipment has no linked order items.

This shipment cannot be marked Delivered because it has not been created correctly.
```

## Customer-side errors

```text id="zra14z"
Please select a delivery option before adding this item to your cart.

This delivery option is not available for the selected address.

Your delivery price changed after your address was updated. Please review your order total.

Store Pickup is not available for this item.

This item is currently unavailable for delivery to the selected destination.

We could not calculate delivery for this item. Please contact support before placing your order.
```

## Avoid customer-facing technical errors

Never show:

```text id="enp9ax"
Rate card missing
Supplier mismatch
Origin unavailable
Consolidation error
API error
Database error
Meta key missing
```

---

# 87. Loading, empty, and fallback states

## Product page loading

When variation or destination changes:

```text id="7i017q"
Updating delivery options…
```

Keep the Add to Cart button disabled only during a genuine required recalculation.

## Empty delivery offers

```text id="1bjh8v"
Delivery options are not currently available for this item and destination.
```

Provide a support link only if the business supports manual assistance.

## Empty shipment list

```text id="am6s2b"
No delivery shipments have been created for this order yet.
```

This should be rare and visible to staff as a configuration/problem state.

## Empty tracking state

```text id="6vnvcu"
Tracking details will appear after dispatch.
```

---

# 88. Accessibility requirements

The delivery selector must be accessible.

Required:

```text id="x3n84g"
Keyboard-accessible radio controls.
Visible focus state.
Correct label/input associations.
Screen-reader announcements when price/estimate updates.
Do not rely only on colour to show selected option.
Sufficient contrast.
Responsive touch targets.
Error messages linked to invalid controls.
No inaccessible custom dropdowns for essential selection.
```

For dynamic price changes:

```text id="0y6wxd"
Use aria-live region for:
Delivery price updated
Estimated delivery updated
Selected option unavailable
```

Do not place important delivery text only inside icons or hover tooltips.

---

# 89. Mobile UX requirements

Many customers will use mobile devices.

Mobile product-page rules:

```text id="iokf1t"
Delivery cards must stack vertically.
Price must remain visible.
Time estimate must remain readable.
Do not hide required options behind accordions by default.
Use large tap targets.
Avoid horizontal overflow.
Keep Add to Cart visible after selection.
```

Mobile cart/checkout rules:

```text id="q7e5ya"
Show shipment grouping without dense tables.
Keep shipping amount near the related shipment.
Do not make customer scroll excessively to understand total delivery cost.
```

---

# 90. First real deployment configuration

The first deployment should not attempt to configure every country, every product, every supplier, every carrier, and every rule type.

Begin with a deliberately small but representative model.

## 90.1 Initial destination zones

For a Ghana-focused first deployment:

```text id="myv8e1"
Greater Accra Central
Greater Accra Outer Areas
Ashanti / Kumasi
Other Regional Capitals
Remote Areas
```

Use one fallback Ghana zone only if the business can honour a reliable fallback price/estimate.

For international customer destinations, configure only countries actively served at launch.

Do not create worldwide zones until the business has price and operational data.

## 90.2 Initial Logistics Profiles

Start with:

```text id="pj4i2w"
Small Parcel
Standard Parcel
Large Parcel
Bulky Item
Fragile Goods
Restricted Goods
```

Do not begin with dozens of profiles.

## 90.3 Initial Delivery Offers

Start with:

```text id="f8kc6m"
Sea Shipping
Air Shipping
Standard Delivery
Same-Day Express Courier
Store Pickup
```

Add carrier-specific offers only where the business genuinely wants customers to choose a named carrier.

Example:

```text id="u04z7t"
Same-Day Express Courier
Carrier assigned by store
GHS 120
Estimated delivery: 2–6 hours
```

This is safer than initially showing Uber, Bolt, or Yango separately if operations may assign whichever partner is available.

## 90.4 Initial product pilot

Use:

```text id="lzi716"
5 International Fulfilment products
2 In Store products
2 In Warehouse products
At least 2 variable products
At least 1 product with Air and Sea choices
At least 1 product with Store Pickup
At least 1 product with Same-Day Express Courier
```

## 90.5 Initial price model

Use only:

```text id="c3osmr"
Fixed per shipment
Fixed per item
```

Do not start with complex weight, volumetric, threshold, highest-fee, or multi-origin formulas unless the business already has reliable data and staff training.

## 90.6 Initial shipment policy

Use:

```text id="2f5nw6"
Manual shipment creation from paid orders
Manual shipment-status updates
Manual tracking-link entry
Manual delay notes
```

Do not automate carrier dispatch or tracking in the first release.

---

# 91. Staff SOP: setting up a new product

## International Fulfilment product

```text id="j6r9jo"
1. Create or edit the WooCommerce product.
2. Set normal product data, price, stock, images, and variations.
3. Open Delivery & Fulfilment.
4. Choose International Fulfilment.
5. Assign Logistics Profile.
6. Assign private Supplier and Origin.
7. Enable Sea and/or Air delivery offers.
8. Choose default offer.
9. Confirm rate cards exist for intended destination zones.
10. Save.
11. Test product page, cart, and checkout.
```

## In Store product

```text id="yykvf7"
1. Create or edit product.
2. Choose In Store.
3. Select Pickup Location.
4. Enable Delivery and Store Pickup.
5. Set Delivery as default.
6. Confirm pickup readiness estimate.
7. Confirm delivery offer/rate card.
8. Save and test.
```

## In Warehouse product

```text id="6jbrbx"
1. Create or edit product.
2. Choose In Warehouse.
3. Assign Logistics Profile.
4. Enable Delivery Only.
5. Select Standard Delivery and/or Same-Day Express Courier.
6. Confirm destination-zone pricing.
7. Save and test.
```

---

# 92. Staff SOP: updating a delivery price

```text id="wmrm3z"
1. Open Rate Cards.
2. Find the affected delivery offer and destination zone.
3. Confirm whether the change is temporary or permanent.
4. Create a new effective rate or update the active rate according to policy.
5. Test quote with representative products.
6. Test in relevant currencies where WCML is active.
7. Publish.
8. Confirm cart/checkout result.
9. Do not alter existing paid-order snapshots.
```

---

# 93. Staff SOP: shipment update

```text id="grfzbe"
1. Open Shipments.
2. Open the relevant shipment.
3. Confirm linked order and items.
4. Update status.
5. Add tracking number/link where available.
6. Add public note only where useful.
7. Add private note if operational detail is needed.
8. Save.
9. Confirm customer-facing timeline shows safe status only.
```

---

# 94. Staff SOP: handling a delayed shipment

```text id="gt9xpi"
1. Open shipment.
2. Confirm actual operational delay.
3. Change status to Delayed / issue.
4. Update estimated delivery only when a better estimate exists.
5. Add a clear public note.
6. Add private root-cause note.
7. Notify customer through the normal order communication process if required.
8. Do not reveal supplier or origin details.
```

Recommended public note:

```text id="r4bdh4"
Your shipment is taking longer than originally estimated. We are working to provide an updated delivery estimate.
```

---

# 95. First-release operational boundaries

The first release must remain manageable.

It should not attempt to become:

```text id="ivbdce"
A carrier marketplace
A driver-management system
A full warehouse system
A route-optimization system
A courier dispatch system
A proof-of-delivery platform
A supplier portal
A live freight-quote engine
A returns-management platform
```

The first release is successful when staff can correctly:

```text id="3znrzv"
Configure products
Show valid delivery choices
Charge correct delivery price
Preserve choices through checkout
Create separate shipments
Update status
Add tracking links
Let customers view shipment progress
```

# Master AI Handoff: Reusable WooCommerce Delivery & Fulfilment Engine

## 1. Project identity

Build a reusable WordPress/WooCommerce plugin named provisionally:

```text
WooCommerce Delivery & Fulfilment Engine
```

It is a delivery, fulfilment-choice, shipping-price, shipment-status, and tracking layer for multiple similar but independent WooCommerce sites.

It is not a generic product-add-ons plugin. It is not only a flat-rate shipping plugin. It is not a driver app, courier marketplace, proof-of-delivery platform, warehouse-management system, supplier marketplace, or carrier-API system.

The initial reference deployment is a WoodMart/WooCommerce store, but the plugin must remain reusable and not hardcode any store brand, supplier, country, currency, carrier, city, or delivery policy.

WooCommerce is the only mandatory dependency.

WoodMart, WPML, WCML, WCFM, VitePOS, Redis, WP Rocket, carrier APIs, and tracking plugins are optional integrations.

---

## 2. Core purpose

The plugin must let each product or variation state:

* where it is operationally fulfilled from;
* what fulfilment choices a buyer may make;
* whether it is Delivery Only, In Store, or In Warehouse;
* whether Air Shipping, Sea Shipping, Local Delivery, Same-Day Express Courier, or Store Pickup is available;
* the customer-facing delivery price;
* processing/dispatch time;
* main transit time;
* final-mile time where relevant;
* estimated delivery to the buyer’s address;
* whether products can consolidate into one shipment;
* how separate shipments should be created;
* how staff update shipment status;
* how the customer sees delivery status and tracking.

The system must preserve the customer’s selected fulfilment/delivery choice from product page through cart, checkout, payment, order, shipment creation, and customer account.

---

## 3. Fundamental business rules

### Do not use Air or Sea as product variations

Air Shipping and Sea Shipping are fulfilment choices, not product attributes.

Do not create combinations such as:

```text
Colour × Size × Air/Sea
```

Existing product variations such as colour, size, model, capacity, or style remain ordinary WooCommerce variations.

Delivery choices remain separate.

### Do not hide delivery as a product add-on

Delivery cost must remain a genuine WooCommerce shipping charge.

Do not add a delivery amount to the product price and also create a shipping charge.

Do not use a product-options plugin as the authoritative delivery engine.

### One order may create multiple shipments

A customer may pay once but receive several shipments.

Example:

```text
Product A:
Sea Shipping — GHS 350

Product B:
Air Shipping — GHS 900

Customer pays:
Product subtotal + GHS 350 + GHS 900
```

The system creates:

```text
Shipment 1:
Sea Shipping
Product A

Shipment 2:
Air Shipping
Product B
```

### Same route does not always mean same shipment

Two Air items may still require separate shipments when they differ in:

```text
Supplier
Private origin
Dispatch window
Carrier/service
Logistics profile
Handling restrictions
Destination rules
```

### Suppliers and origins remain private

Suppliers and origins are necessary internally for staff, shipment grouping, dispatch timing, and internal costing.

They must never appear in:

```text
Product pages
Cart
Checkout
Customer emails
My Account
Tracking links
Public APIs
SEO/schema output
Google feeds
Customer-visible order notes
```

---

## 4. Canonical terminology

Use these exact concepts consistently.

| Term                               | Meaning                                                            |
| ---------------------------------- | ------------------------------------------------------------------ |
| Fulfilment Availability            | Product state: International Fulfilment, In Store, or In Warehouse |
| Fulfilment Choice                  | Buyer choice: Delivery or Store Pickup                             |
| Delivery Route                     | Air, Sea, Local Delivery, or Store Pickup                          |
| Delivery Offer                     | Complete customer-selectable delivery service                      |
| Service Level                      | Economy, Standard, Express, Same-Day, Consolidated, Priority       |
| Carrier                            | Named delivery provider where publicly shown                       |
| Carrier Assigned by Store          | Store chooses operational provider later                           |
| Processing / Dispatch              | Time before product is prepared and dispatched                     |
| Main Transit                       | Main transport period                                              |
| Final-mile Delivery                | Local movement to the buyer’s address                              |
| Estimated Delivery to Your Address | Customer-facing expected delivery range                            |
| Logistics Profile                  | Internal operational transport/handling classification             |
| Shipment                           | Distinct fulfilment unit inside one WooCommerce order              |
| Supplier / Origin                  | Private operational source information                             |
| Rate Card                          | Price rule for a delivery offer and destination context            |

Do not use “Arrival” as the primary customer-facing promise. It can mean arrival at a port, airport, warehouse, or destination country.

Use:

```text
Estimated delivery to your address
```

---

## 5. Product fulfilment availability model

Every product or variation must have one fulfilment availability rule.

### A. International Fulfilment

Use where product is outside the local market or requires cross-border freight.

```text
Customer label: Delivery Only
Fulfilment choice: Delivery, locked
Allowed routes: Air Shipping and/or Sea Shipping
Store Pickup: unavailable
```

If only one valid delivery offer exists, preselect it automatically.

If multiple offers exist, show all valid offers and allow the customer to choose.

Example:

```text
Delivery Only

Sea Shipping
GHS 350
Processing: 3–6 business days
Main transit: 20–30 business days
Estimated delivery to your address: 25–41 business days

Air Shipping
GHS 900
Processing: 2–4 business days
Main transit: 7–15 business days
Estimated delivery to your address: 10–22 business days
```

### B. In Store

Use where the item is physically available at a customer pickup location.

```text
Customer label: In Store
Default fulfilment choice: Delivery
Alternative choice: Store Pickup
```

When customer selects Store Pickup:

```text
Remove delivery cost.
Remove delivery/carrier choices.
Remove doorstep delivery estimate.
Show pickup location.
Show pickup readiness estimate.
Show pickup instructions.
```

Example:

```text
In Store — Accra Branch

● Delivery
GHS 60
Estimated delivery: 1–3 business days

○ Store Pickup
Free
Ready for pickup: 1–2 business days
```

### C. In Warehouse

Use where product is locally held but not available for walk-in collection.

```text
Customer label: In Warehouse
Fulfilment choice: Delivery Only
Store Pickup: unavailable
Air/Sea: normally unavailable
```

Allowed offers may include:

```text
Standard Delivery
Scheduled Delivery
Same-Day Express Courier
```

Example:

```text
In Warehouse

Delivery Only

Standard Delivery
GHS 60
Estimated delivery: 1–3 business days

Same-Day Express Courier
GHS 120
Estimated delivery: today, approximately 2–6 hours
```

---

## 6. Delivery Offer model

A buyer selects a complete Delivery Offer, not merely a carrier name.

A Delivery Offer includes:

```text
Route
Service level
Carrier visibility rule
Carrier name, if visible
Customer price
Processing range
Transit range
Final-mile range
Estimated delivery calculation
Destination eligibility
Product/variation eligibility
Display priority
```

Examples:

```text
Sea Consolidated
Carrier assigned by store
GHS 350
Processing: 3–6 business days
Transit: 20–30 business days
Estimated delivery: 25–41 business days
```

```text
Air Express — DHL Express
GHS 900
Processing: 2–4 business days
Transit: 7–12 business days
Estimated delivery: 10–19 business days
```

```text
Same-Day Express Courier
Carrier assigned by store
GHS 120
Estimated delivery: today, 2–6 hours
```

A business may expose named carrier choices only where it can reliably honour them.

Otherwise show:

```text
Carrier assigned by the store
```

---

## 7. Manual delivery pricing policy

Version 1 uses manually configured customer-facing delivery prices.

No live quote is required.

No carrier API is required.

No quote approval flow is required.

For each delivery offer, staff may configure:

```text
Customer checkout price
Internal estimated carrier cost
Internal margin/buffer
Destination eligibility
Time estimate
```

Customer pays the visible configured price during ordinary checkout.

If actual later carrier cost differs, the business absorbs the difference or retains the margin.

Do not charge customers an unknown delivery amount after payment.

---

## 8. Destination-zone strategy

Do not create separate rules for every:

```text
Product × country × city × currency
```

Use layered destination zones:

```text
Country
→ Region/state
→ City/metro
→ Postcode/area group
→ Remote area
```

Only add detail where price, delivery time, or eligibility changes.

Example:

```text
Ghana
├── Greater Accra Central
├── Greater Accra Outer Areas
├── Ashanti / Kumasi
├── Other Regional Capitals
└── Remote Areas
```

A Rate Card is resolved from:

```text
Delivery Offer
+ Destination Zone
+ Logistics Profile
+ Supplier/origin where needed
+ Product/variation rule
+ Quantity/weight/volume rule
```

The shipping address is authoritative.

IP geolocation may assist initial display but must never be treated as final delivery pricing truth.

---

## 9. Logistics Profiles

Use a generic internal field called:

```text
Logistics Profile
```

Do not use electronics-specific profile names as the main model.

Suggested initial profiles:

```text
Small Parcel
Standard Parcel
Large Parcel
Bulky Item
Oversized Freight
Fragile Goods
High-Value Goods
Restricted Goods
Made-to-Order Item
```

A Logistics Profile may determine:

```text
Parcel class
Weight/volume behavior
Handling requirements
Route eligibility
Consolidation eligibility
Packing requirement
Dispatch type
Special restrictions
```

Do not call this field “Shipping Class,” because WooCommerce already has native Shipping Classes.

---

## 10. Rate-card pricing rules

The plugin must support:

```text
Fixed fee per shipment
Fixed fee per item
Fixed fee per cart line
Base fee + additional-item fee
Base fee + weight increment
Volumetric-weight fee
Weight-band pricing
Highest eligible item fee
Zone surcharge
Remote-area surcharge
Free-delivery threshold
Product/variation override
```

For initial deployment, prefer only:

```text
Fixed per shipment
Fixed per item
```

Avoid complex formulas until operations are stable.

A missing rate card must never create a free shipping charge by accident.

Instead:

```text
Block checkout for that offer
Show customer-safe availability message
Log staff-facing configuration error
```

---

## 11. Consolidation rules

Items may consolidate only when their operational conditions match.

Default consolidation key:

```text
Same supplier/source
Same private origin
Same route
Same service level
Same carrier/partner rule
Same destination zone
Same dispatch window
Compatible logistics profile
No separate-shipment restriction
```

Never consolidate:

```text
Air and Sea
Store Pickup and Delivery
Different suppliers by default
Different origins by default
Restricted and standard items where profile prevents it
Different dispatch windows
Items requiring separate handling
```

A shipment is a real operational grouping, not a cosmetic cart grouping.

---

## 12. Customer delivery estimate rules

The visible delivery estimate is calculated from:

```text
Payment confirmation date
+ processing/dispatch range
+ transit range
+ final-mile range
+ customs/operational buffer where configured
+ destination-zone adjustment where configured
```

Display:

```text
Processing / dispatch
Main transit
Estimated delivery to your address
```

Example:

```text
Processing: 2–4 business days
Main transit: 7–15 business days
Estimated delivery to your address: 10–22 business days
```

For Same-Day offers, allow hour ranges:

```text
Estimated delivery: today, approximately 2–6 hours
```

At checkout, save an immutable snapshot of:

```text
Customer-selected offer
Price
Currency
Estimate
Address/zone context
Rule version
```

Do not recompute historical paid-order shipping totals after prices, rate cards, currencies, or exchange rates change.

---

## 13. Version 1 scope

Version 1 must include:

```text
Fulfilment Availability rules
Delivery Only / In Store / In Warehouse behavior
Air and/or Sea delivery offers
Local Delivery offers
Store Pickup
Manual delivery pricing
Carrier choice or carrier-assigned display
Destination zones
Rate cards
Logistics Profiles
Private suppliers and origins
Product-level rules
Variation overrides
Cart-line delivery selections
Shipment grouping
WooCommerce custom shipping rates
Shipment records
Manual shipment-status updates
Manual tracking number/link entry
Customer shipment timeline
Classic WooCommerce cart/checkout support
WoodMart compatibility through hooks
HPOS compatibility
Optional WPML support
Optional WCML support
Optional WCFM/VitePOS adapters
Admin logs and diagnostics
Bulk product assignment/import
```

Version 1 must exclude:

```text
Buyer confirmation of receipt
OTP
QR confirmation
GPS
Driver accounts
Driver app
Proof of delivery
Photos/signatures
Automatic delivery confirmation
Automatic order completion
Live carrier quotes
Carrier API dispatch
Automatic tracking sync
Route optimization
Warehouse scanning
Returns automation
Supplier portal
Courier marketplace
```

---

## 14. Core architecture

Build one modular WordPress plugin.

WooCommerce owns:

```text
Products
Variations
Cart
Checkout
Taxes
Payments
Orders
Order items
Stock
Refunds
Customer accounts
```

The Delivery Engine owns:

```text
Fulfilment availability
Delivery offers
Pricing rules
Zones
Logistics profiles
Supplier/origin records
Delivery estimates
Shipment grouping
Shipment records
Shipment events
Tracking links
Customer delivery timeline
```

Recommended modules:

```text
Core
Product Rules
Delivery Offers
Destination Zones
Logistics Profiles
Supplier & Origin Registry
Rate Cards and Consolidation
Delivery-Date Estimator
Cart & Checkout Adapter
WooCommerce Shipping Adapter
Shipment Management
Tracking
Customer Account Adapter
Admin Console
WoodMart Adapter
WPML Adapter
WCML Adapter
WCFM Adapter
VitePOS Adapter
Audit & Diagnostics
Import/Export
```

WooCommerce is mandatory.

All other integrations are optional adapters.

---

## 15. Storage model

Use WooCommerce entities for commerce data.

Use custom plugin tables for complex delivery data.

Suggested tables:

```text
{prefix}_delivery_engine_delivery_offers
{prefix}_delivery_engine_destination_zones
{prefix}_delivery_engine_destination_rules
{prefix}_delivery_engine_logistics_profiles
{prefix}_delivery_engine_suppliers
{prefix}_delivery_engine_origins
{prefix}_delivery_engine_rate_cards
{prefix}_delivery_engine_rate_card_rules
{prefix}_delivery_engine_product_rules
{prefix}_delivery_engine_shipments
{prefix}_delivery_engine_shipment_items
{prefix}_delivery_engine_shipment_events
{prefix}_delivery_engine_audit_log
```

Use WooCommerce order item metadata only for customer-safe delivery snapshots, such as:

```text
Fulfilment: Delivery
Delivery service: Sea Shipping
Estimated delivery: 25–41 business days
```

Keep private supplier, origin, internal cost, and internal shipment logic in custom plugin tables or protected order metadata.

Use WooCommerce CRUD for all WooCommerce order operations.

Do not directly depend on legacy post-based order storage.

---

## 16. Product-rule inheritance

Use this precedence:

```text
Variation override
→ Parent product rule
→ Optional category/default rule
→ Site fallback
→ Invalid/unconfigured
```

Default behavior:

```text
Variation inherits parent delivery rule.
```

Only create variation override where something materially differs.

Examples:

```text
Large size is bulky.
One variation is Sea-only.
One variation is held in store while another is international.
One variation has different dispatch time.
```

Do not duplicate parent rules unnecessarily.

---

## 17. Cart and checkout logic

### Product page

Customer chooses:

```text
Product variation, where applicable
→ Fulfilment choice where applicable
→ Delivery Offer where multiple offers exist
```

### Cart item

Store normalized delivery selection with cart line.

The selection must affect cart-line uniqueness.

Therefore:

```text
Product A + Sea
Product A + Air
```

must become two separate cart lines.

### Server-side validation

Never trust delivery price from browser input.

At Add to Cart, Cart, Checkout, and Order creation:

```text
Resolve product rule server-side.
Validate offer eligibility.
Validate destination.
Calculate shipping server-side.
Calculate estimate server-side.
Replace client values with trusted server values.
```

### Shipping packages

Use WooCommerce shipping packages.

Group cart items by shipment/consolidation key.

Register one custom WooCommerce shipping method:

```text
Delivery Engine Selected Offer
```

It returns only the customer’s selected offer for each managed shipment package.

Do not expose generic $0 Air/Sea placeholder methods.

Do not show unrelated default Flat Rate methods for delivery-engine-controlled packages.

---

## 18. Shipment lifecycle

Shipment statuses:

```text
Awaiting fulfilment
Processing
Dispatched
In transit
Delayed / issue
Delivered
Cancelled
```

Basic flow:

```text
Paid WooCommerce order
→ Delivery Engine validates selections
→ Shipment planner groups items
→ Shipment records created
→ Initial status: Awaiting fulfilment
→ Staff updates status manually
→ Staff adds tracking when available
→ Customer sees shipment timeline
```

One order can have several shipments.

A shipment can contain one or several compatible order items.

Do not mark the whole order completed automatically when one shipment is delivered.

The business may later define its own rule for completing an order when all applicable shipments are delivered or collected.

---

## 19. Tracking

Version 1 tracking is manual.

Staff can store:

```text
Carrier display name
Tracking number
Tracking URL
Dispatch date
Public shipment note
```

Customer sees tracking only for the relevant shipment.

No tracking link should appear when unavailable.

No carrier API is required.

---

## 20. Customer frontend behavior

### Product page

Use theme-neutral WooCommerce hooks.

Preferred placement:

```text
After variation selection
Before Add to Cart
```

The selector must react to variation changes.

For International Fulfilment:

```text
Delivery Only
Choose delivery option
Sea Shipping / Air Shipping
Price
Processing
Transit
Estimated delivery to your address
```

For In Store:

```text
Delivery selected by default
Customer may select Store Pickup
Pickup removes delivery cost and estimate
Pickup location/instructions appear
```

For In Warehouse:

```text
Delivery Only
Standard Delivery and/or Same-Day Express Courier
```

### Cart

Show delivery details under each line item.

### Checkout

Show shipment-grouped delivery information.

Do not show one generic estimate for the whole cart where items can arrive separately.

### My Account

Show separate shipment cards with:

```text
Shipment number
Items
Delivery service
Status
Estimated delivery range
Tracking link where available
```

Never show suppliers, origins, internal cost, private notes, or internal route logic.

---

## 21. WPML/WCML strategy

The plugin must work without WPML or WCML.

### Without WPML/WCML

```text
Use site language.
Use WooCommerce base currency.
No integration errors.
```

### With WPML

Translate customer-facing labels and descriptions.

Copy/shared operational data:

```text
Fulfilment availability
Logistics Profile
Supplier ID
Origin ID
Delivery offer IDs
Rate card linkage
Eligibility rules
Internal notes
Consolidation behavior
```

Translate:

```text
Delivery Only
In Store
In Warehouse
Air Shipping
Sea Shipping
Store Pickup
Customer-facing offer descriptions
Public pickup instructions
Customer-facing shipment notes
```

### With WCML

Store canonical price in base currency.

Use WCML conversion where no manual override exists.

Allow optional manual per-currency delivery prices.

Snapshot at checkout:

```text
Base price
Base currency
Charged price
Checkout currency
Override/conversion context
```

Do not use customer currency to determine route eligibility.

Destination determines eligibility.

Currency determines display/payment amount.

---

## 22. WoodMart, WCFM, VitePOS, cache, and HPOS rules

### WoodMart

Do not edit WoodMart parent-theme files.

Do not make plugin logic depend on `variable.php`.

Use WooCommerce hooks and JavaScript events.

Test:

```text
Variation swatches
Quick View
Mini-cart
AJAX Add to Cart
Buy Now
Mobile layout
Cart
Checkout
```

The current WoodMart variable-product template override must be reviewed in staging because it is reported as outdated relative to current WooCommerce core.

### WCFM

Do not expose global rate cards, suppliers, origins, or internal costs to vendors by default.

WCFM integration is optional and must be capability-controlled.

### VitePOS

POS orders must not bypass fulfilment rules.

POS integration is optional and should be tested separately.

### Cache

Cart, Checkout, My Account, delivery AJAX/REST endpoints, and customer shipment screens must not be publicly cached.

Use Redis only for reusable configuration data where cache invalidation is safe.

### HPOS

Use WooCommerce CRUD APIs.

Declare HPOS compatibility.

Do not hardcode access to `wp_posts`, `wp_postmeta`, or HPOS table names.

---

## 23. Administrator interface

Top-level admin menu:

```text
Delivery Engine
```

Submenus:

```text
Dashboard
Shipments
Products & Rules
Delivery Offers
Rate Cards
Destination Zones
Logistics Profiles
Suppliers & Origins
Pickup Locations
Import / Export
Integrations
Logs & Diagnostics
Settings
```

### Dashboard should show

```text
Awaiting fulfilment
Processing
Dispatched
In transit
Delayed
Delivered
No tracking
Incomplete product rules
Missing rate cards
Shipment creation failures
```

### Product Rules should allow

```text
Fulfilment Availability
Logistics Profile
Supplier
Origin
Eligible Delivery Offers
Default Offer
Store Pickup eligibility
Pickup location
Variation overrides
Bulk assignment
CSV import/export
Validation warnings
```

### Shipment workspace should allow

```text
View linked order/items
View private supplier/origin data
Update status
Add tracking
Add public/internal notes
Change delivery estimate with reason
Split shipment where necessary
Open audit history
```

No proof-of-delivery UI in Version 1.

---

## 24. Roles and capabilities

Use granular capabilities.

Examples:

```text
manage_delivery_settings
manage_delivery_offers
manage_delivery_rate_cards
manage_delivery_zones
manage_logistics_profiles
manage_private_sources
manage_product_delivery_rules
manage_shipments
update_shipment_status
view_private_delivery_costs
view_private_origins
manage_delivery_integrations
view_delivery_logs
import_delivery_data
```

Suggested access:

```text
Administrator:
All

Logistics Manager:
Shipments, suppliers/origins, rate cards, zones, offers

Product Manager:
Product delivery rules and profiles

Customer Support:
Shipment visibility, customer-safe notes, tracking visibility

Store Staff:
Pickup and assigned shipment workflow only

Vendor:
No access by default
```

---

## 25. First deployment plan

Do not deploy to every product immediately.

### Phase A: readiness

```text
Create staging clone.
Back up files/database.
Review WooCommerce Status.
Review template overrides.
Confirm HPOS.
Confirm classic checkout vs blocks.
Confirm WPML/WCML state.
Check Action Scheduler health.
Check cache exclusions.
Inventory shipping/delivery plugins.
```

### Phase B: configure base data

```text
Destination Zones
Logistics Profiles
Suppliers/Origins
Delivery Offers
Rate Cards
Pickup Locations
```

### Phase C: pilot products

Use approximately 10–25 representative products:

```text
Sea-only international product
Air-only international product
Air-and-Sea international product
Variable product with inherited rule
Variable product with override
In Store product with pickup
In Warehouse product
Same-Day Express Courier product
```

### Phase D: controlled rollout

```text
Enable product-page selector for pilot products.
Test cart/checkout.
Create real test orders.
Create shipment records.
Test tracking and My Account.
Review support issues.
Expand product coverage only after pilot passes.
```

---

## 26. Acceptance criteria

The plugin is not complete merely because a delivery card appears on the product page.

It is complete only when:

```text
International products can show Air and/or Sea correctly.

In Store products default to Delivery and can switch to Store Pickup.

Store Pickup removes delivery calculations.

In Warehouse products are Delivery Only.

Variable-product rules inherit and override correctly.

Same product can be added twice with different delivery offers.

Cart lines do not merge incorrectly.

Shipping remains genuine WooCommerce shipping.

Shipment packages group correctly.

Mixed Air/Sea orders calculate correctly.

Supplier/origin remains private.

Checkout totals match selected delivery offers.

Paid orders save delivery snapshots.

Shipment records create idempotently.

Staff can manually update statuses and tracking.

Customers can view safe shipment progress.

Plugin works without WPML/WCML.

Plugin works with WPML/WCML where installed.

Plugin works with HPOS.

WoodMart flows are tested.

Cache does not leak one customer’s delivery result to another.

No proof-of-delivery, OTP, QR, GPS, driver, or live-quote feature is required.
```

---

## 27. Non-negotiable rules

```text
Do not use Air/Sea as product variations.

Do not rely on product add-ons as the delivery authority.

Do not hide shipping inside product prices.

Do not expose suppliers or origins publicly.

Do not require WPML/WCML for activation.

Do not assume every site has the same plugins.

Do not edit WoodMart parent files.

Do not use zero-cost placeholder Air/Sea shipping methods.

Do not silently replace selected customer delivery choice.

Do not return free shipping because a rate card is missing.

Do not merge cart lines with different delivery selections.

Do not recalculate historical paid-order delivery prices due to later changes.

Do not reveal internal logistics through customer APIs, emails, My Account, or SEO output.

Do not add proof-of-delivery/driver workflows to Version 1.

Do not bypass WooCommerce HPOS APIs.

Do not deploy directly to production without staging and pilot testing.
```

---

## 28. Final instruction to any AI or developer

**First read:** [CURRENT IMPLEMENTATION STATUS — V1 RC](#current-implementation-status--v1-rc-read-first) at the top of this file. That block is the living map of what is already in code vs still future.

Treat this as a delivery-domain project.

Full intended flow (vision):

```text
WooCommerce Product/Variation
→ Fulfilment Rule Resolver
→ Delivery Offer Resolver
→ Server-side Price/Estimate Engine
→ Cart-Line Delivery Selection
→ Shipment Package Builder
→ WooCommerce Custom Shipping Method
→ Order Delivery Snapshot
→ Shipment Records
→ Staff Shipment Updates
→ Customer Shipment Timeline
```

**As of `1.0.0-rc.1`:** the path through order delivery snapshot + customer/email summaries is implemented behind feature flags. Shipment records, staff shipment updates, and customer shipment timeline are **not** implemented yet.

Keep operational logic private.

Keep customer-facing delivery information clear and truthful.

Keep integrations optional.

Keep Version 1 deliberately simple, manually priced, manually tracked, shipment-aware, and checkout-safe.

Do not add complexity before the core product-to-cart-to-checkout-to-shipment workflow is proven in live operations.
