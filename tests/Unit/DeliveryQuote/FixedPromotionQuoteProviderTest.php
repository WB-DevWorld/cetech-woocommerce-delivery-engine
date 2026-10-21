<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteProvider, QuoteProviderInterface, QuoteProviderRegistry};
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedDeliveryPromotion;
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteContext, QuoteJson, QuoteTerms};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';

final class FixedPromotionQuoteProviderTest extends TestCase {

	public function test_compiled_provider_preserves_native_tax_money_and_original_private_proof(): void {
		$source = F::source(); $result = F::result( $source ); $provider = F::provider( $result ); $context = $provider->context(); $terms = $provider->terms();
		self::assertSame( 1, $source->reads ); self::assertTrue( $result->unchanged() );
		self::assertSame( 3, $context->format_version() ); self::assertSame( 3, $terms->format_version() );
		self::assertSame( FixedPromotionQuoteProvider::CODE, $provider->code() ); self::assertSame( $provider->code(), $provider->profile() ); self::assertSame( 1, $provider->version() ); self::assertSame( 1, $provider->profile_version() );
		self::assertSame( $result->native_context()->to_private_json(), $context->base_context()->to_private_json() );
		$capture = $context->fixed_promotion_capture();
		self::assertSame( $result->original_requests()->header()->to_private_json(), $capture->original_header()->to_private_json() );
		self::assertSame( F::original_context()->to_private_json(), $capture->original_context()->to_private_json() );
		self::assertSame( F::original_terms()->to_private_json(), $capture->original_terms()->to_private_json() );
		self::assertSame( $capture->digest(), $terms->private_facts()['promotion_capture_digest'] );
		$group = $terms->private_facts()['groups'][0]; $fresh = $result->native_receipt()->group( F::key() );
		foreach ( [ 'list' => '12.5000', 'final' => '9.5000', 'tax' => '1.9000', 'total' => '11.4000' ] as $key => $amount ) { self::assertSame( [ 'amount' => $amount, 'currency' => 'GHS', 'precision' => 4 ], $group[$key] ); }
		self::assertSame( [ 'amount' => '3.0000', 'currency' => 'GHS', 'precision' => 4 ], $group['promotion']['amount'] );
		self::assertSame( [ 'code' => FixedPromotionQuoteProvider::PROMOTION_PROVIDER, 'version' => 1 ], $group['promotion']['provider'] );
		self::assertSame( $fresh->customer_label(), $group['customer_label'] );
		self::assertSame( QuoteJson::encode( $fresh->native_tax_receipt() ), QuoteJson::encode( $group['native_tax_receipt'] ) );
		self::assertSame( QuoteJson::encode( $fresh->native_money_receipt() ), QuoteJson::encode( $group['native_money_receipt'] ) );
		self::assertSame( $terms->to_private_json(), $provider->capture( QuoteContext::from_json( $context->to_private_json() ) )->to_private_json() );
		self::assertSame( 1, $source->reads, 'Replay must never recapture or replan.' );
		$quote = DeliveryQuote::issue( F::header( $provider ), $context, $terms ); self::assertFalse( $quote->usable_at( QuoteFixtures::time() ) );
	}

	public function test_mixed_capture_requires_and_preserves_an_explicit_null_slot(): void {
		$result = F::mixed_result(); $rules = F::mixed_rules(); $provider = F::provider( $result, $rules );
		$groups = array_column( $provider->terms()->private_facts()['groups'], null, 'component_key' );
		self::assertSame( 'applied', $groups[F::key()]['promotion']['state'] );
		self::assertSame( 'none', $groups[F::second_key()]['promotion']['state'] );
		self::assertSame( [ 'code' => 'native_no_delivery_promotion_v1', 'version' => 1 ], $groups[F::second_key()]['promotion']['provider'] );
		self::assertSame( '0.0000', $groups[F::second_key()]['promotion']['amount']['amount'] );
		self::assertSame( '12.5000', $groups[F::second_key()]['final']['amount'] );
		$slots = array_column( $provider->context()->fixed_promotion_capture()->private_facts()['groups'], null, 'component_key' );
		self::assertNull( $slots[F::second_key()]['rule'] ); self::assertNull( $slots[F::second_key()]['plan'] );
		self::assertSame( $provider->terms()->digest(), F::provider( $result, array_reverse( $rules ) )->terms()->digest() );
		self::assertSame( $provider->context()->digest(), F::provider( $result, array_reverse( $rules ) )->context()->digest() );
	}

