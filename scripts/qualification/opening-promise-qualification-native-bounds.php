<?php
/** Real marked WordPress/Woo loaded-cart boundaries; no successful 200-group placement claim. */
declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteEnvironment;
use CetechDeliveryEngine\Application\ServicePromise\Presentation\PublicPromiseFormatter;
use CetechDeliveryEngine\Domain\ServicePromise\{PublicPromiseView, ServicePromisePolicy};

return static function ( callable $check, string $mode = 'hpos_on' ): void {
	global $wpdb;
	if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || ! defined( 'WP_ADMIN' ) || true !== WP_ADMIN || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! $wpdb instanceof wpdb || wpdb::class !== get_class( $wpdb ) || is_multisite() || ( 'hpos_on' === $mode ) !== Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() || WP_Object_Cache::class !== get_class( $GLOBALS['wp_object_cache'] ) ) { throw new RuntimeException( 'P06 bounds require the exact marked native default-cache fixture.' ); }
	require_once __DIR__ . '/opening-promise-handoff-support.php'; require_once __DIR__ . '/opening-promise-native-configuration-support.php'; require_once __DIR__ . '/promise-qualification-native-source-observer.php';
	$fixture = null; $configuration = null; $failure = null; $loaded_cart = null; $cleanup = [];
	try {
		$fixture = new CetechPromiseHandoffNativeFixture( $wpdb ); $fixture->prepare(); $cart = WC()->cart;
		if ( ! $cart instanceof WC_Cart || WC_Cart::class !== get_class( $cart ) || ! is_array( $cart->cart_contents ) || [] === $cart->cart_contents ) { throw new RuntimeException( 'P06 actual loaded Woo cart unavailable.' ); }
		$loaded_cart = $cart->cart_contents; $item = array_values( $loaded_cart )[0]; $native = new NativeCartQuoteEnvironment( $fixture->native->factory );
		$source_connections = static fn(): int => count( ( new ReflectionProperty( CetechQuotePlacementFactory::class, 'sessions' ) )->getValue( $fixture->native->factory ) );
		$small = $native->draft();
		$check( 'NATIVE-W2P06-REAL-LOADED-CART-CONTROL', null !== $small && count( $small->private_facts()['lines'] ) === count( $loaded_cart ), [ 'actual_woo_cart_class' => WC_Cart::class === get_class( $cart ), 'native_positive_control_before_bounds' => null !== $small, 'same_loaded_native_members' => null !== $small && count( $small->private_facts()['lines'] ) === count( $loaded_cart ) ], [ 'loaded_lines' => count( $loaded_cart ) ] );
		foreach ( [ 200, 201 ] as $count ) {
			$entries = []; for ( $i = 0; $i < $count; ++$i ) { $entries['p06_bound_' . $i] = $item; } $cart->set_cart_contents( $entries ); $queries = $wpdb->num_queries; $connections = $source_connections(); $draft = $native->draft();
			if ( 200 === $count ) {
				$check( 'NATIVE-W2P06-LOADED-LINES-200-WHOLE-REFUSAL', null === $draft && $queries === $wpdb->num_queries && $connections === $source_connections(), [ 'actual_native_loaded_count' => 200 === count( $cart->cart_contents ), 'no_partial_draft' => null === $draft, 'cheap_refusal_before_wordpress_sql' => $queries === $wpdb->num_queries, 'no_owned_source_connection' => $connections === $source_connections() ], [ 'loaded_lines' => count( $cart->cart_contents ), 'wordpress_queries' => $wpdb->num_queries - $queries, 'source_connections' => $source_connections() - $connections ] );
			} else {
				$check( 'NATIVE-W2P06-LOADED-LINES-201-WHOLE-REFUSAL', null === $draft && $queries === $wpdb->num_queries && $connections === $source_connections(), [ 'actual_native_loaded_count' => 201 === count( $cart->cart_contents ), 'no_partial_draft' => null === $draft, 'cheap_refusal_before_wordpress_sql' => $queries === $wpdb->num_queries, 'no_owned_source_connection' => $connections === $source_connections() ], [ 'loaded_lines' => count( $cart->cart_contents ), 'wordpress_queries' => $wpdb->num_queries - $queries, 'source_connections' => $source_connections() - $connections ] );
			}
		}
		$cart->set_cart_contents( $loaded_cart ); $restored = $native->draft();
		$check( 'NATIVE-W2P06-LOADED-CART-RESTORE-POSITIVE-CONTROL', null !== $restored && $restored->draft_digest() === $small->draft_digest(), [ 'original_members_restored' => $loaded_cart === $cart->cart_contents, 'same_original_draft_digest' => null !== $restored && $restored->draft_digest() === $small->draft_digest() ], [ 'loaded_lines' => count( $cart->cart_contents ) ] );
		// The retained legacy control predates this fixture's native source configuration.
		// Refresh/Confirm uses the real admission/preparation path after that configuration;
		// its exact new quote namespaces are discovered by the existing owner-only cleanup.
		$fresh = $fixture->native->confirmed_evidence(); $base = $fresh->context()->base_context(); $owner = $fresh->owner();
		if ( ! $owner->equals( $fixture->legacy->owner() ) || ! $base->checkout_acceptable() || $fixture->legacy_bytes !== $fixture->native->cart->row( $fixture->legacy->header()->id()->value() ) ) { throw new RuntimeException( 'P06 fresh native source context changed its owner or retained quote history.' ); }
		$source_factory = new CetechPromiseQualificationSourceFactory( $wpdb ); $source_authority = new CetechPromiseQualificationNativeAuthority( $fixture->authority, $source_factory ); $network_owned = 0; $wordpress_queries_owned = 0;
		$http_observer = static function ( mixed $preempt ) use ( $source_factory, &$network_owned ): mixed { if ( $source_factory->has_owner() ) { ++$network_owned; return new WP_Error( 'p06_owned_network_refused', 'Owned source network invocation refused.' ); } return $preempt; };
		$query_observer = static function ( string $sql ) use ( $source_factory, &$wordpress_queries_owned ): string { if ( $source_factory->has_owner() ) { ++$wordpress_queries_owned; throw new RuntimeException( 'P06 native WordPress SQL callback occurred inside source ownership.' ); } return $sql; };
		add_filter( 'pre_http_request', $http_observer, PHP_INT_MAX, 1 ); add_filter( 'query', $query_observer, PHP_INT_MAX, 1 );
		try {
			$at = \CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime::parse( \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime::now()->sql() );
			$service = new \CetechDeliveryEngine\Application\ServicePromise\Handoff\PromiseNativeCaptureService( $fixture->binding, $source_factory, $source_authority ); $captured = $service->capture( $base, $owner, $at, $fixture->registry->create_demands( $base ) ); $capture_retired = $source_factory->all_retired(); $capture_trace = $source_factory->transports[0]->source_trace();
			$session = $source_factory->open(); if ( ! $session->begin() ) { throw new RuntimeException( 'P06 final source owner unavailable.' ); }
			try { $validity = $service->current_fence( $captured->context(), $at )->validity_context( $session, $owner, $captured->context() ); $final_trace = $source_factory->transports[1]->source_trace(); } finally { $session->rollback(); $session->retire(); }
			$check( 'NATIVE-W2P06-ACTUAL-SOURCE-OWNER-QUERY-CALLBACK-FENCE', null !== $validity && $capture_retired && $source_factory->all_retired() && $capture_trace['unique'] && $final_trace['unique'] && 0 === $source_authority->owned_calls && 0 === $network_owned && 0 === $wordpress_queries_owned, [ 'real_native_collected_context' => $base->checkout_acceptable(), 'one_unique_source_read_per_identity_each_owner' => $capture_trace['unique'] && $final_trace['unique'], 'source_owner_retired_before_native_runtime_calculation' => $capture_retired, 'final_existing_owner_verified_without_replacement' => null !== $validity && 2 === count( $source_factory->sessions ), 'no_authority_callback_under_owned_sql' => 0 === $source_authority->owned_calls, 'native_wordpress_network_observer_saw_no_owned_request' => 0 === $network_owned, 'native_wordpress_sql_observer_saw_no_owned_callback' => 0 === $wordpress_queries_owned, 'all_owned_connections_retired' => $source_factory->all_retired() ], [ 'native_groups' => count( $base->private_facts()['groups'] ), 'capture_source_selects' => $capture_trace['source_selects'], 'final_source_selects' => $final_trace['source_selects'], 'connections' => count( $source_factory->sessions ), 'authority_callbacks' => $source_authority->calls, 'callbacks_during_owned_sql' => $source_authority->owned_calls, 'owned_network_requests' => $network_owned, 'owned_wordpress_queries' => $wordpress_queries_owned ] );
			fwrite( STDERR, json_encode( [ 'case' => 'p06_native_source_query_observation', 'capture' => $capture_trace, 'final_existing_owner' => $final_trace ], JSON_THROW_ON_ERROR ) . "\n" );
		} finally { remove_filter( 'pre_http_request', $http_observer, PHP_INT_MAX ); remove_filter( 'query', $query_observer, PHP_INT_MAX ); $source_factory->close_all(); }
		// Native authorized hypothetical preview: Same Day is impossible for an explicit 25-hour leg.
		$configuration = new CetechPromiseNativeConfigurationFixture( $wpdb ); $calendar = $configuration->calendar(); $base = $configuration->policy( $calendar, 1, [ 'anchor' => 'checkout_capture', 'late_payment_rule' => 'refuse_if_infeasible' ] )->private_facts();
		$base['service'] = [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'same_day', 'customer_label' => 'Same Day' ]; $base['day_constraint'] = 'same_day'; $base['graph']['components'][0]['duration']['min'] = 1500; $base['graph']['components'][0]['duration']['max'] = 1500;
		$selected = WC()->session->get( 'chosen_shipping_methods' ); $same_day = $configuration->service->preview( $configuration->binding, ServicePromisePolicy::from_array( $base )->to_private_json(), [ $calendar->private_facts() ] );
		$base['service'] = [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'standard', 'customer_label' => 'Standard' ]; $base['day_constraint'] = 'none'; $alternative = $configuration->service->preview( $configuration->binding, ServicePromisePolicy::from_array( $base )->to_private_json(), [ $calendar->private_facts() ] ); $formatter = new PublicPromiseFormatter();
		$bad_view = PublicPromiseView::from_array( $same_day['views'][0] ); $alternative_view = PublicPromiseView::from_array( $alternative['views'][0] ); $text = $formatter->text( $alternative_view );
		$check( 'NATIVE-W2P06-IMPOSSIBLE-SAME-DAY-HONEST-ALTERNATIVE', in_array( $bad_view->fields()['state'], [ 'ineligible', 'unavailable' ], true ) && 'absolute_window' === $alternative_view->fields()['state'] && str_contains( $text, 'UTC' ) && $selected === WC()->session->get( 'chosen_shipping_methods' ), [ 'same_day_complete_range_refused' => in_array( $bad_view->fields()['state'], [ 'ineligible', 'unavailable' ], true ), 'standard_manual_twenty_five_hour_leg_available' => 'absolute_window' === $alternative_view->fields()['state'], 'honest_zone_range_visible' => str_contains( $text, 'UTC' ), 'original_selected_native_method_unchanged' => $selected === WC()->session->get( 'chosen_shipping_methods' ), 'both_previews_no_admission_authority' => false === $same_day['admission'] && false === $alternative['admission'] ], [ 'duration_minutes' => 1500, 'selected_methods' => is_array( $selected ) ? count( $selected ) : 0 ] );
	} catch ( Throwable $error ) { $failure = $error; throw $error; }
	finally {
		if ( null !== $loaded_cart && WC()->cart instanceof WC_Cart ) { WC()->cart->set_cart_contents( $loaded_cart ); }
		if ( null !== $configuration ) { try { $configuration_cleanup = $configuration->cleanup(); foreach ( $configuration_cleanup as $key => $ok ) { $cleanup['configuration_' . $key] = $ok; } } catch ( Throwable ) { $cleanup['configuration_cleanup'] = false; } }
		if ( null !== $fixture ) { try { foreach ( $fixture->cleanup() as $key => $ok ) { $cleanup[$key] = $ok; } } catch ( Throwable ) { $cleanup['native_cleanup'] = false; } }
		try { $check( 'NATIVE-W2P06-TRACKED-NATIVE-BOUNDS-CLEANUP', [] !== $cleanup && ! in_array( false, $cleanup, true ), [ 'tracked_native_entities_restored' => [] !== $cleanup && ! in_array( false, $cleanup, true ), 'adoption_and_control_restored' => true === ( $cleanup['default_adoption_restored'] ?? false ), 'all_owned_connections_retired' => true === ( $cleanup['all_owned_connections_retired'] ?? false ) ], [ 'cleanup_checks' => count( $cleanup ) ] ); } catch ( Throwable $cleanup_error ) { if ( null === $failure ) { throw $cleanup_error; } }
	}
};
