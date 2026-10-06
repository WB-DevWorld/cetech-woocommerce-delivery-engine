<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Contracts;

use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteResult;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration;
use CetechDeliveryEngine\Domain\Contracts\DecisionContext;
use CetechDeliveryEngine\Domain\Contracts\DecisionProvenance;
use CetechDeliveryEngine\Domain\Contracts\DecisionTarget;
use CetechDeliveryEngine\Domain\Contracts\DecisionTrace;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Coverage\CoverageMatchDiagnostic;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Internal, read-only adapters. Legacy summaries are never an exhaustive trace.
 * Arbitrary values, diagnostic details and human messages are not copied.
 */
final class DecisionContextAdapter {

	private const CONFIGURATION_REASONS = [
		ConfigurationReasonCode::MISSING_REQUIRED_FIELD,
		ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE,
		ConfigurationReasonCode::UNSUPPORTED_DISABLE,
		ConfigurationReasonCode::INVALID_COLLECTION_OPERATION,
		ConfigurationReasonCode::INVALID_SCOPE_RELATIONSHIP,
		ConfigurationReasonCode::INVALID_REFERENCE,
		ConfigurationReasonCode::INVALID_REQUEST,
		ConfigurationReasonCode::CONSTRAINT_CHOICE_PROHIBITED,
		ConfigurationReasonCode::CONSTRAINT_ROUTE_FILTERED,
		ConfigurationReasonCode::CONSTRAINT_PICKUP_LOCATION_INVALID,
	];

	private const COVERAGE_INCLUSIONS = [ 'entire_area', 'selected_descendant', 'entire_area_except' ];
	private const COVERAGE_EXCLUSIONS = [
		'no_group_matched', 'root_missing', 'destination_not_canonical', 'destination_inactive',
		'country_mismatch', 'outside_root_ancestry', 'postcode_mismatch',
		'excluded_descendant', 'not_selected_descendant',
	];

	public static function from_configuration(
		EffectiveConfiguration $configuration,
		int $site_id,
		RequestContext $request,
		DateTimeImmutable $evaluated_at,
		string $cache_state = 'not_recorded'
	): DecisionContext {
		$descriptor = $configuration->version;
		if ( $descriptor->product_id !== $configuration->product_id
			|| $descriptor->variation_id !== $configuration->variation_id
			|| $descriptor->slice_key !== $configuration->slice_key
		) {
			self::invalid_source();
		}
		$versions = [
			'global' => $descriptor->global_version,
			'product' => $descriptor->product_version,
			'variation' => $descriptor->variation_version,
			'fingerprint' => $descriptor->fingerprint,
		];
		$summary = match ( $configuration->state ) {
			EffectiveFieldState::Valid => 'configuration_ready',
			EffectiveFieldState::Disabled => 'configuration_disabled',
			EffectiveFieldState::Unresolved => 'configuration_unresolved',
			EffectiveFieldState::Invalid => null,
		};
		$reasons = self::configuration_reasons( $configuration->reason_codes );
		if ( null !== $summary ) {
			array_unshift( $reasons, $summary );
		}
		if ( [] === $reasons ) {
			self::invalid_source();
		}
		$provenance = [];
		$entries = [];
		foreach ( $reasons as $reason ) {
			$entries[] = self::entry( 'configuration', $reason );
		}
		foreach ( [ $configuration->scalars, $configuration->collections ] as $fields ) {
			foreach ( $fields as $field_key => $field ) {
				if ( ! in_array( $field_key, ConfigurationFieldKey::all(), true ) ) {
					self::invalid_source();
				}
				$field_reasons = self::configuration_reasons( $field->reason_codes );
				$source = $field->provenance;
				$version = null === $source->source_scope ? null : $versions[ $source->source_scope->value ];
				$provenance[] = new DecisionProvenance(
					$field_key, $field->state, $source->source_scope, $source->source_label,
					$source->scope_row_id, $version
				);
				foreach ( $field_reasons as $reason ) {
					$reasons[] = $reason;
					$entries[] = self::entry( 'configuration', $reason, 'configuration_scope', $source->scope_row_id, $version );
				}
				if ( [] === $field_reasons && null !== $summary ) {
					$entries[] = self::entry( 'configuration', $summary, 'configuration_scope', $source->scope_row_id, $version );
				}
			}
		}
		return new DecisionContext(
			'configuration.resolve',
			new DecisionTarget( $site_id, $configuration->product_id, $configuration->variation_id, $configuration->slice_key ),
			$request, $evaluated_at,
			match ( $configuration->state ) {
				EffectiveFieldState::Valid => 'available',
				EffectiveFieldState::Disabled => 'disabled',
				default => 'unavailable',
			},
			array_values( array_unique( $reasons ) ), $versions, $provenance,
			new DecisionTrace( $entries, false ), $cache_state
		);
	}

