<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\ServicePromise\Presentation;

use CetechDeliveryEngine\Application\CustomerContext\{CustomerBrowsingLocationStore, ShopperDeliveryLocationPrecision};
use CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePlacementActivation;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\ServicePromise\Calculation\DeterministicPromiseCalculator;
use CetechDeliveryEngine\Application\ServicePromise\Persistence\PromiseVersionReadService;
use CetechDeliveryEngine\Application\ServicePromise\Presentation\{PromisePublicProjection, PublicPromiseFormatter};
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseInput, PromiseShape, PublicPromiseView};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromiseEffectiveAssignment, PromiseSiteBinding};
use CetechDeliveryEngine\Integrations\ServicePromise\NativePromiseRuntimeCapture;

/** Native public catalog preview. No cart, quote, acceptance, source mutation or arbitrary version read. */
final class NativePromisePdpRuntime {
	public function __construct( private OperationConnectionFactory $factory, private PromiseQuotePlacementActivation $activation, private CustomerBrowsingLocationStore $browsing, private ?ShopperDeliveryLocationPrecision $precision = null ) {}
	public function decorate( ProductDeliveryOption $option, ProductRuleResolutionResult $resolution ): ProductDeliveryOption {
		if ( ! $this->activation->requested() || ! $option->is_available ) { return $option; }
		try {
			$config = $this->activation->configuration(); if ( null === $config || null === $option->delivery_offer_id || ! function_exists( 'wc_get_product' ) ) { return $this->unavailable( $option ); }
			$product = wc_get_product( $resolution->input_target_id ); if ( ! $product instanceof \WC_Product || (int) $product->get_id() !== $resolution->input_target_id || ! $this->visible( $product ) || ! in_array( $resolution->input_target_type, [ 'product', 'variation' ], true ) ) { return $this->unavailable( $option ); }
			$variation = $product->is_type( 'variation' ) ? (int) $product->get_id() : null; $product_id = null === $variation ? (int) $product->get_id() : (int) $product->get_parent_id();
			if ( ( null !== $variation ) !== ( 'variation' === $resolution->input_target_type ) ) { return $this->unavailable( $option ); }
			$entry = null; foreach ( $config['registry']->private_facts()['entries'] as $candidate ) { if ( $option->delivery_offer_id === $candidate['native_service_id'] ) { $entry = $candidate; break; } } if ( null === $entry ) { return $this->unavailable( $option ); }
			$service = [ 'service_kind' => $entry['service_kind'], 'service_code' => $entry['service_code'], 'endpoint' => $entry['destination_endpoint'], 'endpoint_kind' => $entry['destination_kind'] ];
			$native = $this->native_binding( $product ); $principal = hash( 'sha256', $native ); $binding = $config['binding'];
			$current = fn(): bool => function_exists( 'get_current_blog_id' ) && get_current_blog_id() === $binding->site_id() && $native === $this->native_binding( $product ) && $this->visible( $product );
			if ( ! $current() ) { return $this->unavailable( $option ); }
			$authority = new NativePromisePdpAuthorizer( $binding, $product_id, $variation, $service, $principal, $current ); $reader = new PromiseVersionReadService( $binding, $this->factory, $authority );
			$at = RuleTime::parse( ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' ) ); $effective = $this->effective( $reader, $binding, $service, $principal, $at, $product_id, $variation );
			if ( null === $effective || 'assigned' !== $effective->state() ) { return $this->unavailable( $option ); }
			$location = $this->browsing->get(); $complete = null !== $location && '' !== $location->country_identity && '' !== $location->city_identity && ( null === $this->precision || $this->precision->evaluate( $location )->sufficient );
			if ( 'pickup' === $entry['destination_kind'] ) { $complete = null !== $option->pickup_location_id; }
			if ( ! $complete ) { $projection = PromisePublicProjection::hypothetical( [], false ); return $option->withPromiseEstimate( $projection['notice'], true ); }
			$origin = null; foreach ( $resolution->chosen_rules as $rule ) { if ( in_array( $option->delivery_offer_id, $rule->delivery_offer_ids, true ) && null !== $rule->origin_id ) { $origin = $rule->origin_id; break; } }
			if ( null === $origin ) { return $this->unavailable( $option ); }
			$policy = $effective->policy(); $facts = $policy->private_facts(); $expires = RuleTime::from_epoch_microseconds( $at->epoch_microseconds() + 300000000 ); $deadline = null !== $policy->effective_until() && $policy->effective_until()->compare( $expires ) < 0 ? $policy->effective_until() : $expires;
			$day = $this->service_day( $facts, $at ); if ( null !== $day && RuleTime::parse( $day['end_at'] )->compare( $deadline ) < 0 ) { $deadline = RuleTime::parse( $day['end_at'] ); }
			$anchor = [ 'format_version' => 1, 'kind' => $policy->anchor(), 'evaluated_at' => $at->sql(), 'quote_expires_at' => $expires->sql() ] + match ( $policy->anchor() ) { 'order_accepted' => [ 'accept_until' => $deadline->sql() ], 'checkout_capture' => [ 'capture_at' => $at->sql() ], 'payment_confirmed' => [ 'awaited_event' => 'woocommerce_payment_confirmed' ] };
			$destination = [ 'endpoint' => $entry['destination_endpoint'], 'endpoint_kind' => $entry['destination_kind'], 'identity_digest' => hash( 'sha256', 'pickup' === $entry['destination_kind'] ? 'pickup:' . $option->pickup_location_id : $location->identity() ) ];
			$sources = []; foreach ( $facts['graph']['components'] as $component ) { $sources[$component['source']['source_id']] = $component['source']; }
			$capacity = [ 'format_version' => 1, 'mode' => 'none' ]; if ( 'required' === $policy->capacity_mode() ) { return $this->unavailable( $option ); }
			$runtime = ( new NativePromiseRuntimeCapture() )->capture();
			$input = PromiseInput::from_array( [ 'format_version' => 1, 'site_id' => $binding->site_key(), 'evaluated_at' => $at->sql(), 'owner' => [ 'site_id' => $binding->site_key(), 'kind' => function_exists( 'get_current_user_id' ) && get_current_user_id() > 0 ? 'customer' : 'guest', 'principal_hash' => $principal, 'session_hash' => hash( 'sha256', $native ), 'key_epoch' => 'pdp-preview-v1' ], 'material' => [ 'group_id' => 'pdp-preview', 'material_digest' => hash( 'sha256', $native . ':' . $option->display_key ) ], 'origin' => [ 'endpoint' => $entry['origin_endpoint'], 'endpoint_kind' => $entry['origin_kind'], 'identity_digest' => hash( 'sha256', 'native-origin:' . $origin ) ], 'destination' => $destination, 'policy' => $facts, 'calendar_refs' => array_map( static fn( $calendar ): array => $calendar->reference()->private_facts(), $effective->calendars() ), 'source_receipts' => array_values( $sources ), 'anchor' => $anchor, 'service_day' => $day, 'capacity' => $capacity, 'runtime' => $runtime ] );
			$result = ( new DeterministicPromiseCalculator( $runtime ) )->calculate( $input, $effective->calendars() ); if ( ! $current() || $runtime !== ( new NativePromiseRuntimeCapture() )->capture() || $config['revision'] !== $this->activation->configuration()['revision'] || $facts['promise_required'] && ! in_array( $result->state(), [ 'absolute_window', 'relative_window' ], true ) ) { return $this->unavailable( $option ); }
			$views = array_map( static fn( string $id ): PublicPromiseView => PublicPromiseView::from_result( $result, $id ), $policy->endpoint_terminal_component_ids() ); $projection = PromisePublicProjection::hypothetical( $views, true );
			return $option->withPromiseEstimate( sprintf( __( 'Preliminary estimate: %s. Review the final delivery window at checkout.', 'cetech-woocommerce-delivery-engine' ), $projection['text'] ), true );
		} catch ( \Throwable ) { return $this->unavailable( $option ); }
	}
	private function effective( PromiseVersionReadService $reader, PromiseSiteBinding $binding, array $service, string $principal, RuleTime $at, int $product, ?int $variation ): ?PromiseEffectiveAssignment {
		$scopes = null === $variation ? [ [ 'product', $product ], [ 'global', 0 ] ] : [ [ 'variation', $variation ], [ 'product', $product ], [ 'global', 0 ] ];
		foreach ( $scopes as [ $kind, $id ] ) { $key = [ 'scope_kind' => $kind, 'scope_id' => $id ] + $service; $identity = new OperationIdentity( $binding->site_id(), 'service_promise.pdp.public.v1', $principal, 'promise.assignment.read', 1, PromiseAssignmentCommand::target_key( $binding, $key ), 'pdp-preview' ); $effective = $reader->capture_assignment( $identity, $key, $at ); if ( ! in_array( $effective->state(), [ 'absent', 'inherit' ], true ) ) { return $effective; } } return null;
	}
	private function visible( \WC_Product $product ): bool { if ( ! $product->is_visible() || ! $product->is_purchasable() ) { return false; } if ( $product->is_type( 'variation' ) ) { $parent = wc_get_product( $product->get_parent_id() ); return $parent instanceof \WC_Product && $parent->is_visible() && $parent->is_purchasable(); } return true; }
	private function native_binding( \WC_Product $product ): string { $user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0; $site = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0; $session = function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'get_customer_id' ) ? (string) WC()->session->get_customer_id() : ''; return $site . ':' . $product->get_id() . ':' . $product->get_parent_id() . ':' . $user . ':' . $session; }
	private function unavailable( ProductDeliveryOption $option ): ProductDeliveryOption { return $option->withPromiseEstimate( null, false, __( 'This service is currently unavailable. Choose another service or try again.', 'cetech-woocommerce-delivery-engine' ) ); }
	private function service_day( array $policy, RuleTime $at ): ?array { if ( 'none' === $policy['day_constraint'] ) { return null; } $local = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $at->sql(), new \DateTimeZone( 'UTC' ) )->setTimezone( new \DateTimeZone( $policy['promise_timezone'] ) ); $start = $local->setTime( 0, 0, 0, 0 ); return [ 'local_date' => $local->format( 'Y-m-d' ), 'timezone' => $policy['promise_timezone'], 'start_at' => $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' ), 'end_at' => $start->modify( '+1 day' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' ) ]; }
}
