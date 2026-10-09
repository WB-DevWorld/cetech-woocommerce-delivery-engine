<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementNoEffectEvidenceGuard;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Application\Operation\DatabaseOperationReadiness;
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope,OrderDeliverySnapshot,OrderDeliverySnapshotJson,QuoteNativeOrderFacts,QuoteNativeOrderHistory,QuoteNativeOrderStageResult,QuoteNativeOrderStager};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};

/** Fixed native facts for abandoning a conclusively rejected original; never payment authority. */
final readonly class QuotePlacementNoEffectNativeGuard implements QuotePlacementNoEffectEvidenceGuard, \JsonSerializable {
	private function __construct( private int $site, private int $id, private bool $hpos, private array $quote_row, private ?array $binding_row, private EmergencyCheckoutLocalBinding $local, private QuoteOrderPayLocalBinding $full, private ?QuoteNativeOrderStageResult $staged, private array $physical ) {}
	/** Native getters and readback occur only before the owned disposition unit. */
	public static function capture( OperationConnectionFactory $factory, QuoteNativeOrderStager $stager, \WC_Order $order, QuoteStoredRow $quote, ?QuoteBinding $binding ): ?self {
		$session = null; $begun = false; $retired = false;
		try {
			$site = get_current_blog_id(); $id = $order->get_id();
			if ( $site !== $quote->site_id() || $id < 1 || null !== $binding && ( $binding->site_id() !== $site || $binding->row()['order_id'] !== $id || $binding->row()['quote_uuid'] !== $quote->header()->id()->value() || 'prepared' !== $binding->state() || 1 !== $binding->revision() ) ) { return null; }
			$order->get_meta_data(); foreach ( [ 'line_item', 'shipping', 'tax', 'fee', 'coupon' ] as $type ) { foreach ( $order->get_items( $type ) as $item ) { $item->get_meta_data(); } }
			$owned = QuoteNativeOrderHistory::owned( $order );
			if ( null === $binding ? $owned : ! $owned || ! QuoteNativeOrderHistory::verify( $order ) ) { return null; }
			$local = EmergencyCheckoutLocalBinding::capture( $order ); if ( null === $local ) { return null; }
			$full = QuoteOrderPayLocalBinding::capture( $order );
			$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			$staged = null === $binding ? null : $stager->saved_guard( $order, $quote, $binding );
			if ( ! $local->unchanged() || ! $full->unchanged() ) { return null; }
			if ( null !== $staged ) { return new self( $site, $id, $hpos, $quote->row(), $binding->row(), $local, $full, $staged, [] ); }
			$session = $factory->open(); ( new DatabaseOperationReadiness() )->assert_ready( $session );
			if ( $session->site_id() !== $site || $session->in_transaction() || $session->is_retired() || ! $session->begin() ) { return null; } $begun = true;
			if ( ! $session->validate_tables( self::native_tables( $session, $hpos ) ) || ! $local->unchanged() || ! $full->unchanged() ) { return null; }
			$physical = QuoteNativeOrderStageResult::read_physical( $session, $id, $hpos );
			if ( ! self::ownership_absent( $physical ) || ! $local->unchanged() || ! $full->unchanged() || ! $session->rollback() ) { return null; } $begun = false;
			if ( ! $session->retire() ) { return null; } $retired = true;
			return $local->unchanged() && $full->unchanged() ? new self( $site, $id, $hpos, $quote->row(), null, $local, $full, null, $physical ) : null;
		} catch ( \Throwable ) { return null; }
		finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } if ( ! $retired && ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} } } }
	}
	public function site_id(): int { return $this->site; }
	public function order_id(): int { return $this->id; }
	public function tables( OperationSession $session ): array { return self::native_tables( $session, $this->hpos ); }
	public function unchanged(): bool { return ( $GLOBALS['blog_id'] ?? null ) === $this->site && $this->local->unchanged() && $this->full->unchanged(); }
	/** Only owned SQL and raw prewarmed comparisons; no native getter or callback. */
	public function verify( OperationSession $session, QuoteStoredRow $original, ?QuoteBinding $expected ): bool {
		try {
			if ( ! $this->unchanged() || $session->site_id() !== $this->site || ! $session->in_transaction() || $session->is_retired() || $original->row() !== $this->quote_row || $expected?->row() !== $this->binding_row ) { return false; }
			$valid = null === $expected ? null === $this->staged && self::ownership_absent( $this->physical ) && $this->physical === QuoteNativeOrderStageResult::read_physical( $session, $this->id, $this->hpos, true ) : null !== $this->staged && $this->staged->verify_unconfirmed_placement( $session, $expected );
			return $valid && $this->unchanged() && $session->in_transaction() && ! $session->is_retired();
		} catch ( \Throwable ) { return false; }
	}
	/** Loaded absence is insufficient: the original physical rows must also be unowned. */
	private static function ownership_absent( array $physical ): bool {
		foreach ( array_merge( $physical['order_meta'], $physical['item_meta'] ) as $row ) {
			$key = $row['meta_key']; $raw = $row['meta_value'];
			if ( DeliveryQuoteSnapshotEnvelope::META_FORMAT === $key || in_array( $key, [ QuoteNativeOrderFacts::META_DRAFT, QuoteNativeOrderFacts::META_REFERENCE, QuoteNativeOrderFacts::META_TAX_SOURCE ], true ) || str_starts_with( $key, '_cetech_de_quote_' ) && QuoteNativeOrderFacts::META_LINE_KEY !== $key ) { return false; }
			if ( ! in_array( $key, [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, OrderDeliverySnapshot::META_LINE_SNAPSHOT ], true ) || ! is_string( $raw ) || '' === $raw ) { continue; }
			if ( strlen( $raw ) > 4 * 1024 * 1024 ) { return false; }
			try { $decoded = json_decode( $raw, true, OrderDeliverySnapshotJson::MAX_DEPTH, JSON_THROW_ON_ERROR ); if ( is_array( $decoded ) && array_key_exists( DeliveryQuoteSnapshotEnvelope::MEMBER, $decoded ) ) { return false; } } catch ( \Throwable ) {}
			$names = preg_replace_callback( '/\\\\u00([0-7][0-9a-fA-F])/', static fn( array $match ): string => chr( hexdec( $match[1] ) ), $raw );
			if ( null === $names || str_contains( $names, DeliveryQuoteSnapshotEnvelope::MEMBER ) ) { return false; }
		}
		return true;
	}
	private static function native_tables( OperationSession $session, bool $hpos ): array { $p = $session->table_prefix(); return array_merge( [ $p . 'woocommerce_order_items', $p . 'woocommerce_order_itemmeta' ], $hpos ? [ $p . 'wc_orders', $p . 'wc_orders_meta', $p . 'wc_order_addresses', $p . 'wc_order_operational_data' ] : [ $p . 'posts', $p . 'postmeta' ] ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Native no-effect evidence is private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Native no-effect evidence is private.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Native no-effect evidence is private.' ); }
}
