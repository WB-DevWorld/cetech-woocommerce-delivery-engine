<?php

declare(strict_types=1);

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;

/** Forward-only schema 9. No existing price, history or configuration is rewritten. */
return new class implements VerifiableMigrationInterface {

	public function get_id(): string { return DeliveryQuoteReadiness::MIGRATION_ID; }
	public function get_version(): string { return '9'; }

	public function up(): void {
		global $wpdb;
		$inspection = new DeliveryQuoteReadiness( $wpdb );
		// All existing structures and rows are checked before dbDelta can alter one.
		$inspection->preflight();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( DeliveryQuoteSchema::create_table_statements( $wpdb->get_charset_collate() ) as $suffix => $sql ) {
			dbDelta( $sql );
			$inspection->verify_table( $suffix );
		}
		$table = $wpdb->prefix . TableNames::PREFIX . 'rate_cards';
		$indexes = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", DeliveryQuoteSchema::RATE_INDEX ), ARRAY_A );
		if ( ! is_array( $indexes ) || '' !== trim( (string) $wpdb->last_error ) ) {
			throw new RuntimeException( 'Quote storage migration could not be verified.' );
		}
		if ( [] === $indexes && false === $wpdb->query( DeliveryQuoteSchema::rate_index_statement( $wpdb->prefix ) ) ) {
			throw new RuntimeException( 'Quote storage migration could not be verified.' );
		}
		$inspection->verify_rate_index();
		$this->verify();
	}

	public function verify(): void {
		$inspection = new DeliveryQuoteReadiness();
		$inspection->verify();
		$inspection->verify_stored_records();
	}
};