	public static function from_coverage(
		CoverageMatchDiagnostic $diagnostic,
		DecisionTarget $target,
		RequestContext $request,
		DateTimeImmutable $evaluated_at,
		string $cache_state = 'not_recorded'
	): DecisionContext {
		if ( $diagnostic->zone_id <= 0 || ( null !== $diagnostic->coverage_group_id && $diagnostic->coverage_group_id <= 0 )
			|| ! in_array( $diagnostic->specificity, [ 0, 10, 20, 30, 40, 50, 60, 70 ], true )
			|| ( '' !== $diagnostic->inclusion_reason && ! in_array( $diagnostic->inclusion_reason, self::COVERAGE_INCLUSIONS, true ) )
			|| ( '' !== $diagnostic->exclusion_reason && ! in_array( $diagnostic->exclusion_reason, self::COVERAGE_EXCLUSIONS, true ) )
			|| ! in_array( $diagnostic->fallback_reason, [ '', 'no_usable_coverage_group' ], true )
			|| ( $diagnostic->matched && ( '' === $diagnostic->inclusion_reason || '' !== $diagnostic->exclusion_reason || '' !== $diagnostic->fallback_reason ) )
			|| ( ! $diagnostic->matched && '' !== $diagnostic->inclusion_reason )
			|| ( 'no_usable_coverage_group' === $diagnostic->fallback_reason && ( '' !== $diagnostic->exclusion_reason || null !== $diagnostic->coverage_group_id ) )
		) {
			self::invalid_source();
		}
		// Absent group evidence does not reject a later legacy-zone fallback.
		$not_recorded = ! $diagnostic->matched && '' === $diagnostic->exclusion_reason;
		$reasons = [ $not_recorded ? 'coverage_not_recorded' : ( $diagnostic->matched ? 'coverage_matched' : 'coverage_unmatched' ) ];
		foreach ( [ $diagnostic->inclusion_reason, $diagnostic->exclusion_reason, $diagnostic->fallback_reason ] as $reason ) {
			if ( '' !== $reason ) {
				$reasons[] = $reason;
			}
		}
		$entries = [];
		foreach ( $reasons as $reason ) {
			$entries[] = self::entry( 'coverage', $reason, 'coverage_zone', $diagnostic->zone_id );
			if ( null !== $diagnostic->coverage_group_id ) {
				$entries[] = self::entry( 'coverage', $reason, 'coverage_group', $diagnostic->coverage_group_id );
			}
		}
		return new DecisionContext(
			'coverage.match', $target, $request, $evaluated_at,
			$not_recorded ? 'not_applicable' : ( $diagnostic->matched ? 'available' : 'unavailable' ), $reasons,
			null, [], new DecisionTrace( $entries, false ), $cache_state
		);
	}

	public static function from_quote(
		RateQuoteResult $quote,
		DecisionTarget $target,
		RequestContext $request,
		DateTimeImmutable $evaluated_at,
		string $cache_state = 'not_recorded'
	): DecisionContext {
		$errors = [
			RateQuoteEngine::ERROR_NO_MATCHING_RATE_CARD, RateQuoteEngine::ERROR_NEGATIVE_AMOUNT,
			RateQuoteEngine::ERROR_INVALID_AMOUNT, RateQuoteEngine::ERROR_UNSUPPORTED_CHARGE_TYPE,
		];
		if ( $quote->success ) {
			if ( null !== $quote->error_code || null === $quote->amount || null === $quote->line
				|| null === $quote->matched_rate_card_id || $quote->matched_rate_card_id <= 0
			) {
				self::invalid_source();
			}
			$reason = 'quote_available';
		} else {
			if ( ! in_array( $quote->error_code, $errors, true ) || null !== $quote->amount
				|| null !== $quote->line || null !== $quote->matched_rate_card_id
			) {
				self::invalid_source();
			}
			$reason = $quote->error_code;
		}
		return new DecisionContext(
			'rate.quote', $target, $request, $evaluated_at,
			$quote->success ? 'available' : 'unavailable', [ $reason ], null, [],
			new DecisionTrace( [ self::entry( 'quote', $reason, null === $quote->matched_rate_card_id ? null : 'rate_card', $quote->matched_rate_card_id ) ], false ),
			$cache_state
		);
	}

	/** @return list<string> */
	private static function configuration_reasons( array $reasons ): array {
		if ( ! array_is_list( $reasons ) || count( $reasons ) > count( self::CONFIGURATION_REASONS ) ) {
			self::invalid_source();
		}
		foreach ( $reasons as $reason ) {
			if ( ! in_array( $reason, self::CONFIGURATION_REASONS, true ) ) {
				self::invalid_source();
			}
		}
		return $reasons;
	}

	/** @return array{stage:string,reason_code:string,reference_kind:?string,reference_id:?int,reference_version:?int} */
	private static function entry( string $stage, string $reason, ?string $kind = null, ?int $id = null, ?int $version = null ): array {
		return [ 'stage' => $stage, 'reason_code' => $reason, 'reference_kind' => null === $id ? null : $kind, 'reference_id' => $id, 'reference_version' => null === $id ? null : $version ];
	}

	private static function invalid_source(): never {
		throw new InvalidArgumentException( 'Invalid existing decision source.' );
	}
}
