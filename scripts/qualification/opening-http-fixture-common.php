<?php
/** Native WP-CLI helpers for the third, explicitly disposable HTTP CI site. */
declare(strict_types=1);

function opening_http_fixture_guard(): void {
    $site = (string) getenv('CETECH_DE_HTTP_FIXTURE_SITE');
    $work = (string) getenv('CETECH_DE_HTTP_WORK_PATH');
    $private = (string) getenv('CETECH_DE_HTTP_PRIVATE_DIR');
    $checks = [
        'FLAGS' => '1' === getenv('CETECH_DE_NATIVE_OPENING_QUALIFICATION') && '1' === getenv('CETECH_DE_HTTP_OPENING_QUALIFICATION'),
        'DB_HOST' => '127.0.0.1' === getenv('CETECH_DE_WP_DB_HOST') && defined('DB_HOST') && (bool) preg_match('/^127\\.0\\.0\\.1(?::[0-9]+)?$/D', (string) DB_HOST),
        'DB_NAME' => defined('DB_NAME') && (bool) preg_match('/^cetech_wp_opening_qualification_[a-z0-9]+$/D', (string) DB_NAME),
        'SITE_PATH' => defined('ABSPATH') && '' !== $site && false !== realpath($site) && realpath($site) === realpath(ABSPATH),
        'WORK_PATH' => '' !== $work && false !== realpath($work) && false !== realpath($site) && dirname((string) realpath($site)) === realpath($work),
        'PRIVATE_PATH' => '' !== $private && false !== realpath($private) && dirname((string) realpath($private)) === realpath($work) && defined('ABSPATH') && !str_starts_with((string) realpath($private) . '/', rtrim((string) realpath(ABSPATH), '/') . '/'),
        'MARKER' => function_exists('get_option') && '1' === (string) get_option('cetech_opening_qualification_disposable'),
        'CRON' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'SITEURL' => function_exists('get_option') && 'http://127.0.0.1:8085' === (string) get_option('siteurl'),
        'HOME' => function_exists('get_option') && 'http://127.0.0.1:8085' === (string) get_option('home'),
        'NATIVE_CONTEXT' => defined('WP_CLI') && WP_CLI && defined('WP_ADMIN') && WP_ADMIN,
        'PLUGIN_CLASSES' => class_exists('WC_Product') && class_exists('CetechDeliveryEngine\\Bootstrap\\Plugin'),
    ];
    $failed = [];
    foreach ($checks as $code => $passed) {
        if (!$passed) {
            $failed[] = 'HTTP_COMMON_GUARD_' . $code;
        }
    }
    if ([] !== $failed) {
        throw new RuntimeException('Refusing HTTP fixture access outside the marked, exact-path disposable loopback CI site. ' . implode(' ', $failed));
    }
    // Real queue persists jobs, but this fixture controls progress through HTTP.
    add_filter('action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX);
}

/** Reject web-root destinations and symlinks; credential files are private only. */
function opening_http_write_json(string $path, array $data, bool $private = false): void {
    $directory = realpath(dirname($path));
    $work = (string) realpath((string) getenv('CETECH_DE_HTTP_WORK_PATH'));
    $site = rtrim((string) realpath(ABSPATH), '/') . '/';
    $private_directory = (string) realpath((string) getenv('CETECH_DE_HTTP_PRIVATE_DIR'));
    if (
        false === $directory || !str_starts_with($directory . '/', rtrim($work, '/') . '/')
        || str_starts_with($directory . '/', $site)
        || ($private && $directory !== $private_directory)
        || is_link($path) || (file_exists($path) && !is_file($path))
    ) {
        throw new RuntimeException('HTTP fixture output must be an ordinary file outside the WordPress document root.');
    }
    $temporary = $directory . '/.opening-http-' . bin2hex(random_bytes(10)) . '.tmp';
    try {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (false === file_put_contents($temporary, $json) || !chmod($temporary, 0600) || !rename($temporary, $path)) {
            throw new RuntimeException('Could not persist the HTTP fixture JSON.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function opening_http_read_state(string $path): array {
    $private_directory = (string) realpath((string) getenv('CETECH_DE_HTTP_PRIVATE_DIR'));
    if (
        !is_file($path) || is_link($path)
        || realpath(dirname($path)) !== $private_directory
        || 0 !== (fileperms($path) & 0077)
    ) {
        throw new RuntimeException('HTTP fixture state must be a private ordinary file outside the document root.');
    }
    $state = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($state) || ($state['site_path'] ?? '') !== realpath(ABSPATH) || ($state['database_name'] ?? '') !== DB_NAME) {
        throw new RuntimeException('HTTP fixture state belongs to a different disposable site.');
    }
    return $state;
}

/** Installed production PHP identity must equal the preceding PASS native receipt. */
function opening_http_identity(): array {
    global $wpdb;
    $path = (string) getenv('CETECH_DE_HTTP_NATIVE_RECEIPT');
    if ('' === $path || !is_file($path) || is_link($path)) {
        throw new RuntimeException('HTTP qualification requires the native PASS receipt from this fresh fixture.');
    }
    $native = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $root = dirname((string) (new ReflectionClass('CetechDeliveryEngine\\Bootstrap\\Plugin'))->getFileName(), 3);
    $installed = [];
    foreach (['src', 'database'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $installed[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
    }
    foreach (['cetech-woocommerce-delivery-engine.php', 'uninstall.php'] as $file) {
        $installed[$file] = hash_file('sha256', $root . '/' . $file);
    }
    ksort($installed, SORT_STRING);
    $hash = opening_http_hash($installed);
    if (
        'PASS' !== ($native['status'] ?? null)
        || 494 !== count($installed)
        || $installed !== ($native['installed_php_sources'] ?? null)
        || $hash !== ($native['installed_php_sources_hash'] ?? null)
        || (string) getenv('CETECH_DE_QUALIFICATION_HEAD') !== ($native['source_head'] ?? null)
        || (string) getenv('CETECH_DE_QUALIFICATION_CANDIDATE_HEAD') !== ($native['candidate_head'] ?? null)
        || (string) getenv('CETECH_DE_QUALIFICATION_TREE') !== ($native['source_tree'] ?? null)
    ) {
        throw new RuntimeException('HTTP installed production sources diverged from the same-run native qualification receipt.');
    }
    return [
        'source_head' => $native['source_head'], 'candidate_head' => $native['candidate_head'], 'source_tree' => $native['source_tree'],
        'installed_php_sources' => $installed, 'installed_php_sources_hash' => $hash,
        'environment' => [
            'php' => PHP_VERSION, 'wordpress' => get_bloginfo('version'), 'woocommerce' => WC_VERSION,
            'database_version' => (string) $wpdb->get_var('SELECT VERSION()'),
            'hpos' => (string) get_option('woocommerce_custom_orders_table_enabled'),
            'schema' => (string) get_option('cetech_de_db_version'),
            'transport' => 'HTTP to isolated PHP listener, native WordPress login/cookies/nonces/redirects and wp-admin/admin-ajax callbacks',
            'background_requests' => 'WP Cron disabled; marked fixture MU plugin suppresses the Action Scheduler async runner across requests',
        ],
    ];
}

function opening_http_hash(array $data): string {
    return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** Native physical SQL snapshots; callers pass only fixed fixture-owned clauses. */
function opening_http_sql_rows(string $suffix, ?string $where = null, array $params = []): array {
    global $wpdb;
    if (!preg_match('/^[a-z_]+$/D', $suffix)) {
        throw new RuntimeException('Invalid fixture SQL suffix.');
    }
    $table = CetechDeliveryEngine\Infrastructure\Persistence\TableNames::for($suffix);
    $query = 'SELECT * FROM `' . $table . '`';
    if (null !== $where && '' !== $where) {
        $query .= ' WHERE ' . (empty($params) ? $where : $wpdb->prepare($where, ...$params));
    }
    $rows = $wpdb->get_results($query . ' ORDER BY id', ARRAY_A);
    if ('' !== $wpdb->last_error || !is_array($rows)) {
        throw new RuntimeException('HTTP fixture SQL snapshot failed: ' . $suffix . ': ' . $wpdb->last_error);
    }
    return $rows;
}

function opening_http_private_snapshot(): array {
    $result = [];
    foreach (['suppliers', 'origins'] as $suffix) {
        $rows = opening_http_sql_rows($suffix);
        $result[$suffix] = ['count' => count($rows), 'sha256' => opening_http_hash($rows)];
    }
    return $result;
}

function opening_http_grants(int $user_id, string $role, array $capabilities): array {
    global $wpdb;
    $original = get_current_user_id();
    wp_set_current_user(0);
    clean_user_cache($user_id);
    wp_set_current_user($user_id);
    $raw = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $wpdb->prefix . 'user_roles'));
    $roles = maybe_unserialize($raw);
    if ('' !== $wpdb->last_error || !is_array($roles)) {
        throw new RuntimeException('Could not read physical fixture role capabilities.');
    }
    $persisted = $roles[$role]['capabilities'] ?? [];
    $native = [];
    $physical = [];
    foreach ($capabilities as $capability) {
        $native[$capability] = current_user_can($capability);
        $physical[$capability] = !empty($persisted[$capability]);
    }
    wp_set_current_user(0);
    wp_set_current_user($original);
    return ['native' => $native, 'persisted' => $physical, 'match' => $native === $physical, 'role_exists' => isset($roles[$role]), 'user_exists' => false !== get_user_by('id', $user_id)];
}

opening_http_fixture_guard();