	public function test_only_explicit_registration_captures_the_exact_new_pair(): void {
		$provider = F::provider(); $registry = new QuoteProviderRegistry( [ $provider ] );
		self::assertSame( [ FixedPromotionQuoteProvider::PROMOTION_PROVIDER => [ 1 ], 'native_no_delivery_promotion_v1' => [ 1 ] ], $provider->evidence_providers() );
		self::assertSame( $provider->terms()->to_private_json(), $registry->capture( $provider->code(), 1, $provider->profile(), 1, $provider->context() )->to_private_json() );
		$this->expectException( \InvalidArgumentException::class ); ( new QuoteProviderRegistry() )->get( $provider->code(), 1, $provider->profile(), 1 );
	}

	#[DataProvider( 'invalid_rules' )]
	public function test_factory_refuses_nonexact_or_rebound_rules( string $case ): void {
		$rules = F::rules();
		switch ( $case ) {
			case 'missing': $rules = []; break;
			case 'foreign': $rules[0]['component_key'] = QuoteFixtures::digest( 'foreign component' ); break;
			case 'duplicate': $rules[] = $rules[0]; break;
			case 'extra': $rules[0]['authority'] = true; break;
			case 'null planned': $rules[0]['rule'] = null; break;
			case 'untyped': $rules[0]['rule'] = F::rule()->private_facts(); break;
			default:
				$facts = F::rule()->private_facts();
				if ( 'amount' === $case ) { $facts['discount']['amount'] = '2.00'; }
				if ( 'version' === $case ) { $facts['version'] = 2; }
				if ( 'funder' === $case ) { $facts['funding_source']['code'] = 'another_funder'; }
				$rules[0]['rule'] = FixedDeliveryPromotion::from_array( $facts );
		}
		$this->expectException( \InvalidArgumentException::class ); F::provider( F::result(), $rules );
	}
	public static function invalid_rules(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'missing', 'foreign', 'duplicate', 'extra', 'null planned', 'untyped', 'amount', 'version', 'funder' ] ); }

	public function test_unadjusted_component_cannot_be_omitted_or_given_a_rule(): void {
		$result = F::mixed_result();
		foreach ( [ F::rules(), [ ...F::rules(), [ 'component_key' => F::second_key(), 'rule' => F::rule() ] ] ] as $rules ) {
			try { F::provider( $result, $rules ); self::fail( 'Missing/null-slot disagreement must refuse.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		}
	}

	#[DataProvider( 'changed_contexts' )]
	public function test_replay_refuses_any_changed_fresh_context( string $case ): void {
		$provider = F::provider(); $facts = $provider->context()->private_facts();
		if ( 'selection' === $case ) { $facts['selection_digest'] = QuoteFixtures::digest( 'changed selection' ); unset( $facts['promotion_capture'] ); $facts['format_version'] = 1; }
		else { $facts['tax']['context_digest'] = QuoteFixtures::digest( 'changed native tax' ); }
		$this->expectException( \RuntimeException::class ); $provider->capture( QuoteContext::from_array( $facts ) );
	}
	public static function changed_contexts(): array { return [ [ 'selection' ], [ 'tax' ] ]; }

	public function test_native_source_mutation_refuses_both_factory_and_replay_without_recalculation(): void {
		$source = F::source(); $result = F::result( $source ); $provider = F::provider( $result ); $source->data['packages'][0]['label'] = 'Changed after native capture';
		foreach ( [ static fn() => F::provider( $result ), static fn() => $provider->capture( $provider->context() ) ] as $attempt ) {
			try { $attempt(); self::fail( 'Changed source must refuse.' ); } catch ( \RuntimeException ) { self::assertTrue( true ); }
		}
		self::assertSame( 1, $source->reads );
	}

	public function test_factory_detaches_caller_slot_references_and_public_projection_omits_private_proof(): void {
		$key = F::key(); $rule = F::rule(); $rules = [ [ 'component_key' => &$key, 'rule' => &$rule ] ]; $provider = F::provider( F::result(), $rules ); $before = $provider->context()->to_private_json();
		$key = QuoteFixtures::digest( 'replacement component' ); $rule = F::rule( '1.00' ); $rules[0]['another_field'] = 'caller modification';
		self::assertSame( $before, $provider->context()->to_private_json() ); self::assertSame( $provider->terms()->digest(), $provider->capture( $provider->context() )->digest() );
		self::assertStringContainsString( 'private_fixed_funder', $before );
		$display = $provider->terms()->display_money();
		self::assertSame( [ 'component_key', 'customer_label', 'list', 'final', 'tax', 'total', 'promotion', 'rounded_tax', 'display_total' ], array_keys( $display[0] ) );
		self::assertSame( [ 'state', 'amount' ], array_keys( $display[0]['promotion'] ) );
		foreach ( [ 'private_fixed_funder', 'promotion_capture', 'funding_source', 'eligibility', 'budget', 'rule_digest', 'evidence_digest' ] as $private ) { self::assertStringNotContainsString( $private, json_encode( $display, JSON_THROW_ON_ERROR ) ); self::assertStringNotContainsString( $private, print_r( $provider, true ) ); }
	}

	#[DataProvider( 'implicit_exports' )]
	public function test_implicit_provider_exports_are_refused( string $format ): void {
		$provider = F::provider(); $this->expectException( \LogicException::class );
		if ( 'json' === $format ) { json_encode( $provider, JSON_THROW_ON_ERROR ); } else { serialize( $provider ); }
	}
	public static function implicit_exports(): array { return [ [ 'json' ], [ 'php' ] ]; }

	public function test_registry_binds_claimed_digest_to_actual_terms_in_the_context_proof(): void {
		$real = F::provider(); $facts = $real->terms()->private_facts();
		// Individually balanced money is insufficient when it contradicts the frozen plan.
		$facts['groups'][0]['list']['amount'] = '13.5000'; $facts['groups'][0]['promotion']['amount']['amount'] = '4.0000';
		$provider = new class( QuoteTerms::from_array( $facts ) ) implements QuoteProviderInterface {
			public function __construct( private QuoteTerms $terms ) {}
			public function code(): string { return FixedPromotionQuoteProvider::CODE; } public function version(): int { return 1; }
			public function profile(): string { return $this->code(); } public function profile_version(): int { return 1; }
			public function evidence_providers(): array { return [ FixedPromotionQuoteProvider::PROMOTION_PROVIDER => [ 1 ] ]; }
			public function capture( QuoteContext $context ): QuoteTerms { return $this->terms; }
		};
		$this->expectException( \InvalidArgumentException::class );
		( new QuoteProviderRegistry( [ $provider ] ) )->capture( $provider->code(), 1, $provider->profile(), 1, $real->context() );
	}

	#[DataProvider( 'invalid_pairs' )]
	public function test_registry_refuses_aliases_versions_and_legacy_format_under_the_new_pair( string $code, int $version, string $profile, int $profile_version, bool $base ): void {
		$real = F::provider(); $terms = $base ? $real->terms()->base_terms() : $real->terms();
		$fake = new class( $terms, $code, $version, $profile, $profile_version, $real->evidence_providers() ) implements QuoteProviderInterface {
			public function __construct( private QuoteTerms $terms, private string $provider_code, private int $provider_version, private string $profile_code, private int $profile_v, private array $evidence ) {}
			public function code(): string { return $this->provider_code; } public function version(): int { return $this->provider_version; }
			public function profile(): string { return $this->profile_code; } public function profile_version(): int { return $this->profile_v; }
			public function evidence_providers(): array { return $this->evidence; } public function capture( QuoteContext $context ): QuoteTerms { return $this->terms; }
		};
		$this->expectException( \InvalidArgumentException::class ); ( new QuoteProviderRegistry( [ $fake ] ) )->capture( $code, $version, $profile, $profile_version, $base ? $real->context()->base_context() : $real->context() );
	}
	public static function invalid_pairs(): array {
		$code = FixedPromotionQuoteProvider::CODE;
		return [ [ 'legacy_fixed_base_v1', 1, $code, 1, false ], [ $code, 1, 'legacy_fixed_base_v1', 1, false ], [ $code, 2, $code, 1, false ], [ $code, 1, $code, 2, false ], [ $code, 1, $code, 1, true ] ];
	}
}
