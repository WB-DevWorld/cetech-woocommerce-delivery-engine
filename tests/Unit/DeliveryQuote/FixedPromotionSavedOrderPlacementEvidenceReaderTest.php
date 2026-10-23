<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteBindCommand, FixedPromotionSavedOrderPlacementEvidenceReader};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionNativeUnitFixture, PromotionNativeUnitOrder, PromotionSealedReadFixture};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionNativeUnitFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionSealedReadFixture.php';

/** Actual SQLite producer receipts plus declared native models; no physical Woo checkout acceptance. */
final class FixedPromotionSavedOrderPlacementEvidenceReaderTest extends TestCase {

	private array $globals = [];
	protected function setUp(): void { foreach ( [ 'current_user', 'blog_id', 'woocommerce', '_GET', '_POST' ] as $key ) { $this->globals[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; } }
	protected function tearDown(): void { foreach ( $this->globals as $key => [ $present, $value ] ) { if ( $present ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } } }
	private function fixture(): array {
		$history = new PromotionSealedReadFixture(); $native = PromotionNativeUnitFixture::create(); $bind = $history->command->verification()->binding_command();
		$order = new class( $native['fixture'], $bind->binding()->row()['order_id'] ) extends PromotionNativeUnitOrder { public function __construct( array $fixture, int $id ) { parent::__construct( $fixture ); $this->id = $id; } };
		$order->meta[FixedPromotionSavedOrderPlacementEvidenceReader::META_ORIGINAL_BIND] = FixedPromotionSavedOrderPlacementEvidenceReader::encode( $bind );
		$factory = new PromotionSavedHistoryTestFactory( $history, $order );
		$readiness = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} };
		$reader = new FixedPromotionSavedOrderPlacementEvidenceReader( $factory, static fn( \WC_Order $candidate ): bool => $candidate === $order, static fn(): bool => true, $readiness );
		return compact( 'history', 'native', 'bind', 'order', 'factory', 'reader' );
	}
	public function test_fresh_reader_authenticates_all_original_commands_from_one_immutable_bind_packet(): void {
		$f = $this->fixture(); $before = $f['history']->bytes(); $read = $f['reader']->read( $f['order'], RequestContext::create() );
		self::assertNotNull( $read ); self::assertSame( [ 'bind', 'verify', 'seal', 'quote', 'binding' ], array_keys( $read ) );
		self::assertSame( $f['history']->command->to_recovery_array(), $read['seal']->to_recovery_array() ); self::assertSame( 3, $read['binding']->revision() );
		self::assertSame( $before, $f['history']->bytes() ); self::assertSame( 0, $f['factory']->writes );
		foreach ( $f['factory']->sessions as $session ) { self::assertTrue( $session->is_retired() ); self::assertFalse( $session->in_transaction() ); }
	}
	public static function producers(): array { return [ [ 1 ], [ 2 ], [ 3 ], [ 4 ], [ 5 ] ]; }
	#[DataProvider( 'producers' )]
	public function test_a_missing_original_producer_never_becomes_authority_from_the_later_binding( int $id ): void {
		$f = $this->fixture(); $f['history']->db->pdo->exec( 'DELETE FROM durable_delivery_engine_operation_records WHERE id=' . $id );
		self::assertNull( $f['reader']->read( $f['order'], RequestContext::create() ) ); self::assertSame( 0, $f['factory']->writes );
	}
	public function test_exact_native_authorization_precedes_private_meta_and_sql(): void {
		$f = $this->fixture(); $before = $f['order']->gets;
		$reader = new FixedPromotionSavedOrderPlacementEvidenceReader( $f['factory'], static fn(): bool => false, static fn(): bool => true );
		self::assertNull( $reader->read( $f['order'], RequestContext::create() ) ); self::assertSame( $before, $f['order']->gets ); self::assertSame( 0, $f['factory']->opens );
	}
	public function test_packet_is_the_existing_bounded_canonical_command_and_rejects_duplicates_or_unknown_fields(): void {
		$f = $this->fixture(); $raw = FixedPromotionSavedOrderPlacementEvidenceReader::encode( $f['bind'] );
		self::assertSame( $f['bind']->to_recovery_array(), FixedPromotionSavedOrderPlacementEvidenceReader::decode( $raw )->to_recovery_array() );
		foreach ( [ '{"format_version":1,' . substr( $raw, 1 ), substr( $raw, 0, -1 ) . ',"future_phase":true}', str_repeat( 'x', FixedPromotionQuoteBindCommand::MAX_RECOVERY_BYTES + 1 ) ] as $broken ) {
			try { FixedPromotionSavedOrderPlacementEvidenceReader::decode( $broken ); self::fail( 'An ambiguous original command was accepted.' ); } catch ( \Throwable $error ) { self::assertNotInstanceOf( \PHPUnit\Framework\AssertionFailedError::class, $error ); }
		}
	}
	public function test_original_packet_is_written_once_acknowledged_and_never_replaced_by_another_placement(): void {
		$n = PromotionNativeUnitFixture::create(); $bind = FixedPromotionQuoteBindCommand::create( $n['fixture']['acceptance'], $n['fixture']['quote'], 100, QuoteId::generate(), $n['mapping'], $n['guard']->evidence_digest() );
		$factory = new PromotionSavedHistoryTestFactory( $n['factory'], $n['order'] ); $reader = new FixedPromotionSavedOrderPlacementEvidenceReader( $factory, static fn(): bool => true, static fn(): bool => true );
		$reader->preserve( $n['order'], $bind ); $raw = $n['order']->meta[FixedPromotionSavedOrderPlacementEvidenceReader::META_ORIGINAL_BIND]; $saves = $n['order']->saves;
		self::assertSame( $bind->to_recovery_array(), $reader->original( $n['order'] )?->to_recovery_array() );
		$reader->preserve( $n['order'], $bind ); self::assertSame( $saves, $n['order']->saves ); self::assertSame( $raw, FixedPromotionSavedOrderPlacementEvidenceReader::encode( $bind ) );
		$other = FixedPromotionQuoteBindCommand::create( $bind->acceptance(), $bind->quote_record(), 100, QuoteId::generate(), $n['mapping'], $bind->order_evidence_digest() );
		try { $reader->preserve( $n['order'], $other ); self::fail( 'A different original placement replaced the packet.' ); } catch ( \UnexpectedValueException ) {}
		self::assertSame( $raw, $n['order']->meta[FixedPromotionSavedOrderPlacementEvidenceReader::META_ORIGINAL_BIND] ); self::assertSame( 0, $factory->writes );
	}
	public function test_a_later_accepted_producer_prevents_mutable_binding_rollback_from_becoming_original_history(): void {
		foreach ( [ 1, 2 ] as $revision ) {
			$f = $this->fixture(); $binding = 1 === $revision ? $f['history']->command->verification()->prepared_binding() : $f['history']->command->verified_binding(); $row = $binding->row();
			$columns = array_keys( $row ); $f['history']->db->pdo->prepare( 'UPDATE durable_delivery_engine_delivery_quote_bindings SET ' . implode( ',', array_map( static fn( string $key ): string => $key . '=?', $columns ) ) )->execute( array_values( $row ) );
			self::assertNull( $f['reader']->read( $f['order'], RequestContext::create() ) ); self::assertSame( 0, $f['factory']->writes );
		}
	}
	public function test_corrupt_packet_only_presence_is_owned_with_no_runtime_injection_or_adoption(): void {
		$n = PromotionNativeUnitFixture::create(); self::assertFalse( \CetechDeliveryEngine\Application\Order\QuoteNativeOrderHistory::owned( $n['order'] ) );
		$n['order']->meta[FixedPromotionSavedOrderPlacementEvidenceReader::META_ORIGINAL_BIND] = null;
		self::assertTrue( \CetechDeliveryEngine\Application\Order\QuoteNativeOrderHistory::owned( $n['order'] ) ); self::assertTrue( \CetechDeliveryEngine\Application\Order\QuoteNativeOrderHistory::attempted( $n['order'] ) ); self::assertSame( 0, $n['order']->saves );
	}
}

