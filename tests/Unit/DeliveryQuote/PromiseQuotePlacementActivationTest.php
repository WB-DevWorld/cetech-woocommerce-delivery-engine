<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

require_once __DIR__ . '/../../Support/DeliveryQuote/PromiseQuoteActivationFixtures.php';
require_once __DIR__ . '/../../Support/DeliveryQuote/QuoteStorageFixtures.php';
use CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePlacementActivation;
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationSession};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;
use CetechDeliveryEngine\Integrations\ServicePromise\PromiseNativeServiceRegistry;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{PromiseQuoteActivationFactory,QuoteStorageFixtures};
use PHPUnit\Framework\TestCase;

/** Comparative SQLite protected decision/CAS faults; physical source checks have separate native receipts. */
final class PromiseQuotePlacementActivationTest extends TestCase {
	private function registry(): PromiseNativeServiceRegistry { return PromiseNativeServiceRegistry::from_array( [ 'format' => 1, 'entries' => [ [ 'native_service_id' => 1, 'service_kind' => 'built_in', 'service_code' => 'standard', 'origin_endpoint' => 'origin', 'origin_kind' => 'origin', 'destination_endpoint' => 'destination', 'destination_kind' => 'doorstep' ] ] ] ); }
	private function actor( int $site = 1 ): OperationIdentity { return new OperationIdentity( $site, 'service_promise.settings', 'captured-settings-principal', 'service_promise.adoption', 1, 'settings:promise', 'original-settings-request' ); }
	private function activation( PromiseQuoteActivationFactory $f, ?callable $allow = null, ?callable $ready = null ): PromiseQuotePlacementActivation { return new PromiseQuotePlacementActivation( $f, $ready ?? static fn(): bool => true, new class implements OperationReadiness { public function assert_ready( OperationSession $s ): void {} }, $allow ?? static fn(): bool => true ); }
	public function test_original_configuration_acknowledges_off_then_separate_authorized_revision_enables(): void {
		$f = new PromiseQuoteActivationFactory(); $a = $this->activation( $f ); self::assertFalse( $a->active() ); self::assertFalse( $a->requested() ); self::assertNull( $a->configuration() ); self::assertSame( 0, $f->adoption_rows() );
		self::assertTrue( $a->configure( PromiseSiteBinding::bind( 1, 'opaque_site' ), $this->registry(), 0, $this->actor() ) ); self::assertFalse( $a->requested() ); self::assertSame( 1, $a->status()['revision'] ); self::assertTrue( $a->status()['ready'] );
		self::assertFalse( $a->change( true, 0, $this->actor() ) ); self::assertTrue( $a->change( true, 1, $this->actor() ) ); self::assertTrue( $a->active() ); self::assertSame( 'opaque_site', $a->configuration()['binding']->site_key() ); self::assertSame( $this->registry()->private_facts(), $a->configuration()['registry']->private_facts() );
		self::assertTrue( $a->change( false, 2, $this->actor() ) ); self::assertFalse( $a->requested() ); self::assertNull( $a->configuration() ); self::assertSame( 1, $f->adoption_rows() );
	}
	public function test_authority_runs_only_outside_owned_sql_and_foreign_default_denied_cannot_write(): void {
		$f = new PromiseQuoteActivationFactory(); $calls = 0; $a = $this->activation( $f, static function() use ( $f, &$calls ): bool { ++$calls; self::assertFalse( $f->pdo->inTransaction() ); return true; } );
		self::assertTrue( $a->configure( PromiseSiteBinding::bind( 1, 'opaque_site' ), $this->registry(), 0, $this->actor() ) ); self::assertSame( 1, $calls ); self::assertTrue( $a->change( true, 1, $this->actor() ) ); self::assertSame( 2, $calls );
		$before = $a->status(); self::assertFalse( $a->change( false, 2, $this->actor( 2 ) ) ); self::assertSame( $before, $a->status() ); self::assertSame( 2, $calls );
		$f = new PromiseQuoteActivationFactory(); $a = new PromiseQuotePlacementActivation( $f, static fn(): bool => true ); self::assertFalse( $a->configure( PromiseSiteBinding::bind( 1, 'opaque_site' ), $this->registry(), 0, $this->actor() ) ); self::assertSame( 0, $f->opens ); self::assertSame( 0, $f->adoption_rows() );
	}
	public function test_unready_current_flags_unknown_bytes_or_empty_mapping_never_fall_back_or_enable(): void {
		$f = new PromiseQuoteActivationFactory(); $a = $this->activation( $f ); self::assertFalse( $a->configure( PromiseSiteBinding::bind( 1, 'opaque_site' ), PromiseNativeServiceRegistry::from_array( [ 'format' => 1, 'entries' => [] ] ), 0, $this->actor() ) ); self::assertSame( 0, $f->opens );
		self::assertTrue( $a->configure( PromiseSiteBinding::bind( 1, 'opaque_site' ), $this->registry(), 0, $this->actor() ) ); self::assertTrue( $a->change( true, 1, $this->actor() ) ); $f->pdo->exec( "UPDATE activation_options SET option_value='0' WHERE option_name='cetech_de_enable_blocks_adapter'" ); self::assertTrue( $a->requested() ); self::assertFalse( $a->active() ); self::assertFalse( $a->change( true, 2, $this->actor() ) ); self::assertTrue( $a->change( false, 2, $this->actor() ) );
		$f->pdo->exec( "UPDATE activation_options SET option_value='{\"format\":999}' WHERE option_name='" . PromiseQuotePlacementActivation::OPTION . "'" ); self::assertTrue( $a->requested() ); self::assertFalse( $a->active() ); self::assertFalse( $a->status()['available'] );
	}
	public function test_configuration_commit_uncertainty_does_not_acknowledge_enable_or_rewrite_revision(): void {
		$f = new PromiseQuoteActivationFactory(); $a = $this->activation( $f ); $f->fault = OperationCommitResult::Unconfirmed; self::assertFalse( $a->configure( PromiseSiteBinding::bind( 1, 'opaque_site' ), $this->registry(), 0, $this->actor() ) ); self::assertSame( 1, $a->status()['revision'] ); self::assertFalse( $a->requested() ); self::assertFalse( $a->configure( PromiseSiteBinding::bind( 1, 'replacement_site' ), $this->registry(), 0, $this->actor() ) );
		$f = new PromiseQuoteActivationFactory(); $a = $this->activation( $f ); $f->fault = OperationCommitResult::NotSent; self::assertFalse( $a->configure( PromiseSiteBinding::bind( 1, 'opaque_site' ), $this->registry(), 0, $this->actor() ) ); self::assertSame( 0, $f->adoption_rows() ); self::assertFalse( $f->pdo->inTransaction() );
	}
	public function test_original_option_bytes_and_mapping_revision_remain_fenced_in_existing_owner(): void {
		$f = new PromiseQuoteActivationFactory(); $a = $this->activation( $f ); self::assertTrue( $a->configure( PromiseSiteBinding::bind( 1, 'opaque_site' ), $this->registry(), 0, $this->actor() ) ); self::assertTrue( $a->change( true, 1, $this->actor() ) ); $guard = $a->fence(); $b = QuoteStorageFixtures::binding( QuoteStorageFixtures::quote( state: 'accepted' ) ); $s = $f->open(); $s->begin(); self::assertTrue( $guard->verify( $s, $b ) ); $f->pdo->exec( "UPDATE activation_options SET option_value=replace(option_value,'opaque_site','foreign_site') WHERE option_name='" . PromiseQuotePlacementActivation::OPTION . "'" ); self::assertFalse( $guard->verify( $s, $b ) ); $s->retire();
	}
}
