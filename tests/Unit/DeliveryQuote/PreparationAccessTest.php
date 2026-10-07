<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

require_once __DIR__ . '/../../Support/DeliveryQuote/QuoteFixtures.php';
require_once __DIR__ . '/../../Support/DeliveryQuote/LegacyQuoteProviderFixtures.php';

use CetechDeliveryEngine\Application\DeliveryQuote\{LegacyQuoteProviderStack,NativeQuotePreparationAccess,QuoteNativeCaptureSource,QuoteNativeReceiptCapture,QuoteNativeState};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures as F,QuoteFixtures};
use PHPUnit\Framework\TestCase;

/** Tests the actual C07 read-owner protocol with a symbolic current-store fixture. */
final class PreparationAccessTest extends TestCase {
	private function access( PreparationUnitFactory $factory, PreparationUnitStore $store, ?callable $owner = null ): NativeQuotePreparationAccess { return new NativeQuotePreparationAccess( $factory, $store, $owner ?? static fn(): QuoteOwner => QuoteFixtures::owner() ); }
	private function enabled( int $revision = 3 ): EmergencyControlState { return EmergencyControlState::from_physical( 1, 7, EmergencyControlState::record_json( 1, 'enabled', $revision, 'resume_verified', 7, 1791300000 ) ); }
	public function test_enabled_observation_and_confirm_release_fresh_owners_without_writes(): void {
		$factory = new PreparationUnitFactory(); $store = new PreparationUnitStore( $this->enabled() ); $owner_reads = 0;
		$owner = static function() use ( $factory, &$owner_reads ): QuoteOwner { ++$owner_reads; foreach ( $factory->sessions as $session ) { if ( $session->in_transaction() ) { throw new \LogicException( 'Native owner getter ran under a control lock.' ); } } return QuoteFixtures::owner(); };
		$access = $this->access( $factory, $store, $owner );
		self::assertSame( 3, $access->observe( QuoteFixtures::owner() ) ); self::assertTrue( $access->confirm( QuoteFixtures::owner(), 3 ) ); self::assertCount( 2, $factory->sessions );
		foreach ( $factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); self::assertFalse( $session->in_transaction() ); self::assertSame( 1, $session->rollbacks ); self::assertSame( 0, $session->writes ); }
		self::assertSame( 4, $owner_reads );
	}
	public function test_paused_access_refuses_before_source_and_native_preparation(): void {
		$state = EmergencyControlState::from_physical( 1, 7, EmergencyControlState::record_json( 1, 'checkout_suspended', 2, 'operator_pause', 7, 1791300000 ) );
		$factory = new PreparationUnitFactory(); $store = new PreparationUnitStore( $state );
		$source = new class implements QuoteNativeCaptureSource { public int $captures = 0; public function current_owner(): QuoteOwner { return QuoteFixtures::owner(); } public function capture(): QuoteNativeState { ++$this->captures; throw new \LogicException( 'Unexpected capture.' ); } public function unchanged(): bool { return true; } };
		$stack = new LegacyQuoteProviderStack( $factory, new QuoteNativeReceiptCapture( $source ), access: $this->access( $factory, $store ) );
		try { $stack->prepare_current( QuoteFixtures::owner(), F::context(), [] ); self::fail( 'Paused preparation was allowed.' ); } catch ( \RuntimeException $error ) { self::assertSame( 'Delivery quote preparation unavailable.', $error->getMessage() ); }
		self::assertCount( 1, $factory->sessions ); self::assertTrue( $factory->sessions[0]->is_retired() ); self::assertSame( 1, $store->reads ); self::assertSame( 0, $source->captures );
	}
	public function test_unknown_control_never_becomes_enabled(): void {
		$factory = new PreparationUnitFactory(); $store = new PreparationUnitStore( null );
		self::assertNull( $this->access( $factory, $store )->observe( QuoteFixtures::owner() ) ); self::assertCount( 1, $factory->sessions ); self::assertTrue( $factory->sessions[0]->is_retired() ); self::assertSame( 1, $factory->sessions[0]->rollbacks );
	}
	public function test_changed_revision_after_preparation_refuses_even_when_enabled_again(): void {
		$factory = new PreparationUnitFactory(); $store = new PreparationUnitStore( $this->enabled( 3 ) ); $access = $this->access( $factory, $store ); $opened = $access->observe( QuoteFixtures::owner() );
		$store->state = $this->enabled( 5 ); self::assertSame( 3, $opened ); self::assertFalse( $access->confirm( QuoteFixtures::owner(), $opened ) ); self::assertCount( 2, $factory->sessions ); self::assertTrue( $factory->sessions[1]->is_retired() );
	}
	public function test_owner_mismatch_is_refused_before_opening_a_control_owner(): void {
		$factory = new PreparationUnitFactory(); $data = QuoteFixtures::owner()->facts(); $data['session_hash'] = QuoteFixtures::digest( 'different_session' );
		self::assertNull( $this->access( $factory, new PreparationUnitStore( $this->enabled() ), static fn(): QuoteOwner => QuoteOwner::from_array( $data ) )->observe( QuoteFixtures::owner() ) ); self::assertSame( [], $factory->sessions );
	}
	public function test_owner_revocation_at_retirement_hides_the_observation(): void {
		$factory = new PreparationUnitFactory(); $allowed = true; $factory->after_retire = static function() use ( &$allowed ): void { $allowed = false; };
		$owner = static function() use ( &$allowed ): QuoteOwner { if ( ! $allowed ) { throw new \RuntimeException( 'Current owner unavailable.' ); } return QuoteFixtures::owner(); };
		self::assertNull( $this->access( $factory, new PreparationUnitStore( $this->enabled() ), $owner )->observe( QuoteFixtures::owner() ) ); self::assertCount( 1, $factory->sessions ); self::assertTrue( $factory->sessions[0]->is_retired() );
	}
	public function test_unknown_rollback_or_retirement_never_authorizes_preparation(): void {
		foreach ( [ 'rollback', 'retire' ] as $failure ) { $factory = new PreparationUnitFactory(); $factory->fail = $failure; self::assertNull( $this->access( $factory, new PreparationUnitStore( $this->enabled() ) )->observe( QuoteFixtures::owner() ) ); self::assertCount( 1, $factory->sessions ); self::assertSame( 0, $factory->sessions[0]->writes ); }
	}
}

