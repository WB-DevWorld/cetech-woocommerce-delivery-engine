<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

require_once __DIR__ . '/../../Support/DeliveryQuote/QuoteStorageFixtures.php';
require_once __DIR__ . '/../../Support/DeliveryQuote/QuoteActivationFixtures.php';
use CetechDeliveryEngine\Application\DeliveryQuote\{QuotePlacementActivation,QuotePlacementCompositeGuard,QuotePlacementPolicyFence,QuotePlacementSavedEvidenceGuard};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteJson};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteStorageFixtures,QuoteActivationFactory,QuoteActivationGuard};
use PHPUnit\Framework\TestCase;

/** Owned SQLite/CAS/fault model; physical MariaDB locking belongs to required native qualification. */
final class QuotePlacementActivationTest extends TestCase {
	private function activation( QuoteActivationFactory $factory, ?callable $ready = null ): QuotePlacementActivation { $readiness = new class implements OperationReadiness { public function assert_ready( OperationSession $s ): void {} }; return new QuotePlacementActivation( $factory, $ready ?? static fn(): bool => true, $readiness ); }
	public function test_default_off_then_approved_adoption_round_trips_canonical_bytes_and_rejects_stale_form(): void {
		$f = new QuoteActivationFactory(); $activation = $this->activation( $f ); $before = $activation->status(); self::assertTrue( $before['ready'] ); self::assertFalse( $before['enabled'] ); self::assertFalse( $before['active'] ); self::assertSame( 0, $before['revision'] );
		self::assertTrue( $activation->change( true, 0 ) ); $state = $activation->status(); self::assertTrue( $state['active'] ); self::assertSame( 1, $state['revision'] ); $bytes = $f->pdo->query( "SELECT option_value FROM activation_options WHERE option_name='" . QuotePlacementActivation::OPTION . "'" )->fetchColumn(); self::assertSame( QuoteJson::encode( [ 'format' => 1, 'profile' => 'legacy_fixed_base_v1', 'enabled' => true, 'revision' => 1 ] ), $bytes ); self::assertTrue( QuotePlacementActivation::decode( $bytes )['enabled'] );
		self::assertFalse( $activation->change( false, 0 ) ); self::assertTrue( $activation->status()['active'] ); self::assertTrue( $activation->change( false, 1 ) ); self::assertFalse( $activation->status()['active'] ); self::assertSame( 2, $activation->status()['revision'] ); self::assertFalse( $f->pdo->inTransaction() );
	}
	public function test_partial_upstream_flags_and_unavailable_prerequisites_never_enable_any_adoption_row(): void {
		$f = new QuoteActivationFactory(); $f->pdo->exec( "DELETE FROM activation_options WHERE option_name='cetech_de_enable_blocks_adapter'" ); $activation = $this->activation( $f ); self::assertFalse( $activation->status()['ready'] ); self::assertFalse( $activation->change( true, 0 ) ); self::assertSame( 0, $f->adoption_rows() );
		$f = new QuoteActivationFactory(); $activation = $this->activation( $f, static fn(): bool => false ); self::assertFalse( $activation->change( true, 0 ) ); self::assertSame( 0, $f->opens ); self::assertFalse( $activation->status()['ready'] ); self::assertSame( 0, $f->adoption_rows() );
		$activation = $this->activation( $f, static function(): bool { throw new \RuntimeException( 'Unavailable readiness.' ); } ); self::assertFalse( $activation->change( true, 0 ) ); self::assertFalse( $activation->status()['available'] && $activation->status()['ready'] );
	}
	public function test_lost_commit_ack_is_not_reported_success_and_unsent_commit_is_rolled_back(): void {
		$f = new QuoteActivationFactory(); $activation = $this->activation( $f ); $f->fault = OperationCommitResult::Unconfirmed; self::assertFalse( $activation->change( true, 0 ) ); self::assertTrue( $activation->status()['active'] ); self::assertSame( 1, $f->adoption_rows() ); self::assertFalse( $activation->change( true, 0 ) );
		$f = new QuoteActivationFactory(); $activation = $this->activation( $f ); $f->fault = OperationCommitResult::NotSent; self::assertFalse( $activation->change( true, 0 ) ); self::assertFalse( $activation->status()['active'] ); self::assertSame( 0, $f->adoption_rows() ); self::assertFalse( $f->pdo->inTransaction() );
	}
	public function test_refused_write_and_unknown_option_format_preserve_default_off(): void {
		$f = new QuoteActivationFactory(); $f->refuse_write = true; $activation = $this->activation( $f ); self::assertFalse( $activation->change( true, 0 ) ); self::assertSame( 0, $f->adoption_rows() );
		$f->pdo->exec( "INSERT INTO activation_options(option_name,option_value,autoload) VALUES('" . QuotePlacementActivation::OPTION . "','{\"format\":999}','no')" ); $state = $activation->status(); self::assertFalse( $state['available'] ); self::assertFalse( $state['active'] );
	}
	public function test_revision_exhaustion_cannot_acknowledge_an_unreadable_policy(): void {
		$f = new QuoteActivationFactory(); $activation = $this->activation( $f );
		$state = QuoteJson::encode( [ 'format' => 1, 'profile' => QuotePlacementActivation::PROFILE, 'enabled' => true, 'revision' => PHP_INT_MAX - 1 ] );
		$f->pdo->prepare( "INSERT INTO activation_options(option_name,option_value,autoload) VALUES(?,?,'no')" )->execute( [ QuotePlacementActivation::OPTION, $state ] );
		self::assertTrue( $activation->status()['active'] );
		$opens = $f->opens;
		self::assertFalse( $activation->change( false, PHP_INT_MAX - 1 ) );
		self::assertSame( $opens, $f->opens );
		self::assertSame( $state, $f->pdo->query( "SELECT option_value FROM activation_options WHERE option_name='" . QuotePlacementActivation::OPTION . "'" )->fetchColumn() );
		self::assertTrue( $activation->status()['available'] ); self::assertTrue( $activation->requested() );
	}
	public function test_final_policy_fence_detects_current_flag_and_revision_changes_and_checks_same_site(): void {
		$f = new QuoteActivationFactory(); $activation = $this->activation( $f ); self::assertTrue( $activation->change( true, 0 ) ); $fence = $activation->fence(); $binding = QuoteStorageFixtures::binding( QuoteStorageFixtures::quote( state: 'accepted' ) ); $session = $f->open(); $session->begin(); self::assertTrue( $fence->verify( $session, $binding ) ); self::assertStringEndsWith( ' FOR UPDATE', end( $f->sql ) );
		$f->pdo->exec( "UPDATE activation_options SET option_value='0' WHERE option_name='cetech_de_enable_blocks_adapter'" ); self::assertFalse( $fence->verify( $session, $binding ) ); $session->rollback(); $session->retire();
		$f->pdo->exec( "UPDATE activation_options SET option_value='1' WHERE option_name='cetech_de_enable_blocks_adapter'" ); self::assertTrue( $activation->change( true, 1 ) ); $session = $f->open(); $session->begin(); self::assertFalse( $fence->verify( $session, $binding ) ); $session->retire();
		$f->wrong_site = true; $session = $f->open(); $session->begin(); self::assertFalse( $fence->verify( $session, $binding ) ); $session->retire();
	}
	public function test_composite_policy_refusal_prevents_native_guard_and_deduplicates_same_owned_tables(): void {
		$policy = new QuoteActivationGuard( false ); $native = new QuoteActivationGuard( true ); $composite = new QuotePlacementCompositeGuard( $native, $policy ); $f = new QuoteActivationFactory(); $session = $f->open(); $session->begin(); self::assertSame( [ 'activation_options' ], $composite->tables( $session ) ); self::assertFalse( $composite->verify( $session, QuoteStorageFixtures::binding( QuoteStorageFixtures::quote( state: 'accepted' ) ) ) ); self::assertSame( 0, $native->calls ); $policy->allowed = true; self::assertTrue( $composite->verify( $session, QuoteStorageFixtures::binding( QuoteStorageFixtures::quote( state: 'accepted' ) ) ) ); self::assertSame( 1, $native->calls ); $session->retire();
	}
}
