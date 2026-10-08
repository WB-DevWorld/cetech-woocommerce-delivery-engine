<?php
/** Private native setup/observations. Only real shopper routes perform quote placement. */
declare(strict_types=1);

require_once __DIR__ . '/opening-quote-placement-support.php';

use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Application\Order\QuoteNativeOrderHistory;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;

/** Requires the Q05 private file and exact marked disposable native site. */
final class CetechQuotePlacementHttpFixture {
    public const GATEWAY = 'cetech_q06_local_gateway';
    public const GATEWAY_ALT = 'cetech_q06_alternate_gateway';
    private static bool $registered = false;
    private static bool $gateway_registered = false;
    /** Shared native ownership policy; a late row does not confer cleanup authority. */
    public static function operational_cleanup_plan(array $before, array $owned_orders, array $rows): array {
        return CetechQuotePlacementOperationalCleanup::plan($before, $owned_orders, $rows);
    }
    public static function guard(array $state): void {
        if ('1' !== getenv('CETECH_DE_HTTP_OPENING_QUALIFICATION') || '1' !== getenv('CETECH_DE_NATIVE_OPENING_QUALIFICATION')
            || !defined('ABSPATH') || ($state['site_path'] ?? null) !== realpath(ABSPATH) || !defined('DB_HOST')
            || !preg_match('/\A127\.0\.0\.1(?::[0-9]+)?\z/D', DB_HOST) || !defined('DB_NAME')
            || !preg_match('/\Acetech_wp_opening_qualification_[a-z0-9]+\z/D', DB_NAME) || ($state['database_name'] ?? null) !== DB_NAME
            || '1' !== (string)get_option('cetech_opening_qualification_disposable') || !preg_match('/\A[a-f0-9]{48}\z/D', (string)($state['fixture_token'] ?? ''))) {
            throw new RuntimeException('Q06 private HTTP fixture refused its environment.');
        }
    }
    public static function active(array $state): bool { return true === ($state['q06']['active'] ?? false); }
    public static function principal(array $state): bool { return self::active($state) && (int)$state['user_id'] === get_current_user_id() && (int)$state['site_id'] === get_current_blog_id(); }
    public static function register(): void {
        if (self::$registered || !function_exists('cetech_q05_state')) { return; }
        $state = cetech_q05_state(); self::guard($state); self::$registered = true;
        self::register_gateway();
        $track = static function(mixed $order): void { if (!$order instanceof WC_Order || defined('WP_CLI') && WP_CLI) { return; } $state = cetech_q05_state(); if (self::principal($state)) { self::track($state, $order->get_id()); cetech_q05_write($state); } };
        add_action('woocommerce_checkout_order_created', $track, 900000);
        add_action('woocommerce_store_api_checkout_order_created', $track, 900000);
        add_action('woocommerce_payment_complete', static function(int $id): void {
            $state = cetech_q05_state(); if (!self::principal($state)) { return; } self::track($state, $id); $order = wc_get_order($id);
            ++$state['q06']['payment_complete_calls']; if ($order instanceof WC_Order && 0.0 === (float)$order->get_total()) { ++$state['q06']['free_completion_calls']; }
            $state['q06']['completion_before_seal'] = $state['q06']['completion_before_seal'] || !$order instanceof WC_Order || (QuoteNativeOrderHistory::owned($order) && !self::sealed($order)); cetech_q05_write($state);
        }, -900000);
        add_action('woocommerce_before_order_object_save', static function(mixed $order): void {
            if (!$order instanceof WC_Order || defined('WP_CLI') && WP_CLI) { return; }
            $state = cetech_q05_state();
            if (!self::principal($state) || 'snapshot' !== $state['q06']['barrier'] || $state['q06']['barrier_triggered'] || !QuoteNativeOrderHistory::owned($order)) { return; }
            self::track($state, $order->get_id()); $state['q06']['barrier_triggered'] = true; cetech_q05_write($state);
            // This is the real Woo save carrying the newly staged protected order packet.
            throw new RuntimeException('Q06 marked native snapshot save refused.');
        }, -900000, 1);
        add_action('shutdown', static function(): void {
            $factory = $GLOBALS['cetech_q06_http_factory'] ?? null;
            if (!$factory instanceof CetechQuotePlacementFactory) { return; }
            $state = cetech_q05_state(); if (!self::active($state)) { return; }
            $state['q06']['masked_binding_acks'] += $factory->masked_binding_acks;
            $state['q06']['verified_sql_clocks'] += $factory->verified_clocks;
            if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['woocommerce_pay']) && $state['q06']['foreign_order_id'] > 0 && (int)($_GET['order-pay'] ?? get_query_var('order-pay', 0)) === $state['q06']['foreign_order_id']) { $state['q06']['foreign_payment_private_reads'] += $factory->private_quote_reads; }
            if ($factory->masked_binding_acks > 0) { $state['q06']['uncertain_connection_retired'] = $state['q06']['uncertain_connection_retired'] && $factory->fault_retired(); }
            $state['q06']['owned_connections_retired'] = $state['q06']['owned_connections_retired'] && $factory->close_all() && $factory->all_retired(); cetech_q05_write($state);
        }, 18);
        // The hook is a controlled last-boundary stimulus, never a replacement final coordinator.
        foreach (['woocommerce_checkout_order_processed', 'woocommerce_store_api_checkout_order_processed'] as $hook) {
            add_action($hook, static function(): void { $state = cetech_q05_state(); if (!in_array($state['q06']['barrier'] ?? null, ['monetary', 'protected'], true)) { self::barrier(); } }, PHP_INT_MAX - 1);
            // Registered after real C07: this mutation must lose to its known seal receipt.
            add_action($hook, static function(): void { $state = cetech_q05_state(); if (in_array($state['q06']['barrier'] ?? null, ['monetary', 'protected'], true)) { self::barrier(); } }, PHP_INT_MAX);
        }
        add_action('woocommerce_before_pay_action', static function(mixed $order): void { $state = cetech_q05_state(); if (self::principal($state) && $order instanceof WC_Order && in_array($order->get_id(), $state['q06']['orders'], true)) { $GLOBALS['cetech_q06_native_pay_order'] = $order; } }, PHP_INT_MAX, 1);
        add_action('template_redirect', static function(): void {
            if (!isset($_GET['cetech_q06_fixture'])) { return; } $state = cetech_q05_state(); self::guard($state);
            $token = $_SERVER['HTTP_X_CETECH_Q06_FIXTURE'] ?? null;
            if (!self::principal($state) || !is_string($token) || !hash_equals($state['fixture_token'], $token)) { wp_send_json_error(['code' => 'fixture_forbidden'], 403); }
            $mode = $_GET['cetech_q06_fixture']; if (!is_string($mode) || !in_array($mode, self::MODES, true)) { wp_send_json_error(['code' => 'fixture_mode'], 400); }
            if ('inspect' !== $mode && ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || !is_string($_POST['nonce'] ?? null) || !wp_verify_nonce($_POST['nonce'], 'cetech_q06_fixture'))) { wp_send_json_error(['code' => 'fixture_nonce'], 403); }
            if (!WC()->cart || !WC()->session) { wc_load_cart(); } WC()->session->set_customer_session_cookie(true);
            self::command($mode, $state, $_POST); cetech_q05_write($state); wp_send_json_success(self::snapshot($state));
        }, -110);
    }
    public static function register_gateway(): void {
        if (self::$gateway_registered) { return; } self::$gateway_registered = true;
        add_filter('woocommerce_payment_gateways', static function(array $methods): array { $methods[] = CetechOpeningQuotePlacementGateway::class; $methods[] = CetechOpeningQuotePlacementAlternateGateway::class; return $methods; });
        // The disposable instrumented gateway still needs Woo's normal Blocks registration.
        add_action('woocommerce_blocks_payment_method_type_registration', static function($registry): void {
            if (!class_exists(Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class)) { throw new RuntimeException('Pinned native Blocks payment registry unavailable.'); }
            $registry->register(new class extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
                protected $name = CetechQuotePlacementHttpFixture::GATEWAY;
                public function initialize() {}
                public function is_active() { return CetechQuotePlacementHttpFixture::principal(cetech_q05_state()); }
                public function get_payment_method_script_handles() {
                    $handle = 'cetech-q06-native-qualification-payment';
                    if (!wp_script_is($handle, 'registered')) {
                        wp_register_script($handle, false, ['wc-blocks-registry', 'wp-element'], '1', true);
                        wp_add_inline_script($handle, "(function(){var e=window.wp.element.createElement;var content=e('span',null,'Disposable qualification payment');window.wc.wcBlocksRegistry.registerPaymentMethod({name:'cetech_q06_local_gateway',label:'Q06 local test payment',content:content,edit:content,canMakePayment:function(){return true;},ariaLabel:'Q06 local test payment',supports:{features:['products']}});}());");
                    }
                    return [$handle];
                }
                public function get_payment_method_data() { return ['title' => 'Q06 local test payment', 'supports' => ['products']]; }
            });
        });
    }
    public const MODES = ['inspect', 'seed', 'free', 'refill', 'resume_pending', 'resume_failed', 'hold', 'empty', 'expire', 'change', 'pause', 'resume', 'arm_late_pause', 'arm_late_expiry', 'arm_bind_ack', 'arm_seal_ack', 'arm_snapshot_fault', 'arm_postsave_money', 'arm_postsave_protected', 'arm_validate_billing', 'arm_validate_money', 'restore_mutation', 'foreign', 'legacy', 'release', 'clear_fault', 'historychange'];
    public static function track(array &$state, int $id): void {
        if ($id < 1 || count($state['q06']['orders']) > 150) { throw new RuntimeException('Q06 tracked native order bound exceeded.'); }
        if (!in_array($id, $state['q06']['orders'], true)) { $state['q06']['orders'][] = $id; } $state['q06']['last_order_id'] = $id;
    }
    public static function sealed(WC_Order $order): bool {
        global $wpdb; $rows = $wpdb->get_results($wpdb->prepare('SELECT state,snapshot_digest,context_digest FROM `' . TableNames::for('delivery_quote_bindings') . '` WHERE site_id=%d AND order_id=%d LIMIT 2', get_current_blog_id(), $order->get_id()), ARRAY_A);
        return is_array($rows) && '' === $wpdb->last_error && 1 === count($rows) && 'sealed' === $rows[0]['state'] && is_string($rows[0]['snapshot_digest']) && is_string($rows[0]['context_digest']) && QuoteNativeOrderHistory::verify($order);
    }
    public static function factory(wpdb $db): CetechQuotePlacementFactory {
        self::register_gateway(); $factory = new CetechQuotePlacementFactory($db); $state = cetech_q05_state(); self::guard($state);
        if (self::active($state)) {
            $GLOBALS['cetech_q06_http_factory'] = $factory;
            if ('bind_ack' === $state['q06']['fault']) { $factory->mask_next_binding_ack = true; }
            if (null !== $state['q06']['clock']) { $factory->clock = QuoteTime::parse($state['q06']['clock']); }
        }
        return $factory;
    }
    public static function barrier(): void {
        $state = cetech_q05_state(); if (!self::principal($state) || defined('WP_CLI') && WP_CLI) { return; }
        $mode = $state['q06']['barrier']; if (null === $mode || 'snapshot' === $mode || $state['q06']['barrier_triggered']) { return; }
        $state['q06']['barrier_triggered'] = true;
        if ('pause' === $mode) { self::control($state, 'checkout_suspended'); }
        elseif ('expiry' === $mode) {
            $factory = $GLOBALS['cetech_q06_http_factory'] ?? null; if (!$factory instanceof CetechQuotePlacementFactory) { throw new RuntimeException('Q06 final expiry requires the actual injected native factory.'); }
            $current = $GLOBALS['cetech_q05_sessions']->load((new CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeOwnerResolver())->current());
            $factory->clock = $current?->header()?->expires_at(); if (!$factory->clock instanceof QuoteTime) { throw new RuntimeException('Q06 final expiry has no accepted original quote.'); }
        } elseif ('seal_ack' === $mode) {
            $factory = $GLOBALS['cetech_q06_http_factory'] ?? null; if (!$factory instanceof CetechQuotePlacementFactory) { throw new RuntimeException('Q06 final ACK requires the actual injected native factory.'); } $factory->mask_next_binding_ack = true;
        } elseif (in_array($mode, ['monetary', 'protected'], true)) {
            $order = wc_get_order($state['q06']['last_order_id']); if (!$order instanceof WC_Order || !self::principal($state)) { throw new RuntimeException('Q06 marked saved mutation requires its exact owned order.'); }
            if (!self::sealed($order)) { throw new RuntimeException('Q06 post-admission mutation requires an acknowledged physical seal.'); } $state['q06']['acknowledged_before_mutation'] = true;
            $key = OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT;
            $state['q06']['mutation'] = ['order_id' => $order->get_id(), 'kind' => $mode, 'original' => 'monetary' === $mode ? $order->get_total('edit') : $order->get_meta($key, true)];
            if ('monetary' === $mode) { $order->set_total('999.99'); } else { $order->update_meta_data($key, 'Q06-MARKED-PROTECTED-MUTATION'); } $order->save();
        }
        cetech_q05_write($state);
    }
    public static function control(array &$state, string $desired): void {
        global $wpdb; $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s LIMIT 1", EmergencyControlStore::OPTION_NAME), ARRAY_A);
        $decoded = is_array($row) ? json_decode($row['option_value'], true, 32, JSON_THROW_ON_ERROR) : null; $revision = is_array($decoded) ? (int)($decoded['revision'] ?? 0) + 1 : 1;
        $bytes = EmergencyControlState::record_json(get_current_blog_id(), $desired, 990002, 'enabled' === $desired ? 'resume_verified' : 'incident_pause', $revision, time());
        if (false === update_option(EmergencyControlStore::OPTION_NAME, $bytes, false) && get_option(EmergencyControlStore::OPTION_NAME) !== $bytes) { throw new RuntimeException('Q06 fixture control stimulus refused.'); }
    }
    public static function command(string $mode, array &$state, array $fields = []): void {
        global $wpdb; self::guard($state);
        if (!self::active($state) || !in_array($mode, self::MODES, true)) { throw new RuntimeException('Q06 fixture command refused.'); }
        if (in_array($mode, ['seed', 'free'], true)) {
            $state['q06']['fault'] = null; $state['q06']['clock'] = null; $state['q06']['barrier'] = null; $state['q06']['barrier_triggered'] = false; $state['q06']['acknowledged_before_mutation'] = false; $state['q06']['unsaved_native_change_detected'] = false; $state['q06']['hold_gateway'] = false; self::control($state, 'enabled');
            foreach ($state['native']['products'] as $id) { $p = wc_get_product($id); if (!$p instanceof WC_Product) { throw new RuntimeException('Q06 fixture owned product missing.'); } $p->set_regular_price('free' === $mode ? '0.00' : '20.00'); $p->set_price('free' === $mode ? '0.00' : '20.00'); $p->set_stock_quantity(100); $p->save(); }
            if (false === $wpdb->update(TableNames::for('rate_cards'), ['base_amount' => 'free' === $mode ? '0.0000' : '7.0000'], ['id' => $state['native']['rate']])) { throw new RuntimeException('Q06 fixture owned native rate unavailable.'); }
            $native = CetechQuoteCartHttpFixture::hydrate_native($wpdb, $state['native']); WC()->cart->empty_cart(); WC()->session->set('order_awaiting_payment', 0); WC()->session->set('store_api_draft_order', 0);
            // Native checkout persists an ordinary customer before order creation.
            // Author the same owned fixture address before quote preparation so the
            // strict physical customer fence sees the identical native rows.
            $address = ['billing_country' => 'GH', 'billing_state' => 'AA', 'billing_postcode' => '00001', 'billing_first_name' => 'Synthetic', 'billing_last_name' => 'Shopper', 'billing_company' => '', 'billing_address_1' => 'PRIVATE-Q04-NATIVE-FIXTURE-ADDRESS', 'billing_address_2' => '', 'billing_city' => 'Accra', 'billing_email' => 'q06@example.invalid', 'billing_phone' => '0200000000', 'shipping_country' => 'GH', 'shipping_state' => 'AA', 'shipping_city' => 'Accra', 'shipping_postcode' => '00001', 'shipping_company' => '', 'shipping_address_1' => 'PRIVATE-Q04-NATIVE-FIXTURE-ADDRESS', 'shipping_address_2' => '', 'shipping_first_name' => 'Synthetic', 'shipping_last_name' => 'Shopper'];
            $persistent_customer = new WC_Customer($state['user_id']);
            if ($persistent_customer->get_id() !== $state['user_id']) { throw new RuntimeException('Q06 owned native customer unavailable.'); }
            foreach ([$persistent_customer, WC()->customer] as $customer) { foreach ($address as $property => $value) { $customer->{'set_' . $property}($value); } $customer->save(); }
            $native->cart(); WC()->cart->set_session(); WC()->session->save_data();
        } elseif ('refill' === $mode) { $native = CetechQuoteCartHttpFixture::hydrate_native($wpdb, $state['native']); $native->cart(); WC()->cart->set_session(); WC()->session->save_data();
        } elseif (in_array($mode, ['resume_pending', 'resume_failed', 'foreign'], true)) {
            if ('foreign' === $mode && null === $state['q06']['foreign_user']) { $login = 'q06_foreign_' . bin2hex(random_bytes(6)); $user = wp_create_user($login, bin2hex(random_bytes(24)), $login . '@example.invalid'); if (!is_int($user) || $user < 1 || $user === $state['user_id']) { throw new RuntimeException('Q06 exact foreign customer allocation refused.'); } (new WP_User($user))->set_role('customer'); $state['q06']['foreign_user'] = ['id' => $user, 'login' => $login]; cetech_q05_write($state); }
            if ('foreign' === $mode) { $order = wc_get_order($state['q06']['last_order_id']); if (!$order instanceof WC_Order || !in_array($order->get_id(), $state['q06']['orders'], true) || !$order->needs_payment() || !self::sealed($order)) { throw new RuntimeException('Q06 foreign authorization requires its existing unpaid sealed quote order.'); } $history = self::snapshot_hash($order); $order->set_customer_id($state['q06']['foreign_user']['id']); $order->save(); if ($history !== self::snapshot_hash($order) || !QuoteNativeOrderHistory::owned($order)) { throw new RuntimeException('Q06 foreign authority setup changed quote history.'); } $state['q06']['foreign_order_id'] = $order->get_id(); return; }
            $order = wc_create_order(['customer_id' => 'foreign' === $mode ? $state['q06']['foreign_user']['id'] : $state['user_id']]); if (!$order instanceof WC_Order) { throw new RuntimeException('Q06 native draft allocation failed.'); }
            self::track($state, $order->get_id()); $order->set_status('resume_failed' === $mode ? 'failed' : 'pending'); $order->set_cart_hash(WC()->cart->get_cart_hash()); $order->set_currency('GHS'); $order->set_total('67.70'); $item = new WC_Order_Item_Product(); $item->set_product_id($state['native']['products'][0]); $item->set_name('Q06 old native draft member'); $item->set_quantity(1); $item->set_subtotal('20.00'); $item->set_total('20.00'); $order->add_item($item); $order->save();
            $state['q06']['reuse_order_id'] = $order->get_id(); $state['q06']['reuse_old_item_ids'] = array_keys($order->get_items('line_item'));
            if ('foreign' !== $mode) { WC()->session->set('order_awaiting_payment', $order->get_id()); WC()->session->save_data(); } else { $state['q06']['foreign_order_id'] = $order->get_id(); }
        } elseif ('hold' === $mode || 'release' === $mode) { $state['q06']['hold_gateway'] = 'hold' === $mode; }
        elseif ('restore_mutation' === $mode) { $mutation = $state['q06']['mutation']; if (!is_array($mutation) || !in_array($mutation['order_id'], $state['q06']['orders'], true)) { throw new RuntimeException('Q06 refuses a foreign mutation restoration.'); } $order = wc_get_order($mutation['order_id']); if (!$order instanceof WC_Order) { throw new RuntimeException('Q06 marked native mutation owner unavailable.'); } if ('monetary' === $mutation['kind']) { $order->set_total($mutation['original']); } else { $order->update_meta_data(OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, $mutation['original']); } $order->save(); $state['q06']['mutation'] = null; }
        elseif ('clear_fault' === $mode) { $state['q06']['fault'] = null; $state['q06']['clock'] = null; $state['q06']['barrier'] = null; }
        elseif ('legacy' === $mode) {
            if (!function_exists('cetech_c07_private_state')) { throw new RuntimeException('Q06 legacy proof needs retained C07 fixture.'); } $prior = cetech_c07_private_state(); require_once $prior['support_file']; $fixture = new CetechOpeningEmergencyFixture($prior); $item = $fixture->item('unmanaged'); $order = wc_create_order(['customer_id' => $state['user_id']]); if (!$order instanceof WC_Order) { throw new RuntimeException('Q06 legacy native order unavailable.'); } $order->add_product($item['data'], 1); $order->set_status('pending'); $order->calculate_totals(); $order->save(); self::track($state, $order->get_id());
        }
        elseif ('empty' === $mode) { WC()->cart->empty_cart(); WC()->session->save_data(); }
        elseif ('pause' === $mode || 'resume' === $mode) { self::control($state, 'pause' === $mode ? 'checkout_suspended' : 'enabled'); }
        elseif ('change' === $mode) { if (false === $wpdb->update(TableNames::for('rate_cards'), ['base_amount' => '9.0000'], ['id' => $state['native']['rate']])) { throw new RuntimeException('Q06 owned source change refused.'); } }
        elseif ('expire' === $mode) { $envelope = $GLOBALS['cetech_q05_sessions']->load((new CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeOwnerResolver())->current()); $state['q06']['clock'] = $envelope?->header()?->expires_at()->sql(); if (null === $state['q06']['clock']) { throw new RuntimeException('Q06 clock stimulus needs an accepted quote.'); } }
        elseif ('arm_bind_ack' === $mode) { $state['q06']['fault'] = 'bind_ack'; }
        elseif (str_starts_with($mode, 'arm_')) { $state['q06']['barrier'] = match($mode) { 'arm_late_pause' => 'pause', 'arm_late_expiry' => 'expiry', 'arm_seal_ack' => 'seal_ack', 'arm_snapshot_fault' => 'snapshot', 'arm_postsave_money' => 'monetary', 'arm_postsave_protected' => 'protected', 'arm_validate_billing' => 'validate_billing', 'arm_validate_money' => 'validate_money' }; $state['q06']['barrier_triggered'] = false; }
        elseif ('historychange' === $mode) { $wpdb->delete(TableNames::for('rate_cards'), ['id' => $state['native']['rate']]); update_option('woocommerce_currency', 'USD', false); foreach ($state['native']['tax_rates'] as $id) { $wpdb->delete($wpdb->prefix . 'woocommerce_tax_rate_locations', ['tax_rate_id' => $id]); $wpdb->delete($wpdb->prefix . 'woocommerce_tax_rates', ['tax_rate_id' => $id]); } }
    }
    public static function snapshot_hash(WC_Order $order): string {
        global $wpdb; $hpos = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(); $meta = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta; $owner = $hpos ? 'order_id' : 'post_id';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM `{$meta}` WHERE `{$owner}`=%d AND meta_key LIKE %s ORDER BY meta_key,meta_value", $order->get_id(), $wpdb->esc_like('_cetech_de_') . '%'), ARRAY_A);
        $items = $wpdb->get_results($wpdb->prepare("SELECT i.order_item_id,i.order_item_type,m.meta_key,m.meta_value FROM `{$wpdb->prefix}woocommerce_order_items` i JOIN `{$wpdb->prefix}woocommerce_order_itemmeta` m ON m.order_item_id=i.order_item_id WHERE i.order_id=%d AND (LEFT(m.meta_key,11)='_cetech_de_' OR m.meta_key='cetech_de_group_id' OR m.meta_key IN ('_product_id','_variation_id','_qty','_line_total','_line_tax','_line_subtotal','_line_subtotal_tax','_line_tax_data','method_id','instance_id','cost','total_tax','taxes','rate_id','label','compound','tax_amount','shipping_tax_amount','rate_percent')) ORDER BY i.order_item_id,m.meta_key,m.meta_value", $order->get_id()), ARRAY_A);
        if (!is_array($rows) || !is_array($items) || '' !== $wpdb->last_error) { throw new RuntimeException('Q06 physical history unavailable.'); } return hash('sha256', json_encode([$rows, $items], JSON_THROW_ON_ERROR));
    }
    public static function inert_references_present(): bool {
        $owner = (new CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeOwnerResolver())->current();
        $envelope = $GLOBALS['cetech_q05_sessions']->load($owner); $header = $envelope?->header();
        if (null === $header || !$envelope->owner()->equals($owner) || 'confirmed' !== $envelope->phase()) { return false; }
        $chosen = WC()->session->get('chosen_shipping_methods', []); $packages = WC()->shipping()->get_packages();
        if (!is_array($chosen) || !is_array($packages) || [] === $packages || count($packages) > 200) { return false; }
        foreach ($packages as $index => $package) {
            $group = $package[CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity::PACKAGE_META_KEY]['group_id'] ?? null;
            $rate = $package['rates'][$chosen[$index] ?? ''] ?? null;
            if (!is_string($group) || !$rate instanceof WC_Shipping_Rate || WC_Shipping_Rate::class !== get_class($rate)) { return false; }
            $component = CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation::component_key($group); $expected = null;
            foreach ($envelope->rate_references() as $reference) { if ($reference->matches($header, $component, $envelope->generation())) { $expected = $reference->public_fields(); break; } }
            if (null === $expected) { return false; } $meta = $rate->get_meta_data();
            $runtime = CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteRateReferenceRuntime::class;
            if (($meta[$runtime::META_QUOTE_ID] ?? null) !== $expected['quote_id'] || ($meta[$runtime::META_COMPONENT_HANDLE] ?? null) !== $expected['component_handle'] || ($meta[$runtime::META_GENERATION] ?? null) !== $expected['generation']) { return false; }
            $keys = array_values(array_filter(array_keys($meta), static fn($key): bool => is_string($key) && (str_starts_with($key, '_cetech_de_quote_') || str_starts_with($key, 'cetech_de_quote_')))); sort($keys, SORT_STRING);
            $allowed = [$runtime::META_QUOTE_ID, $runtime::META_COMPONENT_HANDLE, $runtime::META_GENERATION]; sort($allowed, SORT_STRING); if ($keys !== $allowed) { return false; }
        }
        return true;
    }
    public static function shipment_supported(WC_Order $order): bool {
        if (!QuoteNativeOrderHistory::owned($order)) { return false; }
        $factory = new CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory(new CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader());
        $context = $factory->from_order($order);
        if (!$context->package_meta_present || null === $context->package || $context->package_unreadable || count($context->lines) !== count($order->get_items('line_item'))) { return false; }
        foreach ($context->lines as $line) { if (!$line->has_snapshot || $line->snapshot_unreadable) { return false; } }
        return true;
    }
    public static function snapshot(array $state): array {
        global $wpdb; self::guard($state); $orders = []; $sealed = 0; $prepared = 0; $paid = 0;
        $owner = (new CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeOwnerResolver())->current();
        $now = $wpdb->get_row('SELECT UTC_TIMESTAMP(6) AS utc', ARRAY_A); if (!is_array($now) || !is_string($now['utc'] ?? null)) { throw new RuntimeException('Q06 actual preparation window clock unavailable.'); }
        $time = QuoteTime::parse($now['utc']); $epoch = $time->epoch_microseconds(); $window = QuoteTime::from_epoch_microseconds(intdiv($epoch, 60000000) * 60000000);
        $attempts = $wpdb->get_var($wpdb->prepare('SELECT attempt_count FROM `' . TableNames::for('delivery_quote_budget_windows') . '` WHERE site_id=%d AND slot_kind=%s AND slot_key=%s AND window_start=%s LIMIT 1', get_current_blog_id(), 'session_minute', CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot::session_slot_key($owner), $window->sql()));
        if ('' !== $wpdb->last_error || null !== $attempts && (!is_numeric($attempts) || (int)$attempts < 0 || (int)$attempts > 20)) { throw new RuntimeException('Q06 actual preparation window counter unavailable.'); }
        foreach ($state['q06']['orders'] as $id) { $order = wc_get_order($id); if (!$order instanceof WC_Order) { continue; } $rows = $wpdb->get_results($wpdb->prepare('SELECT state,revision FROM `' . TableNames::for('delivery_quote_bindings') . '` WHERE site_id=%d AND order_id=%d LIMIT 2', $state['site_id'], $id), ARRAY_A); if (!is_array($rows) || '' !== $wpdb->last_error || count($rows) > 1) { throw new RuntimeException('Q06 private binding identity unavailable.'); } $binding = $rows[0] ?? null; $sealed += null !== $binding && 'sealed' === $binding['state'] ? 1 : 0; $prepared += null !== $binding && 'prepared' === $binding['state'] ? 1 : 0; $paid += $order->is_paid() ? 1 : 0;
            $orders[(string)$id] = ['id' => $id, 'status' => $order->get_status(), 'payment_method' => $order->get_payment_method(), 'paid' => $order->is_paid(), 'binding_state' => $binding['state'] ?? null, 'binding_revision' => isset($binding['revision']) ? (int)$binding['revision'] : 0, 'history_supported' => QuoteNativeOrderHistory::verify($order), 'shipment_reader_supported' => self::shipment_supported($order), 'snapshot_hash' => self::snapshot_hash($order), 'line_count' => count($order->get_items('line_item')), 'line_ids' => array_keys($order->get_items('line_item')), 'shipping_count' => count($order->get_items('shipping')), 'total' => $order->get_total(), 'order_pay_url' => $order->get_checkout_payment_url(), 'order_key' => $order->get_order_key()];
        }
        $facts = $GLOBALS['cetech_q05_review_runtime']->current_facts();
        return ['history_counts' => CetechQuoteCartHttpFixture::counts($wpdb), 'budget_attempts' => (int)$attempts, 'budget_window_remaining_ms' => (int)ceil((60000000 - $epoch % 60000000) / 1000), 'counts' => ['orders' => count($orders), 'paid' => $paid, 'sealed' => $sealed, 'prepared' => $prepared, 'gateway_calls' => $state['q06']['gateway_calls'], 'payment_complete_calls' => $state['q06']['payment_complete_calls'], 'free_completion_calls' => $state['q06']['free_completion_calls']], 'orders' => $orders, 'last_order_id' => $state['q06']['last_order_id'], 'reuse_order_id' => $state['q06']['reuse_order_id'], 'reuse_old_item_ids' => $state['q06']['reuse_old_item_ids'], 'foreign_order_id' => $state['q06']['foreign_order_id'], 'foreign_native_authorized' => $state['q06']['foreign_order_id'] > 0 && current_user_can('pay_for_order', $state['q06']['foreign_order_id']), 'foreign_payment_private_reads' => $state['q06']['foreign_payment_private_reads'], 'native_pay_nonce' => wp_create_nonce('woocommerce-pay'), 'gateway_before_seal' => $state['q06']['gateway_before_seal'], 'completion_before_seal' => $state['q06']['completion_before_seal'], 'barrier_triggered' => $state['q06']['barrier_triggered'], 'acknowledged_before_mutation' => $state['q06']['acknowledged_before_mutation'], 'gateway_validation_calls' => $state['q06']['gateway_validation_calls'], 'unsaved_native_change_detected' => $state['q06']['unsaved_native_change_detected'], 'inert_component_references_present' => self::inert_references_present(), 'masked_binding_acks' => $state['q06']['masked_binding_acks'], 'verified_sql_clocks' => $state['q06']['verified_sql_clocks'], 'uncertain_connection_retired' => $state['q06']['uncertain_connection_retired'], 'facts' => $facts, 'nonce' => wp_create_nonce('cetech_q06_fixture'), 'review_nonce' => wp_create_nonce(CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteReviewRuntime::NONCE_ACTION), 'store_nonce' => wp_create_nonce('wc_store_api'), 'chosen_methods' => WC()->session->get('chosen_shipping_methods', []), 'draft_pointer' => (int)WC()->session->get('store_api_draft_order', 0), 'hpos' => Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'source_identity' => array_intersect_key($state['identity'], array_flip(['source_head', 'candidate_head', 'source_tree', 'installed_php_sources_hash']))];
    }
}

