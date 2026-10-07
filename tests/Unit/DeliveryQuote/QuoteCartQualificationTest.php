<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/scripts/qualification/opening-quote-cart-support.php';

/** Fixture protocol only; actual WordPress/SQL/browser results remain separate. */
final class QuoteCartQualificationTest extends TestCase {
	private function factory(): \CetechQuoteCartFactory { return ( new \ReflectionClass( \CetechQuoteCartFactory::class ) )->newInstanceWithoutConstructor(); }
	public function test_lost_ack_seam_masks_only_after_actual_quote_effect_and_native_commit_acknowledgement(): void {
		$factory = $this->factory(); $factory->mask_next_quote_ack = true; $native = new QuoteCartFixtureNativeTransport(); $transport = new \CetechQuoteCartTransport( $native, $factory, 'owned_' );
		$transport->execute( 'START TRANSACTION' ); $transport->execute( 'INSERT INTO `owned_delivery_engine_delivery_quotes` (id) VALUES (1)' ); $result = $transport->execute( 'COMMIT' );
		self::assertSame( [ 'START TRANSACTION', 'INSERT INTO `owned_delivery_engine_delivery_quotes` (id) VALUES (1)', 'COMMIT' ], $native->sent ); self::assertFalse( $result->acknowledged ); self::assertTrue( $result->sent ); self::assertSame( 2013, $result->errno );
		self::assertSame( 1, $factory->quote_writes ); self::assertSame( 1, $factory->sent_quote_commits ); self::assertSame( 1, $factory->masked_quote_acks ); self::assertSame( 71, $factory->fault_connection ); self::assertFalse( $factory->mask_next_quote_ack );
	}
	public function test_control_and_budget_commits_cannot_be_misreported_as_lost_quote_ack(): void {
		$factory = $this->factory(); $factory->mask_next_quote_ack = true; $native = new QuoteCartFixtureNativeTransport(); $transport = new \CetechQuoteCartTransport( $native, $factory, 'owned_' );
		$transport->execute( 'START TRANSACTION' ); $transport->execute( 'UPDATE `owned_delivery_engine_delivery_quote_budget_windows` SET revision=2 WHERE id=1' ); self::assertTrue( $transport->execute( 'COMMIT' )->acknowledged );
		$transport->execute( 'START TRANSACTION' ); self::assertTrue( $transport->execute( 'COMMIT' )->acknowledged );
		self::assertSame( [ 'budget_commit' ], $factory->timeline ); self::assertSame( 1, $factory->budget_writes ); self::assertSame( 0, $factory->masked_quote_acks ); self::assertTrue( $factory->mask_next_quote_ack );
	}
	public function test_real_commit_refusal_is_preserved_and_never_relabelled_actual_lost_ack(): void {
		$factory = $this->factory(); $factory->mask_next_quote_ack = true; $native = new QuoteCartFixtureNativeTransport(); $transport = new \CetechQuoteCartTransport( $native, $factory, 'owned_' );
		$transport->execute( 'START TRANSACTION' ); $transport->execute( 'UPDATE `owned_delivery_engine_delivery_quotes` SET revision=2 WHERE id=1' ); $native->refuse_commit = true; $result = $transport->execute( 'COMMIT' );
		self::assertFalse( $result->acknowledged ); self::assertFalse( $result->sent ); self::assertSame( 0, $factory->masked_quote_acks ); self::assertSame( 0, $factory->sent_quote_commits ); self::assertTrue( $factory->mask_next_quote_ack );
	}
	public function test_private_cross_process_fixture_export_excludes_native_objects_callbacks_and_credentials(): void {
		$fixture = ( new \ReflectionClass( \CetechNativeQuoteProviderFixture::class ) )->newInstanceWithoutConstructor();
		foreach ( [ 'suffix' => 'fixture', 'tax_class' => 'fixture_tax', 'offer' => 1, 'zone' => 2, 'rate' => 3, 'shipping_instance' => 4, 'alternate_supplier' => 5, 'alternate_profile' => 6, 'alternate_origin' => 7 ] as $name => $value ) { ( new \ReflectionProperty( $fixture, $name ) )->setValue( $fixture, $value ); }
		foreach ( [ 'wc_before', 'hooks_before', 'globals_before' ] as $name ) { ( new \ReflectionProperty( $fixture, $name ) )->setValue( $fixture, [ 'private_callback' => static fn (): string => 'PRIVATE-CALLBACK', 'private_object' => (object) [ 'credential' => 'PRIVATE-PASSWORD' ] ] ); }
		$facts = \CetechQuoteCartHttpFixture::export_native( $fixture ); $json = json_encode( $facts, JSON_THROW_ON_ERROR );
		self::assertStringNotContainsString( 'PRIVATE-', $json ); self::assertArrayNotHasKey( 'wc_before', $facts ); self::assertArrayNotHasKey( 'hooks_before', $facts ); self::assertArrayNotHasKey( 'globals_before', $facts ); self::assertArrayNotHasKey( 'db', $facts ); self::assertSame( 3, $facts['rate'] ); self::assertSame( $facts, json_decode( $json, true, 512, JSON_THROW_ON_ERROR ) );
	}
	public function test_native_diagnostic_finds_only_known_installed_guard_line_through_bounded_previous_chain(): void {
		try { \CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation::component_key( '' ); self::fail( 'Empty component was accepted.' ); }
		catch ( \RuntimeException $error ) { $cause = $error; }
		$wrapped = new \RuntimeException( 'PRIVATE-PAYLOAD /private/path', 0, $cause ); [ $site, $line ] = \CetechQuoteCartEnvironmentObservation::verified_refusal( $wrapped );
		self::assertSame( 'native_preparation', $site ); self::assertIsInt( $line ); $class = new \ReflectionClass( \CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation::class ); self::assertGreaterThanOrEqual( $class->getStartLine(), $line ); self::assertLessThanOrEqual( $class->getEndLine(), $line );
		self::assertSame( [ null, null ], \CetechQuoteCartEnvironmentObservation::verified_refusal( new \RuntimeException( 'PRIVATE-PAYLOAD /private/path' ) ) );
		for ( $i = 0; $i < 4; ++$i ) { $cause = new \RuntimeException( 'PRIVATE-PAYLOAD', 0, $cause ); } self::assertSame( [ null, null ], \CetechQuoteCartEnvironmentObservation::verified_refusal( $cause ) );
		self::assertSame( 'Error', \CetechQuoteCartEnvironmentObservation::safe_error_class( new \Error( 'PRIVATE-ERROR' ) ) ); self::assertSame( 'InvalidArgumentException', \CetechQuoteCartEnvironmentObservation::safe_error_class( new \InvalidArgumentException( 'PRIVATE-ERROR' ) ) ); self::assertSame( 'RuntimeException', \CetechQuoteCartEnvironmentObservation::safe_error_class( new \LogicException( 'PRIVATE-ERROR' ) ) );
	}
}

final class QuoteCartFixtureNativeTransport implements OperationConnectionTransport {
	public array $sent = []; public bool $refuse_commit = false;
	public function execute( string $sql ): OperationConnectionResult { $this->sent[] = $sql; return 'COMMIT' === $sql && $this->refuse_commit ? new OperationConnectionResult( false, false, errno: 2006 ) : new OperationConnectionResult( true, true, affected_rows: 1 ); }
	public function connection_id(): int { return 71; }
	public function transaction_state(): ?array { return [ 'autocommit' => 1, 'in_transaction' => 0 ]; }
	public function escape( string $value ): string { return $value; }
	public function close(): bool { return true; }
}
