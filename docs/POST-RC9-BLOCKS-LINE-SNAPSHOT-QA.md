# POST-RC.9 Blocks line snapshot — local QA record

**Identity:** `1.0.0-dev.blocks-snapshot.1`  
**Schema:** `5`  
**Source:** `54c9894f492906a24d30c939f831f4538d6b0255`  
**ZIP SHA-256:** `092f3144e2a4b4df474c91513b6a282af08ccc817a5fa007f1f94d5d5229c401`  
**RC.9 tag:** packaged commit `e6bc7fba16d9d7b96682f2945c518a33a9a16cd5` — **untouched**  
**FLAIROC / training / cartstate.1:** not modified  
**Deployed:** no

## Automated

PHPUnit 855 tests / 4891 assertions: **OK**.

## Local native Blocks (lab `http://localhost:8088`, WC 11.0.1)

Reset to RC.9, then install **only** this ZIP (not cartstate.1).

| Order | Path | Line `_cetech_de_delivery_snapshot` | Package quote snapshot | Shipment plan | Staff preview |
|-------|------|-------------------------------------|------------------------|---------------|---------------|
| 25 | Store API Delivery, GHS 75 / shipping 15 | present (`delivery`, `in_warehouse\|delivery\|1`, ETA, qty, GHS 15.0000) | present | `ok`, 1 plan | `ready` (COD, `date_paid` empty) |
| 26 | Store API Pickup, GHS 50 / shipping 0 | present (`store_pickup`, pickup location) | present | `ok`, 0 plans, 1 pickup skipped | `pickup_only` |

Transient `_cetech_de_cart_item_key` was not left on either order line.

Do not install this ZIP on FLAIROC.
