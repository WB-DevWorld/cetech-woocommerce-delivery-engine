<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteBindInputs, FixedPromotionQuoteOrderEvidenceGuard, FixedPromotionQuoteSealedEvidence, FixedPromotionQuoteVerifyInputs};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionSealedReadFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionSealedReadFixture.php';

/** Fresh owned observations must fail closed without changing or republishing sealed history. */
final class FixedPromotionQuoteSealedReadOwnershipTest extends TestCase {

	private PromotionSealedReadFixture $f;

	protected function setUp(): void { $this->f = new PromotionSealedReadFixture(); }

	public static function owner_losses(): array {
		$cases = []; $fields = [ 'site', 'prefix', 'charset' ];
		foreach ( [ 'readiness', 'begin', 'control_ready', 'validation', 'receipt', 'control', 'publication', 'clock', 'quote', 'binding', 'rollback', 'retire' ] as $index => $phase ) {
			$field = $fields[$index % 3]; $cases[ $phase . ' loses ' . $field ] = [ $phase, $field ];
		}
		return $cases;
	}

	#[DataProvider( 'owner_losses' )]
	public function test_pinned_site_prefix_and_charset_survive_every_callback_or_withhold_all_observations( string $phase, string $field ): void {
		$reached = false;
		$this->f->on_hook = static function( $session, string $point ) use ( $phase, $field, &$reached ): void {
			if ( $point !== $phase || $reached ) { return; } $reached = true;
			$session->$field = self::foreign_value( $field );
		};
		$before = $this->f->bytes(); $result = $this->f->read();
		self::assertTrue( $reached, 'The selected owner boundary must actually run.' );
		$this->assert_withheld( $result, $before );
	}

	public static function transaction_losses(): array {
		$cases = [];
		foreach ( [ 'control_ready', 'validation', 'receipt', 'control', 'publication', 'clock', 'quote', 'binding' ] as $index => $phase ) {
			$action = 0 === $index % 2 ? 'rollback' : 'retire'; $cases[ $phase . ' ends owner by ' . $action ] = [ $phase, $action ];
		}
		return $cases;
	}

	#[DataProvider( 'transaction_losses' )]
	public function test_current_callbacks_cannot_end_or_retire_the_read_transaction_and_still_return_evidence( string $phase, string $action ): void {
		$reached = false;
		$this->f->on_hook = static function( $session, string $point ) use ( $phase, $action, &$reached ): void {
			if ( $phase !== $point || $reached ) { return; }
			$reached = true; self::assertTrue( $session->inner->in_transaction() ); $session->inner->$action();
		};
		$before = $this->f->bytes(); $result = $this->f->read();
		self::assertTrue( $reached ); $this->assert_withheld( $result, $before );
		self::assertTrue( $this->f->sessions[0]->inner->is_retired() ); self::assertFalse( $this->f->db->pdo->inTransaction() );
	}

	public static function proof_owner_losses(): array {
		$cases = []; $fields = [ 'site', 'prefix', 'charset' ];
		foreach ( [ 'eligibility', 'order', 'saved' ] as $index => $port ) {
			foreach ( [ 1, 2 ] as $call ) {
				$field = $fields[( $index + $call - 1 ) % 3]; $cases[ $port . ' proof ' . $call . ' loses ' . $field ] = [ $port, $call, $field ];
			}
		}
		return $cases;
	}

	#[DataProvider( 'proof_owner_losses' )]
	public function test_initial_and_late_current_proof_ports_cannot_replace_the_pinned_owner( string $port, int $call, string $field ): void {
		$reached = false;
		$this->on_proof( $port, $call, static function( $session ) use ( $field, &$reached ): void { $reached = true; $session->$field = self::foreign_value( $field ); } );
		$before = $this->f->bytes(); $result = $this->f->read(); self::assertTrue( $reached ); $this->assert_withheld( $result, $before );
	}

	public static function proof_transaction_losses(): array {
		$cases = [];
		foreach ( [ 'eligibility', 'order', 'saved' ] as $port ) {
			foreach ( [ 1, 2 ] as $call ) {
				$action = 1 === $call ? 'rollback' : 'retire'; $cases[ $port . ' proof ' . $call . ' ends owner by ' . $action ] = [ $port, $call, $action ];
			}
		}
		return $cases;
	}

