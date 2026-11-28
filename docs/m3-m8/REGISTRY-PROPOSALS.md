# Registry proposals for Frank (M3–M8 batch)

The authorization (§7) says new requirement IDs go in a proposal file for Frank, not into `docs/product/CAPABILITY-REGISTRY.yaml`. The registry is also frozen at 372 rows: `scripts/verify-product-control-plane.mjs` fails on any other count.

Nothing here changes the registry. Frank accepts, changes or rejects each proposal. After acceptance, the registry row, the decision-register entry and the `.md` rendering change together in one pull request.

## Proposed new IDs

The authorization names these as M7 scope, but the registry has no row for them. The audit package's registry delta lists `DE-EMBED-001`, `DE-EMBED-002` and `DE-UX-009` as "owner scope freeze required". The wording below restates the accepted direction from `13-Research-Plugin-Embeds.txt` and the UI/UX principles.

| Proposed ID | Requirement (one line) | Release intent (proposed) | Lane |
|---|---|---|---|
| `DE-EMBED-001` | The delivery selector and delivery information are available as shortcodes and as server-rendered dynamic blocks that can be nested in Group, Columns and Row. All of them use the same component service as the WooCommerce hooks; none calculates delivery in the browser | Required for M7 | L5 |
| `DE-EMBED-002` | Every component works outside the add-to-cart form through a form bridge (the HTML `form=` attribute), and several instances on one page share one delivery context and one network request. This includes WoodMart custom product layouts and Quick View fragments | Required for M7 | L5 |
| `DE-CTX-007` | A site-wide shopping destination ("Deliver to") can be placed in the header, a menu or any page. It is advisory only: it seeds new choices and never changes lines already in the cart | Required for M7 | L5 |
| `DE-UX-009` | On checkout, shipping fields that the engine already holds are collapsed into a summary with Change. Multi-destination carts are grouped per delivery. Billing stays. Classic and Blocks are reviewed separately | Required for M7 | L5 |
| `DE-LABEL-010` | A product promise or fulfilment label may link to its own archive | Post-1.0 (not built in this batch) | — |

## Findings for Frank (no new ID needed)

- **No `DE-NOTIFY-*` row is required before Stable 1.0.** All four are post-1.0, so M7 builds none of them. That follows "only the IDs the registry requires" in §7.
- **All six `DE-ORIGIN-*` rows are required before Stable 1.0.** They go with L6 (pickup and origin) in Wave B.
- **Some families required before Stable 1.0 are in no listed milestone:** `DE-SEC` (10), `DE-DIAG` (7), `DE-LOG` (6), `DE-SNAP` (8), `DE-TAX` (4), `DE-GLOBAL` (5), and `DE-UX-001` to `008`. The batch touches several of them anyway: security review, the snapshot freeze in M3 and M4, money and tax parity in M4. Their rows are updated only where there is evidence. Calling any release "Stable 1.0" still needs the management scope decision, because M5 and M6 are out.
- **COR-023** is "Qualify authoritative Woo monetary and tax boundary" (`DE-RATE-005`, `DE-RATE-008`, `DE-TAX-001`, `DE-TAX-002`, `DE-CART-005`), from the audit's correction register (files 205/206). It is M4's money-parity work.
- **The M2 line planned to reclassify `DE-PERF-003` and `DE-PERF-006`, but that change was never written into the registry.** It stays as it is until there is evidence from this batch.
