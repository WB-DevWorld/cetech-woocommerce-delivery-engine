# Primary Development Environment

**Current development target:**  
`https://flairoc.com/intl/`

**Purpose:**  
Primary real-world development/integration environment for CETECH WooCommerce Delivery Engine.

**Portability:**  
The Delivery Engine must remain a reusable WooCommerce plugin. It must **not** depend on the hostname, theme, currency, country, products, suppliers, origins, or configuration of this environment. Do not hardcode `flairoc.com` (or any store hostname) into core business logic, resolvers, rates, or shipping behaviour. Site-specific credentials and test fixtures belong in local environment configuration and documentation only.

**Historical environment:**  
`https://training.cetechbpa.com`  
Former staging target. Stage 0 on 2026-08-10 was blocked by Cloudflare HTTP 525 (origin SSL failure). That condition must not by itself block post-RC development after the project owner designated FLAIROC International as the canonical development target.

**Credentials:**  
Do not store passwords, application passwords, API keys, tokens, cookies, or other secrets in this document. Use a local gitignored `.env.local` (or equivalent) for agent/developer access.

**Related:**  
See `docs/POST-RC-BASELINE-VERIFICATION.md` for Stage 0 / Stage 0A verification results against this environment.
