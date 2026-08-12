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
			EffectiveFieldState::Valid => 'Ready',
			EffectiveFieldState::Unresolved => 'Needs configuration',
			EffectiveFieldState::Disabled => 'Disabled',
			EffectiveFieldState::Invalid => 'Configuration problem',
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
			ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE => 'No default value has been set, and this product does not provide its own value.',
			ConfigurationReasonCode::INVALID_COLLECTION_OPERATION => 'This list change needs a complete inherited list first. Set the Default Settings or choose “Use only these options”.',
			ConfigurationReasonCode::INVALID_SCOPE_RELATIONSHIP => 'The variation does not belong to the selected parent product.',
			ConfigurationReasonCode::MISSING_REQUIRED_FIELD => 'A required setting is missing.',
			ConfigurationReasonCode::UNSUPPORTED_DISABLE => 'This setting cannot be turned off.',
			ConfigurationReasonCode::INVALID_REFERENCE => 'A selected supplier, origin, profile, or offer is not valid.',
			ConfigurationReasonCode::INVALID_REQUEST => 'This delivery settings request is not valid.',
			default => $code,
		};
	}
}
