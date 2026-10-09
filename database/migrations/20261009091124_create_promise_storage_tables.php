<?php

declare(strict_types=1);

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\PromiseStorageReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\PromiseStorageSchema;

/** Forward-only schema 10. Existing business rows and retained formats remain unchanged. */
return new class implements VerifiableMigrationInterface {
	public function get_id(): string { return PromiseStorageReadiness::MIGRATION_ID; }
	public function get_version(): string { return '10'; }
	public function up(): void {
		global $wpdb;
		$inspection = new PromiseStorageReadiness( $wpdb );
		// All three units and bounded existing rows/backlinks must pass before any DDL.
		$inspection->preflight();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( PromiseStorageSchema::create_table_statements( $wpdb->get_charset_collate() ) as $suffix => $sql ) {
			dbDelta( $sql ); $inspection->verify_table( $suffix );
		}
		$this->verify();
	}
	public function verify(): void {
		$inspection = new PromiseStorageReadiness(); $inspection->verify(); $inspection->verify_stored_records();
	}
};
