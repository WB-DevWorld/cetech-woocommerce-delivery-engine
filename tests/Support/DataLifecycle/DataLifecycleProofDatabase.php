<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DataLifecycle;

use CetechDeliveryEngine\Infrastructure\Persistence\BulkJobSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\ScopedConfigurationSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\ShipmentSchema;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofDatabase;

/** Retained schema-9 physical DDL comes from current package source, not invented sentinel tables. */
final class DataLifecycleProofDatabase {
	public static function connect(): \mysqli { return OperationProofDatabase::connect(); }
	public static function prefix(): string { return 'gc6_' . bin2hex( random_bytes( 6 ) ) . '_'; }
	public static function validate_prefix( string $prefix ): void { if ( 1 !== preg_match( '/\Agc6_[a-f0-9]{12}_\z/D', $prefix ) ) { throw new \InvalidArgumentException( 'Invalid disposable lifecycle prefix.' ); } }
	public static function install( \mysqli $database, string $prefix ): void {
		self::validate_prefix( $prefix );
		foreach ( [ OperationStoreSchema::class, RuleLifecycleSchema::class, DeliveryQuoteSchema::class ] as $schema ) { foreach ( $schema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $prefix . 'delivery_engine_' ) as $sql ) { self::execute( $database, $sql ); } }
		self::execute( $database, "CREATE TABLE `{$prefix}options` (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'off') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
		self::insert_option( $database, $prefix, SchemaVersion::OPTION_NAME, '9' ); self::insert_option( $database, $prefix, MigrationStatus::OPTION_NAME, serialize( [ 'status' => 'success', 'to_version' => '9', 'migration_id' => DeliveryQuoteReadiness::MIGRATION_ID ] ) );
		self::execute( $database, "CREATE TABLE `{$prefix}operation_fixture_counter` (id bigint unsigned NOT NULL PRIMARY KEY, revision bigint unsigned NOT NULL, value bigint NOT NULL, published_revision bigint unsigned NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
		self::execute( $database, "INSERT INTO `{$prefix}operation_fixture_counter` (id,revision,value) VALUES (1,1,0)" );
		$charset = 'ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		foreach ( [ '20260705160000_create_configuration_tables.php', '20260705170000_create_product_delivery_rules_table.php' ] as $file ) {
			$source = file_get_contents( dirname( __DIR__, 3 ) . '/database/migrations/' . $file );
			if ( ! is_string( $source ) || ! preg_match_all( '/\$table\s*=\s*TableNames::for\(\s*\x27([a-z_]+)\x27\s*\);\s*\$sql\s*=\s*"(.*?)";/s', $source, $matches, PREG_SET_ORDER ) ) { throw new \RuntimeException( 'Disposable lifecycle legacy schema capture failed.' ); }
			foreach ( $matches as $match ) { $sql = str_replace( [ '{$table}', '{$charset_collate}' ], [ $prefix . 'delivery_engine_' . $match[1], $charset ], $match[2] ); self::execute( $database, $sql ); }
		}
		foreach ( [ BulkJobSchema::class, CoverageSchema::class, GeographySchema::class, ScopedConfigurationSchema::class, ShipmentSchema::class ] as $schema ) {
			foreach ( $schema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $prefix . 'delivery_engine_' ) as $suffix => $sql ) { if ( false === $database->query( $sql ) ) { throw new \RuntimeException( 'Disposable lifecycle schema setup failed: ' . $suffix . ' / ' . $database->errno ); } }
		}
		self::execute( $database, DeliveryQuoteSchema::rate_index_statement( $prefix ) );
	}
	public static function execute( \mysqli $database, string $sql ): void { OperationProofDatabase::execute( $database, $sql ); }
	public static function row( \mysqli $database, string $sql ): ?array { return OperationProofDatabase::row( $database, $sql ); }
	public static function scalar( \mysqli $database, string $sql ): mixed { return OperationProofDatabase::scalar( $database, $sql ); }
	public static function options( \mysqli $database, string $prefix ): array {
		self::validate_prefix( $prefix ); $result = $database->query( "SELECT * FROM `{$prefix}options` ORDER BY option_id" ); if ( ! $result instanceof \mysqli_result ) { throw new \RuntimeException( 'Disposable lifecycle physical read failed.' ); } return $result->fetch_all( MYSQLI_ASSOC );
	}
	public static function insert_option( \mysqli $database, string $prefix, string $name, string $value, string $autoload = 'off' ): int {
		self::validate_prefix( $prefix ); $s = $database->prepare( "INSERT INTO `{$prefix}options` (option_name,option_value,autoload) VALUES (?,?,?)" ); if ( false === $s || ! $s->bind_param( 'sss', $name, $value, $autoload ) || ! $s->execute() ) { throw new \RuntimeException( 'Disposable lifecycle option setup failed.' ); } $id = $database->insert_id; $s->close(); return $id;
	}
	public static function cleanup( \mysqli $database, string $prefix ): void {
		self::validate_prefix( $prefix ); $escaped = $database->real_escape_string( $prefix ); $result = $database->query( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME," . strlen( $prefix ) . ")='{$escaped}'" ); if ( ! $result instanceof \mysqli_result ) { throw new \RuntimeException( 'Disposable lifecycle teardown read failed.' ); }
		foreach ( $result->fetch_all( MYSQLI_ASSOC ) as $row ) { if ( 1 !== preg_match( '/\A[a-z0-9_]+\z/D', $row['TABLE_NAME'] ) ) { throw new \RuntimeException( 'Unsafe disposable lifecycle teardown table.' ); } self::execute( $database, 'DROP TABLE `' . $row['TABLE_NAME'] . '`' ); }
	}
}
