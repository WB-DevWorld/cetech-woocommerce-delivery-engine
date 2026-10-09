<?php

declare(strict_types=1);

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\ShipmentPromiseReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\ShipmentPromiseSchema;

/** Forward-only schema 11; retained original promises and text shipment history are preserved. */
return new class implements VerifiableMigrationInterface {
 public function get_id(): string { return ShipmentPromiseReadiness::MIGRATION_ID; }
 public function get_version(): string { return '11'; }
 public function up(): void {
  global $wpdb;
  $inspection = new ShipmentPromiseReadiness( $wpdb );
  $inspection->preflight();
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  foreach ( ShipmentPromiseSchema::create_table_statements( $wpdb->get_charset_collate() ) as $suffix => $sql ) {
   dbDelta( $sql ); $inspection->verify();
  }
  $this->verify();
 }
 public function verify(): void {
  $inspection = new ShipmentPromiseReadiness();
  $inspection->verify(); $inspection->verify_stored_records();
 }
};
