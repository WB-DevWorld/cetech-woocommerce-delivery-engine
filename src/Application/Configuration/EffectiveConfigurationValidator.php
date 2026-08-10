<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationValidationResult;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfiguration;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;

final class EffectiveConfigurationValidator {

	public function validate( EffectiveConfiguration $configuration ): ConfigurationValidationResult {
		$has_invalid    = false;
		$has_unresolved = false;
		$reason_codes   = $configuration->reason_codes;
		$field_notes    = [];

		foreach ( $configuration->scalars as $field ) {
			if ( EffectiveFieldState::Invalid === $field->state ) {
				$has_invalid = true;
			}

			if ( EffectiveFieldState::Unresolved === $field->state ) {
				$has_unresolved = true;
			}

			if ( [] !== $field->reason_codes ) {
				$field_notes[ $field->field_key ] = $field->reason_codes;
				array_push( $reason_codes, ...$field->reason_codes );
			}
		}

		foreach ( $configuration->collections as $field ) {
			if ( EffectiveFieldState::Invalid === $field->state ) {
				$has_invalid = true;
			}

			if ( EffectiveFieldState::Unresolved === $field->state ) {
				$has_unresolved = true;
			}

			if ( [] !== $field->reason_codes ) {
				$field_notes[ $field->field_key ] = $field->reason_codes;
				array_push( $reason_codes, ...$field->reason_codes );
			}
		}

		$state = EffectiveFieldState::Valid;
		if ( $has_invalid ) {
			$state = EffectiveFieldState::Invalid;
		} elseif ( $has_unresolved ) {
			$state = EffectiveFieldState::Unresolved;
		}

		return new ConfigurationValidationResult(
			$state,
			array_values( array_unique( $reason_codes ) ),
			$field_notes
		);
	}
}
