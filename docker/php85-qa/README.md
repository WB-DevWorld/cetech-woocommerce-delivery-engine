# Isolated PHP 8.5 qualification stack

This Compose project reproduces the **CETECH production PHP target** locally:

- PHP **8.5** (`wordpress:php8.5-apache` / `wordpress:cli-php8.5`)
- current supported WordPress from the official image
- MariaDB **11.4** (current CETECH isolated-qualification database until production names a different version)

It is **not** FLAIROC, training, production, or POS.

## Policy

See `docs/PHP-RUNTIME-POLICY.md`. Do not qualify a CETECH release only on PHP 8.1/8.2. Do not use PHP 8.6 pre-release images.

Mounting the repository as the plugin directory is a development convenience. For package-like qualification, copy a Composer `--no-dev` staged tree (or an extracted ZIP) into `wp-content/plugins/cetech-woocommerce-delivery-engine` instead of the raw git checkout.

## Start

```bash
docker compose -f docker/php85-qa/docker-compose.yml up -d
```

WordPress: `http://localhost:8085`

Install WordPress, install current WooCommerce, enable HPOS, then activate the Delivery Engine. Confirm `php -v` inside the WordPress container is PHP 8.5.x (latest patch on the image), then check `wp-content/debug.log` for Delivery Engine fatals or deprecations.

```bash
docker compose -f docker/php85-qa/docker-compose.yml --profile cli run --rm wpcli --info
```

Tear down with volumes after the lab:

```bash
docker compose -f docker/php85-qa/docker-compose.yml down -v
```
