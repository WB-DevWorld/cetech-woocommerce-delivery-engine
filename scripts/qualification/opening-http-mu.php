<?php
/**
 * CI fixture MU plugin, copied only into the marked third site's mu-plugins.
 * It suppresses queue background requests and attests the owned listener.
 * It does not change a WordPress authentication, permission, nonce, terminal
 * callback, production plugin hook, or normal wp-admin response.
 */
declare(strict_types=1);

if ('1' !== getenv('CETECH_DE_HTTP_OPENING_QUALIFICATION')) {
    return;
}
$fixture_site = (string) getenv('CETECH_DE_HTTP_FIXTURE_SITE');
$opening_http_mu_guard_checks = [
    'FLAGS' => '1' === getenv('CETECH_DE_NATIVE_OPENING_QUALIFICATION'),
    'DB_HOST' => '127.0.0.1' === getenv('CETECH_DE_WP_DB_HOST') && defined('DB_HOST') && (bool) preg_match('/^127\\.0\\.0\\.1(?::[0-9]+)?$/D', (string) DB_HOST),
    'DB_NAME' => defined('DB_NAME') && (bool) preg_match('/^cetech_wp_opening_qualification_[a-z0-9]+$/D', (string) DB_NAME),
    'SITE_PATH' => defined('ABSPATH') && '' !== $fixture_site && false !== realpath($fixture_site) && realpath(ABSPATH) === realpath($fixture_site),
    'MARKER' => function_exists('get_option') && '1' === (string) get_option('cetech_opening_qualification_disposable'),
    'CRON' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
    'SITEURL' => function_exists('get_option') && 'http://127.0.0.1:8085' === (string) get_option('siteurl'),
    'HOME' => function_exists('get_option') && 'http://127.0.0.1:8085' === (string) get_option('home'),
];
$opening_http_mu_guard_failed = [];
foreach ($opening_http_mu_guard_checks as $opening_http_mu_guard_code => $opening_http_mu_guard_passed) {
    if (!$opening_http_mu_guard_passed) {
        $opening_http_mu_guard_failed[] = 'HTTP_MU_GUARD_' . $opening_http_mu_guard_code;
    }
}
if ([] !== $opening_http_mu_guard_failed) {
    throw new RuntimeException('HTTP fixture MU plugin refused an unmarked or different disposable site. ' . implode(' ', $opening_http_mu_guard_failed));
}
add_filter('action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX);

if (!defined('WP_CLI') || !WP_CLI) {
    if ('1' !== (string) get_option('cetech_opening_http_qualification_disposable')) {
        throw new RuntimeException('HTTP qualification fixture was not prepared.');
    }
    if (isset($_GET['cetech_opening_http_probe']) && '1' === (string) $_GET['cetech_opening_http_probe']) {
        $expected = (string) getenv('CETECH_DE_HTTP_PROBE_TOKEN');
        $received = (string) ($_SERVER['HTTP_X_CETECH_OPENING_PROBE'] ?? '');
        if (!preg_match('/^[a-f0-9]{48}$/D', $expected) || !hash_equals($expected, $received)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"status":"forbidden"}';
            exit;
        }
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'format' => 'cetech-opening-http-owned-listener-v1',
            'probe_sha256' => hash('sha256', $expected),
            'site_path_sha256' => hash('sha256', (string) realpath(ABSPATH)),
            'database_name_sha256' => hash('sha256', (string) DB_NAME),
            'source_head' => (string) getenv('CETECH_DE_QUALIFICATION_HEAD'),
            'candidate_head' => (string) getenv('CETECH_DE_QUALIFICATION_CANDIDATE_HEAD'),
            'source_tree' => (string) getenv('CETECH_DE_QUALIFICATION_TREE'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}