final class PreparationUnitStore extends EmergencyControlStore {
	public int $reads = 0;
	public function __construct( public ?EmergencyControlState $state ) {}
	public function assert_standard_wordpress_route( OperationSession $session ): void {}
	public function assert_ready( OperationSession $session, int $site ): void { if ( ! $session->in_transaction() || $site !== $session->site_id() ) { throw new \RuntimeException( 'Invalid owner.' ); } }
	public function current( OperationSession $session ): EmergencyControlState { ++$this->reads; if ( ! $session->in_transaction() || null === $this->state ) { throw new \RuntimeException( 'Current state unavailable.' ); } return $this->state; }
}
final class PreparationUnitFactory implements OperationConnectionFactory {
	public array $sessions = []; public ?\Closure $after_retire = null; public ?string $fail = null;
	public function open(): OperationSession { $session = new PreparationUnitSession( $this ); $this->sessions[] = $session; return $session; }
}
final class PreparationUnitSession implements OperationSession {
	private bool $owned = false; private bool $retired = false; public int $rollbacks = 0; public int $writes = 0;
	public function __construct( private PreparationUnitFactory $factory ) {}
	public function site_id(): int { return 1; } public function table_prefix(): string { return 'unit_'; } public function charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
	public function begin(): bool { $this->owned = true; return true; } public function commit(): OperationCommitResult { ++$this->writes; return OperationCommitResult::NotSent; }
	public function rollback(): bool { ++$this->rollbacks; $this->owned = false; return 'rollback' !== $this->factory->fail; }
	public function retire(): bool { $this->retired = true; if ( null !== $this->factory->after_retire ) { ( $this->factory->after_retire )(); } return 'retire' !== $this->factory->fail; }
	public function is_retired(): bool { return $this->retired; } public function in_transaction(): bool { return $this->owned; } public function validate_tables( array $table_names ): bool { return true; }
	public function query( string $sql ): int|false { ++$this->writes; throw new \LogicException( 'Unexpected DML.' ); } public function get_row( string $sql ): array|null|false { throw new \LogicException( 'Unexpected query.' ); } public function get_results( string $sql ): array|false { throw new \LogicException( 'Unexpected query.' ); }
	public function prepare( string $sql, mixed ...$args ): string { throw new \LogicException( 'Unexpected query.' ); } public function errno(): int { return 0; } public function insert_id(): int { return 0; }
}
