<?php
/** Exact tracked ownership for supplementary native staff-guide HTTP requests. */
declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePlacementActivation;
use CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseStaffIntent;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromiseSiteBinding, PromiseVersionCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Integrations\ServicePromise\Configuration\NativePromiseConfigurationAuthority;

final class CetechPromiseStaffGuideHttpFixture {
    private const INDEX = 'cetech_de_promise_staff_index';
    private static bool $observing = false;

    public static function register(): void {
        // Even an inert query callback changes the native shopper source fence.
        // Observe only this owned guide POST; all other requests keep its hooks untouched.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_admin() || ($_GET['page'] ?? null) !== 'cetech-de-promises'
            || ($_POST['cetech_de_action'] ?? null) !== 'cetech_de_promise_guide') { return; }
        $state = cetech_q05_state();
        if (true !== ($state['p05_guide']['active'] ?? false) || true !== ($state['p05']['active'] ?? false)
            || !is_int($state['p05']['admin']['id'] ?? null) || get_current_user_id() !== $state['p05']['admin']['id']
            || ($state['site_id'] ?? null) !== get_current_blog_id()) { return; }
        self::guard($state);
        add_filter('query', static function(string $sql): string {
            if (self::$observing || !str_contains($sql, 'cetech_de_staff_intent_') || !preg_match('/\A(?:INSERT|UPDATE|DELETE)\b/', $sql)) { return $sql; }
            self::$observing = true;
            try {
                $state = cetech_q05_state();
                if (true !== ($state['p05_guide']['active'] ?? false)) { return $sql; }
                self::guard($state);
                if (!str_contains($sql, $state['p05_guide']['journal'])) { return $sql; }
                if (get_current_user_id() !== $state['p05']['admin']['id'] || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['cetech_de_action'] ?? '') !== 'cetech_de_promise_guide') { throw new RuntimeException('Guide journal actor/action differs.'); }
                $documents = self::journal_sql($sql, $GLOBALS['wpdb']->options, $state['p05_guide']['journal']);
                foreach ($documents as $bytes) {
                    $plan = PromiseStaffIntent::decode($bytes);
                    if (($plan['owner'] ?? null) !== $state['p05']['admin']['id'] || ($plan['site_id'] ?? null) !== $state['site_id'] || ($plan['site_key'] ?? null) !== 'store'
                        || ($plan['scope'] ?? null) !== $state['p05_guide']['scope'] || ($plan['row']['policy_id'] ?? null) !== $state['p05_guide']['policy_id']) { throw new RuntimeException('Guide journal binding differs.'); }
                    foreach ($plan['steps'] as $step) { self::track($state, $step); }
                    $state['p05_guide']['journal_bytes'][hash('sha256', $bytes)] = $bytes;
                    $state['p05_guide']['index_rows'][hash('sha256', serialize($plan['row']))] = $plan['row'];
                }
                cetech_q05_write($state);
            } finally { self::$observing = false; }
            return $sql;
        }, PHP_INT_MAX);
    }

    /** Parse only the three exact SQL shapes emitted by the private intent store. */
    public static function journal_sql(string $sql, string $table, string $name): array {
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $table) || !preg_match('/\Acetech_de_staff_intent_[a-f0-9]{64}\z/D', $name) || strlen($sql) > 1048576) { throw new RuntimeException('Guide journal SQL bounds differ.'); }
        $cursor = 0; $prefix = '`' . $table . '`'; $values = [];
        if (str_starts_with($sql, 'INSERT INTO ')) {
            self::sql_piece($sql, $cursor, 'INSERT INTO ' . $prefix . ' (option_name, option_value, autoload) VALUES (');
            $observed = self::sql_value($sql, $cursor);
            if ($observed !== $name) { throw new RuntimeException('Guide journal option differs.'); }
            self::sql_piece($sql, $cursor, ', '); $values[] = self::sql_value($sql, $cursor);
            self::sql_piece($sql, $cursor, ", 'off')");
        } elseif (str_starts_with($sql, 'UPDATE ')) {
            self::sql_piece($sql, $cursor, 'UPDATE ' . $prefix . ' SET option_value = '); $after = self::sql_value($sql, $cursor);
            self::sql_piece($sql, $cursor, ' WHERE option_name = '); $first = self::sql_value($sql, $cursor);
            self::sql_piece($sql, $cursor, ' AND BINARY option_name = '); $second = self::sql_value($sql, $cursor);
            self::sql_piece($sql, $cursor, ' AND BINARY option_value = '); $before = self::sql_value($sql, $cursor);
            if ($first !== $name || $second !== $name) { throw new RuntimeException('Guide journal option differs.'); }
            $values = [$after, $before];
        } elseif (str_starts_with($sql, 'DELETE FROM ')) {
            self::sql_piece($sql, $cursor, 'DELETE FROM ' . $prefix . ' WHERE option_name = '); $first = self::sql_value($sql, $cursor);
            self::sql_piece($sql, $cursor, ' AND BINARY option_name = '); $second = self::sql_value($sql, $cursor);
            self::sql_piece($sql, $cursor, ' AND BINARY option_value = '); $values[] = self::sql_value($sql, $cursor);
            if ($first !== $name || $second !== $name) { throw new RuntimeException('Guide journal option differs.'); }
        } else { throw new RuntimeException('Guide journal SQL shape differs.'); }
        if ($cursor !== strlen($sql)) { throw new RuntimeException('Guide journal SQL shape differs.'); }
        return $values;
    }

    private static function sql_piece(string $sql, int &$cursor, string $piece): void {
        if (substr($sql, $cursor, strlen($piece)) !== $piece) { throw new RuntimeException('Guide journal SQL shape differs.'); }
        $cursor += strlen($piece);
    }

    /** Each byte is visited once; escaping never changes the fixed SQL template. */
    private static function sql_value(string $sql, int &$cursor): string {
        self::sql_piece($sql, $cursor, "'"); $start = $cursor; $length = strlen($sql);
        while ($cursor < $length) {
            if ($sql[$cursor] === "'") { $raw = substr($sql, $start, $cursor - $start); ++$cursor; return self::sql_literal($raw); }
            if ($sql[$cursor] === '\\') { if ($cursor + 1 >= $length) { break; } $cursor += 2; }
            else { ++$cursor; }
        }
        throw new RuntimeException('Guide journal SQL shape differs.');
    }

    public static function sql_literal(string $value): string {
        return preg_replace_callback('/\\\\(.)/s', static function(array $match): string {
            return match ($match[1]) { '0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => "\x1a", '\\' => '\\', "'" => "'", '"' => '"', default => throw new RuntimeException('Unsupported journal SQL escape.') };
        }, $value);
    }

    public static function guard(array $state): void {
        CetechQuotePlacementHttpFixture::guard($state);
        if (true !== ($state['p05']['active'] ?? false) || ($state['site_id'] ?? null) !== get_current_blog_id() || !is_int($state['p05']['admin']['id'] ?? null)
            || !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON || get_option('home') !== 'http://127.0.0.1:8085' || get_option('siteurl') !== 'http://127.0.0.1:8085') { throw new RuntimeException('Guide requires the exact active native fixture.'); }
    }

    private static function rows(string $sql): array { return CetechPromiseHandoffHttpFixture::rows($sql); }
    private static function option(string $name): ?array { return CetechPromiseHandoffHttpFixture::option($name); }
    private static function digest(mixed $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
    private static function off(array $state): bool { return false === CetechPromiseHandoffHttpFixture::activation($state)->status()['enabled']; }

    private static function all_sources(): array {
        $out = [];
        foreach (['promise_objects', 'promise_versions', 'promise_assignments', 'operation_records', 'operation_changes'] as $suffix) { $out[$suffix] = self::rows('SELECT * FROM `' . TableNames::for($suffix) . '` ORDER BY id'); }
        return $out;
    }

    private static function order_history(): array {
        global $wpdb;
        $out = [];
        foreach (['wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'woocommerce_order_items', 'woocommerce_order_itemmeta'] as $suffix) { $out[$suffix] = self::rows('SELECT * FROM `' . $wpdb->prefix . $suffix . '` ORDER BY 1'); }
        $out['order_posts'] = self::rows("SELECT * FROM `{$wpdb->posts}` WHERE post_type IN ('shop_order','shop_order_refund') ORDER BY ID");
        $out['order_postmeta'] = self::rows("SELECT m.* FROM `{$wpdb->postmeta}` m INNER JOIN `{$wpdb->posts}` p ON p.ID=m.post_id WHERE p.post_type IN ('shop_order','shop_order_refund') ORDER BY m.meta_id");
        foreach (['shipments', 'shipment_items', 'shipment_events', 'shipment_promises'] as $suffix) { $out[$suffix] = self::rows('SELECT * FROM `' . TableNames::for($suffix) . '` ORDER BY id'); }
        return $out;
    }

    public static function prepare(array &$state): array {
        global $wpdb;
        self::guard($state);
        if (isset($state['p05_guide']) || !self::off($state) || !array_key_exists('adoption_before', $state['p04']) || false !== PromiseQuotePlacementActivation::decode($state['p04']['adoption_before']['option_value'] ?? null)['enabled'] || !in_array(['delivery_offers', $state['native']['offer']], $state['native']['entities'], true)) { throw new RuntimeException('Guide requires an unused owned OFF allocation and an OFF restoration target.'); }
        $offers = self::rows($wpdb->prepare('SELECT * FROM `' . TableNames::for('delivery_offers') . '` WHERE id=%d LIMIT 2', $state['native']['offer']));
        $offer = $offers[0] ?? [];
        $product = $state['native']['products'][0] ?? null;
        $actor = get_userdata($state['p05']['admin']['id']);
        if (count($offers) !== 1 || ($offer['internal_code'] ?? null) !== 'q04_offer_' . $state['native']['suffix'] || ($offer['route'] ?? null) !== 'local_delivery' || ($offer['status'] ?? null) !== 'active'
            || !is_int($product) || $product < 1 || !(wc_get_product($product) instanceof WC_Product) || !$actor || $actor->user_login !== $state['p05']['admin']['login'] || !str_starts_with($actor->user_login, 'p05_admin_')) { throw new RuntimeException('Guide source ownership differs.'); }
        $suffix = $offer['internal_code'] . '-product-' . $product;
        $policy = 'rule-' . $suffix; $calendar = 'hours-' . $suffix;
        $journal = 'cetech_de_staff_intent_' . hash('sha256', $state['site_id'] . ':store:' . $state['p05']['admin']['id']);
        $index = self::option(self::INDEX); $decoded = null === $index ? [] : unserialize($index['option_value'], ['allowed_classes' => false]);
        if (!is_array($decoded) || isset($decoded[$policy]) || null !== self::option($journal)) { throw new RuntimeException('Guide refuses an existing private rule/journal.'); }
        $key = ['scope_kind' => 'product', 'scope_id' => $product, 'service_kind' => 'merchant', 'service_code' => $offer['internal_code'], 'endpoint' => 'customer-door', 'endpoint_kind' => 'doorstep'];
        foreach (['promise_objects', 'promise_versions'] as $table) {
            if (self::rows($wpdb->prepare('SELECT * FROM `' . TableNames::for($table) . '` WHERE site_id=%d AND site_key=%s AND logical_id IN (%s,%s) ORDER BY id', $state['site_id'], 'store', $policy, $calendar)) !== []) { throw new RuntimeException('Guide refuses an existing store document.'); }
        }
        $assignment = self::rows($wpdb->prepare('SELECT * FROM `' . TableNames::for('promise_assignments') . '` WHERE site_id=%d AND site_key=%s AND scope_kind=%s AND scope_id=%d AND service_kind=%s AND service_code=%s AND endpoint=%s AND endpoint_kind=%s ORDER BY id', $state['site_id'], 'store', ...array_values($key)));
        if ($assignment !== []) { throw new RuntimeException('Guide refuses an existing assignment.'); }
        $fields = ['offer_code' => $offer['internal_code'], 'scope' => 'product', 'product_id' => (string)$product, 'variation_id' => '', 'estimate_required' => 'no', 'processing_min' => '1', 'processing_max' => '2', 'delivery_min' => '1', 'delivery_max' => '2', 'unit' => 'business_days', 'timezone' => 'Africa/Accra', 'closures' => '', 'cutoff' => '', 'cutoff_miss' => 'next_opening'];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) { $fields[$day . '_open'] = '09:00'; $fields[$day . '_close'] = '17:00'; }
        $state['p05_guide'] = ['active' => true, 'policy_id' => $policy, 'calendar_id' => $calendar, 'scope' => ['kind' => 'product', 'target_id' => $product], 'assignment_key' => $key, 'journal' => $journal, 'index_before' => $index, 'journal_before' => null, 'sources_before' => self::all_sources(), 'orders_before' => self::order_history(), 'adoption_before' => self::option(PromiseQuotePlacementActivation::OPTION), 'fields' => $fields, 'namespaces' => [], 'uuids' => [], 'registered' => ['promise_objects' => [], 'promise_versions' => [], 'promise_assignments' => []], 'journal_bytes' => [], 'index_rows' => []];
        cetech_q05_write($state);
        return ['ready' => true, 'identity' => $state['identity'], 'fields' => $fields, 'policy_id' => $policy, 'calendar_id' => $calendar, 'adoption_off' => true, 'targets_absent' => true];
    }

    /** Every token/UUID is registered before its original native command executes. */
    public static function track(array &$state, array $step): void {
        $guide = &$state['p05_guide'];
        $operation = $step['operation'] ?? null; $token = $step['token'] ?? null; $payload = $step['payload'] ?? null;
        if (true !== ($guide['active'] ?? false) || !is_string($token) || !RequestContext::is_valid_identifier($token) || !is_array($payload)) { throw new RuntimeException('Guide command tracking differs.'); }
        $binding = PromiseSiteBinding::bind($state['site_id'], 'store');
        if ($operation === PromiseAssignmentCommand::OPERATION) {
            if (($payload['key'] ?? null) !== $guide['assignment_key']) { throw new RuntimeException('Guide assignment ownership differs.'); }
            $target = PromiseAssignmentCommand::target_key($binding, $payload['key']);
        } else {
            $kind = $payload['kind'] ?? null;
            if (!in_array($operation, ['promise.version.create', 'promise.version.retire', 'promise.version.seal', 'promise.version.publish'], true) || !in_array($kind, ['calendar', 'policy'], true)
                || ($payload['logical_id'] ?? null) !== $guide[$kind . '_id'] || ($payload['scope'] ?? null) !== ('calendar' === $kind ? ['kind' => 'global', 'target_id' => 0] : $guide['scope'])
                || !is_string($payload['version_uuid'] ?? null) || !RequestContext::is_valid_identifier($payload['version_uuid'])) { throw new RuntimeException('Guide version ownership differs.'); }
            $target = PromiseVersionCommand::target_key($binding, $kind, $payload['logical_id']);
            $guide['uuids'][$payload['version_uuid']] = ['kind' => $kind, 'logical_id' => $payload['logical_id'], 'domain_version' => $payload['domain_version']];
        }
        $identity = new OperationIdentity($state['site_id'], NativePromiseConfigurationAuthority::AUTHORITY, 'user:' . $state['p05']['admin']['id'], $operation, 1, $target, $token);
        $namespace = $identity->namespace_digest();
        $record = ['operation' => $operation, 'target_hash' => hash('sha256', 'cetech-operation-target-v1:' . $target)];
        if (isset($guide['namespaces'][$namespace]) && $guide['namespaces'][$namespace] !== $record) { throw new RuntimeException('Guide namespace changed.'); }
        if (!isset($guide['namespaces'][$namespace])) {
            global $wpdb;
            if (self::rows($wpdb->prepare('SELECT * FROM `' . TableNames::for('operation_records') . '` WHERE site_id=%d AND namespace_hash=%s LIMIT 2', $state['site_id'], $namespace)) !== []) { throw new RuntimeException('Guide refuses a preexisting operation namespace.'); }
        }
        if ($operation === PromiseAssignmentCommand::OPERATION) { PromiseAssignmentCommand::from_array($identity, $binding, $payload + ['author_user_id' => $state['p05']['admin']['id']]); }
        else { PromiseVersionCommand::from_array($identity, $binding, $payload + ['author_user_id' => $state['p05']['admin']['id']]); }
        $guide['namespaces'][$namespace] = $record;
    }

    private static function sources(array &$state): array {
        global $wpdb;
        $guide = &$state['p05_guide']; $out = [];
        foreach (['promise_objects', 'promise_versions'] as $suffix) {
            $out[$suffix] = self::rows($wpdb->prepare('SELECT * FROM `' . TableNames::for($suffix) . '` WHERE site_id=%d AND site_key=%s AND logical_id IN (%s,%s) ORDER BY id', $state['site_id'], 'store', $guide['policy_id'], $guide['calendar_id']));
            foreach ($out[$suffix] as $row) {
                if (!in_array($row['kind'], ['calendar', 'policy'], true) || $row['logical_id'] !== $guide[$row['kind'] . '_id']) { throw new RuntimeException('Guide source target differs.'); }
                if ($suffix === 'promise_versions' && (($guide['uuids'][$row['version_uuid']] ?? null) !== ['kind' => $row['kind'], 'logical_id' => $row['logical_id'], 'domain_version' => (int)$row['domain_version']] || (int)$row['author_user_id'] !== $state['p05']['admin']['id'])) { throw new RuntimeException('Guide version lacks original cleanup authority.'); }
                $guide['registered'][$suffix][(string)$row['id']] = $row;
            }
        }
        $key = $guide['assignment_key'];
        $out['promise_assignments'] = self::rows($wpdb->prepare('SELECT * FROM `' . TableNames::for('promise_assignments') . '` WHERE site_id=%d AND site_key=%s AND scope_kind=%s AND scope_id=%d AND service_kind=%s AND service_code=%s AND endpoint=%s AND endpoint_kind=%s ORDER BY id', $state['site_id'], 'store', ...array_values($key)));
        foreach ($out['promise_assignments'] as $row) { $guide['registered']['promise_assignments'][(string)$row['id']] = $row; }
        return $out;
    }

    public static function inspect(array &$state): array {
        self::guard($state); $sources = self::sources($state); $guide = $state['p05_guide'];
        $index = self::option(self::INDEX); $decoded = null === $index ? [] : unserialize($index['option_value'], ['allowed_classes' => false]);
        $out = ['ready' => true, 'adoption_off' => self::off($state), 'sources' => $sources, 'physical_hash' => self::digest(self::all_sources()), 'private_options_hash' => self::digest([$index, self::option($guide['journal'])]), 'index' => is_array($decoded) ? ($decoded[$guide['policy_id']] ?? null) : null, 'journal_absent' => self::option($guide['journal']) === null, 'orders_unchanged' => self::order_history() === $guide['orders_before']];
        cetech_q05_write($state); return $out;
    }

    private static function restore_option(string $name, ?array $before, array $guide): bool {
        global $wpdb;
        $now = self::option($name);
        if ($now === $before) { return true; }
        if (null === $now) { throw new RuntimeException('Guide option disappeared unexpectedly.'); }
        if ($name === self::INDEX) {
            $index = unserialize($now['option_value'], ['allowed_classes' => false]); $old = null === $before ? [] : unserialize($before['option_value'], ['allowed_classes' => false]);
            if (!is_array($index) || !is_array($old) || !isset($index[$guide['policy_id']]) || !in_array($index[$guide['policy_id']], $guide['index_rows'], true)) { throw new RuntimeException('Guide index cleanup lacks exact projection authority.'); }
            unset($index[$guide['policy_id']]); if ($index !== $old) { throw new RuntimeException('Guide refuses another rule index change.'); }
        } elseif (!isset($guide['journal_bytes'][hash('sha256', $now['option_value'])]) || $guide['journal_bytes'][hash('sha256', $now['option_value'])] !== $now['option_value']) { throw new RuntimeException('Guide journal cleanup lacks exact bytes.'); }
        if (null === $before) {
            $result = $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_id=%d AND BINARY option_name=%s AND BINARY option_value=%s", (int)$now['option_id'], $name, $now['option_value']));
        } else {
            if ($now['option_id'] !== $before['option_id']) { throw new RuntimeException('Guide index row identity changed.'); }
            $result = $wpdb->query($wpdb->prepare("UPDATE `{$wpdb->options}` SET option_value=%s,autoload=%s WHERE option_id=%d AND BINARY option_name=%s AND BINARY option_value=%s", $before['option_value'], $before['autoload'], (int)$now['option_id'], $name, $now['option_value']));
        }
        foreach ([$name, 'alloptions', 'notoptions'] as $key) { wp_cache_delete($key, 'options'); }
        return 1 === $result && self::option($name) === $before;
    }

    public static function cleanup(array &$state): array {
        global $wpdb;
        if (!isset($state['p05_guide'])) { return array_fill_keys(['exact_owned_sources_removed', 'exact_owned_operation_namespaces_removed', 'exact_private_index_restored', 'exact_pending_journal_restored', 'protected_history_unchanged', 'adoption_stays_off'], true); }
        if (true === ($state['p05_guide']['cleanup_done'] ?? false)) { return $state['p05_guide']['cleanup']; }
        self::guard($state); self::sources($state);
        $state['p05_guide']['active'] = false; cetech_q05_write($state); $guide = $state['p05_guide']; $ok = true;
        foreach (['promise_assignments', 'promise_versions', 'promise_objects'] as $suffix) {
            foreach ($guide['registered'][$suffix] as $id => $row) {
                $where = ['id' => (int)$id, 'site_id' => $state['site_id'], 'site_key' => 'store'];
                $current = self::rows($wpdb->prepare('SELECT * FROM `' . TableNames::for($suffix) . '` WHERE id=%d AND site_id=%d AND site_key=%s LIMIT 2', (int)$id, $state['site_id'], 'store'));
                if ([$row] !== $current) { throw new RuntimeException('Guide exact owned row changed during cleanup.'); }
                $ok = 1 === $wpdb->delete(TableNames::for($suffix), $where) && $ok;
            }
        }
        $operations = true;
        foreach ($guide['namespaces'] as $namespace => $expected) {
            $rows = self::rows($wpdb->prepare('SELECT * FROM `' . TableNames::for('operation_records') . '` WHERE site_id=%d AND namespace_hash=%s LIMIT 2', $state['site_id'], $namespace));
            if (count($rows) > 1) { throw new RuntimeException('Guide ambiguous owned namespace.'); }
            foreach ($rows as $row) {
                if ($row['operation'] !== $expected['operation'] || $row['target_hash'] !== $expected['target_hash']) { throw new RuntimeException('Guide operation target differs.'); }
                $operations = false !== $wpdb->delete(TableNames::for('operation_changes'), ['site_id' => $state['site_id'], 'operation_id' => (int)$row['id']]) && $operations;
                $operations = 1 === $wpdb->delete(TableNames::for('operation_records'), ['site_id' => $state['site_id'], 'id' => (int)$row['id'], 'namespace_hash' => $namespace]) && $operations;
            }
        }
        $index = self::restore_option(self::INDEX, $guide['index_before'], $guide);
        $journal = self::restore_option($guide['journal'], $guide['journal_before'], $guide);
        $state['p05_guide']['cleanup'] = ['exact_owned_sources_removed' => $ok, 'exact_owned_operation_namespaces_removed' => $operations, 'exact_private_index_restored' => $index, 'exact_pending_journal_restored' => $journal, 'protected_history_unchanged' => self::all_sources() === $guide['sources_before'] && self::order_history() === $guide['orders_before'], 'adoption_stays_off' => self::off($state) && self::option(PromiseQuotePlacementActivation::OPTION) === $guide['adoption_before']];
        $state['p05_guide']['cleanup_done'] = true; cetech_q05_write($state); return $state['p05_guide']['cleanup'];
    }
}

if (defined('WP_CLI') && WP_CLI && isset($args) && count($args) === 3) {
    $guide_args = $args; unset($args); require_once __DIR__ . '/opening-http-promise-native-configuration-support.php'; $args = $guide_args; unset($guide_args);
    require_once __DIR__ . '/opening-http-fixture-common.php';
    [$mode, $state_path, $output_path] = $args; $state = opening_http_read_state($state_path); CetechQuotePlacementHttpFixture::guard($state);
    if ($mode !== 'cleanupguide') { CetechPromiseStaffGuideHttpFixture::guard($state); }
    if ($mode === 'prepareguide') { $out = CetechPromiseStaffGuideHttpFixture::prepare($state); }
    elseif ($mode === 'inspectguide') { $out = CetechPromiseStaffGuideHttpFixture::inspect($state); }
    elseif ($mode === 'cleanupguide') { $out = CetechPromiseStaffGuideHttpFixture::cleanup($state); }
    elseif (str_starts_with($mode, 'trackguide:') && strlen($mode) <= 32768) {
        $bytes = base64_decode(substr($mode, 11), true); if (!is_string($bytes)) { throw new RuntimeException('Guide command encoding differs.'); }
        CetechPromiseStaffGuideHttpFixture::track($state, json_decode($bytes, true, 32, JSON_THROW_ON_ERROR)); cetech_q05_write($state); $out = ['tracked' => true];
    } else { throw new RuntimeException('Guide private bridge mode refused.'); }
    opening_http_write_json($output_path, $out);
}
