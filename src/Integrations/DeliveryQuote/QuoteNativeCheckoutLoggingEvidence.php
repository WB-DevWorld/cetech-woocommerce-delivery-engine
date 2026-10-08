<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Integrations\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementSavedEvidenceGuard;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** One observed native Classic logger save; never authority from a metadata value. */
final readonly class QuoteNativeCheckoutLoggingEvidence implements QuotePlacementSavedEvidenceGuard, \JsonSerializable {
	private const KEY = '_debug_log_source';
	private const STEPS = [ 1 => '[Shortcode #1] Place Order flow initiated', 2 => '[Shortcode #2] Session updated with checkout data and totals calculated', 3 => '[Shortcode #3] Checkout posted data validated', 4 => '[Shortcode #4] Validated/Created customer and created order object', 5 => '[Shortcode #5] woocommerce_checkout_order_processed hook ran successfully' ];
	private function __construct( private string $uid, private string $source, private int $start, private int $site, private ?\WC_Order $order = null, private int $phase = 0, private ?array $row = null, private ?string $date = null, private ?EmergencyCheckoutLocalBinding $exact = null, private ?QuoteOrderPayLocalBinding $full = null, private bool $hpos = false, private ?QuotePlacementSavedEvidenceGuard $saved = null ) {}
	/** Called only outside owned SQL, before Classic's created-order stage. */
	public static function begin_classic(): ?self {
		try {
			$state = self::native(); $steps = $state['steps'] ?? null;
			if ( null === $state || $steps !== self::steps( 3 ) ) { return null; }
			return new self( $state['order_uid'], 'place-order-debug-' . $state['order_uid_short'], count( $steps ), $GLOBALS['blog_id'] ?? 0 );
		} catch ( \Throwable ) { return null; }
	}
	/** Observe the completed save itself, before the logger appends its step. */
	public function observe_saved( \WC_Order $order ): ?self {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS | DEBUG_BACKTRACE_PROVIDE_OBJECT, 24 ); $logger = null; $saves = 0; $native_save = false;
		foreach ( $trace as $index => $frame ) {
			if ( in_array( $frame['class'] ?? null, [ 'WC_Order', 'WC_Abstract_Order' ], true ) && 'save' === ( $frame['function'] ?? null ) ) { ++$saves; $native_save = $native_save || defined( 'WC_ABSPATH' ) && realpath( $frame['file'] ?? '' ) === realpath( WC_ABSPATH . 'includes/wc-order-step-logger-functions.php' ); }
			if ( 'wc_log_order_step' === ( $frame['function'] ?? null ) ) { $logger = $index; break; }
		}
		if ( null === $logger ) { return null; }
		$checkout = $trace[$logger + 1] ?? []; $frame = $trace[$logger]; $expected = defined( 'WC_ABSPATH' ) ? realpath( WC_ABSPATH . 'includes/class-wc-checkout.php' ) : false;
		if ( $saves < 1 || $saves > 2 || ! $native_save || false === $expected || realpath( $frame['file'] ?? '' ) !== $expected || ( $checkout['class'] ?? null ) !== 'WC_Checkout' || ( $checkout['function'] ?? null ) !== 'process_checkout' || ! isset( $checkout['object'] ) || get_class( $checkout['object'] ) !== 'WC_Checkout' ) { throw new \RuntimeException( 'Native logger provenance unavailable.' ); }
		$state = self::native(); $steps = $state['steps'] ?? null;
		$phase = $steps === self::steps( 3 ) ? 4 : ( $steps === self::steps( 4 ) ? 5 : 0 );
		if ( 0 === $phase || null === $state || $state['order_uid'] !== $this->uid || ( $state['order'] ?? null ) !== $order || ! in_array( get_class( $order ), [ 'WC_Order', 'Automattic\\WooCommerce\\Admin\\Overrides\\Order' ], true ) ) { throw new \RuntimeException( 'Native logger identity unavailable.' ); }
		$order->get_meta_data(); foreach ( [ 'line_item', 'shipping', 'tax', 'fee', 'coupon' ] as $type ) { foreach ( $order->get_items( $type ) as $item ) { $item->get_meta_data(); } }
		$row = self::row( $order, $this->source );
		$data = self::property( $order, 'data' ); $changes = self::property( $order, 'changes' ); $date = $data['date_modified'] ?? null;
		if ( null === $row || ! is_array( $changes ) || [] !== $changes || ! is_object( $date ) || 'WC_DateTime' !== get_class( $date ) ) { throw new \RuntimeException( 'Native logger completed save unavailable.' ); }
		$exact = EmergencyCheckoutLocalBinding::capture( $order ) ?? throw new \RuntimeException( 'Native logger raw evidence unavailable.' );
		return new self( $this->uid, $this->source, $this->start, $this->site, $order, $phase, $row, gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ), $exact, QuoteOrderPayLocalBinding::capture( $order ), class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
	}
	/** Function statics are inspected outside every owned SQL unit. */
	public function is_current(): bool {
		try { $state = self::native(); $steps = $state['steps'] ?? null; return null !== $this->order && null !== $state && $state['order_uid'] === $this->uid && ( $state['order'] ?? null ) === $this->order && in_array( $this->phase, [ 4, 5 ], true ) && $steps === self::steps( $this->phase ) && $this->raw_unchanged(); } catch ( \Throwable ) { return false; }
	}
	public function phase(): int { return $this->phase; }
	public function raw_unchanged(): bool { return ( $GLOBALS['blog_id'] ?? null ) === $this->site && null !== $this->exact && null !== $this->full && $this->exact->unchanged() && $this->full->unchanged(); }
	public function matches_saved( \WC_Order $saved ): bool { try { return $this->row === self::row( $this->order, $this->source ) && $this->same_row( self::row( $saved, $this->source ) ); } catch ( \Throwable ) { return false; } }
	public function successor_of( self $before ): bool { return 5 === $this->phase && 4 === $before->phase && $this->uid === $before->uid && $this->source === $before->source && $this->order === $before->order && null !== $this->row && null !== $before->row && $this->row['index'] === $before->row['index'] + 1 && $this->row['id'] !== $before->row['id']; }
	/** Exact admitted root row; every other metadata member retains its raw index. */
	public function admitted_index( \WC_Order $order ): int {
		if ( $order !== $this->order || ! $this->raw_unchanged() || self::row( $order, $this->source ) !== $this->row ) { throw new \RuntimeException( 'Native logger raw delta unavailable.' ); }
		return $this->row['index'];
	}
	public function with_saved( QuotePlacementSavedEvidenceGuard $saved ): self { return new self( $this->uid, $this->source, $this->start, $this->site, $this->order, $this->phase, $this->row, $this->date, $this->exact, $this->full, $this->hpos, $saved ); }
	public function tables( OperationSession $session ): array { $p = $session->table_prefix(); return array_values( array_unique( [ ...( null === $this->saved ? [] : $this->saved->tables( $session ) ), ...($this->hpos ? [ $p . 'wc_orders', $p . 'wc_orders_meta' ] : [ $p . 'posts', $p . 'postmeta' ]) ] ) ); }
	/** No function statics, native getters or callbacks are consulted here. */
	public function verify( OperationSession $session, QuoteBinding $binding ): bool {
		try {
			if ( null === $this->row || null === $this->order || ! $this->raw_unchanged() || $session->site_id() !== $this->site || $binding->site_id() !== $this->site || $binding->row()['order_id'] !== self::property( $this->order, 'id' ) || ! $session->in_transaction() || $session->is_retired() || null !== $this->saved && ! $this->saved->verify( $session, $binding ) ) { return false; }
			$p = $session->table_prefix(); $id = $binding->row()['order_id'];
			$meta = $this->hpos ? "SELECT id AS meta_id,meta_key,meta_value FROM `{$p}wc_orders_meta` WHERE order_id=%d" : "SELECT meta_id,meta_key,meta_value FROM `{$p}postmeta` WHERE post_id=%d";
			$rows = $session->get_results( $session->prepare( $meta . " AND meta_key='_debug_log_source' ORDER BY meta_id LIMIT 2 FOR UPDATE", $id ) );
			$date = $this->hpos ? "SELECT date_updated_gmt AS modified FROM `{$p}wc_orders` WHERE id=%d LIMIT 2 FOR UPDATE" : "SELECT post_modified_gmt AS modified FROM `{$p}posts` WHERE ID=%d LIMIT 2 FOR UPDATE";
			$dates = $session->get_results( $session->prepare( $date, $id ) );
			return is_array( $rows ) && count( $rows ) === 1 && array_keys( $rows[0] ) === [ 'meta_id', 'meta_key', 'meta_value' ] && (string) $rows[0]['meta_id'] === (string) $this->row['id'] && $rows[0]['meta_key'] === self::KEY && $rows[0]['meta_value'] === $this->source && $dates === [ [ 'modified' => $this->date ] ] && $session->in_transaction() && ! $session->is_retired() && $this->raw_unchanged();
		} catch ( \Throwable ) { return false; }
	}
	private function same_row( ?array $row ): bool { return null !== $row && null !== $this->row && $row['id'] === $this->row['id'] && $row['value'] === $this->row['value']; }
	private static function steps( int $through ): array { return array_values( array_slice( self::STEPS, 0, $through ) ); }
	private static function row( \WC_Order $order, string $source ): ?array {
		$members = self::property( $order, 'meta_data' ); if ( ! is_array( $members ) || count( $members ) > 512 ) { throw new \RuntimeException( 'Native logger metadata unavailable.' ); } $found = null;
		foreach ( $members as $index => $member ) {
			if ( ! $member instanceof \WC_Meta_Data ) { throw new \RuntimeException( 'Native logger metadata unavailable.' ); }
			$current = self::property( $member, 'current_data' ); $data = self::property( $member, 'data' );
			if ( ( $current['key'] ?? null ) !== self::KEY && ( $data['key'] ?? null ) !== self::KEY ) { continue; }
			if ( null !== $found || get_class( $member ) !== 'WC_Meta_Data' || ! is_int( $index ) || $index < 0 || $data !== $current || ! in_array( array_keys( $current ), [ [ 'key', 'value', 'id' ], [ 'id', 'key', 'value' ] ], true ) || ! is_int( $current['id'] ) || $current['id'] < 1 || $current['key'] !== self::KEY || $current['value'] !== $source ) { throw new \RuntimeException( 'Native logger saved row unavailable.' ); }
			$found = [ 'index' => $index, 'id' => $current['id'], 'value' => $current['value'] ];
		} return $found;
	}
	private static function native(): ?array {
		if ( ! function_exists( 'wc_log_order_step' ) || ! defined( 'WC_ABSPATH' ) ) { return null; }
		$function = new \ReflectionFunction( 'wc_log_order_step' ); $expected = realpath( WC_ABSPATH . 'includes/wc-order-step-logger-functions.php' ); if ( false === $expected || realpath( $function->getFileName() ) !== $expected ) { return null; }
		$state = $function->getStaticVariables(); $uid = $state['order_uid'] ?? null; $short = $state['order_uid_short'] ?? null;
		return true === ( $state['logging_active'] ?? null ) && is_string( $uid ) && 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $uid ) && $short === substr( $uid, 0, 8 ) && is_array( $state['steps'] ?? null ) && count( $state['steps'] ) <= 64 ? $state : null;
	}
	private static function property( object $object, string $name ): mixed { $r = new \ReflectionObject( $object ); $p = $r->getProperty( $name ); if ( ! in_array( $p->getDeclaringClass()->getName(), [ 'WC_Data', 'WC_Abstract_Order', 'WC_Order', 'WC_Meta_Data' ], true ) || $p->isStatic() || ! $p->isInitialized( $object ) ) { throw new \RuntimeException( 'Native logger raw evidence unavailable.' ); } return method_exists( $p, 'getRawValue' ) ? $p->getRawValue( $object ) : $p->getValue( $object ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Native logger evidence is private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Native logger evidence is private.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Native logger evidence is private.' ); }
}
