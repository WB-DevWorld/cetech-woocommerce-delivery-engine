<?php
declare(strict_types=1);

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutAdmissionService;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutQuoteValidator;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlCommand;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlProjection;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnership;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipClassifier;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipLatch;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlDiagnostics;
use CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyCheckoutHooks;
use CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlResponse;
use CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlRuntime;

/** Actual default-cache WordPress/Woo boundaries; no unit bootstrap or fake product source. */
return static function ( callable $check, ?array $history = null ): void {
	global $wpdb;
	if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || true !== WP_ADMIN || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST ) || ! preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME ) || ! $wpdb instanceof wpdb || is_multisite() || WP_Object_Cache::class !== get_class( $GLOBALS['wp_object_cache'] ) ) { throw new RuntimeException( 'C07 native proof requires the marked default-cache site.' ); }
	foreach ( get_included_files() as $file ) { if ( str_ends_with( str_replace( '\\', '/', $file ), '/tests/bootstrap.php' ) ) { throw new RuntimeException( 'Unit bootstrap is forbidden in native C07 proof.' ); } }
	require_once __DIR__ . '/opening-emergency-fixture-support.php';
	$old_user = get_current_user_id(); $fixture = new CetechOpeningEmergencyFixture(); $failure = null;
	try {
		$fixture->prepare(); wp_set_current_user( $fixture->state['user_id'] );
		$service = CetechOpeningEmergencyFixture::service(); $site = get_current_blog_id();
		if ( null !== $history ) {
			if ( ! is_callable( $history['physical'] ?? null ) || ! is_callable( $history['historical'] ?? null ) ) { throw new RuntimeException( 'Invalid C07 history probe.' ); }
			$bytes = $history['physical'](); $facts = $history['historical']();
			$paused = $fixture->transition( 'checkout_suspended', 'maintenance_pause' ); $enabled = $fixture->transition( 'enabled', 'resume_verified' );
			$check( 'NATIVE-C07-HPOS-V1V2-MALFORMED-HISTORICAL-PAUSE-RESUME-PRESERVED', 'accepted' === $paused->outcome->state && 'accepted' === $enabled->outcome->state && $bytes === $history['physical']() && $facts === $history['historical'](), [ 'actual_hpos_history' => true, 'protected_meta_rows_unchanged' => $bytes === $history['physical'](), 'historical_planner_unchanged' => $facts === $history['historical']() ] );
			return;
		}
		$container = Plugin::instance()->container(); $classifier = $container->get( EmergencyOwnershipClassifier::class ); $quote = $container->get( EmergencyCheckoutQuoteValidator::class );
		$fresh = static function () use ( $service, $classifier, $quote ): array { $latch = new EmergencyOwnershipLatch(); $admission = new EmergencyCheckoutAdmissionService( $service, $classifier, $quote, $latch ); return [ $admission, new EmergencyControlRuntime( $service, $classifier, $latch, $admission ), $latch ]; };
		$read = $service->read( $site );
		$check( 'NATIVE-C07-DEFAULT-CACHE-NATIVE-READY-READ', $read->available && null !== $read->state && $read->state->site_id === $site && WP_Object_Cache::class === get_class( $GLOBALS['wp_object_cache'] ), [ 'native_default_object_cache' => true, 'no_object_cache_dropin' => ! file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ] );
		$managed = $fixture->order(); $legacy = $fixture->order( legacy: true ); $missing = $fixture->order( missing: true ); $pickup = $fixture->order( 'pickup' ); $ordinary = $fixture->order( 'unmanaged' ); $paid = $fixture->order(); $paid->set_date_paid( time() ); $paid->set_status( 'processing' ); $paid->save();
		$check( 'NATIVE-C07-AUTHORITATIVE-MANAGED-UNMANAGED-PRODUCTS', EmergencyOwnership::Managed === $classifier->product( $fixture->state['managed_product_id'] ) && EmergencyOwnership::Managed === $classifier->product( $fixture->state['pickup_product_id'] ) && EmergencyOwnership::Unmanaged === $classifier->product( $fixture->state['unmanaged_product_id'] ) );
		$check( 'NATIVE-C07-NEW-LEGACY-MISSING-ORDER-OWNERSHIP', EmergencyOwnership::Managed === $classifier->order( $managed ) && EmergencyOwnership::Managed === $classifier->order( $legacy ) && EmergencyOwnership::Managed === $classifier->order( $missing ) && EmergencyOwnership::Unmanaged === $classifier->order( $ordinary ) );
		$before = CetechOpeningEmergencyFixture::order_bytes( $fixture->state['orders'] );
		$paused = $fixture->transition( 'checkout_suspended', 'incident_pause' );
		$check( 'NATIVE-C07-PAUSE-COMMITTED-AUTOLOAD-OFF', 'accepted' === $paused->outcome->state && 'checkout_suspended' === $service->read( $site )->state?->state && 'off' === CetechOpeningEmergencyFixture::option( $fixture::CONTROL )['autoload'] );
		[ $admission, $runtime ] = $fresh();
		$writer_before = $fixture::option( $fixture::CONTROL ); $writer_events = $fixture::rows( 'operation_changes' ); $writer_history = $fixture::order_bytes( $fixture->state['orders'] );
		ob_start(); try { ( new CetechDeliveryEngine\Presentation\Admin\EmergencyControlSettings( $service, $container->get( CetechDeliveryEngine\Presentation\Admin\AdminActionHandler::class ), false ) )->render(); $writer_html = ob_get_contents(); } finally { ob_end_clean(); }
		$check( 'NATIVE-C07-WRITER-DISABLED-RETAINS-PAUSE-AND-GUARDS', is_string( $writer_html ) && str_contains( $writer_html, 'New delivery and pickup checkouts are paused.' ) && str_contains( $writer_html, 'The recorded state is preserved.' ) && ! str_contains( $writer_html, '<form' ) && $writer_before === $fixture::option( $fixture::CONTROL ) && $writer_events === $fixture::rows( 'operation_changes' ) && $writer_history === $fixture::order_bytes( $fixture->state['orders'] ) && ! $runtime->product_allowed( $fixture->state['managed_product_id'] ) && current_user_can( 'manage_delivery_settings' ), [ 'actual_native_authorized_render' => true, 'server_constructor_writer_switch' => false, 'no_control_form_or_post' => true, 'live_guard_denies_managed' => true ] );
		$check( 'NATIVE-C07-MANAGED-DELIVERY-AND-PICKUP-EARLY-DENIED', ! $runtime->product_allowed( $fixture->state['managed_product_id'] ) && ! $runtime->product_allowed( $fixture->state['pickup_product_id'] ) && $runtime->product_allowed( $fixture->state['unmanaged_product_id'] ) );
		foreach ( [ 'new' => $managed, 'legacy' => $legacy, 'missing' => $missing, 'pickup' => $pickup ] as $name => $order ) { $decision = $fresh()[0]->final_order( $order, 'order_pay' ); $check( 'NATIVE-C07-UNPAID-' . strtoupper( $name ) . '-ORDER-PAY-PAUSED', ! $decision->allowed && 'checkout_suspended' === $decision->code && ! $order->is_paid(), [ 'actual_unpaid_order' => true, 'no_payment_invoked' => true ] ); }
		$check( 'NATIVE-C07-PAID-AND-UNMANAGED-ORDERS-CONTINUE', $fresh()[0]->final_order( $paid, 'order_pay' )->allowed && $fresh()[0]->final_order( $ordinary, 'order_pay' )->allowed );
		$check( 'NATIVE-C07-PAUSE-READS-DO-NOT-REWRITE-ORDER-HISTORY', $before === CetechOpeningEmergencyFixture::order_bytes( $fixture->state['orders'] ), [ 'physical_orders_items_meta_identical' => true ] );
		$rates = [ 'ordinary' => new WC_Shipping_Rate( 'flat_rate:1', 'C07 ordinary', 4, [], 'flat_rate', 1 ), 'engine' => new WC_Shipping_Rate( 'delivery_engine_selected_offer:1', 'C07 managed', 7, [], 'delivery_engine_selected_offer', 1 ) ];
		$hooks = new EmergencyCheckoutHooks( $runtime ); $managed_package = [ 'contents' => [ 'c07_managed' => $fixture->item() ] ]; $ordinary_package = [ 'contents' => [ 'c07_unmanaged' => $fixture->item( 'unmanaged' ) ] ];
		$check( 'NATIVE-C07-PAUSED-MANAGED-RATES-NO-NATIVE-FREE-FALLBACK', [] === $hooks->package_rates( $rates, $managed_package ) && $rates === $hooks->package_rates( $rates, $ordinary_package ) );
		$flags = [ 'enable_classic_checkout_adapter', 'enable_woocommerce_shipping_rate_calculation', 'enable_order_delivery_snapshot_persistence', 'enable_effective_configuration_runtime', 'enable_variable_product_ecr_runtime' ]; foreach ( $flags as $name ) { update_option( 'cetech_de_' . $name, 0, false ); }
		$check( 'NATIVE-C07-INACTIVE-RUNTIME-FLAGS-DO-NOT-BYPASS-PAUSE', EmergencyOwnership::Managed === $classifier->product( $fixture->state['managed_product_id'] ) && ! $fresh()[1]->product_allowed( $fixture->state['managed_product_id'] ) && [] === $hooks->package_rates( $rates, $managed_package ) );
		foreach ( [ 'enable_classic_checkout_adapter', 'enable_woocommerce_shipping_rate_calculation', 'enable_order_delivery_snapshot_persistence' ] as $name ) { update_option( 'cetech_de_' . $name, 1, false ); }
		$stopped = false; $redirected = null; $notices = [];
		$response = new EmergencyControlResponse( static function ( string $message ) use ( &$notices ): void { $notices[] = $message; }, static fn (): string => wc_get_checkout_url(), static function ( string $url, int $status ) use ( &$redirected ): bool { $redirected = [ $url, $status ]; return true; }, static function ( bool $redirect, string $message ) use ( &$stopped ): never { $stopped = $redirect; throw new LogicException( 'C07 trusted fixture terminal.' ); } );
		try { ( new EmergencyCheckoutHooks( $fresh()[1], $response ) )->before_pay( $managed ); } catch ( LogicException $error ) { if ( 'C07 trusted fixture terminal.' !== $error->getMessage() ) { throw $error; } }
		$check( 'NATIVE-C07-ORDER-PAY-SAFE-REDIRECT-AND-TERMINATION', $stopped && [ wc_get_checkout_url(), 303 ] === $redirected && 1 === count( $notices ) && str_contains( $notices[0], 'temporarily paused' ) && str_contains( $notices[0], 'Reference:' ), [ 'server_checkout_url_only' => true, 'trusted_fixture_stopped_before_gateway' => true ] );
		$shopper = EmergencyControlProjection::for_shopper( $admission->final_order( $managed, 'classic' ), RequestContext::create() );
		$check( 'NATIVE-C07-SHOPPER-PROJECTION-EXACT-PRIVATE-EXCLUSION', [ 'contract_version', 'decision_kind', 'status', 'message_key', 'message', 'recovery_action', 'correlation_id' ] === array_keys( $shopper ) && 'cetech.checkout.paused' === $shopper['message_key'] && ! str_contains( json_encode( $shopper ), 'PRIVATE-C07' ) );
		$loads = 0; $denied = EmergencyControlProjection::for_diagnostics( $site, RequestContext::create(), static fn (): bool => false, static function () use ( &$loads ): void { ++$loads; } );
		$check( 'NATIVE-C07-DIAGNOSTICS-DENIES-BEFORE-LOADER', $denied instanceof ContractError && 'not_authorized' === $denied->code && 0 === $loads );
		$checks = 0; $revoked = EmergencyControlProjection::for_settings( $site, RequestContext::create(), static function () use ( &$checks ): bool { return 1 === ++$checks; }, static fn () => $service->read( $site ) );
		$check( 'NATIVE-C07-ADMIN-DISCLOSURE-RECHECKS-CURRENT-AUTHORITY', $revoked instanceof ContractError && 'not_authorized' === $revoked->code && 2 === $checks );
		$diagnostics = EmergencyControlProjection::for_diagnostics( $site, RequestContext::create(), static fn (): bool => current_user_can( 'view_delivery_diagnostics' ), static fn () => new EmergencyControlDiagnostics( $site, $service->read( $site ), 'unknown', observed_lines: 200, observed_packages: 200, observed_complete: false ) );
		$check( 'NATIVE-C07-IMPACT-IS-BOUNDED-INCOMPLETE-ESTIMATE', is_array( $diagnostics ) && 'bounded_observed_estimate' === $diagnostics['observed_impact']['kind'] && false === $diagnostics['observed_impact']['complete'] && 200 === $diagnostics['observed_impact']['limit'] && 'checkout_suspended' === $diagnostics['effective_state'] && 'unknown' === $diagnostics['module_readiness'] );
		$cached = get_option( $fixture::CONTROL ); wp_cache_set( $fixture::CONTROL, 'PRIVATE-STALE-CONTROL-CACHE', 'options' ); $autoload = wp_load_alloptions(); $autoload[$fixture::CONTROL] = 'PRIVATE-STALE-ALL-OPTIONS'; wp_cache_set( 'alloptions', $autoload, 'options' );
		$check( 'NATIVE-C07-CURRENT-NATIVE-READ-IGNORES-POISONED-OPTION-CACHES', 'checkout_suspended' === $service->read( $site )->state?->state && 'PRIVATE-STALE-ALL-OPTIONS' === get_option( $fixture::CONTROL ) );
		$enabled = $fixture->transition( 'enabled', 'resume_verified' );
		$check( 'NATIVE-C07-RESUME-INVALIDATES-NATIVE-OPTION-AND-ALLOPTIONS', 'accepted' === $enabled->outcome->state && CetechOpeningEmergencyFixture::option( $fixture::CONTROL )['option_value'] === get_option( $fixture::CONTROL ) && 'enabled' === $service->read( $site )->state?->state );
		$wpdb->update( $wpdb->options, [ 'autoload' => 'on' ], [ 'option_name' => $fixture::CONTROL ] ); $fixture::invalidate( $fixture::CONTROL ); wp_load_alloptions(); $on = $fixture->transition( 'checkout_suspended', 'operator_pause' );
		$check( 'NATIVE-C07-AUTOLOAD-ON-UPDATE-RETAINS-PARITY', 'accepted' === $on->outcome->state && 'on' === CetechOpeningEmergencyFixture::option( $fixture::CONTROL )['autoload'] && CetechOpeningEmergencyFixture::option( $fixture::CONTROL )['option_value'] === get_option( $fixture::CONTROL ) );
		$opened = $service->read( $site )->state; $token = 'c07_native_replay_' . bin2hex( random_bytes( 5 ) ); $identity = EmergencyControlCommand::identity( $site, get_current_user_id(), $token ); $fixture->state['namespaces'][] = $identity->namespace_digest(); $payload = EmergencyControlCommand::payload( $opened, 'enabled', 'resume_verified' ); $accepted = $service->transition( $identity, $payload, RequestContext::create() ); $count = count( $fixture::rows( 'operation_changes' ) ); $control_bytes = $fixture::option( $fixture::CONTROL ); $replay = $service->transition( $identity, $payload, RequestContext::create() );
		$check( 'NATIVE-C07-ORIGINAL-TOKEN-REPLAY-NO-SECOND-AUDIT', 'accepted' === $accepted->outcome->state && 'accepted' === $replay->outcome->state && $replay->replayed && $count === count( $fixture::rows( 'operation_changes' ) ) && $control_bytes === $fixture::option( $fixture::CONTROL ) );
		$no_change = $fixture->transition( 'enabled', 'resume_verified' ); $check( 'NATIVE-C07-SAME-STATE-NO-OPTION-WRITE-NO-MATERIAL-AUDIT', 'not_applicable' === $no_change->outcome->state && $count === count( $fixture::rows( 'operation_changes' ) ) && $control_bytes === $fixture::option( $fixture::CONTROL ) );
		$quote_bytes = $fixture::order_bytes( [ $managed->get_id(), $pickup->get_id() ] );
		$quote->fingerprint( $managed ); $native_line = array_values( $managed->get_items( 'line_item' ) )[0]; $native_meta = $native_line->get_meta_data(); $native_shipping = $managed->get_items( 'shipping' ); $queries_before_local = $wpdb->num_queries; $local = CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding::capture( $managed ); $local_unchanged = null !== $local && $local->unchanged(); $native_line->set_quantity( 2 ); $local_changed = null !== $local && ! $local->unchanged(); $native_line->set_quantity( 1 ); $local_queries = $wpdb->num_queries - $queries_before_local;
		$check( 'NATIVE-C07-LOCAL-BINDING-NATIVE-DATES-META-AND-ITEM-MUTATION', $managed->get_date_created() instanceof WC_DateTime && [] !== $native_meta && [] === array_filter( $native_meta, static fn ( mixed $meta ): bool => ! $meta instanceof WC_Meta_Data ) && $local_unchanged && $local_changed && 0 === $local_queries && $quote_bytes === $fixture::order_bytes( [ $managed->get_id(), $pickup->get_id() ] ), [ 'actual_native_woo_dates_and_metadata' => $managed->get_date_created() instanceof WC_DateTime && [] !== $native_meta, 'prewarmed_request_facts' => true, 'native_order_class' => WC_Order::class === get_class( $managed ), 'native_line_class' => WC_Order_Item_Product::class === get_class( $native_line ), 'shipping_items_loaded' => count( $native_shipping ), 'metadata_items_loaded' => count( $native_meta ), 'local_capture_available' => null !== $local, 'local_unchanged' => $local_unchanged, 'in_memory_quantity_change_detected' => $local_changed, 'local_query_count' => $local_queries, 'no_order_save_or_database_write' => 0 === $local_queries ] );
		$check( 'NATIVE-C07-RESUME-REVALIDATES-FROZEN-DELIVERY-AND-PICKUP', $fresh()[0]->final_order( $managed, 'order_pay' )->allowed && $fresh()[0]->final_order( $pickup, 'order_pay' )->allowed && $quote_bytes === $fixture::order_bytes( [ $managed->get_id(), $pickup->get_id() ] ) );
		$wpdb->update( CetechDeliveryEngine\Infrastructure\Persistence\TableNames::for( 'rate_cards' ), [ 'base_amount' => '9.0000' ], [ 'id' => $fixture->state['rate_id'] ] ); $mismatch = $fresh()[0]->final_order( $managed, 'order_pay' );
		$check( 'NATIVE-C07-RESUME-PRICE-MISMATCH-DENIED-WITHOUT-REPRICING', ! $mismatch->allowed && 'checkout_revalidation_required' === $mismatch->code && $quote_bytes === $fixture::order_bytes( [ $managed->get_id(), $pickup->get_id() ] ) );
		$wpdb->update( CetechDeliveryEngine\Infrastructure\Persistence\TableNames::for( 'rate_cards' ), [ 'base_amount' => '7.0000' ], [ 'id' => $fixture->state['rate_id'] ] );
		$check( 'NATIVE-C07-MISSING-FACTS-DENIED-WITHOUT-ACCEPTANCE-INFERENCE', ! $fresh()[0]->final_order( $missing, 'order_pay' )->allowed && $before === $fixture::order_bytes( $fixture->state['orders'] ) );
		$valid = $fixture::option( $fixture::CONTROL ); $wpdb->update( $wpdb->options, [ 'option_value' => '{"format_version":1,"state":"enabled"}' ], [ 'option_name' => $fixture::CONTROL ] ); $fixture::invalidate( $fixture::CONTROL ); $bad = $service->read( $site );
		$check( 'NATIVE-C07-MALFORMED-CONTROL-FAILS-CLOSED-UNMANAGED-CONTINUES', ! $bad->available && ! $fresh()[1]->product_allowed( $fixture->state['managed_product_id'] ) && $fresh()[1]->product_allowed( $fixture->state['unmanaged_product_id'] ) ); $wpdb->replace( $wpdb->options, $valid ); $fixture::invalidate( $fixture::CONTROL );
		$over_limit = []; for ( $i = 0; $i < 201; ++$i ) { $over_limit[ 'c07_limit_' . $i ] = $fixture->item(); } $changes_before = count( $fixture::rows( 'operation_changes' ) );
		$check( 'NATIVE-C07-201-LINE-LIMIT-REFUSES-WITHOUT-PARTIAL-ADMISSION', ! $fresh()[1]->lines_allowed( $over_limit ) && $changes_before === count( $fixture::rows( 'operation_changes' ) ), [ 'observed_lines' => 201, 'supported_limit' => 200 ] );
		$in_flight = $fixture->order(); $in_flight_admission = $fresh()[0]->final_order( $in_flight, 'classic' );
		$package_epoch_before = $fresh()[1]->decorate_packages( [ $managed_package ] ); $fixture->transition( 'checkout_suspended', 'operator_pause' ); $package_epoch_after = $fresh()[1]->decorate_packages( [ $managed_package ] );
		$check( 'NATIVE-C07-PACKAGE-CACHE-EPOCH-CHANGES-AFTER-PAUSE', $package_epoch_before !== $package_epoch_after && ! str_contains( json_encode( $package_epoch_after ), 'actor_user_id' ) );
		update_option( 'cetech_de_enable_shipment_records', 1, false );
		$in_flight->payment_complete( 'c07-private-fixture-transaction' ); $reloaded = wc_get_order( $in_flight->get_id() );
		$check( 'NATIVE-C07-ALREADY-ADMITTED-PAYMENT-CALLBACK-COMPLETES-AFTER-PAUSE', $in_flight_admission->allowed && $reloaded instanceof WC_Order && null !== $reloaded->get_date_paid() && 'checkout_suspended' === $service->read( $site )->state?->state, [ 'admission_confirmed_before_pause' => true, 'actual_woo_payment_complete_after_pause' => true ] );
		$creation = $container->get( CetechDeliveryEngine\Application\Shipment\ShipmentService::class )->create_for_paid_order( $paid );
		$check( 'NATIVE-C07-PAID-HISTORICAL-SHIPMENT-CREATION-CONTINUES', $creation->is_success() && 1 === count( $creation->shipments ) && 'checkout_suspended' === $service->read( $site )->state?->state && current_user_can( 'manage_shipments' ), [ 'actual_native_repository' => true, 'existing_staff_authority' => true ] );
		$shipment = $creation->shipments[0]; $tracking = $container->get( CetechDeliveryEngine\Application\Shipment\ShipmentTrackingService::class )->save( $shipment->id, new CetechDeliveryEngine\Application\Shipment\ShipmentTrackingInput( 'C07 synthetic carrier', 'C07-SYNTHETIC-TRACK', 'https://example.invalid/c07-track', gmdate( 'Y-m-d' ), 'C07 synthetic tracking note' ), get_current_user_id() );
		$status = $container->get( CetechDeliveryEngine\Application\Shipment\ShipmentStatusService::class )->change( $shipment->id, CetechDeliveryEngine\Domain\Enum\ShipmentStatus::Processing, CetechDeliveryEngine\Application\Shipment\ShipmentStatusChangeRequest::staff_normal( 'C07 synthetic progress', get_current_user_id() ) );
		$check( 'NATIVE-C07-EXISTING-SHIPMENT-TRACKING-STATUS-CONTINUES', $tracking->ok && $status->ok && 'processing' === $status->shipment?->status->value && 'C07-SYNTHETIC-TRACK' === $tracking->shipment?->tracking_number && 'checkout_suspended' === $service->read( $site )->state?->state );
		$cod = $fixture->order(); $cod->set_payment_method( 'cod' ); $cod->set_status( 'on-hold' ); $cod->save(); $container->get( CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentEvaluator::class )->sync( $cod );
		$check( 'NATIVE-C07-EXISTING-COD-MANUAL-AUTHORITY-PRESERVED', $container->get( CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentStore::class )->is_awaiting( $cod ) && null === $cod->get_date_paid() && current_user_can( 'manage_shipments' ) && 'checkout_suspended' === $service->read( $site )->state?->state, [ 'existing_cod_task_index' => true, 'no_payment_confirmation_inferred' => true ] );
	} catch ( Throwable $error ) { $failure = $error; throw $error; }
	finally {
		if ( isset( $fixture->state['user_id'] ) ) { try {
			// Track exact new native operational rows in this single-process disposable
			// phase. Cleanup deletes only these captured IDs, never an order/table sweep.
			foreach ( [ 'shipments', 'shipment_items', 'shipment_events', 'audit_logs' ] as $suffix ) { $baseline = array_column( $fixture->state['domain_before'][ $suffix ], 'id' ); $already = array_map( static fn ( array $item ): int => $item[0] === $suffix ? $item[1] : 0, $fixture->state['entities'] ); foreach ( $fixture::rows( $suffix ) as $row ) { if ( ! in_array( $row['id'], $baseline, true ) && ! in_array( (int) $row['id'], $already, true ) ) { $fixture->state['entities'][] = [ $suffix, (int) $row['id'] ]; } } }
			$cleanup = $fixture->cleanup(); $check( null === $history ? 'NATIVE-C07-EXACT-FIXTURE-CLEANUP-ALL32-CONTROL-PRESERVED' : 'NATIVE-C07-HISTORY-HOOK-FIXTURE-CLEANUP', $cleanup['cleanup_restored'] && ! $cleanup['role_exists'] && ! $cleanup['user_exists'], $cleanup );
		} catch ( Throwable $cleanup_error ) { if ( null === $failure ) { throw $cleanup_error; } } }
		wp_set_current_user( 0 ); wp_set_current_user( $old_user );
	}
};
