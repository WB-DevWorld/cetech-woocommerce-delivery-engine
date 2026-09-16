# RC.10 finalization

**Document status:** FINAL — owner-qualified core collaboration baseline established  
**Release:** `1.0.0-rc.10`  
**Schema:** `5`  
**Stage 15:** NOT STARTED  
**Owner QA:** PASS  
**Date:** 2026-09-16  
**FLAIROC / training / production:** NOT MODIFIED

---

## 1. Final verdict

**RC.10 RELEASE FINALIZATION COMPLETE.**

Owner authorization:

`OWNER QA PASS — ELIGIBLE FOR RC.10 PROMOTION. RC.10 is the core collaboration baseline; WPML/WCML and WP Rocket certification remain separate.`

RC.10 is now the **canonical core implementation/collaboration baseline** for WS1 / Ben, WS2 / Emmanuel, and WS3 / `@wbdevworld`.

This release is the protected integration and identity promotion of the already owner-qualified runtime. It is **not** Stage 15 and does not introduce schema 6.

---

## 2. Protected release identity

| Item | Value |
|------|-------|
| Release PR | `#16` — `[RC10] Promote owner-qualified core baseline to 1.0.0-rc.10` |
| Release branch head reviewed | `c19f23eacdd198817a8fa2f5e4adf1d0f366ce2e` |
| Protected merge commit / release source | `d1409258caf1a90675b689ab105471460de4c713` |
| Annotated tag | `v1.0.0-rc.10` |
| Tag object | `506c2067f963ae7c1753fa0f32e2dc0e30b7d1c9` |
| Tag peeled commit | `d1409258caf1a90675b689ab105471460de4c713` |
| Schema | `5` |
| Final ZIP | `cetech-woocommerce-delivery-engine-1.0.0-rc.10.zip` |
| ZIP bytes | `1532664` |
| ZIP SHA-256 | `6f451d7d898773ee257ab511f41c7db7199b1017c02de638a7b27c2c584d39ab` |

The final RC.10 ZIP was built from the exact tagged release source. The earlier pre-merge/preflight RC.10 ZIP is not the release artifact identity.

`v1.0.0-rc.10` is immutable after publication. Do not move the tag or overwrite the ZIP under the same identity.

---

## 3. Review and CI evidence

PR #16 received independent approvals from:
- `@Emmanuel-coder-prog` on unchanged release head `c19f23e...`;
- `@Ben-001-sys` on unchanged release head `c19f23e...`.

PR #16 merged through the normal protected flow using a merge commit. There was:
- no admin bypass;
- no self-approval;
- no squash of the qualification history;
- no force-push.

Post-merge CI run `35111530217` passed all required jobs on protected `master @ d1409258...`:
- Runtime PHP 8.1;
- PHP / PHPUnit 8.2;
- JavaScript / Vitest;
- Control Plane.

Issue #13 is CLOSED / COMPLETED.

---

## 4. Owner-qualified provenance

| Item | Value |
|------|-------|
| Qualification PR | `#12` — `[BATCH][DO NOT MERGE] Pre-RC.10 qualification baseline` |
| Qualification branch | `batch/pre-rc10-qualification` |
| Qualification head | `be586a454cc9a03395b981ef2b07cce445ef8f10` |
| Qualified runtime source | `c0000ab97aff8f2829ed9b173e0e6c7432b4eb9e` |
| Qualified identity | `1.0.0-dev.qual.1` |
| Qualified ZIP | `cetech-woocommerce-delivery-engine-1.0.0-dev.qual.1.zip` |
| qual.1 SHA-256 | `c2f86650854c2e61aef6493459d2ef2f7b3fa7241e822a8da9827fcd32872a16` |
| Protected master at promotion start | `b7c9f2bff8a50eab8fcda978c27b73647e49fc74` |

PR #14 recovery documentation was retained through the protected release merge.

### PR #12 archival caveat

GitHub displays PR #12 as `MERGED` because PR #16 imported the frozen qualification commits onto protected `master`. The draft PR itself was never independently merge-approved or merge-buttoned; `mergedBy` is null. Its branch remains preserved as historical qualification provenance.

PR #12 is **not** the active collaboration baseline. RC.10 is.

---

## 5. Qualified core runtime included in RC.10

