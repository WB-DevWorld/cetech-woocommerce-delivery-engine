# Primary Development Environment

**Current development / qualification policy (2026-09-22):**  
Use disposable CI/local isolated environments by default. Shared `https://training.cetechbpa.com` is the current authorized physical-QA surface only when a task explicitly permits writes. FLAIROC is **not** the current post-RC.12 development target and requires separate human authorization.

**Primary local/QA runtime:** PHP **8.5.x** (CETECH production target). See `docs/PHP-RUNTIME-POLICY.md` and `docker/php85-qa/`.

Do not qualify a CETECH release only on PHP 8.3/8.4. Isolated qualification must be able to reproduce PHP 8.5, current supported WordPress, current supported WooCommerce, and MariaDB 11.4 (current isolated-qualification database until production names a different version).

**Purpose:**  
Primary real-world development/integration environment for CETECH WooCommerce Delivery Engine.

**Portability:**  
The Delivery Engine must remain a reusable WooCommerce plugin. It must **not** depend on the hostname, theme, currency, country, products, suppliers, origins, or configuration of this environment. Do not hardcode `flairoc.com` (or any store hostname) into core business logic, resolvers, rates, or shipping behaviour. Site-specific credentials and test fixtures belong in local environment configuration and documentation only.

**Historical environment:**  
`https://training.cetechbpa.com`  
Former staging target. Stage 0 on 2026-08-10 was blocked by Cloudflare HTTP 525 (origin SSL failure). That condition must not by itself block post-RC development after the project owner designated FLAIROC International as the canonical development target.

**Credentials:**  
Do not store passwords, application passwords, API keys, tokens, cookies, or other secrets in this document. Use a local gitignored `.env.local` (or equivalent) for agent/developer access.

Environment credentials are local-only. Historical FLAIROC key names may still exist in private tooling, but no FLAIROC access is implied by repository documentation. Training/other target credentials must likewise remain outside Git and be used only under explicit task authorization.

**Environment facts (Stage 0B final, 2026-08-10):**  
- WordPress **7.0.3**; WooCommerce **11.0.0** (DB **11.0.0**); PHP **8.5.5**; table prefix `flagh_`  
- HPOS enabled; classic checkout; Woodmart Child / Woodmart 8.5.7  
- WP Rocket + Redis Object Cache active; WPML/WCML/WCFM/WoodMart present  
- Delivery Engine `1.0.0-rc.1` active; schema `cetech_de_db_version=2`  
- Stage 0B **VERIFIED**: customer/runtime flags OFF (dormant storefront), COD OFF, Code Snippets not executing  
- Application Password authentication works for authenticated REST (except Nginx-blocked `users/me` path)  
- Delivery Engine has no V1 public REST config API — prefer wp-admin for flag/config changes; do not reactivate Code Snippets for Stage 0B leftovers 

**REST automation notes (non-secret):**  
- WooCommerce REST with consumer keys works for commerce inventory and payment gateway toggles.  
- WordPress Application Password Basic auth works for plugins/settings/posts and (when healthy) authenticated admin-capable routes.  
- `/wp-json/wp/v2/users/me` may still return Nginx 403 under the user-enumeration rule; use other authenticated endpoints for health checks.  
- Cloudflare managed challenge still blocks automated `wp-login.php` / wp-admin HTML for this agent.  
- Delivery Engine has no V1 public REST config API — flag/config writes need wp-admin UI or a disposable capability-gated bridge (never Code Snippets after the Stage 0B outage).

**Code Snippets (Stage 0B incident):**  
Disabled at filesystem level as `code-snippets.disabled` after a temporary one-shot snippet caused HTTP 500. **Do not reactivate** until its residual Stage 0B snippet records are removed. Residual disabled files/DB rows are not a Stage 0B verification blocker while not executing.

**Deferred infrastructure:**  
Redis key namespace / prefix / database isolation hardening was observed as undefined (`WP_REDIS_PREFIX`, `WP_CACHE_KEY_SALT`, `WP_REDIS_DATABASE`) and is **deferred by project owner** — not a Stage 0B verification blocker unless cross-installation contamination is proven.

Related:  
See `docs/POST-RC-BASELINE-VERIFICATION.md` for Stage 0 / Stage 0A / Stage 0B verification results against this environment.  
See `docs/PHP-RUNTIME-POLICY.md` for supported PHP 8.3–8.5.x (minimum 8.3; CETECH production 8.5.x). Isolated PHP 8.5 Compose: `docker/php85-qa/`.
