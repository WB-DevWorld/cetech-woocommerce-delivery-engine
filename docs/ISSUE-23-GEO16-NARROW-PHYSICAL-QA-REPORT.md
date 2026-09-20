# GEO.16 NARROW PHYSICAL QA — OWNER ACCEPTED

Published in-repo copy of the isolated-lab evidence for exact candidate `1.0.0-dev.geo.16`.

On 2026-09-20 `@wbdevworld` accepted this candidate as having completed the required technical and physical qualification for Issue #23. This publication does **not** create RC.12, a CETECH Pilot, or FLAIROC/training/production deployment.

Canonical local evidence path (screenshots, JSON, logs):

`C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-owner-qa-geo16\evidence\`

Original heading at QA completion: GEO.16 NARROW PHYSICAL QA COMPLETE — READY FOR OWNER ACCEPTANCE.

Differential review result (given before this QA): **PASSED.** The geo.15 → geo.16 production correction is confined to the Location Packs admin-form POST contract plus version identity. Previously qualified geo.15 production subsystems are byte-identical and their physical evidence is inherited below.

---

## Identity

| Field | Value |
| --- | --- |
| Identity | `1.0.0-dev.geo.16` |
| Schema | `6` |
| Package-source SHA | `7aeb4c573d04d12d8101c0e05bc8858ff332d63f` |
| Evidence HEAD | `f9615b547fb3a6ac19ed40eb5897993250f60083` |
| ZIP | `cetech-woocommerce-delivery-engine-1.0.0-dev.geo.16.zip` |
| Expected bytes | `1,764,171` |
| Expected SHA-256 | `500b09878ba08b879e5bdfdb0ea6e58726a71fe90a6994a83bb96b1e314a3925` |
| Draft PR | `#24` remains Draft / OPEN / unmerged |

## Exact ZIP bytes / SHA-256

Recomputed **before** testing (`evidence/artifact-verify.json`, 2026-09-20T14:39:14Z):

* Dist + lab copy: **1,764,171** bytes
* SHA-256: **`500b09878ba08b879e5bdfdb0ea6e58726a71fe90a6994a83bb96b1e314a3925`**
* `bytes_match`: true
* `hash_match`: true
* `copied_not_rebuilt`: true

Recomputed **after** QA (`evidence/artifact-verify-after-qa.json`, 2026-09-20T15:07:45Z):

* Dist bytes / SHA-256: unchanged match
* Lab copy bytes / SHA-256: unchanged match
* **`package_bytes_changed`: false**

The geo.16 ZIP was not modified, rebuilt, or substituted.

---

## Lab environment

Isolated Docker WordPress/WooCommerce lab. Not FLAIROC, not training, not production, not POS. Geo.14 and geo.15 labs were left running.

| Item | Value |
| --- | --- |
| Lab root | `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-owner-qa-geo16\` |
| Compose project | `cetech-de-owner-qa-geo16-a` |
| URL | `http://localhost:8104` |
| HTTP port | `8104` |
| Docker subnet | `192.168.64.0/20` (explicit; default pools were exhausted) |
| WordPress | `7.1` (core nags 7.1.1) |
| WooCommerce | `11.0.1` |
| PHP | `8.2.33` (wordpress:php8.2-apache) |
| Database | MariaDB `11.4` |
| Theme | Storefront `4.6.2` |
| Plugin | `1.0.0-dev.geo.16` active |
| Schema target / db_version | `6` / `6` |
| HPOS | yes |
| Currency / store (after seed) | GHS / GH:AA |
| Admin | `qa-admin` with `manage_delivery_zones` |
| Capability-denied user | `qa-editor` (authenticated editor; no `manage_delivery_zones`) |

Install path: exact geo.16 ZIP copied from repository `dist/` into `packages/` and installed into a clean WordPress. No production-file patch in the ZIP.

Lab-only helpers (outside the ZIP): MU POST probe, wp-cli seed/inspect, Playwright scripts, `enable-runtime.php` to apply site-wide defaults so Classic checkout runtime can quote. These are not geo.16 package changes.