RC.10 includes the owner-qualified core runtime:
- cart live-config reconciliation;
- Blocks line snapshots;
- per-item customer destinations;
- Classic multi-destination flow;
- Blocks per-item flow;
- customer UX cleanup;
- WCFM isolation/security;
- current control-plane safeguards;
- PHP 8.1 compatibility repair.

Schema remains **5**. Shipment/snapshot behavior from the qualified tree is preserved.

Runtime comparison during release preparation found **no unexplained runtime drift** from the owner-qualified packaged source in `src/`, `assets/`, `database/`, plugin bootstrap, or uninstall. The release-specific differences were identity/bookkeeping/status/tooling changes only.

---

## 6. Final package verification

Final immutable package verification: **PASS**.

Verified:
- plugin version `1.0.0-rc.10`;
- schema target `5`;
- one plugin root `cetech-woocommerce-delivery-engine/`;
- production `vendor/autoload.php` present;
- no PHPUnit vendor;
- no tests;
- no `node_modules`;
- no `.git`, `.github`, `.cursor`, or `.env`;
- no secrets;
- no licensed WPML, WP Rocket, or WoodMart binaries bundled;
- `scripts/verify-production-package-autoload.php`: PASS;
- packaged PHP lint: 431 files, 0 failures.

---

## 7. Isolated release smoke

Release smoke used the disposable owner-QA upgrade stack only. FLAIROC, training, and production were not touched.

### Clean install
PASS:
- final RC.10 ZIP installed and activated;
- reported `1.0.0-rc.10`;
- schema `5`;
- required Delivery Engine tables created, including `wp_delivery_engine_shipments`;
- no PHP fatal/parse error.

### Canonical RC.9 -> RC.10 upgrade
PASS:
- canonical RC.9 installed as `1.0.0-rc.9` / schema `5`;
- representative schema-5 offers survived;
- historical order/line snapshots survived and remained readable;
- RC.10 upgrade succeeded;
- schema remained `5`;
- deactivation/reactivation retained intended data.

The full owner-QA campaign was not repeated because the promotion introduced no new runtime behavior.

---

## 8. Certification boundary

```text
WPML/WCML certification: separate / not included in RC.10 core certification
WP Rocket certification: separate / not certified
WoodMart physically qualified on 8.4.1
WordPress physically qualified on 7.1
External PSP certification: not claimed
FLAIROC/training/production certification: not claimed
VitePOS: not in RC.10 certification scope
```

The recovered WPML overlay (`feat/post-rc9-wpml` @ `3b5b60d0d92b2edc00496d536774f8ac907cd6d2`) is **not** merged into RC.10.

---

## 9. Owner-QA P3 observations (non-blocking)

No P0/P1 defect remained at owner acceptance.

| ID | Observation |
|----|-------------|
| DEF-P3-001 | axe color-contrast on the PDP selector (2 nodes) |
| DEF-P3-002 | Thank You / My Account / email delivery-detail cards omit city; the shipping method line already shows Accra + Kumasi |
| DEF-P3-003 | WoodMart `/classic-cart/` empty while `/cart/` and mini-cart have the item in the isolated lab (page-assignment/theme observation) |
| DEF-P3-004 | Hidden `cetech_de_pdp_context` unlabeled |

These are not RC.10 release blockers and may be scheduled through normal post-RC.10 ownership.

Owner QA evidence lives outside this product tree at the recorded owner-QA evidence location.

---

## 10. Historical artifacts left untouched

- Tag `v1.0.0-rc.9` still peels to `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5`.
- RC.9 package identity remains immutable.
- `1.0.0-dev.qual.1` package identity remains immutable.
- Historical `1.0.0-dev.integrated.2` and earlier QA/release packages remain provenance only.

---

## 11. New team baseline

RC.10 is now the canonical team collaboration baseline.

Normal WS1 / WS2 / WS3 ownership and review rules resume. New work should branch from the **current protected post-RC.10 `master`** unless a task explicitly names another base. The immutable release anchor is `v1.0.0-rc.10` / peeled commit `d1409258...`.

Do not infer permission to start Stage 15. Stage 15 remains **NOT STARTED** until explicitly authorized.

Production/FLAIROC/training rollout remains a separate human-authorized activity.
