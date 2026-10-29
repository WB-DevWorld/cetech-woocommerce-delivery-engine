# Training-site operations

`.github/workflows/training-ops.yml` is the only sanctioned way to change the training site
(`training.cetechbpa.com`). It is started by hand (`workflow_dispatch`), runs in the protected
`training` environment, and every run waits for the environment reviewer's approval.

| Operation  | Inputs                      | Effect |
|------------|-----------------------------|--------|
| `inspect`  | none                        | Read-only report: WordPress, WooCommerce, PHP and database versions, plugin list, active theme, `cetech_de_db_version`, the `*_delivery_engine_*` tables with collations, HPOS and object-cache state, disk space, the `/srv/cetech-deploy` listing, and the SHA-256 of the plugin's main file. |
| `backup`   | none                        | `wp db export` gzipped plus a tar of the plugin folder under `/srv/cetech-deploy/backups/<UTC id>/`, with `manifest.txt` and `SHA256SUMS`. Prints the backup id, sizes and hashes only. |
| `install`  | `package_run_id`            | Downloads the `Build Release Package` artifact of that run, checks the ZIP against its `.sha256`, requires a successful `Delivery Engine CI` run for the packaged commit, uploads the ZIP to `/srv/cetech-deploy/packages/<run id>/`, takes a backup, then `wp plugin install <zip> --force`, confirms the plugin is active and prints version, schema and main-file hash. |
| `rollback` | `backup_id`, `restore_db`   | Takes a safety backup, restores the plugin folder from the named backup, restores the database only when `restore_db=true` (followed by `wp cache flush`), confirms the plugin is active, purges the caches (see below) and prints version and schema. |

After an `install` or a `rollback`, `remote.sh` purges the caches that could still serve the
previous build: `wp cache flush` for the Redis object cache, then WP Rocket's page cache,
minified CSS/JS copies and cache-busting files through its own functions
(`rocket_clean_domain`, `rocket_clean_minify`, `rocket_clean_cache_busting`, called with
`wp eval` because the installed WP Rocket registers no `wp rocket clean` command). The log
shows `object_cache_flushed=yes|no` and `wp_rocket=purged version=…|absent|purge-failed`; a
failed purge is a warning, not a failed operation. Background: the 1.0.0-m2.1 install on
2026-10-29 left WP Rocket serving the old minified `product-delivery-selector.js` on cached
pages until the cache was cleared.

Server-side logic lives in `remote.sh`, copied to `/srv/cetech-deploy/bin/` for the run and
removed afterwards. It executes as the site user (`sudo -n -u $TRAINING_SITE_USER`), so every
`wp` command runs as that user with `--path=$TRAINING_WP_PATH`.

Environment configuration (GitHub → Settings → Environments → `training`):

- secrets `TRAINING_SSH_HOST`, `TRAINING_SSH_USER`, `TRAINING_SSH_KEY`, `TRAINING_SSH_KNOWN_HOSTS`;
- variables `TRAINING_SITE_USER`, `TRAINING_WP_PATH`.

The SSH key is written to the runner with mode 600, host keys are checked strictly against the
`known_hosts` secret, and the key material is shredded at the end of every run, including failed
ones. Backups stay on the server; backup retention is a human decision.

Because the repository is public, Actions logs are readable by anyone and artifacts by any
signed-in GitHub user. The workflow and `remote.sh` therefore print versions, names, sizes and
hashes only. They never print option values, `wp-config.php` values, database dumps, customer or
order data, or keys. Anything sensitive is read over direct SSH from an operator's machine.