---

## Natural Install / update result

Rendered form (Playwright inspect of the live Location Packs page):

* method `post`
* enctype `multipart/form-data`
* hidden `cetech_de_action` = `cetech_de_install_location_pack`
* `cetech_de_nonce` present, length 10
* fields: country_code, pack_file, download_official, pack_op, submit

Positive test: natural browser submit of **Install / update pack** with a small valid Ghana GeoNames `.txt` (3 gazetteer rows). Official GeoNames download was not required.

MU probe (`evidence/geo16-qa-posts.jsonl` line 3, 2026-09-20T14:56:40Z):

```
method=POST
page=cetech-delivery-engine-location-packs
user=qa-admin
user_can_zones=true
post_action=cetech_de_install_location_pack
nonce_post=true
nonce_len=10
country=GH
pack_op=install
```

Result:

* Request reached `LocationPacksPage::handle_actions()` (not a geo.15 no-op).
* Success notice: **Location pack for GH queued. Import continues in the background.**
* Pack row created: GH / geonames / importing.
* No PHP fatal.
* No nonce/action rejection.
* No `lock_lost`.

BJ install (same form, later): `post_action=cetech_de_install_location_pack`, nonce present, country BJ, success notice **Location pack for BJ queued…**

Corrupt US install (error path): same action+nonce; pack status **failed** with **The geography pack file is not valid GeoNames tabular data.**

GH lifecycle: background Action Scheduler did not finish the small pack during a ~3 minute poll. Six natural **Continue / retry** clicks advanced GH through admin2 → admin3 → admin4 → locality → promote → **ready** (`2026-09-20 15:02:29`, checksum `b7cb03e3d3288eec…`). Final GH locations: 1 country + 17 administrative + 1 locality.

Screenshot: `screenshots/02-after-install-gh-small.png`, `screenshots/10-gh-ready.png`.

---

## Actual submitted action / nonce confirmation

| Form | Natural POST proof | Action | Nonce |
| --- | --- | --- | --- |
| Install GH (multipart) | MU probe JSONL | `cetech_de_install_location_pack` | present, len 10 |
| Install BJ (multipart) | MU probe JSONL | `cetech_de_install_location_pack` | present, len 10 |
| Continue BJ | Playwright urlencoded body | `cetech_de_tick_location_pack` | `a25ce7e0be` |
| Reconcile | Playwright urlencoded body | `cetech_de_reconcile_legacy_coverage` | `1e09c143ee` |
| Continue GH ×6 | Playwright urlencoded bodies | `cetech_de_tick_location_pack` pack_id=1 | `b6638cfa06` |
| Install corrupt US | MU probe JSONL | `cetech_de_install_location_pack` | present, len 10 |

BJ Continue body:

`cetech_de_nonce=a25ce7e0be&_wp_http_referer=%2Fwp-admin%2Fadmin.php%3Fpage%3Dcetech-delivery-engine-location-packs&cetech_de_action=cetech_de_tick_location_pack&pack_id=2&pack_op=retry&submit=Continue+%2F+retry`

Reconcile body:

`cetech_de_nonce=1e09c143ee&_wp_http_referer=%2Fwp-admin%2Fadmin.php%3Fpage%3Dcetech-delivery-engine-location-packs&cetech_de_action=cetech_de_reconcile_legacy_coverage&submit=Run+safe+legacy+reconciliation`

Playwright `postData()` is empty for multipart; install nonce/action for multipart was proven by the MU probe on `admin_init`, not by constructing POSTs as the positive test.

---

## Natural Continue / retry result

Rendered Continue / retry form: `cetech_de_action=cetech_de_tick_location_pack`, nonce present len 10, pack_id=2, pack_op=retry.

Clicked the **actual** button (not a constructed POST as the positive test).

