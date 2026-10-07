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
	/** Actual persisted row through cleanup discovery; SQL transport stops after exact selectors. */
	public function test_cleanup_uses_the_owned_persisted_header_uuid_for_quote_selectors(): void {
		$program = <<<'PHP'
function wp_delete_user( int $id ): bool { return true; }
class wpdb {
    public string $prefix = 'owned_'; public string $last_error = ''; public array $quote_rows = []; public array $deleted = [];
    public function get_results( string $sql, mixed $format ): array { return str_contains( $sql, '`owned_delivery_engine_delivery_quotes`' ) ? $this->quote_rows : []; }
    public function delete( string $table, array $where ): int { if ( in_array( $table, [ 'owned_delivery_engine_delivery_quote_bindings', 'owned_delivery_engine_delivery_quotes' ], true ) ) { $this->deleted[$table] = $where; if ( str_ends_with( $table, '_delivery_quotes' ) ) { throw new LogicException( 'OWNED-SELECTOR-OBSERVED' ); } } return 1; }
}
require 'tests/bootstrap.php'; require 'tests/Support/DeliveryQuote/CartQuoteFixtures.php'; require 'scripts/qualification/opening-quote-cart-support.php';
$f = new CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteDurableFixtureFactory(); $environment = new CetechDeliveryEngine\Tests\Support\DeliveryQuote\CartQuoteFixtureEnvironment( $f ); $sessions = new CetechDeliveryEngine\Tests\Support\DeliveryQuote\CartQuoteFixtureSessions(); $service = CetechDeliveryEngine\Tests\Support\DeliveryQuote\CartQuoteFixtures::service( $f, $environment, $sessions );
$issued = $service->refresh( CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::generate()->value(), 0, CetechDeliveryEngine\Domain\Contracts\RequestContext::create() );
$row = $f->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quotes' )->fetch( PDO::FETCH_ASSOC ); $typed = CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow::from_row( $row ); $owner = $typed->header()->owner();
$db = new wpdb(); $db->quote_rows = [ $row ]; $GLOBALS['wpdb'] = $db; $GLOBALS['wp_filter'] = []; $GLOBALS['cetech_de_test_wc'] = new class { public mixed $session = null; public mixed $cart = null; public mixed $customer = null; public function shipping(): object { return (object) []; } };
$fixture = ( new ReflectionClass( CetechNativeQuoteProviderFixture::class ) )->newInstanceWithoutConstructor();
foreach ( [ 'suffix' => 'fixture', 'tax_class' => 'fixture_tax', 'offer' => 1, 'zone' => 2, 'rate' => 3, 'shipping_instance' => 4, 'alternate_supplier' => 5, 'alternate_profile' => 6, 'alternate_origin' => 7 ] as $name => $value ) { ( new ReflectionProperty( $fixture, $name ) )->setValue( $fixture, $value ); }
$native = CetechQuoteCartHttpFixture::export_native( $fixture ); $native['domain_before'] = [ 'delivery_quotes' => [], 'delivery_quote_budget_windows' => [] ]; $native['native_before'] = [ 'woocommerce_sessions' => [] ];
$state = [ 'native' => $native, 'owners' => [ [ 'owner' => $owner->facts(), 'auxiliary_key' => 'owned-auxiliary', 'native_session_key' => 'owned-session' ] ], 'site_id' => $owner->site_id(), 'page_ids' => [], 'user_id' => 9 ]; $reached = false; $failure_class = null;
try { CetechQuoteCartHttpFixture::cleanup( $db, $state ); }
catch ( Throwable $error ) { $reached = $error instanceof LogicException && 'OWNED-SELECTOR-OBSERVED' === $error->getMessage(); $failure_class = $error instanceof Error ? 'Error' : ( $error instanceof LogicException ? 'LogicException' : 'OtherError' ); }
$wanted = [ 'site_id' => $owner->site_id(), 'quote_uuid' => $typed->header()->id()->value() ];
echo json_encode( [ 'actual_issued_quote' => 'review_required' === $issued->shopper_facts()['status'], 'stored_id_is_integer' => is_int( $typed->id() ), 'owned_selectors_reached' => $reached, 'exact_binding_uuid_selector' => $wanted === ( $db->deleted['owned_delivery_engine_delivery_quote_bindings'] ?? null ), 'exact_quote_uuid_selector' => $wanted === ( $db->deleted['owned_delivery_engine_delivery_quotes'] ?? null ), 'selector_count' => count( $db->deleted ), 'failure_class' => $failure_class ], JSON_THROW_ON_ERROR );
PHP;
		$process = proc_open( [ PHP_BINARY, '-r', $program ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__, 3 ) ); self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr );
		$facts = json_decode( $stdout, true, 8, JSON_THROW_ON_ERROR ); self::assertTrue( $facts['actual_issued_quote'] ); self::assertTrue( $facts['stored_id_is_integer'] ); self::assertTrue( $facts['owned_selectors_reached'] ); self::assertTrue( $facts['exact_binding_uuid_selector'] ); self::assertTrue( $facts['exact_quote_uuid_selector'] ); self::assertSame( 2, $facts['selector_count'] );
	}
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
	/** Fresh host shims test only pure fixture isolation, not native callback semantics. */
	public function test_source_registration_probe_removes_only_one_exact_tuple_and_restores_before_returning(): void {
		$program = <<<'PHP'
class WC_Query { public static int $calls = 0; public function pre_get_posts(): never { ++self::$calls; throw new RuntimeException( 'PRIVATE-CALLBACK' ); } }
class WooCommerce { public function __construct( public WC_Query $query ) {} }
class WP_Hook { public array $callbacks = []; }
require 'tests/bootstrap.php'; require 'scripts/qualification/opening-quote-cart-support.php';
$cases = [];
foreach ( [ 'exact', 'other_identity', 'wrong_priority', 'wrong_args', 'duplicate', 'additional_unknown', 'other_hook_unknown', 'over_budget', 'malformed' ] as $mode ) {
    $query = new WC_Query(); $GLOBALS['woocommerce'] = new WooCommerce( $query ); $hook = new WP_Hook(); $tuple = [ 'function' => [ $query, 'pre_get_posts' ], 'accepted_args' => 1 ]; $hook->callbacks = [ 10 => [ 'PRIVATE-ENTRY' => $tuple ] ]; $GLOBALS['wp_filter'] = [ 'pre_get_posts' => $hook ];
    if ( 'other_identity' === $mode ) { $hook->callbacks[10]['PRIVATE-ENTRY']['function'][0] = new WC_Query(); }
    if ( 'wrong_priority' === $mode ) { $hook->callbacks = [ 11 => [ 'PRIVATE-ENTRY' => $tuple ] ]; }
    if ( 'wrong_args' === $mode ) { $hook->callbacks[10]['PRIVATE-ENTRY']['accepted_args'] = 2; }
    if ( 'duplicate' === $mode ) { $hook->callbacks[10]['PRIVATE-DUPLICATE'] = $tuple; }
    if ( 'additional_unknown' === $mode ) { $hook->callbacks[10]['PRIVATE-UNKNOWN'] = [ 'function' => static fn (): never => throw new RuntimeException( 'PRIVATE-CALLBACK' ), 'accepted_args' => 1 ]; }
    if ( 'other_hook_unknown' === $mode ) { $other = new WP_Hook(); $other->callbacks = [ 10 => [ [ 'function' => 'PRIVATE-UNKNOWN-CALLBACK', 'accepted_args' => 1 ] ] ]; $GLOBALS['wp_filter']['woocommerce_product_get_status'] = $other; }
    if ( 'over_budget' === $mode ) { $hook->callbacks = [ 10 => array_fill( 0, 257, $tuple ) ]; }
    if ( 'malformed' === $mode ) { $hook->callbacks[10]['PRIVATE-MALFORMED'] = 'PRIVATE-COOKIE'; }
    $before = $hook->callbacks; $before_filters = $GLOBALS['wp_filter']; $report = CetechQuoteCartEnvironmentObservation::source_registration_counterfactual();
    $cases[$mode] = [ 'report' => $report, 'report_valid' => null === $report || CetechQuoteCartEnvironmentObservation::valid_source_registration_probe( $report ), 'callbacks_restored' => $before === $hook->callbacks, 'hook_identity_restored' => $before_filters === $GLOBALS['wp_filter'], 'callbacks_not_invoked' => 0 === WC_Query::$calls, 'private_sentinel_absent' => ! str_contains( json_encode( $report, JSON_THROW_ON_ERROR ), 'PRIVATE' ) ];
}
echo json_encode( $cases, JSON_THROW_ON_ERROR );
PHP;
		$process = proc_open( [ PHP_BINARY, '-r', $program ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__, 3 ) ); self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr );
		$cases = json_decode( $stdout, true, 8, JSON_THROW_ON_ERROR );
		foreach ( $cases as $case ) { self::assertTrue( $case['report_valid'] ); self::assertTrue( $case['callbacks_restored'] ); self::assertTrue( $case['hook_identity_restored'] ); self::assertTrue( $case['callbacks_not_invoked'] ); self::assertTrue( $case['private_sentinel_absent'] ); }
		self::assertSame( 1, $cases['exact']['report']['native_query_tuple_count'] ); self::assertTrue( $cases['exact']['report']['capture_without_exact_tuple'] ); self::assertTrue( $cases['exact']['report']['original_hook_restored'] );
		foreach ( [ 'other_identity', 'wrong_priority', 'wrong_args' ] as $mode ) { self::assertSame( 0, $cases[$mode]['report']['native_query_tuple_count'] ); self::assertNull( $cases[$mode]['report']['capture_without_exact_tuple'] ); }
		self::assertSame( 2, $cases['duplicate']['report']['native_query_tuple_count'] ); self::assertNull( $cases['duplicate']['report']['capture_without_exact_tuple'] );
		self::assertFalse( $cases['additional_unknown']['report']['capture_without_exact_tuple'] ); self::assertFalse( $cases['other_hook_unknown']['report']['capture_without_exact_tuple'] ); self::assertNull( $cases['over_budget']['report'] ); self::assertNull( $cases['malformed']['report'] );
	}
	public function test_source_registration_probe_is_optional_and_refuses_private_or_unrelated_failure_fields(): void {
		$native = ( new \ReflectionClass( \CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteEnvironment::class ) )->newInstanceWithoutConstructor(); $observed = new \CetechQuoteCartEnvironmentObservation( $native ); $factory = $this->factory();
		$observed->diagnostics['prepare_entered'] = true; $observed->diagnostics['prepare_error_class'] = 'RuntimeException'; $observed->diagnostics['prepare_refusal_site'] = 'source_local_binding'; $observed->diagnostics['prepare_refusal_line'] = 51;
		$plain = \CetechQuoteCartHttpFixture::failure_observation( $observed, $factory ); self::assertCount( 15, $plain ); self::assertArrayNotHasKey( 'source_registration_probe', $plain );
		$observed->source_registration_probe = [ 'observation' => 'pure_registration_counterfactual_original_attempt_not_retried', 'native_query_singleton_present' => true, 'native_query_tuple_count' => 1, 'pre_get_posts_callback_count' => 1, 'capture_without_exact_tuple' => true, 'original_hook_restored' => true ];
		$with = \CetechQuoteCartHttpFixture::failure_observation( $observed, $factory ); self::assertCount( 16, $with ); self::assertSame( $plain, array_diff_key( $with, [ 'source_registration_probe' => true ] ) );
		$observed->source_registration_probe['private_cookie'] = 'PRIVATE-COOKIE'; self::assertNull( \CetechQuoteCartHttpFixture::failure_observation( $observed, $factory ) ); unset( $observed->source_registration_probe['private_cookie'] );
		$observed->source_registration_probe['native_query_tuple_count'] = 2; self::assertNull( \CetechQuoteCartHttpFixture::failure_observation( $observed, $factory ) ); $observed->source_registration_probe['native_query_tuple_count'] = 1;
		$observed->diagnostics['prepare_refusal_site'] = 'legacy_source'; self::assertNull( \CetechQuoteCartHttpFixture::failure_observation( $observed, $factory ) );
	}
	public function test_followup_stops_on_missing_original_input_without_reading_native_state(): void {
		$native = ( new \ReflectionClass( \CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteEnvironment::class ) )->newInstanceWithoutConstructor();
		$observer = new \CetechQuoteCartEnvironmentObservation( $native ); $report = $observer->readonly_followup();
		self::assertSame( 'followup_readonly_not_original_timing', $report['observation'] ); self::assertSame( 'input_ready', $report['failed_stage'] ); self::assertFalse( $report['input_ready'] ); self::assertNull( $report['error_class'] ); self::assertNull( $report['refusal_site'] ); self::assertNull( $report['refusal_line'] );
		foreach ( array_slice( $report, 6 ) as $value ) { self::assertNull( $value ); }
		self::assertStringNotContainsString( 'original_token', json_encode( $report, JSON_THROW_ON_ERROR ) );
	}
	public function test_failed_evidence_samples_raw_cache_and_keeps_original_counts_separate_from_followup(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/CartQuoteFixtures.php';
		$existed = array_key_exists( 'woocommerce', $GLOBALS ); $before_wc = $GLOBALS['woocommerce'] ?? null;
		$GLOBALS['woocommerce'] = (object) [ 'session' => new class { private array $_data = [ 'chosen_shipping_methods' => [ 'PRIVATE-RATE' ], 'cart_totals' => [ 'PRIVATE-TOTAL' ], 'shipping_for_package_0' => [ 'PRIVATE-PACKAGE' ] ]; } ];
		try {
			$factory = $this->createMock( \CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory::class ); $factory->expects( self::never() )->method( 'open' );
			$observer = new \CetechQuoteCartEnvironmentObservation( new \CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteEnvironment( $factory ) ); $owner = \CetechDeliveryEngine\Tests\Support\DeliveryQuote\CartQuoteFixtures::owner(); $context = \CetechDeliveryEngine\Tests\Support\DeliveryQuote\CartQuoteFixtures::context();
			$original = \CetechDeliveryEngine\Application\DeliveryQuote\QuoteIssueCommand::create( $owner, $context, 'legacy_fixed_base_v1', 1, 'legacy_fixed_base_v1', 1, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::generate()->value() ); $header = \CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures::issue( $owner, context: $context )->header(); $draft = \CetechDeliveryEngine\Tests\Support\DeliveryQuote\CartQuoteFixtures::draft();
			self::assertNull( $observer->evidence( $original, $header, $draft ) ); $original_facts = $observer->diagnostics; $counts = $this->factory(); $counts->source_reads = 0; $counts->quote_writes = 0; $counts->budget_writes = 4;
			$failure = \CetechQuoteCartHttpFixture::failure_observation( $observer, $counts ); self::assertSame( $original_facts, $observer->diagnostics ); self::assertFalse( $failure['prepare_entered'] ); self::assertTrue( $failure['evidence_called'] ); self::assertFalse( $failure['evidence_returned'] );
			foreach ( [ 'native_chosen_cache_present', 'native_totals_cache_present', 'native_shipping_cache_present' ] as $key ) { self::assertTrue( $failure[$key] ); }
			self::assertSame( 0, $failure['source_reads'] ); self::assertSame( 0, $failure['quote_writes'] ); self::assertSame( 4, $failure['budget_writes'] );
			$followup = $failure['current_evidence_followup']; self::assertCount( 27, $followup ); self::assertTrue( \CetechQuoteCartEnvironmentObservation::valid_evidence_followup( $followup ) ); self::assertSame( 'followup_readonly_not_original_timing', $followup['observation'] ); self::assertSame( 'environment_same_draft', $followup['failed_stage'] ); self::assertFalse( $followup['environment_same_draft'] );
			foreach ( [ 'source_read_delta', 'quote_write_delta', 'budget_write_delta' ] as $key ) { self::assertSame( 0, $followup[$key] ); }
			self::assertStringNotContainsString( 'PRIVATE-', json_encode( $failure, JSON_THROW_ON_ERROR ) ); $bad = $followup; $bad['private_cookie'] = 'PRIVATE-COOKIE'; self::assertFalse( \CetechQuoteCartEnvironmentObservation::valid_evidence_followup( $bad ) ); $bad = $followup; $bad['failed_stage'] = 'PRIVATE-COOKIE'; self::assertFalse( \CetechQuoteCartEnvironmentObservation::valid_evidence_followup( $bad ) );
		} finally { if ( $existed ) { $GLOBALS['woocommerce'] = $before_wc; } else { unset( $GLOBALS['woocommerce'] ); } }
	}
	public function test_followup_stops_at_actual_native_same_draft_false_before_any_connection_or_cache_restore(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/CartQuoteFixtures.php';
		$factory = $this->createMock( \CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory::class ); $factory->expects( self::never() )->method( 'open' );
		$native = new \CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteEnvironment( $factory ); $observer = new \CetechQuoteCartEnvironmentObservation( $native );
		$owner = \CetechDeliveryEngine\Tests\Support\DeliveryQuote\CartQuoteFixtures::owner(); $context = \CetechDeliveryEngine\Tests\Support\DeliveryQuote\CartQuoteFixtures::context();
		$original = \CetechDeliveryEngine\Application\DeliveryQuote\QuoteIssueCommand::create( $owner, $context, 'legacy_fixed_base_v1', 1, 'legacy_fixed_base_v1', 1, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::generate()->value() );
		$header = \CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures::issue( $owner, context: $context )->header(); $draft = \CetechDeliveryEngine\Tests\Support\DeliveryQuote\CartQuoteFixtures::draft();
		self::assertNull( $observer->evidence( $original, $header, $draft ) ); $report = $observer->readonly_followup();
		self::assertTrue( $report['input_ready'] ); self::assertFalse( $report['environment_same_draft'] ); self::assertSame( 'environment_same_draft', $report['failed_stage'] ); self::assertNull( $report['error_class'] );
		foreach ( array_slice( $report, 7 ) as $value ) { self::assertNull( $value ); }
		self::assertStringNotContainsString( $original->identity()->namespace_digest(), json_encode( $report, JSON_THROW_ON_ERROR ) );
	}
	public function test_shape_refusal_is_located_only_at_exact_installed_bind_context_method(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/LegacyQuoteProviderFixtures.php';
		$context = \CetechDeliveryEngine\Tests\Support\DeliveryQuote\LegacyQuoteProviderFixtures::context();
		$snapshot = \CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceSnapshot::captured( \CetechDeliveryEngine\Tests\Support\DeliveryQuote\LegacyQuoteProviderFixtures::plan(), $context, [], [], \CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures::time() );
		try { $snapshot->bind_context( $context ); self::fail( 'Synthetic unmatched policy was accepted.' ); } catch ( \InvalidArgumentException $error ) { $cause = $error; }
		[ $site, $line ] = \CetechQuoteCartEnvironmentObservation::verified_refusal( $cause ); $method = new \ReflectionMethod( \CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceSnapshot::class, 'bind_context' );
		self::assertSame( 'source_snapshot', $site ); self::assertIsInt( $line ); self::assertGreaterThanOrEqual( $method->getStartLine(), $line ); self::assertLessThanOrEqual( $method->getEndLine(), $line );
		try { \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape::invalid(); } catch ( \InvalidArgumentException $unknown ) {}
		self::assertSame( [ null, null ], \CetechQuoteCartEnvironmentObservation::verified_refusal( $unknown ) );
	}

	/** Actual service/C03 SQLite protocol; native MariaDB qualification is separate. */
	private function actual_accept_publication_pair(): array {
		require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/CartQuoteFixtures.php';
		$f = new \CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteDurableFixtureFactory(); $environment = new \CetechDeliveryEngine\Tests\Support\DeliveryQuote\CartQuoteFixtureEnvironment( $f ); $sessions = new \CetechDeliveryEngine\Tests\Support\DeliveryQuote\CartQuoteFixtureSessions(); $service = \CetechDeliveryEngine\Tests\Support\DeliveryQuote\CartQuoteFixtures::service( $f, $environment, $sessions );
		$request = \CetechDeliveryEngine\Domain\Contracts\RequestContext::create(); self::assertSame( 'review_required', $service->refresh( \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::generate()->value(), 0, $request )->shopper_facts()['status'] );
		$opened = $sessions->current; $original = \CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableCommand::accept( $opened->owner(), $opened->reference(), $opened->header(), null ); $f->effect_fault = 'lost_ack';
		self::assertSame( 'unconfirmed', $service->confirm( 1, $request )->shopper_facts()['status'] );
		$history = static function () use ( $f ): array { $out = []; foreach ( [ 'operation_records', 'operation_changes', 'delivery_quotes', 'delivery_quote_bindings', 'delivery_quote_budget_windows' ] as $store ) { $out[$store] = $f->pdo->query( 'SELECT * FROM durable_delivery_engine_' . $store . ' ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC ); } return $out; };
		$before = $history(); self::assertSame( 'confirmed', $service->retry( 1, $request )->shopper_facts()['status'] ); return [ $before, $history(), $original, $service, $history ];
	}
	public function test_retry_delta_allows_only_the_actual_original_publication_marker_and_second_retry_is_readonly(): void {
		[ $before, $after, $original, $service, $history ] = $this->actual_accept_publication_pair(); self::assertNotSame( $before, $after );
		self::assertSame( [ 'no_second_quote_mutation_or_audit' => true, 'one_original_accept_publication_marker' => true, 'all_other_history_unchanged' => true ], \CetechQuoteCartFixture::accept_retry_delta( $before, $after, $original ) );
		self::assertSame( 'confirmed', $service->retry( 1, \CetechDeliveryEngine\Domain\Contracts\RequestContext::create() )->shopper_facts()['status'] ); self::assertSame( $after, $history() );
	}
	public function test_retry_delta_rejects_extra_effects_identity_changes_and_nonmonotonic_marker_changes(): void {
		[ $before, $after, $original ] = $this->actual_accept_publication_pair(); $index = null; foreach ( $after['operation_records'] as $i => $row ) { if ( $row['namespace_hash'] === $original->identity->namespace_digest() ) { $index = $i; } } self::assertNotNull( $index );
		$mutations = [];
		foreach ( [ 'completion_json', 'intent_hash', 'target_hash', 'audit_id', 'site_id', 'completed_at' ] as $field ) { $changed = $after; $changed['operation_records'][$index][$field] = 'PRIVATE-CORRUPT'; $mutations[$field] = $changed; }
		$changed = $after; $changed['operation_records'][$index]['row_version'] += 1; $mutations['two_marker_versions'] = $changed;
		$changed = $after; $changed['operation_records'][$index]['updated_at'] = '2026-10-07 04:59:59.999999'; $mutations['clock_regression'] = $changed;
		$changed = $after; $changed['operation_records'][$index]['publication_state'] = 'pending'; $mutations['not_published'] = $changed;
		$changed = $after; $changed['operation_records'][0]['updated_at'] = '2026-10-07 05:00:01.000000'; $mutations['other_record_write'] = $changed;
		$changed = $after; $changed['operation_records'][] = $changed['operation_records'][$index]; $mutations['second_accept_record'] = $changed;
		foreach ( [ 'operation_changes', 'delivery_quotes', 'delivery_quote_bindings', 'delivery_quote_budget_windows' ] as $store ) { $changed = $after; $changed[$store][] = [ 'private_unexpected_effect' => 'PRIVATE-PAYLOAD' ]; $mutations[$store] = $changed; }
		foreach ( $mutations as $name => $changed ) { self::assertContains( false, \CetechQuoteCartFixture::accept_retry_delta( $before, $changed, $original ), $name ); }
		self::assertFalse( \CetechQuoteCartFixture::accept_retry_delta( $after, $after, $original )['one_original_accept_publication_marker'] );
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
