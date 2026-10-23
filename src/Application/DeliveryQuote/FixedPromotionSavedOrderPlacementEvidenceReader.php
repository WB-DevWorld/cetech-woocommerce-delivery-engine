<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\Operation\{DatabaseOperationReadiness, OperationReadiness};
use CetechDeliveryEngine\Application\Order\QuoteNativeOrderHistory;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationRecord, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository, DeliveryQuoteSchema, OperationStoreSchema, TableNames};

/** Exact original commands and producer history, independent of current cart or payment authority. */
final readonly class FixedPromotionSavedOrderPlacementEvidenceReader implements \JsonSerializable {

	public const META_ORIGINAL_BIND = '_cetech_de_promotion_original_bind';
	private \Closure $authorize_order;
	private \Closure $authorize_owner;
	private OperationReadiness $readiness;

	public function __construct( private OperationConnectionFactory $factory, callable $authorize_order, callable $authorize_owner, ?OperationReadiness $readiness = null ) {
		$this->authorize_order = \Closure::fromCallable( $authorize_order ); $this->authorize_owner = \Closure::fromCallable( $authorize_owner ); $this->readiness = $readiness ?? new DatabaseOperationReadiness();
	}

	/** Corrupt presence is still ownership. It must never release an order to the legacy writer. */
	public function owns( \WC_Order $order ): bool { return false !== QuoteNativeOrderHistory::meta_present( $order, self::META_ORIGINAL_BIND ); }

	/** Original intent recovery only; callers must use the real service before claiming any effect. */
	public function original( \WC_Order $order ): ?FixedPromotionQuoteBindCommand {
		try { $authorization = QuoteSavedOrderAuthorization::capture( $order, $this->authorize_order ); if ( true !== QuoteNativeOrderHistory::meta_present( $order, self::META_ORIGINAL_BIND ) ) { return null; } $raw = $order->get_meta( self::META_ORIGINAL_BIND, true ); if ( ! is_string( $raw ) || ! $authorization->unchanged() ) { return null; } $command = self::decode( $raw ); if ( $command->binding()->row()['order_id'] !== $order->get_id() || ! $this->authorized( $command ) ) { return null; } $this->acknowledge_packet( $order, $command, $authorization, $raw ); return $command; } catch ( \Throwable ) { return null; }
	}

	/** Write once before saved-proof capture. Later phases never replace or append a recovery packet. */
	public function preserve( \WC_Order $order, FixedPromotionQuoteBindCommand $command ): void {
		$authorization = QuoteSavedOrderAuthorization::capture( $order, $this->authorize_order );
		if ( $command->binding()->row()['order_id'] !== $order->get_id() || ! $this->authorized( $command ) ) { self::fail(); }
		$json = self::encode( $command ); $present = QuoteNativeOrderHistory::meta_present( $order, self::META_ORIGINAL_BIND );
		if ( null === $present || ! $authorization->unchanged() ) { self::fail(); }
		if ( $present ) { if ( $order->get_meta( self::META_ORIGINAL_BIND, true ) !== $json ) { self::fail(); } }
		else { $order->add_meta_data( self::META_ORIGINAL_BIND, $json, true ); if ( ! $authorization->unchanged() ) { self::fail(); } $order->save(); }
		if ( ! $authorization->unchanged() || $order->get_meta( self::META_ORIGINAL_BIND, true ) !== $json ) { self::fail(); }
		$this->acknowledge_packet( $order, $command, $authorization, $json );
	}

	/** Returns authenticated history only. No held-budget, source, expiry or gateway admission is inferred. */
	public function read( \WC_Order $order, RequestContext $request ): ?array {
		$session = null; $closed = false;
		try {
			// Exact native access precedes even the private packet lookup.
			$authorization = QuoteSavedOrderAuthorization::capture( $order, $this->authorize_order );
			if ( true !== QuoteNativeOrderHistory::meta_present( $order, self::META_ORIGINAL_BIND ) ) { return null; }
			$raw = $order->get_meta( self::META_ORIGINAL_BIND, true );
			if ( ! is_string( $raw ) || ! $authorization->unchanged() ) { return null; }
			$id = $order->get_id(); $bind = self::decode( $raw ); if ( $bind->binding()->row()['order_id'] !== $id || ! $this->authorized( $bind ) ) { return null; }
			$hpos = self::hpos(); $session = $this->factory->open(); $pin = self::pin( $session );
			$this->assert_owned( $session, $pin, $bind, $authorization, false ); $this->readiness->assert_ready( $session ); $this->assert_owned( $session, $pin, $bind, $authorization, false );
			if ( ! $session->begin() ) { return null; } $this->assert_owned( $session, $pin, $bind, $authorization, true );
			$tables = [ ...DeliveryQuoteSchema::tables( $pin[1] ), ...array_map( static fn( string $suffix ): string => $pin[1] . TableNames::PREFIX . $suffix, OperationStoreSchema::SUFFIXES ), $pin[1] . ( $hpos ? 'wc_orders_meta' : 'postmeta' ) ];
			if ( ! $session->validate_tables( array_values( array_unique( $tables ) ) ) ) { return null; } $this->assert_owned( $session, $pin, $bind, $authorization, true );
			if ( ! $this->packet_matches( $session, $id, $hpos, $raw, false ) ) { return null; }
			$repo = new DeliveryQuoteRepository( $session ); $quote = $repo->find_quote( $bind->header()->id() ); $binding = null === $quote ? null : $repo->find_binding( $quote );
			$this->assert_owned( $session, $pin, $bind, $authorization, true );
			if ( null === $quote || $quote->row() !== $bind->quote_record()->row() || null === $binding || $binding->row()['order_id'] !== $id ) { return null; }
			$prepared = QuoteBinding::from_row( array_replace( $binding->row(), [ 'state' => 'prepared', 'revision' => 1, 'snapshot_digest' => null, 'context_digest' => null, 'verified_at' => null, 'sealed_at' => null ] ), $quote );
			$verifier = new QuoteReceiptVerifier(); $verify = null; $seal = null;
			$all = $verifier->lock( $session, $quote->header(), $binding );
			if ( 'accepted' === ( $all['invalidate'] ?? null )?->state || 1 === $binding->revision() && ( 'accepted' === ( $all['verify_binding'] ?? null )?->state || 'accepted' === ( $all['seal'] ?? null )?->state ) || 2 === $binding->revision() && 'accepted' === ( $all['seal'] ?? null )?->state ) { return null; }
			if ( 1 === $binding->revision() ) { $records = $verifier->lock_promotion_binding( $session, $bind ); $valid = $verifier->promotion_binding_verified( $quote, $records, $prepared, $bind ); }
			else {
				$verify = FixedPromotionQuoteVerifyCommand::create( $bind, $prepared, $binding->row()['snapshot_digest'], $binding->row()['context_digest'] );
				$verified = QuoteBinding::from_row( array_replace( $binding->row(), [ 'state' => 'prepared', 'revision' => 2, 'sealed_at' => null ] ), $quote );
				if ( 2 === $binding->revision() ) { $records = $verifier->lock_promotion_verification( $session, $verify ); $valid = $verifier->promotion_verification_verified( $quote, $records, $verified, $verify ); }
				else {
					// The candidate hint supplies no authority; all five original receipts must authenticate it.
					$hint = $all['seal'] ?? null; $revision = $hint instanceof OperationRecord ? ( $hint->completion?->result['control_revision'] ?? null ) : null;
					if ( ! is_int( $revision ) || $revision < 1 ) { return null; }
					$seal = FixedPromotionQuoteSealCommand::create( $verify, $verified, $revision ); $records = $verifier->lock_promotion_seal( $session, $seal ); $valid = $verifier->promotion_seal_verified( $quote, $records, $binding, $seal );
				}
			}
			$this->assert_owned( $session, $pin, $bind, $authorization, true );
			if ( ! $valid || $repo->find_quote( $bind->header()->id(), true )?->row() !== $quote->row() || $repo->find_binding( $quote, true )?->row() !== $binding->row() || ! $this->packet_matches( $session, $id, $hpos, $raw, true ) ) { return null; }
			$this->assert_owned( $session, $pin, $bind, $authorization, true );
			if ( ! $session->rollback() ) { return null; } $this->assert_owned( $session, $pin, $bind, $authorization, false );
			if ( ! $session->retire() ) { return null; } $this->assert_owned( $session, $pin, $bind, $authorization, false, true );
			if ( ! $this->authorized( $bind ) || self::hpos() !== $hpos ) { return null; } $this->assert_owned( $session, $pin, $bind, $authorization, false, true );
			$closed = true; return [ 'bind' => $bind, 'verify' => $verify, 'seal' => $seal, 'quote' => $quote, 'binding' => $binding ];
		} catch ( \Throwable ) { return null; }
		finally { if ( null !== $session && ! $closed ) { try { $session->rollback(); } catch ( \Throwable ) {} try { $session->retire(); } catch ( \Throwable ) {} } }
	}

	/** Canonical encoding of the existing bounded command transport, without a new phase or intent. */
	public static function encode( FixedPromotionQuoteBindCommand $command ): string {
		// The typed command fixes field order as well as values; preserve that exact transport.
		$json = json_encode( $command->to_recovery_array(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 28 );
		if ( strlen( $json ) > FixedPromotionQuoteBindCommand::MAX_RECOVERY_BYTES ) { self::fail(); } return $json;
	}
	public static function decode( string $raw ): FixedPromotionQuoteBindCommand {
		if ( '' === $raw || strlen( $raw ) > FixedPromotionQuoteBindCommand::MAX_RECOVERY_BYTES ) { self::fail(); }
		$value = json_decode( $raw, true, 28, JSON_THROW_ON_ERROR ); if ( ! is_array( $value ) || array_is_list( $value ) ) { self::fail(); }
		$command = FixedPromotionQuoteBindCommand::from_recovery_array( $value ); if ( self::encode( $command ) !== $raw ) { self::fail(); } return $command;
	}
	private function acknowledge_packet( \WC_Order $order, FixedPromotionQuoteBindCommand $command, QuoteSavedOrderAuthorization $authorization, string $json ): void {
		$session = null; $closed = false; $hpos = self::hpos(); $id = $order->get_id();
		try { $session = $this->factory->open(); $pin = self::pin( $session ); $this->assert_owned( $session, $pin, $command, $authorization, false ); if ( ! $session->begin() ) { self::fail(); } $this->assert_owned( $session, $pin, $command, $authorization, true ); if ( ! $session->validate_tables( [ $pin[1] . ( $hpos ? 'wc_orders_meta' : 'postmeta' ) ] ) || ! $this->packet_matches( $session, $id, $hpos, $json, false ) ) { self::fail(); } $this->assert_owned( $session, $pin, $command, $authorization, true ); if ( ! $session->rollback() ) { self::fail(); } $this->assert_owned( $session, $pin, $command, $authorization, false ); if ( ! $session->retire() ) { self::fail(); } $this->assert_owned( $session, $pin, $command, $authorization, false, true ); if ( ! $this->authorized( $command ) || self::hpos() !== $hpos ) { self::fail(); } $this->assert_owned( $session, $pin, $command, $authorization, false, true ); $closed = true; }
		finally { if ( null !== $session && ! $closed ) { try { $session->rollback(); } catch ( \Throwable ) {} try { $session->retire(); } catch ( \Throwable ) {} } }
	}
	private function packet_matches( OperationSession $session, int $id, bool $hpos, string $json, bool $lock ): bool {
		$p = $session->table_prefix(); $table = $p . ( $hpos ? 'wc_orders_meta' : 'postmeta' ); $column = $hpos ? 'order_id' : 'post_id';
		$rows = $session->get_results( $session->prepare( "SELECT meta_value FROM `{$table}` WHERE {$column}=%d AND meta_key=%s LIMIT 2" . ( $lock ? ' FOR UPDATE' : '' ), $id, self::META_ORIGINAL_BIND ) ); return $rows === [ [ 'meta_value' => $json ] ];
	}
	private function authorized( FixedPromotionQuoteBindCommand $command ): bool { try { return true === ( $this->authorize_owner )( $command->owner(), 'delivery_quote.read' ); } catch ( \Throwable ) { return false; } }
	private static function pin( OperationSession $session ): array { $pin = [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ]; DeliveryQuoteSchema::tables( $pin[1] ); DeliveryQuoteSchema::charset_details( $pin[2] ); return $pin; }
	private function assert_owned( OperationSession $session, array $pin, FixedPromotionQuoteBindCommand $command, QuoteSavedOrderAuthorization $authorization, bool $active, bool $retired = false ): void { if ( ! $authorization->unchanged() || $command->owner()->site_id() !== $pin[0] || self::pin( $session ) !== $pin || $session->in_transaction() !== $active || $session->is_retired() !== $retired ) { self::fail(); } }
	private static function hpos(): bool { return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(); }
	private static function fail(): never { throw new \UnexpectedValueException( 'Original promotion placement unavailable.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Saved promotion placement history is private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Saved promotion placement readers require trusted ports.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Saved promotion placement readers require trusted ports.' ); }
	public function __debugInfo(): array { return [ 'private_saved_promotion_placement' => 'redacted' ]; }
}