* Handler executed (`handle_tick`).
* Request not silently ignored.
* No PHP fatal.
* BJ progress **0 / 0 (admin1)** → **2 / 150 (admin1)**.
* GH later reached **ready** after six actual Continue clicks.

Screenshot: `screenshots/04-after-continue-retry.png`, `screenshots/10-gh-ready.png`.

---

## Natural Reconciliation result

Clicked the actual **Run safe legacy reconciliation** button.

* POST action `cetech_de_reconcile_legacy_coverage` + valid nonce.
* MU probe after_handlers: `schema6.pass_kind` **initial** → **reconciliation**, pass_id `03ca16062ba4c5e0`.
* Success notice: **Safe reconciliation finished. Scanned 1, skipped manual 1, reconciled 0, still review required 0, activated 0.**
* Migration report: `skipped_manual=1`, `reconciled=0`, `warnings=[]`.
* Coverage group id 1 remains `{"origin":"manual"}`, status `active`, root_location_id `1`, review_required `0`.
* `Schema6CoverageUpgradeService::reconcile()` was **not** invoked as the positive physical proof.

Screenshot: `screenshots/05-after-reconcile.png`.

---

## Success / error / warning notice result

No occurrence of:

* `Call to undefined method AdminNoticeService::add_success()`
* `Call to undefined method AdminNoticeService::add_error()`
* `Call to undefined method AdminNoticeService::add_warning()`

Inspect `undefined_notice_api`: `[]`. Inspect `php_fatals`: `[]`. Debug grep: NONE.

Physically rendered Delivery Engine flash notices:

| Kind | Mechanism | Text |
| --- | --- | --- |
| Success | `notice notice-success` / `flash_success` | Location pack for GH queued… |
| Success | `flash_success` | Location pack for BJ queued… |
| Success | `flash_success` | Safe reconciliation finished. Scanned 1, skipped manual 1… |
| Error | `notice notice-error` / `flash_error` | The geography pack file is not valid GeoNames tabular data. |
| Error | `flash_error` | Security check failed. (missing nonce / invalid nonce) |

Delivery Engine `flash_warning` was **not** physically fired: reconciliation returned zero warnings. WordPress core **update-nag** (“WordPress 7.1.1 is available”) is unrelated.

---

## Negative security cases

Controlled requests. Handler contract was not weakened.

Authoritative admin-cookie Playwright results (`evidence/playwright-negative.json`): pack table **unchanged=true** (GH ready, BJ importing, US failed).

| Case | Result |
| --- | --- |
| A missing `cetech_de_action` | HTTP 200, no success, no queued, no mutation |
| B wrong `cetech_de_action` | HTTP 200, no success, no queued, no mutation |
| C missing nonce | HTTP 200, error + **Security check failed**, no mutation |
| D invalid nonce | HTTP 200, error + **Security check failed**, no mutation |
| F GET with action+nonce+country NG | HTTP 200, no success, no queued, no NG pack |
| E user lacking `manage_delivery_zones` | Authenticated editor dashboard 200; Location Packs GET **403**; Location Packs POST **403**; body “Sorry, you are not allowed to access this page.”; `has_zones=false`; no queued success; GH remained ready |

A first PowerShell `WebRequestSession` login landed on `wp-login.php` and is discarded. Playwright editor without AUTH cookies also hit `reauth=1`. The capability case is the later authenticated editor 403 (`evidence/negative-editor.json`).

Screenshots: `screenshots/11-before-negative.png`, `screenshots/12-editor-packs.png`, `screenshots/13-after-negative.png`.

---

## Basic PDP regression smoke

* Plugin activation: OK (`plugin_active` true, version `1.0.0-dev.geo.16`).
* Location Packs page renders (`h1` Location Packs). Screenshot `01-location-packs-render.png`.
* No new PHP fatal.
* No Delivery Engine `pageErrors` / `deConsole` on Location Packs or PDP.
* PDP `QA Warehouse Chair` (`http://localhost:8104/product/qa-warehouse-chair/`): Ghana selected → **Standard Delivery** / 2–3 business days / **Delivery fee: ₵12.00**. MatchingLocationOptionsEndpoint status `ok`, `price_amount` `12.0000`.

