<?php

declare(strict_types=1);

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreSchema;

/** Schema 7 is additive. Existing schema-6 services and records are untouched. */
return new class implements VerifiableMigrationInterface {

	public function get_id(): string { return OperationStoreReadiness::MIGRATION_ID; }
	public function get_version(): string { return '7'; }

	public function up(): void {
		global $wpdb;
		$inspection = new OperationStoreReadiness( $wpdb );
		$inspection->preflight();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( OperationStoreSchema::create_table_statements( $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
		$this->verify();
	}

	public function verify(): void {
		$inspection = new OperationStoreReadiness();
		$inspection->verify();
		$inspection->verify_stored_records();
	}
};
