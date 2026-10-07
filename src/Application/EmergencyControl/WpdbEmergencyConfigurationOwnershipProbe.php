<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

use CetechDeliveryEngine\Infrastructure\Persistence\ScopedConfigurationSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;

/** Fixed read-only ownership probe; it never hydrates or repairs authored rows. */
final class WpdbEmergencyConfigurationOwnershipProbe implements EmergencyConfigurationOwnershipProbeInterface {
	public function ownership( int $product_id, ?int $variation_id ): EmergencyOwnership {
		global $wpdb;
		if ( $product_id < 1 || ( null !== $variation_id && $variation_id < 1 ) || ! is_object( $wpdb ) ) {
			return EmergencyOwnership::Unresolved;
		}
		try {
			$scopes = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );
			$fields = TableNames::for( ScopedConfigurationSchema::FIELDS_SUFFIX );
			$collections = TableNames::for( ScopedConfigurationSchema::COLLECTIONS_SUFFIX );
			// An automatically created empty global scope is not an authored path.
			$sql = "SELECT s.id, s.scope_type FROM `{$scopes}` s WHERE (s.scope_type = 'product' AND s.scope_id = %d) OR (s.scope_type = 'variation' AND s.scope_id = %d) OR (s.scope_type = 'global' AND (EXISTS (SELECT 1 FROM `{$fields}` f WHERE f.scope_row_id = s.id) OR EXISTS (SELECT 1 FROM `{$collections}` c WHERE c.scope_row_id = s.id))) LIMIT 201";
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $product_id, $variation_id ?? 0 ), ARRAY_A );
			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error || count( $rows ) > 200 ) {
				return EmergencyOwnership::Unresolved;
			}
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || EmergencyCheckoutFacts::positive_int( $row['id'] ?? null ) === null || ! in_array( $row['scope_type'] ?? null, [ 'global', 'product', 'variation' ], true ) ) {
					return EmergencyOwnership::Unresolved;
				}
			}
			return [] === $rows ? EmergencyOwnership::Unmanaged : EmergencyOwnership::Managed;
		} catch ( \Throwable ) {
			return EmergencyOwnership::Unresolved;
		}
	}
}
