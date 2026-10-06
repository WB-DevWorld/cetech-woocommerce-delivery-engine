<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationPackage;
use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Presentation\Admin\BulkJobAccess;
use CetechDeliveryEngine\Presentation\Admin\BulkJobProgressEndpoint;
use CetechDeliveryEngine\Presentation\Admin\BulkToolsPage;

/**
 * Native PHP callback and MariaDB qualification for public-only mixed-package
 * import. Load only through the guarded opening runner, AFTER authority and
 * BEFORE configuration/migrations. No WP function/capability or queue stubs.
 * Core wp_doing_ajax context filter selects native AJAX terminal handling;
 * this does not prove HTTP, browser authentication/session transport or workers.
 * Fixture jobs/offers remain in the disposable database; private stores must
 * remain byte-for-byte unchanged. No orders, payments or live-site operations.
 */
return static function (callable $check): void {
    global $wpdb;

    if (
        '1' !== getenv('CETECH_DE_NATIVE_OPENING_QUALIFICATION')
        || '127.0.0.1' !== getenv('CETECH_DE_WP_DB_HOST')
        || !defined('ABSPATH')
        || !defined('DB_HOST') || !preg_match('/^127\\.0\\.0\\.1(?::[0-9]+)?$/D', DB_HOST)
        || !defined('DB_NAME') || !preg_match('/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME)
        || '1' !== (string) get_option('cetech_opening_qualification_disposable')
    ) {
        throw new RuntimeException('Refusing public import qualification outside the marked disposable loopback fixture.');
    }
    $check('PUBLIC-IMPORT-NATIVE-PRECONDITION', is_admin() && class_exists(Plugin::class) && function_exists('wp_doing_ajax'));
    $container = Plugin::instance()->container();
    $engine = $container->get(BulkJobEngine::class);
    $jobs = $container->get(BulkJobRepositoryInterface::class);
    $page = $container->get(BulkToolsPage::class);
    $endpoint = $container->get(BulkJobProgressEndpoint::class);
    $access = new BulkJobAccess($engine);
    $original_user = get_current_user_id();
    $original_post = $_POST;
    $original_get = $_GET;
    $original_request = $_REQUEST;
    $suffix = substr(bin2hex(random_bytes(8)), 0, 12);
    $role_slug = 'cetech_public_import_' . $suffix;
    $role = add_role($role_slug, 'CETECH public import qualification', ['read' => true]);
    $check('PUBLIC-IMPORT-NATIVE-ROLE', $role instanceof WP_Role);
    $fixture_user = 0;
    $redirect_location = '';
    $observed_redirects = [];
    $redirect_exception = new class('native import redirect intercepted') extends RuntimeException {};
    $die_exception = new class('native import JSON termination intercepted') extends RuntimeException {};
    $redirect_hook = static function ($location) use (&$redirect_location, &$observed_redirects, $redirect_exception): never {
        $redirect_location = (string) $location;
        $observed_redirects[] = $redirect_location;
        throw $redirect_exception;
    };
    $die_handler = static function () use ($die_exception): never {
        throw $die_exception;
    };
    $die_hook = static fn () => $die_handler;
    add_filter('wp_redirect', $redirect_hook, PHP_INT_MAX);
    add_filter('wp_die_handler', $die_hook, PHP_INT_MAX);
    add_filter('wp_die_ajax_handler', $die_hook, PHP_INT_MAX);

    $rows = static function (string $suffix, ?int $job_id = null) use ($wpdb): array {
        $table = TableNames::for($suffix);
        $query = "SELECT * FROM `{$table}`";
        if (null !== $job_id) {
            $query .= $wpdb->prepare(' WHERE ' . ('bulk_jobs' === $suffix ? 'id' : 'job_id') . '=%d', $job_id);
        }
        $result = $wpdb->get_results($query . ' ORDER BY id', ARRAY_A);
        if ('' !== $wpdb->last_error || !is_array($result)) {
            throw new RuntimeException('Public import native SQL failed: ' . $suffix . ': ' . $wpdb->last_error);
        }
        return $result;
    };
    $digest = static fn (array $value): string => hash('sha256', wp_json_encode($value));
    $private_snapshot = static fn (): array => ['suppliers' => $rows('suppliers'), 'origins' => $rows('origins')];
    $offers_by_code = static function (string $code) use ($wpdb): array {
        $table = TableNames::for('delivery_offers');
        $result = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` WHERE internal_code=%s ORDER BY id", $code), ARRAY_A);
        if ('' !== $wpdb->last_error || !is_array($result)) {
            throw new RuntimeException('Public import offer SQL failed: ' . $wpdb->last_error);
        }
        return $result;
    };
    $set_user = static function (int $id): void {
        wp_set_current_user(0);
        clean_user_cache($id);
        wp_set_current_user($id);
    };
    $grant_revision = 0;
    $set_caps = static function (bool $import, bool $private) use ($role, $role_slug, &$fixture_user, $set_user, $wpdb, $check, &$grant_revision): void {
        $expected = ['manage_product_delivery_rules' => true, 'import_delivery_data' => $import, 'manage_private_sources' => $private];
        foreach ($expected as $cap => $allowed) {
            $allowed ? $role->add_cap($cap) : $role->remove_cap($cap);
        }
        $set_user($fixture_user);
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s", $wpdb->prefix . 'user_roles'));
        $persisted_roles = maybe_unserialize($raw);
        $persisted = $persisted_roles[$role_slug]['capabilities'] ?? [];
        $correct = '' === $wpdb->last_error && is_array($persisted);
        foreach ($expected as $cap => $allowed) {
            $correct = $correct && $allowed === !empty($persisted[$cap]) && $allowed === current_user_can($cap);
        }
        $check('PUBLIC-IMPORT-NATIVE-PERSISTED-GRANTS-' . ++$grant_revision, $correct, ['principal' => $fixture_user, 'capabilities' => $expected]);
    };
    $post = static function (string $action, array $data = []): void {
        $_GET = [];
        $_POST = ['cetech_de_action' => $action, 'cetech_de_nonce' => wp_create_nonce($action)] + $data;
        $_REQUEST = $_POST;
    };
    $dispatch_post = static function () use ($page, $redirect_exception, &$redirect_location, &$observed_redirects): string {
        $redirect_location = '';
        $observed_redirects = [];
        try {
            $page->handle_actions();
            return 'returned';
        } catch (Throwable $error) {
            if ($error !== $redirect_exception) {
                throw $error;
            }
            return 'redirect_intercepted';
        }
    };
    $progress = static function (int $id, bool $advance) use ($endpoint, $die_exception): array {
        $_GET = [];
        $_POST = ['job_id' => (string) $id, 'advance' => $advance ? '1' : '0', 'nonce' => wp_create_nonce(BulkJobProgressEndpoint::ACTION)];
        $_REQUEST = $_POST;
        $context_filter = static fn (): bool => true;
        add_filter('wp_doing_ajax', $context_filter, PHP_INT_MAX);
        $terminated = false;
        ob_start();
        try {
            $endpoint->handle();
        } catch (Throwable $error) {
            if ($error !== $die_exception) {
                throw $error;
            }
            $terminated = true;
        } finally {
            $json = (string) ob_get_contents();
            ob_end_clean();
            remove_filter('wp_doing_ajax', $context_filter, PHP_INT_MAX);
        }
        $decoded = json_decode($json, true);
        if (!$terminated || !is_array($decoded) || !array_key_exists('success', $decoded)) {
            throw new RuntimeException('Native progress callback did not return terminated JSON: ' . substr($json, 0, 300));
        }
        return $decoded;
    };
    $render = static function (int $id) use ($page): string {
        $_POST = [];
        $_GET = ['tab' => 'jobs', 'job' => (string) $id];
        $_REQUEST = $_GET;
        ob_start();
        try {
            $page->render();
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    };
    $allow_action = static function (BulkJob $job) use ($access): bool {
        try {
            $access->require_action_access($job);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    };

    try {
        $user = wp_insert_user(['user_login' => 'cetech_public_import_' . $suffix, 'user_pass' => wp_generate_password(32), 'role' => $role_slug]);
        $check('PUBLIC-IMPORT-NATIVE-PRINCIPAL', !is_wp_error($user) && $user > 0);
        $fixture_user = (int) $user;
        $set_caps(true, false);
        $private_before = $private_snapshot();
        $offer_code = 'qa-public-import-' . $suffix;
        $private_sentinel = 'qa-private-source-sentinel-' . $suffix;
        $package = ConfigurationPackage::create('native-qualification', '6', [
            'delivery_options' => [['internal_code' => $offer_code, 'internal_name' => 'Native public import fixture ' . $suffix, 'public_label' => 'Native public fixture', 'route' => 'local_delivery', 'service_level' => 'standard', 'status' => 'active']],
            'suppliers' => [['internal_code' => 'qa-supplier-' . $suffix, 'internal_name' => $private_sentinel]],
            'origins' => [['internal_code' => 'qa-origin-' . $suffix, 'internal_name' => $private_sentinel]],
        ], true);
        $check('PUBLIC-IMPORT-NATIVE-INPUT-MIXED', isset($package->sections['suppliers'], $package->sections['origins']) && true === $package->manifest['include_private_sources'] && [] === $offers_by_code($offer_code), ['boundary' => 'private sentinel confined to direct supplier/origin source rows; transitive privacy policy remains separate']);
        $before_jobs = $rows('bulk_jobs');
        $before_ids = array_column($before_jobs, 'id');
        $post(BulkToolsPage::ACTION_CONFIG_IMPORT, ['package_json' => wp_slash($package->to_json()), 'conflict_mode' => 'skip_conflicts']);
        $terminal = $dispatch_post();
        $created = array_values(array_filter($rows('bulk_jobs'), static fn (array $row): bool => !in_array($row['id'], $before_ids, true)));
        $redirect_query = [];
        parse_str((string) wp_parse_url($observed_redirects[0] ?? '', PHP_URL_QUERY), $redirect_query);
        $check('PUBLIC-IMPORT-NATIVE-INITIATING-POST', 'redirect_intercepted' === $terminal && 1 === count($created) && BulkToolsPage::SLUG === ($redirect_query['page'] ?? null) && 'jobs' === ($redirect_query['tab'] ?? null) && (string) ($created[0]['id'] ?? '') === ($redirect_query['job'] ?? null), ['control_flow' => $terminal, 'redirect_attempts' => $observed_redirects, 'first_redirect_query' => $redirect_query, 'created_job_count' => count($created), 'boundary' => 'first supported job redirect inspected; interception exception is caught by the initiating callback and induces its later error redirect, so final/browser redirect UX is unproven']);
        $job_id = (int) $created[0]['id'];
        $job = $engine->find($job_id);
        $check('PUBLIC-IMPORT-NATIVE-DURABLE-PREVIEW', $job instanceof BulkJob && BulkOperationType::ConfigImport === $job->operation_type && BulkJobStatus::Previewing === $job->status && $job->dry_run && $fixture_user === $job->actor_user_id, ['job_id' => $job_id, 'status' => $job?->status->value]);
        $stored = json_decode($created[0]['action_manifest_json'], true);
        $stored_package = $stored['package'] ?? [];
        $stored_manifest = $stored_package['manifest'] ?? [];
        $stored_sections = $stored_package['sections'] ?? [];
        $check('PUBLIC-IMPORT-NATIVE-MANIFEST-MINIMIZED', false === ($stored['include_private_sources'] ?? null) && false === ($stored_manifest['include_private_sources'] ?? null) && ['delivery_options'] === array_keys($stored_sections) && ['delivery_options'] === ($stored_manifest['exported_sections'] ?? null) && ['delivery_options' => 1] === ($stored_manifest['counts'] ?? null) && !str_contains(wp_json_encode($created), $private_sentinel), ['job_id' => $job_id, 'sections' => array_keys($stored_sections), 'exported_sections' => $stored_manifest['exported_sections'] ?? null, 'counts' => $stored_manifest['counts'] ?? null, 'job_include_private_sources' => $stored['include_private_sources'] ?? null, 'package_include_private_sources' => $stored_manifest['include_private_sources'] ?? null, 'private_sentinel_retained' => str_contains(wp_json_encode($created), $private_sentinel), 'job_row_sha256' => $digest($created)]);
        $check('PUBLIC-IMPORT-NATIVE-READ-ACTION-PERMITTED', $access->can_access($job) && $allow_action($job) && !current_user_can('manage_private_sources'));
        $html = $render($job_id);
        $history_link = '<a href="' . esc_url(add_query_arg(['page' => BulkToolsPage::SLUG, 'tab' => 'jobs', 'job' => (string) $job_id], admin_url('admin.php'))) . '">' . esc_html($job->job_code) . '</a>';
        $check('PUBLIC-IMPORT-NATIVE-PREVIEW-DETAIL-HISTORY', str_contains($html, $job->job_code) && str_contains($html, 'data-cetech-de-job-id="' . $job_id . '"') && str_contains($html, $history_link) && !str_contains($html, $private_sentinel), ['html_sha256' => hash('sha256', $html), 'history_job_link_present' => str_contains($html, $history_link), 'boundary' => 'production PHP render callback; detail plus history on the current page, not browser transport or pagination proof']);
        $response = $progress($job_id, false);
        $check('PUBLIC-IMPORT-NATIVE-PROGRESS-READ', true === $response['success'] && $job->job_code === ($response['data']['code'] ?? null) && 'previewing' === ($response['data']['status'] ?? null), ['response' => $response]);
        $ticks = 0;
        while ($ticks < 20 && BulkJobStatus::Ready !== $engine->find($job_id)?->status) {
            ++$ticks;
            $response = $progress($job_id, true);
            $check('PUBLIC-IMPORT-NATIVE-PREVIEW-ADVANCE-' . $ticks, true === $response['success'], ['response' => $response]);
        }
        $job = $engine->find($job_id);
        $items = $rows('bulk_job_items', $job_id);
        $check('PUBLIC-IMPORT-NATIVE-READY-PUBLIC-ONLY', $job instanceof BulkJob && BulkJobStatus::Ready === $job->status && $job->dry_run && 1 === $job->total_count && 1 === $job->processed_count && 1 === $job->changed_count && 0 === $job->failed_count && 1 === count($items) && 'delivery_options' === ($items[0]['target_type'] ?? null) && !str_contains(wp_json_encode($items), $private_sentinel), ['ticks' => $ticks, 'status' => $job?->status->value, 'items_sha256' => $digest($items), 'item_types' => array_column($items, 'target_type')]);
        $check('PUBLIC-IMPORT-NATIVE-PREVIEW-NO-PUBLIC-WRITE', [] === $offers_by_code($offer_code) && $private_before === $private_snapshot(), ['private_before_sha256' => $digest($private_before), 'private_after_sha256' => $digest($private_snapshot())]);
        $html = $render($job_id);
        $check('PUBLIC-IMPORT-NATIVE-READY-APPLY-CONTROL', str_contains($html, $job->job_code) && str_contains($html, 'data-cetech-de-apply-preview') && str_contains($html, 'value="' . BulkToolsPage::ACTION_APPLY . '"') && !str_contains($html, $private_sentinel), ['html_sha256' => hash('sha256', $html)]);

        $set_caps(false, false);
        $before_jobs = $rows('bulk_jobs');
        $before_items = $rows('bulk_job_items');
        $post(BulkToolsPage::ACTION_CONFIG_IMPORT, ['package_json' => wp_slash($package->to_json())]);
        $terminal = $dispatch_post();
        $check('PUBLIC-IMPORT-NATIVE-REVOKED-INITIATION-DENIED', 'redirect_intercepted' === $terminal && $before_jobs === $rows('bulk_jobs') && $before_items === $rows('bulk_job_items'), ['control_flow' => $terminal, 'jobs_sha256' => $digest($before_jobs)]);
        $post(BulkToolsPage::ACTION_APPLY, ['job_id' => $job_id]);
        $terminal = $dispatch_post();
        $check('PUBLIC-IMPORT-NATIVE-REVOKED-APPLY-DENIED', 'redirect_intercepted' === $terminal && $before_jobs === $rows('bulk_jobs') && $before_items === $rows('bulk_job_items') && [] === $offers_by_code($offer_code), ['control_flow' => $terminal]);
        $response = $progress($job_id, true);
        $check('PUBLIC-IMPORT-NATIVE-REVOKED-PROGRESS-DENIED', false === $response['success'] && 'forbidden' === ($response['data']['message'] ?? null) && $before_jobs === $rows('bulk_jobs') && $before_items === $rows('bulk_job_items'), ['response' => $response]);
        $html = $render($job_id);
        $check('PUBLIC-IMPORT-NATIVE-REVOKED-RENDER-DENIED', !str_contains($html, $job->job_code) && !str_contains($html, 'data-cetech-de-job-id="' . $job_id . '"'), ['html_sha256' => hash('sha256', $html)]);
        $set_caps(true, false);
        $post(BulkToolsPage::ACTION_APPLY, ['job_id' => $job_id]);
        $terminal = $dispatch_post();
        $job = $engine->find($job_id);
        $check('PUBLIC-IMPORT-NATIVE-APPLY-POST-QUEUED', 'redirect_intercepted' === $terminal && $job instanceof BulkJob && BulkJobStatus::Queued === $job->status && !$job->dry_run && [] === $offers_by_code($offer_code), ['control_flow' => $terminal, 'status' => $job?->status->value]);
        $ticks = 0;
        while ($ticks < 20 && !$engine->find($job_id)?->status->is_terminal()) {
            ++$ticks;
            $response = $progress($job_id, true);
            $check('PUBLIC-IMPORT-NATIVE-APPLY-ADVANCE-' . $ticks, true === $response['success'], ['response' => $response]);
        }
        $job = $engine->find($job_id);
        $public_rows = $offers_by_code($offer_code);
        $check('PUBLIC-IMPORT-NATIVE-COMPLETED-PUBLIC-WRITE', $job instanceof BulkJob && BulkJobStatus::Completed === $job->status && !$job->dry_run && 1 === $job->changed_count && 0 === $job->failed_count && 1 === count($public_rows) && 'Native public fixture' === ($public_rows[0]['public_label'] ?? null), ['ticks' => $ticks, 'status' => $job?->status->value, 'public_rows_sha256' => $digest($public_rows), 'public_row_ids' => array_column($public_rows, 'id')]);
        $check('PUBLIC-IMPORT-NATIVE-COMPLETED-ITEMS-MINIMIZED', !str_contains(wp_json_encode($rows('bulk_job_items', $job_id)), $private_sentinel) && !str_contains(wp_json_encode($rows('bulk_jobs', $job_id)), $private_sentinel));
        $check('PUBLIC-IMPORT-NATIVE-PRIVATE-STORES-UNCHANGED', $private_before === $private_snapshot(), ['before_sha256' => $digest($private_before), 'after_sha256' => $digest($private_snapshot())]);

        // A genuinely private initiating request remains private and is not
        // advanced here: the permitted control proves retention/access only.
        $set_caps(true, true);
        $before_ids = array_column($rows('bulk_jobs'), 'id');
        $post(BulkToolsPage::ACTION_CONFIG_IMPORT, ['package_json' => wp_slash($package->to_json())]);
        $terminal = $dispatch_post();
        $created = array_values(array_filter($rows('bulk_jobs'), static fn (array $row): bool => !in_array($row['id'], $before_ids, true)));
        $check('PUBLIC-IMPORT-NATIVE-PRIVATE-CONTROL-INITIATED', 'redirect_intercepted' === $terminal && 1 === count($created));
        $private_job = $engine->find((int) $created[0]['id']);
        $check('PUBLIC-IMPORT-NATIVE-PRIVATE-CONTROL-RETAINED-PERMITTED', $private_job instanceof BulkJob && true === ($private_job->action_manifest['include_private_sources'] ?? null) && isset($private_job->action_manifest['package']['sections']['suppliers'], $private_job->action_manifest['package']['sections']['origins']) && str_contains(wp_json_encode($created), $private_sentinel) && $access->can_access($private_job) && $allow_action($private_job), ['job_id' => $private_job?->id, 'boundary' => 'permitted private retention/access control; no private worker advancement or private write']);
        $response = $progress((int) $private_job->id, false);
        $check('PUBLIC-IMPORT-NATIVE-PRIVATE-CONTROL-PROGRESS-READ', true === $response['success'] && $private_job->job_code === ($response['data']['code'] ?? null), ['response' => $response]);
        $set_caps(true, false);
        $before_jobs = $rows('bulk_jobs');
        $before_items = $rows('bulk_job_items');
        $check('PUBLIC-IMPORT-NATIVE-GENUINE-PRIVATE-REVOCATION', !$access->can_access($private_job) && !$allow_action($private_job));
        $response = $progress((int) $private_job->id, true);
        $check('PUBLIC-IMPORT-NATIVE-GENUINE-PRIVATE-PROGRESS-DENIED', false === $response['success'] && 'forbidden' === ($response['data']['message'] ?? null) && $before_jobs === $rows('bulk_jobs') && $before_items === $rows('bulk_job_items'), ['response' => $response]);
        $post(BulkToolsPage::ACTION_CONTINUE, ['job_id' => $private_job->id]);
        $terminal = $dispatch_post();
        $check('PUBLIC-IMPORT-NATIVE-GENUINE-PRIVATE-CONTINUE-DENIED', 'redirect_intercepted' === $terminal && $before_jobs === $rows('bulk_jobs') && $before_items === $rows('bulk_job_items'), ['control_flow' => $terminal]);
        $html = $render((int) $private_job->id);
        $check('PUBLIC-IMPORT-NATIVE-GENUINE-PRIVATE-RENDER-DENIED', !str_contains($html, $private_job->job_code) && !str_contains($html, $private_sentinel), ['html_sha256' => hash('sha256', $html)]);

        // Persist a pre-repair shape explicitly, since the repaired initiating
        // route can no longer create it for this principal. Existing records
        // must continue to deny disclosure without widening the action guard.
        $legacy_job = $jobs->save_job(BulkJob::create(BulkOperationType::ConfigImport, $fixture_user, ['scope' => 'selected_ids'], ['include_private_sources' => false, 'package' => $package->to_array(), 'conflict_mode' => 'skip_conflicts'], true)->with_status(BulkJobStatus::Ready));
        $check('PUBLIC-IMPORT-NATIVE-LEGACY-MIXED-READ-DENIED', !$access->can_access($legacy_job) && $allow_action($legacy_job), ['job_id' => $legacy_job->id, 'boundary' => 'explicitly seeded old public-action/private-retained-payload shape; historical skip-private action policy preserved']);
        $before_jobs = $rows('bulk_jobs');
        $before_items = $rows('bulk_job_items');
        $response = $progress((int) $legacy_job->id, true);
        $check('PUBLIC-IMPORT-NATIVE-LEGACY-MIXED-PROGRESS-DENIED', false === $response['success'] && 'forbidden' === ($response['data']['message'] ?? null) && $before_jobs === $rows('bulk_jobs') && $before_items === $rows('bulk_job_items'), ['response' => $response]);
        $html = $render((int) $legacy_job->id);
        $check('PUBLIC-IMPORT-NATIVE-LEGACY-MIXED-RENDER-DENIED', !str_contains($html, $legacy_job->job_code) && !str_contains($html, $private_sentinel), ['html_sha256' => hash('sha256', $html)]);
        $check('PUBLIC-IMPORT-NATIVE-ALL-PRIVATE-CONTROLS-NO-WRITE', $private_before === $private_snapshot(), ['before_sha256' => $digest($private_before), 'after_sha256' => $digest($private_snapshot())]);
        $check('PUBLIC-IMPORT-NATIVE-BOUNDARY-RECORDED', true, ['native' => ['persisted WordPress role/user grants and revocations', 'production initiating/Apply/Continue POST callbacks', 'native AJAX-context progress callback and nonce', 'production PHP detail/current history/Apply-control render', 'public offer persistence plus raw SQL jobs/items/private stores'], 'intercepted' => ['wp_redirect terminal flow', 'wp_die AJAX terminal handler', 'core wp_doing_ajax context filter for direct PHP invocation'], 'reserved' => ['HTTP/browser/login/cookie/session transport', 'external worker scheduling', 'job-history pagination', 'transitive private references in public fields', 'granular import field policy', 'CLI/delegation', 'release qualification'], 'fixture_job_ids' => [$job_id, $private_job->id, $legacy_job->id]]);
    } finally {
        remove_filter('wp_redirect', $redirect_hook, PHP_INT_MAX);
        remove_filter('wp_die_handler', $die_hook, PHP_INT_MAX);
        remove_filter('wp_die_ajax_handler', $die_hook, PHP_INT_MAX);
        $_POST = $original_post;
        $_GET = $original_get;
        $_REQUEST = $original_request;
        $set_user($original_user);
        if ($fixture_user > 0) {
            delete_transient('cetech_de_admin_notice_' . $fixture_user);
            delete_transient('cetech_de_admin_draft_' . $fixture_user . '_' . sanitize_key(BulkToolsPage::SLUG));
            if (!function_exists('wp_delete_user')) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            wp_delete_user($fixture_user);
        }
        remove_role($role_slug);
    }
};
