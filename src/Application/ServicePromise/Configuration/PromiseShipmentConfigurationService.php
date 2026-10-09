<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Configuration;

use CetechDeliveryEngine\Application\ServicePromise\Shipment\ShipmentPromiseService;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseJson, PromiseShape};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\Shipment\ShipmentCurrentPromise;
use CetechDeliveryEngine\Domain\Shipment\{Shipment, ShipmentRepositoryInterface};
use CetechDeliveryEngine\Integrations\ServicePromise\NativePromiseRuntimeCapture;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;

/** Staff estimate adapter. A signed user/site/session-bound form preserves original event facts. */
final class PromiseShipmentConfigurationService {
	private \Closure $resolve;
	private ?string $original_envelope = null;
	public function __construct( private ShipmentRepositoryInterface $shipments, callable $promise_service_for_order ) { $this->resolve = \Closure::fromCallable( $promise_service_for_order ); }
	public function can_access(): bool {
		return function_exists( 'is_admin' ) && is_admin() && function_exists( 'get_current_user_id' ) && get_current_user_id() > 0
			&& ! AdminPageAccess::current_user_is_restricted() && current_user_can( 'manage_shipments' );
	}
	public function can_disclose( int $shipment_id, int $order_id ): bool { try { [ , $order ] = $this->target( $shipment_id ); return $order->get_id() === $order_id; } catch ( \Throwable ) { return false; } }
	public function open( int $shipment_id ): array {
		[ $shipment, $order, $service ] = $this->target( $shipment_id ); $stored = $service->read_for_order( $shipment, $order );
		if ( null === $stored ) { self::deny(); } $this->require_order( $order );
		$token = RequestContext::create()->request_id; $at = RuleTime::parse( ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' ) );
		$envelope = [ 'format' => 1, 'kind' => 'context', 'site_id' => $this->site(), 'author_user_id' => get_current_user_id(), 'session_hash' => $this->session_hash(),
			'shipment_id' => $shipment_id, 'order_id' => $order->get_id(), 'expected_revision' => $stored->row()['revision'], 'request_token' => $token, 'original_packet_digest' => $stored->packet()->digest(),
			'event_key' => hash( 'sha256', 'cetech-staff-promise-event-v1:' . $this->site() . ':' . get_current_user_id() . ':' . $token ), 'event_at' => $at->sql(), 'runtime' => ( new NativePromiseRuntimeCapture() )->capture(),
			'expires_at' => RuleTime::from_epoch_microseconds( $at->epoch_microseconds() + 900000000 )->sql(), 'semantic_json' => null, 'current_json' => null ];
		$this->require_order( $order );
		return [ 'shipment_id' => $shipment->id, 'order_id' => $order->get_id(), 'revision' => $stored->row()['revision'], 'original' => $stored->original_public(), 'current' => $stored->current()?->public_facts(),
			'current_reason' => $stored->current()?->private_facts()['reason'] ?? null, 'request_token' => $token, 'original_envelope' => $this->seal( $envelope ) ];
	}
	/** Estimate bounds/reason are explicit staff decisions. Event clock, runtime, author and original link are server facts. */
	public function update( int $shipment_id, int $revision, string $token, array $input, bool $reconcile = false, ?string $original_envelope = null ): OperationAttemptResult {
		$this->original_envelope = null;
		if ( ! RequestContext::is_valid_identifier( $token ) ) { PromiseShape::invalid(); }
		PromiseShape::integer( $revision, 2, PHP_INT_MAX - 1 );
		PromiseShape::fields( $input, [ 'state', 'from', 'until', 'display_timezone', 'reason' ] ); PromiseShape::choice( $input['state'], [ 'absolute_window', 'unavailable' ] ); PromiseShape::text( $input['reason'], 1000 );
		if ( 'absolute_window' === $input['state'] ) { PromiseShape::timezone( $input['display_timezone'] ); if ( PromiseShape::instant( $input['from'] )->compare( PromiseShape::instant( $input['until'] ) ) > 0 ) { PromiseShape::invalid(); } }
		elseif ( null !== $input['from'] || null !== $input['until'] || null !== $input['display_timezone'] ) { PromiseShape::invalid(); }
		[ $shipment, $order, $service ] = $this->target( $shipment_id ); $semantic = PromiseJson::encode( $input ); $draft = $this->verify( $original_envelope );
		if ( $draft['shipment_id'] !== $shipment_id || $draft['order_id'] !== $order->get_id() || $draft['expected_revision'] !== $revision || $draft['request_token'] !== $token ) { self::deny(); }
		if ( 'context' === $draft['kind'] ) {
			if ( $reconcile ) { self::deny(); } // Missing original command never becomes a new request.
			$stored = $service->read_for_order( $shipment, $order ); if ( null === $stored ) { self::deny(); }
			if ( $stored->packet()->digest() !== $draft['original_packet_digest'] ) { self::deny(); }
			$this->require_order( $order ); $views = [];
			if ( 'absolute_window' === $input['state'] ) { foreach ( $stored->original_public()['views'] as $original ) { $views[] = [ 'service_label' => $original['service_label'], 'display_timezone' => $input['display_timezone'], 'from' => $input['from'], 'until' => $input['until'] ]; } }
			$current = ShipmentCurrentPromise::from_array( [ 'format' => 1, 'source' => 'staff_revision', 'state' => $input['state'], 'original_packet_digest' => $stored->packet()->digest(),
				'event_key' => $draft['event_key'], 'event_at' => $draft['event_at'], 'reason' => $input['reason'], 'runtime' => $draft['runtime'], 'views' => $views ] );
			$draft['kind'] = 'command'; $draft['semantic_json'] = $semantic; $draft['current_json'] = $current->to_private_json();
		}
		if ( $draft['semantic_json'] !== $semantic || ! is_string( $draft['current_json'] ) ) { self::deny(); }
		$this->require_order( $order ); $current = ShipmentCurrentPromise::from_json( $draft['current_json'] );
		$this->original_envelope = $this->seal( $draft );
		$result = $service->update_current( $order, $shipment_id, $revision, $current, get_current_user_id(), $token, $reconcile ); $this->require_order( $order ); return $result;
	}
	public function original_envelope(): ?string { return $this->original_envelope; }
	private function target( int $id ): array {
		if ( ! $this->can_access() || $id < 1 || ! function_exists( 'wc_get_order' ) ) { self::deny(); }
		$shipment = $this->shipments->findById( $id ); if ( ! $shipment instanceof Shipment ) { self::deny(); }
		$order = wc_get_order( $shipment->order_id ); if ( ! $order instanceof \WC_Order || $order->get_id() !== $shipment->order_id ) { self::deny(); }
		$this->require_order( $order ); $service = ( $this->resolve )( $order ); if ( ! $service instanceof ShipmentPromiseService ) { self::deny(); }
		return [ $shipment, $order, $service ];
	}
	private function require_order( \WC_Order $order ): void { if ( ! $this->can_access() || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) { self::deny(); } }
	private function seal( array $facts ): string { $json = PromiseJson::encode( $facts ); return base64_encode( PromiseJson::encode( [ 'facts' => $facts, 'mac' => hash_hmac( 'sha256', $json, $this->key() ) ] ) ); }
	private function verify( ?string $encoded ): array {
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 131072 ) { self::deny(); } $json = base64_decode( $encoded, true ); if ( false === $json ) { self::deny(); }
		$data = PromiseJson::decode( $json ); PromiseShape::fields( $data, [ 'facts', 'mac' ] ); $facts = PromiseShape::object( $data['facts'] );
		if ( ! hash_equals( hash_hmac( 'sha256', PromiseJson::encode( $facts ), $this->key() ), PromiseShape::digest( $data['mac'] ) ) ) { self::deny(); }
		PromiseShape::fields( $facts, [ 'format', 'kind', 'site_id', 'author_user_id', 'session_hash', 'shipment_id', 'order_id', 'expected_revision', 'request_token', 'original_packet_digest', 'event_key', 'event_at', 'runtime', 'expires_at', 'semantic_json', 'current_json' ] );
		if ( 1 !== $facts['format'] || ! in_array( $facts['kind'], [ 'context', 'command' ], true ) || $facts['site_id'] !== $this->site() || $facts['author_user_id'] !== get_current_user_id() || $facts['session_hash'] !== $this->session_hash()
			|| ! RequestContext::is_valid_identifier( $facts['request_token'] ) || RuleTime::parse( ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' ) )->compare( PromiseShape::instant( $facts['expires_at'] ) ) >= 0
			|| ( 'context' === $facts['kind'] && ( null !== $facts['semantic_json'] || null !== $facts['current_json'] ) )
			|| ( 'command' === $facts['kind'] && ( ! is_string( $facts['semantic_json'] ) || ! is_string( $facts['current_json'] ) ) ) ) { self::deny(); }
		return $facts;
	}
	private function key(): string { if ( ! function_exists( 'wp_salt' ) ) { self::deny(); } $key = wp_salt( 'auth' ); if ( ! is_string( $key ) || strlen( $key ) < 16 ) { self::deny(); } return $key; }
	private function session_hash(): string { if ( ! function_exists( 'wp_get_session_token' ) ) { self::deny(); } $token = wp_get_session_token(); if ( ! is_string( $token ) || '' === $token ) { self::deny(); } return hash( 'sha256', $token ); }
	private function site(): int { return function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1; }
	private static function deny(): never { throw new \RuntimeException( 'Shipment promise request is unavailable.' ); }
}