if (!class_exists('CetechOpeningQuotePlacementGateway', false)) {
            class CetechOpeningQuotePlacementGateway extends WC_Payment_Gateway {
                public function __construct() { $this->id = CetechQuotePlacementHttpFixture::GATEWAY; $this->method_title = 'Q06 native qualification gateway'; $this->title = 'Q06 local test payment'; $this->enabled = 'yes'; $this->has_fields = false; $this->supports = ['products']; }
                public function is_available() { return CetechQuotePlacementHttpFixture::principal(cetech_q05_state()) && parent::is_available(); }
                public function validate_fields() {
                    $state = cetech_q05_state(); $mode = $state['q06']['barrier']; if (!CetechQuotePlacementHttpFixture::principal($state) || !in_array($mode, ['validate_billing', 'validate_money'], true)) { return true; }
                    $order = $GLOBALS['cetech_q06_native_pay_order'] ?? null; if (!$order instanceof WC_Order || !in_array($order->get_id(), $state['q06']['orders'], true) || !CetechQuotePlacementHttpFixture::sealed($order)) { throw new RuntimeException('Q06 actual native validation requires the acknowledged original order.'); }
                    ++$state['q06']['gateway_validation_calls']; $state['q06']['acknowledged_before_mutation'] = true; $state['q06']['barrier_triggered'] = true;
                    if ('validate_billing' === $mode) { $order->set_billing_city('PRIVATE-Q06-UNSAVED-BILLING'); $changed = 'PRIVATE-Q06-UNSAVED-BILLING' === ($order->get_changes()['billing']['city'] ?? null); }
                    else { $items = $order->get_items('line_item'); $item = reset($items); if (!$item instanceof WC_Order_Item_Product) { throw new RuntimeException('Q06 actual native validation has no owned line.'); } $item->set_total('999.99'); $changed = '999.99' === ($item->get_changes()['total'] ?? null); }
                    $state['q06']['unsaved_native_change_detected'] = $changed; cetech_q05_write($state); return true;
                }
                public function process_payment($order_id) {
                    $state = cetech_q05_state(); if (!CetechQuotePlacementHttpFixture::principal($state)) { throw new RuntimeException('Q06 local gateway refused a foreign principal.'); }
                    ++$state['q06']['gateway_calls']; CetechQuotePlacementHttpFixture::track($state, (int)$order_id);
                    $order = wc_get_order($order_id); $sealed = $order instanceof WC_Order && (!QuoteNativeOrderHistory::owned($order) || CetechQuotePlacementHttpFixture::sealed($order));
                    $state['q06']['gateway_before_seal'] = $state['q06']['gateway_before_seal'] || !$sealed; cetech_q05_write($state);
                    // Entry was counted first. This fixture cannot hide a production admission failure.
                    if (!$order instanceof WC_Order || !$sealed) { throw new RuntimeException('Q06 gateway observed missing acknowledged placement.'); }
                    if (true === $state['q06']['hold_gateway']) { return ['result' => 'success', 'redirect' => $order->get_checkout_payment_url()]; }
                    $order->payment_complete(); return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
                }
            }
        }
