# D11 report — the "Business Number" alert at checkout (training, 2026-10-29)

Decision D11 (28 Oct 2026): "Business Number" at checkout is required for wholesale
(B2BKing) customers only. The alert on `/sisi-checkout/` looked like site-specific
code, not this plugin. The task was to find where it lives and report it, without
editing theme, snippet or server code outside this repository.

## Where it lives

The field and its alert are **B2BKing data and B2BKing code, not the Delivery Engine
and not theme or snippet code.** Found by read-only WP-CLI queries on training as
the site user (no change was made):

| Item | Finding |
|---|---|
| Delivery Engine repository | No "Business Number" field, label or validation anywhere in `src/`, `assets/` or `tests/`. |
| Theme (`woodmart-child`) and mu-plugins | No file mentions "Business Number". |
| Page `/sisi-checkout/` (post 49333, published, default template) | The page content does not mention the field; it only hosts the checkout. |
| B2BKing custom registration field, post 49425 (`b2bking_custom_field`) | Label **Business Number**, placeholder "Enter Business Number", type text, **required = 1**, registration role = multiple roles, roles `role_49423` (Wholesaler Application) and `role_49424` (Distributor Application), group `group_b2b`, no billing connection. Sibling fields: Business Type (49427, required), Business Telephone (49426), Business Certificate (49428), Business Registration Number (49425 is the "Business Number" one; the title in the admin list is "Business Registration Number"). |
| B2BKing option `b2bking_registration_at_checkout_setting` | **1** (registration at checkout is on). `b2bking_allow_registration_fields_checkout` and `b2bking_custom_registration_fields_checkout` are unset (defaults). |
| B2BKing code (plugin version 5.2.61) | `public/class-b2bking-public.php` line 32 hooks `b2bking_custom_registration_fields_checkout()` (defined at line 545) on `woocommerce_after_checkout_billing_form` when registration at checkout is on. That function renders B2BKing's registration role dropdown and the registration fields of the chosen role inside the checkout form; the field's own "required" setting makes the browser and B2BKing's checkout validation block the order until it is filled. |

So the alert appears whenever the checkout form contains B2BKing's registration
block and the selected registration role is one the Business Number field belongs
to (Wholesaler Application or Distributor Application). It is scoped to those two
wholesale roles by its own settings; it is not applied to every customer by the
plugin's code.

## What this means for D11

- The requirement "required for wholesale customers only" is already how the field
  is configured in B2BKing: required, limited to the two wholesale application roles
  and the B2B group.
- If retail customers still see the alert on `/sisi-checkout/`, the cause is on
  the B2BKing side, in one of these places, all of them settings rather than code:
  1. the registration role pre-selected or forced on that page (B2BKing's
     registration shortcode/role setting can fix the dropdown to a role), or
  2. registration at checkout being on for guests who are not wholesale buyers
     (`b2bking_registration_at_checkout_setting`), or
  3. the field's role scoping having been widened in B2BKing → Registration fields.
- None of this is Delivery Engine scope. No theme, snippet, plugin or server file
  was edited, and no option was changed. Any change is Frank's call in the B2BKing
  settings screens, after which the `/sisi-checkout/` page should be re-tested as
  a retail customer and as a wholesale applicant.

## How it was found (for the record)

Read-only WP-CLI as the site user, over the authorised SSH connection: `wp post
list` for the page and the `b2bking_custom_field` / `b2bking_custom_role` post
types, `wp post meta list` on the field posts, `wp option get` on the B2BKing
registration/checkout options, and `grep` on the theme, mu-plugins and the B2BKing
plugin files for the label and the option keys. No customer, order or secret data
was read or recorded.
