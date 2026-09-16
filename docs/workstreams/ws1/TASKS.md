# WS1 Tasks — Ben

Qualification queue against PR #12 / `1.0.0-dev.qual.1` (not canonical `master`, not RC.10). Source recovery is complete. Do not implement on published `master` unless separately authorized.

## WS1-Q1 — Current-candidate WoodMart/customer-surface qualification
Scope: PDP, variable/swatches, AJAX Add to Cart, mini-cart, Classic cart/checkout, Blocks customer surfaces, responsive/accessibility. Quick View/Buy Now only if enabled in target WoodMart environment.
Output: exact tested SHA if fixes are needed; otherwise evidence-only handoff.
Do not change core resolver/schema/security/release identity.

## WS1-Q2 — Customer-surface regression after integration
Run focused browser regression on the final combined candidate and hand exact evidence to WS3.