if (!class_exists('CetechOpeningQuotePlacementAlternateGateway', false)) {
    class CetechOpeningQuotePlacementAlternateGateway extends CetechOpeningQuotePlacementGateway {
        public function __construct() { parent::__construct(); $this->id = CetechQuotePlacementHttpFixture::GATEWAY_ALT; $this->title = 'Q06 alternate local test payment'; }
    }
}

/** Root's shared driver uses this file as a separate bridge over the live Q05 state. */
if (defined('WP_CLI') && WP_CLI && isset($args) && count($args) >= 3) {
    require_once __DIR__ . '/opening-http-fixture-common.php'; [$mode, $state_path, $output_path] = $args; $state = opening_http_read_state($state_path); CetechQuotePlacementHttpFixture::guard($state); global $wpdb;
    if ('prepareplacement' === $mode) {
        if (isset($state['q06'])) { throw new RuntimeException('Q06 refuses to replace its prior fixture.'); }
        $native = CetechQuoteCartHttpFixture::hydrate_native($wpdb, $state['native']);
        // Full native checkout activation consumes ECR, while the retained Q04
        // fixture authored legacy rules. Author only these exact owned products;
        // persist each allocated row's cleanup authority before the next write.
        $insert = new ReflectionMethod(CetechNativeQuoteProviderFixture::class, 'insert');
        $owned_insert = static function(string $suffix, array $row) use ($insert, $native, &$state, $state_path): int {
            $id = $insert->invoke($native, $suffix, $row);
            if (!is_int($id) || $id < 1) { throw new RuntimeException('Q06 owned ECR fixture allocation refused.'); }
            $state['native'] = CetechQuoteCartHttpFixture::export_native($native); opening_http_write_json($state_path, $state, true);
            return $id;
        };
        foreach ($state['native']['products'] as $product_id) {
            if (!wc_get_product($product_id) instanceof WC_Product || 0 !== (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . TableNames::for('configuration_scopes') . '` WHERE scope_type=%s AND scope_id=%d', 'product', $product_id)) || '' !== $wpdb->last_error) { throw new RuntimeException('Q06 refuses a missing or previously configured fixture product.'); }
            $scope = $owned_insert('configuration_scopes', ['scope_type' => 'product', 'scope_id' => $product_id, 'slice_key' => 'in_warehouse', 'status' => 'active', 'config_version' => 1, 'source' => 'native']);
            foreach (['fulfilment_availability' => ['string', 'in_warehouse'], 'fulfilment_choice' => ['string', 'delivery'], 'priority' => ['int', '1']] as $field => [$type, $value]) { $owned_insert('configuration_fields', ['scope_row_id' => $scope, 'field_key' => $field, 'mode' => 'override', 'value_type' => $type, 'value_text' => $value]); }
            $owned_insert('configuration_collections', ['scope_row_id' => $scope, 'field_key' => 'delivery_offer_ids', 'mode' => 'replace', 'members_json' => json_encode([$state['native']['offer']], JSON_THROW_ON_ERROR)]);
        }
        foreach ([...CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation::CHAIN, 'enable_blocks_adapter'] as $flag) { $native->set_option('cetech_de_' . $flag, '1'); }
        $native->set_option(CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementActivation::OPTION, CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson::encode(['format' => 1, 'profile' => CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementActivation::PROFILE, 'enabled' => true, 'revision' => 1]));
        $state['native'] = CetechQuoteCartHttpFixture::export_native($native);
        $state['q06'] = ['active' => true, 'orders' => [], 'last_order_id' => 0, 'reuse_order_id' => 0, 'reuse_old_item_ids' => [], 'foreign_order_id' => 0, 'foreign_user' => null, 'foreign_payment_private_reads' => 0, 'gateway_calls' => 0, 'payment_complete_calls' => 0, 'free_completion_calls' => 0, 'gateway_before_seal' => false, 'completion_before_seal' => false, 'hold_gateway' => false, 'fault' => null, 'clock' => null, 'barrier' => null, 'barrier_triggered' => false, 'acknowledged_before_mutation' => false, 'gateway_validation_calls' => 0, 'unsaved_native_change_detected' => false, 'mutation' => null, 'masked_binding_acks' => 0, 'verified_sql_clocks' => 0, 'uncertain_connection_retired' => true, 'owned_connections_retired' => true, 'operational_before' => [], 'cleanup_done' => false];
        foreach (['shipments', 'shipment_items', 'shipment_events', 'audit_log'] as $suffix) { $state['q06']['operational_before'][$suffix] = array_column(CetechNativeQuoteProviderFixture::rows(TableNames::for($suffix)), 'id'); }
        opening_http_write_json($state_path, $state, true); opening_http_write_json($output_path, ['identity' => $state['identity'], 'ready' => true]);
    } elseif ('cleanupplacement' === $mode) {
        if (true === ($state['q06']['cleanup_done'] ?? false)) { opening_http_write_json($output_path, $state['q06']['cleanup']); return; }
        $ok = true; $removed = true; $owned_orders = []; $operational_rows = [];
        foreach ($state['q06']['orders'] as $id) { $order = wc_get_order($id); if (!$order instanceof WC_Order) { continue; } $groups = []; foreach ($order->get_items('line_item') as $item) { $group = $item->get_meta('cetech_de_group_id', true); if (is_string($group) && '' !== $group && strlen($group) <= 191) { $groups[] = $group; } } $owned_orders[$id] = ['groups' => array_values(array_unique($groups)), 'items' => array_keys($order->get_items('line_item'))]; }
        foreach (array_keys($state['q06']['operational_before']) as $suffix) { $operational_rows[$suffix] = CetechNativeQuoteProviderFixture::rows(TableNames::for($suffix)); }
        $plan = CetechQuotePlacementHttpFixture::operational_cleanup_plan($state['q06']['operational_before'], $owned_orders, $operational_rows); $ok = $plan['ownership_complete'];
        foreach (array_reverse($state['q06']['orders']) as $id) { $order = wc_get_order($id); if ($order instanceof WC_Order) { $order->delete(true); } $removed = !wc_get_order($id) && $removed; }
        // Only bindings of exact tracked native orders confer cleanup authority.
        foreach ($state['q06']['orders'] as $id) { $bindings = $wpdb->get_results($wpdb->prepare('SELECT quote_uuid,bind_namespace_hash,seal_namespace_hash FROM `' . TableNames::for('delivery_quote_bindings') . '` WHERE site_id=%d AND order_id=%d LIMIT 2', $state['site_id'], $id), ARRAY_A); if (!is_array($bindings) || count($bindings) > 1 || '' !== $wpdb->last_error) { throw new RuntimeException('Q06 cleanup binding identity refused.'); }
            foreach ($bindings as $binding) { $qrow = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . TableNames::for('delivery_quotes') . '` WHERE site_id=%d AND quote_uuid=%s', $state['site_id'], $binding['quote_uuid']), ARRAY_A); $quote = is_array($qrow) ? CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow::from_row($qrow) : null; if (null === $quote || !isset($state['owners'][$quote->header()->owner()->digest()])) { throw new RuntimeException('Q06 cleanup refuses a foreign quote owner.'); }
                $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM `' . TableNames::for('delivery_quote_bindings') . '` WHERE site_id=%d AND order_id=%d', $state['site_id'], $id), ARRAY_A); $b = CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding::from_row($rows[0], $quote); $names = CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableCommand::binding_namespaces($quote->header()->owner(), $quote->header(), $b->row()['placement_uuid'], true);
                $names['verify'] = CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableCommand::verification_namespace($quote->header()->owner(), $quote->header(), $b->row()['placement_uuid']);
                foreach ($names as $purpose => $namespace) { $records = $wpdb->get_results($wpdb->prepare('SELECT id,operation FROM `' . TableNames::for('operation_records') . '` WHERE site_id=%d AND namespace_hash=%s LIMIT 2', $state['site_id'], $namespace), ARRAY_A); if (!is_array($records) || count($records) > 1 || '' !== $wpdb->last_error) { throw new RuntimeException('Q06 cleanup exact operation identity refused.'); } foreach ($records as $record) { if (!in_array($record['operation'], ['delivery_quote.bind', 'delivery_quote.verify_binding', 'delivery_quote.seal'], true)) { throw new RuntimeException('Q06 cleanup operation authority refused.'); } $ok = false !== $wpdb->delete(TableNames::for('operation_changes'), ['site_id' => $state['site_id'], 'operation_id' => (int)$record['id']]) && $ok; $ok = false !== $wpdb->delete(TableNames::for('operation_records'), ['site_id' => $state['site_id'], 'id' => (int)$record['id']]) && $ok; } }
                $ok = false !== $wpdb->delete(TableNames::for('delivery_quote_bindings'), ['site_id' => $state['site_id'], 'order_id' => $id]) && $ok;
            }
        }
        foreach ($plan['authorized'] as $suffix => $ids) { foreach ($ids as $id) { $state['native']['entities'][] = [$suffix, $id]; } }
        if (is_array($state['q06']['foreign_user'])) { $owned_user = $state['q06']['foreign_user']; $user = get_userdata($owned_user['id']); if ($user && ($user->user_login !== $owned_user['login'] || !preg_match('/\Aq06_foreign_[a-f0-9]{12}\z/D', $user->user_login) || $user->ID === $state['user_id'])) { throw new RuntimeException('Q06 refuses a foreign user cleanup identity.'); } if ($user) { if (!function_exists('wp_delete_user')) { require_once ABSPATH . 'wp-admin/includes/user.php'; } $ok = wp_delete_user($owned_user['id']) && $ok; } $ok = false === get_userdata($owned_user['id']) && $ok; }
        $state['q06']['active'] = false; $state['q06']['cleanup_done'] = true; $state['q06']['cleanup'] = ['cleanup_restored' => $ok && $removed, 'owned_native_orders_removed' => $removed, 'exact_owned_placement_namespaces_removed' => $ok, 'no_gateway_before_seal' => !$state['q06']['gateway_before_seal'], 'no_completion_before_seal' => !$state['q06']['completion_before_seal'], 'owned_connections_retired' => $state['q06']['owned_connections_retired'] && $state['q06']['uncertain_connection_retired']]; opening_http_write_json($state_path, $state, true); opening_http_write_json($output_path, $state['q06']['cleanup']);
    } else { throw new RuntimeException('Q06 private CLI bridge mode refused.'); }
}
