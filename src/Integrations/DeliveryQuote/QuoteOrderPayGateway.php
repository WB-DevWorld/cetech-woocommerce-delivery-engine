<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\DeliveryQuote;

use CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlResponse;

/** Only the pinned native order-pay handler receives this request-local adapter. */
final class QuoteOrderPayGateway extends \WC_Payment_Gateway {
	private \Closure $terminal;
	public function __construct( private \WC_Payment_Gateway $native, private QuoteNativeOrderPayContinuation $continuation, callable $terminal ) {
		// The existing gateway owns registration, settings, fields and all effects.
		foreach ( get_object_vars( $native ) as $name => $value ) { if ( in_array( $name, [ 'native', 'continuation', 'terminal' ], true ) ) { throw new \RuntimeException( EmergencyControlResponse::message() ); } $this->$name = $value; }
		$this->terminal = \Closure::fromCallable( $terminal );
		if ( $this->id !== $continuation->method() ) { throw new \RuntimeException( EmergencyControlResponse::message() ); }
	}
	public function get_title() { $title = $this->native->get_title(); $this->continuation->title( $title ); return $title; }
	public function validate_fields() { $this->check(); return $this->native->validate_fields(); }
	public function process_payment( $order_id ) { if ( ! is_int( $order_id ) || $order_id !== $this->continuation->order_id() || $this->continuation->delegated() ) { throw new \RuntimeException( EmergencyControlResponse::message() ); } $this->check(); $this->continuation->delegate(); return $this->native->process_payment( $order_id ); }
	private function check(): void { if ( $this->id !== $this->continuation->method() || $this->native->id !== $this->continuation->method() || true !== ( $this->terminal )() || ! $this->continuation->verify() ) { throw new \RuntimeException( EmergencyControlResponse::message() ); } }
	public function payment_fields() { return $this->native->payment_fields(); }
	public function is_available() { return $this->native->is_available(); }
	public function get_icon() { return $this->native->get_icon(); }
	public function supports( $feature ) { return $this->native->supports( $feature ); }
}
