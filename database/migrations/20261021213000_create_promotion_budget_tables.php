<?php

declare(strict_types=1);

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetInstallationReadiness, FixedPromotionBudgetSchema, TableNames};

/** Additive schema12 only. No ledger, merchant policy or adoption is seeded. */
return new class implements VerifiableMigrationInterface {
	public function get_id(): string { return FixedPromotionBudgetInstallationReadiness::MIGRATION_ID; }
	public function get_version(): string { return '12'; }
	public function up(): void {
		[ $inspection, $assert, $prefix, $charset ] = $this->inspection();
		$inspection->preflight(); $assert();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$assert();
		foreach ( FixedPromotionBudgetSchema::create_table_statements( $charset, $prefix . TableNames::PREFIX ) as $suffix => $sql ) {
			$assert(); dbDelta( $sql ); $assert(); $inspection->verify_table( $suffix ); $assert();
		}
		$inspection->verify(); $assert(); $inspection->verify_stored_records(); $assert();
	}
	public function verify(): void { [ $inspection, $assert ] = $this->inspection(); $inspection->verify(); $assert(); $inspection->verify_stored_records(); $assert(); }
	/** Pin the native connection and site through all DDL and verification callbacks. */
	private function inspection(): array {
		$connection = $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $connection ) || ! is_string( $connection->prefix ?? null ) || ! is_callable( [ $connection, 'get_charset_collate' ] ) ) { throw new \RuntimeException( 'Promotion budget migration binding is unavailable.' ); }
		$prefix = $connection->prefix; $charset = $connection->get_charset_collate(); $site = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
		$assert = static function () use ( $connection, $prefix, $charset, $site ): void {
			if ( ( $GLOBALS['wpdb'] ?? null ) !== $connection || $connection->prefix !== $prefix || $connection->get_charset_collate() !== $charset || ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 ) !== $site || $connection->prefix !== $prefix || ( $GLOBALS['wpdb'] ?? null ) !== $connection ) { throw new \RuntimeException( 'Promotion budget migration binding is unavailable.' ); }
		};
		$assert(); FixedPromotionBudgetSchema::tables( $prefix ); FixedPromotionBudgetSchema::charset_details( $charset );
		return [ new FixedPromotionBudgetInstallationReadiness( $connection ), $assert, $prefix, $charset ];
	}
};
