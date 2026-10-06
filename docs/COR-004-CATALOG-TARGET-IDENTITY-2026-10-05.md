# COR-004 catalog target identity

Date: 2026-10-05. Base candidate: `d5c8e2f3c467a7dc38637bb2cdc3e881fc1c24f8`. This is a focused identity repair. It does not close COR-004's remaining private, delegation, CLI, or worker-authority associations, and it does not choose COR-005 scan, high-water, or exhaustive-count policy.

## COR-001 dependency for this batch

COR-001 on the base candidate already rechecks stored operation capability, private-content access, and the catalog preview/apply capability `manage_product_delivery_rules` before a job is created or a later admin action runs. This batch uses that gate and does not add a creator-only, foreign-job, CLI, or background-worker grant. A denied catalog preview still creates no job. A stored MatchingFilters payload with an unsupported key fails the job before any item row is inserted.

## Behavior

- Unsupported nonempty filter keys are rejected. They are not dropped and they do not make MatchingFilters select the rest of the catalog. Empty MatchingFilters still selects nothing.
- Category, tag, and shipping-class filters compare the UI `term_id` inside the named taxonomy. A colliding `term_taxonomy_id` in another taxonomy does not match.
- A pickup filter matches the configured pickup location id. In Store fulfilment or any Store Pickup offer is not a substitute for that endpoint.
- Delivery-option membership uses exact JSON membership. Add and Replace can include the member. Remove cannot. A product with no local Replace/Remove inherits a global Add/Replace member. Member `10` does not satisfy a request for member `1`.
- Count and preview use that same SQL identity. Selected ids that are missing or the wrong product/variation type are not returned when the database is available.

## Red then green

PHP 8.5.0. Before the repair, `CatalogTargetIdentityTest` was 5 failures / 7 tests: unknown keys were kept, pickup matched in-store and the other endpoint, and Remove was treated as inclusion. After the repair the same file is 8 tests / 17 assertions, OK. `tests/Unit/Bulk` is 147 tests / 849 assertions, OK, with 2 existing deprecations. Physical MariaDB `cetech_cor004_catalog` on `127.0.0.1:33079` (`phpunit.cor004-sql.xml`): 4 tests / 23 assertions, OK. Those SQL runs checked returned ids and unchanged table checksums. They are excluded from the default PHPUnit configuration so CI does not require that disposable database.

## Review repair

A nonempty unsupported value is rejected before normalization. An array such as `unsupported_filter => [900]` beside a real category is not dropped. Remove mode blocks only the requested member; inherited membership of a different member remains. PHP 8.5 `CatalogTargetIdentityTest` at that filter repair: 9 tests / 20 assertions, OK. The later authorized-detail case brings the same file to 10 tests / 24 assertions, OK. A stored invalid filter is shown as the job failure; the stored definition is not replaced and Apply is not offered. Disposable MariaDB catalog proof: 4 tests / 24 assertions, OK. Product 308 inherits member 1 through a local Remove of member 10. Product 309 removes member 1 and is excluded.

## Limits

CET-Q-061 large-catalog qualification is not claimed. Effective-fulfilment count can still differ from the SQL candidate count; that remains COR-005. Variation offer inheritance is implemented in SQL and was not part of the product-only MariaDB fixture. No schema change.
