# M2 acceptance — how to run it and how to record it

This folder is the acceptance script for the M2 package (`1.0.0-m2.N`) of the
CETECH WooCommerce Delivery Engine. It turns the case list from the Cowork
intake document (`claude/m2-intake-and-authorization-2026-10-28.md` §1.4) and
the 3/5 Oct correction registers into something a tester can execute and a
signatory can read.

Acceptance has three parts. Each produces evidence that goes into the ledger
(`acceptance-cases.md`). Nothing here changes a site.

| Part | What it is | Who runs it | Where |
|---|---|---|---|
| A. Automated evidence | The PHPUnit and Vitest classes that prove each case's rule at the packaged commit | Claude Code or any developer | `bash scripts/qualification/m2/run-automated-evidence.sh` on the packaged commit |
| B. Site preflight | Read-only checks that the training (later business) site is in the state the cases assume | Whoever has WP-CLI on the site, or via the training-ops `inspect` step | `wp eval-file scripts/qualification/m2/m2-site-preflight.php` |
| C. Browser cases | The customer and staff journeys, one row per case | Claude in Frank's Cowork project, signed in on training as `claude-qa` | `acceptance-cases.md` |

## Preconditions (from the intake document)

1. Run first on a restore of the business database on the training host, after
   an in-place upgrade from the installed build (`1.0.0-dev.attention-count.1`,
   schema 6). Then run on the business site after a maintenance-window install.
2. Every M2 feature must be switched ON. Installing with a feature OFF does not
   count as delivering it. Part B lists the flags and fails when one is off.
3. Platform freeze: no WordPress, WooCommerce, theme or plugin updates during
   acceptance. Part B records the versions it sees.
4. Air and Sea stay on with no prices (D9). A product set to International has no
   delivery option at checkout until Air/Sea prices exist; this is a known limit,
   not a failure.
5. Training rate cards expected by the cases (D9): Greater Accra Selected Cities /
   Standard ₵30, Accra / Standard ₵50, Accra / Store Pickup ₵0.

## Evidence rules

- The repository is public. Evidence committed here or attached to a GitHub
  issue never contains customer names, addresses, phone numbers, emails, order
  totals of real customers, database dumps, `wp-config` values or keys. Use the
  `claude-qa` test customer and test orders only.
- A browser case passes only when the expected outcome is observed on the
  target stack (WoodMart theme, WPML, B2BKing active). Record the package
  version, the ZIP SHA-256 and the commit on the ledger header.
- Record each case as PASS or FAIL. "Not run" is not a result; say why and who
  owns the blocker.
- Automated evidence (Part A) is necessary but not sufficient: it proves the
  rule in the repository, not the behaviour on the site. The receipt it writes
  names the commit so the two can be matched.

## Where the limits are

- Concurrency and fault injection (COR-031/032/033) are proven by the unit
  suites and the real-database CI group, not in the browser. S3, S4 and S6
  show only the customer- and staff-visible result.
- Received emails (COR-034, C1) are checked in the `claude-qa` mailbox; the
  renderers are proven for HTML and plain text in Part A.
- COR-029 (where the order snapshot freezes) stays open with M4, so COR-031 to
  COR-034 are accepted as their invariants, not closed.

## Sign-off

Frank and a named management representative sign the ledger once every row
has a result. A FAIL does not block signing by itself; the signatories decide
what is a release blocker and what is a recorded known limit.