	#[DataProvider( 'proof_transaction_losses' )]
	public function test_initial_and_late_current_proof_ports_cannot_end_the_owned_transaction( string $port, int $call, string $action ): void {
		$reached = false;
		$this->on_proof( $port, $call, static function( $session ) use ( $action, &$reached ): void { $reached = true; self::assertTrue( $session->inner->in_transaction() ); $session->inner->$action(); } );
		$before = $this->f->bytes(); $result = $this->f->read(); self::assertTrue( $reached ); $this->assert_withheld( $result, $before );
		self::assertTrue( $this->f->sessions[0]->inner->is_retired() ); self::assertFalse( $this->f->db->pdo->inTransaction() );
	}

	public static function initial_sessions(): array { return [ 'active' => [ 'active' ], 'retired' => [ 'retired' ], 'active throwing metadata' => [ 'active throwing metadata' ] ]; }

	#[DataProvider( 'initial_sessions' )]
	public function test_a_fresh_reader_refuses_an_already_active_or_retired_owner( string $state ): void {
		$this->f->on_open = static function( $session ) use ( $state ): void { if ( str_starts_with( $state, 'active' ) ) { $session->inner->begin(); } else { $session->inner->retire(); } if ( 'active throwing metadata' === $state ) { $session->fail = 'throw:charset_collate'; } };
		$before = $this->f->bytes(); $this->assert_withheld( $this->f->read(), $before );
		self::assertGreaterThanOrEqual( 1, $this->f->sessions[0]->rollback_calls ); self::assertGreaterThanOrEqual( 1, $this->f->sessions[0]->retire_calls ); self::assertFalse( $this->f->db->pdo->inTransaction() );
	}

	public static function authorization_losses(): array {
		return array_map( static fn( string $phase ): array => [ $phase ], [ 'readiness', 'begin', 'control_ready', 'validation', 'receipt', 'control', 'publication', 'clock', 'quote', 'binding', 'rollback', 'retire' ] );
	}

	#[DataProvider( 'authorization_losses' )]
	public function test_authority_lost_during_current_proofs_or_cleanup_never_releases_private_observations( string $phase ): void {
		$reached = false;
		$this->f->on_hook = function( $session, string $point ) use ( $phase, &$reached ): void { if ( $phase === $point ) { $reached = true; $this->f->authorized = false; } };
		$before = $this->f->bytes(); $result = $this->f->read();
		self::assertTrue( $reached ); $this->assert_withheld( $result, $before );
	}

	public static function proof_authority_losses(): array {
		$cases = [];
		foreach ( [ 'eligibility', 'order', 'saved' ] as $port ) { foreach ( [ 1, 2 ] as $call ) { $cases[ $port . ' proof ' . $call ] = [ $port, $call ]; } }
		return $cases;
	}

	#[DataProvider( 'proof_authority_losses' )]
	public function test_authority_lost_in_initial_or_late_proof_callbacks_withholds_observations( string $port, int $call ): void {
		$reached = false;
		$this->on_proof( $port, $call, function() use ( &$reached ): void { $reached = true; $this->f->authorized = false; } );
		$before = $this->f->bytes(); $result = $this->f->read(); self::assertTrue( $reached ); $this->assert_withheld( $result, $before );
	}

	public static function initial_authority(): array { return [ [ false ], [ true ] ]; }

	#[DataProvider( 'initial_authority' )]
	public function test_initial_authority_denial_or_exception_opens_no_sql_owner( bool $throws ): void {
		$this->f->authorized = false;
		if ( $throws ) { $this->f->on_authorize = static function(): void { throw new \RuntimeException( 'Synthetic denied owner authority.' ); }; }
		$before = $this->f->bytes(); $this->assert_withheld( $this->f->read(), $before );
		self::assertSame( [], $this->f->sessions ); self::assertSame( [ 'delivery_quote.read' ], $this->f->auth_operations );
		self::assertSame( [], $this->f->trace->events );
	}

	public function test_an_unauthorized_caller_cannot_invoke_even_the_order_evidence_digest_port(): void {
		$digest_calls = 0; $this->f->authorized = false;
		$guard = new SealedReadFinalAuthorizationOrderGuard( $this->f->order, static function() use ( &$digest_calls ): void { ++$digest_calls; } );
		$this->f->inputs = FixedPromotionQuoteVerifyInputs::from_sources( FixedPromotionQuoteBindInputs::from_sources( $this->f->inputs->current()->sources(), $guard ), $this->f->inputs->saved_evidence() );
		$before = $this->f->bytes(); $this->assert_withheld( $this->f->read(), $before );
		self::assertSame( 0, $digest_calls ); self::assertSame( [], $this->f->sessions ); self::assertSame( [], $this->f->trace->events );
	}

	public static function failed_acknowledgments(): array {
		$cases = [];
		foreach ( [ 'begin', 'validation', 'rollback', 'retire' ] as $phase ) {
			$cases[ $phase . ' false' ] = [ $phase, false ];
			$cases[ $phase . ' throws' ] = [ $phase, true ];
		}
		return $cases;
	}

