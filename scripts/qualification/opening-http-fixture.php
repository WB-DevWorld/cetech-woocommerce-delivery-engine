<?php
/** Synthetic public import fixture only; actual admin callbacks run over HTTP. */
declare(strict_types=1);

require __DIR__ . '/opening-http-fixture-common.php';

use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationPackage;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Presentation\Admin\BulkJobProgressEndpoint;
use CetechDeliveryEngine\Presentation\Admin\BulkToolsPage;

function opening_http_import_snapshot(array $state, int $job_id = 0): array {
    $jobs = opening_http_sql_rows('bulk_jobs');
    $items = opening_http_sql_rows('bulk_job_items', $job_id > 0 ? 'job_id=%d' : null, $job_id > 0 ? [$job_id] : []);
    $offers = opening_http_sql_rows('delivery_offers', 'internal_code=%s', [$state['offer_code']]);
    $principal = opening_http_grants((int) $state['user_id'], (string) $state['role'], ['read', 'view_admin_dashboard', 'manage_product_delivery_rules', 'import_delivery_data', 'manage_private_sources']);
    $job = null;
    $job_hash = null;
    if ($job_id > 0) {
        $selected = opening_http_sql_rows('bulk_jobs', 'id=%d AND actor_user_id=%d', [$job_id, (int) $state['user_id']]);
        if (1 !== count($selected)) {
            throw new RuntimeException('Requested HTTP job does not belong to this fixture principal.');
        }
        $row = $selected[0];
        $job_hash = opening_http_hash($selected);
        $manifest = json_decode((string) $row['action_manifest_json'], true, 512, JSON_THROW_ON_ERROR);
        $package = $manifest['package'] ?? [];
        $sections = $package['sections'] ?? [];
        $package_manifest = $package['manifest'] ?? [];
        $private_keys = ['suppliers', 'origins'];
        $private_sections = [] !== array_intersect($private_keys, array_keys($sections));
        $exported = (array) ($package_manifest['exported_sections'] ?? []);
        $counts = (array) ($package_manifest['counts'] ?? []);
        $job = [
            'id' => (int) $row['id'], 'job_code' => (string) $row['job_code'], 'status' => (string) $row['status'],
            'actor_user_id' => (int) $row['actor_user_id'], 'dry_run' => (bool) $row['dry_run'],
            'total_count' => (int) $row['total_count'], 'processed_count' => (int) $row['processed_count'],
            'changed_count' => (int) $row['changed_count'], 'failed_count' => (int) $row['failed_count'],
            'include_private_sources' => $manifest['include_private_sources'] ?? null,
            'package_include_private_sources' => $package_manifest['include_private_sources'] ?? null,
            'manifest_sections' => array_keys($sections), 'manifest_counts' => $counts,
            'private_sections_present' => $private_sections,
            'private_manifest_keys_present' => [] !== array_intersect($private_keys, $exported) || [] !== array_intersect($private_keys, array_keys($counts)),
            'contains_private_marker' => str_contains(json_encode($selected, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (string) $state['private_sentinel']),
        ];
    }
    return [
        'jobs_count' => count($jobs), 'jobs_hash' => opening_http_hash($jobs), 'job' => $job, 'job_row_hash' => $job_hash,
        'items_count' => count($items), 'items_hash' => opening_http_hash($items),
        'items_private_marker' => str_contains(json_encode($items, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (string) $state['private_sentinel']),
        'item_target_types' => array_values(array_unique(array_column($items, 'target_type'))),
        'offer_count' => count($offers), 'offers_hash' => opening_http_hash($offers), 'offer_ids' => array_map('intval', array_column($offers, 'id')),
        'private_hashes' => opening_http_private_snapshot(),
        'grants' => $principal['native'], 'grants_persisted' => $principal['persisted'], 'grants_match' => $principal['match'],
        'role_exists' => $principal['role_exists'], 'user_exists' => $principal['user_exists'],
    ];
}

$mode = (string) ($args[0] ?? '');
$state_path = (string) ($args[1] ?? '');
$output_path = (string) ($args[2] ?? '');
$job_id = (int) ($args[3] ?? 0);
if (!in_array($mode, ['prepare', 'snapshot', 'revoke', 'grant', 'cleanup'], true) || '' === $state_path || '' === $output_path) {
    throw new RuntimeException('Usage: opening-http-fixture.php MODE PRIVATE_STATE OUTPUT [JOB_ID]');
}
$identity = opening_http_identity();

if ('prepare' === $mode) {
    if (file_exists($state_path)) {
        throw new RuntimeException('Refusing to overwrite an existing HTTP credential state.');
    }
    $probe = (string) getenv('CETECH_DE_HTTP_PROBE_TOKEN');
    if (!preg_match('/^[a-f0-9]{48}$/D', $probe)) {
        throw new RuntimeException('HTTP fixture preparation requires a fresh unpredictable listener token.');
    }
    $suffix = bin2hex(random_bytes(8));
    $role = 'cetech_http_import_' . $suffix;
    $grants = ['read' => true, 'view_admin_dashboard' => true, 'manage_product_delivery_rules' => true, 'import_delivery_data' => true];
    if (!add_role($role, 'CETECH synthetic HTTP import', $grants) instanceof WP_Role) {
        throw new RuntimeException('Could not create the exclusive synthetic HTTP import role.');
    }
    $username = 'cetech_http_import_' . $suffix;
    $password = wp_generate_password(48, true, true);
    $created = wp_insert_user(['user_login' => $username, 'user_pass' => $password, 'user_email' => $username . '@fixture.invalid', 'role' => $role]);
    if (is_wp_error($created) || (int) $created < 1) {
        remove_role($role);
        throw new RuntimeException('Could not create the synthetic HTTP import principal.');
    }
    $package_path = dirname($state_path) . '/import-package-' . $suffix . '.json';
    $state = [
        'format' => 'cetech-opening-http-import-state-v1', 'site_path' => realpath(ABSPATH), 'database_name' => DB_NAME,
        'base_url' => 'http://127.0.0.1:8085', 'username' => $username, 'password' => $password,
        'user_id' => (int) $created, 'role' => $role, 'probe_token' => $probe,
        'offer_code' => 'qa-http-public-import-' . $suffix, 'private_sentinel' => 'qa-http-private-source-' . $suffix,
        'packages' => ['public_mixed' => $package_path],
        'import_slug' => BulkToolsPage::SLUG, 'import_action' => BulkToolsPage::ACTION_CONFIG_IMPORT,
        'apply_action' => BulkToolsPage::ACTION_APPLY, 'progress_action' => BulkJobProgressEndpoint::ACTION,
        'identity' => $identity,
    ];
    $package = ConfigurationPackage::create('http-qualification', '6', [
        'delivery_options' => [['internal_code' => $state['offer_code'], 'internal_name' => 'Synthetic HTTP import ' . $suffix, 'public_label' => 'HTTP public fixture', 'route' => 'local_delivery', 'service_level' => 'standard', 'status' => 'active']],
        'suppliers' => [['internal_code' => 'qa-http-supplier-' . $suffix, 'internal_name' => $state['private_sentinel']]],
        'origins' => [['internal_code' => 'qa-http-origin-' . $suffix, 'internal_name' => $state['private_sentinel']]],
    ], true);
    // Persist the tracked identity before later package/marker work. An earlier
    // allocation failure still relies on disposal of the dedicated CI database.
    $state['baseline'] = opening_http_import_snapshot($state);
    opening_http_write_json($state_path, $state, true);
    opening_http_write_json($package_path, $package->to_array(), true);
    update_option('cetech_opening_http_qualification_disposable', '1', false);
    if ('1' !== (string) get_option('cetech_opening_http_qualification_disposable')) {
        throw new RuntimeException('Could not persist the HTTP qualification fixture marker.');
    }
    if (!$state['baseline']['grants_match'] || $state['baseline']['grants']['manage_private_sources'] || !$state['baseline']['grants']['import_delivery_data'] || 0 !== $state['baseline']['offer_count']) {
        throw new RuntimeException('Synthetic HTTP import grants or public fixture uniqueness diverged.');
    }
    opening_http_write_json($state_path, $state, true);
    $output = $identity + ['mode' => $mode, 'principal_user_id' => $state['user_id'], 'principal_role' => $role, 'baseline' => $state['baseline']];
} else {
    $state = opening_http_read_state($state_path);
    if ('cetech-opening-http-import-state-v1' !== ($state['format'] ?? '') || !preg_match('/^cetech_http_import_[a-f0-9]{16}$/D', (string) ($state['role'] ?? ''))) {
        throw new RuntimeException('Unrecognized HTTP import fixture state.');
    }
    if (in_array($mode, ['revoke', 'grant'], true)) {
        $role = get_role((string) $state['role']);
        if (!$role instanceof WP_Role) {
            throw new RuntimeException('Synthetic HTTP import role is absent.');
        }
        'grant' === $mode ? $role->add_cap('import_delivery_data') : $role->remove_cap('import_delivery_data');
        clean_user_cache((int) $state['user_id']);
    }
    if ('cleanup' === $mode) {
        global $wpdb;
        $owned = opening_http_sql_rows('bulk_jobs', 'actor_user_id=%d', [(int) $state['user_id']]);
        foreach ($owned as $row) {
            if (false === $wpdb->delete(TableNames::for('bulk_job_items'), ['job_id' => (int) $row['id']], ['%d']) || false === $wpdb->delete(TableNames::for('bulk_jobs'), ['id' => (int) $row['id'], 'actor_user_id' => (int) $state['user_id']], ['%d', '%d'])) {
                throw new RuntimeException('Could not clean synthetic HTTP import job rows.');
            }
        }
        if (false === $wpdb->delete(TableNames::for('delivery_offers'), ['internal_code' => $state['offer_code']], ['%s'])) {
            throw new RuntimeException('Could not clean the synthetic HTTP public offer.');
        }
        delete_transient('cetech_de_admin_notice_' . $state['user_id']);
        delete_transient('cetech_de_admin_draft_' . $state['user_id'] . '_' . BulkToolsPage::SLUG);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        if (false !== get_user_by('id', (int) $state['user_id']) && !wp_delete_user((int) $state['user_id'])) {
            throw new RuntimeException('Could not delete the synthetic HTTP principal.');
        }
        remove_role((string) $state['role']);
        $output = opening_http_import_snapshot($state);
        $baseline = $state['baseline'];
        $output['cleanup_restored'] = !$output['user_exists'] && !$output['role_exists']
            && $baseline['jobs_count'] === $output['jobs_count'] && $baseline['jobs_hash'] === $output['jobs_hash']
            && $baseline['items_count'] === $output['items_count'] && $baseline['items_hash'] === $output['items_hash']
            && 0 === $output['offer_count'] && $baseline['private_hashes'] === $output['private_hashes'];
        $output['cleanup_scope'] = 'Fixture actor bulk_jobs/bulk_job_items and own delivery_offers; supplier/origin hashes unchanged; synthetic user/role removed. Action Scheduler and ancillary WordPress state are outside this baseline and remain disposable.';
        foreach ((array) ($state['packages'] ?? []) as $path) {
            if (is_file($path) && realpath(dirname($path)) === realpath(dirname($state_path))) {
                unlink($path);
            }
        }
        unlink($state_path);
    } else {
        $output = opening_http_import_snapshot($state, $job_id);
        if (in_array($mode, ['revoke', 'grant'], true) && (!$output['grants_match'] || $output['grants']['manage_private_sources'] || ('grant' === $mode) !== $output['grants']['import_delivery_data'])) {
            throw new RuntimeException('Physical and native HTTP import revocation state diverged.');
        }
    }
    $output['mode'] = $mode;
}
opening_http_write_json($output_path, $output);
echo json_encode(['mode' => $mode, 'result' => 'fixture_state_recorded'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
