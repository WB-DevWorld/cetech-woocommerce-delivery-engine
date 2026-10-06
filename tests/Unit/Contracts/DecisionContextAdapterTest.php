<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Contracts;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Contracts\DecisionContextAdapter;
use CetechDeliveryEngine\Application\Coverage\CoverageGroupMatcher;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteLine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteResult;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationVersionDescriptor;
use CetechDeliveryEngine\Domain\Configuration\EffectiveScalarField;
use CetechDeliveryEngine\Domain\Configuration\FieldProvenance;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Contracts\DecisionContext;
use CetechDeliveryEngine\Domain\Contracts\DecisionTarget;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Coverage\CoverageMatchDiagnostic;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\ValueObject\CurrencyCode;
use CetechDeliveryEngine\Domain\ValueObject\Money;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecisionContextAdapterTest extends TestCase {

	public function test_configuration_first_memoized_and_fresh_preserve_reasons_rows_and_versions(): void {
		$repository = new InMemoryScopedConfigurationRepository();
		$global = $repository->saveScopedConfiguration( new ScopedConfiguration(
			ConfigurationScope::global(), [
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, 'in_store' ),
				ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_CHOICE, 'delivery' ),
				ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 20 ),
				ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 30 ),
				ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 10 ),
				ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 5 ),
			], [ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 2, 3 ] ) ]
		) );
		$product = $repository->saveScopedConfiguration( new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Product, 101, 'in_store', null, RecordStatus::Active, 7, ConfigurationSource::Native, null ),
			[ ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 0 ) ]
		) );
		$variation = $repository->saveScopedConfiguration( new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Variation, 202, 'in_store', 101, RecordStatus::Active, 9, ConfigurationSource::Native, null ),
			[ ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 99 ) ]
		) );
		$resolver = new EffectiveConfigurationResolver( $repository, new EffectiveConfigurationValidator() );
		$source_request = new EffectiveConfigurationRequest( 101, 202, 'in_store' );
		$request = RequestContext::create();
		$first_source = $resolver->resolve( $source_request );
		$first = DecisionContextAdapter::from_configuration( $first_source, 1, $request, self::time(), 'miss' );
		$memoized = DecisionContextAdapter::from_configuration( $resolver->resolve( $source_request ), 1, $request, self::time(), 'hit' );
		$fresh = DecisionContextAdapter::from_configuration( ( new EffectiveConfigurationResolver( $repository, new EffectiveConfigurationValidator() ) )->resolve( $source_request ), 1, $request, self::time(), 'miss' );
		self::assertSame( self::semantics( $first ), self::semantics( $memoized ) );
		self::assertSame( self::semantics( $first ), self::semantics( $fresh ) );
		self::assertSame( [ 'global' => $global->scope->config_version, 'product' => $product->scope->config_version, 'variation' => $variation->scope->config_version, 'fingerprint' => $first_source->version->fingerprint ], $first->configuration_versions() );
		self::assertTrue( $first->target()->equals( new DecisionTarget( 1, 101, 202, 'in_store' ) ) );
		$provenance = [];
		foreach ( $first->provenance() as $fact ) {
			$provenance[ $fact->field_key ] = $fact;
		}
		self::assertSame( $product->scope->id, $provenance['priority']->scope_row_id );
		self::assertSame( $product->scope->config_version, $provenance['priority']->configuration_version );
		self::assertSame( $variation->scope->id, $provenance['origin_id']->scope_row_id );
		self::assertSame( $variation->scope->config_version, $provenance['origin_id']->configuration_version );
		self::assertFalse( $first->is_complete() );
		self::assertFalse( $memoized->is_complete() );
		self::assertSame( 'miss', $first->cache_state() );
		self::assertSame( 'hit', $memoized->cache_state() );
	}

	public function test_actual_coverage_first_cached_and_fresh_preserve_semantic_diagnostics(): void {
		$geo = new GhanaGeographyFixture();
		$groups = new InMemoryCoverageGroupRepository();
		$groups->save_group( [ 'zone_id' => 10, 'root_location_id' => $geo->greater_accra->id, 'coverage_mode' => CoverageMode::SelectedDescendants->value, 'status' => RecordStatus::Active->value, 'members' => [ [ 'location_id' => $geo->accra->id, 'membership' => 'include' ] ] ] );
		$zones = new InMemoryDestinationZoneRepository();
		$zones->save( [ 'id' => 10, 'status' => 'active', 'priority' => 100, 'public_label' => 'Accra', 'internal_name' => 'Private zone', 'is_fallback' => false ] );
		$factory = static fn (): DestinationZoneMatcher => new DestinationZoneMatcher(
			$zones, new InMemoryDestinationRuleRepository(), null,
			new CoverageGroupMatcher( $groups, $geo->locations ), new CanonicalLocationResolver( $geo->locations, $geo->locations )
		);
		$matcher = $factory();
		$first_rows = $matcher->match_all( 'GH', '', 'Accra', '', [ 'canonical_location_key' => 'loc-accra' ] );
		$request = RequestContext::create();
		$target = new DecisionTarget( 1, 101 );
		$first = DecisionContextAdapter::from_coverage( $matcher->last_diagnostics()[0], $target, $request, self::time(), 'miss' );
		$cached_rows = $matcher->match_all( 'GH', '', 'Accra', '', [ 'canonical_location_key' => 'loc-accra' ] );
		$cached = DecisionContextAdapter::from_coverage( $matcher->last_diagnostics()[0], $target, $request, self::time(), 'hit' );
		$fresh_matcher = $factory();
		$fresh_rows = $fresh_matcher->match_all( 'GH', '', 'Accra', '', [ 'canonical_location_key' => 'loc-accra' ] );
		$fresh = DecisionContextAdapter::from_coverage( $fresh_matcher->last_diagnostics()[0], $target, $request, self::time(), 'miss' );
		self::assertSame( [ 10 ], array_column( $first_rows, 'id' ) );
		self::assertSame( $first_rows, $cached_rows );
		self::assertSame( $first_rows, $fresh_rows );
		self::assertSame( self::semantics( $first ), self::semantics( $cached ) );
		self::assertSame( self::semantics( $first ), self::semantics( $fresh ) );
		self::assertSame( [ 'coverage_matched', 'selected_descendant' ], $first->reason_codes() );
		self::assertNull( $first->configuration_versions() );
		self::assertFalse( $first->is_complete() );
		foreach ( $first->trace()->entries() as $entry ) {
			self::assertNull( $entry['reference_version'] );
		}
	}

	public function test_configuration_value_and_collection_members_are_never_captured(): void {
		$value = [ 'renamed_private' => [ 'customer_secret' => 'PRIVATE_SENTINEL', 'latitude' => 1.234 ] ];
		$config = new EffectiveConfiguration( 101, null, '', [ 'estimated_delivery' => new EffectiveScalarField( 'estimated_delivery', EffectiveFieldState::Valid, $value, FieldProvenance::system_default() ) ], [], EffectiveFieldState::Valid, [], self::version() );
		$context = DecisionContextAdapter::from_configuration( $config, 1, RequestContext::create(), self::time() );
		$captured = json_encode( self::semantics( $context ), JSON_THROW_ON_ERROR );
		self::assertStringNotContainsString( 'PRIVATE_SENTINEL', $captured );
		self::assertStringNotContainsString( 'latitude', $captured );
		self::assertStringNotContainsString( 'customer_secret', $captured );
		self::assertSame( $value, $config->scalar( 'estimated_delivery' )->value );
	}

	public function test_actual_legacy_zone_fallback_is_not_rejected_by_absent_group_diagnostic(): void {
		$geo = new GhanaGeographyFixture();
		$zones = new InMemoryDestinationZoneRepository();
		$zones->save( [ 'id' => 10, 'status' => 'active', 'priority' => 100, 'public_label' => 'Accra', 'internal_name' => 'Private zone', 'is_fallback' => false ] );
		$rules = new InMemoryDestinationRuleRepository();
		$rules->replaceForZone( 10, [
			[ 'rule_type' => 'country', 'rule_value' => 'GH', 'match_mode' => 'exact' ],
			[ 'rule_type' => 'city', 'rule_value' => 'Accra', 'match_mode' => 'exact' ],
		] );
		$matcher = new DestinationZoneMatcher(
			$zones, $rules, null,
			new CoverageGroupMatcher( new InMemoryCoverageGroupRepository(), $geo->locations ),
			new CanonicalLocationResolver( $geo->locations, $geo->locations )
		);
		$matches = $matcher->match_all( 'GH', '', 'Accra', '', [ 'canonical_location_key' => 'loc-accra' ] );
		self::assertSame( [ 10 ], array_column( $matches, 'id' ) );
		self::assertCount( 1, $matcher->last_diagnostics() );
		$context = DecisionContextAdapter::from_coverage( $matcher->last_diagnostics()[0], new DecisionTarget( 1, 101 ), RequestContext::create(), self::time() );
		self::assertSame( 'not_applicable', $context->outcome() );
		self::assertSame( [ 'coverage_not_recorded', 'no_usable_coverage_group' ], $context->reason_codes() );
		self::assertFalse( $context->is_complete() );
		self::assertNotContains( 'coverage_unmatched', $context->reason_codes() );
		self::assertSame( 10, $context->trace()->entries()[0]['reference_id'] );
	}

	public function test_empty_group_summary_is_unknown_and_keeps_actual_exclusion_separate(): void {
		$request = RequestContext::create();
		$target = new DecisionTarget( 1, 101 );
		$empty = DecisionContextAdapter::from_coverage( new CoverageMatchDiagnostic( false, 10, null, '', '', 0, '' ), $target, $request, self::time() );
		self::assertSame( 'not_applicable', $empty->outcome() );
		self::assertSame( [ 'coverage_not_recorded' ], $empty->reason_codes() );
		self::assertFalse( $empty->is_complete() );
		$excluded = DecisionContextAdapter::from_coverage( new CoverageMatchDiagnostic( false, 10, null, '', 'no_group_matched', 0, '' ), $target, $request, self::time() );
		self::assertSame( 'unavailable', $excluded->outcome() );
		self::assertSame( [ 'coverage_unmatched', 'no_group_matched' ], $excluded->reason_codes() );
		self::assertFalse( $excluded->is_complete() );
	}

	public function test_configuration_hard_constraint_preserves_applied_reason_and_unknown_rule_version(): void {
		$source = new EffectiveConfiguration( 101, null, 'in_warehouse', [
			'fulfilment_choice' => new EffectiveScalarField( 'fulfilment_choice', EffectiveFieldState::Valid, 'store_pickup', new FieldProvenance( ConfigurationScopeType::Product, 'product', 5 ) ),
		], [], EffectiveFieldState::Valid, [], self::version( 'in_warehouse' ) );
		$constrained = ( new HardFulfilmentConstraintService() )->apply( $source );
		$context = DecisionContextAdapter::from_configuration( $constrained, 1, RequestContext::create(), self::time() );
		self::assertSame( 'unavailable', $context->outcome() );
		self::assertContains( ConfigurationReasonCode::CONSTRAINT_CHOICE_PROHIBITED, $context->reason_codes() );
		self::assertSame( 'hard_constraint', $context->provenance()[0]->source_label );
		self::assertNull( $context->provenance()[0]->configuration_version );
		self::assertSame( $source->version->fingerprint, $context->configuration_versions()['fingerprint'] );
		self::assertFalse( $context->is_complete() );
	}

	public function test_unresolved_configuration_keeps_absent_versions_and_empty_default_slice(): void {
		$source = ( new EffectiveConfigurationResolver( new InMemoryScopedConfigurationRepository(), new EffectiveConfigurationValidator() ) )->resolve( new EffectiveConfigurationRequest( 101 ) );
		$context = DecisionContextAdapter::from_configuration( $source, 1, RequestContext::create(), self::time() );
		self::assertSame( 'unavailable', $context->outcome() );
		self::assertContains( 'configuration_unresolved', $context->reason_codes() );
		self::assertContains( ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE, $context->reason_codes() );
		self::assertSame( 0, $context->configuration_versions()['global'] );
		self::assertSame( '', $context->target()->slice_key );
		self::assertFalse( $context->is_complete() );
	}

	public function test_coverage_discards_nested_and_preencoded_details_without_claiming_exhaustive_match(): void {
		$details = [ 'renamed_private' => [ 'password' => 'PRIVATE_SENTINEL' ], 'encoded' => '{"sql":"PRIVATE_SENTINEL"}', 'root_location_id' => 777 ];
		$source = new CoverageMatchDiagnostic( false, 10, 20, '', 'excluded_descendant', 60, '', $details );
		$context = DecisionContextAdapter::from_coverage( $source, new DecisionTarget( 1, 101 ), RequestContext::create(), self::time() );
		$captured = json_encode( self::semantics( $context ), JSON_THROW_ON_ERROR );
		self::assertStringNotContainsString( 'PRIVATE_SENTINEL', $captured );
		self::assertStringNotContainsString( 'root_location_id', $captured );
		self::assertSame( $details, $source->details );
		self::assertContains( 'excluded_descendant', $context->reason_codes() );
		self::assertSame( 'unavailable', $context->outcome() );
		self::assertFalse( $context->is_complete() );
	}

	public function test_quote_success_keeps_card_identity_with_unknown_version_and_excludes_private_message(): void {
		$money = new Money( '25.0000', new CurrencyCode( 'GHS' ) );
		$quote = RateQuoteResult::success( $money, new RateQuoteLine( 'fixed_per_shipment', $money, 1 ), 42, 'PRIVATE_CARD', 'fixed_per_shipment', 'PRIVATE_SENTINEL' );
		$context = DecisionContextAdapter::from_quote( $quote, new DecisionTarget( 1, 101 ), RequestContext::create(), self::time() );
		self::assertSame( 'available', $context->outcome() );
		self::assertSame( [ 'quote_available' ], $context->reason_codes() );
		self::assertSame( 42, $context->trace()->entries()[0]['reference_id'] );
		self::assertNull( $context->trace()->entries()[0]['reference_version'] );
		self::assertFalse( $context->is_complete() );
		$captured = json_encode( self::semantics( $context ), JSON_THROW_ON_ERROR );
		self::assertStringNotContainsString( 'PRIVATE_SENTINEL', $captured );
		self::assertStringNotContainsString( 'PRIVATE_CARD', $captured );
		self::assertStringNotContainsString( '25.0000', $captured );
		self::assertSame( '25.0000', $quote->amount->amount() );
	}

	#[DataProvider( 'quote_failures' )]
	public function test_quote_failures_preserve_actual_safe_codes_without_captured_message( string $code ): void {
		$context = DecisionContextAdapter::from_quote( RateQuoteResult::failure( $code, 'PRIVATE_SENTINEL' ), new DecisionTarget( 1, 101 ), RequestContext::create(), self::time() );
		self::assertSame( 'unavailable', $context->outcome() );
		self::assertSame( [ $code ], $context->reason_codes() );
		self::assertNull( $context->trace()->entries()[0]['reference_id'] );
		self::assertFalse( $context->is_complete() );
		self::assertStringNotContainsString( 'PRIVATE_SENTINEL', json_encode( self::semantics( $context ), JSON_THROW_ON_ERROR ) );
	}

	public static function quote_failures(): array {
		return array_map( static fn ( string $code ): array => [ $code ], [ RateQuoteEngine::ERROR_NO_MATCHING_RATE_CARD, RateQuoteEngine::ERROR_NEGATIVE_AMOUNT, RateQuoteEngine::ERROR_INVALID_AMOUNT, RateQuoteEngine::ERROR_UNSUPPORTED_CHARGE_TYPE ] );
	}

	#[DataProvider( 'invalid_sources' )]
	public function test_unknown_or_inconsistent_source_facts_are_rejected_without_echo( string $case ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid existing decision source.' );
		$request = RequestContext::create();
		$target = new DecisionTarget( 1, 101 );
		if ( 'quote_reason' === $case ) {
			DecisionContextAdapter::from_quote( RateQuoteResult::failure( 'PRIVATE_SENTINEL', '' ), $target, $request, self::time() );
		} elseif ( 'coverage_reason' === $case ) {
			DecisionContextAdapter::from_coverage( new CoverageMatchDiagnostic( false, 10, null, '', 'PRIVATE_SENTINEL', 0, '' ), $target, $request, self::time() );
		} elseif ( 'coverage_conflict' === $case ) {
			DecisionContextAdapter::from_coverage( new CoverageMatchDiagnostic( true, 10, 20, 'entire_area', 'country_mismatch', 0, '' ), $target, $request, self::time() );
		} elseif ( 'configuration_reason' === $case ) {
			DecisionContextAdapter::from_configuration( new EffectiveConfiguration( 101, null, '', [], [], EffectiveFieldState::Invalid, [ 'PRIVATE_SENTINEL' ], self::version() ), 1, $request, self::time() );
		} else {
			DecisionContextAdapter::from_configuration( new EffectiveConfiguration( 101, null, '', [], [], EffectiveFieldState::Valid, [], self::version( 'in_store' ) ), 1, $request, self::time() );
		}
	}

	public static function invalid_sources(): array {
		return array_map( static fn ( string $case ): array => [ $case ], [ 'quote_reason', 'coverage_reason', 'coverage_conflict', 'configuration_reason', 'configuration_identity' ] );
	}

	private static function version( string $slice = '' ): EffectiveConfigurationVersionDescriptor {
		return new EffectiveConfigurationVersionDescriptor( 101, null, $slice, 1, 2, 0, str_repeat( 'a', 64 ) );
	}

	private static function time(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-10-06T18:00:00.123456+02:00' );
	}

	private static function semantics( DecisionContext $context ): array {
		return [ $context->outcome(), $context->reason_codes(), $context->configuration_versions(), array_map( static fn ( $fact ): array => $fact->admin_fields(), $context->provenance() ), $context->trace()->entries(), $context->is_complete() ];
	}
}
