<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\RuleLifecycle;

use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase;

/** Real additive schema8 fixture in the existing explicitly disposable database. */
final class RuleProofDatabase {
	public static function connect(): \mysqli { return OperationProofDatabase::connect(); }
	public static function prefix(): string { return OperationProofDatabase::prefix(); }
	public static function install( \mysqli $database, string $prefix ): void {
		OperationProofDatabase::install( $database, $prefix );
		foreach ( RuleLifecycleSchema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $prefix . 'delivery_engine_' ) as $sql ) { OperationProofDatabase::execute( $database, $sql ); }
		OperationProofDatabase::option( $database, $prefix, SchemaVersion::OPTION_NAME, '8' );
		OperationProofDatabase::option( $database, $prefix, MigrationStatus::OPTION_NAME, serialize( [ 'status' => 'success', 'to_version' => '8', 'migration_id' => RuleLifecycleReadiness::MIGRATION_ID ] ) );
	}
	public static function cleanup( \mysqli $database, string $prefix ): void {
		OperationProofDatabase::validate_prefix( $prefix );
		foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) { OperationProofDatabase::execute( $database, "DROP TABLE IF EXISTS `{$prefix}delivery_engine_{$suffix}`" ); }
		OperationProofDatabase::cleanup( $database, $prefix );
	}
	public static function row( \mysqli $database, string $sql ): ?array { return OperationProofDatabase::row( $database, $sql ); }
	public static function execute( \mysqli $database, string $sql ): void { OperationProofDatabase::execute( $database, $sql ); }
	public static function scalar( \mysqli $database, string $sql ): mixed { return OperationProofDatabase::scalar( $database, $sql ); }
}
