<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteIssueCommand;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteLifecycle;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProviderInterface;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProviderRegistry;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';

final class ProviderRegistryTest extends TestCase {
	public function test_empty_default_registry_never_captures_or_invents_a_price(): void {
		$id = QuoteId::generate(); $result = ( new QuoteLifecycle() )->issue( $this->command(), $id, QuoteFixtures::reference( $id ), QuoteFixtures::time() );
		self::assertFalse( $result->completed() ); self::assertNull( $result->quote() ); self::assertSame( 'quote_unavailable', $result->reason_code() );
	}
	public function test_injected_fixture_provider_issues_complete_detached_body_with_explicit_reference(): void {
		$provider = $this->provider(); $registry = new QuoteProviderRegistry( [ $provider ] ); $id = QuoteId::generate(); $reference = QuoteFixtures::reference( $id );
		$result = ( new QuoteLifecycle( $registry ) )->issue( $this->command(), $id, $reference, QuoteFixtures::time() ); $quote = $result->quote();
		self::assertTrue( $result->completed() ); self::assertSame( 1, $provider->captures ); self::assertTrue( $quote->header()->matches_reference( $reference ) ); self::assertSame( 'issued', $quote->state() ); self::assertSame( QuoteFixtures::context()->digest(), $quote->context()->digest() ); self::assertSame( QuoteFixtures::terms()->to_private_json(), $quote->terms()->to_private_json() );
		$facts = $quote->terms()->private_facts(); $facts['groups'][0]['final']['amount'] = '900.00'; self::assertSame( '12.50', $quote->terms()->private_facts()['groups'][0]['final']['amount'] );
	}
	public function test_unknown_code_version_profile_or_profile_version_refuses_before_capture(): void {
		$provider = $this->provider(); $registry = new QuoteProviderRegistry( [ $provider ] );
		foreach ( [ [ 'missing', 1, 'fixture_v1', 1 ], [ 'fixture_v1', 2, 'fixture_v1', 1 ], [ 'fixture_v1', 1, 'unknown_profile', 1 ], [ 'fixture_v1', 1, 'fixture_v1', 2 ] ] as $fields ) {
			$command = QuoteIssueCommand::create( QuoteFixtures::owner(), QuoteFixtures::context(), $fields[0], $fields[1], $fields[2], $fields[3], 'original_token' ); $id = QuoteId::generate();
			$result = ( new QuoteLifecycle( $registry ) )->issue( $command, $id, QuoteFixtures::reference( $id ), QuoteFixtures::time() ); self::assertFalse( $result->completed() ); self::assertNull( $result->quote() ); self::assertSame( 'quote_unavailable', $result->reason_code() );
		}
		self::assertSame( 0, $provider->captures );
	}
	public function test_unavailable_clock_or_mismatched_reference_performs_zero_provider_work(): void {
		$provider = $this->provider(); $service = new QuoteLifecycle( new QuoteProviderRegistry( [ $provider ] ) ); $id = QuoteId::generate();
		self::assertFalse( $service->issue( $this->command(), $id, QuoteFixtures::reference( $id ), null )->completed() ); self::assertFalse( $service->issue( $this->command(), $id, QuoteFixtures::reference( QuoteId::generate() ), QuoteFixtures::time() )->completed() ); self::assertSame( 0, $provider->captures );
	}
	public function test_capture_requires_exact_component_policy_currency_and_evidence_manifest(): void {
		$base = QuoteFixtures::terms()->private_facts(); $variants = [];
		$data = $base; $data['groups'][0]['component_key'] = QuoteFixtures::digest( 'unknown_component' ); $variants[] = $data;
		$data = $base; $data['groups'][0]['policy_digest'] = QuoteFixtures::digest( 'new_policy' ); $variants[] = $data;
		$data = $base; $data['groups'][0]['native_tax_receipt']['context_digest'] = QuoteFixtures::digest( 'new_tax_context' ); $variants[] = $data;
		$data = $base; $data['groups'][0]['native_money_receipt']['evidence_digest'] = QuoteFixtures::digest( 'new_money_context' ); $variants[] = $data;
		$data = $base; foreach ( [ 'list', 'final', 'tax', 'total' ] as $key ) { $data['groups'][0][$key]['currency'] = 'USD'; } $data['groups'][0]['promotion']['amount']['currency'] = 'USD'; $data['groups'][0]['native_tax_receipt']['rounded_tax']['currency'] = 'USD'; $data['groups'][0]['native_money_receipt']['native_total']['currency'] = 'USD'; $data['groups'][0]['native_money_receipt']['display_total']['currency'] = 'USD'; $variants[] = $data;
		foreach ( $variants as $data ) {
			$provider = $this->provider( QuoteTerms::from_array( $data ) ); $id = QuoteId::generate(); $result = ( new QuoteLifecycle( new QuoteProviderRegistry( [ $provider ] ) ) )->issue( $this->command(), $id, QuoteFixtures::reference( $id ), QuoteFixtures::time() );
			self::assertFalse( $result->completed() ); self::assertNull( $result->quote() ); self::assertSame( 1, $provider->captures );
		}
	}
	public function test_unknown_auxiliary_provider_cannot_become_retained_acceptance(): void {
		$data = QuoteFixtures::terms()->private_facts(); $data['groups'][0]['promotion']['provider'] = [ 'code' => 'unknown_promoter', 'version' => 1 ]; $terms = QuoteTerms::from_array( $data ); $provider = $this->provider( $terms ); $id = QuoteId::generate();
		self::assertFalse( ( new QuoteLifecycle( new QuoteProviderRegistry( [ $provider ] ) ) )->issue( $this->command(), $id, QuoteFixtures::reference( $id ), QuoteFixtures::time() )->completed() );
		$quote = QuoteFixtures::issue( terms: $terms ); self::assertSame( 'quote_unavailable', $quote->reason_at( QuoteFixtures::time() ) ); self::assertFalse( $quote->usable_at( QuoteFixtures::time() ) );
	}
	public function test_unknown_main_provider_is_preserved_as_unavailable_in_pure_issue_model(): void {
		$data = QuoteFixtures::terms()->private_facts(); $data['groups'][0]['provider'] = [ 'code' => 'unknown_provider', 'version' => 1 ]; $quote = QuoteFixtures::issue( terms: QuoteTerms::from_array( $data ) );
		self::assertSame( 'issued', $quote->state() ); self::assertSame( 'unknown_provider', $quote->terms()->private_facts()['groups'][0]['provider']['code'] ); self::assertFalse( $quote->usable_at( QuoteFixtures::time() ) );
		$result = ( new QuoteLifecycle() )->accept( $quote, QuoteFixtures::owner(), QuoteFixtures::reference( $quote->header()->id() ), QuoteFixtures::context(), QuoteFixtures::time(), 1, $quote->header()->body_digest(), $quote->header()->expires_at() ); self::assertFalse( $result->completed() ); self::assertNull( $quote->accepted_at() );
	}
	public function test_native_precise_tax_and_independent_display_precision_are_not_coerced(): void {
		$data = QuoteFixtures::terms()->private_facts(); $group = &$data['groups'][0];
		foreach ( [ 'list', 'final', 'tax', 'total' ] as $field ) { $group[$field]['precision'] = 6; }
		$group['tax']['amount'] = '0.123456'; $group['total']['amount'] = '12.623456'; $group['promotion']['amount']['precision'] = 6;
		$group['native_tax_receipt']['exempt'] = false; $group['native_tax_receipt']['tax_status'] = 'taxable'; $group['native_tax_receipt']['rates'] = [ [ 'rate_id' => 7, 'amount' => $group['tax'] ] ]; $group['native_tax_receipt']['rounded_tax']['amount'] = '0.12';
		$group['native_money_receipt']['native_total'] = $group['total']; $group['native_money_receipt']['display_total']['amount'] = '12.62'; unset( $group );
		$registry = new QuoteProviderRegistry( [ $this->provider( QuoteTerms::from_array( $data ) ) ] ); $captured = $registry->capture( 'fixture_v1', 1, 'fixture_v1', 1, QuoteFixtures::context() )->private_facts()['groups'][0];
		self::assertSame( 6, $captured['tax']['precision'] ); self::assertSame( '0.123456', $captured['tax']['amount'] ); self::assertSame( '0.12', $captured['native_tax_receipt']['rounded_tax']['amount'] ); self::assertSame( 2, $captured['native_tax_receipt']['rounded_tax']['precision'] ); self::assertSame( '12.62', $captured['native_money_receipt']['display_total']['amount'] ); self::assertSame( '12.623456', $captured['total']['amount'] );
	}
	public function test_route_zero_cost_zero_and_unknown_promotion_remain_distinct_without_defaults(): void {
		$base = QuoteFixtures::terms()->private_facts(); $explicit = $base;
		$explicit['groups'][0]['route'] = [ 'state' => 'known', 'distance' => '0', 'distance_unit' => 'km', 'duration_seconds' => '0', 'provider' => [ 'code' => 'fixture_route_v1', 'version' => 1 ] ];
		$explicit['groups'][0]['cost'] = [ 'state' => 'known', 'amount' => [ 'amount' => '0', 'currency' => 'GHS', 'precision' => 2 ], 'provider' => [ 'code' => 'fixture_cost_v1', 'version' => 1 ] ];
		$recorded = QuoteTerms::from_array( $explicit ); self::assertNotSame( QuoteFixtures::terms()->digest(), $recorded->digest() ); self::assertSame( 'not_recorded', $base['groups'][0]['route']['state'] ); self::assertSame( 'unavailable', $base['groups'][0]['cost']['state'] );
		self::assertSame( '0', $recorded->private_facts()['groups'][0]['route']['distance'] ); self::assertSame( '0.00', $recorded->private_facts()['groups'][0]['cost']['amount']['amount'] );
		$unknown = $base; $unknown['groups'][0]['promotion'] = [ 'state' => 'unavailable', 'reason' => 'promotion_context_unavailable' ]; $terms = QuoteTerms::from_array( $unknown );
		self::assertFalse( $terms->checkout_acceptable() ); self::assertNotSame( QuoteFixtures::terms()->digest(), $terms->digest() ); self::assertSame( [ 'state' => 'unavailable', 'amount' => null ], $terms->display_money()[0]['promotion'] ); self::assertFalse( QuoteFixtures::issue( terms: $terms )->usable_at( QuoteFixtures::time() ) );
	}
	public function test_duplicate_provider_identity_refuses_instead_of_shadowing_a_capture(): void {
		$provider = $this->provider(); $this->expectException( \InvalidArgumentException::class ); new QuoteProviderRegistry( [ $provider, $this->provider() ] );
	}
	public function test_provider_failure_never_echoes_private_exception_details(): void {
		$provider = $this->provider(); $provider->failure = true; $id = QuoteId::generate(); $result = ( new QuoteLifecycle( new QuoteProviderRegistry( [ $provider ] ) ) )->issue( $this->command(), $id, QuoteFixtures::reference( $id ), QuoteFixtures::time() );
		self::assertFalse( $result->completed() ); self::assertSame( 'quote_unavailable', $result->reason_code() ); self::assertNull( $result->quote() );
	}
	private function command(): QuoteIssueCommand { return QuoteIssueCommand::create( QuoteFixtures::owner(), QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, 'original_token' ); }
	private function provider( ?QuoteTerms $terms = null ): QuoteProviderInterface {
		return new class( $terms ?? QuoteFixtures::terms() ) implements QuoteProviderInterface {
			public int $captures = 0; public bool $failure = false;
			public function __construct( private readonly QuoteTerms $terms ) {}
			public function code(): string { return 'fixture_v1'; } public function version(): int { return 1; } public function profile(): string { return 'fixture_v1'; } public function profile_version(): int { return 1; }
			public function evidence_providers(): array { return [ 'fixture_none_v1' => [ 1 ], 'fixture_cost_v1' => [ 1 ], 'fixture_route_v1' => [ 1 ] ]; }
			public function capture( QuoteContext $context ): QuoteTerms { ++$this->captures; if ( $this->failure ) { throw new \RuntimeException( 'PRIVATE-QUOTE-PROVIDER address=secret token=secret' ); } return $this->terms; }
		};
	}
}
