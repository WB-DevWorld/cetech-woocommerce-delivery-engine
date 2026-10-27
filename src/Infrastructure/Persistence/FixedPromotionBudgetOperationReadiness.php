<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Published installation and exact structure on the caller's owner, never allocation authority. */
final class FixedPromotionBudgetOperationReadiness implements OperationReadiness {
	public function assert_ready( OperationSession $session ): void {
		try {
			$binding = self::binding( $session );
			$options = $binding[1] . 'options';
			if ( ! $session->validate_tables( [ $options, ...FixedPromotionBudgetSchema::tables( $binding[1] ) ] ) ) { self::refuse(); }
			self::unchanged( $session, $binding );
			$sql = $session->prepare( "SELECT option_name, CASE WHEN OCTET_LENGTH(option_value) <= 16384 THEN option_value ELSE NULL END AS option_value FROM `{$options}` WHERE option_name IN (%s,%s) ORDER BY option_name ASC LIMIT 3 FOR UPDATE", SchemaVersion::OPTION_NAME, MigrationStatus::OPTION_NAME );
			self::unchanged( $session, $binding );
			$rows = $session->get_results( $sql );
			self::unchanged( $session, $binding );
			$error = $session->errno(); self::unchanged( $session, $binding );
			if ( 0 !== $error || ! is_array( $rows ) || ! array_is_list( $rows ) || 2 !== count( $rows ) ) { self::refuse(); }
			$values = [];
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! is_string( $row['option_name'] ?? null ) || ! in_array( $row['option_name'], [ SchemaVersion::OPTION_NAME, MigrationStatus::OPTION_NAME ], true ) || array_key_exists( $row['option_name'], $values ) || ! is_string( $row['option_value'] ?? null ) || strlen( $row['option_value'] ) > 16384 ) { self::refuse(); }
				$values[$row['option_name']] = $row['option_value'];
			}
			$version = $values[SchemaVersion::OPTION_NAME];
			$status = @unserialize( $values[MigrationStatus::OPTION_NAME], [ 'allowed_classes' => false, 'max_depth' => 8 ] );
			if ( 1 !== preg_match( '/\A[1-9][0-9]{0,8}\z/D', $version ) || version_compare( $version, '12', '<' ) || ! is_array( $status ) || 'success' !== ( $status['status'] ?? null ) || $version !== ( $status['to_version'] ?? null ) || ! is_string( $status['migration_id'] ?? null ) || '' === $status['migration_id'] || ( '12' === $version && FixedPromotionBudgetInstallationReadiness::MIGRATION_ID !== $status['migration_id'] ) ) { self::refuse(); }
			// Ordinary diagnostics use a consistent snapshot; current publication above is separately locked.
			( new FixedPromotionBudgetInstallationReadiness( $session ) )->verify();
			self::unchanged( $session, $binding );
		} catch ( \Throwable ) { self::refuse(); }
	}
	private static function unchanged( OperationSession $session, array $binding ): void { if ( self::binding( $session ) !== $binding ) { self::refuse(); } }

	/** Capture and recheck the complete site/prefix/charset binding without opening another owner. */
	private static function binding( OperationSession $session ): array {
		if ( $session->is_retired() || ! $session->in_transaction() ) { self::refuse(); }
		$site = $session->site_id(); $prefix = $session->table_prefix(); $charset = $session->charset_collate();
		if ( $site < 1 ) { self::refuse(); }
		FixedPromotionBudgetSchema::tables( $prefix ); FixedPromotionBudgetSchema::charset_details( $charset );
		if ( $session->is_retired() || ! $session->in_transaction() || $session->site_id() !== $site || $session->table_prefix() !== $prefix || $session->charset_collate() !== $charset ) { self::refuse(); }
		return [ $site, $prefix, $charset ];
	}
	private static function refuse(): never { throw new \RuntimeException( 'Promotion budget operation readiness is unavailable.' ); }
}
