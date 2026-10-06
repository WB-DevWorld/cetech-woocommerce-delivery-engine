<?php
/** Pin only the marked third CI site's URLs to its isolated listener origin. */
declare(strict_types=1);

global $wpdb;
$opening_http_site = (string) getenv('CETECH_DE_HTTP_FIXTURE_SITE');
$opening_http_work = (string) getenv('CETECH_DE_HTTP_WORK_PATH');
$opening_http_private = (string) getenv('CETECH_DE_HTTP_PRIVATE_DIR');
$opening_http_checks = [
    'FLAGS' => '1' === getenv('CETECH_DE_NATIVE_OPENING_QUALIFICATION') && '1' === getenv('CETECH_DE_HTTP_OPENING_QUALIFICATION'),
    'DB_HOST' => '127.0.0.1' === getenv('CETECH_DE_WP_DB_HOST') && defined('DB_HOST') && (bool) preg_match('/^127\\.0\\.0\\.1(?::[0-9]+)?$/D', (string) DB_HOST),
    'DB_NAME' => defined('DB_NAME') && (bool) preg_match('/^cetech_wp_opening_qualification_[a-z0-9]+$/D', (string) DB_NAME),
    'SITE_PATH' => defined('ABSPATH') && '' !== $opening_http_site && false !== realpath($opening_http_site) && realpath($opening_http_site) === realpath(ABSPATH),
    'WORK_PATH' => '' !== $opening_http_work && false !== realpath($opening_http_work) && false !== realpath($opening_http_site) && dirname((string) realpath($opening_http_site)) === realpath($opening_http_work),
    'PRIVATE_PATH' => '' !== $opening_http_private && false !== realpath($opening_http_private) && dirname((string) realpath($opening_http_private)) === realpath($opening_http_work) && defined('ABSPATH') && !str_starts_with((string) realpath($opening_http_private) . '/', rtrim((string) realpath(ABSPATH), '/') . '/'),
    'NATIVE_CONTEXT' => defined('WP_CLI') && WP_CLI && defined('WP_ADMIN') && WP_ADMIN,
];
$opening_http_failed = [];
foreach ($opening_http_checks as $opening_http_code => $opening_http_passed) {
    if (!$opening_http_passed) {
        $opening_http_failed[] = 'HTTP_ORIGIN_PREFLIGHT_' . $opening_http_code;
    }
}
if ([] !== $opening_http_failed) {
    throw new RuntimeException(implode(' ', $opening_http_failed));
}
if ('1' !== (string) get_option('cetech_opening_qualification_disposable')) {
    throw new RuntimeException('HTTP_ORIGIN_PREFLIGHT_MARKER');
}
if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON || !($wpdb instanceof wpdb) || !preg_match('/^[A-Za-z0-9_]+$/D', (string) $wpdb->options)) {
    throw new RuntimeException('HTTP_ORIGIN_PREFLIGHT_NATIVE_STORAGE_CONTEXT');
}
add_filter('action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX);

// The same-run native PASS provenance is a prerequisite to every URL write.
$opening_http_native_path = (string) getenv('CETECH_DE_HTTP_NATIVE_RECEIPT');
if ($opening_http_native_path !== realpath($opening_http_work) . '/opening-qualification-results.json' || !is_file($opening_http_native_path) || is_link($opening_http_native_path)) {
    throw new RuntimeException('HTTP_ORIGIN_PREFLIGHT_NATIVE_RECEIPT');
}
$opening_http_native = json_decode((string) file_get_contents($opening_http_native_path), true, 512, JSON_THROW_ON_ERROR);
$opening_http_native_sources = $opening_http_native['installed_php_sources'] ?? null;
if (!is_array($opening_http_native_sources) || 494 !== count($opening_http_native_sources)) {
    throw new RuntimeException('HTTP_ORIGIN_PREFLIGHT_NATIVE_IDENTITY');
}
ksort($opening_http_native_sources, SORT_STRING);
if (
    'PASS' !== ($opening_http_native['status'] ?? null)
    || (string) getenv('CETECH_DE_QUALIFICATION_HEAD') !== ($opening_http_native['source_head'] ?? null)
    || (string) getenv('CETECH_DE_QUALIFICATION_CANDIDATE_HEAD') !== ($opening_http_native['candidate_head'] ?? null)
    || (string) getenv('CETECH_DE_QUALIFICATION_TREE') !== ($opening_http_native['source_tree'] ?? null)
    || !preg_match('/^[a-f0-9]{40}$/D', (string) ($opening_http_native['source_head'] ?? ''))
    || !preg_match('/^[a-f0-9]{40}$/D', (string) ($opening_http_native['candidate_head'] ?? ''))
    || !preg_match('/^[a-f0-9]{40}$/D', (string) ($opening_http_native['source_tree'] ?? ''))
    || hash('sha256', json_encode($opening_http_native_sources, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) !== ($opening_http_native['installed_php_sources_hash'] ?? null)
) {
    throw new RuntimeException('HTTP_ORIGIN_PREFLIGHT_NATIVE_IDENTITY');
}

$opening_http_output = (string) ($args[0] ?? '');
if ('' === $opening_http_output || realpath(dirname($opening_http_output)) !== realpath($opening_http_private) || is_link($opening_http_output) || (file_exists($opening_http_output) && !is_file($opening_http_output))) {
    throw new RuntimeException('HTTP_ORIGIN_PREFLIGHT_OUTPUT_PATH');
}
$opening_http_expected = 'http://127.0.0.1:8085';
$opening_http_report = ['format' => 'cetech-opening-http-origin-preflight-v1', 'status' => 'RUNNING', 'expected_origin' => $opening_http_expected, 'same_run_native_pass_before_mutations' => true];
$opening_http_write = static function () use (&$opening_http_report, $opening_http_output): void {
    if (is_link($opening_http_output) || false === file_put_contents($opening_http_output, json_encode($opening_http_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") || !chmod($opening_http_output, 0600)) {
        throw new RuntimeException('HTTP_ORIGIN_PREFLIGHT_RECEIPT_WRITE');
    }
};
$opening_http_shape = static function ($value) use ($opening_http_expected): array {
    $string = is_string($value) ? $value : '';
    $parts = is_string($value) ? parse_url($value) : false;
    return [
        'value_type' => gettype($value), 'sha256' => hash('sha256', $string), 'exact_expected' => $value === $opening_http_expected,
        'scheme' => is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true) ? $parts['scheme'] : 'other_or_missing',
        'host' => is_array($parts) && '127.0.0.1' === ($parts['host'] ?? '') ? '127.0.0.1' : 'other_or_missing',
        'port' => is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : null,
        'root_path' => is_array($parts) && in_array($parts['path'] ?? '', ['', '/'], true),
        'query_present' => is_array($parts) && isset($parts['query']), 'fragment_present' => is_array($parts) && isset($parts['fragment']),
        'userinfo_present' => is_array($parts) && (isset($parts['user']) || isset($parts['pass'])),
    ];
};
$opening_http_physical = static function () use ($wpdb): array {
    $rows = $wpdb->get_results("SELECT option_name,option_value FROM `{$wpdb->options}` WHERE option_name IN ('home','siteurl') ORDER BY option_name", ARRAY_A);
    if ('' !== $wpdb->last_error || !is_array($rows) || 2 !== count($rows)) {
        throw new RuntimeException('HTTP_ORIGIN_PREFLIGHT_URL_SQL');
    }
    $values = array_column($rows, 'option_value', 'option_name');
    if (!array_key_exists('home', $values) || !array_key_exists('siteurl', $values)) {
        throw new RuntimeException('HTTP_ORIGIN_PREFLIGHT_URL_SQL');
    }
    return $values;
};
$opening_http_capture = static function () use ($opening_http_physical, $opening_http_shape): array {
    $physical = $opening_http_physical();
    return ['physical' => array_map($opening_http_shape, $physical), 'native' => ['home' => $opening_http_shape(get_option('home')), 'siteurl' => $opening_http_shape(get_option('siteurl'))]];
};
$opening_http_write();
try {
    $opening_http_report['before'] = $opening_http_capture();
    $opening_http_write();
    $opening_http_report['option_update_returns'] = [
        'siteurl' => update_option('siteurl', $opening_http_expected),
        'home' => update_option('home', $opening_http_expected),
    ];
    $opening_http_report['after'] = $opening_http_capture();
    $opening_http_values = $opening_http_physical();
    if ($opening_http_expected !== $opening_http_values['home'] || $opening_http_expected !== $opening_http_values['siteurl'] || $opening_http_expected !== get_option('home') || $opening_http_expected !== get_option('siteurl')) {
        throw new RuntimeException('HTTP_ORIGIN_PREFLIGHT_EXACT_READBACK');
    }
    // This invokes the unchanged literal URL guards and native source-map proof
    // only after the fixture URL configuration has passed physical read-back.
    require __DIR__ . '/opening-http-fixture-common.php';
    $opening_http_identity = opening_http_identity();
    $opening_http_report['identity'] = array_intersect_key($opening_http_identity, array_flip(['source_head', 'candidate_head', 'source_tree', 'installed_php_sources_hash']));
    $opening_http_report['installed_php_source_files'] = count($opening_http_identity['installed_php_sources']);
    $opening_http_report['status'] = 'PASS';
    $opening_http_write();
    echo 'opening_http_origin_preflight=PASS' . PHP_EOL;
} catch (Throwable $opening_http_error) {
    $opening_http_report['status'] = 'FAIL';
    $opening_http_report['error_class'] = in_array(get_class($opening_http_error), ['RuntimeException', 'Error', 'TypeError', 'ParseError', 'ValueError', 'JsonException', 'Exception'], true) ? get_class($opening_http_error) : 'other';
    $opening_http_report['error_code'] = 'HTTP_ORIGIN_PREFLIGHT_FAILED';
    $opening_http_write();
    throw $opening_http_error;
}