Screenshot: `screenshots/09-pdp-chair-after-gh.png`.

Full geo.15 customer matrices were **not** re-run. This smoke did not expose a regression that would require them.

Lab note: Classic checkout runtime stayed inactive until lab-only `enable-runtime.php` applied site-wide defaults. That is the same class of lab seed used on geo.15, not a geo.16 ZIP patch.

---

## P1 / P2 / P3 / environment findings

### P1

None.

### P2

None on the geo.16 form contract. Natural install / continue / reconcile POSTs execute the handlers. The geo.15 Location Packs POST no-op is closed on this candidate.

### P3 (not pulled into geo.16)

Unchanged later cleanup:

* Blocks script dependency warning
* Variable ETA prefix/copy
* Lamp default-selection UX
* Blocks cart totals polish

### Environment / lab observations (not production defects)

* Docker default address pools were exhausted; geo.16 used an explicit `192.168.64.0/20` subnet. Geo.14/geo.15 labs were not stopped.
* Playwright multipart `postData()` is empty; install POST fields were proven with a lab MU probe.
* Action Scheduler did not finish the tiny GH pack during a long poll; the actual Continue button did.
* PowerShell cookie login is unreliable against this WP 7.1 login; Playwright admin cookies and generated editor AUTH cookies were used instead.
* WordPress 7.1.1 core update-nag appears on admin screens.

---

## Inherited geo.15 evidence

The following were **not** re-executed under geo.16. They are inherited because the corresponding production files are byte-identical to the already-qualified geo.15 candidate.

Label: **INHERITED FROM GEO.15 — PRODUCTION BYTES UNCHANGED.**

* automatic RC.11 → schema-6 HTTP conversion
* worker/lease safety
* full Ghana pack import
* 15,349 locality result
* import idempotency
* 20-locality parity
* selected / entire / exclusion behavior
* locality cascade
* overlap priority behavior
* country-only/postcode
* Storefront
* WoodMart
* simple/variable
* Classic
* Blocks
* PDP/cart/checkout pricing parity
* privacy/tamper handling

Geo.15 evidence path: `C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-owner-qa-geo15\evidence\GEO15-PHYSICAL-QA-REPORT.md`.

---

## Scope intentionally excluded

* Merge of Draft PR #24
* Ready-for-review transition
* RC.12
* CETECH Pilot
* FLAIROC / training / production
* POS
* Stage 15
* WPML/WCML
* Next Stable-1.0 wave
* History rewrite
* Rebuild or patch of the geo.16 ZIP
* Full geography QA cycle items listed in the ISSUE #23 brief

---

## Evidence path

Canonical evidence (outside the production ZIP):

`C:\Users\Jane\Desktop\Learning 2026\Cursor\cetech-de-owner-qa-geo16\evidence\`

In-repo pointer: `docs/ISSUE-23-GEO16-PHYSICAL-QA.md`.

Key files:

* `GEO16-NARROW-PHYSICAL-QA-REPORT.md` (this file)
* `artifact-verify.json` / `artifact-verify-after-qa.json`
* `env-manifest.json` / `inspect-final.json` / `final-summary.json`
* `geo16-qa-posts.jsonl` / `debug-relevant.log`
* `playwright-geo16-forms.json` / `playwright-gh-continue.json` / `playwright-negative.json` / `playwright-pdp-smoke.json`
* `negative-editor.json` / `quote-smoke.json`
* `screenshots/01` … `13`

---

## Confirmation: package bytes did not change

After QA, dist and lab copies remain **1,764,171** bytes, SHA-256 **`500b09878ba08b879e5bdfdb0ea6e58726a71fe90a6994a83bb96b1e314a3925`**. `package_bytes_changed=false`.

STOP. Owner acceptance/rejection is required before any merge or release.
