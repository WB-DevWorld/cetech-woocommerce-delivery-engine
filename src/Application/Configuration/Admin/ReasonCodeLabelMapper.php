<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;

/**
 * Maps effective validation states and reason codes for administrators.
 */
final class ReasonCodeLabelMapper {

	public static function state_label( EffectiveFieldState $state ): string {
		return match ( $state ) {
			EffectiveFieldState::Valid => 'Valid',
			EffectiveFieldState::Unresolved => 'Unresolved',
			EffectiveFieldState::Disabled => 'Disabled',
			EffectiveFieldState::Invalid => 'Invalid',
		};
	}

	public static function state_tone( EffectiveFieldState $state ): string {
		return match ( $state ) {
			EffectiveFieldState::Valid => 'success',
			EffectiveFieldState::Unresolved => 'warning',
			EffectiveFieldState::Disabled => 'neutral',
			EffectiveFieldState::Invalid => 'error',
		};
	}

	/**
	 * @param list<string> $reason_codes
	 *
	 * @return list<string>
	 */
	public static function explain_codes( array $reason_codes ): array {
		$messages = [];

		foreach ( $reason_codes as $code ) {
			$messages[] = self::explain( $code );
		}

		return array_values( array_unique( $messages ) );
	}

	public static function explain( string $code ): string {
		return match ( $code ) {
			ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE => 'A required root (global) value is not configured.',
			ConfigurationReasonCode::INVALID_COLLECTION_OPERATION => 'This collection operation needs a resolvable base collection first.',
			ConfigurationReasonCode::INVALID_SCOPE_RELATIONSHIP => 'The variation does not belong to the selected parent product.',
			ConfigurationReasonCode::MISSING_REQUIRED_FIELD => 'A required field is missing.',
			ConfigurationReasonCode::UNSUPPORTED_DISABLE => 'Disable is not allowed for this field.',
			ConfigurationReasonCode::INVALID_REFERENCE => 'A referenced entity is invalid.',
			ConfigurationReasonCode::INVALID_REQUEST => 'The configuration request is invalid.',
			default => $code,
		};
	}
}