	#[DataProvider( 'failed_acknowledgments' )]
	public function test_unacknowledged_read_or_cleanup_never_exposes_a_success( string $phase, bool $throws ): void {
		$reached = false;
		$this->f->on_hook = static function( $session, string $point ) use ( $phase, $throws, &$reached ): void { if ( $phase === $point ) { $reached = true; $session->fail = ( $throws ? 'throw:' : '' ) . $phase; } };
		$before = $this->f->bytes(); $result = $this->f->read();
		self::assertTrue( $reached ); $this->assert_withheld( $result, $before );
		self::assertGreaterThanOrEqual( 1, $this->f->sessions[0]->retire_calls ); self::assertFalse( $this->f->db->pdo->inTransaction() );
	}

	public static function failed_metadata(): array {
		$cases = [];
		foreach ( [ 'is_retired', 'in_transaction', 'site_id', 'table_prefix', 'charset_collate' ] as $metadata ) { $cases[ 'open throws ' . $metadata ] = [ 'open', $metadata ]; }
		foreach ( [ 'is_retired', 'in_transaction' ] as $metadata ) { $cases[ 'receipt throws ' . $metadata ] = [ 'receipt', $metadata ]; }
		$cases['rollback throws site_id'] = [ 'rollback', 'site_id' ]; $cases['retire throws charset_collate'] = [ 'retire', 'charset_collate' ];
		return $cases;
	}

	#[DataProvider( 'failed_metadata' )]
	public function test_throwing_owner_metadata_never_suppresses_best_effort_physical_cleanup( string $phase, string $metadata ): void {
		$reached = false;
		$inject = static function( $session ) use ( $metadata, &$reached ): void { $reached = true; $session->fail = 'throw:' . $metadata; };
		if ( 'open' === $phase ) { $this->f->on_open = $inject; }
		else { $this->f->on_hook = static function( $session, string $point ) use ( $phase, $inject ): void { if ( $phase === $point ) { $inject( $session ); } }; }
		$before = $this->f->bytes(); $result = $this->f->read();
		self::assertTrue( $reached ); $this->assert_withheld( $result, $before );
		$session = $this->f->sessions[0];
		self::assertGreaterThanOrEqual( 1, $session->rollback_calls );
		self::assertGreaterThanOrEqual( 1, $session->retire_calls ); self::assertTrue( $session->inner->is_retired() ); self::assertFalse( $this->f->db->pdo->inTransaction() );
	}

	public static function final_binding_losses(): array { return [ [ 'site' ], [ 'prefix' ], [ 'charset' ] ]; }

	#[DataProvider( 'final_binding_losses' )]
	public function test_a_true_final_authorizer_cannot_replace_the_retired_pinned_owner( string $field ): void {
		$reached = false;
		$this->f->on_authorize = static function( $owner, string $operation, PromotionSealedReadFixture $f ) use ( $field, &$reached ): void {
			$session = $f->sessions[0] ?? null; if ( null === $session || ! $session->inner->is_retired() ) { return; }
			$reached = true; $session->$field = self::foreign_value( $field );
		};
		$before = $this->f->bytes(); $result = $this->f->read();
		self::assertTrue( $reached ); self::assertTrue( $this->f->authorized ); $this->assert_withheld( $result, $before );
	}

	public function test_a_final_authorization_exception_withholds_all_observations_after_successful_retirement(): void {
		$reached = false;
		$this->f->on_authorize = static function( $owner, string $operation, PromotionSealedReadFixture $f ) use ( &$reached ): void {
			$session = $f->sessions[0] ?? null; if ( null === $session || ! $session->inner->is_retired() ) { return; }
			$reached = true; throw new \RuntimeException( 'Synthetic final authority unavailable.' );
		};
		$before = $this->f->bytes(); $result = $this->f->read(); self::assertTrue( $reached ); $this->assert_withheld( $result, $before );
	}

