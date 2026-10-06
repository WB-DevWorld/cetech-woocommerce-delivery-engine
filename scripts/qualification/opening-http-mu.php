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
// Reject a different physical environment before reading WordPress options.
$opening_http_mu_guard_checks = [
    'FLAGS' => '1' === getenv('CETECH_DE_NATIVE_OPENING_QUALIFICATION'),
    'DB_HOST' => '127.0.0.1' === getenv('CETECH_DE_WP_DB_HOST') && defined('DB_HOST') && (bool) preg_match('/^127\\.0\\.0\\.1(?::[0-9]+)?$/D', (string) DB_HOST),
    'DB_NAME' => defined('DB_NAME') && (bool) preg_match('/^cetech_wp_opening_qualification_[a-z0-9]+$/D', (string) DB_NAME),
    'SITE_PATH' => defined('ABSPATH') && '' !== $fixture_site && false !== realpath($fixture_site) && realpath(ABSPATH) === realpath($fixture_site),
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
$opening_http_mu_guard_checks = [
    'MARKER' => function_exists('get_option') && '1' === (string) get_option('cetech_opening_qualification_disposable'),
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
$opening_http_mu_guard_checks = [
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
        $listener_identity = [
            'format' => 'cetech-opening-http-owned-listener-v1',
            'probe_sha256' => hash('sha256', $expected),
            'site_path_sha256' => hash('sha256', (string) realpath(ABSPATH)),
            'database_name_sha256' => hash('sha256', (string) DB_NAME),
            'source_head' => (string) getenv('CETECH_DE_QUALIFICATION_HEAD'),
            'candidate_head' => (string) getenv('CETECH_DE_QUALIFICATION_CANDIDATE_HEAD'),
            'source_tree' => (string) getenv('CETECH_DE_QUALIFICATION_TREE'),
        ];
        if ('1' === getenv('CETECH_DE_HTTP_CRASH_DIAGNOSTIC')) {
            $all_ini = ini_get_all(null, false);
            ksort($all_ini);
            $jit1235_ini = $all_ini;
            $jit1235_ini['opcache.jit'] = '1235';
            $safe_ini = [];
            foreach (['memory_limit', 'max_execution_time', 'opcache.enable', 'opcache.enable_cli', 'opcache.jit', 'opcache.jit_buffer_size', 'opcache.optimization_level', 'opcache.protect_memory'] as $name) {
                $safe_ini[$name] = ini_get($name);
            }
            $extensions = [];
            foreach (get_loaded_extensions() as $name) {
                $extensions[$name] = phpversion($name);
            }
            ksort($extensions);
            // Read the actual web-listener state. Never include cached-script
            // paths or the configuration array in the public receipt.
            $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
            $opcache_state = ['status_available' => is_array($opcache)];
            if (is_array($opcache)) {
                foreach (['opcache_enabled', 'cache_full', 'restart_pending', 'restart_in_progress'] as $key) {
                    $opcache_state[$key] = isset($opcache[$key]) ? (bool) $opcache[$key] : null;
                }
                foreach (['num_cached_scripts', 'hits', 'misses'] as $key) {
                    $opcache_state['statistics'][$key] = isset($opcache['opcache_statistics'][$key]) ? (int) $opcache['opcache_statistics'][$key] : null;
                }
                foreach (['enabled', 'on'] as $key) {
                    $opcache_state['jit'][$key] = isset($opcache['jit'][$key]) ? (bool) $opcache['jit'][$key] : null;
                }
                foreach (['kind', 'opt_level', 'opt_flags', 'buffer_size', 'buffer_free'] as $key) {
                    $opcache_state['jit'][$key] = isset($opcache['jit'][$key]) ? (int) $opcache['jit'][$key] : null;
                }
            }
            $listener_identity['diagnostic_runtime'] = [
                'sapi' => PHP_SAPI, 'php_version' => PHP_VERSION,
                'php_binary_sha256' => hash_file('sha256', PHP_BINARY),
                'extensions' => $extensions, 'safe_ini' => $safe_ini,
                'full_ini_sha256' => hash('sha256', json_encode($all_ini, JSON_THROW_ON_ERROR)),
                'full_ini_jit1235_sha256' => hash('sha256', json_encode($jit1235_ini, JSON_THROW_ON_ERROR)),
                'historical_ini_comparison_available' => false,
                'opcache_state_at_existing_probe' => $opcache_state,
            ];
        }
        echo json_encode($listener_identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}
