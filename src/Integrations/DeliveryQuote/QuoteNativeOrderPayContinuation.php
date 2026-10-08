<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuotePlacementSavedEvidenceGuard,QuoteSavedOrderAuthorization};
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;

/** Same acknowledged native attempt; no second control/source/expiry admission. */
final class QuoteNativeOrderPayContinuation {
	private QuoteOrderPayLocalBinding $local;
	private ?string $title = null;
	private bool $delegated = false;
	private bool $hpos;
	private bool $free;
	private EmergencyCheckoutLocalBinding $exact;
	public function __construct( private OperationConnectionFactory $factory, private \WC_Order $order, private QuoteBinding $binding, private QuotePlacementSavedEvidenceGuard $saved, private QuoteSavedOrderAuthorization $authorization, private string $method, private bool $method_change = false ) {
		if ( 'sealed' !== $binding->state() || 3 !== $binding->revision() || $binding->row()['order_id'] !== $order->get_id() || '' === $method || strlen( $method ) > 200 || preg_match( '/[^a-zA-Z0-9_\-]/', $method ) ) { throw new \RuntimeException( 'Native payment continuation unavailable.' ); }
		$this->local = QuoteOrderPayLocalBinding::capture( $order );
		$this->exact = EmergencyCheckoutLocalBinding::capture( $order ) ?? throw new \RuntimeException( 'Native payment continuation unavailable.' );
		if ( ! $method_change ) { $this->title( $order->get_payment_method_title( 'edit' ) ); }
		$this->hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$total = $order->get_total( 'edit' ); $this->free = ( is_string( $total ) || is_int( $total ) ) && 1 === preg_match( '/\A0(?:\.0{1,12})?\z/D', (string) $total );
	}
	public function method(): string { return $this->method; }
	public function order_id(): int { return $this->binding->row()['order_id']; }
	public function title( mixed $title ): void { if ( ! is_string( $title ) || strlen( $title ) > 1024 || null !== $this->title && $this->title !== $title ) { throw new \RuntimeException( 'Native payment title unavailable.' ); } $this->title = $title; }
	public function delegated(): bool { return $this->delegated; }
	public function verify_free(): bool { return $this->free && $this->verify( false ); }
	public function delegate(): void { $this->delegated = true; }
	public function verify( bool $selected = true ): bool {
		$session = null; $begun = false;
		try {
			$unchanged = fn(): bool => $this->local->unchanged() && ( $this->method_change || $this->exact->unchanged() ) && $this->authorization->unchanged() && ( ! $selected || null !== $this->title && $this->local->payment_matches( $this->method, $this->title ) );
			if ( ! $unchanged() ) { return false; }
			$session = $this->factory->open();
			if ( $session->site_id() !== $this->binding->site_id() || $session->in_transaction() || $session->is_retired() || ! $session->begin() ) { return false; } $begun = true;
			$tables = array_values( array_unique( $this->saved->tables( $session ) ) ); sort( $tables, SORT_STRING );
			if ( ! $session->validate_tables( $tables ) || ! $unchanged() || ! $this->saved->verify( $session, $this->binding ) || $selected && ! $this->selected_physical( $session ) || ! $unchanged() || ! $session->rollback() ) { return false; }
			$begun = false; return $session->retire() && $unchanged();
		} catch ( \Throwable ) { return false; }
		finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } if ( ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} } } }
	}
	private function selected_physical( \CetechDeliveryEngine\Domain\Operation\OperationSession $session ): bool {
		$p = $session->table_prefix(); $id = $this->order_id();
		if ( $this->hpos ) { $rows = $session->get_results( $session->prepare( "SELECT payment_method,payment_method_title FROM `{$p}wc_orders` WHERE id=%d LIMIT 2 FOR UPDATE", $id ) ); return is_array( $rows ) && count( $rows ) === 1 && ( $rows[0]['payment_method'] ?? null ) === $this->method && ( $rows[0]['payment_method_title'] ?? null ) === $this->title; }
		$rows = $session->get_results( $session->prepare( "SELECT meta_key,meta_value FROM `{$p}postmeta` WHERE post_id=%d AND meta_key IN ('_payment_method','_payment_method_title') ORDER BY meta_key,meta_value LIMIT 3 FOR UPDATE", $id ) );
		return is_array( $rows ) && count( $rows ) === 2 && $rows === [ [ 'meta_key' => '_payment_method', 'meta_value' => $this->method ], [ 'meta_key' => '_payment_method_title', 'meta_value' => $this->title ] ];
	}
}
