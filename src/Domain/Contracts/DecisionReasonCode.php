<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

use InvalidArgumentException;

/** Internal, finite reason vocabulary. Categories/message keys contain no caller prose. */
final class DecisionReasonCode {

	private const CATEGORIES = [
		'configuration_ready' => 'configuration_ready',
		'configuration_disabled' => 'delivery_disabled',
		'configuration_unresolved' => 'configuration_unavailable',
		'MISSING_REQUIRED_FIELD' => 'configuration_unavailable',
		'UNRESOLVED_GLOBAL_VALUE' => 'configuration_unavailable',
		'UNSUPPORTED_DISABLE' => 'configuration_unavailable',
		'INVALID_COLLECTION_OPERATION' => 'configuration_unavailable',
		'INVALID_SCOPE_RELATIONSHIP' => 'configuration_unavailable',
		'INVALID_REFERENCE' => 'configuration_unavailable',
		'INVALID_REQUEST' => 'configuration_unavailable',
		'CONSTRAINT_CHOICE_PROHIBITED' => 'delivery_unavailable',
		'CONSTRAINT_ROUTE_FILTERED' => 'delivery_options_updated',
		'CONSTRAINT_PICKUP_LOCATION_INVALID' => 'pickup_unavailable',
		'coverage_matched' => 'coverage_available',
		'coverage_unmatched' => 'delivery_unavailable',
		'coverage_not_recorded' => 'information_incomplete',
		'no_usable_coverage_group' => 'information_incomplete',
		'no_group_matched' => 'delivery_unavailable',
		'root_missing' => 'configuration_unavailable',
		'destination_not_canonical' => 'destination_required',
		'destination_inactive' => 'delivery_unavailable',
		'country_mismatch' => 'delivery_unavailable',
		'outside_root_ancestry' => 'delivery_unavailable',
		'postcode_mismatch' => 'delivery_unavailable',
		'excluded_descendant' => 'delivery_unavailable',
		'not_selected_descendant' => 'delivery_unavailable',
		'entire_area' => 'coverage_available',
		'entire_area_except' => 'coverage_available',
		'selected_descendant' => 'coverage_available',
		'quote_available' => 'quote_available',
		'no_matching_rate_card' => 'quote_unavailable',
		'negative_amount' => 'quote_unavailable',
		'invalid_amount' => 'quote_unavailable',
		'unsupported_charge_type' => 'quote_unavailable',
		'outcome_unknown' => 'outcome_unknown',
		'publication_pending' => 'outcome_unknown',
		'settings_changed' => 'settings_updated',
		'settings_reset' => 'settings_updated',
		'mutation_rejected' => 'settings_not_saved',
	];

	public static function is_known(string $code): bool {
		return isset(self::CATEGORIES[$code]);
	}

	public static function public_category(string $code): string {
		if (!self::is_known($code)) {
			throw new InvalidArgumentException('Unknown decision reason code.');
		}
		return self::CATEGORIES[$code];
	}

	public static function message_key(string $code): string {
		return 'cetech.decision.' . self::public_category($code);
	}
}
