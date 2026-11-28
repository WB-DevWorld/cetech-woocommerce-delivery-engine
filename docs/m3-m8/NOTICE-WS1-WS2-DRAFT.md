# Draft notice to Ben (WS1) and Emmanuel (WS2), for Frank to send

Frank sends this; Claude does not. Copy the text below the line.

---

Hi Ben, hi Emmanuel,

From 28 November 2026 we are finishing four milestones on one integration branch, `batch/m3-m8-2026-11`, cut from `master` `08422f06`:

- **M3:** promised delivery dates instead of durations, and an emergency pause switch.
- **M4:** the delivery price held and confirmed at checkout, with expiry.
- **M7:** shortcodes and blocks, mobile layouts, pickup hours and capacity.
- **M8:** API, command-line tools, webhooks, certification and rename preparation.

M5 and M6 (promotions, economics, currency, returns, refunds, labels) are out of scope. That code stays in the tree, switched off and untouched.

I have authorised work across your file areas for this batch only. The work is done by Claude Code agents, each on a short `fix/**` branch with its own file lease. The leases are listed in `CURRENT-WORK.md` under "M3–M8 batch".

- **WS1 (Ben):** product page, cart and checkout presentation (Classic and Blocks), the mini-cart, emails, My Account, the WoodMart placement, mobile layouts and accessibility.
- **WS2 (Emmanuel):** WooCommerce runtime hooks, shipping rates, Store API and Blocks server code, the order snapshot, shipments, and the order-placement freeze.

What this means for you:

1. Please do not start new work in those file areas until the batch reaches `master`. If you have work in progress there, tell me so we can record it and avoid conflicts.
2. Every feature ships switched off. Nothing changes on the training or business site.
3. The batch reaches `master` at three gates (A, B, C), each as one pull request that I merge. You are welcome to review them. Review fixes come back to you as normal once the batch is merged.
4. Staff testing happens on staging at each gate. I will send the test kit and the dates.

Thanks,
Frank