final class PromotionSavedHistoryTestFactory implements OperationConnectionFactory {
	public int $opens = 0; public int $writes = 0; public array $sessions = [];
	public function __construct( private OperationConnectionFactory $inner, public \WC_Order $order ) {}
	public function open(): OperationSession { ++$this->opens; return $this->sessions[] = new PromotionSavedHistoryTestSession( $this->inner->open(), $this ); }
}
final class PromotionSavedHistoryTestSession implements OperationSession {
	public function __construct( private OperationSession $inner, private PromotionSavedHistoryTestFactory $factory ) {}
	public function site_id(): int { return $this->inner->site_id(); } public function table_prefix(): string { return $this->inner->table_prefix(); } public function charset_collate(): string { return $this->inner->charset_collate(); }
	public function begin(): bool { return $this->inner->begin(); } public function rollback(): bool { return $this->inner->rollback(); } public function retire(): bool { return $this->inner->retire(); } public function is_retired(): bool { return $this->inner->is_retired(); } public function in_transaction(): bool { return $this->inner->in_transaction(); }
	public function commit(): OperationCommitResult { throw new \LogicException( 'History reader cannot commit.' ); } public function query( string $sql ): int|false { ++$this->factory->writes; throw new \LogicException( 'History reader cannot mutate.' ); }
	public function validate_tables( array $tables ): bool { return true; } public function errno(): int { return $this->inner->errno(); } public function insert_id(): int { return $this->inner->insert_id(); } public function prepare( string $sql, mixed ...$args ): string { return $this->inner->prepare( $sql, ...$args ); }
	public function get_row( string $sql ): array|null|false { return $this->inner->get_row( $sql ); }
	public function get_results( string $sql ): array|false { if ( str_starts_with( $sql, 'SELECT meta_value FROM ' ) ) { return [ [ 'meta_value' => $this->factory->order->meta[FixedPromotionSavedOrderPlacementEvidenceReader::META_ORIGINAL_BIND] ?? '' ] ]; } return $this->inner->get_results( $sql ); }
}
