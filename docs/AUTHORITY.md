# Repository Authority and Chronology

Use this order when sources conflict:
1. explicit newer human instruction;
2. actual repository/code and immutable release refs;
3. `CURRENT-WORK.md` and `docs/STATUS_CURRENT.md`;
4. accepted ADRs/current project governance and contracts;
5. current workstream task/handoff records;
6. current release/QA evidence;
7. historical project documents/work-history exports;
8. conversation exports/private AI memory;
9. assumptions.

Existing project governance files such as `docs/PROJECT-GOVERNANCE.md`, `docs/PROJECT-RULES.md` and `docs/AI-HANDOFF.md` remain important. They must be reconciled against newer repository/release truth; stale historical implementation-status statements must not override newer evidence.

Never erase historical decisions merely because they are superseded. Mark them historical/superseded and link to the newer authority.

## Product control plane

The owner-accepted product-truth baseline is published under `docs/product/`. Use each artifact only for the truth it governs:

| Artifact | Governing truth |
|---|---|
| `docs/product/PRODUCT-CONSTITUTION.md` | Product identity, ownership boundaries, architectural invariants, and negative invariants |
| `docs/product/CAPABILITY-REGISTRY.yaml` | Machine-readable Requirement IDs, atomic requirements, release intent, and conformance classification |
| `docs/product/CAPABILITY-REGISTRY.md` | Human-readable rendering of the registry; the YAML file wins if registry rows differ |
| `docs/product/RELEASE-SCOPE.md` | Stable 1.0 inclusion, post-1.0 completion, optional certification, and deferral boundaries |
| `docs/product/COMPATIBILITY-CERTIFICATION.md` | Designed, implemented, tested, and physically certified compatibility claims |
| `docs/product/DECISION-CONFLICT-REGISTER.md` | Explicit owner decisions, chronology, supersessions, and negative-invariant findings |
| `docs/product/DESIGN-CODE-TRACEABILITY.md` | Evidence-backed mapping between product requirements and inspected implementation |
| `docs/product/REALIGNMENT-PLAN.md` | Approved dependency order, protected foundations, and implementation waves |
| `docs/product/AUDIT-MANIFEST.yaml` | Audit provenance, counts, limitations, acceptance checkpoint, and resume state |
| `docs/product/README.md` | Package index, source coverage, limitations, and publication boundary |
| `docs/PHP-RUNTIME-POLICY.md` | CETECH production PHP 8.5 versus commercial minimum PHP 8.1 versus CI matrix |

`CURRENT-WORK.md` and `docs/STATUS_CURRENT.md` continue to govern live work authorization and release/deployment state. Product-control-plane artifacts do not by themselves prove that a capability is implemented, accepted, released, certified, or deployed. A newer explicit owner decision may amend the baseline, but the change must be recorded in the decision register and reflected in the registry without renumbering frozen Requirement IDs.