	public function test_success_ends_at_the_final_retired_owner_check_without_another_authorizer_or_live_port_callback(): void {
		$retired_authorizations = 0; $after_authorization = [];
		$this->f->on_authorize = static function( $owner, string $operation, PromotionSealedReadFixture $f ) use ( &$retired_authorizations ): void {
			$session = $f->sessions[0] ?? null; if ( null === $session || ! $session->inner->is_retired() ) { return; }
			++$retired_authorizations;
			if ( $retired_authorizations > 1 ) { throw new \LogicException( 'No callback may replace the final retired-owner check.' ); }
		};
		$this->f->on_hook = static function( $session, string $phase ) use ( &$retired_authorizations, &$after_authorization ): void { if ( 1 === $retired_authorizations ) { $after_authorization[] = $phase; } };
		$before = $this->f->bytes(); $result = $this->f->read();
		self::assertInstanceOf( FixedPromotionQuoteSealedEvidence::class, $result ); self::assertSame( 1, $retired_authorizations );
		self::assertNotEmpty( $after_authorization ); self::assertSame( [], array_values( array_diff( $after_authorization, [ 'is_retired', 'in_transaction', 'site_id', 'table_prefix', 'charset_collate' ] ) ) );
		$auth_before = $this->f->auth_operations; $hooks_before = $after_authorization;
		self::assertSame( $this->f->binding()->row(), $result->binding()->row() ); self::assertSame( $this->f->quote()->row(), $result->quote_record()->row() );
		$result->owner(); $result->reference(); $result->header(); $result->context(); $result->terms(); $result->sealed_at(); $result->evaluated_at(); $result->validity(); $result->control_revision();
		self::assertSame( $auth_before, $this->f->auth_operations ); self::assertSame( $hooks_before, $after_authorization ); $this->assert_unchanged( $before );
	}

	public static function final_callback_fences(): array { return [ [ 'clock' ], [ 'authority' ] ]; }

	#[DataProvider( 'final_callback_fences' )]
	public function test_no_order_digest_callback_can_run_after_the_final_fence_and_revoke_the_exposed_observation( string $fence ): void {
		$final_observed = false; $late_callbacks = 0;
		$guard = new SealedReadFinalAuthorizationOrderGuard( $this->f->order, function() use ( &$final_observed, &$late_callbacks ): void { if ( $final_observed ) { ++$late_callbacks; $this->f->authorized = false; } } );
		$this->f->inputs = FixedPromotionQuoteVerifyInputs::from_sources( FixedPromotionQuoteBindInputs::from_sources( $this->f->inputs->current()->sources(), $guard ), $this->f->inputs->saved_evidence() );
		$this->f->on_authorize = static function( $owner, string $operation, PromotionSealedReadFixture $f ) use ( $fence, &$final_observed ): void { $session = $f->sessions[0] ?? null; if ( 'authority' === $fence && null !== $session && $session->inner->is_retired() ) { $final_observed = true; } };
		$this->f->on_hook = static function( $session, string $phase ) use ( $fence, &$final_observed ): void { if ( 'clock' === $fence && 'clock' === $phase && 3 === $session->clock_reads ) { $final_observed = true; } };
		$before = $this->f->bytes(); $result = $this->f->read();
		self::assertTrue( $final_observed ); self::assertInstanceOf( FixedPromotionQuoteSealedEvidence::class, $result ); self::assertSame( 0, $late_callbacks ); self::assertTrue( $this->f->authorized ); $this->assert_unchanged( $before );
	}

	private static function foreign_value( string $field ): string|int { return match ( $field ) { 'site' => 2, 'prefix' => 'foreign_', default => 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' }; }

	private function on_proof( string $port, int $call, \Closure $inject ): void {
		$initial_calls = $this->f->$port->calls;
		$this->f->$port->on_proof = function( ...$arguments ) use ( $port, $call, $initial_calls, $inject ): void {
			if ( $initial_calls + $call !== $this->f->$port->calls ) { return; }
			$inject( $arguments[0] ?? $this->f->sessions[0] );
		};
	}

	private function assert_withheld( ?FixedPromotionQuoteSealedEvidence $result, array $before ): void { self::assertNull( $result ); $this->assert_unchanged( $before ); }

	private function assert_unchanged( array $before ): void {
		self::assertSame( $before, $this->f->bytes() ); self::assertSame( 0, $this->f->budget->writes );
		foreach ( $this->f->sessions as $session ) { self::assertSame( 0, $session->writes ); }
		foreach ( $this->f->auth_operations as $operation ) { self::assertSame( 'delivery_quote.read', $operation ); }
	}
}

/** A digest is an external trusted-port callback even though its output is immutable. */
final readonly class SealedReadFinalAuthorizationOrderGuard implements FixedPromotionQuoteOrderEvidenceGuard {
	public function __construct( private FixedPromotionQuoteOrderEvidenceGuard $inner, private \Closure $on_digest ) {}
	public function evidence_digest(): string { ( $this->on_digest )(); return $this->inner->evidence_digest(); }
	public function tables( OperationSession $session ): array { return $this->inner->tables( $session ); }
	public function verify( OperationSession $session, QuoteStoredRow $quote, QuoteBinding $candidate ): bool { return $this->inner->verify( $session, $quote, $candidate ); }
}
