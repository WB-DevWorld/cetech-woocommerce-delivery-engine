<?php

declare(strict_types=1);

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;

/** Schema 8 is additive. Existing rule families and operation history are untouched. */
return new class implements VerifiableMigrationInterface {

	public function get_id(): string { return RuleLifecycleReadiness::MIGRATION_ID; }
	public function get_version(): string { return '8'; }

	public function up(): void {
		global $wpdb;
		$inspection = new RuleLifecycleReadiness( $wpdb );
		$inspection->preflight();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( RuleLifecycleSchema::create_table_statements( $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}
		$this->verify();
	}

	public function verify(): void {
		$inspection = new RuleLifecycleReadiness();
		$inspection->verify();
		$inspection->verify_stored_records();
	}
};
