<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Presentation\Admin\AdministratorAccessRecovery;
use CetechDeliveryEngine\Presentation\Admin\BulkJobAccess;
use CetechDeliveryEngine\Presentation\Admin\BulkToolsPage;
use CetechDeliveryEngine\Presentation\Admin\SetupWizardPage;

/**
 * Disposable native WordPress qualification only. No WP function/capability stubs.
 * Run after native plugin activation, with WP_ADMIN=true before wp-load.php.
 * wp_redirect/wp_die_handler hooks interrupt terminal control flow; this proves
 * PHP callbacks and SQL persistence, not HTTP, cookies, browser/session isolation,
 * WCFM-installed behavior, external workers, delegated grants or release fitness.
 */
return static function ( callable $check ): void {
    global $wpdb;

    $check('AUTH-PRECONDITION-NATIVE', is_admin() && class_exists(Plugin::class) && function_exists('wc_get_base_location'), ['admin' => is_admin(), 'wordpress' => get_bloginfo('version'), 'woocommerce' => defined('WC_VERSION') ? WC_VERSION : null]);
    $container = Plugin::instance()->container();
    $engine = $container->get(BulkJobEngine::class);
    $jobs = $container->get(BulkJobRepositoryInterface::class);
    $access = new BulkJobAccess($engine);
    $bulk_page = $container->get(BulkToolsPage::class);
    $wizard = $container->get(SetupWizardPage::class);
    $progress = $container->get(SetupWizardProgress::class);
    $recovery = $container->get(AdministratorAccessRecovery::class);
    $role_option = $wpdb->prefix . 'user_roles';
    $original_user = get_current_user_id();
    $original_post = $_POST;
    $original_get = $_GET;
    $original_request = $_REQUEST;
    $old_progress = get_option(SetupWizardProgress::OPTION_NAME, null);
    $role_snapshot = get_role('administrator')->capabilities;
    $suffix = substr(bin2hex(random_bytes(6)), 0, 10);
    $role_slug = 'cetech_opening_qa_' . $suffix;
    $role = add_role($role_slug, 'CETECH opening qualification fixture', ['read' => true]);
    $check('AUTH-FIXTURE-ROLE', $role instanceof WP_Role);
    $fixture_users = [];
    $fixture_jobs = [];
    $redirect_exception = new class('fixture redirect intercepted') extends RuntimeException {};
    $die_exception = new class('fixture wp_die intercepted') extends RuntimeException {};
    $redirect_hook = static function ($location) use ($redirect_exception): never {
        throw $redirect_exception;
    };
    $die_handler = static function () use ($die_exception): never {
        throw $die_exception;
    };
    $die_hook = static fn () => $die_handler;
    add_filter('wp_redirect', $redirect_hook, PHP_INT_MAX);
    add_filter('wp_die_handler', $die_hook, PHP_INT_MAX);
    // WPCLI may choose another standard die handler for JSON/AJAX requests.
    add_filter('wp_die_ajax_handler', $die_hook, PHP_INT_MAX);

    $sql_option = static function (string $name) use ($wpdb): ?string {
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s", $name));
        if ('' !== $wpdb->last_error) {
            throw new RuntimeException($wpdb->last_error);
        }
        return null === $value ? null : (string) $value;
    };
    $sql_roles = static function () use ($sql_option, $role_option): array {
        $value = maybe_unserialize($sql_option($role_option));
        return is_array($value) ? $value : [];
    };
    $sql_rows = static function (string $suffix) use ($wpdb): array {
        $table = TableNames::for($suffix);
        $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY id", ARRAY_A);
        if ('' !== $wpdb->last_error || !is_array($rows)) {
            throw new RuntimeException('Native SQL snapshot failed for ' . $suffix . ': ' . $wpdb->last_error);
        }
        return $rows;
    };
    $entities_snapshot = static function () use ($sql_rows): array {
        $result = [];
        foreach (['delivery_offers', 'destination_zones', 'destination_rules', 'rate_cards', 'pickup_locations'] as $table) {
            $result[$table] = $sql_rows($table);
        }
        return $result;
    };
    $digest = static fn (array $rows): string => hash('sha256', wp_json_encode($rows));
    $set_user = static function (int $id): void {
        wp_set_current_user(0);
        clean_user_cache($id);
        wp_set_current_user($id);
    };
    $create_user = static function (string $name, string $user_role) use (&$fixture_users, $suffix, $check): int {
        $id = wp_insert_user(['user_login' => 'cetech_opening_qa_' . $name . '_' . $suffix, 'user_pass' => wp_generate_password(32), 'role' => $user_role]);
        $check('AUTH-USER-CREATE-' . $name, !is_wp_error($id) && $id > 0);
        $fixture_users[] = (int) $id;
        return (int) $id;
    };
    $caps_revision = 0;
    $set_caps = static function (array $caps, int $user_id) use ($role, $set_user, $sql_roles, $role_slug, $check, &$caps_revision): void {
        foreach (Capabilities::ALL as $cap) {
            $role->remove_cap($cap);
        }
        $role->remove_cap('manage_options');
        foreach ($caps as $cap) {
            $role->add_cap($cap);
        }
        $set_user($user_id);
        $persisted = $sql_roles()[$role_slug]['capabilities'] ?? [];
        $correct = true;
        foreach (Capabilities::ALL as $cap) {
            $expected = in_array($cap, $caps, true);
            $correct = $correct && $expected === !empty($persisted[$cap]) && $expected === current_user_can($cap);
        }
        $check('AUTH-PERSISTED-GRANTS-' . ++$caps_revision, $correct, ['capabilities' => $caps, 'principal' => $user_id]);
    };
    $dispatch = static function (callable $callback) use ($redirect_exception, $die_exception): string {
        try {
            $callback();
            return 'returned';
        } catch (Throwable $error) {
            if ($error === $redirect_exception) {
                return 'redirect_intercepted';
            }
            if ($error === $die_exception) {
                return 'wp_die_intercepted';
            }
            throw $error;
        }
    };
    $post = static function (string $action, array $data = [], ?string $nonce = null): void {
        $_GET = [];
        $_POST = ['cetech_de_action' => $action, 'cetech_de_nonce' => $nonce ?? wp_create_nonce($action)] + $data;
        $_REQUEST = $_POST;
    };
    $action_allowed = static function (BulkJob $job) use ($access): bool {
        try {
            $access->require_action_access($job);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    };
    $save_job = static function (BulkOperationType $type, int $actor, array $manifest = [], array $target = [], ?int $parent = null) use ($jobs, &$fixture_jobs): BulkJob {
        $job = $jobs->save_job(BulkJob::create($type, $actor, $target, ['cetech_opening_qa' => true] + $manifest, true, 25, $parent)->with_status(BulkJobStatus::Ready));
        $fixture_jobs[] = (int) $job->id;
        return $job;
    };

    try {
        $operator = $create_user('operator', $role_slug);
        $other_actor = $create_user('other_actor', 'subscriber');
        $administrator = $create_user('administrator', 'administrator');
        $set_caps(['manage_product_delivery_rules'], $operator);
        $user_caps_raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM `{$wpdb->usermeta}` WHERE user_id=%d AND meta_key=%s", $operator, $wpdb->prefix . 'capabilities'));
        $user_caps = maybe_unserialize($user_caps_raw);
        $check('AUTH-NATIVE-PRINCIPAL-ROLE-PERSISTED', is_array($user_caps) && true === ($user_caps[$role_slug] ?? false) && $operator === get_current_user_id(), ['principal' => $operator, 'role' => $role_slug, 'usermeta_sha256' => hash('sha256', (string) $user_caps_raw)]);
        $catalog_job = $save_job(BulkOperationType::CatalogUpdate, $other_actor);
        $rate_job = $save_job(BulkOperationType::RateCardUpdate, $operator);
        $import_job = $save_job(BulkOperationType::ConfigImport, $operator);
        $check('COR001-NATIVE-OPERATION-MAPPING', $access->can_access($catalog_job) && !$access->can_access($rate_job) && !$action_allowed($rate_job) && !$access->can_access($import_job), ['current_user' => $operator, 'catalog_actor' => $other_actor, 'rate_actor' => $operator, 'boundary' => 'native helper; attribution does not grant operation authority']);
        $before = $sql_rows('bulk_jobs');
        $post(BulkToolsPage::ACTION_CANCEL, ['job_id' => $rate_job->id]);
        $terminal = $dispatch([$bulk_page, 'handle_actions']);
        $after = $sql_rows('bulk_jobs');
        $check('COR001-NATIVE-POST-CANCEL-DENIED', 'redirect_intercepted' === $terminal && $before === $after, ['rows_before' => $digest($before), 'rows_after' => $digest($after), 'control_flow' => $terminal]);
        $set_caps(['manage_product_delivery_rules', 'manage_delivery_rate_cards', 'import_delivery_data'], $operator);
        $check('COR001-NATIVE-EXACT-GRANT', $access->can_access($rate_job) && $action_allowed($rate_job) && $access->can_access($import_job));
        $before = $sql_rows('bulk_jobs');
        $post(BulkToolsPage::ACTION_CANCEL, ['job_id' => $rate_job->id], wp_create_nonce(BulkToolsPage::ACTION_APPLY));
        $terminal = $dispatch([$bulk_page, 'handle_actions']);
        $check('COR001-NATIVE-POST-NONCE-DENIED', 'redirect_intercepted' === $terminal && $before === $sql_rows('bulk_jobs'), ['control_flow' => $terminal]);
        $post(BulkToolsPage::ACTION_CANCEL, ['job_id' => $rate_job->id]);
        $terminal = $dispatch([$bulk_page, 'handle_actions']);
        $cancelled = $engine->find((int) $rate_job->id);
        $check('COR001-NATIVE-POST-CANCEL-PERMITTED', 'redirect_intercepted' === $terminal && $cancelled instanceof BulkJob && BulkJobStatus::Cancelled === $cancelled->status && $cancelled->cancel_requested, ['job_id' => $rate_job->id, 'status' => $cancelled?->status->value, 'sql_digest' => $digest($sql_rows('bulk_jobs')), 'control_flow' => $terminal]);
        $set_caps(['manage_product_delivery_rules', 'import_delivery_data'], $operator);
        $persisted_caps = $sql_roles()[$role_slug]['capabilities'];
        $check('COR001-NATIVE-REVOCATION', !isset($persisted_caps['manage_delivery_rate_cards']) && !current_user_can('manage_delivery_rate_cards') && !$access->can_access($rate_job) && !$action_allowed($rate_job));

        $private_job = $save_job(BulkOperationType::ConfigImport, $operator, ['include_private_sources' => true]);
        $public_with_retained_private = $save_job(BulkOperationType::ConfigImport, $operator, ['include_private_sources' => false, 'package' => ['manifest' => ['include_private_sources' => true], 'sections' => ['suppliers' => [['internal_name' => 'cetech_opening_qa_private']], 'origins' => []]]]);
        $check('COR001-NATIVE-PRIVATE-READ-ACTION-SEPARATION', !$access->can_access($private_job) && !$action_allowed($private_job) && !$access->can_access($public_with_retained_private) && $action_allowed($public_with_retained_private), ['boundary' => 'native helper with retained payload persisted in MariaDB; public-only skip-private action remains permitted']);
        $_GET = ['tab' => 'jobs', 'job' => (string) $private_job->id];
        ob_start();
        try {
            $bulk_page->render();
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $check('COR001-NATIVE-PRIVATE-RENDER-DENIED', !str_contains($html, $private_job->job_code) && !str_contains($html, $public_with_retained_private->job_code) && str_contains($html, $catalog_job->job_code), ['html_sha256' => hash('sha256', $html), 'boundary' => 'actual PHP render callback, not browser/HTTP']);
        $set_caps(['manage_product_delivery_rules', 'import_delivery_data', 'manage_private_sources'], $operator);
        $check('COR001-NATIVE-PRIVATE-PERMITTED', $access->can_access($private_job) && $action_allowed($private_job) && $access->can_access($public_with_retained_private));
        $set_caps(['manage_product_delivery_rules', 'import_delivery_data'], $operator);
        $check('COR001-NATIVE-PRIVATE-REVOKED', !$access->can_access($private_job) && !$action_allowed($private_job));
        $filtered_job = $save_job(BulkOperationType::CatalogUpdate, $operator, [], ['filters' => ['origin_id' => 123]]);
        $check('COR001-NATIVE-PRIVATE-FILTER-DENIED', !$access->can_access($filtered_job));
        $set_caps(['manage_product_delivery_rules', 'view_private_origins'], $operator);
        $check('COR001-NATIVE-PRIVATE-FILTER-OR-GRANT', $access->can_access($filtered_job) && $action_allowed($filtered_job));
        $rollback = $save_job(BulkOperationType::Rollback, $operator, [], [], $import_job->id);
        $check('COR001-NATIVE-ROLLBACK-ANCESTOR-DENIED', !$access->can_access($rollback) && !$action_allowed($rollback));
        $set_caps(['manage_product_delivery_rules', 'import_delivery_data'], $operator);
        $check('COR001-NATIVE-ROLLBACK-ANCESTOR-PERMITTED', $access->can_access($rollback) && $action_allowed($rollback));
        $missing = $save_job(BulkOperationType::Rollback, $operator, [], [], 2147483647);
        $cycle = $save_job(BulkOperationType::Rollback, $operator, [], [], $rollback->id);
        $cycle = $jobs->save_job($cycle->with(['parent_job_id' => $cycle->id]));
        $check('COR001-NATIVE-ROLLBACK-MISSING-CYCLE-DENIED', !$access->can_access($missing) && !$access->can_access($cycle));
        foreach ([
            [BulkOperationType::CatalogCsvImport, 'import_delivery_data'],
            [BulkOperationType::CatalogCsvExport, 'import_delivery_data'],
            [BulkOperationType::ConfigExport, 'manage_delivery_settings'],
            [BulkOperationType::ValidationScan, 'manage_product_delivery_rules'],
        ] as [$type, $capability]) {
            $job = $save_job($type, $other_actor);
            $set_caps([$capability], $operator);
            $check('COR001-NATIVE-OPERATION-PERMITTED-' . $type->value, $access->can_access($job) && $action_allowed($job));
            $set_caps([], $operator);
            $check('COR001-NATIVE-OPERATION-REVOKED-' . $type->value, !$access->can_access($job) && !$action_allowed($job));
        }
        $set_caps(Capabilities::ALL, $operator);
        foreach ([BulkOperationType::EntityUpdate, BulkOperationType::Cleanup] as $unsupported_type) {
            $unsupported = $save_job($unsupported_type, $operator);
            $check('COR001-NATIVE-UNSUPPORTED-' . $unsupported_type->value, !$access->can_access($unsupported) && !$action_allowed($unsupported));
        }

        $check('COR003-NATIVE-EMPTY-ZONES-PRECONDITION', [] === $sql_rows('destination_zones'), ['reason' => 'dedicated disposable site; implicit-area case must precede any area creation']);
        $progress->save(['status' => SetupWizardProgress::STATUS_IN_PROGRESS, 'step' => 3, 'draft' => ['active_profiles' => ['in_warehouse', 'in_store'], 'primary_profile' => 'in_warehouse']]);
        $entity_actions = [
            'option' => [SetupWizardPage::ACTION_CREATE_OPTION, 'manage_delivery_offers', 'delivery_offers', ['option_name' => 'cetech_opening_qa_option_' . $suffix, 'option_route' => 'local_delivery', 'option_active' => '1']],
            'charge' => [SetupWizardPage::ACTION_CREATE_CHARGE, 'manage_delivery_rate_cards', 'rate_cards', ['charge_name' => 'cetech_opening_qa_charge_' . $suffix, 'charge_amount' => '20', 'charge_style' => 'flat']],
            'area' => [SetupWizardPage::ACTION_CREATE_AREA, 'manage_delivery_zones', 'destination_zones', ['area_name' => 'cetech_opening_qa_area_' . $suffix, 'area_city' => 'Accra']],
            'pickup' => [SetupWizardPage::ACTION_CREATE_PICKUP, Capabilities::PICKUP, 'pickup_locations', ['pickup_name' => 'cetech_opening_qa_pickup_' . $suffix, 'pickup_address' => 'Fixture address', 'pickup_city' => 'Accra', 'pickup_ready' => 'Ready in 2 hours', 'profile_key' => 'in_store']],
        ];
        $set_caps(['manage_delivery_settings'], $operator);
        foreach ($entity_actions as $kind => [$action, $capability, $table, $input]) {
            $before = $entities_snapshot();
            $before_progress = $sql_option(SetupWizardProgress::OPTION_NAME);
            $post($action, $input);
            $terminal = $dispatch([$wizard, 'handle_actions']);
            $after = $entities_snapshot();
            $check('COR003-NATIVE-SETTINGS-ONLY-DENIED-' . $kind, 'wp_die_intercepted' === $terminal && $before === $after && $before_progress === $sql_option(SetupWizardProgress::OPTION_NAME), ['rows_before' => $digest($before), 'rows_after' => $digest($after), 'control_flow' => $terminal]);
        }
        [$action, $capability, $table, $input] = $entity_actions['option'];
        $set_caps(['manage_delivery_settings', $capability], $operator);
        $before = $entities_snapshot();
        $post($action, $input);
        $terminal = $dispatch([$wizard, 'handle_actions']);
        $after = $entities_snapshot();
        $check('COR003-NATIVE-OPTION-PERMITTED', 'redirect_intercepted' === $terminal && count($after[$table]) === count($before[$table]) + 1, ['created_rows' => array_values(array_diff(array_column($after[$table], 'id'), array_column($before[$table], 'id'))), 'control_flow' => $terminal]);
        $offer_id = (int) end($after[$table])['id'];
        [$action, $capability, $table, $input] = $entity_actions['charge'];
        $input += ['charge_option_id' => $offer_id, 'charge_area_id' => 0];
        $set_caps(['manage_delivery_settings', $capability], $operator);
        $before = $entities_snapshot();
        $post($action, $input);
        $terminal = $dispatch([$wizard, 'handle_actions']);
        $after = $entities_snapshot();
        $check('COR003-NATIVE-IMPLICIT-AREA-DENIED', 'wp_die_intercepted' === $terminal && $before === $after, ['rows_before' => $digest($before), 'rows_after' => $digest($after), 'control_flow' => $terminal]);
        $set_caps(['manage_delivery_settings', $capability, 'manage_delivery_zones'], $operator);
        $post($action, $input);
        $terminal = $dispatch([$wizard, 'handle_actions']);
        $after = $entities_snapshot();
        $check('COR003-NATIVE-IMPLICIT-AREA-PERMITTED', 'redirect_intercepted' === $terminal && count($after['destination_zones']) === count($before['destination_zones']) + 1 && count($after[$table]) === count($before[$table]) + 1 && count($after['destination_rules']) > count($before['destination_rules']), ['sql_digest' => $digest($after), 'control_flow' => $terminal]);
        $set_caps(['manage_delivery_settings', $capability], $operator);
        $before = $after;
        $input['charge_name'] .= '_existing_area';
        $post($action, $input);
        $terminal = $dispatch([$wizard, 'handle_actions']);
        $after = $entities_snapshot();
        $check('COR003-NATIVE-EXISTING-AREA-WITHOUT-ZONE-GRANT', 'redirect_intercepted' === $terminal && $before['destination_zones'] === $after['destination_zones'] && count($after[$table]) === count($before[$table]) + 1, ['control_flow' => $terminal]);
        foreach (['area', 'pickup'] as $kind) {
            [$action, $capability, $table, $input] = $entity_actions[$kind];
            $set_caps(['manage_delivery_settings', $capability], $operator);
            $before = $entities_snapshot();
            $post($action, $input);
            $terminal = $dispatch([$wizard, 'handle_actions']);
            $after = $entities_snapshot();
            $check('COR003-NATIVE-' . strtoupper($kind) . '-PERMITTED', 'redirect_intercepted' === $terminal && count($after[$table]) === count($before[$table]) + 1, ['sql_digest' => $digest($after), 'control_flow' => $terminal]);
        }
        [$action, $capability, $table, $input] = $entity_actions['pickup'];
        $set_caps(['manage_delivery_settings', 'manage_delivery_zones'], $operator);
        $before = $entities_snapshot();
        $post($action, $input);
        $terminal = $dispatch([$wizard, 'handle_actions']);
        $check('COR003-NATIVE-PICKUP-ZONE-IS-NOT-GRANT', 'wp_die_intercepted' === $terminal && $before === $entities_snapshot());
        $set_caps(['manage_delivery_settings', Capabilities::PICKUP], $operator);
        $correct_nonce = wp_create_nonce($action);
        $set_user($other_actor);
        $other_nonce = wp_create_nonce($action);
        $set_user($operator);
        foreach (['missing' => '', 'wrong_action' => wp_create_nonce(SetupWizardPage::ACTION_CREATE_AREA), 'other_principal' => $other_nonce] as $kind => $nonce) {
            $before = $entities_snapshot();
            $before_progress = $sql_option(SetupWizardProgress::OPTION_NAME);
            $post($action, $input, $nonce);
            if ('missing' === $kind) {
                unset($_POST['cetech_de_nonce'], $_REQUEST['cetech_de_nonce']);
            }
            $terminal = $dispatch([$wizard, 'handle_actions']);
            $check('COR003-NATIVE-NONCE-DENIED-' . $kind, 'redirect_intercepted' === $terminal && $before === $entities_snapshot() && $before_progress === $sql_option(SetupWizardProgress::OPTION_NAME), ['native_nonce_valid' => false !== wp_verify_nonce($nonce, $action), 'control_flow' => $terminal]);
        }
        $set_caps(['manage_delivery_settings'], $operator);
        $check('COR003-NATIVE-NONCE-SURVIVES-CAP-REVOCATION', false !== wp_verify_nonce($correct_nonce, $action) && !current_user_can(Capabilities::PICKUP));
        $before = $entities_snapshot();
        $post($action, $input, $correct_nonce);
        $terminal = $dispatch([$wizard, 'handle_actions']);
        $check('COR003-NATIVE-REVOKED-PICKUP-DENIED', 'wp_die_intercepted' === $terminal && $before === $entities_snapshot());

        $admin_role = get_role('administrator');
        $admin_role->remove_cap(Capabilities::PICKUP);
        $before_roles = $sql_roles();
        $check('COR003-NATIVE-RECOVERY-REPAIR-NEEDED', $recovery->needs_repair() && empty($before_roles['administrator']['capabilities'][Capabilities::PICKUP]));
        $set_caps([Capabilities::DIAGNOSTICS], $operator);
        $check('COR003-NATIVE-RECOVERY-CUSTOM-CAP-DENIED', !$recovery->can_recover() && !$recovery->restore_if_authorized() && $before_roles['administrator'] === $sql_roles()['administrator']);
        $set_user($administrator);
        $check('COR003-NATIVE-RECOVERY-MANAGE-OPTIONS', current_user_can('manage_options') && $recovery->can_recover());
        $before_roles = $sql_roles();
        $_POST = ['cetech_de_nonce' => wp_create_nonce('cetech_opening_qa_wrong_recovery')];
        $_REQUEST = $_POST;
        $terminal = $dispatch([$recovery, 'process_restore_request']);
        $check('COR003-NATIVE-RECOVERY-NONCE-DENIED', 'wp_die_intercepted' === $terminal && $before_roles === $sql_roles(), ['control_flow' => $terminal]);
        $_POST = ['cetech_de_nonce' => wp_create_nonce(AdministratorAccessRecovery::ACTION)];
        $_REQUEST = $_POST;
        $check('COR003-NATIVE-RECOVERY-PERMITTED', true === $recovery->process_restore_request());
        $set_user($administrator);
        $after_roles = $sql_roles();
        $all_repaired = true;
        foreach (Capabilities::ADMINISTRATOR_RECOVERY as $cap) {
            $all_repaired = $all_repaired && true === ($after_roles['administrator']['capabilities'][$cap] ?? false);
        }
        unset($before_roles['administrator'], $after_roles['administrator']);
        $check('COR003-NATIVE-RECOVERY-ONLY-ADMINISTRATOR', $all_repaired && current_user_can(Capabilities::PICKUP) && $before_roles === $after_roles && !$recovery->needs_repair(), ['required_capability_count' => count(Capabilities::ADMINISTRATOR_RECOVERY), 'subordinate_roles_unchanged' => $before_roles === $after_roles]);
        $before_roles = $sql_roles();
        $check('COR003-NATIVE-RECOVERY-IDEMPOTENT', $recovery->restore_if_authorized() && $before_roles === $sql_roles());
        $check('AUTH-NATIVE-BOUNDARY-RECORDED', true, ['native' => ['persisted WordPress roles/users', 'current_user_can', 'wp_create_nonce/wp_verify_nonce/check_admin_referer', 'production PHP admin callbacks', 'production wpdb repositories and SQL read-back'], 'intercepted' => ['wp_redirect', 'wp_die handler'], 'reserved' => ['HTTP/browser/cookie/session transport', 'installed WCFM vendor identity', 'cross-session cache isolation', 'CLI/delegation policy', 'external worker scheduling', 'full release qualification'], 'fixture_job_ids' => $fixture_jobs, 'fixture_user_ids' => $fixture_users]);
    } finally {
        remove_filter('wp_redirect', $redirect_hook, PHP_INT_MAX);
        remove_filter('wp_die_handler', $die_hook, PHP_INT_MAX);
        remove_filter('wp_die_ajax_handler', $die_hook, PHP_INT_MAX);
        $admin_role = get_role('administrator');
        foreach ($admin_role->capabilities as $cap => $enabled) {
            if (!array_key_exists($cap, $role_snapshot)) {
                $admin_role->remove_cap($cap);
            }
        }
        foreach ($role_snapshot as $cap => $enabled) {
            $admin_role->add_cap($cap, (bool) $enabled);
        }
        if (null === $old_progress) {
            delete_option(SetupWizardProgress::OPTION_NAME);
        } else {
            update_option(SetupWizardProgress::OPTION_NAME, $old_progress, false);
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($fixture_users as $id) {
            delete_transient('cetech_de_admin_notice_' . $id);
            wp_delete_user($id);
        }
        remove_role($role_slug);
        $set_user($original_user);
        $_POST = $original_post;
        $_GET = $original_get;
        $_REQUEST = $original_request;
        // Fixture entity/job rows intentionally remain for final SQL inspection.
        // Caller must discard this disposable site/database after qualification.
    }
};
